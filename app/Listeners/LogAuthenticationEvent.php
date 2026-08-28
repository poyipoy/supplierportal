<?php

namespace App\Listeners;

use App\Events\AuthSecurityEvent;
use App\Models\User;
use App\Notifications\RepeatedLockoutAlertNotification;
use App\Services\Auth\AuthAuditLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\OtherDeviceLogout;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class LogAuthenticationEvent
{
    public function __construct(private readonly AuthAuditLogger $logger) {}

    public function handleLogin(Login $event): void
    {
        if ($event->remember && $event->user instanceof User && $event->user->hasTwoFactorAuthentication()
            && ! request()->attributes->get('auth_security.login_completed')) {
            return;
        }

        $this->logger->write('login_success', $event->user, metadata: [
            'guard' => $event->guard,
            'remember' => $event->remember,
        ]);
    }

    public function handleFailed(Failed $event): void
    {
        $this->logger->write(
            'login_failed',
            $event->user instanceof User ? $event->user : null,
            is_string($event->credentials['email'] ?? null) ? $event->credentials['email'] : null,
            ['guard' => $event->guard]
        );
    }

    public function handleLogout(Logout $event): void
    {
        if (request()->attributes->get('auth_security.suppress_logout_audit')) {
            return;
        }

        $this->logger->write('logout', $event->user instanceof User ? $event->user : null, metadata: [
            'guard' => $event->guard,
        ]);
    }

    public function handleLockout(Lockout $event): void
    {
        $email = $event->request->string('email')->toString();

        $this->logger->write('lockout', email: $email, request: $event->request);

        $this->alertAdminsOnRepeatedLockouts($email);
    }

    /**
     * Track lockouts per account in a rolling window and alert admins once
     * the account crosses the repeated-lockout threshold. A separate "already
     * alerted" flag stops the same ongoing attack from paging admins on
     * every subsequent lockout.
     */
    private function alertAdminsOnRepeatedLockouts(string $email): void
    {
        $normalized = Str::lower(trim($email));

        if ($normalized === '') {
            return;
        }

        $window = (int) config('auth_security.login.repeated_lockout_alert.window_seconds', 3600);
        $threshold = (int) config('auth_security.login.repeated_lockout_alert.threshold', 3);
        $countKey = 'auth-lockout-streak:'.hash('sha256', $normalized);
        $alertedKey = 'auth-lockout-streak-alerted:'.hash('sha256', $normalized);

        $count = ((int) Cache::get($countKey, 0)) + 1;
        Cache::put($countKey, $count, $window);

        if ($count < $threshold || Cache::has($alertedKey)) {
            return;
        }

        Cache::put($alertedKey, true, $window);

        $this->logger->write('repeated_lockouts_detected', email: $normalized, metadata: ['count' => $count]);

        $admins = User::query()->where('role', 'admin')->where('is_active', true)->get();

        if ($admins->isNotEmpty()) {
            Notification::send($admins, new RepeatedLockoutAlertNotification($normalized, $count));
        }
    }

    public function handleOtherDeviceLogout(OtherDeviceLogout $event): void
    {
        $this->logger->write('other_device_logout', $event->user instanceof User ? $event->user : null, metadata: [
            'guard' => $event->guard,
        ]);
    }

    public function handleSecurityEvent(AuthSecurityEvent $event): void
    {
        $this->logger->write($event->event, $event->user, $event->email, $event->metadata);
    }
}
