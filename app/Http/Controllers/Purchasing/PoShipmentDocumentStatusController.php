<?php

namespace App\Http\Controllers\Purchasing;

use App\Exceptions\PoShipmentDocumentBatchRefused;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\UpdatePoShipmentDocumentStatusRequest;
use App\Models\PurchaseOrder;
use App\Services\PoShipmentDocumentStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PoShipmentDocumentStatusController extends Controller
{
    public function __invoke(
        UpdatePoShipmentDocumentStatusRequest $request,
        $id,
        string $doc_type,
        PoShipmentDocumentStatusService $service,
    ): JsonResponse {
        $po = PurchaseOrder::findOrFail($id);

        try {
            $result = $service->updateSelected(
                po: $po,
                docType: $doc_type,
                rows: $request->validated('rows'),
                target: (string) $request->validated('target_status'),
                reason: $request->validated('reason'),
                actor: $request->user(),
            );

            return response()->json($result);
        } catch (PoShipmentDocumentBatchRefused $exception) {
            return response()->json($exception->toPayload(), $exception->httpStatus);
        }
    }

    public function batchUpdateMultiType(
        Request $request,
        $id,
        PoShipmentDocumentStatusService $service,
    ): JsonResponse {
        $po = PurchaseOrder::findOrFail($id);

        $validated = $request->validate([
            'documents' => ['required', 'array', 'min:1', 'max:4'],
            'documents.*.rows' => ['required', 'array', 'min:1', 'max:50'],
            'documents.*.rows.*.shipment' => ['required', 'string'],
            'documents.*.rows.*.expected_status' => ['nullable', 'string'],
            'documents.*.rows.*.expected_attachment' => ['nullable', 'string'],
            'documents.*.target_status' => ['required', 'string'],
            'documents.*.reason' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $result = $service->updatePoDocumentsMultiType(
                po: $po,
                payloadByDocType: $validated['documents'],
                actor: $request->user(),
            );

            return response()->json($result);
        } catch (PoShipmentDocumentBatchRefused $exception) {
            return response()->json($exception->toPayload(), $exception->httpStatus);
        }
    }
}
