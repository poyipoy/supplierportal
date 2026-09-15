<?php

namespace App\Http\Requests\LocalInvoice;

use App\Services\VendorMaster\VendorMasterService;
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

        $supplier = $this->user()->supplier;
        $vendorMasterService = app(VendorMasterService::class);
        $requiresTaxInvoice = $supplier ? $vendorMasterService->requiresFakturPajak($supplier) : false;

        $rules['tax_invoice_number'] = [
            $requiresTaxInvoice ? 'required' : 'nullable',
            'string',
            'regex:/^\d{3}\.\d{3}-\d{2}\.\d{8}$/',
            Rule::unique('local_invoices', 'tax_invoice_number')
                ->where('supplier_id', $this->user()->id)
                ->ignore($this->route('invoice')?->id),
        ];

        return $rules;
    }
}
