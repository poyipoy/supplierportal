<?php

namespace App\Http\Requests\LocalInvoice;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveLocalGoodsReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active && ($this->user()->isFinance() || $this->user()->isAdmin() || $this->user()->isPurchasing());
    }

    public function rules(): array
    {
        $gr = $this->route('goodsReceipt');

        return [
            'gr_number' => ['required', 'string', 'max:100', Rule::unique('local_goods_receipts')->ignore($gr?->id)],
            'gr_date' => ['required', 'date_format:Y-m-d'],
            'qty' => ['required', 'numeric', 'gt:0', 'regex:/^\d{1,8}(\.\d{1,4})?$/'],
            'description' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
