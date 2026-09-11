<?php

namespace App\Http\Requests\LocalInvoice;

use Illuminate\Foundation\Http\FormRequest;

class WorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isLocalOperator();
    }

    public function rules(): array
    {
        return [
            'notes' => [$this->routeIs('accounting.invoices.request-revision', 'accounting.invoices.reject') ? 'required' : 'nullable', 'string', 'max:5000'],
            'scheduled_payment_date' => [$this->routeIs('accounting.invoices.schedule-payment') ? 'required' : 'nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
        ];
    }
}
