<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\Period;
use App\Models\PrItemAward;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Pricing\PurchaseHistoryPriceService;
use App\Support\Pricing\EvaluationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gate 2 §3-C / PUR-07: Vs Best "historical best" follows the authoritative purchase-history
 * rules (PurchaseHistoryPriceService) per material and shape, and PUR-05 filters by material.
 */
class PriceComparisonVsBestEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasing;

    private User $supplier;

    private Period $period;

    private ExchangeRate $rate;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-28 10:00:00');
        $this->purchasing = User::factory()->create(['role' => 'purchasing']);
        $this->supplier = User::factory()->create(['role' => 'supplier']);
        Supplier::create(['user_id' => $this->supplier->id, 'company_name' => 'PT History']);
        $this->period = Period::create(['name' => 'Oct', 'month' => 10, 'year' => 2026, 'status' => 'open', 'created_by' => $this->purchasing->id]);
        $this->rate = ExchangeRate::create(['currency' => 'USD', 'rate_to_idr' => 16000, 'valid_from' => '2025-01-01', 'created_by' => $this->purchasing->id]);

        // Eligible: completed legacy PO without awards, Flat at 9.
        $this->historyLine('Flat', 9, 'completed');
        // Excluded: a cancelled PO at 5.
        $this->historyLine('Flat', 5, 'cancelled');
        // Eligible: awarded line frozen at 6.5 (offer 7); an unawarded line at 4 on the same PO is excluded.
        [$po, $quotation, $awardedItem] = $this->historyLine('Flat', 7, 'active', awarded: true, frozenPrice: '6.5000');
        $pr = PurchaseRequisition::create(['period_id' => $this->period->id, 'created_by' => $this->purchasing->id, 'pr_number' => 'REQ/UNAWARDED', 'status' => 'completed']);
        $item = $pr->items()->create($this->itemAttributes('Flat'));
        $unawardedQuote = Quotation::create(['pr_id' => $pr->id, 'supplier_id' => $this->supplier->id, 'exchange_rate_id' => $this->rate->id, 'currency' => 'USD', 'status' => 'accepted', 'submitted_at' => '2026-02-01 09:00:00']);
        $unawardedQuote->items()->create(['pr_item_id' => $item->id, 'is_available' => true, 'price_per_kg' => 4, 'amount' => 40]);
        $po->quotations()->attach($unawardedQuote->id);
        // Round history at 3 must never become the Flat best.
        $this->historyLine('Round', 3, 'completed');

        // Current offers for both shapes.
        foreach (['Flat', 'Round'] as $shape) {
            $currentPr = PurchaseRequisition::create(['period_id' => $this->period->id, 'created_by' => $this->purchasing->id, 'pr_number' => 'REQ/CUR/'.$shape, 'status' => 'bidding']);
            $currentItem = $currentPr->items()->create($this->itemAttributes($shape));
            $currentQuote = Quotation::create(['pr_id' => $currentPr->id, 'supplier_id' => $this->supplier->id, 'exchange_rate_id' => $this->rate->id, 'currency' => 'USD', 'status' => 'submitted', 'submitted_at' => '2026-10-01 09:00:00']);
            $currentQuote->items()->create(['pr_item_id' => $currentItem->id, 'is_available' => true, 'price_per_kg' => 10, 'amount' => 100]);
        }
    }

    public function test_historical_best_uses_only_eligible_lines_per_material_and_shape(): void
    {
        $rows = collect($this->vsBest()->json('data'))->filter(fn (array $row) => str_contains($row['material_display'], 'REQ/CUR/'));
        $this->assertCount(2, $rows);
        $byShape = $rows->mapWithKeys(fn (array $row) => [str_contains($row['material_display'], 'REQ/CUR/Flat') ? 'Flat' : 'Round' => (float) $row['best_price_idr']]);

        // Oracle: the authoritative history service under the same currency and evaluation date.
        $service = app(PurchaseHistoryPriceService::class);
        $context = EvaluationContext::current();
        foreach (['Flat', 'Round'] as $shape) {
            $expected = $service->summarize(['material_name' => 'SCM440', 'shape' => $shape], 'USD', $context)['total']['best'];
            $this->assertEqualsWithDelta((float) $expected, $byShape[$shape], 0.00001, $shape);
        }
        $this->assertEqualsWithDelta(6.5, $byShape['Flat'], 0.00001, 'Frozen award price wins; cancelled 5, unawarded 4 and Round 3 never do.');
        $this->assertEqualsWithDelta(3.0, $byShape['Round'], 0.00001);
    }

    public function test_material_filter_restricts_rows_to_the_material_and_shape(): void
    {
        $all = $this->vsBest()->json('recordsFiltered');
        $flat = $this->vsBest(['material_name' => 'SCM440', 'shape' => 'Flat']);
        $this->assertGreaterThan($flat->json('recordsFiltered'), $all);
        $this->assertNotEmpty($flat->json('data'));
        foreach ($flat->json('data') as $row) {
            $this->assertStringNotContainsString('REQ/CUR/Round', $row['material_display']);
        }
        $this->assertSame($flat->json('recordsFiltered'), $flat->json('summary.total_rows'), 'Summary follows the material filter.');
        $this->assertSame(0, $this->vsBest(['material_name' => 'Unknown Material'])->json('recordsFiltered'));
    }

    private function vsBest(array $filters = [])
    {
        return $this->actingAs($this->purchasing)
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->getJson(route('purchasing.comparison.vs-best.data').'?'.http_build_query(['draw' => 1, 'start' => 0, 'length' => 50, 'currency' => 'USD', 'search' => ['value' => '', 'regex' => 'false'], ...$filters]))
            ->assertOk();
    }

    private function itemAttributes(string $shape): array
    {
        return $shape === 'Flat'
            ? ['material_name' => 'SCM440', 'quantity' => 2, 'shape' => 'Flat', 'thickness' => 10, 'width' => 100, 'length' => 200, 'weight_needed' => 5]
            : ['material_name' => 'SCM440', 'quantity' => 2, 'shape' => 'Round', 'd_outer' => 50, 'length' => 200, 'weight_needed' => 5];
    }

    private function historyLine(string $shape, float $price, string $poStatus, bool $awarded = false, ?string $frozenPrice = null): array
    {
        $this->sequence++;
        $pr = PurchaseRequisition::create(['period_id' => $this->period->id, 'created_by' => $this->purchasing->id, 'pr_number' => 'REQ/HIST/'.$this->sequence, 'status' => 'completed']);
        $item = $pr->items()->create($this->itemAttributes($shape));
        $quotation = Quotation::create(['pr_id' => $pr->id, 'supplier_id' => $this->supplier->id, 'exchange_rate_id' => $this->rate->id, 'currency' => 'USD', 'status' => 'accepted', 'submitted_at' => '2026-02-01 09:00:00']);
        $quotationItem = $quotation->items()->create(['pr_item_id' => $item->id, 'is_available' => true, 'price_per_kg' => $price, 'amount' => $price * 10]);
        $po = PurchaseOrder::create(['supplier_id' => $this->supplier->id, 'currency' => 'USD', 'exchange_rate_id' => $this->rate->id, 'po_number' => 'PO/HIST/'.$this->sequence, 'status' => $poStatus, 'created_by' => $this->purchasing->id]);
        $po->quotations()->attach($quotation->id);
        if ($awarded) {
            PrItemAward::create(['pr_id' => $pr->id, 'pr_item_id' => $item->id, 'quotation_id' => $quotation->id, 'quotation_item_id' => $quotationItem->id, 'supplier_id' => $this->supplier->id, 'purchase_order_id' => $po->id, 'awarded_by' => $this->purchasing->id, 'awarded_at' => now()])
                ->forceFill(['ordered_qty' => 2, 'ordered_total_weight_kg' => '10.0000', 'ordered_price_per_kg' => $frozenPrice, 'ordered_amount' => bcmul($frozenPrice, '10', 4)])
                ->save();
        }

        return [$po, $quotation, $quotationItem];
    }
}
