<?php

namespace App\Http\Requests\Auth;

use App\Enums\TurnstileStatus;
use App\Services\Auth\TurnstileVerifier;
use App\Support\SupplierComplianceQuestionnaire;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class SupplierRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Registration-only password policy (min 8, mixed case, number, symbol).
     *
     * Deliberately separate from Password::defaults() (min 12) which governs internal
     * accounts and later password changes. Registration validates composition only.
     */
    public static function registrationPasswordRule(): Password
    {
        return Password::min(8)
            ->max(255)
            ->mixedCase()
            ->numbers()
            ->symbols();

    }

    public function rules(): array
    {
        return [
            // Company
            'company_title' => ['nullable', 'string', 'max:50'],
            'company_name' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string', 'max:1000'],
            'phone' => ['required', 'string', 'max:50'],
            'category' => ['nullable', 'string', 'max:100'],
            'vendor_category' => ['nullable', 'string', 'max:100'],
            'is_pkp' => ['nullable', 'boolean'],

            // Tax & Identity
            'nib' => ['required', 'string', 'max:50'],
            'npwp' => ['required', 'string', 'max:50'],

            // PIC
            'pic_name' => ['required', 'string', 'max:255'],
            'pic_email' => ['required', 'email', 'max:255'],
            'pic_phone' => ['required', 'string', 'max:50'],

            // Bank
            'bank_name' => ['required', 'string', 'max:100'],
            'bank_select' => ['nullable', 'string', 'max:100'],
            'other_bank_name' => ['nullable', 'string', 'max:100'],
            'account_number' => ['required', 'string', 'max:50'],
            'account_holder_name' => ['required', 'string', 'max:255'],

            // Account credentials
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', self::registrationPasswordRule(), 'confirmed'],

            // Mandatory Documents (Max 5MB = 5120 KB)
            'nib_file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'npwp_file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'sknr_file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],

            // Optional Documents
            'sppkp_file' => [Rule::requiredIf(fn () => $this->boolean('is_pkp')), 'nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'skd_file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'company_profile_file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],

            // Bot protection
            'cf-turnstile-response' => ['nullable', 'string', 'max:2048'],
        ] + SupplierComplianceQuestionnaire::rules();
    }

    public function attributes(): array
    {
        return SupplierComplianceQuestionnaire::attributes();
    }

    public function messages(): array
    {
        return ['sppkp_file.required' => __('registration.sppkp_required')];
    }

    /**
     * Both empty keys disable Turnstile; partial configuration and verification failures block submission.
     */
    public function validateTurnstile(TurnstileVerifier $turnstile): void
    {
        if (filled(config('auth_security.turnstile.site_key')) || filled(config('auth_security.turnstile.secret_key'))) {
            $status = $turnstile->verify($this);
            if ($status !== TurnstileStatus::Passed) {
                throw ValidationException::withMessages([
                    'cf-turnstile-response' => __('auth.registration_verification_failed'),
                ]);
            }
        }
    }

    /**
     * Safe whitelist of registration fields (strictly excludes privileges).
     */
    public function safeRegistrationData(): array
    {
        return $this->only([
            'company_title',
            'company_name',
            'address',
            'phone',
            'category',
            'vendor_category',
            'is_pkp',
            'nib',
            'npwp',
            'pic_name',
            'pic_email',
            'pic_phone',
            'bank_name',
            'account_number',
            'account_holder_name',
            'email',
            'password',
            'questionnaire',
        ]);
    }
}
