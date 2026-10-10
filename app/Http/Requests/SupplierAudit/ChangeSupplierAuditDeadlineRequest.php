<?php

namespace App\Http\Requests\SupplierAudit;

use App\Models\SupplierAudit;
use App\Support\BusinessTime;
use Illuminate\Foundation\Http\FormRequest;

class ChangeSupplierAuditDeadlineRequest extends FormRequest
{
    public function authorize(): bool
    {
        $audit = $this->route('supplierAudit');

        return $audit instanceof SupplierAudit && $this->user()->can('changeDeadline', $audit);
    }

    public function rules(): array
    {
        return [
            'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.BusinessTime::today()->toDateString()],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function attributes(): array
    {
        return [
            'due_date' => __('supplier_audit.fields.due_date'),
            'reason' => __('supplier_audit.deadline.reason'),
        ];
    }
}
