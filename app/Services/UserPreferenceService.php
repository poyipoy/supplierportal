<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserPreferenceService
{
    public function __construct(private readonly QuickAccessService $quickAccess) {}

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

            $preference = $stored ?? new UserPreference;
            $preference->fill([
                'theme' => $values['theme'],
                'density' => $values['density'],
                'sidebar_state' => $values['sidebar_state'],
                'page_size' => $values['page_size'],
                'quick_access' => $quickAccess,
            ]);
            $preference->revision = ($stored?->revision ?? 0) + 1;
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
            $preference->fill([...config('user_preferences.defaults'), 'quick_access' => []]);
            $preference->revision = ($stored?->revision ?? 0) + 1;
            $lockedUser->preference()->save($preference);
            request()->attributes->remove($this->requestCacheKey($lockedUser));

            return $this->for($lockedUser->refresh());
        }, 3);
    }

    public function sidebarCacheVersion(User $user, array $preferences): string
    {
        $cache = request()->attributes->get($this->requestCacheKey($user));
        $preferenceId = is_array($cache) ? ($cache['id'] ?? 0) : 0;

        return $preferenceId.':'.(int) $preferences['revision'];
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
            'sidebarState' => $preferences['sidebar_state'],
            'pageSize' => $preferences['page_size'],
            'sidebarRevision' => $this->sidebarCacheVersion($user, $preferences),
            'accountId' => (string) $user->getKey(),
        ];
    }
}
