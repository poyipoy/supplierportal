<?php

namespace App\Services\Auth;

use App\Events\AuthSecurityEvent;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\NotificationDomain;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CompleteLoginService
{
    public function __construct(
        private readonly KnownDeviceService $knownDevices,
        private readonly SessionInventoryService $sessions,
        private readonly SessionRevocationService $revocation,
        private readonly NotificationService $notifications,
        private readonly TwoFactorService $twoFactor,
    ) {}

    public function complete(Request $request, User $user, bool $remember): void
    {
        $this->twoFactor->rejectAuthenticatedLogin($request);

        $freshUser = User::query()->find($user->getKey());

        if (! $freshUser?->isAuthenticationEligible()
            || ! $freshUser->hasSameAuthenticationConfigurationAs($user)) {
            $this->twoFactor->forgetPendingLoginFor($request, $user->getKey());

            throw ValidationException::withMessages(['email' => trans('auth.failed')])
                ->redirectTo(route('login'));
        }

        $user = $freshUser;
        // Supersession: later failures must not restore obsolete pending proof.
        $this->twoFactor->forgetPendingLogin($request);
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
            $this->revocation->invalidateRememberTokens($user, $request);
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

        if (config('ui.auth_splash.enabled', false)) {
            $request->session()->put('ui.auth_splash', [
                'id' => (string) Str::uuid(),
                'reason' => 'post-login',
                'issued_at' => now()->timestamp,
                'target' => null,
            ]);
        }
    }
}
