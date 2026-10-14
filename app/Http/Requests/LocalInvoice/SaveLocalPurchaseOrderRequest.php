<?php

namespace App\Http\Requests\LocalInvoice;

use App\Support\LocalPoPrNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
            'pr_number' => ['sometimes', 'nullable', 'string', 'max:100', function ($attribute, $value, $fail) use ($po) {
                try {
                    $number = LocalPoPrNumber::normalize($value);
                    if ($po && filled($po->pr_number) && LocalPoPrNumber::key($po->pr_number) !== LocalPoPrNumber::key($number)) {
                        $fail(__('local_procurement.pr_import.conflict', ['po' => $po->po_number]));
                    }
                } catch (ValidationException $exception) {
                    $fail($exception->validator->errors()->first('pr_number'));
                }
            }],
            'supplier_id' => ['required', 'integer', 'exists:users,id'],
            'po_date' => ['required', 'date_format:Y-m-d'],
            'total_amount' => ['required', 'numeric', 'gt:0', 'regex:/^\d{1,18}(\.\d{1,2})?$/'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function after(): array
    {
        return [function ($validator): void {
            $po = $this->route('purchaseOrder');
            if ($po && filled($po->pr_number) && array_key_exists('pr_number', $this->all()) && blank($this->input('pr_number'))) {
                $validator->errors()->add('pr_number', __('local_procurement.pr_import.conflict', ['po' => $po->po_number]));
            }
        }];
    }
}
