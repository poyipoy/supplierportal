<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\PoItemProgressUpdate;
use App\Models\PrItemAward;
use App\Models\PurchaseOrder;
use App\Services\MaterialProgressService;
use App\Services\RegionalDisplayFormatter;
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
    public function history(Request $request, int $po_id, int $award_id, RegionalDisplayFormatter $regionalFormatter): JsonResponse
    {
        $po = PurchaseOrder::findOrFail($po_id);
        $award = PrItemAward::findOrFail($award_id);

        if ((int) $award->purchase_order_id !== (int) $po->id) {
            abort(404, __('purchasing.copy.award_does_not_belong_to_the_requested_purchase_order'));
        }

        $history = $this->progressService->historyForAward($award);
        $projection = $this->progressService->projectionForAward($award);

        return response()->json([
            'success' => true,
            'award_id' => $award->id,
            'material_name' => $award->prItem?->material_name ?? __('purchasing.copy.material'),
            'projection' => $projection,
            'history' => $history->map(fn (PoItemProgressUpdate $item) => [
                'id' => $item->id,
                'status' => $item->status,
                'status_label' => $item->status_label,
                'status_badge' => $item->status_badge,
                'status_tone' => $item->status_tone,
                'supplier_controlled_qty_snapshot' => $item->supplier_controlled_qty_snapshot,
                'estimated_ready_date' => $item->estimated_ready_date?->format('d M Y'),
                'note' => $item->note,
                'updated_by' => $item->updatedByUser?->name ?? __('purchasing.copy.supplier_user'),
                'created_at' => $item->created_at ? \App\Support\BusinessTime::format($item->created_at, 'd M Y H:i') : null,
                'created_at_display' => $item->created_at ? $regionalFormatter->timestamp($item->created_at, 'datetime') : null,
                'estimated_ready_date_display' => $item->estimated_ready_date ? $regionalFormatter->date($item->estimated_ready_date, 'human') : null,
            ]),
        ]);
    }
}
