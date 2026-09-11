<?php

namespace App\Http\Requests\LocalInvoice;

use Illuminate\Validation\Rule;

class ResubmitLocalInvoiceRequest extends StoreLocalInvoiceRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('resubmit', $this->route('invoice'));
    }

    public function rules(): array
    {
        $rules = parent::rules();
        // The invoice number is its business identity and remains fixed across revisions.
        $rules['invoice_number'] = ['required', Rule::in([$this->route('invoice')->invoice_number])];

        return $rules;
    }
}
