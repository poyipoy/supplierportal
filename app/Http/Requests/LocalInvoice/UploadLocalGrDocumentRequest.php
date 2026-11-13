<?php

namespace App\Http\Requests\LocalInvoice;

use App\Models\LocalPurchaseOrder;
use Illuminate\Foundation\Http\FormRequest;

class UploadLocalGrDocumentRequest extends FormRequest
{
    private ?LocalPurchaseOrder $resolvedPurchaseOrder = null;

    public function authorize(): bool
    {
        return $this->user()?->can('manage', LocalPurchaseOrder::class) ?? false;
    }

    public function rules(): array
    {
        $isZip = $this->isZipUpload();
        $maxKilobytes = $isZip
            ? intdiv((int) config('native_file_security.zip.max_upload_bytes', 20 * 1024 * 1024), 1024)
            : intdiv((int) config('native_file_security.profiles.gr_pdf.max_bytes', 5 * 1024 * 1024), 1024);

        return [
            'purchase_order' => [
                'required',
                'string',
                'max:64',
                function ($attribute, $value, $fail) {
                    // The hash is decoded server-side; raw ids and unknown hashes get one uniform message.
                    $purchaseOrder = (new LocalPurchaseOrder)->resolveRouteBinding($value)?->loadMissing('supplier');
                    if (! $purchaseOrder) {
                        $fail(__('local_procurement.gr_documents.po_missing'));

                        return;
                    }
                    if ($purchaseOrder->status === LocalPurchaseOrder::STATUS_CANCELLED) {
                        $fail(__('local_procurement.gr_documents.po_cancelled_selected'));

                        return;
                    }
                    $supplier = $purchaseOrder->supplier;
                    if (! $supplier || ! $supplier->is_active || ! $supplier->isLocalEligible()) {
                        $fail(__('local_procurement.validation.supplier_ineligible'));

                        return;
                    }
                    $this->resolvedPurchaseOrder = $purchaseOrder;
                },
            ],
            'file' => [
                'required',
                'file',
                $isZip ? 'mimes:zip' : 'mimes:pdf',
                $isZip ? 'extensions:zip' : 'extensions:pdf',
                'max:'.$maxKilobytes,
            ],
        ];
    }

    public function purchaseOrder(): LocalPurchaseOrder
    {
        return $this->resolvedPurchaseOrder
            ?? abort(422, __('local_procurement.gr_documents.po_missing'));
    }

    public function messages(): array
    {
        return [
            'purchase_order.required' => __('local_procurement.gr_documents.po_required'),
            'purchase_order.max' => __('local_procurement.gr_documents.po_missing'),
            'file.required' => __('local_procurement.gr_documents.file_required'),
            'file.mimes' => __('local_procurement.gr_documents.file_type'),
            'file.extensions' => __('local_procurement.gr_documents.file_type'),
            'file.max' => $this->isZipUpload()
                ? __('local_procurement.gr_documents.file_size')
                : __('local_procurement.gr_documents.pdf_size'),
        ];
    }

    private function isZipUpload(): bool
    {
        return $this->hasFile('file') && strtolower((string) $this->file('file')->getClientOriginalExtension()) === 'zip';
    }
}
