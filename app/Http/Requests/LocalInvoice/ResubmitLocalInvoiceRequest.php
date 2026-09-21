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
        $invoice = $this->route('invoice');

        // The invoice number is its business identity and remains fixed across revisions.
        $rules['invoice_number'] = ['required', Rule::in([$invoice->invoice_number])];

        $supplier = $this->user()->supplier;
        $vendorMasterService = app(VendorMasterService::class);
        $requiresTaxInvoice = $supplier ? $vendorMasterService->requiresFakturPajak($supplier) : false;
        $requiresSuratJalan = $supplier ? $vendorMasterService->requiresSuratJalan($supplier) : false;

        $rules['tax_invoice_number'] = [
            $requiresTaxInvoice ? 'required' : 'nullable',
            'string',
            'regex:/^(\d{2}\.\d{2}\.\d{2}\.\d{11}|\d{3}\.\d{3}-\d{2}\.\d{8})$/',
            Rule::unique('local_invoices', 'tax_invoice_number')
                ->where('supplier_id', $this->user()->id)
                ->ignore($invoice?->id),
        ];

        $keptIds = array_filter((array) $this->input('kept_document_ids', []));
        $retainedDocs = $invoice ? $invoice->documents()->whereIn('id', $keptIds)->get() : collect();

        // If retained docs satisfy requirement, make new upload optional
        if ($retainedDocs->where('document_type', 'invoice')->isNotEmpty()) {
            $rules = array_merge($rules, $this->fileRule('invoice', false));
        }
        if ($retainedDocs->where('document_type', 'tax_invoice')->isNotEmpty()) {
            $rules = array_merge($rules, $this->fileRule('tax_invoice', false));
        }
        if ($retainedDocs->where('document_type', 'delivery_note')->isNotEmpty()) {
            $rules = array_merge($rules, $this->fileRule('delivery_note', false));
        }

        $rules['kept_document_ids'] = ['nullable', 'array'];
        $rules['kept_document_ids.*'] = ['integer'];
        $rules['kept_document_ids'][] = function ($attribute, $value, $fail) use ($invoice, $retainedDocs) {
            if (! empty($value)) {
                // IDOR verification: strictly check ownership
                $validCount = $invoice->documents()->whereIn('id', $value)->count();
                if ($validCount !== count(array_unique($value))) {
                    $fail('Beberapa dokumen lampiran yang dipilih tidak valid untuk tagihan ini.');
                    return;
                }
            }

            foreach (['invoice', 'tax_invoice', 'delivery_note', 'supporting'] as $type) {
                $retainedCount = $retainedDocs->where('document_type', $type)->count();
                $uploaded = $this->file($type);
                $newCount = is_array($uploaded) ? count($uploaded) : ($uploaded ? 1 : 0);
                if ($retainedCount + $newCount > 5) {
                    $fail('Total berkas tersimpan dan baru untuk ' . $type . ' melebihi batas maksimal 5 berkas.');
                }
            }
        };

        return $rules;
    }
}
