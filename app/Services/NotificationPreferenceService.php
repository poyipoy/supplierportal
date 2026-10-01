<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class NotificationPreferenceService
{
    private array $overrides = [];

    private array $eligibility = [];

    public function __construct(private readonly UserPreferenceService $preferences) {}

    public function registry(): array
    {
        $registry = config('notification_preferences', []);

        return is_array($registry) ? $registry : [];
    }

    public function eventsFor(User $user): array
    {
        return array_filter($this->registry(), fn (array $event): bool => $this->eligible($user, $event));
    }

    /** Normalize submitted values without removing true values needed to clear an override. */
    public function normalize(User $user, array $submitted): array
    {
        $events = $this->eventsFor($user);
        $normalized = [];

        foreach ($submitted as $key => $channels) {
            if (! isset($events[$key]) || ! is_array($channels)) {
                throw ValidationException::withMessages(['notification_preferences' => 'Choose only notifications available for your account.']);
            }

            foreach ($channels as $channel => $value) {
                $definition = $events[$key]['channels'][$channel] ?? null;
                if (! is_array($definition) || ($definition['configurable'] ?? false) !== true
                    || ! in_array($value, [true, false, 0, 1, '0', '1'], true)) {
                    throw ValidationException::withMessages(['notification_preferences' => 'Choose only supported notification channels and boolean values.']);
                }

                $normalized[$key][$channel] = in_array($value, [true, 1, '1'], true);
            }
        }

        return $normalized;
    }

    public function mergeOverrides(User $user, array $stored, array $submitted): array
    {
        $overrides = $this->normalizeStored($stored);
        $registry = $this->registry();

        foreach ($this->normalize($user, $submitted) as $key => $channels) {
            foreach ($channels as $channel => $value) {
                if ($value === ($registry[$key]['channels'][$channel]['default'] ?? true)) {
                    unset($overrides[$key][$channel]);
                    if (empty($overrides[$key])) {
                        unset($overrides[$key]);
                    }
                } else {
                    $overrides[$key][$channel] = $value;
                }
            }
        }

        return $overrides;
    }

    public function effectivePreferences(User $user): array
    {
        $effective = [];
        foreach ($this->eventsFor($user) as $key => $event) {
            foreach ($event['channels'] as $channel => $definition) {
                if (($definition['configurable'] ?? false) === true) {
                    $effective[$key][$channel] = $this->enabled($user, $key, $channel);
                }
            }
        }

        return $effective;
    }

    public function enabled(User $user, string $eventKey, string $channel): bool
    {
        try {
            $event = $this->registry()[$eventKey] ?? null;
            $definition = is_array($event) ? ($event['channels'][$channel] ?? null) : null;
            if (! is_array($definition) || ($definition['configurable'] ?? false) !== true) {
                return true;
            }

            $overrides = $this->overridesFor($user);
            if (($overrides[$eventKey][$channel] ?? null) !== false) {
                return true;
            }

            return ! $this->eligible($user, $event);
        } catch (Throwable $exception) {
            // Preference resolution must never turn a delivery failure into a lost notification.
            $this->overrides[$user->getKey()] = [];
            Log::warning('Notification preference lookup failed; legacy delivery retained.', [
                'recipient_id' => $user->getKey(),
                'event_key' => $eventKey,
                'channel' => $channel,
                'exception_class' => $exception::class,
            ]);

            return true;
        }
    }

    public function forget(User $user): void
    {
        unset($this->overrides[$user->getKey()], $this->eligibility[$user->getKey()]);
        request()->attributes->remove(UserPreferenceService::class.'.'.$user->getKey());
    }

    private function eligible(User $user, array $event): bool
    {
        if (! in_array($user->role, $event['roles'] ?? [], true)
            || ! $user->is_active || $user->account_status !== User::ACCOUNT_STATUS_ACTIVE
            || ($event['supplier_scopes'] ?? []) !== ['local']) {
            return false;
        }

        return $this->eligibility[$user->getKey()] ??= $user->isLocalEligible();
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
            // A daemon worker has no matched HTTP route. Never reuse request attributes across jobs.
            $stored = UserPreference::query()->where('user_id', $user->getKey())->value('notification_preferences');
        }

        return $this->overrides[$user->getKey()] = $this->normalizeStored(is_array($stored) ? $stored : []);
    }

    private function normalizeStored(array $stored): array
    {
        $overrides = [];
        foreach ($this->registry() as $key => $event) {
            $channels = $stored[$key] ?? null;
            if (! is_array($channels)) {
                continue;
            }

            foreach ($event['channels'] as $channel => $definition) {
                $value = $channels[$channel] ?? null;
                if (($definition['configurable'] ?? false) === true && is_bool($value)
                    && $value !== ($definition['default'] ?? true)) {
                    $overrides[$key][$channel] = $value;
                }
            }
        }

        return $overrides;
    }
}
