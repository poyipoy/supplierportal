<?php

namespace App\Http\Requests\Purchasing;

use App\Models\ShipmentDocument;
use App\Services\PoShipmentDocumentStatusService;
use App\Support\HashidCodec;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePoShipmentDocumentStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->role === 'purchasing';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $docType = (string) $this->route('doc_type');
        $allowedTargets = PoShipmentDocumentStatusService::ALLOWED_TARGETS[$docType] ?? [];

        return [
            'target_status' => [
                'required',
                'string',
                Rule::in($allowedTargets),
            ],
            'reason' => [
                'nullable',
                'string',
                'max:1000',
            ],
            'rows' => [
                'required',
                'array',
                'min:1',
                'max:50',
            ],
            'rows.*.shipment' => [
                'required',
                'string',
                'distinct',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value)) {
                        $fail(__('documents.sync.invalid_row_payload'));

                        return;
                    }

                    $trimmed = trim($value);
                    if ($trimmed === '' || ctype_digit($trimmed) || HashidCodec::decode($trimmed) === null) {
                        $fail(__('documents.sync.invalid_row_payload'));
                    }
                },
            ],
            'rows.*.expected_status' => [
                'required',
                'string',
                Rule::in(ShipmentDocument::STATUSES),
            ],
            'rows.*.expected_attachment' => [
                'nullable',
                'string',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value === null || $value === '') {
                        return;
                    }

                    if (! is_string($value)) {
                        $fail(__('documents.sync.invalid_row_payload'));

                        return;
                    }

                    $trimmed = trim($value);
                    if ($trimmed !== '' && (ctype_digit($trimmed) || HashidCodec::decode($trimmed) === null)) {
                        $fail(__('documents.sync.invalid_row_payload'));
                    }
                },
            ],
        ];
    }
}
