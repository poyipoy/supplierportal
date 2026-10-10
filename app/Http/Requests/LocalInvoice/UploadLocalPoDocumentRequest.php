<?php

namespace App\Http\Requests\LocalInvoice;

use App\Models\LocalPurchaseOrder;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class UploadLocalPoDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('uploadDocument', LocalPurchaseOrder::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'request_key' => ['nullable', 'uuid'],
            'supplier_id' => [
                'required',
                'integer',
                'exists:users,id',
                function ($attribute, $value, $fail) {
                    $supplierUser = User::find($value);
                    if (! $supplierUser || ! $supplierUser->is_active || ! $supplierUser->isLocalEligible()) {
                        $fail(__('local_procurement.validation.supplier_ineligible'));
                    }
                },
            ],
            'file' => [
                'required',
                'file',
                'mimes:pdf,zip',
                'extensions:pdf,zip',
                'max:'.intdiv((int) config('native_file_security.zip.max_upload_bytes', 50 * 1024 * 1024), 1024),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'supplier_id.required' => __('local_procurement.validation.supplier_select'),
            'supplier_id.exists' => __('local_procurement.validation.supplier_missing'),
            'file.required' => __('local_procurement.validation.po_file_required'),
            'file.mimes' => __('local_procurement.validation.po_file_type'),
            'file.max' => __('local_procurement.validation.po_file_size'),
        ];
    }
}
