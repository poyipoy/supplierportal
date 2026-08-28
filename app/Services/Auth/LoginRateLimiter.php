<?php

namespace App\Services\Auth;

use App\Events\AuthSecurityEvent;
use App\Support\RateLimitResponse;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class LoginRateLimiter
{
    public function ensureNotLimited(Request $request, string $email): void
    {
        $limited = collect($this->definitions($request, $email))
            ->filter(fn (array $definition): bool => RateLimiter::tooManyAttempts($definition['key'], $definition['attempts']));

        if ($limited->isEmpty()) {
            return;
        }

        event(new Lockout($request));

        $seconds = $limited->max(fn (array $definition): int => RateLimiter::availableIn($definition['key']));

        $headers = [
            'Retry-After' => $seconds,
            'X-RateLimit-Limit' => (int) $limited->min('attempts'),
            'X-RateLimit-Remaining' => 0,
            'X-RateLimit-Reset' => now()->addSeconds($seconds)->getTimestamp(),
        ];

        throw new HttpResponseException(RateLimitResponse::forLogin($request, $headers));
    }

    public function hit(Request $request, string $email): void
    {
        foreach ($this->definitions($request, $email) as $definition) {
            RateLimiter::hit($definition['key'], $definition['decay_seconds']);
        }

        $this->recordDistinctFailedEmail($request, $email);

        $global = $this->globalDefinition();
        $count = RateLimiter::hit($global['key'], $global['decay_seconds']);
        $remainingWindow = max(1, RateLimiter::availableIn($global['key']));

        if ($count >= $global['attempts'] && Cache::add(
            $this->globalAnomalyMarkerKey(),
            true,
            $remainingWindow,
        )) {
            event(new AuthSecurityEvent('global_login_anomaly_detected', metadata: ['count' => $count]));
        }
    }

    public function clearAfterSuccess(Request $request, string $email): void
    {
        $definitions = $this->definitions($request, $email);

        RateLimiter::clear($definitions['combination']['key']);
        RateLimiter::clear($definitions['email']['key']);
    }

    public function requiresTurnstile(Request $request, string $email): bool
    {
        $threshold = (int) config('auth_security.turnstile.failure_threshold', 3);
        $definitions = $this->definitions($request, $email);

        return RateLimiter::attempts($definitions['email']['key']) >= $threshold
            || RateLimiter::attempts($definitions['ip']['key']) >= $threshold
            || $this->distinctEmailThresholdExceeded($request)
            || $this->globalThresholdExceeded();
    }

    /**
     * Record that this IP just attempted to sign in with the given email.
     *
     * The per-identity limiters above catch an attacker hammering ONE
     * account, but not the "wide" credential-stuffing pattern of trying many
     * different accounts from a single IP, each only once or twice. This
     * tracks the set of distinct emails seen per IP in a rolling window so
     * that pattern can trip requiresTurnstile() too.
     */
    private function recordDistinctFailedEmail(Request $request, string $email): void
    {
        $window = (int) config('auth_security.login.distinct_email.window_seconds', 300);
        $emails = Cache::get($this->distinctEmailKey($request), []);

        if (! is_array($emails)) {
            $emails = [];
        }

        $emails[hash('sha256', $this->normalizedEmail($email))] = true;

        Cache::put($this->distinctEmailKey($request), $emails, $window);
    }

    private function distinctEmailThresholdExceeded(Request $request): bool
    {
        $threshold = (int) config('auth_security.login.distinct_email.threshold', 5);
        $emails = Cache::get($this->distinctEmailKey($request), []);

        return is_array($emails) && count($emails) >= $threshold;
    }

    private function distinctEmailKey(Request $request): string
    {
        return 'auth-login:distinct-emails:'.hash('sha256', (string) ($request->ip() ?: 'unknown'));
    }

    private function globalThresholdExceeded(): bool
    {
        $definition = $this->globalDefinition();

        return RateLimiter::attempts($definition['key']) >= $definition['attempts'];
    }

    private function globalAnomalyMarkerKey(): string
    {
        return 'auth-login:global-anomaly-audited';
    }

    public function attempts(Request $request, string $email): array
    {
        return collect($this->definitions($request, $email))
            ->map(fn (array $definition): int => RateLimiter::attempts($definition['key']))
            ->all();
    }

    public function normalizedEmail(string $email): string
    {
        return Str::lower(trim($email));
    }

    private function definitions(Request $request, string $email): array
    {
        $normalizedEmail = $this->normalizedEmail($email);
        $ip = (string) ($request->ip() ?: 'unknown');

        return [
            'combination' => $this->definition('combination', $normalizedEmail.'|'.$ip),
            'email' => $this->definition('email', $normalizedEmail),
            'ip' => $this->definition('ip', $ip),
        ];
    }

    private function definition(string $scope, string $identity): array
    {
        $config = config("auth_security.login.{$scope}");

        return [
            'key' => "auth-login:{$scope}:".hash('sha256', $identity),
            'attempts' => (int) $config['attempts'],
            'decay_seconds' => (int) $config['decay_seconds'],
        ];
    }

    private function globalDefinition(): array
    {
        $config = config('auth_security.login.global');

        return [
            'key' => 'auth-login:global-failures',
            'attempts' => (int) $config['attempts'],
            'decay_seconds' => (int) $config['decay_seconds'],
        ];
    }
}
