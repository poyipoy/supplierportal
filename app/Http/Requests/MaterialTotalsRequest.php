<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MaterialTotalsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'purchasing' && (bool) $this->user()?->is_active;
    }

    public function rules(): array
    {
        return [
            'items' => ['present', 'array', 'max:500'],
            'items.*' => ['array'],
            'items.*.index' => ['nullable', 'integer', 'min:0'],
            'items.*.material_master_id' => ['nullable'],
            'items.*.shape' => ['nullable', 'string', 'max:30'],
            'items.*.quantity' => ['nullable'],
            'items.*.thickness' => ['nullable'],
            'items.*.width' => ['nullable'],
            'items.*.length' => ['nullable'],
            'items.*.d_inner' => ['nullable'],
            'items.*.d_outer' => ['nullable'],
            'items.*.weight_needed' => ['nullable'],
            'items.*.weight_manual_override' => ['nullable'],
            'items.*.hs_code' => ['nullable', 'string', 'max:30'],
            'items.*.hs_code_manual_override' => ['nullable'],
        ];
    }
}
