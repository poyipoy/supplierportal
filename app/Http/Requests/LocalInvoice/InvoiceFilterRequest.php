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
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'due_from' => ['nullable', 'date_format:Y-m-d'], 'due_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:due_from'],
            'overdue' => ['nullable', 'boolean'], 'history' => ['nullable', 'boolean'],
        ];
    }
}
