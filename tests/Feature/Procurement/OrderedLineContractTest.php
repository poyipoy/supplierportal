<?php

namespace Tests\Feature\Procurement;

use App\Models\PrItem;
use App\Models\PrItemAward;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Services\PrItemAwardService;
use App\Services\PurchaseOrderGenerationService;
use App\Support\Procurement\OrderedLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

/**
 * Gate 1 section 11.2: PurchaseOrder::orderedLines() is the single ordered
 * qty/KG/amount resolver with the precedence frozen -> selected -> legacy award
 * -> legacy PO. Mission 01 delivers the contract only; no consumer reads it yet.
 *
 * Fixture: PR 10 pcs x 3 kg; offer 15 pcs x 3.2 kg at 2.5/kg (surplus offer).
 */
class OrderedLineContractTest extends TestCase
{
    use ProcurementFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurementActors();
    }

    public function test_ordered_line_is_a_readonly_value_object(): void
    {
        $this->assertTrue((new ReflectionClass(OrderedLine::class))->isReadOnly());
        $this->assertSame(
            ['frozen', 'selected', 'legacy_award', 'legacy_po'],
            [OrderedLine::SOURCE_FROZEN, OrderedLine::SOURCE_SELECTED, OrderedLine::SOURCE_LEGACY_AWARD, OrderedLine::SOURCE_LEGACY_PO],
        );
    }

    public function test_legacy_award_lines_reproduce_todays_offer_based_values_including_surplus(): void
    {
        $po = $this->surplusAwardPurchaseOrder();

        $line = $po->orderedLines()->sole();

        $this->assertSame(OrderedLine::SOURCE_LEGACY_AWARD, $line->source);
        $this->assertSame($po->awards->sole()->id, $line->award?->id);
        $this->assertSame(15, $line->qty);
        $this->assertSame('48.0000', $line->kg);
        $this->assertSame('2.5000', $line->pricePerKg);
        $this->assertSame('120.0000', $line->amount);
        $this->assertCharacterizesExistingAccessors($po->fresh());
    }

    public function test_legacy_po_without_awards_reproduces_todays_quotation_wide_values(): void
    {
        $pr = $this->createRequisition([[], []]);
        $quotation = $this->createQuotation($pr, [
            ['available_qty' => 15, 'offered_weight_per_unit' => 3.2],
            // Pre-offer legacy row: no offered quantity or weight, stored requested amount.
            ['available_qty' => null, 'offered_weight_per_unit' => null, 'amount' => 75.0],
        ]);
        $po = $this->legacyPurchaseOrder($quotation);

        $lines = $po->orderedLines();

        $this->assertSame([OrderedLine::SOURCE_LEGACY_PO, OrderedLine::SOURCE_LEGACY_PO], $lines->pluck('source')->all());
        $this->assertNull($lines[0]->award);
        $this->assertSame([15, 10], $lines->pluck('qty')->all());
        $this->assertSame(['48.0000', '30.0000'], $lines->pluck('kg')->all());
        $this->assertSame(['120.0000', '75.0000'], $lines->pluck('amount')->all());
        $this->assertCharacterizesExistingAccessors($po->fresh());
    }

    public function test_selected_quantity_without_frozen_values_derives_kg_and_amount_from_the_offer(): void
    {
        $po = $this->surplusAwardPurchaseOrder();
        $po->awards->sole()->forceFill(['ordered_qty' => 12])->save();

        $line = $po->fresh()->orderedLines()->sole();

        $this->assertSame(OrderedLine::SOURCE_SELECTED, $line->source);
        $this->assertSame(12, $line->qty);
        $this->assertSame('38.4000', $line->kg);
        $this->assertSame('96.0000', $line->amount);
    }

    public function test_selected_quantity_falls_back_to_the_pr_weight_per_unit_when_the_offer_has_none(): void
    {
        $pr = $this->createRequisition();
        $quotation = $this->createQuotation($pr, [['available_qty' => 15, 'offered_weight_per_unit' => null, 'amount' => 112.5]]);
        $po = $this->generate($pr, $quotation);
        $po->awards->sole()->forceFill(['ordered_qty' => 12])->save();

        $line = $po->fresh()->orderedLines()->sole();

        $this->assertSame(OrderedLine::SOURCE_SELECTED, $line->source);
        $this->assertSame('36.0000', $line->kg);
        $this->assertSame('90.0000', $line->amount);
    }

    public function test_frozen_values_take_precedence_and_ignore_later_offer_changes(): void
    {
        $po = $this->surplusAwardPurchaseOrder();
        $po->awards->sole()->forceFill([
            'ordered_qty' => 12,
            'ordered_weight_per_unit' => '3.2000',
            'ordered_total_weight_kg' => '38.4000',
            'ordered_price_per_kg' => '2.5000',
            'ordered_amount' => '96.0000',
        ])->save();
        $po->awards->sole()->quotationItem->forceFill(['price_per_kg' => 9.99, 'amount' => 999, 'available_qty' => 99])->save();

        $line = $po->fresh()->orderedLines()->sole();

        $this->assertSame(OrderedLine::SOURCE_FROZEN, $line->source);
        $this->assertSame(12, $line->qty);
        $this->assertSame('38.4000', $line->kg);
        $this->assertSame('2.5000', $line->pricePerKg);
        $this->assertSame('96.0000', $line->amount);
    }

    public function test_each_line_resolves_its_own_source_on_a_mixed_purchase_order(): void
    {
        $pr = $this->createRequisition([[], []]);
        $quotation = $this->createQuotation($pr, [
            ['available_qty' => 15, 'offered_weight_per_unit' => 3.2],
            ['available_qty' => 10, 'offered_weight_per_unit' => 3.0],
        ]);
        $po = $this->generate($pr, $quotation);
        $first = $po->awards->firstWhere('pr_item_id', $pr->items[0]->id);
        $first->forceFill(['ordered_qty' => 12, 'ordered_total_weight_kg' => '38.4000', 'ordered_price_per_kg' => '2.5000', 'ordered_amount' => '96.0000'])->save();

        $lines = $po->fresh()->orderedLines()->keyBy(fn (OrderedLine $line) => $line->quotationItem->pr_item_id);

        $this->assertSame(OrderedLine::SOURCE_FROZEN, $lines[$pr->items[0]->id]->source);
        $this->assertSame(OrderedLine::SOURCE_LEGACY_AWARD, $lines[$pr->items[1]->id]->source);
        $this->assertSame('75.0000', $lines[$pr->items[1]->id]->amount);
    }

    public function test_existing_consumers_read_the_resolver(): void
    {
        $po = $this->surplusAwardPurchaseOrder();
        $po->awards->sole()->forceFill(['ordered_qty' => 12])->save();
        $po = $po->fresh();

        // Mission 04-R moved these consumers onto orderedLines() (I-15).
        $this->assertSame(12, $po->total_ordered_quantity);
        $this->assertSame(38.4, $po->total_ordered_weight);
        $this->assertSame(12.0, $po->itemFulfillmentStatus($po->awards->sole()->quotation_item_id)['ordered']);
    }

    public function test_award_acceptance_helpers(): void
    {
        $award = $this->surplusAwardPurchaseOrder()->awards->sole();
        $snapshot = ['requested' => ['thickness' => '100'], 'offered' => ['thickness' => '105']];

        $this->assertFalse($award->hasFrozenOrderedValues());
        $this->assertFalse($award->hasPurchasingSelection());
        $this->assertSame(64, strlen(PrItemAward::specAcceptanceHash($snapshot)));
        $this->assertSame(
            PrItemAward::specAcceptanceHash($snapshot),
            PrItemAward::specAcceptanceHash(['offered' => ['thickness' => '105'], 'requested' => ['thickness' => '100']]),
        );
        $this->assertFalse($award->isSpecAcceptanceCurrent($snapshot));

        $award->forceFill([
            'spec_accepted_at' => now(),
            'spec_acceptance_snapshot' => $snapshot,
            'spec_acceptance_hash' => PrItemAward::specAcceptanceHash($snapshot),
        ]);
        $this->assertTrue($award->hasPurchasingSelection());
        $this->assertTrue($award->isSpecAcceptanceCurrent($snapshot));
        $this->assertFalse($award->isSpecAcceptanceCurrent(['requested' => ['thickness' => '100'], 'offered' => ['thickness' => '106']]));

        $award->forceFill(['spec_accepted_at' => null, 'ordered_qty' => 12]);
        $this->assertTrue($award->hasPurchasingSelection());
        $award->forceFill(['ordered_amount' => '96.0000']);
        $this->assertTrue($award->hasFrozenOrderedValues());
    }

    private function surplusAwardPurchaseOrder(): PurchaseOrder
    {
        $pr = $this->createRequisition();
        $quotation = $this->createQuotation($pr, [['available_qty' => 15, 'offered_weight_per_unit' => 3.2]]);

        return $this->generate($pr, $quotation);
    }

    /**
     * Builds the legacy PO these contracts characterize. Mission 04-R refuses
     * to generate an unselected surplus/shortage (guard a), so the PR quantity
     * is matched to the offer for generation and restored afterwards, which
     * is exactly the shape of a PO that already exists before the release.
     */
    private function generate(PurchaseRequisition $pr, Quotation $quotation): PurchaseOrder
    {
        $selections = [];
        $requested = [];
        foreach ($quotation->items as $item) {
            $selections[$item->pr_item_id] = $item->id;
            $requested[$item->pr_item_id] = $item->prItem->quantity;
            if ($item->available_qty !== null) {
                PrItem::query()->whereKey($item->pr_item_id)->update(['quantity' => $item->available_qty]);
            }
        }
        $awards = app(PrItemAwardService::class)->awardBatch($pr, $selections, $this->purchasing);
        $po = app(PurchaseOrderGenerationService::class)->generateFromAwards($awards, $this->purchasing)->sole();

        foreach ($requested as $prItemId => $quantity) {
            PrItem::query()->whereKey($prItemId)->update(['quantity' => $quantity]);
        }

        return $po->fresh();
    }

    /**
     * A pre-award PO: quotation attached through the pivot, no award rows.
     */
    private function legacyPurchaseOrder(Quotation $quotation): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'supplier_id' => $this->supplier->id,
            'currency' => 'USD',
            'exchange_rate_id' => $this->usdRate->id,
            'po_number' => 'PO/10/2026/'.uniqid(),
            'status' => 'active',
            'created_by' => $this->purchasing->id,
        ]);
        $po->quotations()->attach($quotation->id);

        return $po->fresh();
    }

    private function assertCharacterizesExistingAccessors(PurchaseOrder $po): void
    {
        $lines = $po->orderedLines();

        $this->assertSame($po->total_ordered_quantity, $lines->sum('qty'));
        $this->assertEqualsWithDelta($po->total_ordered_weight, $lines->sum(fn (OrderedLine $line) => (float) $line->kg), 0.00001);
        $this->assertEqualsWithDelta(
            $po->commercialQuotationItems()->sum('resolved_amount'),
            $lines->sum(fn (OrderedLine $line) => (float) $line->amount),
            0.00001,
        );
        foreach ($lines as $line) {
            $this->assertSame((int) $po->itemFulfillmentStatus($line->quotationItem->id)['ordered'], $line->qty);
        }
    }
}
