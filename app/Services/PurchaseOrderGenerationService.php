<?php

namespace App\Services;

use App\Models\ExchangeRate;
use App\Models\PoDocument;
use App\Models\PrItem;
use App\Models\PrItemAward;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\User;
use App\Support\NotificationCategory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PurchaseOrderGenerationService
{
    public function __construct(
        protected NotificationService $notifications
    ) {}

    /**
     * Generate separate Purchase Orders from awarded items grouped by supplier.
     *
     * @param  Collection<int, PrItemAward>|array<int>  $awards  Awards or award IDs
     * @param array{
     *     estimated_arrival?: string|null,
     *     notes?: string|null,
     *     estimated_arrivals?: array<int, string>,
     *     supplier_notes?: array<int, string>
     * } $options
     * @return Collection<int, PurchaseOrder>
     *
    /**
     * Generate separate Purchase Orders from awarded items grouped by supplier (alias).
     */
    public function generatePurchaseOrdersForAwards(
        Collection|array $awards,
        User $creator,
        array $options = []
    ): Collection {
        return $this->generateFromAwards($awards, $creator, $options);
    }

    /**
     * Generate separate Purchase Orders from awarded items grouped by supplier.
     *
     * @param  Collection<int, PrItemAward>|array<int>  $awards  Awards or award IDs
     * @param array{
     *     estimated_arrival?: string|null,
     *     notes?: string|null,
     *     estimated_arrivals?: array<int, string>,
     *     supplier_notes?: array<int, string>
     * } $options
     * @return Collection<int, PurchaseOrder>
     *
     * @throws InvalidArgumentException
     * @throws \Throwable
     */
    public function generateFromAwards(
        Collection|array $awards,
        User $creator,
        array $options = []
    ): Collection {
        $awardIds = collect($awards)->map(function ($award) {
            return $award instanceof PrItemAward ? (int) $award->id : (int) $award;
        })->filter(fn ($id) => $id > 0)->values()->all();

        if (empty($awardIds)) {
            throw new InvalidArgumentException('No item awards selected for PO generation.');
        }

        sort($awardIds);

        return DB::transaction(function () use ($awardIds, $creator, $options) {
            $awardReferences = PrItemAward::query()
                ->whereIn('id', $awardIds)
                ->orderBy('id')
                ->get(['id', 'pr_id', 'pr_item_id', 'quotation_id', 'quotation_item_id']);
            if ($awardReferences->count() !== count($awardIds)) {
                throw new InvalidArgumentException('One or more selected item awards could not be found.');
            }

            $prIds = $awardReferences->pluck('pr_id')->unique()->sort()->values()->all();
            PurchaseRequisition::query()
                ->whereIn('id', $prIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $prItemIds = $awardReferences->pluck('pr_item_id')->unique()->sort()->values()->all();
            PrItem::query()
                ->whereIn('id', $prItemIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            // Award writers lock PR items first, so these references are now stable.
            $awardReferences = PrItemAward::query()
                ->whereIn('id', $awardIds)
                ->orderBy('id')
                ->get(['id', 'quotation_id', 'quotation_item_id']);
            if ($awardReferences->count() !== count($awardIds)) {
                throw new InvalidArgumentException('One or more selected item awards changed during PO generation.');
            }

            $quotationIds = $awardReferences->pluck('quotation_id')->unique()->sort()->values()->all();
            Quotation::query()
                ->whereIn('id', $quotationIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $quotationItemIds = $awardReferences->pluck('quotation_item_id')->unique()->sort()->values()->all();
            QuotationItem::query()
                ->whereIn('id', $quotationItemIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            // Lock all awards last in the shared deterministic finalization order.
            /** @var Collection<int, PrItemAward> $lockedAwards */
            $lockedAwards = PrItemAward::query()
                ->whereIn('id', $awardIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($lockedAwards->count() !== count($awardIds)) {
                throw new InvalidArgumentException('One or more selected item awards could not be found.');
            }

            $lockedAwards->load([
                'prItem',
                'quotationItem',
                'quotation.supplier',
                'quotation.exchange_rate',
                'purchaseRequisition',
            ]);

            // Revalidate every award
            foreach ($lockedAwards as $award) {
                if ($award->purchase_order_id !== null) {
                    throw new InvalidArgumentException("Award #{$award->id} for PR Item #{$award->pr_item_id} has already been assigned to PO #{$award->purchase_order_id}.");
                }

                if (! $award->quotationItem || ! $award->quotationItem->is_available) {
                    throw new InvalidArgumentException("Award #{$award->id} contains an item that is no longer marked as available.");
                }

                if ($award->quotation && ! in_array($award->quotation->status, Quotation::AWARD_ELIGIBLE_STATUSES, true)) {
                    throw new InvalidArgumentException("Award #{$award->id} belongs to a quotation with ineligible status '{$award->quotation->status}'.");
                }
            }

            // Group awards strictly by supplier_id (1 PO = exactly 1 supplier)
            $groupedBySupplier = $lockedAwards->groupBy('supplier_id');
            $createdPos = collect();
            $affectedPrIds = $lockedAwards->pluck('pr_id')->unique()->values()->all();

            foreach ($groupedBySupplier as $supplierId => $supplierAwards) {
                // Ensure all quotations in this supplier group share the same currency
                $currencies = $supplierAwards->map(fn ($a) => $a->quotation?->currency)->filter()->unique();
                if ($currencies->count() > 1) {
                    throw new InvalidArgumentException("Supplier #{$supplierId} awards span multiple currencies ({$currencies->implode(', ')}). A PO must have a single currency.");
                }

                $currency = $currencies->first() ?? 'USD';

                // Resolve snapshot exchange rate
                $exchangeRateId = $supplierAwards->first()?->quotation?->exchange_rate_id;
                if (! $exchangeRateId) {
                    $latestRate = ExchangeRate::where('currency', $currency)
                        ->orderByDesc('valid_from')
                        ->first();
                    $exchangeRateId = $latestRate?->id;
                }

                // Verify quotation uniqueness for 1 Quotation -> max 1 PO invariant
                $quotationIds = $supplierAwards->pluck('quotation_id')->unique()->values()->all();
                $alreadyAssignedQuotations = DB::table('po_quotations')
                    ->whereIn('quotation_id', $quotationIds)
                    ->pluck('quotation_id');

                if ($alreadyAssignedQuotations->isNotEmpty()) {
                    throw new InvalidArgumentException("Quotation #{$alreadyAssignedQuotations->first()} is already assigned to an existing PO.");
                }

                // Determine estimated arrival & notes
                $estimatedArrival = $options['estimated_arrivals'][$supplierId]
                    ?? $options['estimated_arrival']
                    ?? now()->addDays(14)->toDateString();

                $notes = $options['supplier_notes'][$supplierId]
                    ?? $options['notes']
                    ?? null;

                // 1. Create Purchase Order for this supplier
                $po = PurchaseOrder::create([
                    'supplier_id' => $supplierId,
                    'currency' => $currency,
                    'exchange_rate_id' => $exchangeRateId,
                    'po_number' => PurchaseOrder::generatePoNumber(),
                    'status' => 'active',
                    'created_by' => $creator->id,
                    'estimated_arrival' => $estimatedArrival,
                    'notes' => $notes,
                ]);

                // 2. Attach quotations to PO via pivot po_quotations
                $po->quotations()->attach($quotationIds);

                // 3. Create default legacy po_documents
                foreach (['invoice', 'bl', 'packing_list', 'form_e'] as $docType) {
                    PoDocument::create([
                        'po_id' => $po->id,
                        'doc_type' => $docType,
                        'status' => 'pending',
                    ]);
                }

                // 4. Link item awards to this PO
                PrItemAward::whereIn('id', $supplierAwards->pluck('id'))
                    ->update(['purchase_order_id' => $po->id]);

                // 5. Update quotation status to accepted for participating quotations
                Quotation::whereIn('id', $quotationIds)
                    ->update([
                        'status' => Quotation::STATUS_ACCEPTED,
                        'reviewed_at' => now(),
                        'reviewed_by' => $creator->id,
                    ]);

                // 6. Notify supplier user
                $supplierUser = $supplierAwards->first()?->supplier;
                if ($supplierUser) {
                    $this->notifications->send(
                        $supplierUser,
                        'po.issued',
                        "po.issued:{$po->id}",
                        'New PO Issued',
                        "Purchase Order {$po->po_number} has been issued for your awarded items.",
                        route('supplier.purchase-orders.show', $po, absolute: false),
                        'receipt text-primary',
                        [
                            'category' => NotificationCategory::OTHER,
                            'po_id' => $po->id,
                            'po_number' => $po->po_number,
                        ]
                    );
                }

                $createdPos->push($po);
            }

            // Phase 4: Coverage-based PR Completion and item-aware Quotation Rejection
            foreach ($affectedPrIds as $prId) {
                /** @var PurchaseRequisition|null $pr */
                $pr = PurchaseRequisition::with(['items', 'awards'])->find($prId);
                if (! $pr) {
                    continue;
                }

                $totalItemsCount = $pr->items->count();
                $awardedAndPoCount = $pr->awards
                    ->whereNotNull('purchase_order_id')
                    ->count();

                // If all items are awarded and assigned to POs, mark PR completed
                if ($totalItemsCount > 0 && $awardedAndPoCount === $totalItemsCount) {
                    $pr->update(['status' => 'completed']);

                    // Now that all items are awarded and PR is finalized,
                    // reject competing quotations for this PR that have ZERO winning items
                    $participatingQuotationIds = $pr->awards
                        ->whereNotNull('purchase_order_id')
                        ->pluck('quotation_id')
                        ->unique()
                        ->all();

                    Quotation::where('pr_id', $pr->id)
                        ->whereNotIn('id', $participatingQuotationIds)
                        ->whereIn('status', [Quotation::STATUS_SUBMITTED, 'revision_requested'])
                        ->update([
                            'status' => Quotation::STATUS_REJECTED,
                            'reviewed_at' => now(),
                            'reviewed_by' => $creator->id,
                            'reviewer_notes' => 'Awarded to alternative supplier offers.',
                        ]);
                } else {
                    // Items still remain unresolved - ensure PR remains actionable (e.g. bidding)
                    if ($pr->status !== 'bidding') {
                        $pr->update(['status' => 'bidding']);
                    }
                }
            }

            return $createdPos;
        });
    }
}
