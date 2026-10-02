<?php

namespace App\Services;

use App\Models\ExportJob;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\UserPreference;
use App\Notifications\SystemNotification;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class NotificationPreferenceService
{
    private array $overrides = [];

    private array $supplierScopes = [];

    private array $poCreators = [];

    private array $exportOwners = [];

    public function __construct(private readonly UserPreferenceService $preferences) {}

    public function registry(): array
    {
        $registry = config('notification_preferences', []);

        return is_array($registry) ? $registry : [];
    }

    public function keyFor(Notification $notification): ?string
    {
        if ($notification::class !== SystemNotification::class || $notification->event() === null) {
            return null;
        }

        foreach ($this->registry() as $key => $event) {
            if (($event['class'] ?? null) === $notification::class
                && ($event['source_event'] ?? null) === $notification->event()) {
                return $key;
            }
        }

        return null;
    }

    public function eventsFor(User $user): array
    {
        return array_filter($this->registry(), fn (array $event): bool => $this->eligible($user, $event));
    }

    /** Retain submitted true values so an existing override can be cleared. */
    public function normalize(User $user, array $submitted): array
    {
        $events = $this->eventsFor($user);
        $normalized = [];
        foreach ($submitted as $key => $value) {
            if (! isset($events[$key]) || ! in_array($value, [true, false, 0, 1, '0', '1'], true)) {
                throw ValidationException::withMessages([
                    'notification_preferences' => 'Choose only notifications available for your account and boolean values.',
                ]);
            }
            $normalized[$key] = in_array($value, [true, 1, '1'], true);
        }

        return $normalized;
    }

    public function mergeOverrides(User $user, array $stored, array $submitted, array $delivery = []): array
    {
        $overrides = $this->normalizeStored($stored);
        foreach ($this->normalize($user, $submitted) as $key => $value) {
            if ($value) {
                if (($delivery[$key] ?? null) === 'silent') {
                    $overrides[$key] = 'silent';
                } else {
                    unset($overrides[$key]);
                }
            } else {
                $overrides[$key] = false;
            }
        }

        return $overrides;
    }

    public function deliveryFor(User $user, string $eventKey): string
    {
        try {
            $event = $this->registry()[$eventKey] ?? null;
            if (! is_array($event) || ! $this->eligible($user, $event)) {
                return 'normal';
            }

            $override = $this->overridesFor($user)[$eventKey] ?? null;
            if ($override === false) {
                return 'off';
            }
            if ($override === 'silent') {
                return 'silent';
            }

            return 'normal';
        } catch (Throwable $exception) {
            $this->overrides[$user->getKey()] = [];
            try {
                Log::warning('Notification preference delivery lookup failed; normal delivery retained.', [
                    'recipient_id' => $user->getKey(),
                    'event_key' => $eventKey,
                    'exception_class' => $exception::class,
                ]);
            } catch (Throwable) {
                // Logging must not make preference failure interrupt the business workflow.
            }

            return 'normal';
        }
    }

    public function effectivePreferences(User $user): array
    {
        $effective = [];
        foreach ($this->eventsFor($user) as $key => $event) {
            $effective[$key] = $this->enabled($user, $key);
        }

        return $effective;
    }

    public function enabled(User $user, string $eventKey): bool
    {
        try {
            $event = $this->registry()[$eventKey] ?? null;
            if (! is_array($event) || ! $this->eligible($user, $event)) {
                return true;
            }

            return ($this->overridesFor($user)[$eventKey] ?? null) !== false;
        } catch (Throwable $exception) {
            $this->overrides[$user->getKey()] = [];
            try {
                Log::warning('Notification preference lookup failed; legacy delivery retained.', [
                    'recipient_id' => $user->getKey(),
                    'event_key' => $eventKey,
                    'exception_class' => $exception::class,
                ]);
            } catch (Throwable) {
                // Logging must not make preference failure interrupt the business workflow.
            }

            return true;
        }
    }

    public function forget(User $user): void
    {
        $id = $user->getKey();
        unset($this->overrides[$id], $this->supplierScopes[$id], $this->poCreators[$id], $this->exportOwners[$id]);
        request()->attributes->remove(UserPreferenceService::class.'.'.$id);
    }

    private function eligible(User $user, array $event): bool
    {
        if (! in_array($user->role, $event['roles'] ?? [], true)
            || ! $user->is_active || $user->account_status !== User::ACCOUNT_STATUS_ACTIVE) {
            return false;
        }

        $scopes = $event['supplier_scopes'] ?? [];
        if ($user->isSupplier() && $scopes !== [] && array_intersect($scopes, $this->scopesFor($user)) === []) {
            return false;
        }

        return match ($event['eligibility'] ?? null) {
            'import_po_creator' => $user->isPurchasing()
                || ($this->poCreators[$user->getKey()] ??= PurchaseOrder::query()->where('created_by', $user->getKey())->exists()),
            'export_owner' => in_array($user->role, ['admin', 'purchasing', 'qc', 'finance', 'accounting'], true)
                || ($user->isSupplier() && in_array('import', $this->scopesFor($user), true))
                || ($this->exportOwners[$user->getKey()] ??= ExportJob::query()->where('user_id', $user->getKey())->exists()),
            default => true,
        };
    }

    private function scopesFor(User $user): array
    {
        return $this->supplierScopes[$user->getKey()] ??= $user->supplierScopes()->pluck('scope')->all();
    }

    public function hasSupplierScope(User $user, string $scope): bool
    {
        return $user->isSupplier() && $user->is_active
            && $user->account_status === User::ACCOUNT_STATUS_ACTIVE
            && in_array($scope, $this->scopesFor($user), true);
    }

    private function overridesFor(User $user): array
    {
        if (array_key_exists($user->getKey(), $this->overrides)) {
            return $this->overrides[$user->getKey()];
        }

        $request = request();
        if ($request->route() !== null && $request->user() instanceof User
            && (int) $request->user()->getKey() === (int) $user->getKey()) {
            $stored = $this->preferences->for($user)['notification_preferences'] ?? [];
        } else {
            // Daemon jobs must not reuse the synthetic request's earlier preference snapshot.
            $stored = UserPreference::query()->where('user_id', $user->getKey())->value('notification_preferences');
        }

        return $this->overrides[$user->getKey()] = $this->normalizeStored(is_array($stored) ? $stored : []);
    }

    public function normalizeStored(array $stored): array
    {
        $overrides = [];
        foreach ($this->registry() as $key => $event) {
            $val = $stored[$key] ?? null;
            if ($val === false) {
                $overrides[$key] = false;
            } elseif ($val === 'silent') {
                $overrides[$key] = 'silent';
            }
        }

        return $overrides;
    }
}
