<?php

namespace App\Http\Requests\SupplierAudit;

use App\Models\SupplierAudit;
use App\Support\BusinessTime;
use Illuminate\Foundation\Http\FormRequest;

class RequestSupplierAuditRevisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $audit = $this->route('supplierAudit');

        return $audit instanceof SupplierAudit && $this->user()->can('requestRevision', $audit);
    }

    public function rules(): array
    {
        return [
            'note' => ['required', 'string', 'max:2000'],
            'change_due_date' => ['nullable', 'boolean'],
            'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.BusinessTime::today()->toDateString()],
        ];
    }

    public function attributes(): array
    {
        return [
            'note' => __('supplier_audit.fields.revision_note'),
            'due_date' => __('supplier_audit.fields.due_date'),
        ];
    }
}
