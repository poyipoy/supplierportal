<?php

namespace App\Http\Requests\ReferencePrice;

use App\Models\ExchangeRate;
use App\Models\MaterialMaster;
use App\Models\MaterialReferencePrice;
use App\Models\PrItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create a reference price. The material is chosen by its code; overlap and
 * the remaining business rules are checked by MaterialReferencePriceService
 * under the material row lock.
 */
class SaveMaterialReferencePriceRequest extends FormRequest
{
    public ?MaterialMaster $material = null;

    public function authorize(): bool
    {
        return $this->user()?->can('create', MaterialReferencePrice::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'material_code' => ['required', 'string', 'max:100'],
            'shape' => ['required', Rule::in([PrItem::SHAPE_FLAT, PrItem::SHAPE_ROUND, PrItem::SHAPE_HOLLOW])],
            'dimension_min' => ['required', 'integer', 'min:1', 'max:999999'],
            'dimension_max' => ['required', 'integer', 'gte:dimension_min', 'max:999999'],
            'currency' => ['required', Rule::in(ExchangeRate::CURRENCIES)],
            'price_per_kg' => ['required', 'numeric', 'gt:0', 'max:99999999999.9999'],
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'valid_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('material_code')) {
                return;
            }
            $code = mb_strtoupper(trim((string) $this->input('material_code')));
            $this->material = MaterialMaster::query()->where('normalized_code', $code)->first();
            if ($this->material === null) {
                $validator->errors()->add('material_code', __('materials.reference_prices.material_not_found'));
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            ...$this->safe()->except('material_code'),
            'material_master_id' => $this->material->id,
        ];
    }
}
