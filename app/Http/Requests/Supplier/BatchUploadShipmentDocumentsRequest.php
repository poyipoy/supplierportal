<?php

namespace App\Http\Requests\Supplier;

use App\Models\Shipment;
use App\Models\ShipmentDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class BatchUploadShipmentDocumentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $shipmentId = $this->route('id');
        $shipment = $shipmentId instanceof Shipment ? $shipmentId : Shipment::findOrFail($shipmentId);

        return Gate::allows('uploadDocument', $shipment);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'documents' => ['required', 'array', 'min:1', 'max:4'],
            'documents.*.doc_type' => ['required', 'string', 'distinct', Rule::in(ShipmentDocument::DOC_TYPES)],
            'documents.*.file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,xlsx,doc,docx', 'max:10240'],
            'documents.*.document_number' => ['nullable', 'string', 'max:100'],
        ];
    }
}
