<?php

namespace App\Http\Requests\SupplierRegistration;

use App\Models\Supplier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApproveRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && in_array($this->user()->role, ['admin', 'finance', 'purchasing'], true);
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('scopes')) {
            $this->merge([
                'scopes' => [Supplier::SCOPE_LOCAL],
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['required', 'string', Rule::in([Supplier::SCOPE_LOCAL]), 'distinct'],
            'vendor_category' => ['required', 'string', Rule::in(Supplier::VENDOR_CATEGORIES)],
            'confirm_bank_declaration' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
