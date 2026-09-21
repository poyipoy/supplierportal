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
use Normalizer;

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

        // Only the credential-specific counters are forgiven. The IP and
        // subnet counters keep running so one correct guess inside a spray
        // does not reset the network-level defences.
        RateLimiter::clear($definitions['combination']['key']);
        RateLimiter::clear($definitions['email']['key']);
    }

    /**
     * Number of failures recorded so far for this exact email + IP pair.
     *
     * Drives the progressive tarpit delay, which escalates per attempt from
     * one client rather than per account (an account-wide counter would let a
     * distributed pool inflict the delay on the legitimate owner).
     */
    public function currentFailureCount(Request $request, string $email): int
    {
        $definitions = $this->definitions($request, $email);

        return (int) RateLimiter::attempts($definitions['combination']['key']);
    }

    public function requiresTurnstile(Request $request, string $email): bool
    {
        $threshold = (int) config('auth_security.turnstile.failure_threshold', 3);
        $definitions = $this->definitions($request, $email);

        return RateLimiter::attempts($definitions['email']['key']) >= $threshold
            || RateLimiter::attempts($definitions['ip']['key']) >= $threshold
            || RateLimiter::attempts($definitions['subnet']['key']) >= $threshold
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

    /**
     * Canonicalize an email before it is hashed into a limiter key.
     *
     * Zero-width and invisible codepoints, or a decomposed Unicode form, all
     * render as the same address but hash differently — which would hand an
     * attacker a fresh attempt budget per variant. Stripping the invisibles
     * and forcing NFC collapses every variant onto one key.
     */
    public function normalizedEmail(string $email): string
    {
        $cleaned = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $email) ?? $email;

        if (class_exists(Normalizer::class) && Normalizer::isNormalized($cleaned, Normalizer::FORM_C) === false) {
            $cleaned = Normalizer::normalize($cleaned, Normalizer::FORM_C) ?: $cleaned;
        }

        return Str::lower(trim($cleaned));
    }

    /**
     * Collapse a client IP onto its network block: /24 for IPv4, /64 for IPv6.
     *
     * Loopback is left intact so local and test traffic is not bucketed with
     * anything else.
     */
    public function extractSubnet(string $ip): string
    {
        if ($ip === '' || $ip === 'unknown' || $ip === '127.0.0.1' || $ip === '::1') {
            return $ip;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return preg_replace('/\.\d+$/', '.0/24', $ip) ?? $ip;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = inet_pton($ip);

            if ($packed !== false) {
                $masked = inet_ntop($packed & inet_pton('ffff:ffff:ffff:ffff::'));

                if ($masked !== false) {
                    return $masked.'/64';
                }
            }
        }

        return $ip;
    }

    private function definitions(Request $request, string $email): array
    {
        $normalizedEmail = $this->normalizedEmail($email);
        $ip = (string) ($request->ip() ?: 'unknown');

        return [
            'combination' => $this->definition('combination', $normalizedEmail.'|'.$ip),
            'email' => $this->definition('email', $normalizedEmail),
            'ip' => $this->definition('ip', $ip),
            'subnet' => $this->definition('subnet', $this->extractSubnet($ip)),
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
