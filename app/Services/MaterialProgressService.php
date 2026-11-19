<?php

namespace App\Services;

use App\Models\PoItemProgressUpdate;
use App\Models\PrItemAward;
use App\Models\PurchaseOrder;
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

        // E3: Default initial progress status when PO is created is Order Confirmed
        $status = ($hasManualUpdate && $latestUpdate->status !== PoItemProgressUpdate::STATUS_AWAITING_CONFIRMATION)
            ? $latestUpdate->status
            : PoItemProgressUpdate::STATUS_ORDER_CONFIRMED;

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
    public function projectionForAward(PrItemAward $award, ?array $fulfillment = null): array
    {
        $award->loadMissing([
            'latestProgressUpdate.updatedByUser',
            'prItem',
            'quotation',
            'purchaseOrder',
        ]);

        $po = $award->purchaseOrder;
        // Gate 2 §3-E: a PO-wide fulfillmentProjection() line may be passed in (display only).
        $fulfillment ??= $po
            ? $po->itemFulfillmentStatus($award->quotation_item_id)
            : ['ordered_qty' => 0, 'accepted_qty' => 0, 'remaining_qty' => 0];

        $latestUpdate = $award->latestProgressUpdate;
        $hasManualUpdate = $latestUpdate !== null;

        // E3: Default initial progress status when PO is created is Order Confirmed
        $status = ($hasManualUpdate && $latestUpdate->status !== PoItemProgressUpdate::STATUS_AWAITING_CONFIRMATION)
            ? $latestUpdate->status
            : PoItemProgressUpdate::STATUS_ORDER_CONFIRMED;

        $orderedQty = (int) ($fulfillment['ordered_qty'] ?? 0);
        $acceptedQty = (int) ($fulfillment['accepted_qty'] ?? 0);
        $supplierControlledQty = (int) ($fulfillment['remaining_qty'] ?? 0);

        // Gate 2 §3-E: the tally splits reserved into in transit, waiting QC and unresolved NG,
        // so NG under an open claim is no longer shown as waiting for QC.
        $inTransitQty = (int) ($fulfillment['in_transit_qty'] ?? 0);
        $arrivedPendingQcQty = (int) ($fulfillment['waiting_qc_qty'] ?? 0);
        $unresolvedNgQty = (int) ($fulfillment['unresolved_ng_qty'] ?? 0);

        $isPoClosed = $po && in_array($po->status, ['completed', 'cancelled'], true);
        $canUpdate = ! $isPoClosed && $supplierControlledQty > 0;

        // Arrived is derived, never stored: nothing is left with the supplier or in
        // transit, and something has reached Purchasing. A partial arrival keeps the
        // manual stage so the remainder can still be shipped.
        $hasArrived = $supplierControlledQty === 0
            && $inTransitQty === 0
            && ($acceptedQty + $arrivedPendingQcQty + $unresolvedNgQty) > 0;
        $displayStatus = $hasArrived ? PoItemProgressUpdate::DISPLAY_STATUS_ARRIVED : $status;

        return [
            'award_id' => $award->id,
            'award_hashid' => $award->hash,
            'award' => $award,
            'material_name' => $award->prItem?->material_name ?? __('purchasing.copy.material'),
            'hs_code' => $award->prItem?->hs_code,
            'ordered_qty' => $orderedQty,
            'accepted_qty' => $acceptedQty,
            'in_transit_qty' => $inTransitQty,
            'arrived_pending_qc_qty' => $arrivedPendingQcQty,
            'unresolved_ng_qty' => $unresolvedNgQty,
            'supplier_controlled_qty' => $supplierControlledQty,
            'manual_progress_status' => $status,
            'manual_progress_label' => StatusHelper::materialProgressLabel($status),
            'manual_progress_badge' => StatusHelper::materialProgressBadge($status),
            'manual_progress_tone' => StatusHelper::materialProgressTone($status),
            'progress_status' => $displayStatus,
            'progress_label' => StatusHelper::materialProgressLabel($displayStatus),
            'progress_badge' => StatusHelper::materialProgressBadge($displayStatus),
            'progress_tone' => StatusHelper::materialProgressTone($displayStatus),
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
            abort(403, __('purchasing.copy.unauthorized_only_the_assigned_supplier_can_update_material_progress'));
        }

        if ((int) $award->purchase_order_id !== (int) $po->id) {
            abort(403, __('purchasing.copy.award_does_not_belong_to_the_requested_purchase_order'));
        }

        $newStatus = $data['status'] ?? null;
        if (! in_array($newStatus, PoItemProgressUpdate::SUPPLIER_UPDATABLE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => __('purchasing.copy.the_selected_material_progress_status_is_invalid'),
            ]);
        }

        return DB::transaction(function () use ($po, $award, $user, $data, $newStatus) {
            // Lock PO and Award deterministically: PO first, then Award
            $lockedPo = PurchaseOrder::where('id', $po->id)->lockForUpdate()->firstOrFail();
            $lockedAward = PrItemAward::where('id', $award->id)->lockForUpdate()->firstOrFail();

            if ((int) $lockedAward->purchase_order_id !== (int) $lockedPo->id) {
                abort(403, __('purchasing.copy.award_does_not_belong_to_locked_purchase_order'));
            }

            if (in_array($lockedPo->status, ['completed', 'cancelled'], true)) {
                throw ValidationException::withMessages([
                    'status' => __('purchasing.copy.material_progress_locked_po', [
                        'status' => StatusHelper::poLabel($lockedPo->status),
                    ]),
                ]);
            }

            // Read authoritative fulfillment Qty
            $fulfillment = $lockedPo->itemFulfillmentStatus($lockedAward->quotation_item_id);
            $supplierControlledQty = (int) ($fulfillment['remaining_qty'] ?? 0);

            if ($supplierControlledQty <= 0) {
                throw ValidationException::withMessages([
                    'status' => __('purchasing.copy.cannot_update_material_progress_when_supplier_controlled_quantity_is_zero'),
                ]);
            }

            // Resolve previous status
            $latestUpdate = $lockedAward->progressUpdates()->orderByDesc('id')->first();
            $previousStatus = ($latestUpdate && $latestUpdate->status !== PoItemProgressUpdate::STATUS_AWAITING_CONFIRMATION)
                ? $latestUpdate->status
                : PoItemProgressUpdate::STATUS_ORDER_CONFIRMED;

            // Check backward transition
            if ($this->isBackwardTransition($previousStatus, $newStatus)) {
                $note = trim($data['note'] ?? '');
                if ($note === '') {
                    throw ValidationException::withMessages([
                        'note' => __('purchasing.copy.a_progress_note_or_reason_is_required_when_moving_material_progress_backward'),
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
            $this->notifyPurchasingSingleUpdate(
                $lockedPo,
                $lockedAward,
                $update,
                $user,
                $previousStatus,
                $newStatus,
                $supplierControlledQty,
            );

            return $update;
        });
    }

    /**
     * Record progress updates for multiple awarded items on a PO atomically.
     *
     * @return Collection<int, PoItemProgressUpdate>
     *
     * @throws ValidationException
     */
    public function bulkUpdateProgress(PurchaseOrder $po, User $user, array $data): Collection
    {
        if ($user->role !== 'supplier' || (int) $user->id !== (int) $po->supplier_id) {
            abort(403, __('purchasing.copy.unauthorized_only_the_assigned_supplier_can_update_material_progress'));
        }

        $newStatus = $data['status'] ?? null;
        if (! in_array($newStatus, PoItemProgressUpdate::SUPPLIER_UPDATABLE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => __('purchasing.copy.the_selected_material_progress_status_is_invalid'),
            ]);
        }

        $awardIds = collect($data['award_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($awardIds === []) {
            throw ValidationException::withMessages([
                'award_ids' => __('supplier.copy.select_at_least_one_item'),
            ]);
        }

        return DB::transaction(function () use ($po, $user, $data, $newStatus, $awardIds): Collection {
            $lockedPo = PurchaseOrder::where('id', $po->id)->lockForUpdate()->firstOrFail();

            if (in_array($lockedPo->status, ['completed', 'cancelled'], true)) {
                throw ValidationException::withMessages([
                    'status' => __('purchasing.copy.material_progress_locked_po', [
                        'status' => StatusHelper::poLabel($lockedPo->status),
                    ]),
                ]);
            }

            $lockedAwards = PrItemAward::with('prItem')
                ->whereIn('id', $awardIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($lockedAwards->count() !== count($awardIds)) {
                abort(403, __('purchasing.copy.award_does_not_belong_to_the_requested_purchase_order'));
            }

            foreach ($lockedAwards as $lockedAward) {
                if ((int) $lockedAward->supplier_id !== (int) $user->id) {
                    abort(403, __('purchasing.copy.unauthorized_only_the_assigned_supplier_can_update_material_progress'));
                }
                if ((int) $lockedAward->purchase_order_id !== (int) $lockedPo->id) {
                    abort(403, __('purchasing.copy.award_does_not_belong_to_locked_purchase_order'));
                }
            }

            $fulfillmentByItem = $lockedPo->fulfillmentProjection()['items'];
            $hasExplicitDate = ! empty($data['estimated_ready_date']);
            $note = trim((string) ($data['note'] ?? ''));

            $pendingItems = [];
            $hasBackward = false;

            foreach ($awardIds as $awardId) {
                $lockedAward = $lockedAwards->get($awardId);
                $supplierControlledQty = (int) ($fulfillmentByItem[$lockedAward->quotation_item_id]['remaining_qty'] ?? 0);

                if ($supplierControlledQty <= 0) {
                    throw ValidationException::withMessages([
                        'award_ids' => __('purchasing.copy.cannot_update_material_progress_when_supplier_controlled_quantity_is_zero'),
                    ]);
                }

                $latestUpdate = $lockedAward->progressUpdates()->orderByDesc('id')->first();
                $previousStatus = ($latestUpdate && $latestUpdate->status !== PoItemProgressUpdate::STATUS_AWAITING_CONFIRMATION)
                    ? $latestUpdate->status
                    : PoItemProgressUpdate::STATUS_ORDER_CONFIRMED;

                if ($this->isBackwardTransition($previousStatus, $newStatus)) {
                    $hasBackward = true;
                }

                $pendingItems[] = [
                    'award' => $lockedAward,
                    'qty' => $supplierControlledQty,
                    'latest_update' => $latestUpdate,
                    'previous_status' => $previousStatus,
                ];
            }

            if ($hasBackward && $note === '') {
                throw ValidationException::withMessages([
                    'note' => __('purchasing.copy.a_progress_note_or_reason_is_required_when_moving_material_progress_backward'),
                ]);
            }

            $updates = new Collection;
            foreach ($pendingItems as $entry) {
                $estimatedReadyDate = $hasExplicitDate
                    ? $data['estimated_ready_date']
                    : $entry['latest_update']?->estimated_ready_date?->toDateString();

                $updates->push(PoItemProgressUpdate::create([
                    'pr_item_award_id' => $entry['award']->id,
                    'status' => $newStatus,
                    'supplier_controlled_qty_snapshot' => $entry['qty'],
                    'estimated_ready_date' => $estimatedReadyDate,
                    'note' => $note !== '' ? $note : null,
                    'updated_by' => $user->id,
                ]));
            }

            if ($updates->count() === 1) {
                $first = $pendingItems[0];
                $this->notifyPurchasingSingleUpdate(
                    $lockedPo,
                    $first['award'],
                    $updates->first(),
                    $user,
                    $first['previous_status'],
                    $newStatus,
                    $first['qty'],
                );
            } else {
                $this->notifyPurchasingBulkUpdate($lockedPo, $updates, $user, $newStatus);
            }

            return $updates;
        });
    }

    private function notifyPurchasingSingleUpdate(
        PurchaseOrder $lockedPo,
        PrItemAward $lockedAward,
        PoItemProgressUpdate $update,
        User $user,
        string $previousStatus,
        string $newStatus,
        int $supplierControlledQty,
    ): void {
        $purchasingUsers = User::where('role', 'purchasing')->where('is_active', true)->get();

        $this->notifications->send(
            $purchasingUsers,
            'po_item_progress.updated',
            "po_item_progress.updated:{$update->id}",
            'purchasing.copy.supplier_material_progress_updated',
            'purchasing.notify.material_progress_body',
            route('purchasing.purchase-orders.show', $lockedPo, absolute: false).'#material-progress',
            'clock text-primary',
            [
                'category' => NotificationCategory::OTHER,
                'po_id' => $lockedPo->id,
                'award_id' => $lockedAward->id,
                'progress_update_id' => $update->id,
            ],
            [
                'supplier' => $user->name,
                'material' => $lockedAward->prItem?->material_name ?? '-',
                'po' => $lockedPo->po_number,
                'qty' => $supplierControlledQty,
                'ready' => $update->estimated_ready_date ? '@date:'.$update->estimated_ready_date->toDateString() : __('purchasing.copy.not_specified'),
            ],
            [
                'from' => 'status.material_progress.'.$previousStatus,
                'to' => 'status.material_progress.'.$newStatus,
            ],
        );
    }

    /**
     * @param  Collection<int, PoItemProgressUpdate>  $updates
     */
    private function notifyPurchasingBulkUpdate(
        PurchaseOrder $lockedPo,
        Collection $updates,
        User $user,
        string $newStatus,
    ): void {
        $purchasingUsers = User::where('role', 'purchasing')->where('is_active', true)->get();
        $firstId = $updates->first()?->id ?? 0;

        $this->notifications->send(
            $purchasingUsers,
            'po_item_progress.bulk_updated',
            "po_item_progress.bulk_updated:{$lockedPo->id}:{$firstId}:{$updates->count()}",
            'purchasing.copy.supplier_material_progress_updated',
            'purchasing.notify.material_progress_bulk_body',
            route('purchasing.purchase-orders.show', $lockedPo, absolute: false).'#material-progress',
            'clock text-primary',
            [
                'category' => NotificationCategory::OTHER,
                'po_id' => $lockedPo->id,
                'progress_update_ids' => $updates->pluck('id')->all(),
            ],
            [
                'supplier' => $user->name,
                'count' => $updates->count(),
                'po' => $lockedPo->po_number,
            ],
            [
                'to' => 'status.material_progress.'.$newStatus,
            ],
        );
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
                'text' => __('purchasing.copy.no_awarded_items'),
                'counts' => [],
                'is_homogeneous' => true,
                'items' => [],
            ];
        }

        $counts = [];
        $itemProjections = [];
        // One load for every award line instead of a fresh query set per award.
        $fulfillmentByItem = $po->fulfillmentProjection()['items'];

        foreach ($awards as $award) {
            $projection = $this->projectionForAward($award, $fulfillmentByItem[$award->quotation_item_id] ?? null);
            $itemProjections[] = $projection;

            if ($projection['supplier_controlled_qty'] > 0) {
                $stageLabel = $projection['manual_progress_label'];
                $counts[$stageLabel] = ($counts[$stageLabel] ?? 0) + 1;
            } elseif ($projection['in_transit_qty'] > 0) {
                $counts[__('purchasing.copy.in_transit')] = ($counts[__('purchasing.copy.in_transit')] ?? 0) + 1;
            } elseif ($projection['accepted_qty'] >= $projection['ordered_qty']) {
                $counts[__('purchasing.copy.accepted')] = ($counts[__('purchasing.copy.accepted')] ?? 0) + 1;
            } elseif ($projection['arrived_pending_qc_qty'] > 0) {
                $counts[__('purchasing.copy.waiting_qc')] = ($counts[__('purchasing.copy.waiting_qc')] ?? 0) + 1;
            } else {
                $stageLabel = $projection['progress_label'];
                $counts[$stageLabel] = ($counts[$stageLabel] ?? 0) + 1;
            }
        }

        $parts = [];
        foreach ($counts as $label => $count) {
            $parts[] = trans_choice('purchasing.copy.progress_summary', $count, ['count' => $count, 'stage' => $label]);
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
