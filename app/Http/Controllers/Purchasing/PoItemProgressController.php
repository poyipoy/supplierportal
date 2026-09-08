<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\PoItemProgressUpdate;
use App\Models\PrItemAward;
use App\Models\PurchaseOrder;
use App\Services\MaterialProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PoItemProgressController extends Controller
{
    public function __construct(
        protected MaterialProgressService $progressService,
    ) {}

    /**
     * Get progress history for an awarded item (read-only for purchasing).
     */
    public function history(Request $request, int $po_id, int $award_id): JsonResponse
    {
        $po = PurchaseOrder::findOrFail($po_id);
        $award = PrItemAward::findOrFail($award_id);

        if ((int) $award->purchase_order_id !== (int) $po->id) {
            abort(404, 'Award does not belong to the requested purchase order.');
        }

        $history = $this->progressService->historyForAward($award);
        $projection = $this->progressService->projectionForAward($award);

        return response()->json([
            'success' => true,
            'award_id' => $award->id,
            'material_name' => $award->prItem?->material_name ?? 'Material',
            'projection' => $projection,
            'history' => $history->map(fn (PoItemProgressUpdate $item) => [
                'id' => $item->id,
                'status' => $item->status,
                'status_label' => $item->status_label,
                'status_badge' => $item->status_badge,
                'status_tone' => $item->status_tone,
                'supplier_controlled_qty_snapshot' => $item->supplier_controlled_qty_snapshot,
                'estimated_ready_date' => $item->estimated_ready_date ? $item->estimated_ready_date->format('d M Y') : null,
                'note' => $item->note,
                'updated_by' => $item->updatedByUser?->name ?? 'Supplier User',
                'created_at' => $item->created_at ? $item->created_at->format('d M Y H:i') : null,
            ]),
        ]);
    }
}
