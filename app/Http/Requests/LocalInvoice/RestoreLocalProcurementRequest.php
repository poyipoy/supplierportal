<?php

namespace App\Http\Requests\LocalInvoice;

use Illuminate\Foundation\Http\FormRequest;

class RestoreLocalProcurementRequest extends FormRequest
{
    public function authorize(): bool
    {
        $gr = $this->route('goodsReceipt');

        return $this->user()->can($gr ? 'restoreGoodsReceipt' : 'restore', $gr ? $gr->purchaseOrder : $this->route('purchaseOrder'));
    }

    protected function prepareForValidation(): void
    {
        $this->errorBag = $this->route('goodsReceipt') ? 'restoreGr' : 'restorePo';
        $target = $this->route('goodsReceipt') ?? $this->route('purchaseOrder');
        $context = ['_recovery_kind' => $this->route('goodsReceipt') ? 'gr' : 'po', '_recovery_target' => $target->hash];
        $this->merge($context);
        // The exception handler flashes the original HTTP request, not this FormRequest.
        $this->container->make('request')->merge($context);
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim($this->input('reason'))]);
        }
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:1000'], 'business_document_valid' => ['accepted']];
    }

    public function messages(): array
    {
        return ['reason.required' => __('local_procurement.recovery.reason_required'),
            'reason.string' => __('local_procurement.recovery.reason_required'),
            'reason.max' => __('local_procurement.recovery.reason_max'),
            'business_document_valid.accepted' => __('local_procurement.recovery.confirm_required')];
    }
}
