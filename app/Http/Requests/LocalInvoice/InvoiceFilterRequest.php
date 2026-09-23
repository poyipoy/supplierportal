<?php

namespace App\Http\Requests\LocalInvoice;

use App\Models\LocalInvoice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InvoiceFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'], 'supplier' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(LocalInvoice::STATUSES)],
            'payment_status' => ['nullable', 'string', 'in:ALL,UNPAID,PAID,all,unpaid,paid'],
            'overpayment_status' => ['nullable', 'string', 'in:all,open,settled,ALL,OPEN,SETTLED'],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'due_from' => ['nullable', 'date_format:Y-m-d'], 'due_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:due_from'],
            'overdue' => ['nullable', 'boolean'], 'history' => ['nullable', 'boolean'],
            'period' => ['nullable', 'string', 'max:50'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
        ];
    }
}
