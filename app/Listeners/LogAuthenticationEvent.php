<?php

namespace App\Listeners;

use App\Events\AuthSecurityEvent;
use App\Models\User;
use App\Services\Auth\AuthAuditLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\OtherDeviceLogout;

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
        $this->logger->write('lockout', email: $event->request->string('email')->toString(), request: $event->request);
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
