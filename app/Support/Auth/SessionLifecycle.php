<?php

namespace App\Support\Auth;

use Illuminate\Http\Request;

final class SessionLifecycle
{
    public const LAST_ACTIVITY_KEY = 'auth_last_activity_at';

    public const ABSOLUTE_STARTED_KEY = 'auth_absolute_started_at';

    public const SESSION_MUTATED_ATTR = 'auth_security.session_mutated';

    public const PASSIVE_CHECK_HEADER = 'X-Adasi-Session-Check';

    public const RECONNECT_HEADER = 'X-Adasi-Reconnect';

    /**
     * Background polling routes that must never extend the user's idle session window.
     *
     * @var list<string>
     */
    public const PASSIVE_POLL_ROUTES = [
        'notifications.unread-count',
        'conversations.unread-count',
        'conversations.messages.latest',
        'exports.status',
        'finance.local-procurement.imports.status',
        'purchasing.local-procurement.imports.status',
        'finance.local-procurement.po-documents.status',
        'purchasing.local-procurement.po-documents.status',
    ];

    public static function idleTimeoutMinutes(): int
    {
        $sessionLifetime = max(1, (int) config('session.lifetime', 120));
        $absoluteMinutes = max(1, (int) config('auth_security.session.absolute_timeout_minutes', 480));
        $configured = config('auth_security.session.idle_timeout_minutes');

        $idle = is_numeric($configured) && (int) $configured > 0
            ? (int) $configured
            : $sessionLifetime;

        return max(1, min($idle, $sessionLifetime, $absoluteMinutes));
    }

    public static function idleTimeoutSeconds(): int
    {
        return self::idleTimeoutMinutes() * 60;
    }

    public static function absoluteTimeoutSeconds(): int
    {
        return max(1, (int) config('auth_security.session.absolute_timeout_minutes', 480)) * 60;
    }

    public static function heartbeatIntervalSeconds(?int $idleSeconds = null): int
    {
        $idle = $idleSeconds ?? self::idleTimeoutSeconds();
        $configured = (int) config('auth_security.session.heartbeat_interval_seconds', 120);
        $bounded = max(30, min(300, $configured));

        // Ensure short idle windows (e.g. 1 minute in QA) still allow at least one
        // active-user heartbeat before expiration.
        return min($bounded, max(15, intdiv($idle, 2)));
    }

    public static function isPassiveStatusCheck(Request $request): bool
    {
        if (! $request->routeIs('session.status')) {
            return false;
        }

        return $request->boolean('passive')
            || strtolower((string) $request->header(self::PASSIVE_CHECK_HEADER, '')) === 'passive';
    }

    public static function isPassiveRequest(Request $request): bool
    {
        if ($request->routeIs(...self::PASSIVE_POLL_ROUTES)) {
            return true;
        }

        return self::isPassiveStatusCheck($request);
    }

    public static function markSessionMutated(Request $request): void
    {
        $request->attributes->set(self::SESSION_MUTATED_ATTR, true);
    }

    public static function shouldSkipSessionTouch(Request $request): bool
    {
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return false;
        }

        if ($request->attributes->get(self::SESSION_MUTATED_ATTR) === true) {
            return false;
        }

        return self::isPassiveRequest($request);
    }

    public static function isReconnectableReason(string $reason): bool
    {
        return ! in_array($reason, [
            'absolute_timeout',
            'session_revoked',
            'account_deactivated',
        ], true);
    }

    /**
     * @return array{
     *     idle_timeout_seconds: int,
     *     idle_remaining_seconds: int,
     *     absolute_timeout_seconds: int,
     *     absolute_remaining_seconds: int,
     *     heartbeat_interval_seconds: int
     * }
     */
    public static function remainingTimings(Request $request, ?int $now = null): array
    {
        $current = $now ?? now()->timestamp;
        $idleSeconds = self::idleTimeoutSeconds();
        $absoluteSeconds = self::absoluteTimeoutSeconds();

        $lastActivity = $request->hasSession() ? $request->session()->get(self::LAST_ACTIVITY_KEY) : null;
        $startedAt = $request->hasSession() ? $request->session()->get(self::ABSOLUTE_STARTED_KEY) : null;

        $idleRemaining = is_numeric($lastActivity)
            ? max(0, ((int) $lastActivity + $idleSeconds) - $current)
            : $idleSeconds;

        $absoluteRemaining = is_numeric($startedAt)
            ? max(0, ((int) $startedAt + $absoluteSeconds) - $current)
            : $absoluteSeconds;

        return [
            'idle_timeout_seconds' => $idleSeconds,
            'idle_remaining_seconds' => min($idleRemaining, $absoluteRemaining),
            'absolute_timeout_seconds' => $absoluteSeconds,
            'absolute_remaining_seconds' => $absoluteRemaining,
            'heartbeat_interval_seconds' => self::heartbeatIntervalSeconds($idleSeconds),
        ];
    }
}
