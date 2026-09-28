<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserPreference;
use App\Services\Dashboard\DashboardWidgetService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserPreferenceService
{
    public function __construct(
        private readonly QuickAccessService $quickAccess,
        private readonly DashboardWidgetService $dashboardWidgets,
    ) {}

    public function for(User $user): array
    {
        $request = request();
        $cacheKey = $this->requestCacheKey($user);

        if ($request->attributes->has($cacheKey)) {
            return $request->attributes->get($cacheKey)['preferences'];
        }

        $defaults = config('user_preferences.defaults');
        $stored = UserPreference::query()->where('user_id', $user->getKey())->first();
        $preferences = $stored instanceof UserPreference
            ? [
                ...$defaults,
                'theme' => $stored->theme,
                'density' => $stored->density,
                'sidebar_state' => $stored->sidebar_state,
                'page_size' => $stored->page_size,
                'quick_access' => is_array($stored->quick_access) ? $stored->quick_access : [],
                'revision' => $stored->revision,
                'accent' => isset(config('user_preferences.accents')[$stored->accent]) ? $stored->accent : $defaults['accent'],
                'dashboard_preferences' => $this->dashboardWidgets->normalizeLayouts($user, is_array($stored->dashboard_preferences) ? $stored->dashboard_preferences : []),
                'sidebar_revision' => max(1, (int) $stored->sidebar_revision),
            ]
            : [...$defaults, 'revision' => 0];

        $request->attributes->set($cacheKey, [
            'id' => $stored?->getKey(),
            'preferences' => $preferences,
        ]);

        return $preferences;
    }

    public function save(User $user, array $values): array
    {
        return DB::transaction(function () use ($user, $values): array {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $stored = $lockedUser->preference()->first();
            $submittedKeys = array_values($values['quick_access'] ?? []);

            if ($lockedUser->isSupplier()) {
                $activeContext = $this->quickAccess->contextFor($lockedUser);
                $otherContextKeys = $this->quickAccess->availableInOtherSupplierContext(
                    $lockedUser,
                    $stored?->quick_access ?? [],
                    $activeContext,
                );
                $quickAccess = array_values(array_unique([...$submittedKeys, ...$otherContextKeys]));
            } else {
                $quickAccess = array_values(array_unique($submittedKeys));
            }

            if (count($quickAccess) > (int) config('user_preferences.quick_access_limit')) {
                throw ValidationException::withMessages([
                    'quick_access' => 'Reduce the selected shortcuts to six or fewer across your account contexts.',
                ]);
            }

            $savedAccent = $stored?->accent;
            if (! is_string($savedAccent) || ! isset(config('user_preferences.accents')[$savedAccent])) {
                $savedAccent = config('user_preferences.defaults.accent');
            }
            $savedLayouts = is_array($stored?->dashboard_preferences) ? $stored->dashboard_preferences : [];
            $layouts = array_key_exists('dashboard', $values)
                ? $this->dashboardWidgets->mergeLayout($lockedUser, $savedLayouts, $values['dashboard'])
                : $this->dashboardWidgets->normalizeLayouts($lockedUser, $savedLayouts);
            $previousSidebar = $stored?->sidebar_state ?? config('user_preferences.defaults.sidebar_state');
            $preference = $stored ?? new UserPreference;
            $preference->fill([
                'theme' => $values['theme'],
                'density' => $values['density'],
                'sidebar_state' => $values['sidebar_state'],
                'page_size' => $values['page_size'],
                'quick_access' => $quickAccess,
                'accent' => $values['accent'] ?? $savedAccent,
                'dashboard_preferences' => $layouts,
            ]);
            $preference->revision = ($stored?->revision ?? 0) + 1;
            $preference->sidebar_revision = max(1, (int) ($stored?->sidebar_revision ?? 1))
                + ($previousSidebar !== $values['sidebar_state'] ? 1 : 0);
            $lockedUser->preference()->save($preference);
            request()->attributes->remove($this->requestCacheKey($lockedUser));

            return $this->for($lockedUser->refresh());
        }, 3);
    }

    public function reset(User $user): array
    {
        return DB::transaction(function () use ($user): array {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $stored = $lockedUser->preference()->first();
            $preference = $stored ?? new UserPreference;
            $defaults = config('user_preferences.defaults');
            unset($defaults['sidebar_revision']);
            $preference->fill($defaults);
            $preference->sidebar_revision = max(1, (int) ($stored?->sidebar_revision ?? 1)) + 1;
            $preference->revision = ($stored?->revision ?? 0) + 1;
            $lockedUser->preference()->save($preference);
            request()->attributes->remove($this->requestCacheKey($lockedUser));

            return $this->for($lockedUser->refresh());
        }, 3);
    }

    public function sidebarCacheVersion(User $user, array $preferences): string
    {
        return 'sidebar-v2:'.(int) $preferences['sidebar_revision'];
    }

    private function requestCacheKey(User $user): string
    {
        return self::class.'.'.$user->getKey();
    }

    public function frontendPayload(User $user, array $preferences): array
    {
        return [
            'theme' => $preferences['theme'],
            'density' => $preferences['density'],
            'accent' => $preferences['accent'],
            'accentKeys' => array_keys(config('user_preferences.accents')),
            'sidebarState' => $preferences['sidebar_state'],
            'pageSize' => $preferences['page_size'],
            'sidebarRevision' => $this->sidebarCacheVersion($user, $preferences),
            'accountId' => (string) $user->getKey(),
        ];
    }
}
