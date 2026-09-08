<?php

namespace App\Services;

use App\Models\PoItemProgressUpdate;
use App\Models\PrItemAward;
use App\Models\PurchaseOrder;
use App\Models\Shipment;
use App\Models\User;
use App\Support\NotificationCategory;
use App\Support\StatusHelper;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MaterialProgressService
{
    public function __construct(
        protected NotificationService $notifications,
    ) {}

    /**
     * Resolve the current material progress state for an awarded item.
     */
    public function currentForAward(PrItemAward $award): array
    {
        $award->loadMissing(['latestProgressUpdate.updatedByUser', 'prItem', 'quotation', 'purchaseOrder']);
        $po = $award->purchaseOrder;

        $fulfillment = $po
            ? $po->itemFulfillmentStatus($award->quotation_item_id)
            : ['ordered_qty' => 0, 'accepted_qty' => 0, 'remaining_qty' => 0];

        $latestUpdate = $award->latestProgressUpdate;
        $hasManualUpdate = $latestUpdate !== null;

        $status = $hasManualUpdate
            ? $latestUpdate->status
            : PoItemProgressUpdate::STATUS_AWAITING_CONFIRMATION;

        $supplierControlledQty = (int) ($fulfillment['remaining_qty'] ?? 0);
        $orderedQty = (int) ($fulfillment['ordered_qty'] ?? 0);
        $acceptedQty = (int) ($fulfillment['accepted_qty'] ?? 0);

        $isPoClosed = $po && in_array($po->status, ['completed', 'cancelled'], true);
        $canUpdate = ! $isPoClosed && $supplierControlledQty > 0;

        return [
            'award_id' => $award->id,
            'status' => $status,
            'label' => StatusHelper::materialProgressLabel($status),
            'badge' => StatusHelper::materialProgressBadge($status),
            'tone' => StatusHelper::materialProgressTone($status),
            'estimated_ready_date' => $latestUpdate?->estimated_ready_date,
            'note' => $latestUpdate?->note,
            'updated_at' => $latestUpdate?->created_at,
            'updated_by' => $latestUpdate?->updatedByUser?->name,
            'has_manual_update' => $hasManualUpdate,
            'is_fallback' => ! $hasManualUpdate,
            'latest_update' => $latestUpdate,
            'update' => $latestUpdate,
            'ordered_qty' => $orderedQty,
            'accepted_qty' => $acceptedQty,
            'supplier_controlled_qty' => $supplierControlledQty,
            'can_update' => $canUpdate,
        ];
    }

    /**
     * Resolve the full canonical projection for an awarded PO item.
     */
    public function projectionForAward(PrItemAward $award): array
    {
        $award->loadMissing([
            'latestProgressUpdate.updatedByUser',
            'prItem',
            'quotation',
            'purchaseOrder',
        ]);

        $po = $award->purchaseOrder;
        $fulfillment = $po
            ? $po->itemFulfillmentStatus($award->quotation_item_id)
            : ['ordered_qty' => 0, 'accepted_qty' => 0, 'remaining_qty' => 0];

        $latestUpdate = $award->latestProgressUpdate;
        $hasManualUpdate = $latestUpdate !== null;

        $status = $hasManualUpdate
            ? $latestUpdate->status
            : PoItemProgressUpdate::STATUS_AWAITING_CONFIRMATION;

        $orderedQty = (int) ($fulfillment['ordered_qty'] ?? 0);
        $acceptedQty = (int) ($fulfillment['accepted_qty'] ?? 0);
        $supplierControlledQty = (int) ($fulfillment['remaining_qty'] ?? 0);

        // Calculate in-transit quantity from submitted shipments
        $inTransitQty = 0;
        if ($po) {
            $inTransitQty = (int) DB::table('shipment_items')
                ->join('shipments', 'shipments.id', '=', 'shipment_items.shipment_id')
                ->where('shipment_items.purchase_order_id', $po->id)
                ->where('shipment_items.quotation_item_id', $award->quotation_item_id)
                ->where('shipments.status', Shipment::STATUS_SUBMITTED)
                ->whereNull('shipments.deleted_at')
                ->sum('shipment_items.shipped_qty');
        }

        // Arrived awaiting QC or in claim
        $reservedQty = (int) ($fulfillment['reserved_qty'] ?? 0);
        $arrivedPendingQcQty = max(0, $reservedQty - $inTransitQty);

        $isPoClosed = $po && in_array($po->status, ['completed', 'cancelled'], true);
        $canUpdate = ! $isPoClosed && $supplierControlledQty > 0;

        return [
            'award_id' => $award->id,
            'award_hashid' => $award->hash,
            'award' => $award,
            'material_name' => $award->prItem?->material_name ?? 'Material',
            'hs_code' => $award->prItem?->hs_code,
            'ordered_qty' => $orderedQty,
            'accepted_qty' => $acceptedQty,
            'in_transit_qty' => $inTransitQty,
            'arrived_pending_qc_qty' => $arrivedPendingQcQty,
            'supplier_controlled_qty' => $supplierControlledQty,
            'manual_progress_status' => $status,
            'manual_progress_label' => StatusHelper::materialProgressLabel($status),
            'manual_progress_badge' => StatusHelper::materialProgressBadge($status),
            'manual_progress_tone' => StatusHelper::materialProgressTone($status),
            'current_estimated_ready_date' => $latestUpdate?->estimated_ready_date,
            'original_supplier_ready_date' => $award->quotation?->estimated_delivery,
            'last_progress_update_at' => $latestUpdate?->created_at,
            'last_updated_by' => $latestUpdate?->updatedByUser?->name,
            'latest_note' => $latestUpdate?->note,
            'has_manual_update' => $hasManualUpdate,
            'can_update' => $canUpdate,
        ];
    }

    /**
     * Get append-only history for an awarded item, newest first.
     */
    public function historyForAward(PrItemAward $award): Collection
    {
        return $award->progressUpdates()
            ->with('updatedByUser')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Record a new progress update with strict transactional checks.
     *
     * @throws ValidationException
     */
    public function updateProgress(PurchaseOrder $po, PrItemAward $award, User $user, array $data): PoItemProgressUpdate
    {
        // Role and ownership check (fail-secure Zero Trust)
        if ($user->role !== 'supplier' || (int) $user->id !== (int) $po->supplier_id || (int) $award->supplier_id !== (int) $user->id) {
            abort(403, 'Unauthorized: Only the assigned supplier can update material progress.');
        }

        if ((int) $award->purchase_order_id !== (int) $po->id) {
            abort(403, 'Award does not belong to the requested purchase order.');
        }

        $newStatus = $data['status'] ?? null;
        if (! in_array($newStatus, PoItemProgressUpdate::STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => 'The selected material progress status is invalid.',
            ]);
        }

        return DB::transaction(function () use ($po, $award, $user, $data, $newStatus) {
            // Lock PO and Award deterministically: PO first, then Award
            $lockedPo = PurchaseOrder::where('id', $po->id)->lockForUpdate()->firstOrFail();
            $lockedAward = PrItemAward::where('id', $award->id)->lockForUpdate()->firstOrFail();

            if ((int) $lockedAward->purchase_order_id !== (int) $lockedPo->id) {
                abort(403, 'Award does not belong to locked purchase order.');
            }

            if (in_array($lockedPo->status, ['completed', 'cancelled'], true)) {
                throw ValidationException::withMessages([
                    'status' => "Cannot update material progress on a {$lockedPo->status} purchase order.",
                ]);
            }

            // Read authoritative fulfillment Qty
            $fulfillment = $lockedPo->itemFulfillmentStatus($lockedAward->quotation_item_id);
            $supplierControlledQty = (int) ($fulfillment['remaining_qty'] ?? 0);

            if ($supplierControlledQty <= 0) {
                throw ValidationException::withMessages([
                    'status' => 'Cannot update material progress when supplier-controlled quantity is zero.',
                ]);
            }

            // Resolve previous status
            $latestUpdate = $lockedAward->progressUpdates()->orderByDesc('id')->first();
            $previousStatus = $latestUpdate?->status ?? PoItemProgressUpdate::STATUS_AWAITING_CONFIRMATION;

            // Check backward transition
            if ($this->isBackwardTransition($previousStatus, $newStatus)) {
                $note = trim($data['note'] ?? '');
                if ($note === '') {
                    throw ValidationException::withMessages([
                        'note' => 'A progress note or reason is required when moving material progress backward.',
                    ]);
                }
            }

            $update = PoItemProgressUpdate::create([
                'pr_item_award_id' => $lockedAward->id,
                'status' => $newStatus,
                'supplier_controlled_qty_snapshot' => $supplierControlledQty,
                'estimated_ready_date' => ! empty($data['estimated_ready_date']) ? $data['estimated_ready_date'] : null,
                'note' => ! empty($data['note']) ? trim($data['note']) : null,
                'updated_by' => $user->id,
            ]);

            // Notify purchasing team (NotificationService defers until afterCommit)
            $purchasingUsers = User::where('role', 'purchasing')->where('is_active', true)->get();
            $materialName = $lockedAward->prItem?->material_name ?? 'Material';
            $fromLabel = StatusHelper::materialProgressLabel($previousStatus);
            $toLabel = StatusHelper::materialProgressLabel($newStatus);
            $readyFormatted = $update->estimated_ready_date ? $update->estimated_ready_date->format('d M Y') : 'Not specified';
            $supplierName = $user->name;

            $this->notifications->send(
                $purchasingUsers,
                'po_item_progress.updated',
                "po_item_progress.updated:{$update->id}",
                'Supplier Material Progress Updated',
                "Supplier {$supplierName} updated {$materialName} on {$lockedPo->po_number}: {$fromLabel} → {$toLabel}. Current Supplier-controlled Qty: {$supplierControlledQty} pcs. Estimated Ready: {$readyFormatted}.",
                route('purchasing.purchase-orders.show', $lockedPo, absolute: false) . '#material-progress',
                'clock text-primary',
                [
                    'category' => NotificationCategory::OTHER,
                    'po_id' => $lockedPo->id,
                    'award_id' => $lockedAward->id,
                    'progress_update_id' => $update->id,
                ]
            );

            return $update;
        });
    }

    /**
     * Check whether moving from $fromStatus to $toStatus is a backward transition.
     */
    public function isBackwardTransition(string $fromStatus, string $toStatus): bool
    {
        $fromRank = PoItemProgressUpdate::STAGE_RANKS[$fromStatus] ?? 0;
        $toRank = PoItemProgressUpdate::STAGE_RANKS[$toStatus] ?? 0;

        return $toRank < $fromRank;
    }

    /**
     * Compute a derived summary of material progress across all items in a PO.
     */
    public function poSummary(PurchaseOrder $po): array
    {
        $po->loadMissing(['awards.latestProgressUpdate', 'awards.prItem', 'awards.quotationItem']);
        $awards = $po->awards;

        if ($awards->isEmpty()) {
            return [
                'text' => 'No awarded items',
                'counts' => [],
                'is_homogeneous' => true,
                'items' => [],
            ];
        }

        $counts = [];
        $itemProjections = [];

        foreach ($awards as $award) {
            $projection = $this->projectionForAward($award);
            $itemProjections[] = $projection;

            if ($projection['supplier_controlled_qty'] > 0) {
                $stageLabel = $projection['manual_progress_label'];
                $counts[$stageLabel] = ($counts[$stageLabel] ?? 0) + 1;
            } elseif ($projection['in_transit_qty'] > 0) {
                $counts['In Transit'] = ($counts['In Transit'] ?? 0) + 1;
            } elseif ($projection['accepted_qty'] >= $projection['ordered_qty']) {
                $counts['Accepted'] = ($counts['Accepted'] ?? 0) + 1;
            } elseif ($projection['arrived_pending_qc_qty'] > 0) {
                $counts['Waiting QC'] = ($counts['Waiting QC'] ?? 0) + 1;
            } else {
                $stageLabel = $projection['manual_progress_label'];
                $counts[$stageLabel] = ($counts[$stageLabel] ?? 0) + 1;
            }
        }

        $parts = [];
        foreach ($counts as $label => $count) {
            $parts[] = "{$count} {$label}";
        }

        $summaryText = implode(' · ', $parts);
        $isHomogeneous = count($counts) <= 1;

        return [
            'text' => $summaryText,
            'counts' => $counts,
            'is_homogeneous' => $isHomogeneous,
            'items' => $itemProjections,
        ];
    }
}
