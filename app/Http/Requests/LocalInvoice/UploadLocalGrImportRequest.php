<?php

namespace App\Http\Requests\LocalInvoice;

use Illuminate\Foundation\Http\FormRequest;

class UploadLocalGrImportRequest extends FormRequest
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
                'extensions:xlsx,csv,xls',
                'max:'.config('local_procurement_imports.max_file_kib'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'import_file.required' => __('local_procurement.validation.gr_sheet_required'),
            'import_file.file' => __('local_procurement.validation.sheet_invalid'),
            'import_file.extensions' => __('local_procurement.validation.sheet_type'),
            'import_file.mimes' => __('local_procurement.validation.sheet_type'),
            'import_file.max' => __('local_procurement.validation.sheet_size'),
        ];
    }
}
