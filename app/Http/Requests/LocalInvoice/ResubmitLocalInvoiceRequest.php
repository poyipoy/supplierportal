<?php

namespace App\Http\Requests\LocalInvoice;

use App\Models\LocalInvoice;
use App\Models\LocalInvoiceDocument;
use App\Services\VendorMaster\VendorMasterService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ResubmitLocalInvoiceRequest extends StoreLocalInvoiceRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('resubmit', $this->route('invoice'));
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        // Validate the shape before either retention query uses these IDs.
        Validator::make($this->only('kept_document_ids'), [
            'kept_document_ids' => ['nullable', 'array'],
            'kept_document_ids.*' => ['required', 'integer'],
        ], [
            'kept_document_ids.array' => __('local_invoice.validation.retained_invalid'),
            'kept_document_ids.*.required' => __('local_invoice.validation.retained_invalid'),
            'kept_document_ids.*.integer' => __('local_invoice.validation.retained_invalid'),
        ])->validate();

        if (is_string($this->input('recovery_reason'))) {
            $this->merge(['recovery_reason' => trim($this->input('recovery_reason'))]);
        }

        $invoice = $this->route('invoice');
        if ($invoice && $this->has('invoice_number') && $this->input('invoice_number') !== $invoice->invoice_number) {
            throw ValidationException::withMessages([
                'invoice_number' => __('local_invoice.validation.revision_identity', ['uploaded' => $this->input('invoice_number'), 'number' => $invoice->invoice_number]),
            ]);
        }

        $this->dropSupersededKeptDocuments($invoice);
    }

    /**
     * Tipe berkas tunggal (invoice, faktur pajak) memakai semantik ganti: begitu
     * supplier mengunggah berkas baru, dokumen lama bertipe sama tidak lagi
     * dibawa ke revisi berikutnya alih-alih ditolak karena melebihi batas.
     */
    private function dropSupersededKeptDocuments(?LocalInvoice $invoice): void
    {
        $keptIds = array_values(array_filter((array) $this->input('kept_document_ids', [])));
        if (! $invoice || $keptIds === []) {
            return;
        }

        $replacedTypes = array_values(array_filter(
            array_keys(LocalInvoiceDocument::MAX_FILES_PER_TYPE),
            fn (string $type) => LocalInvoiceDocument::replacesOnUpload($type) && ! empty($this->file($type))
        ));

        if ($replacedTypes === []) {
            return;
        }

        $superseded = $invoice->documents()
            ->whereIn('id', $keptIds)
            ->whereIn('document_type', $replacedTypes)
            ->pluck('id')
            ->all();

        if ($superseded === []) {
            return;
        }

        $this->merge([
            'kept_document_ids' => array_values(array_diff(
                array_map('intval', $keptIds),
                array_map('intval', $superseded)
            )),
        ]);
    }

    public function rules(): array
    {
        $rules = parent::rules();
        $invoice = $this->route('invoice');

        if ($invoice?->status !== LocalInvoice::STATUS_CANCELLED && $invoice?->po_source === 'MANUAL' && $invoice->local_purchase_order_id === null) {
            $rules['local_purchase_order_id'] = ['nullable', 'integer', 'exists:local_purchase_orders,id'];
            $rules['goods_receipt_ids'] = ['required_with:local_purchase_order_id', 'nullable', 'array', 'min:1'];
        }

        // The invoice number is its business identity and remains fixed across revisions.
        $rules['invoice_number'] = ['required', Rule::in([$invoice->invoice_number])];

        if ($invoice->status === LocalInvoice::STATUS_CANCELLED) {
            $rules['recovery_reason'] = ['required', 'string', 'max:1000'];
            $rules['business_document_valid'] = ['accepted'];
        }

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
        foreach (['purchase_order', 'goods_receipt'] as $type) {
            if ($retainedDocs->where('document_type', $type)->isNotEmpty()) {
                $rules = array_merge($rules, $this->fileRule($type, false));
            }
        }

        $rules['kept_document_ids'] = ['nullable', 'array'];
        $rules['kept_document_ids.*'] = ['integer'];
        $rules['kept_document_ids'][] = function ($attribute, $value, $fail) use ($invoice, $retainedDocs) {
            if (! empty($value)) {
                // IDOR verification: strictly check ownership
                $validCount = $invoice->documents()->whereIn('id', $value)->count();
                if ($validCount !== count(array_unique($value))) {
                    $fail(__('local_invoice.validation.retained_invalid'));

                    return;
                }
            }

            foreach (['invoice', 'tax_invoice', 'purchase_order', 'goods_receipt', 'delivery_note', 'supporting'] as $type) {
                $retainedCount = $retainedDocs->where('document_type', $type)->count();
                $uploaded = $this->file($type);
                $newCount = is_array($uploaded) ? count($uploaded) : ($uploaded ? 1 : 0);
                $max = LocalInvoiceDocument::maxFilesFor($type);

                // Revisi yang hanya mempertahankan berkas lama tidak diblokir:
                // dokumen warisan boleh melebihi batas baru selama tidak ditambah.
                if (($newCount > 0 || in_array($type, ['purchase_order', 'goods_receipt'], true)) && $retainedCount + $newCount > $max) {
                    $fail(__('local_invoice.validation.file_limit', [
                        'type' => __('local_invoice.labels.'.$type),
                        'count' => $max,
                    ]));
                }
            }
        };

        return $rules;
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'recovery_reason.required' => __('local_invoice.recovery.reason_required'),
            'recovery_reason.string' => __('local_invoice.recovery.reason_required'),
            'recovery_reason.max' => __('local_invoice.recovery.reason_max'),
            'business_document_valid.accepted' => __('local_invoice.recovery.confirm_required'),
        ]);
    }
}
