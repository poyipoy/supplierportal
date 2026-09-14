<?php

namespace App\Http\Requests\LocalInvoice;

use App\Models\LocalInvoice;
use App\Services\VendorMaster\VendorMasterService;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLocalInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', LocalInvoice::class);
    }

    protected function prepareForValidation(): void
    {
        $poSource = $this->input('po_source');
        if (! $poSource) {
            if ($this->filled('internal_po_reference')) {
                $poSource = 'INTERNAL';
            } elseif ($this->filled('manual_po_number') || $this->filled('po_number')) {
                $poSource = 'MANUAL';
            }
        }

        $poNumber = $this->input('po_number')
            ?: ($this->input('manual_po_number') ?: $this->input('internal_po_reference'));

        $manualGr = $this->input('manual_gr_reference') ?: $this->input('gr_reference');

        $this->merge([
            'po_source' => $poSource ? strtoupper($poSource) : 'MANUAL',
            'po_number' => $poNumber,
            'manual_gr_reference' => $manualGr,
            'ppn_scheme' => $this->input('ppn_scheme', '11%'),
        ]);
    }

    public function rules(): array
    {
        $supplier = $this->user()->supplier;
        $vendorMasterService = app(VendorMasterService::class);
        $requiresTaxInvoice = $supplier ? $vendorMasterService->requiresFakturPajak($supplier) : false;
        $requiresSuratJalan = $supplier ? $vendorMasterService->requiresSuratJalan($supplier) : false;

        $fileRules = ['file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'];

        return [
            'po_source' => ['required', 'string', Rule::in(['INTERNAL', 'MANUAL'])],
            'po_number' => ['required', 'string', 'max:100'],
            'internal_po_reference' => ['required_if:po_source,INTERNAL', 'nullable', 'string', 'max:100'],
            'manual_po_number' => ['required_if:po_source,MANUAL', 'nullable', 'string', 'max:100'],
            'manual_gr_reference' => ['required_if:po_source,MANUAL', 'nullable', 'string', 'max:100'],
            'gr_reference' => ['nullable', 'string', 'max:100'],
            'invoice_number' => ['required', 'string', 'max:100', Rule::unique('local_invoices')->where('supplier_id', $this->user()->id)],
            'invoice_date' => ['required', 'date_format:Y-m-d'],
            'invoice_amount' => ['required', 'numeric', 'min:0', 'regex:/^\d{1,18}(\.\d{1,2})?$/'],
            'ppn_scheme' => ['required', 'string', Rule::in(['11%', '1.1%', '0%'])],
            'tax_amount' => ['nullable', 'numeric', 'min:0', 'regex:/^\d{1,18}(\.\d{1,2})?$/'],
            'scheduled_physical_delivery_date' => [
                'nullable',
                'date_format:Y-m-d',
                function ($attribute, $value, $fail) {
                    if (! empty($value) && Carbon::parse($value)->dayOfWeek !== Carbon::WEDNESDAY) {
                        $fail('Physical document delivery schedule must be on a Wednesday.');
                    }
                },
            ],
            'invoice' => array_merge(['required'], $fileRules),
            'tax_invoice' => $requiresTaxInvoice ? array_merge(['required'], $fileRules) : array_merge(['nullable'], $fileRules),
            'delivery_note' => $requiresSuratJalan ? array_merge(['required'], $fileRules) : array_merge(['nullable'], $fileRules),
            'surat_jalan' => array_merge(['nullable'], $fileRules),
            'supporting' => array_merge(['nullable'], $fileRules),
        ];
    }
}
