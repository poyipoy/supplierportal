<?php

namespace App\Http\Requests\VendorMaster;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSupplierPaymentTermRequest extends FormRequest
{
    public function authorize(): bool
    {
        $vendor = $this->route('vendor');
        // Sama dengan halaman detail: vendor di luar Vendor Master tampil sebagai 404.
        abort_unless($vendor instanceof User && $vendor->isLocalEligible() && $vendor->supplier, 404);

        return $this->user()->can('updatePaymentTerm', $vendor->supplier);
    }

    public function rules(): array
    {
        return [
            'payment_term_days' => ['required', 'integer', 'between:1,365'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'payment_term_days.required' => __('local_procurement.payment_term.validation.required'),
            'payment_term_days.integer' => __('local_procurement.payment_term.validation.range'),
            'payment_term_days.between' => __('local_procurement.payment_term.validation.range'),
            'reason.required' => __('local_procurement.payment_term.validation.reason_required'),
            'reason.min' => __('local_procurement.payment_term.validation.reason_length'),
            'reason.max' => __('local_procurement.payment_term.validation.reason_length'),
        ];
    }

    public function attributes(): array
    {
        return [
            'payment_term_days' => __('local_procurement.payment_term.field'),
            'reason' => __('local_procurement.payment_term.reason'),
        ];
    }
}
