<?php

namespace App\Http\Requests\ReferencePrice;

use Illuminate\Foundation\Http\FormRequest;

class VoidMaterialReferencePriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('void', $this->route('referencePrice')) ?? false;
    }

    public function rules(): array
    {
        return ['void_reason' => ['required', 'string', 'min:3', 'max:1000']];
    }
}
