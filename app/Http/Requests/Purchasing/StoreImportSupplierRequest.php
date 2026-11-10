<?php

namespace App\Http\Requests\Purchasing;

use App\Models\Supplier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class StoreImportSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manageImportContact', Supplier::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'company_name' => trim((string) $this->input('company_name')),
            'email' => Str::lower(trim((string) $this->input('email'))),
        ]);
    }

    public function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
        ];
    }

    public function attributes(): array
    {
        return [
            'company_name' => __('supplier_contact.profile.company_name'),
            'email' => __('supplier_contact.profile.account_email'),
            'password' => __('supplier_contact.register.password'),
        ];
    }
}
