<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Requests\SavePrItemRequest;
use App\Models\PrItem;
use App\Models\PurchaseRequisition;
use App\Services\Materials\PrItemProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class PrItemController extends Controller
{
    public function __construct(private readonly PrItemProcessor $processor) {}

    public function store(SavePrItemRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $pr = PurchaseRequisition::findOrFail($validated['pr_id']);

        if ($pr->created_by !== auth()->id() || ! in_array($pr->status, ['draft', 'rejected'], true)) {
            return response()->json(['error' => 'Cannot add items to this requisition.'], 403);
        }

        $result = $this->processor->process($validated, false, auth()->id());
        if (! $result->isValid()) {
            throw ValidationException::withMessages($result->errors);
        }

        $item = $pr->items()->create($result->data);

        return response()->json(['success' => true, 'item' => $item->fresh()]);
    }

    public function update(SavePrItemRequest $request, string $id): JsonResponse
    {
        $item = PrItem::with('purchaseRequisition')->findOrFail($id);
        $pr = $item->purchaseRequisition;

        if ($pr->created_by !== auth()->id() || ! in_array($pr->status, ['draft', 'rejected'], true)) {
            return response()->json(['error' => 'Cannot edit items on this requisition.'], 403);
        }

        $result = $this->processor->process($request->validated(), false, auth()->id(), $item);
        if (! $result->isValid()) {
            throw ValidationException::withMessages($result->errors);
        }

        $item->update($result->data);

        return response()->json(['success' => true, 'item' => $item->fresh()]);
    }

    public function destroy(string $id): JsonResponse
    {
        $item = PrItem::with('purchaseRequisition')->findOrFail($id);
        $pr = $item->purchaseRequisition;

        if ($pr->created_by !== auth()->id() || ! in_array($pr->status, ['draft', 'rejected'], true)) {
            return response()->json(['error' => 'Cannot delete items from this requisition.'], 403);
        }

        if ($item->quotationItems()->exists() || $item->qcItems()->exists()) {
            return response()->json([
                'error' => 'This material is already referenced by a quotation or QC record and cannot be deleted.',
            ], 422);
        }

        $item->delete();

        return response()->json(['success' => true]);
    }
}
