<?php

namespace App\Http\Controllers\Purchasing;

use App\Exceptions\PoShipmentDocumentBatchRefused;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\UpdatePoShipmentDocumentStatusRequest;
use App\Models\PurchaseOrder;
use App\Services\PoShipmentDocumentStatusService;
use Illuminate\Http\JsonResponse;

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
}
