<?php

namespace App\Http\Requests\SupplierRegistration;

use App\Services\SupplierRegistrationService;
use App\Support\SupplierComplianceQuestionnaire;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResubmitRegistrationRequest extends FormRequest
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

            // Documents (Optional upon resubmission: replaces old document if provided)
            'nib_file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'npwp_file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'sknr_file' => [Rule::requiredIf(fn () => $this->bankDetailsChanged()), 'nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'sppkp_file' => [Rule::requiredIf(fn () => $this->boolean('is_pkp')
                && ! app(SupplierRegistrationService::class)->hasReusableSppkp($this->attributes->get('registrationAccess')?->user)),
                'nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'skd_file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'company_profile_file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],

            'revision_notes' => ['nullable', 'string', 'max:1000'],
        ] + SupplierComplianceQuestionnaire::rules();
    }

    public function attributes(): array
    {
        return SupplierComplianceQuestionnaire::attributes();
    }

    public function messages(): array
    {
        return [
            'sppkp_file.required' => __('registration.sppkp_required'),
            'sknr_file.required' => __('registration.sknr_required_on_bank_change'),
        ];
    }

    /**
     * A carried-forward Surat Pernyataan Rekening vouches for the old account details only, so
     * changing the account on resubmit must come with a new declaration.
     */
    private function bankDetailsChanged(): bool
    {
        $account = $this->attributes->get('registrationAccess')?->user?->supplierBankAccounts()->latest('id')->first();

        if (! $account) {
            return true;
        }

        foreach (['bank_name', 'account_number', 'account_holder_name'] as $field) {
            if (trim((string) $this->input($field)) !== trim((string) $account->{$field})) {
                return true;
            }
        }

        return false;
    }

    public function safeResubmitData(): array
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
            'revision_notes',
            'questionnaire',
        ]);
    }
}
