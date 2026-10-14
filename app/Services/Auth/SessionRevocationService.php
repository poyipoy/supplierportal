<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class SessionRevocationService
{
    /** SessionGuard's default remember duration; config/auth.php does not override it. */
    private const REMEMBER_COOKIE_MINUTES = 576000;

    public function revokeOtherSessions(User $user, ?Request $request = null, bool $rotateRememberToken = true): int
    {
        $user->forceFill([
            'auth_session_version' => ((int) $user->auth_session_version) + 1,
            'remember_token' => $rotateRememberToken ? Str::random(60) : $user->getRememberToken(),
        ])->saveQuietly();

        if ($request?->user()?->is($user)) {
            $request->session()->put('auth_session_version', (int) $user->auth_session_version);
        }

        return (int) $user->auth_session_version;
    }

    /**
     * Deleting a session row alone does not sign a device out: a device that
     * ticked "remember me" re-authenticates from its recaller cookie on the
     * next request. Laravel shares one remember token across all devices, so
     * rotate it, then re-issue the recaller for the current device if it uses
     * one, mirroring SessionGuard::logoutOtherDevices().
     */
    public function invalidateRememberTokens(User $user, Request $request): void
    {
        $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();

        $guard = Auth::guard('web');

        if (! $guard instanceof SessionGuard) {
            return;
        }

        $cookies = $guard->getCookieJar();
        $recallerName = $guard->getRecallerName();

        if (! $request->cookies->has($recallerName) && ! $cookies->hasQueued($recallerName)) {
            return;
        }

        $cookies->queue($cookies->make(
            $recallerName,
            $user->getAuthIdentifier().'|'.$user->getRememberToken().'|'.$guard->hashPasswordForCookie($user->getAuthPassword()),
            self::REMEMBER_COOKIE_MINUTES,
        ));
    }
}
