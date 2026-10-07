<?php

namespace App\Http\Requests\LocalInvoice;

use App\Models\LocalInvoice;
use App\Services\LocalInvoice\DeliveryScheduleValidator;
use App\Services\LocalInvoice\InvoiceFilenameParser;
use App\Services\VendorMaster\VendorMasterService;
use App\Support\BusinessTime;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StoreLocalInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', LocalInvoice::class);
    }

    protected function prepareForValidation(): void
    {
        $parser = app(InvoiceFilenameParser::class);

        $rawInvoiceInput = $this->input('invoice_number');
        $rawTaxInput = $this->input('tax_invoice_number');
        if (! $this->has('_original_invoice_number') && $rawInvoiceInput !== null) {
            $this->merge(['_original_invoice_number' => $rawInvoiceInput]);
        }
        if (! $this->has('_original_tax_invoice_number') && $rawTaxInput !== null) {
            $this->merge(['_original_tax_invoice_number' => $rawTaxInput]);
        }

        // Derive invoice number from uploaded invoice file(s)
        $invoiceFiles = $this->file('invoice');
        if (! empty($invoiceFiles)) {
            try {
                $derivedInvoiceNumber = $parser->parseInvoiceNumber($invoiceFiles, $rawInvoiceInput);
                if ($derivedInvoiceNumber !== null) {
                    $this->merge(['invoice_number' => $derivedInvoiceNumber]);
                }
            } catch (\Throwable) {
                // Defer to withValidator so file validation errors take precedence
            }
        }

        // Derive tax invoice number from uploaded tax_invoice file(s)
        $taxFiles = $this->file('tax_invoice');
        if (! empty($taxFiles)) {
            try {
                $derivedTaxNumber = $parser->parseTaxInvoiceNumber($taxFiles, $rawTaxInput);
                if ($derivedTaxNumber !== null) {
                    $this->merge(['tax_invoice_number' => $derivedTaxNumber]);
                }
            } catch (\Throwable) {
                // Defer to withValidator
            }
        }

        $poNumber = $this->input('po_number');
        $internalPoReference = $this->input('internal_po_reference');
        $manualPoNumber = $this->input('manual_po_number');
        $manualGrReference = $this->input('manual_gr_reference') ?? $this->input('gr_reference');
        $poSource = $this->input('po_source');

        // Older integrations submitted only one of the reference fields. Keep
        // those requests compatible while the authoritative UI sends the PO id
        // and whole GR ids explicitly.
        if (blank($poNumber)) {
            $poNumber = $internalPoReference ?: $manualPoNumber;
        }
        if (blank($poSource)) {
            if (filled($internalPoReference)) {
                $poSource = 'INTERNAL';
            } elseif (filled($manualPoNumber) || filled($manualGrReference)) {
                $poSource = 'MANUAL';
            }
        }
        if ($poSource === 'INTERNAL' && blank($internalPoReference)) {
            $internalPoReference = $poNumber;
        }
        if ($poSource === 'MANUAL' && blank($manualPoNumber)) {
            $manualPoNumber = $poNumber;
        }

        $rawTaxInvoiceNumber = $this->input('tax_invoice_number');
        if (is_string($rawTaxInvoiceNumber) && trim($rawTaxInvoiceNumber) !== '') {
            $cleaned = trim($rawTaxInvoiceNumber);
            $digits = preg_replace('/\D/', '', $cleaned);
            if (strlen($digits) === 17) {
                $rawTaxInvoiceNumber = sprintf(
                    '%s.%s.%s.%s',
                    substr($digits, 0, 2),
                    substr($digits, 2, 2),
                    substr($digits, 4, 2),
                    substr($digits, 6, 11)
                );
            } elseif (strlen($digits) === 16) {
                $rawTaxInvoiceNumber = sprintf(
                    '%s.%s-%s.%s',
                    substr($digits, 0, 3),
                    substr($digits, 3, 3),
                    substr($digits, 6, 2),
                    substr($digits, 8, 8)
                );
            }
        } else {
            $rawTaxInvoiceNumber = null;
        }

        $this->merge([
            'ppn_scheme' => $this->input('ppn_scheme', '11%'),
            'tax_invoice_number' => $rawTaxInvoiceNumber,
            'po_number' => $poNumber,
            'po_source' => $poSource,
            'internal_po_reference' => $internalPoReference,
            'manual_po_number' => $manualPoNumber,
        ]);
    }

    public function rules(): array
    {
        $supplier = $this->user()->supplier;
        $vendorMasterService = app(VendorMasterService::class);
        $requiresTaxInvoice = $supplier ? $vendorMasterService->requiresFakturPajak($supplier) : false;
        $requiresSuratJalan = $supplier ? $vendorMasterService->requiresSuratJalan($supplier) : false;

        $fileRules = ['file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'];

        $baseRules = [
            // New UI submits local_purchase_order_id + whole GR IDs. Legacy manual/reference
            // fields remain accepted for grandfathered integrations and existing records.
            'local_purchase_order_id' => ['nullable', 'integer', 'exists:local_purchase_orders,id'],
            'goods_receipt_ids' => ['required_with:local_purchase_order_id', 'nullable', 'array', 'min:1'],
            'goods_receipt_ids.*' => ['required', 'integer', 'distinct', 'exists:local_goods_receipts,id'],
            'po_source' => ['nullable', 'string', Rule::in(['INTERNAL', 'MANUAL'])],
            'po_number' => ['required_without_all:local_purchase_order_id,internal_po_reference,manual_po_number', 'nullable', 'string', 'max:100'],
            'internal_po_reference' => ['required_if:po_source,INTERNAL', 'nullable', 'string', 'max:100'],
            'manual_po_number' => ['required_if:po_source,MANUAL', 'nullable', 'string', 'max:100'],
            'manual_gr_reference' => ['required_if:po_source,MANUAL', 'nullable', 'string', 'max:1000'],
            'gr_reference' => ['nullable', 'string', 'max:1000'],
            'invoice_number' => ['required', 'string', 'max:100', Rule::unique('local_invoices')->where('supplier_id', $this->user()->id)],
            'invoice_date' => ['required', 'date_format:Y-m-d'],
            'invoice_amount' => ['required', 'numeric', 'min:0', 'regex:/^\d{1,18}(\.\d{1,2})?$/'],
            'ppn_scheme' => ['required', 'string', Rule::in(['11%', '1.1%', '0%'])],
            'tax_amount' => ['nullable', 'numeric', 'min:0', 'regex:/^\d{1,18}(\.\d{1,2})?$/'],
            'tax_invoice_number' => [
                $requiresTaxInvoice ? 'required' : 'nullable',
                'string',
                'regex:/^(\d{2}\.\d{2}\.\d{2}\.\d{11}|\d{3}\.\d{3}-\d{2}\.\d{8})$/',
                Rule::unique('local_invoices', 'tax_invoice_number')->where('supplier_id', $this->user()->id),
            ],
            'scheduled_physical_delivery_date' => [
                'nullable',
                'date_format:Y-m-d',
                'after_or_equal:'.BusinessTime::today()->toDateString(),
                function ($attribute, $value, $fail) {
                    if (! empty($value)) {
                        try {
                            app(DeliveryScheduleValidator::class)->assert($value, $attribute);
                        } catch (ValidationException $e) {
                            $fail('Jadwal penyerahan dokumen fisik harus jatuh pada hari Rabu.');
                        }
                    }
                },
            ],
        ];

        return array_merge(
            $baseRules,
            $this->fileRule('invoice', true),
            $this->fileRule('tax_invoice', $requiresTaxInvoice),
            $this->fileRule('delivery_note', $requiresSuratJalan),
            $this->fileRule('surat_jalan', false),
            $this->fileRule('supporting', false)
        );
    }

    protected function fileRule(string $field, bool $required): array
    {
        $fileVal = $this->file($field);

        if (is_array($fileVal)) {
            return [
                $field => [$required ? 'required' : 'nullable', 'array', 'min:1', 'max:5'],
                $field.'.*' => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            ];
        }

        return [
            $field => array_merge([$required ? 'required' : 'nullable'], ['file', 'mimes:pdf,jpg,jpeg,png', 'max:5120']),
        ];
    }

    public function messages(): array
    {
        $messages = [
            'tax_invoice_number.required' => __('local_invoice.validation.tax_number_required'),
            'tax_invoice_number.regex' => __('local_invoice.validation.tax_number_format'),
            'tax_invoice_number.unique' => __('local_invoice.validation.tax_number_duplicate'),
            'invoice_number.required' => __('local_invoice.validation.invoice_number_required'),
            'invoice_number.unique' => __('local_invoice.validation.invoice_duplicate'),
            'invoice_date.required' => __('local_invoice.validation.invoice_date_required'),
            'invoice_amount.required' => __('local_invoice.validation.dpp_required'),
            'goods_receipt_ids.required_with' => __('local_invoice.validation.whole_gr_required'),
            'goods_receipt_ids.min' => __('local_invoice.validation.whole_gr_required'),
            'invoice.required' => __('local_invoice.validation.invoice_file_required'),
            'tax_invoice.required' => __('local_invoice.validation.tax_file_required'),
            'delivery_note.required' => __('local_invoice.validation.dn_file_required'),
            'scheduled_physical_delivery_date.after_or_equal' => __('local_invoice.validation.delivery_past'),
        ];

        foreach ([
            'invoice' => __('local_invoice.labels.invoice'),
            'tax_invoice' => __('local_invoice.labels.tax_invoice'),
            'delivery_note' => __('local_invoice.labels.delivery_note'),
            'supporting' => __('local_invoice.labels.supporting'),
        ] as $field => $label) {
            if (is_array($this->file($field))) {
                $messages["{$field}.max"] = __('local_invoice.validation.file_count', ['type' => $label]);
                $messages["{$field}.*.mimes"] = __('local_invoice.validation.file_type', ['type' => $label]);
                $messages["{$field}.*.max"] = __('local_invoice.validation.file_size', ['type' => $label]);
            } else {
                $messages["{$field}.max"] = __('local_invoice.validation.file_size', ['type' => $label]);
                $messages["{$field}.mimes"] = __('local_invoice.validation.file_type', ['type' => $label]);
            }
        }

        return $messages;
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $parser = app(InvoiceFilenameParser::class);

            $invoiceFiles = $this->file('invoice');
            if (! empty($invoiceFiles) && ! $validator->errors()->has('invoice') && ! $validator->errors()->has('invoice.*')) {
                try {
                    $rawSubmitted = $this->input('_original_invoice_number');
                    $derivedInvoiceNumber = $parser->parseInvoiceNumber($invoiceFiles, $rawSubmitted);
                    if (is_string($rawSubmitted) && trim($rawSubmitted) !== '' && trim($rawSubmitted) !== $derivedInvoiceNumber) {
                        $validator->errors()->add('invoice_number', __('local_invoice.validation.filename_mismatch', ['number' => $rawSubmitted, 'filename' => $derivedInvoiceNumber]));
                    }
                } catch (ValidationException $e) {
                    foreach ($e->errors() as $key => $messages) {
                        foreach ($messages as $msg) {
                            $validator->errors()->add($key, $msg);
                        }
                    }
                }
            }

            $taxFiles = $this->file('tax_invoice');
            if (! empty($taxFiles) && ! $validator->errors()->has('tax_invoice') && ! $validator->errors()->has('tax_invoice.*')) {
                try {
                    $rawSubmittedTax = $this->input('_original_tax_invoice_number');
                    $derivedTaxNumber = $parser->parseTaxInvoiceNumber($taxFiles, $rawSubmittedTax);
                    if ($derivedTaxNumber !== null && is_string($rawSubmittedTax) && trim($rawSubmittedTax) !== '' && $parser->normalizeDigits($rawSubmittedTax) !== $parser->normalizeDigits($derivedTaxNumber)) {
                        $validator->errors()->add('tax_invoice_number', __('local_invoice.validation.tax_filename', ['number' => $rawSubmittedTax, 'filename' => $derivedTaxNumber]));
                    }
                } catch (ValidationException $e) {
                    foreach ($e->errors() as $key => $messages) {
                        foreach ($messages as $msg) {
                            $validator->errors()->add($key, $msg);
                        }
                    }
                }
            }
        });
    }
}
