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
            'supplier_id' => [
                'required',
                'integer',
                'exists:users,id',
                function ($attribute, $value, $fail) {
                    $supplierUser = User::find($value);
                    if (! $supplierUser || ! $supplierUser->is_active || ! $supplierUser->isLocalEligible()) {
                        $fail('Rekanan supplier yang dipilih tidak aktif atau tidak terdaftar dalam pengadaan lokal.');
                    }
                },
            ],
            'file' => [
                'required',
                'file',
                'mimes:pdf,zip',
                'max:51200', // 50 MB
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'supplier_id.required' => 'Pilih rekanan supplier terlebih dahulu.',
            'supplier_id.exists' => 'Rekanan supplier tidak ditemukan.',
            'file.required' => 'Berkas dokumen PO (.pdf atau .zip) wajib diunggah.',
            'file.mimes' => 'Format berkas harus berupa dokumen PDF (.pdf) atau arsip ZIP (.zip).',
            'file.max' => 'Ukuran berkas tidak boleh melebihi 50 MB.',
        ];
    }
}
