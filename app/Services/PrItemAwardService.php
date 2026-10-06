<?php

namespace App\Services;

use App\Models\PrItem;
use App\Models\PrItemAward;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
     * @return Collection<int, PrItemAward>
     */
    public function awardBatch(
        PurchaseRequisition $pr,
        array $selections,
        User $user
    ): Collection {
        if (empty($selections)) {
            return collect();
        }

        return DB::transaction(function () use ($pr, $selections, $user) {
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
                    $existingAward->update([
                        'quotation_id' => $quotation->id,
                        'quotation_item_id' => $quotationItem->id,
                        'supplier_id' => $quotation->supplier_id,
                        'awarded_by' => $user->id,
                        'awarded_at' => now(),
                    ]);
                    $results->push($existingAward->fresh(['prItem', 'quotationItem', 'supplier']));
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
                    $results->push($award);
                }
            }

            return $results;
        });
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
}
