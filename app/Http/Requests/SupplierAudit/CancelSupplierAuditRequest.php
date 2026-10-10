<?php

namespace App\Http\Requests\SupplierAudit;

use App\Models\SupplierAudit;
use Illuminate\Foundation\Http\FormRequest;

class CancelSupplierAuditRequest extends FormRequest
{
    public function authorize(): bool
    {
        $audit = $this->route('supplierAudit');

        return $audit instanceof SupplierAudit && $this->user()->can('cancel', $audit);
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return ['reason' => __('supplier_audit.fields.cancel_reason')];
    }
}
