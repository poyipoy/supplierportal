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
        $aliases = preg_split('/[\r\n,]+/', (string) $this->input('aliases_text', ''), -1, PREG_SPLIT_NO_EMPTY);
        $this->merge([
            'normalized_code' => app(MaterialCodeNormalizer::class)->normalize($this->input('material_code')),
            'aliases' => array_values(array_map('trim', $aliases ?: [])),
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
            'aliases_text' => ['nullable', 'string', 'max:2000'],
            'aliases' => ['nullable', 'array'],
            'aliases.*' => ['nullable', 'string', 'max:100', 'distinct:ignore_case'],
        ];
    }
}
