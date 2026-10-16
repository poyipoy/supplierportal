<?php

namespace App\Services;

use App\Models\PrItem;
use App\Models\PrItemAward;
use App\Models\PrItemAwardEvent;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\User;
use App\Support\Procurement\ProcurementFeatures;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class PrItemAwardService
{
    /**
     * Award a single PR item to a winning quotation item in an atomic transaction.
     *
     * @throws InvalidArgumentException
     */
    public function awardItem(
        int|PrItem $prItem,
        int|QuotationItem $quotationItem,
        User $user
    ): PrItemAward {
        $prItemId = $prItem instanceof PrItem ? $prItem->id : $prItem;
        $quotationItemId = $quotationItem instanceof QuotationItem ? $quotationItem->id : $quotationItem;

        // Resolve only the parent identity before starting the transaction.
        $prId = PrItem::whereKey($prItemId)->value('pr_id');
        if (! $prId) {
            throw new InvalidArgumentException(__('purchasing.errors.item_not_found', ['id' => $prItemId]));
        }

        return DB::transaction(function () use ($prId, $prItemId, $quotationItemId, $user) {
            PurchaseRequisition::whereKey($prId)->lockForUpdate()->firstOrFail();

            // Parent PR always precedes PR items and quotation locks.
            /** @var PrItem|null $lockedPrItem */
            $lockedPrItem = PrItem::query()
                ->where('id', $prItemId)
                ->where('pr_id', $prId)
                ->lockForUpdate()
                ->first();

            if (! $lockedPrItem) {
                throw new InvalidArgumentException(__('purchasing.errors.item_not_found', ['id' => $prItemId]));
            }

            $quotationId = QuotationItem::whereKey($quotationItemId)->value('quotation_id');
            $quotation = Quotation::whereKey($quotationId)->lockForUpdate()->first();

            // Lock the child only after its quotation, then revalidate both.
            /** @var QuotationItem|null $lockedQuotationItem */
            $lockedQuotationItem = QuotationItem::query()
                ->where('id', $quotationItemId)
                ->lockForUpdate()
                ->first();

            if (! $lockedQuotationItem) {
                throw new InvalidArgumentException(__('purchasing.errors.quotation_item_missing', ['id' => $quotationItemId]));
            }

            if (! $quotation || (int) $lockedQuotationItem->quotation_id !== (int) $quotation->id) {
                throw new InvalidArgumentException(__('purchasing.errors.missing_quotation', ['id' => $quotationItemId]));
            }

            // Invariant & validation checks
            if ((int) $lockedQuotationItem->pr_item_id !== (int) $lockedPrItem->id) {
                throw new InvalidArgumentException(__('purchasing.copy.quotation_item_does_not_match_the_requested_pr_item'));
            }

            if ((int) $quotation->pr_id !== (int) $lockedPrItem->pr_id) {
                throw new InvalidArgumentException(__('purchasing.copy.quotation_does_not_belong_to_the_same_purchase_requisition'));
            }

            if (! $lockedQuotationItem->is_available) {
                throw new InvalidArgumentException(__('purchasing.copy.cannot_award_an_item_that_is_marked_as_unavailable_by_the_supplier'));
            }

            if ($quotation->status === Quotation::STATUS_ALL_UNAVAILABLE) {
                throw new InvalidArgumentException(__('purchasing.copy.cannot_award_an_item_from_a_quotation_marked_as_all_unavailable_only_submitted_or_accepted_quotation'));
            }

            if (! in_array($quotation->status, Quotation::AWARD_ELIGIBLE_STATUSES, true)) {
                throw new InvalidArgumentException(__('purchasing.guard_copy.award_status', ['status' => $quotation->status]));
            }

            // Check existing award for this PR item
            $existingAward = PrItemAward::where('pr_item_id', $lockedPrItem->id)->lockForUpdate()->first();
            if ($existingAward && $existingAward->purchase_order_id !== null) {
                throw new InvalidArgumentException(__('purchasing.guard_copy.item_assigned', ['item' => $lockedPrItem->id, 'po' => $existingAward->purchase_order_id]));
            }

            // Create or update award
            if ($existingAward) {
                $this->clearStaleSelection($existingAward, (int) $lockedQuotationItem->id, $user);
                $existingAward->update([
                    'quotation_id' => $quotation->id,
                    'quotation_item_id' => $lockedQuotationItem->id,
                    'supplier_id' => $quotation->supplier_id,
                    'awarded_by' => $user->id,
                    'awarded_at' => now(),
                ]);

                return $existingAward->fresh(['prItem', 'quotationItem', 'supplier', 'quotation']);
            }

            return PrItemAward::create([
                'pr_id' => $lockedPrItem->pr_id,
                'pr_item_id' => $lockedPrItem->id,
                'quotation_id' => $quotation->id,
                'quotation_item_id' => $lockedQuotationItem->id,
                'supplier_id' => $quotation->supplier_id,
                'purchase_order_id' => null,
                'awarded_by' => $user->id,
                'awarded_at' => now(),
            ]);
        });
    }

    /**
     * Award multiple PR items atomically (alias for awardBatch).
     *
     * @param  array<int, int>  $selections  Map of pr_item_id => quotation_item_id
     * @return Collection<int, PrItemAward>
     */
    public function saveAwards(
        PurchaseRequisition $pr,
        array $selections,
        User $user
    ): Collection {
        return $this->awardBatch($pr, $selections, $user);
    }

    /**
     * Award multiple PR items atomically.
     *
     * @param  array<int, int>  $selections  Map of pr_item_id => quotation_item_id
     * @param  array<int, array{order_qty?: mixed, accept_spec?: mixed}>  $orderSelections  Map of pr_item_id => Purchasing selection (M04-W)
     * @return Collection<int, PrItemAward>
     */
    public function awardBatch(
        PurchaseRequisition $pr,
        array $selections,
        User $user,
        array $orderSelections = []
    ): Collection {
        if (empty($selections)) {
            return collect();
        }

        return DB::transaction(function () use ($pr, $selections, $user, $orderSelections) {
            $pr = PurchaseRequisition::whereKey($pr->id)->lockForUpdate()->firstOrFail();
            $prItemIds = array_keys($selections);
            sort($prItemIds);

            $quotationItemIds = array_values($selections);
            sort($quotationItemIds);

            // Lock all PR items deterministically by ID
            $lockedPrItems = PrItem::whereIn('id', $prItemIds)
                ->where('pr_id', $pr->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($lockedPrItems->count() !== count($prItemIds)) {
                throw new InvalidArgumentException(__('purchasing.guard_copy.items_pr', ['pr' => $pr->id]));
            }

            $quotationIds = QuotationItem::whereIn('id', $quotationItemIds)
                ->pluck('quotation_id')->unique()->sort()->values()->all();
            $lockedQuotations = Quotation::whereIn('id', $quotationIds)
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            // Quotation parents precede their item rows.
            $lockedQuotationItems = QuotationItem::query()
                ->whereIn('id', $quotationItemIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($lockedQuotationItems->count() !== count(array_unique($quotationItemIds))) {
                throw new InvalidArgumentException(__('purchasing.copy.one_or_more_quotation_items_could_not_be_found'));
            }

            // Lock existing awards for these PR items
            $existingAwards = PrItemAward::whereIn('pr_item_id', $prItemIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('pr_item_id');

            $results = collect();

            foreach ($selections as $prItemId => $quotationItemId) {
                $prItem = $lockedPrItems->get($prItemId);
                $quotationItem = $lockedQuotationItems->get($quotationItemId);

                if (! $prItem || ! $quotationItem) {
                    throw new InvalidArgumentException(__('purchasing.errors.invalid_item_selection', ['id' => $prItemId]));
                }

                $quotation = $lockedQuotations->get($quotationItem->quotation_id);
                if (! $quotation) {
                    throw new InvalidArgumentException(__('purchasing.errors.quotation_changed', ['id' => $quotationItemId]));
                }

                if ((int) $quotationItem->pr_item_id !== (int) $prItem->id) {
                    throw new InvalidArgumentException(__('purchasing.errors.item_mismatch', ['quotation_item' => $quotationItemId, 'pr_item' => $prItemId]));
                }

                if ((int) $quotation->pr_id !== (int) $pr->id) {
                    throw new InvalidArgumentException(__('purchasing.guard_copy.quotation_pr', ['quotation' => $quotation->id, 'pr' => $pr->id]));
                }

                if (! $quotationItem->is_available) {
                    throw new InvalidArgumentException(__('purchasing.errors.unavailable_item', ['id' => $quotationItemId]));
                }

                if ($quotation->status === Quotation::STATUS_ALL_UNAVAILABLE) {
                    throw new InvalidArgumentException(__('purchasing.guard_copy.quotation_unavailable', ['quotation' => $quotation->id]));
                }

                if (! in_array($quotation->status, Quotation::AWARD_ELIGIBLE_STATUSES, true)) {
                    throw new InvalidArgumentException(__('purchasing.guard_copy.quotation_award', ['quotation' => $quotation->id, 'status' => $quotation->status]));
                }

                $existingAward = $existingAwards->get($prItemId);
                if ($existingAward && $existingAward->purchase_order_id !== null) {
                    throw new InvalidArgumentException(__('purchasing.guard_copy.item_assigned', ['item' => $prItemId, 'po' => $existingAward->purchase_order_id]));
                }

                if ($existingAward) {
                    $this->clearStaleSelection($existingAward, (int) $quotationItem->id, $user);
                    $existingAward->update([
                        'quotation_id' => $quotation->id,
                        'quotation_item_id' => $quotationItem->id,
                        'supplier_id' => $quotation->supplier_id,
                        'awarded_by' => $user->id,
                        'awarded_at' => now(),
                    ]);
                    $award = $existingAward;
                } else {
                    $award = PrItemAward::create([
                        'pr_id' => $pr->id,
                        'pr_item_id' => $prItem->id,
                        'quotation_id' => $quotation->id,
                        'quotation_item_id' => $quotationItem->id,
                        'supplier_id' => $quotation->supplier_id,
                        'purchase_order_id' => null,
                        'awarded_by' => $user->id,
                        'awarded_at' => now(),
                    ]);
                }

                $quotationItem->setRelation('prItem', $prItem);
                $this->applySelection($award, $quotationItem, $orderSelections[$prItemId] ?? [], $user);
                $results->push($existingAward ? $award->fresh(['prItem', 'quotationItem', 'supplier']) : $award);
            }

            return $results;
        });
    }

    /**
     * Mission 04-W (decisions 01–04, Q2): persist a Purchasing order quantity
     * and/or specification acceptance on an award the caller holds locked.
     * Only keys present in $selection are applied; the server validates
     * 1 ≤ order_qty ≤ offered and keys errors to the line. While the write path
     * is disabled nothing is written, so guards (a)/(b) stay authoritative.
     *
     * @param  array{order_qty?: mixed, accept_spec?: mixed}  $selection
     *
     * @throws ValidationException
     */
    public function applySelection(PrItemAward $award, QuotationItem $quotationItem, array $selection, User $user): void
    {
        if (! ProcurementFeatures::orderedQuantityWriteEnabled()) {
            return;
        }

        $prItemId = (int) $award->pr_item_id;
        $material = $quotationItem->prItem?->material_name ?? '#'.$prItemId;
        $requested = $quotationItem->prItem?->quantity_value;
        $offered = (int) $quotationItem->fulfillment_quantity;

        $rawQty = $selection['order_qty'] ?? null;
        if ($rawQty !== null && $rawQty !== '') {
            $qty = filter_var($rawQty, FILTER_VALIDATE_INT);
            if ($qty === false || $qty < 1 || $qty > $offered) {
                throw ValidationException::withMessages([
                    "order_qty.{$prItemId}" => __('procurement.ordered_quantity.order_qty_range', ['material' => $material, 'max' => $offered]),
                ]);
            }

            if ($award->ordered_qty !== $qty) {
                $previous = $award->ordered_qty;
                $award->forceFill(['ordered_qty' => $qty])->save();
                $this->recordEvent($award, PrItemAwardEvent::ORDERED_QTY_SELECTED, [
                    'previous' => $previous,
                    'ordered_qty' => $qty,
                    'requested_qty' => $requested,
                    'offered_qty' => $offered,
                ], $user);
            }
        }

        $rawAccept = $selection['accept_spec'] ?? null;
        if ($rawAccept === null || $rawAccept === '') {
            return;
        }

        $accept = filter_var($rawAccept, FILTER_VALIDATE_BOOL);
        if ($accept && $quotationItem->availability_comparison['specification']['code'] === 'different') {
            $snapshot = $quotationItem->specificationSnapshot();
            if (! $award->isSpecAcceptanceCurrent($snapshot)) {
                $hash = PrItemAward::specAcceptanceHash($snapshot);
                $award->forceFill([
                    'spec_accepted_by' => $user->id,
                    'spec_accepted_at' => now(),
                    'spec_acceptance_snapshot' => $snapshot,
                    'spec_acceptance_hash' => $hash,
                ])->save();
                $this->recordEvent($award, PrItemAwardEvent::SPEC_ACCEPTED, ['snapshot' => $snapshot, 'hash' => $hash], $user);
            }
        } elseif (! $accept && $award->spec_accepted_at !== null) {
            $previousHash = $award->spec_acceptance_hash;
            $award->forceFill([
                'spec_accepted_by' => null,
                'spec_accepted_at' => null,
                'spec_acceptance_snapshot' => null,
                'spec_acceptance_hash' => null,
            ])->save();
            $this->recordEvent($award, PrItemAwardEvent::SPEC_ACCEPTANCE_REVOKED, ['previous_hash' => $previousHash], $user);
        }
    }

    private function recordEvent(PrItemAward $award, string $event, array $payload, User $user): void
    {
        PrItemAwardEvent::create([
            'pr_item_award_id' => $award->id,
            'pr_id' => $award->pr_id,
            'pr_item_id' => $award->pr_item_id,
            'quotation_item_id' => $award->quotation_item_id,
            'purchase_order_id' => $award->purchase_order_id,
            'event' => $event,
            'payload' => $payload,
            'actor_id' => $user->id,
        ]);
    }

    /**
     * Compute item award coverage for a PR.
     *
     * @return array{
     *     total_items: int,
     *     awarded_items: int,
     *     unawarded_items: int,
     *     is_fully_awarded: bool,
     *     coverage_percentage: float
     * }
     */
    public function getCoverage(PurchaseRequisition $pr): array
    {
        $totalItems = $pr->items()->count();
        $awardedItems = $pr->awards()->count();
        $unawarded = max(0, $totalItems - $awardedItems);

        return [
            'total_items' => $totalItems,
            'awarded_items' => $awardedItems,
            'unawarded_items' => $unawarded,
            'is_fully_awarded' => $totalItems > 0 && $awardedItems === $totalItems,
            'coverage_percentage' => $totalItems > 0 ? round(($awardedItems / $totalItems) * 100, 1) : 0.0,
        ];
    }

    /**
     * Group unassigned awards by supplier for PO preview.
     *
     * @return Collection<int, Collection<int, PrItemAward>>
     */
    public function getSupplierGrouping(PurchaseRequisition $pr): Collection
    {
        return $pr->awards()
            ->with(['prItem', 'quotationItem', 'supplier', 'quotation.exchange_rate'])
            ->unassignedToPo()
            ->get()
            ->groupBy('supplier_id');
    }

    /**
     * I-21: a Purchasing selection (ordered quantity or specification
     * acceptance) belongs to one offer. Re-pointing the award to another offer
     * clears it in the same transaction and records why. The caller holds the
     * award row lock.
     */
    private function clearStaleSelection(PrItemAward $award, int $newQuotationItemId, User $user): void
    {
        if ((int) $award->quotation_item_id === $newQuotationItemId || ! $award->hasPurchasingSelection()) {
            return;
        }

        $previous = [
            'previous_quotation_item_id' => (int) $award->quotation_item_id,
            'new_quotation_item_id' => $newQuotationItemId,
            'previous_ordered_qty' => $award->ordered_qty,
            'previous_spec_accepted_at' => $award->spec_accepted_at?->toIso8601String(),
        ];

        $award->forceFill([
            'ordered_qty' => null,
            'spec_accepted_by' => null,
            'spec_accepted_at' => null,
            'spec_acceptance_snapshot' => null,
            'spec_acceptance_hash' => null,
        ])->save();

        PrItemAwardEvent::create([
            'pr_item_award_id' => $award->id,
            'pr_id' => $award->pr_id,
            'pr_item_id' => $award->pr_item_id,
            'quotation_item_id' => $newQuotationItemId,
            'event' => PrItemAwardEvent::SELECTION_CLEARED,
            'payload' => $previous,
            'actor_id' => $user->id,
        ]);
    }
}
