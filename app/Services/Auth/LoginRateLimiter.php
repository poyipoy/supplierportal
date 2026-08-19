<?php

namespace App\Services\Auth;

use App\Support\RateLimitResponse;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
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
            || RateLimiter::attempts($definitions['ip']['key']) >= $threshold;
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
}
