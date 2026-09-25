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
                'extensions:xlsx',
                'max:10240',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'import_file.required' => 'File spreadsheet GR (.xlsx) wajib diunggah.',
            'import_file.file' => 'File yang diunggah tidak valid.',
            'import_file.extensions' => 'Format file harus berupa spreadsheet Excel (.xlsx).',
            'import_file.mimes' => 'Format file harus berupa spreadsheet Excel (.xlsx).',
            'import_file.max' => 'Ukuran file spreadsheet tidak boleh melebihi 10 MB.',
        ];
    }
}
