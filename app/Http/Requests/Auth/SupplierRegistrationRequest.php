<?php

namespace App\Http\Requests\Auth;

use App\Enums\TurnstileStatus;
use App\Services\Auth\TurnstileVerifier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class SupplierRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],

            // Mandatory Documents (Max 5MB = 5120 KB)
            'nib_file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'npwp_file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'sknr_file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],

            // Optional Documents
            'sppkp_file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'skd_file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],

            // Bot protection
            'cf-turnstile-response' => ['nullable', 'string', 'max:2048'],
        ];
    }

    /**
     * Validate bot protection when configured.
     */
    public function validateTurnstile(TurnstileVerifier $turnstile): void
    {
        if ($turnstile->configured()) {
            $status = $turnstile->verify($this);
            if ($status === TurnstileStatus::Invalid) {
                throw ValidationException::withMessages([
                    'cf-turnstile-response' => __('Security verification failed. Please try again.'),
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
        ]);
    }
}
