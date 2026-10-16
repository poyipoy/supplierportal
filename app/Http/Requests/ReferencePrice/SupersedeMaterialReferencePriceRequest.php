<?php

namespace App\Http\Requests\ReferencePrice;

use Illuminate\Foundation\Http\FormRequest;

class SupersedeMaterialReferencePriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('supersede', $this->route('referencePrice')) ?? false;
    }

    public function rules(): array
    {
        return [
            'price_per_kg' => ['required', 'numeric', 'gt:0', 'max:99999999999.9999'],
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'valid_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
