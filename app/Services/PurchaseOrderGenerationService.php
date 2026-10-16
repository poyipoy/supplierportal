<?php

namespace App\Services;

use App\Exceptions\PoGenerationDisabledException;
use App\Models\AwardReferencePriceSnapshot;
use App\Models\ExchangeRate;
use App\Models\PoDocument;
use App\Models\PrItem;
use App\Models\PrItemAward;
use App\Models\PrItemAwardEvent;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\User;
use App\Services\Pricing\ReferencePriceResolver;
use App\Support\Money;
use App\Support\NotificationCategory;
use App\Support\Pricing\EvaluationContext;
use App\Support\Procurement\LogisticsTerms;
use App\Support\Procurement\ProcurementFeatures;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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
        // Kill switch (I-20): first statement, before any lock or write, so all
        // three entry points stop and their enclosing transactions roll back.
        if (! ProcurementFeatures::poGenerationEnabled()) {
            throw new PoGenerationDisabledException;
        }

        $awardIds = collect($awards)->map(function ($award) {
            return $award instanceof PrItemAward ? (int) $award->id : (int) $award;
        })->filter(fn ($id) => $id > 0)->values()->all();

        if (empty($awardIds)) {
            throw new InvalidArgumentException(__('purchasing.copy.no_item_awards_selected_for_po_generation'));
        }

        sort($awardIds);

        // Parent identities only; no transaction snapshot before PR serialization.
        $awardReferences = PrItemAward::query()->whereIn('id', $awardIds)->orderBy('id')
            ->get(['id', 'pr_id', 'pr_item_id']);
        if ($awardReferences->count() !== count($awardIds)) {
            throw new InvalidArgumentException(__('purchasing.copy.one_or_more_selected_item_awards_could_not_be_found'));
        }

        return DB::transaction(function () use ($awardIds, $awardReferences, $creator, $options) {
            $prIds = $awardReferences->pluck('pr_id')->unique()->sort()->values()->all();
            /** @var Collection<int, PurchaseRequisition> $lockedRequisitions */
            $lockedRequisitions = PurchaseRequisition::query()
                ->whereIn('id', $prIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

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
                throw new InvalidArgumentException(__('purchasing.copy.one_or_more_selected_item_awards_changed_during_po_generation'));
            }

            $quotationIds = $awardReferences->pluck('quotation_id')->unique()->sort()->values()->all();
            $lockedQuotations = Quotation::with(['supplier', 'exchange_rate'])
                ->whereIn('id', $quotationIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()->keyBy('id');

            $quotationItemIds = $awardReferences->pluck('quotation_item_id')->unique()->sort()->values()->all();
            $lockedQuotationItems = QuotationItem::query()
                ->whereIn('id', $quotationItemIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()->keyBy('id');

            // Lock all awards last in the shared deterministic finalization order.
            /** @var Collection<int, PrItemAward> $lockedAwards */
            $lockedAwards = PrItemAward::query()
                ->whereIn('id', $awardIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($lockedAwards->count() !== count($awardIds)) {
                throw new InvalidArgumentException(__('purchasing.copy.one_or_more_selected_item_awards_could_not_be_found'));
            }

            $lockedAwards->load(['prItem', 'purchaseRequisition']);
            foreach ($lockedAwards as $award) {
                $award->setRelation('quotation', $lockedQuotations->get($award->quotation_id));
                $award->setRelation('quotationItem', $lockedQuotationItems->get($award->quotation_item_id));
            }
            if (($options['require_single_supplier'] ?? false)
                && $lockedAwards->pluck('supplier_id')->unique()->count() !== 1) {
                throw new InvalidArgumentException(__('purchasing.copy.select_awards_from_exactly_one_supplier_for_consolidation'));
            }

            // Revalidate every award
            foreach ($lockedAwards as $award) {
                if ($award->purchase_order_id !== null) {
                    throw new InvalidArgumentException(__('purchasing.guard_copy.award_assigned', ['award' => $award->id, 'item' => $award->pr_item_id, 'po' => $award->purchase_order_id]));
                }

                if (! $award->quotationItem || ! $award->quotationItem->is_available) {
                    throw new InvalidArgumentException(__('purchasing.guard_copy.award_unavailable', ['award' => $award->id]));
                }

                if (! $award->quotation || ! in_array($award->quotation->status, Quotation::AWARD_ELIGIBLE_STATUSES, true)) {
                    throw new InvalidArgumentException(__('purchasing.guard_copy.award_ineligible', ['award' => $award->id, 'status' => $award->quotation?->status]));
                }
            }

            $writePath = ProcurementFeatures::orderedQuantityWriteEnabled();
            if ($writePath) {
                $this->assertOrderable($lockedAwards, $options['selections'] ?? [], $creator);
            } else {
                $this->assertLegacyOrderable($lockedAwards);
            }

            // Group awards strictly by supplier_id (1 PO = exactly 1 supplier)
            $groupedBySupplier = $lockedAwards->groupBy('supplier_id');
            $createdPos = collect();
            $affectedPrIds = $lockedAwards->pluck('pr_id')->unique()->values()->all();

            foreach ($groupedBySupplier as $supplierId => $supplierAwards) {
                // Ensure all quotations in this supplier group share the same currency
                $currencies = $supplierAwards->map(fn ($a) => $a->quotation?->currency)->filter()->unique();
                if ($currencies->count() > 1) {
                    throw new InvalidArgumentException(__('purchasing.guard_copy.multiple_currencies', ['supplier' => $supplierId, 'currencies' => $currencies->implode(', ')]));
                }

                $currency = $currencies->first() ?? 'USD';

                // Q1 / I-11: one PO carries one Port of Discharge + Incoterms.
                // Mixed PR values are rejected (never picked, never split).
                [$portOfDischarge, $incoterm] = $this->uniformLogistics(
                    $supplierAwards->pluck('pr_id')->unique()->sort()->values(),
                    $lockedRequisitions
                );

                // Resolve snapshot exchange rate
                $exchangeRateId = $supplierAwards->first()?->quotation?->exchange_rate_id;
                if (! $exchangeRateId) {
                    $latestRate = ExchangeRate::latestRate($currency);
                    $exchangeRateId = $latestRate?->id;
                }

                // Verify quotation uniqueness for 1 Quotation -> max 1 PO invariant
                $quotationIds = $supplierAwards->pluck('quotation_id')->unique()->values()->all();
                $alreadyAssignedQuotations = DB::table('po_quotations')
                    ->whereIn('quotation_id', $quotationIds)
                    ->pluck('quotation_id');

                if ($alreadyAssignedQuotations->isNotEmpty()) {
                    throw new InvalidArgumentException(__('purchasing.guard_copy.quotation_assigned', ['quotation' => $alreadyAssignedQuotations->first()]));
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
                    'port_of_discharge' => $portOfDischarge,
                    'incoterm' => $incoterm,
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

                // 4b. M04-W: freeze ordered quantity, KG, price and amount once,
                // in the transaction that creates the PO (I-1, I-17).
                if ($writePath) {
                    foreach ($supplierAwards as $award) {
                        $this->freezeOrderedValues($award, $po, $creator);
                    }
                }

                // 4c. M06B (§12.2, I-19, I-22): one immutable reference-price
                // snapshot per award, in this transaction and in either
                // write-path state; a miss is recorded as not_available.
                foreach ($supplierAwards as $award) {
                    $this->snapshotReferencePrice($award, $po);
                }

                // 5. Update quotation status to accepted for participating quotations
                $participatingQuotations = Quotation::with('items')->whereIn('id', $quotationIds)->get();
                foreach ($participatingQuotations as $quotation) {
                    $totalItemsCount = $quotation->items->count();
                    $awardedItemsInThisPo = $supplierAwards->where('quotation_id', $quotation->id)->count();

                    $notes = $quotation->reviewer_notes;
                    if ($totalItemsCount > $awardedItemsInThisPo) {
                        $partialNote = "Partial award: {$awardedItemsInThisPo} of {$totalItemsCount} items awarded to PO {$po->po_number}.";
                        $notes = $notes ? "{$notes} | {$partialNote}" : $partialNote;
                    }

                    $quotation->update([
                        'status' => Quotation::STATUS_ACCEPTED,
                        'reviewed_at' => now(),
                        'reviewed_by' => $creator->id,
                        'reviewer_notes' => $notes,
                    ]);
                }

                // 6. Notify supplier user
                $supplierUser = $supplierAwards->first()?->supplier;
                if ($supplierUser) {
                    $this->notifications->send(
                        $supplierUser,
                        'po.issued',
                        "po.issued:{$po->id}",
                        'purchasing.copy.new_po_issued',
                        'purchasing.notify.po_issued_body',
                        route('supplier.purchase-orders.show', $po, absolute: false),
                        'receipt text-primary',
                        [
                            'category' => NotificationCategory::OTHER,
                            'po_id' => $po->id,
                            'po_number' => $po->po_number,
                        ],
                        ['po' => $po->po_number],
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
                            'reviewer_notes' => __('purchasing.copy.awarded_to_alternative_supplier_offers'),
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

    /**
     * Mission 04-W (I-1, I-4): apply any submitted selection under the award
     * locks, then require an ordered quantity within 1..offered and, for a
     * different specification, an acceptance whose hash matches the current
     * offer. Every failing line is reported, keyed to its PR item.
     *
     * @param  Collection<int, PrItemAward>  $awards
     * @param  array<int, array{order_qty?: mixed, accept_spec?: mixed}>  $selections  keyed by pr_item_id
     *
     * @throws ValidationException
     */
    private function assertOrderable(Collection $awards, array $selections, User $creator): void
    {
        $awardService = app(PrItemAwardService::class);
        $errors = [];

        foreach ($awards as $award) {
            $quotationItem = $award->quotationItem;
            $quotationItem->setRelation('prItem', $award->prItem);
            $awardService->applySelection($award, $quotationItem, $selections[$award->pr_item_id] ?? [], $creator);

            $prItemId = (int) $award->pr_item_id;
            $material = $award->prItem?->material_name ?? '#'.$prItemId;
            $offered = (int) $quotationItem->fulfillment_quantity;
            if ($award->ordered_qty === null) {
                $errors["order_qty.{$prItemId}"] = __('procurement.ordered_quantity.order_qty_required', ['material' => $material, 'max' => $offered]);
            } elseif ($award->ordered_qty < 1 || $award->ordered_qty > $offered) {
                $errors["order_qty.{$prItemId}"] = __('procurement.ordered_quantity.order_qty_range', ['material' => $material, 'max' => $offered]);
            }

            if ($quotationItem->availability_comparison['specification']['code'] === 'different'
                && ! $award->isSpecAcceptanceCurrent($quotationItem->specificationSnapshot())) {
                $errors["accept_spec.{$prItemId}"] = __($award->spec_accepted_at === null
                    ? 'procurement.ordered_quantity.spec_acceptance_required'
                    : 'procurement.ordered_quantity.spec_acceptance_stale', ['material' => $material]);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * The evaluation date is the generation business date (EvaluationContext);
     * the resolver never blocks generation.
     */
    private function snapshotReferencePrice(PrItemAward $award, PurchaseOrder $po): void
    {
        $line = $award->quotationItem;
        $line->setRelation('prItem', $award->prItem);
        $result = app(ReferencePriceResolver::class)->resolve($line, EvaluationContext::current(), (string) $po->currency);

        AwardReferencePriceSnapshot::create([
            'pr_item_award_id' => $award->id,
            'purchase_order_id' => $po->id,
            'material_reference_price_id' => $result->reference?->id,
            'status' => $result->isMatched() ? AwardReferencePriceSnapshot::STATUS_MATCHED : AwardReferencePriceSnapshot::STATUS_NOT_AVAILABLE,
            'reason_code' => $result->reasonCode,
            'evaluation_date' => $result->evaluationDate,
            'dimension_field' => $result->dimensionField,
            'dimension_value' => $result->dimensionValue,
            'reference_currency' => $result->reference?->currency,
            'reference_price_per_kg' => $result->reference === null ? null : (string) $result->reference->price_per_kg,
            'po_currency' => $result->targetCurrency,
            'reference_rate_id' => $result->referenceRateId,
            'po_rate_id' => $result->targetRateId,
            'converted_reference_price_per_kg' => $result->convertedReferencePricePerKg,
            'offer_price_per_kg' => $result->offerPricePerKg,
            'diff_amount_per_kg' => $result->diffAmountPerKg,
            'diff_percent' => $result->diffPercent,
        ]);
    }

    /**
     * Frozen values are written once and never recomputed (I-17). KG and
     * amount follow the resolver's "selected" formula, rounded to 4 dp.
     */
    private function freezeOrderedValues(PrItemAward $award, PurchaseOrder $po, User $creator): void
    {
        $quotationItem = $award->quotationItem;
        $weightPerUnit = Money::normalize($quotationItem->offered_weight_per_unit ?? $award->prItem?->weight_needed, 4);
        $totalWeight = Money::multiply($award->ordered_qty, $weightPerUnit, 4);
        $pricePerKg = Money::normalize($quotationItem->price_per_kg, 4);
        $amount = Money::multiply($totalWeight, $pricePerKg, 4);

        $award->forceFill([
            'purchase_order_id' => $po->id,
            'ordered_weight_per_unit' => $weightPerUnit,
            'ordered_total_weight_kg' => $totalWeight,
            'ordered_price_per_kg' => $pricePerKg,
            'ordered_amount' => $amount,
        ])->save();

        PrItemAwardEvent::create([
            'pr_item_award_id' => $award->id,
            'pr_id' => $award->pr_id,
            'pr_item_id' => $award->pr_item_id,
            'quotation_item_id' => $award->quotation_item_id,
            'purchase_order_id' => $po->id,
            'event' => PrItemAwardEvent::ORDERED_VALUES_FROZEN,
            'payload' => [
                'ordered_qty' => $award->ordered_qty,
                'ordered_weight_per_unit' => $weightPerUnit,
                'ordered_total_weight_kg' => $totalWeight,
                'ordered_price_per_kg' => $pricePerKg,
                'ordered_amount' => $amount,
                'requested_qty' => $award->prItem?->quantity_value,
                'offered_qty' => $quotationItem->fulfillment_quantity,
            ],
            'actor_id' => $creator->id,
        ]);
    }

    /**
     * Mission 04-R fail-closed legacy guard (I-21). Without the ordered-quantity
     * write path, a PO line is the full offer. The path cannot freeze a
     * Purchasing selection or price a non-offer quantity, so it refuses instead
     * of silently ordering the offer:
     *  (b) any unassigned award carrying a selection (ordered qty or spec
     *      acceptance), whatever the quantities;
     *  (a) an unselected offer whose quantity differs from the PR quantity.
     *
     * @param  Collection<int, PrItemAward>  $awards
     *
     * @throws InvalidArgumentException
     */
    private function assertLegacyOrderable(Collection $awards): void
    {
        foreach ($awards as $award) {
            $context = ' ['.($award->purchaseRequisition?->pr_number ?? '#'.$award->pr_id).', award '.$award->id.']';

            if ($award->purchase_order_id === null && $award->hasPurchasingSelection()) {
                throw new InvalidArgumentException(__('procurement.ordered_quantity.selection_unfinalizable').$context);
            }

            $offered = $award->quotationItem?->fulfillment_quantity;
            $requested = $award->prItem?->quantity_value;
            if ($offered !== null && $requested !== null && (int) $offered !== (int) $requested) {
                throw new InvalidArgumentException(__('procurement.ordered_quantity.selection_required').$context);
            }
        }
    }

    /**
     * The single (port, incoterm) pair shared by every participating PR, read
     * from the rows locked above. NULL is a distinct value: two legacy PRs
     * without logistics still consolidate, NULL never mixes with a value.
     *
     * @param  Collection<int, int>  $prIds
     * @param  Collection<int, PurchaseRequisition>  $requisitions
     * @return array{0: ?string, 1: ?string}
     *
     * @throws InvalidArgumentException
     */
    private function uniformLogistics(Collection $prIds, Collection $requisitions): array
    {
        $terms = $prIds->map(function (int $prId) use ($requisitions): array {
            $pr = $requisitions->get($prId);

            return [
                'pr' => $pr?->pr_number ?? '#'.$prId,
                'port' => $pr?->port_of_discharge,
                'incoterm' => $pr?->incoterm,
            ];
        });

        $distinct = $terms->unique(fn (array $term) => json_encode([$term['port'], $term['incoterm']]));

        if ($distinct->count() > 1) {
            throw new InvalidArgumentException(__('procurement.logistics.mixed_consolidation', [
                'details' => $terms
                    ->map(fn (array $term) => $term['pr'].' ('.LogisticsTerms::describe($term['port'], $term['incoterm']).')')
                    ->implode(' ≠ '),
            ]));
        }

        $term = $terms->first();

        return [$term['port'] ?? null, $term['incoterm'] ?? null];
    }
}
