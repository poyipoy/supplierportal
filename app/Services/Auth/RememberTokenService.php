<?php

namespace App\Services\Auth;

use App\Models\AuthRememberToken;
use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use SensitiveParameter;

class RememberTokenService
{
    /** SessionGuard's default remember duration; config/auth.php does not override it. */
    public const REMEMBER_COOKIE_MINUTES = 576000;

    public const RECALLED_TOKEN_ATTR = 'auth_security.recalled_token_id';

    public const ISSUED_COOKIE_ATTR = 'auth_security.issued_per_device_remember';

    public function issueForSession(
        Request $request,
        User $user,
        string $sessionId,
        ?string $deviceHash = null,
    ): void {
        $guard = Auth::guard('web');

        if (! $guard instanceof SessionGuard) {
            return;
        }

        $plainToken = Str::random(60);
        $now = now();

        // Remove any prior token bound to the exact same session ID before issuing a fresh one.
        AuthRememberToken::query()
            ->where('user_id', $user->getKey())
            ->where('session_id', $sessionId)
            ->delete();

        AuthRememberToken::query()->create([
            'user_id' => $user->getKey(),
            'token_hash' => hash('sha256', $plainToken),
            'session_id' => $sessionId,
            'device_hash' => $deviceHash,
            'ip_address' => $request->ip() ?: null,
            'user_agent' => Str::limit((string) $request->userAgent(), 512, '') ?: null,
            'last_used_at' => $now,
            'expires_at' => $now->copy()->addMinutes(self::REMEMBER_COOKIE_MINUTES),
        ]);

        $this->queueRecallerCookie($guard, $user, $plainToken);
        $request->attributes->set(self::ISSUED_COOKIE_ATTR, true);
    }

    public function resolveUserByToken(
        int|string $identifier,
        #[SensitiveParameter] string $plainToken,
    ): ?User {
        if ($plainToken === '') {
            return null;
        }

        $tokenHash = hash('sha256', $plainToken);

        $record = AuthRememberToken::query()
            ->where('user_id', $identifier)
            ->where('token_hash', $tokenHash)
            ->where('expires_at', '>', now())
            ->first();

        if (! $record instanceof AuthRememberToken) {
            return null;
        }

        $user = User::query()->find($identifier);

        if (! $user instanceof User || ! $user->isAuthenticationEligible()) {
            return null;
        }

        $request = app()->bound('request') ? request() : null;
        $record->forceFill([
            'last_used_at' => now(),
            'ip_address' => $request?->ip() ?: $record->ip_address,
        ])->saveQuietly();

        if ($request instanceof Request) {
            $request->attributes->set(self::RECALLED_TOKEN_ATTR, $record->getKey());
        }

        return $user;
    }

    public function bindToCurrentSession(Request $request, User $user): void
    {
        $tokenId = $request->attributes->get(self::RECALLED_TOKEN_ATTR);

        if (! is_int($tokenId) && ! is_string($tokenId)) {
            return;
        }

        $sessionId = $request->session()->getId();

        if ($sessionId === '') {
            return;
        }

        AuthRememberToken::query()
            ->whereKey($tokenId)
            ->where('user_id', $user->getKey())
            ->update([
                'session_id' => $sessionId,
                'last_used_at' => now(),
                'ip_address' => $request->ip() ?: null,
            ]);
    }

    public function revokeRecalledToken(Request $request, User $user): void
    {
        $tokenId = $request->attributes->get(self::RECALLED_TOKEN_ATTR);

        if (! is_int($tokenId) && ! is_string($tokenId)) {
            return;
        }

        AuthRememberToken::query()
            ->whereKey($tokenId)
            ->where('user_id', $user->getKey())
            ->delete();
    }

    /**
     * @param  list<string>  $sessionIds
     */
    public function revokeForSessions(User $user, array $sessionIds): int
    {
        $ids = array_values(array_filter($sessionIds, fn ($id): bool => is_string($id) && $id !== ''));

        if ($ids === []) {
            return 0;
        }

        return AuthRememberToken::query()
            ->where('user_id', $user->getKey())
            ->whereIn('session_id', $ids)
            ->delete();
    }

    /**
     * @return list<string> session IDs associated with the revoked device tokens
     */
    public function revokeForDevice(User $user, string $deviceHash): array
    {
        $query = AuthRememberToken::query()
            ->where('user_id', $user->getKey())
            ->where('device_hash', $deviceHash);

        $sessionIds = $query->pluck('session_id')
            ->filter(fn ($id): bool => is_string($id) && $id !== '')
            ->values()
            ->all();

        $query->delete();

        return $sessionIds;
    }

    public function revokeOtherTokens(User $user, ?string $keepSessionId = null): int
    {
        $query = AuthRememberToken::query()->where('user_id', $user->getKey());

        if (is_string($keepSessionId) && $keepSessionId !== '') {
            $query->where(function ($builder) use ($keepSessionId): void {
                $builder->whereNull('session_id')
                    ->orWhere('session_id', '!=', $keepSessionId);
            });
        }

        return $query->delete();
    }

    public function requeueCurrentSessionCookie(Request $request, User $user): void
    {
        $guard = Auth::guard('web');

        if (! $guard instanceof SessionGuard) {
            return;
        }

        $recallerName = $guard->getRecallerName();

        if (! $request->cookies->has($recallerName) && ! $guard->getCookieJar()->hasQueued($recallerName)) {
            return;
        }

        if ($request->attributes->get(self::ISSUED_COOKIE_ATTR) === true) {
            return;
        }

        $sessionId = $request->session()->getId();
        $hasPerDeviceToken = $sessionId !== '' && AuthRememberToken::query()
            ->where('user_id', $user->getKey())
            ->where('session_id', $sessionId)
            ->where('expires_at', '>', now())
            ->exists();

        if ($hasPerDeviceToken) {
            // Re-issue a fresh per-device token for the current session so any
            // password update refreshes the recaller hash segment too.
            $deviceHash = app(KnownDeviceService::class)->currentDeviceHash($request);
            $this->issueForSession($request, $user, $sessionId, $deviceHash);

            return;
        }

        $guard->getCookieJar()->queue($guard->getCookieJar()->make(
            $recallerName,
            $user->getAuthIdentifier().'|'.$user->getRememberToken().'|'.$guard->hashPasswordForCookie($user->getAuthPassword()),
            self::REMEMBER_COOKIE_MINUTES,
        ));
    }

    public function hasPerDeviceTokens(User $user): bool
    {
        return AuthRememberToken::query()
            ->where('user_id', $user->getKey())
            ->exists();
    }

    private function queueRecallerCookie(SessionGuard $guard, User $user, string $plainToken): void
    {
        $cookies = $guard->getCookieJar();
        $cookies->queue($cookies->make(
            $guard->getRecallerName(),
            $user->getAuthIdentifier().'|'.$plainToken.'|'.$guard->hashPasswordForCookie($user->getAuthPassword()),
            self::REMEMBER_COOKIE_MINUTES,
        ));
    }
}
