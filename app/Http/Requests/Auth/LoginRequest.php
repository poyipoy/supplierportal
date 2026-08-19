<?php

namespace App\Http\Requests\Auth;

use App\Enums\TurnstileStatus;
use App\Events\AuthSecurityEvent;
use App\Models\User;
use App\Services\Auth\LoginRateLimiter;
use App\Services\Auth\TurnstileVerifier;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
        $this->merge([
            'email' => Str::lower(trim($this->string('email')->toString())),
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

                throw ValidationException::withMessages(['email' => trans('auth.failed')]);
            }
        }

        if (! Auth::guard('web')->once([
            'email' => $email,
            'password' => $this->string('password')->toString(),
            'is_active' => true,
        ])) {
            $limiter->hit($this, $email);
            $this->session()->flash('auth_turnstile_required', $limiter->requiresTurnstile($this, $email));

            throw ValidationException::withMessages(['email' => trans('auth.failed')]);
        }

        $user = Auth::guard('web')->user();

        if (! $user instanceof User) {
            throw ValidationException::withMessages(['email' => trans('auth.failed')]);
        }

        $limiter->clearAfterSuccess($this, $email);

        return $user;
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return hash('sha256', Str::lower(trim($this->string('email')->toString())).'|'.$this->ip());
    }
}
