<?php

namespace App\Services\Auth;

use App\Events\AuthSecurityEvent;
use App\Models\User;
use App\Notifications\NewDeviceLoginNotification;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class CompleteLoginService
{
    public function __construct(
        private readonly KnownDeviceService $knownDevices,
        private readonly SessionInventoryService $sessions,
        private readonly NotificationService $notifications,
    ) {}

    public function complete(Request $request, User $user, bool $remember): void
    {
        $request->attributes->set('auth_security.login_completed', true);

        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();
        $request->session()->put([
            'auth_session_version' => (int) $user->auth_session_version,
            'auth_absolute_started_at' => now()->timestamp,
        ]);

        $isNewDevice = $this->knownDevices->registerOrTouch($request, $user);

        $evicted = $this->sessions->enforceConcurrentLimit(
            $user,
            $request->session()->getId(),
        );

        if ($evicted > 0) {
            event(new AuthSecurityEvent('concurrent_session_limit_enforced', $user, metadata: ['count' => $evicted]));
        }

        if ($isNewDevice) {
            event(new AuthSecurityEvent('new_device_login', $user));

            $this->notifications->send(
                $user,
                'new_device_login',
                'auth:new-device-login:'.Str::uuid(),
                'New sign-in detected',
                'Your account was signed in on a device that has not been used with this account before.',
                route('profile.edit', absolute: false).'#active-sessions',
                'monitor',
            );

            try {
                $user->notify(new NewDeviceLoginNotification(
                    (string) ($request->ip() ?? ''),
                    Str::limit((string) $request->userAgent(), 512, ''),
                    now(),
                ));
            } catch (Throwable $exception) {
                Log::warning('New-device email notification dispatch failed.', [
                    'user_id' => $user->getKey(),
                    'channel' => 'mail',
                    'queue' => config('queue.default'),
                    'exception_class' => $exception::class,
                ]);
            }
        }
    }
}
