<?php

namespace App\Services\Auth;

use App\Events\AuthSecurityEvent;
use App\Models\User;
use App\Notifications\NewDeviceLoginNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class CompleteLoginService
{
    public function __construct(
        private readonly SessionRevocationService $revocation,
    ) {}

    public function complete(Request $request, User $user, bool $remember): void
    {
        $request->attributes->set('auth_security.login_completed', true);

        // Check for a matching active session BEFORE login()/regenerate() run,
        // since at this point any row for this user+ip+agent can only belong
        // to a genuinely different, still-live session.
        $isNewDevice = ! $this->hasMatchingActiveSession($user, $request);

        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();
        $request->session()->put([
            'auth_session_version' => (int) $user->auth_session_version,
            'auth_absolute_started_at' => now()->timestamp,
        ]);

        $evicted = $this->revocation->enforceConcurrentLimit(
            $user,
            $request->session()->getId(),
            (int) config('auth_security.session.max_concurrent_sessions', 3),
        );

        if ($evicted > 0) {
            event(new AuthSecurityEvent('concurrent_session_limit_enforced', $user, metadata: ['count' => $evicted]));
        }

        if ($isNewDevice) {
            event(new AuthSecurityEvent('new_device_login', $user));

            Notification::send($user, new NewDeviceLoginNotification(
                (string) ($request->ip() ?? ''),
                (string) $request->userAgent(),
                now(),
            ));
        }
    }

    private function hasMatchingActiveSession(User $user, Request $request): bool
    {
        $ip = (string) ($request->ip() ?? '');
        $userAgent = (string) $request->userAgent();

        if ($ip === '' && $userAgent === '') {
            return false;
        }

        return DB::table('sessions')
            ->where('user_id', $user->getKey())
            ->where('ip_address', $ip)
            ->where('user_agent', $userAgent)
            ->exists();
    }
}
