<?php

namespace App\Providers;

use App\Events\AuthSecurityEvent;
use App\Listeners\LogAuthenticationEvent;
use App\Services\Auth\TwoFactorService;
use App\Support\RateLimitResponse;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\OtherDeviceLogout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AuthSecurityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Password::defaults(function (): Password {
            $rule = Password::min((int) config('auth_security.password.min', 12))
                ->max((int) config('auth_security.password.max', 255))
                ->mixedCase()
                ->numbers()
                ->symbols();

            if (app()->environment('production') && config('auth_security.password.uncompromised_in_production', true)) {
                $rule->uncompromised();
            }

            return $rule;
        });

        Event::listen(Login::class, [LogAuthenticationEvent::class, 'handleLogin']);
        Event::listen(Failed::class, [LogAuthenticationEvent::class, 'handleFailed']);
        Event::listen(Logout::class, [LogAuthenticationEvent::class, 'handleLogout']);
        Event::listen(Lockout::class, [LogAuthenticationEvent::class, 'handleLockout']);
        Event::listen(OtherDeviceLogout::class, [LogAuthenticationEvent::class, 'handleOtherDeviceLogout']);
        Event::listen(AuthSecurityEvent::class, [LogAuthenticationEvent::class, 'handleSecurityEvent']);

        $this->registerRateLimiters();
    }

    private function registerRateLimiters(): void
    {
        RateLimiter::for('auth.password-reset-link', function (Request $request): array {
            return [
                $this->limit('password-reset-link:email', $this->emailIdentity($request), 'email_security'),
                $this->limit('password-reset-link:ip', $this->ipIdentity($request), 'email_security', 'guest_ip'),
            ];
        });

        RateLimiter::for('auth.password-reset', function (Request $request): array {
            return [
                $this->limit('password-reset:email', $this->emailIdentity($request), 'credentials'),
                $this->limit('password-reset:ip', $this->ipIdentity($request), 'credentials', 'guest_ip'),
            ];
        });

        RateLimiter::for('auth.email-security', function (Request $request): Limit {
            return $this->limit('email-security:user', $this->authenticatedIdentity($request), 'email_security');
        });

        RateLimiter::for('auth.credentials', function (Request $request): Limit {
            return $this->limit('credentials:user', $this->authenticatedIdentity($request), 'credentials');
        });

        RateLimiter::for('auth.mfa-code', function (Request $request): Limit {
            return $this->limit('mfa-code:user', $this->authenticatedIdentity($request), 'mfa_code');
        });

        RateLimiter::for('auth.security-action', function (Request $request): Limit {
            return $this->limit('security-action:user', $this->authenticatedIdentity($request), 'security_action');
        });
    }

    private function limit(string $scope, string $identity, string $policy, string $variant = 'subject'): Limit
    {
        $config = config("auth_security.rate_limits.{$policy}.{$variant}");

        return Limit::perSecond((int) $config['attempts'], (int) $config['decay_seconds'])
            ->by("{$scope}:".hash('sha256', $identity))
            ->response(static fn (Request $request, array $headers) => RateLimitResponse::forNamedLimiter($request, $headers));
    }

    private function emailIdentity(Request $request): string
    {
        $email = Str::lower(trim((string) $request->input('email', '')));

        return $email === '' ? 'missing:'.$this->ipIdentity($request) : 'email:'.$email;
    }

    private function authenticatedIdentity(Request $request): string
    {
        $userId = $request->user()?->getAuthIdentifier();

        if ($userId !== null && $userId !== '') {
            return 'user:'.$userId;
        }

        $pending = $request->session()->get(TwoFactorService::PENDING_LOGIN_KEY);
        if (is_array($pending) && isset($pending['user_id']) && $pending['user_id'] !== '') {
            return 'user:'.$pending['user_id'];
        }

        return 'ip:'.$this->ipIdentity($request);
    }

    private function ipIdentity(Request $request): string
    {
        return (string) ($request->ip() ?: 'unknown');
    }
}
