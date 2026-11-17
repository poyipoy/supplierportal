<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class SessionRevocationService
{
    public function __construct(private readonly RememberTokenService $rememberTokens) {}

    public function revokeOtherSessions(User $user, ?Request $request = null, bool $rotateRememberToken = true): int
    {
        $user->forceFill([
            'auth_session_version' => ((int) $user->auth_session_version) + 1,
            'remember_token' => $rotateRememberToken ? Str::random(60) : $user->getRememberToken(),
        ])->saveQuietly();

        $keepSessionId = $request?->user()?->is($user) ? $request->session()->getId() : null;
        $this->rememberTokens->revokeOtherTokens($user, $keepSessionId);

        if ($request?->user()?->is($user)) {
            $request->session()->put('auth_session_version', (int) $user->auth_session_version);

            if ($rotateRememberToken) {
                $this->rememberTokens->requeueCurrentSessionCookie($request, $user);
            }
        }

        return (int) $user->auth_session_version;
    }

    /**
     * Revoke remember tokens for specific evicted or manually signed-out
     * sessions. Falls back to rotating the legacy single users.remember_token
     * only when none of those sessions held a per-device token row.
     *
     * @param  list<string>  $sessionIds
     */
    public function revokeSessionRememberTokens(User $user, array $sessionIds, ?Request $request = null): void
    {
        $revokedPerDevice = $this->rememberTokens->revokeForSessions($user, $sessionIds);

        if ($revokedPerDevice === 0 && $user->getRememberToken() !== null) {
            $this->invalidateRememberTokens($user, $request);
        }
    }

    /**
     * Legacy shared remember-token rotation (kept for pre-migration sessions
     * and full logout-other-devices invalidation).
     */
    public function invalidateRememberTokens(User $user, ?Request $request = null): void
    {
        $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();

        if (! $request instanceof Request) {
            return;
        }

        if ($request->attributes->get(RememberTokenService::ISSUED_COOKIE_ATTR) === true) {
            return;
        }

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
            RememberTokenService::REMEMBER_COOKIE_MINUTES,
        ));
    }
}
