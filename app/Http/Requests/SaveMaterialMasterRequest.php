<?php

namespace App\Http\Requests;

use App\Models\MaterialMaster;
use App\Services\Materials\MaterialCodeNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveMaterialMasterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'normalized_code' => app(MaterialCodeNormalizer::class)->normalize($this->input('material_code')),
        ]);
    }

    public function rules(): array
    {
        return [
            'material_code' => ['required', 'string', 'max:100'],
            'normalized_code' => [
                'required',
                'string',
                'max:100',
                Rule::unique('material_masters', 'normalized_code')->ignore($this->route('materialMaster')),
            ],
            'raw_category' => ['nullable', 'string', 'max:100'],
            'hs_category' => ['nullable', Rule::in(MaterialMaster::HS_CATEGORIES)],
            'density_profile' => ['required', Rule::in(MaterialMaster::DENSITY_PROFILES)],
            'manufacturer_scope' => ['required', Rule::in(MaterialMaster::MANUFACTURER_SCOPES)],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
