<?php

namespace App\Http\Requests\Purchasing;

use App\Models\Shipment;
use App\Models\ShipmentDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class BatchUpdateShipmentDocumentStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $shipmentId = $this->route('id');
        $shipment = $shipmentId instanceof Shipment ? $shipmentId : Shipment::findOrFail($shipmentId);

        return Gate::allows('reviewDocument', $shipment);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'documents' => ['required', 'array', 'min:1', 'max:4'],
            'documents.*.document_id' => ['nullable', 'integer'],
            'documents.*.doc_type' => ['nullable', 'string', 'distinct', Rule::in(ShipmentDocument::DOC_TYPES)],
            'documents.*.status' => ['required', 'string', Rule::in(ShipmentDocument::STATUSES)],
            'documents.*.reason' => ['nullable', 'string', 'max:1000'],
            'documents.*.expected_status' => ['nullable', 'string', Rule::in(ShipmentDocument::STATUSES)],
            'documents.*.expected_attachment' => ['nullable', 'string', 'max:64'],
        ];
    }
}
