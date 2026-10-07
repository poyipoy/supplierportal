<?php

namespace App\Http\Requests\LocalInvoice;

use Illuminate\Foundation\Http\FormRequest;

class UploadLocalPoImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && $user->is_active && ($user->isFinance() || $user->isAdmin() || $user->isPurchasing());
    }

    public function rules(): array
    {
        return [
            'import_file' => [
                'required',
                'file',
                'extensions:xlsx',
                'max:10240',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'import_file.required' => __('local_procurement.validation.po_sheet_required'),
            'import_file.file' => __('local_procurement.validation.sheet_invalid'),
            'import_file.extensions' => __('local_procurement.validation.sheet_type'),
            'import_file.mimes' => __('local_procurement.validation.sheet_type'),
            'import_file.max' => __('local_procurement.validation.sheet_size'),
        ];
    }
}
