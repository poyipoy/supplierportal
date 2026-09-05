<?php

namespace App\Services;

use App\Models\PrItem;
use App\Models\PrItemAward;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
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

        return DB::transaction(function () use ($prItemId, $quotationItemId, $user) {
            // Deterministic pessimistic lock on PR item
            /** @var PrItem|null $lockedPrItem */
            $lockedPrItem = PrItem::query()
                ->where('id', $prItemId)
                ->lockForUpdate()
                ->first();

            if (! $lockedPrItem) {
                throw new InvalidArgumentException("PR item #{$prItemId} not found.");
            }

            // Deterministic pessimistic lock on Quotation item with quotation loaded
            /** @var QuotationItem|null $lockedQuotationItem */
            $lockedQuotationItem = QuotationItem::query()
                ->with(['quotation.supplier'])
                ->where('id', $quotationItemId)
                ->lockForUpdate()
                ->first();

            if (! $lockedQuotationItem) {
                throw new InvalidArgumentException("Quotation item #{$quotationItemId} not found.");
            }

            $quotation = $lockedQuotationItem->quotation;
            if (! $quotation) {
                throw new InvalidArgumentException("Quotation item #{$quotationItemId} has no associated quotation.");
            }

            // Invariant & validation checks
            if ((int) $lockedQuotationItem->pr_item_id !== (int) $lockedPrItem->id) {
                throw new InvalidArgumentException("Quotation item does not match the requested PR item.");
            }

            if ((int) $quotation->pr_id !== (int) $lockedPrItem->pr_id) {
                throw new InvalidArgumentException("Quotation does not belong to the same Purchase Requisition.");
            }

            if (! $lockedQuotationItem->is_available) {
                throw new InvalidArgumentException("Cannot award an item that is marked as unavailable by the supplier.");
            }

            if ($quotation->status === Quotation::STATUS_ALL_UNAVAILABLE) {
                throw new InvalidArgumentException("Cannot award an item from a quotation marked as all unavailable. Only 'submitted' or 'accepted' quotations are eligible.");
            }

            if (! in_array($quotation->status, Quotation::AWARD_ELIGIBLE_STATUSES, true)) {
                throw new InvalidArgumentException("Cannot award an item from a quotation with status '{$quotation->status}'. Only 'submitted' or 'accepted' quotations are eligible.");
            }

            // Check existing award for this PR item
            $existingAward = PrItemAward::where('pr_item_id', $lockedPrItem->id)->lockForUpdate()->first();
            if ($existingAward && $existingAward->purchase_order_id !== null) {
                throw new InvalidArgumentException("PR item #{$lockedPrItem->id} has already been assigned to Purchase Order #{$existingAward->purchase_order_id}.");
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
     * @param PurchaseRequisition $pr
     * @param array<int, int> $selections Map of pr_item_id => quotation_item_id
     * @param User $user
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
     * @param PurchaseRequisition $pr
     * @param array<int, int> $selections Map of pr_item_id => quotation_item_id
     * @param User $user
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
                throw new InvalidArgumentException("One or more PR items do not belong to PR #{$pr->id}.");
            }

            // Lock all Quotation items deterministically by ID
            $lockedQuotationItems = QuotationItem::with(['quotation.supplier'])
                ->whereIn('id', $quotationItemIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($lockedQuotationItems->count() !== count(array_unique($quotationItemIds))) {
                throw new InvalidArgumentException("One or more quotation items could not be found.");
            }

            // Lock existing awards for these PR items
            $existingAwards = PrItemAward::whereIn('pr_item_id', $prItemIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('pr_item_id');

            $results = collect();

            foreach ($selections as $prItemId => $quotationItemId) {
                $prItem = $lockedPrItems->get($prItemId);
                $quotationItem = $lockedQuotationItems->get($quotationItemId);

                if (! $prItem || ! $quotationItem) {
                    throw new InvalidArgumentException("Invalid item selection for PR item #{$prItemId}.");
                }

                $quotation = $quotationItem->quotation;

                if ((int) $quotationItem->pr_item_id !== (int) $prItem->id) {
                    throw new InvalidArgumentException("Quotation item #{$quotationItemId} does not match PR item #{$prItemId}.");
                }

                if ((int) $quotation->pr_id !== (int) $pr->id) {
                    throw new InvalidArgumentException("Quotation #{$quotation->id} does not belong to PR #{$pr->id}.");
                }

                if (! $quotationItem->is_available) {
                    throw new InvalidArgumentException("Item #{$quotationItemId} is marked as unavailable by supplier.");
                }

                if ($quotation->status === Quotation::STATUS_ALL_UNAVAILABLE) {
                    throw new InvalidArgumentException("Quotation #{$quotation->id} is marked as all unavailable. Only 'submitted' or 'accepted' quotations are eligible.");
                }

                if (! in_array($quotation->status, Quotation::AWARD_ELIGIBLE_STATUSES, true)) {
                    throw new InvalidArgumentException("Quotation #{$quotation->id} with status '{$quotation->status}' is not eligible for award. Only 'submitted' or 'accepted' quotations are eligible.");
                }

                $existingAward = $existingAwards->get($prItemId);
                if ($existingAward && $existingAward->purchase_order_id !== null) {
                    throw new InvalidArgumentException("PR item #{$prItemId} has already been assigned to Purchase Order #{$existingAward->purchase_order_id}.");
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
