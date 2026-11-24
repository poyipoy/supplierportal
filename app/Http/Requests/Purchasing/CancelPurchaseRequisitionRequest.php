<?php

namespace App\Http\Requests\Purchasing;

use Illuminate\Foundation\Http\FormRequest;

class CancelPurchaseRequisitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->isPurchasing() && (bool) $user->is_active;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('cancellation_reason'))) {
            $trimmed = preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $this->input('cancellation_reason')) ?? $this->input('cancellation_reason');
            $this->merge(['cancellation_reason' => $trimmed]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cancellation_reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'cancellation_reason' => __('purchasing.copy.cancellation_reason'),
        ];
    }
}
