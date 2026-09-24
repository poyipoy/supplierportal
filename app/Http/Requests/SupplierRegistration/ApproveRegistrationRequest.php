<?php

namespace App\Http\Requests\SupplierRegistration;

use Illuminate\Foundation\Http\FormRequest;

class ApproveRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && in_array($this->user()->role, ['admin', 'finance', 'purchasing'], true);
    }

    public function rules(): array
    {
        return [
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['required', 'string', 'in:import,local', 'distinct'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
