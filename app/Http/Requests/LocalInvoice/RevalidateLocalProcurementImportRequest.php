<?php

namespace App\Http\Requests\LocalInvoice;

use Illuminate\Foundation\Http\FormRequest;

class RevalidateLocalProcurementImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('revalidate', $this->route('procurementImport'));
    }

    public function rules(): array
    {
        return ['expected_attempt' => ['required', 'integer', 'min:0']];
    }

    public function messages(): array
    {
        return ['expected_attempt.required' => __('local_procurement.revalidation.refresh_required'),
            'expected_attempt.integer' => __('local_procurement.revalidation.refresh_required'),
            'expected_attempt.min' => __('local_procurement.revalidation.refresh_required')];
    }
}
