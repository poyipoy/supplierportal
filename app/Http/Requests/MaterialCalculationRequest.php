<?php

namespace App\Http\Requests;

use App\Models\PrItem;
use App\Services\Materials\MaterialResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MaterialCalculationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('material_master_id') && $this->filled('material_name')) {
            $name = (string) $this->input('material_name');
            $resolver = app(MaterialResolver::class);
            $material = $resolver->resolveExact($name);
            if (! $material) {
                $stripped = preg_replace('/[\s\-_]+(F|R|H|FLAT|ROUND|HOLLOW)$/i', '', trim($name));
                $material = $resolver->resolveExact($stripped);
                if (! $material) {
                    $nospace = str_replace(' ', '', $stripped);
                    $material = $resolver->resolveExact($nospace);
                }
            }
            if ($material) {
                $this->merge(['material_master_id' => $material->id]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'material_master_id' => [
                'required',
                'integer',
                Rule::exists('material_masters', 'id')->where('is_active', true),
            ],
            'material_name' => ['nullable', 'string', 'max:255'],
            'shape' => ['nullable', Rule::in(PrItem::SHAPES)],
            'quantity' => ['required', 'integer', 'min:1'],
            'hs_code' => ['nullable', 'string', 'max:20'],
            'hs_code_manual_override' => ['nullable', 'boolean'],
            'thickness' => ['nullable', 'numeric', 'gt:0'],
            'd_inner' => ['nullable', 'numeric', 'gt:0'],
            'd_outer' => ['nullable', 'numeric', 'gt:0'],
            'width' => ['nullable', 'numeric', 'gt:0'],
            'length' => ['nullable', 'numeric', 'gt:0'],
            'manual_hs_code' => ['nullable', 'string', 'max:20'],
            'weight_needed' => ['nullable', 'numeric'],
            'weight_manual_override' => ['nullable', 'boolean'],
        ];
    }
}
