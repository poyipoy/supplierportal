<?php

namespace App\Services\Auth;

use App\Events\AuthSecurityEvent;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\NotificationDomain;
use App\Support\PortalContext;
use App\Support\WorkspaceShell;
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
        private readonly RememberTokenService $rememberTokens,
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
        $deviceHash = $this->knownDevices->currentDeviceHash($request);

        if ($remember) {
            $this->rememberTokens->issueForSession(
                $request,
                $user,
                $request->session()->getId(),
                $deviceHash,
            );
        }

        $evicted = $this->sessions->enforceConcurrentLimit(
            $user,
            $request->session()->getId(),
        );

        if ($evicted > 0) {
            if ($this->sessions->lastEvictionRevokedPerDeviceCount() === 0 && $user->getRememberToken() !== null) {
                $this->revocation->invalidateRememberTokens($user, $request);
            }
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

        if (config('ui.auth_splash.enabled', false) && (! $request->expectsJson() || $request->hasHeader('X-Auth-Splash-Client'))) {
            $request->session()->put('ui.auth_splash', [
                'id' => (string) Str::uuid(),
                'reason' => 'post-login',
                'issued_at' => now()->timestamp,
                'target' => null,
            ]);
        }
    }

    /**
     * Resolve post-login destination directly to the role dashboard when the splash
     * handshake requests an immediate single-hop navigation.
     */
    public function resolvePostLoginDestination(Request $request, User $user, ?string $intended): string
    {
        $redirectUrl = TwoFactorService::safeIntendedUrl($request, $intended, route('dashboard', absolute: false));

        $dashboardPath = rtrim((string) parse_url(route('dashboard', absolute: false), PHP_URL_PATH), '/');
        $redirectPath = rtrim((string) parse_url($redirectUrl, PHP_URL_PATH), '/');

        if ($redirectPath === $dashboardPath && ! $user->must_change_password) {
            $shortcutsHome = WorkspaceShell::shortcutsHomeUrl($user, absolute: false);
            if ($shortcutsHome !== null) {
                return $shortcutsHome;
            }
        }

        if (! $request->hasHeader('X-Auth-Splash-Client')) {
            return $redirectUrl;
        }

        if ($user->must_change_password) {
            return route('profile.security', absolute: false);
        }

        if ($redirectPath !== $dashboardPath) {
            return $redirectUrl;
        }

        return match ($user->role) {
            'admin' => route('admin.dashboard', absolute: false),
            'purchasing' => route('purchasing.dashboard', absolute: false),
            'supplier', 'accounting', 'finance' => PortalContext::dashboard($user),
            'ga' => route('ga.dashboard', absolute: false),
            'qc' => route('qc.dashboard', absolute: false),
            default => $redirectUrl,
        };
    }
}
