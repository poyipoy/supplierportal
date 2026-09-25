<?php

namespace App\Http\Requests\LocalInvoice;

use App\Models\LocalInvoice;
use App\Services\LocalInvoice\InvoiceFilenameParser;
use App\Services\VendorMaster\VendorMasterService;
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
                'after_or_equal:today',
                function ($attribute, $value, $fail) {
                    if (! empty($value) && Carbon::parse($value)->dayOfWeek !== Carbon::WEDNESDAY) {
                        $fail('Jadwal penyerahan dokumen fisik harus jatuh pada hari Rabu.');
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
            'tax_invoice_number.required' => 'Nomor Faktur Pajak wajib diisi untuk vendor PKP.',
            'tax_invoice_number.regex' => 'Format Nomor Faktur Pajak tidak valid. Gunakan format Coretax 17 digit (contoh: 01.00.26.00000000001) atau 16 digit e-Faktur.',
            'tax_invoice_number.unique' => 'Nomor Faktur Pajak ini sudah pernah digunakan pada tagihan Anda sebelumnya.',
            'invoice_number.required' => 'Nomor Invoice wajib diisi.',
            'invoice_number.unique' => 'Nomor Invoice ini sudah pernah Anda submit sebelumnya.',
            'invoice_date.required' => 'Tanggal Invoice wajib diisi.',
            'invoice_amount.required' => 'Nilai DPP Invoice wajib diisi.',
            'goods_receipt_ids.required_with' => 'Paling sedikit satu Penerimaan Barang (GR) utuh wajib dipilih.',
            'goods_receipt_ids.min' => 'Paling sedikit satu Penerimaan Barang (GR) utuh wajib dipilih.',
            'invoice.required' => 'Berkas Invoice wajib diunggah.',
            'tax_invoice.required' => 'Berkas Faktur Pajak wajib diunggah untuk vendor PKP.',
            'delivery_note.required' => 'Berkas Surat Jalan (Delivery Note) wajib diunggah untuk kategori Barang.',
            'scheduled_physical_delivery_date.after_or_equal' => 'Jadwal penyerahan dokumen fisik tidak boleh di masa lampau.',
        ];

        foreach ([
            'invoice' => 'Berkas Invoice',
            'tax_invoice' => 'Faktur Pajak',
            'delivery_note' => 'Surat Jalan',
            'supporting' => 'Dokumen Pendukung',
        ] as $field => $label) {
            if (is_array($this->file($field))) {
                $messages["{$field}.max"] = "Maksimal 5 berkas yang diizinkan untuk {$label}.";
                $messages["{$field}.*.mimes"] = "Berkas {$label} harus berformat PDF, JPG, JPEG, atau PNG.";
                $messages["{$field}.*.max"] = "Ukuran setiap berkas {$label} tidak boleh melebihi 5 MB.";
            } else {
                $messages["{$field}.max"] = "Ukuran berkas {$label} tidak boleh melebihi 5 MB.";
                $messages["{$field}.mimes"] = "Berkas {$label} harus berformat PDF, JPG, JPEG, atau PNG.";
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
                        $validator->errors()->add('invoice_number', "Nomor invoice yang diinput ({$rawSubmitted}) tidak sesuai dengan nama berkas invoice yang diunggah ({$derivedInvoiceNumber}). Nomor tagihan wajib mengikuti nama berkas.");
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
                        $validator->errors()->add('tax_invoice_number', "Nomor faktur pajak yang diinput ({$rawSubmittedTax}) tidak sesuai dengan nama berkas faktur pajak yang diunggah ({$derivedTaxNumber}).");
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
