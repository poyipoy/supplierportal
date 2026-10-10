<?php

namespace App\Http\Requests\SupplierAudit;

use App\Models\SupplierAudit;
use Illuminate\Foundation\Http\FormRequest;

class UploadSupplierAuditResultRequest extends FormRequest
{
    public function authorize(): bool
    {
        $audit = $this->route('supplierAudit');

        return $audit instanceof SupplierAudit && $this->user()->can('uploadResult', $audit);
    }

    public function rules(): array
    {
        $audit = $this->route('supplierAudit');
        $replacing = $audit instanceof SupplierAudit && $audit->status === SupplierAudit::STATUS_RESULT_PUBLISHED;

        return [
            'result_file' => ['required', 'file', 'mimes:pdf,xlsx,jpg,jpeg,png', 'max:10240'],
            'reason' => [$replacing ? 'required' : 'nullable', 'string', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'result_file' => __('supplier_audit.fields.result_file'),
            'reason' => __('supplier_audit.fields.replace_reason'),
        ];
    }
}
