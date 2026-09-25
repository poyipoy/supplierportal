<?php

namespace App\Http\Requests\LocalInvoice;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveLocalPurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active && ($this->user()->isFinance() || $this->user()->isAdmin() || $this->user()->isPurchasing());
    }

    public function rules(): array
    {
        $po = $this->route('purchaseOrder');

        return [
            'po_number' => ['required', 'string', 'max:100', Rule::unique('local_purchase_orders')->ignore($po?->id)],
            'supplier_id' => ['required', 'integer', 'exists:users,id'],
            'po_date' => ['required', 'date_format:Y-m-d'],
            'total_amount' => ['required', 'numeric', 'gt:0', 'regex:/^\d{1,18}(\.\d{1,2})?$/'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
