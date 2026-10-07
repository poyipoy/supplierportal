<?php

namespace App\Http\Requests\SupplierRegistration;

use App\Services\Auth\LoginRateLimiter;
use Illuminate\Foundation\Http\FormRequest;

class RegistrationCredentialAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => app(LoginRateLimiter::class)->normalizedEmail($this->string('email')->toString()),
        ]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'cf-turnstile-response' => ['nullable', 'string', 'max:2048'],
        ];
    }
}
