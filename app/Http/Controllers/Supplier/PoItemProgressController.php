<?php

namespace App\Http\Controllers\Supplier;

use App\Http\Controllers\Controller;
use App\Models\PoItemProgressUpdate;
use App\Models\PrItemAward;
use App\Models\PurchaseOrder;
use App\Services\MaterialProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PoItemProgressController extends Controller
{
    public function __construct(
        protected MaterialProgressService $progressService,
    ) {}

    /**
     * Update material progress for an awarded item.
     */
    public function update(Request $request, int $po_id, int $award_id): JsonResponse|RedirectResponse
    {
        $po = PurchaseOrder::findOrFail($po_id);
        $award = PrItemAward::findOrFail($award_id);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', PoItemProgressUpdate::STATUSES)],
            'estimated_ready_date' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $update = $this->progressService->updateProgress($po, $award, $request->user(), $validated);

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => 'Material progress updated successfully.',
                'data' => [
                    'id' => $update->id,
                    'status' => $update->status,
                    'status_label' => $update->status_label,
                    'status_badge' => $update->status_badge,
                    'status_tone' => $update->status_tone,
                    'estimated_ready_date' => $update->estimated_ready_date ? $update->estimated_ready_date->format('d M Y') : null,
                    'note' => $update->note,
                    'supplier_controlled_qty_snapshot' => $update->supplier_controlled_qty_snapshot,
                ],
            ]);
        }

        return redirect()->back()->with('success', 'Material progress updated successfully.');
    }

    /**
     * Get progress history for an awarded item.
     */
    public function history(Request $request, int $po_id, int $award_id): JsonResponse
    {
        $po = PurchaseOrder::findOrFail($po_id);

        if ((int) $po->supplier_id !== (int) $request->user()->id) {
            abort(403, 'Unauthorized supplier.');
        }

        $award = PrItemAward::findOrFail($award_id);

        if ((int) $award->supplier_id !== (int) $request->user()->id || (int) $award->purchase_order_id !== (int) $po->id) {
            abort(403, 'Unauthorized award.');
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
