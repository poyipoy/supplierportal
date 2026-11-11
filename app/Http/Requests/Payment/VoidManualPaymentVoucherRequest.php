<?php

namespace App\Http\Requests\Payment;

use App\Models\PaymentVoucher;
use Illuminate\Foundation\Http\FormRequest;

class VoidManualPaymentVoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        $voucher = $this->route('paymentVoucher');

        return $voucher instanceof PaymentVoucher
            && ($this->user()?->can('void', $voucher) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'void_reason' => ['required', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'void_reason' => __('payment_voucher.fields.void_reason'),
        ];
    }
}
