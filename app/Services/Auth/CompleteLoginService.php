<?php

namespace App\Services\Auth;

use App\Events\AuthSecurityEvent;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\NotificationDomain;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

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
                'notifications.security.new_device.title',
                'notifications.security.new_device.message',
                route('profile.security', absolute: false).'#active-sessions',
                'monitor',
                ['domain' => NotificationDomain::GLOBAL],
            );

        }
    }
}
