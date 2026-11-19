<?php

namespace App\Http\Requests\Supplier;

use App\Models\SupplierGoodsLocation;
use App\Support\Procurement\PreshipmentValidation;
use Illuminate\Foundation\Http\FormRequest;

class StoreGoodsLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $location = $this->route('goodsLocation');
        if ($location instanceof SupplierGoodsLocation) {
            return $this->user()?->can('update', $location) ?? false;
        }

        return $this->user()?->can('create', SupplierGoodsLocation::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('country'))) {
            $trimmed = trim((string) $this->input('country'));
            $this->merge(['country' => $trimmed === '' ? null : strtoupper($trimmed)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return PreshipmentValidation::locationRules();
    }
}
