<?php

namespace App\Http\Requests\Auth;

use App\Enums\TurnstileStatus;
use App\Events\AuthSecurityEvent;
use App\Models\User;
use App\Services\Auth\LoginRateLimiter;
use App\Services\Auth\TurnstileVerifier;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Normalizer;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $rawEmail = $this->string('email')->toString();

        // Strip zero-width/invisible codepoints and force canonical NFC so a
        // homograph variant cannot present as a "new" identity to validation
        // or to the rate limiter keys derived from it.
        $sanitized = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $rawEmail) ?? $rawEmail;

        if (class_exists(Normalizer::class) && Normalizer::isNormalized($sanitized, Normalizer::FORM_C) === false) {
            $sanitized = Normalizer::normalize($sanitized, Normalizer::FORM_C) ?: $sanitized;
        }

        $this->merge([
            'email' => Str::lower(trim($sanitized)),
        ]);

        if ($this->has('remember')) {
            $this->merge([
                'remember' => $this->boolean('remember'),
            ]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'remember' => ['nullable', 'boolean'],
            'cf-turnstile-response' => ['nullable', 'string', 'max:2048'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(LoginRateLimiter $limiter, TurnstileVerifier $turnstile): User
    {
        $email = $limiter->normalizedEmail($this->string('email')->toString());
        $limiter->ensureNotLimited($this, $email);

        if ($limiter->requiresTurnstile($this, $email) && $turnstile->configured()) {
            $status = $turnstile->verify($this);

            if ($status === TurnstileStatus::Invalid) {
                $limiter->hit($this, $email);
                $this->session()->flash('auth_turnstile_required', true);
                event(new AuthSecurityEvent('captcha_failed', email: $email));

                $this->applyTarpitDelay($limiter, $email);

                throw ValidationException::withMessages(['email' => trans('auth.failed')]);
            }
        }

        $user = User::query()->where('email', $email)->first();

        // Constant-time verification. The guard's own attempt() short-circuits
        // when no active account matches, returning in ~1ms instead of the
        // ~200ms a bcrypt comparison costs — a gap wide enough to enumerate
        // valid addresses remotely. Hashing against a precomputed dummy hash
        // on the miss path keeps both branches on the same CPU budget.
        $targetHash = ($user instanceof User && $user->is_active)
            ? (string) $user->password
            : (string) config('auth_security.dummy_hash');

        $passwordMatches = Hash::check($this->string('password')->toString(), $targetHash);

        if (! $user instanceof User || ! $user->is_active || ! $passwordMatches) {
            $limiter->hit($this, $email);
            $this->session()->flash('auth_turnstile_required', $limiter->requiresTurnstile($this, $email));

            // Manual verification bypasses the guard, so the Failed event that
            // normally feeds the audit trail never fires. Only name the user
            // when the account is active, matching what the guard used to
            // resolve for these credentials.
            event(new AuthSecurityEvent(
                'login_failed',
                $user instanceof User && $user->is_active ? $user : null,
                $email,
                ['guard' => 'web'],
            ));

            $this->applyTarpitDelay($limiter, $email);

            throw ValidationException::withMessages(['email' => trans('auth.failed')]);
        }

        $limiter->clearAfterSuccess($this, $email);

        return $user;
    }

    /**
     * Stall the failure response once an attacker crosses the tarpit
     * threshold. Bounded by max_delay_ms so held workers cannot be used to
     * exhaust the FPM pool.
     */
    protected function applyTarpitDelay(LoginRateLimiter $limiter, string $email): void
    {
        $tarpit = config('auth_security.login.tarpit');

        if (! ($tarpit['enabled'] ?? false)) {
            return;
        }

        $failureCount = $limiter->currentFailureCount($this, $email);
        $threshold = (int) ($tarpit['threshold'] ?? 3);

        if ($failureCount < $threshold) {
            return;
        }

        $delayMs = min(
            ($failureCount - $threshold + 1) * (int) ($tarpit['delay_step_ms'] ?? 1000),
            (int) ($tarpit['max_delay_ms'] ?? 2000),
        );

        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return hash('sha256', Str::lower(trim($this->string('email')->toString())).'|'.$this->ip());
    }
}
