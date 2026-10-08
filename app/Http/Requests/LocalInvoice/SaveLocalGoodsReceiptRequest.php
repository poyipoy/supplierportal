<?php

namespace App\Http\Requests\LocalInvoice;

use App\Models\LocalGoodsReceipt;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveLocalGoodsReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active && ($this->user()->isFinance() || $this->user()->isAdmin() || $this->user()->isPurchasing());
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['uom' => LocalGoodsReceipt::normalizeUom($this->input('uom'))]);
    }

    public function rules(): array
    {
        $gr = $this->route('goodsReceipt');

        return [
            'gr_number' => ['required', 'string', 'max:100', Rule::unique('local_goods_receipts')->ignore($gr?->id)],
            'gr_date' => ['required', 'date_format:Y-m-d'],
            'qty' => ['required', 'numeric', 'gt:0', 'regex:'.LocalGoodsReceipt::QUANTITY_PATTERN],
            'uom' => ['required', Rule::in(LocalGoodsReceipt::UOMS)],
            'description' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
