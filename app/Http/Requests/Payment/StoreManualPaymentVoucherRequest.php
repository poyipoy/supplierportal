<?php

namespace App\Http\Requests\Payment;

use App\Models\PaymentVoucher;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreManualPaymentVoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', PaymentVoucher::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'ppn_amount' => $this->filled('ppn_amount') ? $this->input('ppn_amount') : '0',
            'pph_amount' => $this->filled('pph_amount') ? $this->input('pph_amount') : '0',
            'bank_fee' => $this->filled('bank_fee') ? $this->input('bank_fee') : '0',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $payeeType = (string) $this->input('payee_type');
        $method = (string) $this->input('payment_method');
        $manualBankRequired = $payeeType === PaymentVoucher::PAYEE_MANUAL
            && $method === PaymentVoucher::METHOD_BANK;

        return [
            'payee_type' => ['required', Rule::in(PaymentVoucher::PAYEE_TYPES)],
            'payee_supplier_hash' => [
                Rule::requiredIf($payeeType === PaymentVoucher::PAYEE_SUPPLIER),
                'nullable',
                'string',
                'max:64',
            ],
            'payee_employee_id' => [
                Rule::requiredIf($payeeType === PaymentVoucher::PAYEE_EMPLOYEE),
                'nullable',
                'integer',
            ],
            'payee_name' => [
                Rule::requiredIf($payeeType === PaymentVoucher::PAYEE_MANUAL),
                'nullable',
                'string',
                'max:150',
            ],
            'npwp' => ['nullable', 'string', 'max:50'],
            'bank_name' => [
                Rule::requiredIf($manualBankRequired),
                'nullable',
                'string',
                'max:100',
            ],
            'account_number' => [
                Rule::requiredIf($manualBankRequired),
                'nullable',
                'string',
                'max:50',
            ],
            'account_holder_name' => [
                Rule::requiredIf($manualBankRequired),
                'nullable',
                'string',
                'max:150',
            ],
            'voucher_date' => ['required', 'date_format:Y-m-d'],
            'payment_method' => ['required', Rule::in(PaymentVoucher::METHODS)],
            'reference_number' => ['nullable', 'string', 'max:150'],
            'description' => ['required', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'dpp_amount' => ['required', 'regex:/^\d{1,16}(\.\d{1,2})?$/'],
            'ppn_amount' => ['nullable', 'regex:/^\d{1,16}(\.\d{1,2})?$/'],
            'pph_amount' => ['nullable', 'regex:/^\d{1,16}(\.\d{1,2})?$/'],
            'bank_fee' => ['nullable', 'regex:/^\d{1,16}(\.\d{1,2})?$/'],
            'supporting' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,xlsx,xls,doc,docx', 'max:10240'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'payee_type' => __('payment_voucher.fields.payee_type'),
            'payee_supplier_hash' => __('payment_voucher.fields.supplier'),
            'payee_employee_id' => __('payment_voucher.fields.employee'),
            'payee_name' => __('payment_voucher.fields.payee_name'),
            'bank_name' => __('payment_voucher.fields.bank_name'),
            'account_number' => __('payment_voucher.fields.account_number'),
            'account_holder_name' => __('payment_voucher.fields.account_holder'),
            'voucher_date' => __('payment_voucher.fields.voucher_date'),
            'payment_method' => __('payment_voucher.fields.payment_method'),
            'description' => __('payment_voucher.fields.description'),
            'dpp_amount' => __('payment_voucher.fields.dpp_amount'),
            'ppn_amount' => __('payment_voucher.fields.ppn_amount'),
            'pph_amount' => __('payment_voucher.fields.pph_amount'),
            'bank_fee' => __('payment_voucher.fields.bank_fee'),
            'supporting' => __('payment_voucher.fields.supporting_file'),
        ];
    }
}
