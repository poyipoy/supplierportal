<?php

namespace App\Services\Auth;

use App\Events\AuthSecurityEvent;
use App\Models\User;
use App\Support\Auth\SessionLifecycle;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use JsonException;

/**
 * Manages server-side in-place session reconnection without full-page reload.
 *
 * An encrypted, signed HttpOnly grant cookie (`adasi_reconnect_grant`) is bound to
 * the user ID, `auth_session_version`, original `auth_absolute_started_at`, session ID,
 * and device hash. It allows 1-click server-side reconnection after idle timeout
 * while strictly enforcing the 8-hour absolute timeout cap, session revocation,
 * trusted-device removal, and account eligibility.
 */
class SessionReconnectService
{
    public const COOKIE_NAME = 'adasi_reconnect_grant';

    private const REVOKED_SESSION_CACHE_PREFIX = 'auth:revoked-sid:';

    public function __construct(
        private readonly KnownDeviceService $knownDevices,
    ) {}

    public function issueGrantCookie(
        Request $request,
        User $user,
        ?int $startedAt = null,
        ?string $sessionId = null,
    ): void {
        $now = now()->timestamp;
        $abs = $startedAt ?? (int) ($request->hasSession()
            ? ($request->session()->get(SessionLifecycle::ABSOLUTE_STARTED_KEY) ?? $now)
            : $now);
        $sid = $sessionId ?? ($request->hasSession() ? $request->session()->getId() : '');
        $deviceHash = $this->knownDevices->currentDeviceHash($request) ?? '';

        $payload = [
            'uid' => (int) $user->getKey(),
            'ver' => (int) $user->auth_session_version,
            'sid' => (string) $sid,
            'dev' => (string) $deviceHash,
            'abs' => $abs,
            'iat' => $now,
        ];

        $encrypted = Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
        $lifetimeMinutes = max(1, (int) ceil(SessionLifecycle::absoluteTimeoutSeconds() / 60));

        Cookie::queue(Cookie::make(
            self::COOKIE_NAME,
            $encrypted,
            $lifetimeMinutes,
            '/',
            config('session.domain'),
            (bool) config('session.secure'),
            true,
            false,
            'lax',
        ));
    }

    public function forgetGrantCookie(): void
    {
        Cookie::queue(Cookie::forget(
            self::COOKIE_NAME,
            '/',
            config('session.domain'),
        ));
    }

    public function markSessionRevoked(?string $sessionId): void
    {
        if (! is_string($sessionId) || $sessionId === '') {
            return;
        }

        Cache::put(
            self::REVOKED_SESSION_CACHE_PREFIX.hash('sha256', $sessionId),
            true,
            SessionLifecycle::absoluteTimeoutSeconds(),
        );
    }

    /**
     * @param  list<string>  $sessionIds
     */
    public function markSessionsRevoked(array $sessionIds): void
    {
        foreach ($sessionIds as $sessionId) {
            if (is_string($sessionId) && $sessionId !== '') {
                $this->markSessionRevoked($sessionId);
            }
        }
    }

    public function isSessionRevoked(?string $sessionId): bool
    {
        if (! is_string($sessionId) || $sessionId === '') {
            return false;
        }

        return Cache::get(self::REVOKED_SESSION_CACHE_PREFIX.hash('sha256', $sessionId)) === true;
    }

