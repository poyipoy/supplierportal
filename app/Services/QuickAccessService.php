<?php

namespace App\Services;

use App\Models\User;
use App\Support\PortalContext;
use App\Support\PurchasingNavigation;
use Illuminate\Support\Facades\Route;

class QuickAccessService
{
    public function contextFor(User $user): ?string
    {
        if (! $user->isSupplier()) {
            return null;
        }

        $context = PortalContext::current($user);

        if ($context === PortalContext::SCOPE_LOCAL && $user->isLocalEligible()) {
            return $context;
        }

        if ($context === PortalContext::SCOPE_IMPORT && $user->isImportEligible()) {
            return $context;
        }

        return null;
    }

    public function availableFor(User $user): array
    {
        return $this->availableForContext($user, $this->contextFor($user));
    }

    public function availableForContext(User $user, ?string $context): array
    {
        $audiences = config('quick_access.audiences', []);

        if ($user->isSupplier()) {
            if (! in_array($context, [PortalContext::SCOPE_LOCAL, PortalContext::SCOPE_IMPORT], true)) {
                return [];
            }

            $eligible = $context === PortalContext::SCOPE_LOCAL
                ? $user->isLocalEligible()
                : $user->isImportEligible();
            $items = $eligible ? ($audiences['supplier'][$context] ?? []) : [];
        } else {
            $items = $audiences[$user->role] ?? [];
        }

        $available = [];

        foreach ($items as $key => $item) {
            if (! Route::has($item['route'] ?? '')) {
                continue;
            }

            $available[$key] = [
                'key' => $key,
                ...$item,
                'label' => __($item['label']),
                'url' => ! empty($item['purchasing_list'])
                    ? PurchasingNavigation::listUrl($item['route'])
                    : route($item['route']),
                'active' => request()->routeIs(...(array) ($item['active'] ?? $item['route'])),
            ];
        }

        return $available;
    }

    public function selectedFor(User $user, array $keys, ?string $context = null): array
    {
        $available = $this->availableForContext(
            $user,
            $user->isSupplier() ? ($context ?? $this->contextFor($user)) : null,
        );

        return collect($keys)
            ->filter(fn ($key) => is_string($key) && isset($available[$key]))
            ->unique()
            ->map(fn (string $key) => $available[$key])
            ->values()
            ->all();
    }

    public function isAvailableKey(User $user, string $key, ?string $context = null): bool
    {
        $available = $this->availableForContext(
            $user,
            $user->isSupplier() ? ($context ?? $this->contextFor($user)) : null,
        );

        return isset($available[$key]);
    }

    public function availableInOtherSupplierContext(User $user, array $keys, ?string $activeContext): array
    {
        if (! $user->isSupplier()) {
            return [];
        }

        $otherContexts = match ($activeContext) {
            PortalContext::SCOPE_LOCAL => [PortalContext::SCOPE_IMPORT],
            PortalContext::SCOPE_IMPORT => [PortalContext::SCOPE_LOCAL],
            default => [PortalContext::SCOPE_LOCAL, PortalContext::SCOPE_IMPORT],
        };
        $available = [];

        foreach ($otherContexts as $context) {
            $available += $this->availableForContext($user, $context);
        }

        return collect($keys)
            ->filter(fn ($key) => is_string($key) && isset($available[$key]))
            ->unique()
            ->values()
            ->all();
    }
}
