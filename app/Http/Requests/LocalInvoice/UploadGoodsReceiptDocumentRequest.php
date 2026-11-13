<?php

namespace App\Http\Requests\LocalInvoice;

use App\Models\LocalPurchaseOrder;
use Illuminate\Foundation\Http\FormRequest;

class UploadGoodsReceiptDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', LocalPurchaseOrder::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:pdf', 'extensions:pdf', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => __('local_procurement.gr_documents.file_required'),
            'file.mimes' => __('local_procurement.gr_documents.pdf_type'),
            'file.extensions' => __('local_procurement.gr_documents.pdf_type'),
            'file.max' => __('local_procurement.gr_documents.pdf_size'),
        ];
    }
}