    /**
     * Inspect whether the current request holds a live session or a valid reconnect
     * grant cookie within the absolute timeout window.
     *
     * @return array{valid: bool, reason: string, user: ?User, started_at: ?int}
     */
    public function inspectGrant(Request $request): array
    {
        $now = now()->timestamp;
        $absoluteSeconds = SessionLifecycle::absoluteTimeoutSeconds();

        $liveUser = $request->user();
        if ($liveUser instanceof User) {
            if (! $liveUser->isAuthenticationEligible()) {
                return ['valid' => false, 'reason' => 'account_deactivated', 'user' => null, 'started_at' => null];
            }

            $sessionId = $request->hasSession() ? $request->session()->getId() : '';
            $sessionVersion = $request->hasSession()
                ? $request->session()->get('auth_session_version')
                : null;

            if ($this->isSessionRevoked($sessionId)
                || ($sessionVersion !== null && (int) $sessionVersion !== (int) $liveUser->auth_session_version)) {
                return ['valid' => false, 'reason' => 'session_revoked', 'user' => null, 'started_at' => null];
            }

            $startedAt = (int) ($request->hasSession()
                ? ($request->session()->get(SessionLifecycle::ABSOLUTE_STARTED_KEY) ?? $now)
                : $now);

            if (($startedAt + $absoluteSeconds) <= $now) {
                return ['valid' => false, 'reason' => 'absolute_timeout', 'user' => null, 'started_at' => null];
            }

            return [
                'valid' => true,
                'reason' => 'idle_timeout',
                'user' => $liveUser,
                'started_at' => $startedAt,
            ];
        }

        $rawCookie = $request->cookie(self::COOKIE_NAME);
        if (! is_string($rawCookie) || $rawCookie === '') {
            return ['valid' => false, 'reason' => 'session_ended', 'user' => null, 'started_at' => null];
        }

        try {
            $decoded = json_decode(Crypt::decryptString($rawCookie), true, 16, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return ['valid' => false, 'reason' => 'session_ended', 'user' => null, 'started_at' => null];
        }

        if (! is_array($decoded)
            || ! isset($decoded['uid'], $decoded['ver'], $decoded['abs'])
            || ! is_numeric($decoded['uid'])
            || ! is_numeric($decoded['ver'])
            || ! is_numeric($decoded['abs'])) {
            return ['valid' => false, 'reason' => 'session_ended', 'user' => null, 'started_at' => null];
        }

        $userId = (int) $decoded['uid'];
        $version = (int) $decoded['ver'];
        $startedAt = (int) $decoded['abs'];
        $sessionId = is_string($decoded['sid'] ?? null) ? $decoded['sid'] : '';
        $deviceHash = is_string($decoded['dev'] ?? null) ? $decoded['dev'] : '';

        if ($this->isSessionRevoked($sessionId)) {
            return ['valid' => false, 'reason' => 'session_revoked', 'user' => null, 'started_at' => null];
        }

        $user = User::query()->find($userId);
        if (! $user instanceof User || ! $user->isAuthenticationEligible()) {
            return ['valid' => false, 'reason' => 'account_deactivated', 'user' => null, 'started_at' => null];
        }

        if ($version !== (int) $user->auth_session_version) {
            return ['valid' => false, 'reason' => 'session_revoked', 'user' => null, 'started_at' => null];
        }

        if ($deviceHash !== '') {
            $currentDeviceHash = $this->knownDevices->currentDeviceHash($request);
            if ($currentDeviceHash === null || ! hash_equals($deviceHash, $currentDeviceHash)) {
                return ['valid' => false, 'reason' => 'session_revoked', 'user' => null, 'started_at' => null];
            }

            $deviceExists = DB::table('auth_known_devices')
                ->where('user_id', $user->getKey())
                ->where('device_hash', $deviceHash)
                ->exists();

            if (! $deviceExists) {
                return ['valid' => false, 'reason' => 'session_revoked', 'user' => null, 'started_at' => null];
            }
        }

        if (($startedAt + $absoluteSeconds) <= $now) {
            return ['valid' => false, 'reason' => 'absolute_timeout', 'user' => null, 'started_at' => null];
        }

        return [
            'valid' => true,
            'reason' => 'idle_timeout',
            'user' => $user,
            'started_at' => $startedAt,
        ];
    }

    /**
     * Re-establish or refresh the user's session in place without page reload.
     *
     * @return array{ok: bool, user: ?User, reason: string}
     */
    public function attemptReconnect(Request $request): array
    {
        $inspection = $this->inspectGrant($request);

        if (! $inspection['valid'] || ! $inspection['user'] instanceof User) {
            $this->forgetGrantCookie();

            if ($request->user() instanceof User) {
                $request->attributes->set('auth_security.suppress_logout_audit', true);
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            SessionLifecycle::markSessionMutated($request);

            return [
                'ok' => false,
                'user' => null,
                'reason' => $inspection['reason'],
            ];
        }

        $user = $inspection['user'];
        $now = now()->timestamp;
        $startedAt = $inspection['started_at'] ?? $now;

        $request->attributes->set('auth_security.suppress_login_audit', true);

        if (! $request->user() instanceof User || ! $request->user()->is($user)) {
            Auth::guard('web')->login($user);
        }

        $request->session()->regenerate();
        $request->session()->regenerateToken();

        $sessionPayload = [
            'auth_session_version' => (int) $user->auth_session_version,
            SessionLifecycle::ABSOLUTE_STARTED_KEY => $startedAt,
            SessionLifecycle::LAST_ACTIVITY_KEY => $now,
        ];

        if (config('sso.enabled')) {
            $sessionPayload['sso.verified_at'] = $now;
            $sessionPayload['sso.client_id'] = (string) config('sso.client_id');
        }

        $request->session()->put($sessionPayload);

        SessionLifecycle::markSessionMutated($request);
        $this->issueGrantCookie($request, $user, $startedAt, $request->session()->getId());

        event(new AuthSecurityEvent('session_reconnected', $user, metadata: [
            'reason' => 'idle_reconnect',
        ]));

        return [
            'ok' => true,
            'user' => $user,
            'reason' => 'reconnected',
        ];
    }
}
