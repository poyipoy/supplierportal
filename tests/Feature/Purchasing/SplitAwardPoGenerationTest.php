<?php

namespace Tests\Feature\Purchasing;

use App\Models\ExchangeRate;
use App\Models\Period;
use App\Models\PrItem;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\User;
use App\Services\PrItemAwardService;
use App\Services\PurchaseOrderGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SplitAwardPoGenerationTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasing;

    private User $supplierA;

    private User $supplierB;

    private Period $period;

    private ExchangeRate $rate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $this->supplierA = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $this->supplierB = User::factory()->create(['role' => 'supplier', 'is_active' => true]);

        $this->period = Period::create([
            'name' => '2026-09',
            'year' => 2026,
            'status' => 'open',
            'created_by' => $this->purchasing->id,
        ]);

        $this->rate = ExchangeRate::create([
            'currency' => 'USD',
            'rate_to_idr' => 16000,
            'valid_from' => now()->subDay(),
            'created_by' => $this->purchasing->id,
        ]);
    }

    public function test_split_award_annotates_quotation_notes_and_isolates_item_award_truth(): void
    {
        $pr = PurchaseRequisition::create([
            'period_id' => $this->period->id,
            'pr_number' => 'REQ/09/2026/050',
            'status' => 'submitted',
            'created_by' => $this->purchasing->id,
        ]);

        $item1 = PrItem::create([
            'pr_id' => $pr->id,
            'material_name' => 'Item Alpha',
            'shape' => 'round',
            'd_outer' => 50,
            'length' => 100,
            'weight_needed' => 10,
            'quantity' => 1,
        ]);

        $item2 = PrItem::create([
            'pr_id' => $pr->id,
            'material_name' => 'Item Beta',
            'shape' => 'round',
            'd_outer' => 60,
            'length' => 120,
            'weight_needed' => 20,
            'quantity' => 1,
        ]);

        // Supplier A quotes on both items
        $qA = Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $this->supplierA->id,
            'exchange_rate_id' => $this->rate->id,
            'currency' => 'USD',
            'status' => 'submitted',
        ]);
        $qItemA1 = QuotationItem::create([
            'quotation_id' => $qA->id,
            'pr_item_id' => $item1->id,
            'price_per_kg' => 5.0,
            'amount' => 50.0,
            'available_qty' => 1,
        ]);
        $qItemA2 = QuotationItem::create([
            'quotation_id' => $qA->id,
            'pr_item_id' => $item2->id,
            'price_per_kg' => 8.0,
            'amount' => 160.0,
            'available_qty' => 1,
        ]);

        // Supplier B quotes on both items
        $qB = Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $this->supplierB->id,
            'exchange_rate_id' => $this->rate->id,
            'currency' => 'USD',
            'status' => 'submitted',
        ]);
        $qItemB1 = QuotationItem::create([
            'quotation_id' => $qB->id,
            'pr_item_id' => $item1->id,
            'price_per_kg' => 6.0,
            'amount' => 60.0,
            'available_qty' => 1,
        ]);
        $qItemB2 = QuotationItem::create([
            'quotation_id' => $qB->id,
            'pr_item_id' => $item2->id,
            'price_per_kg' => 7.0,
            'amount' => 140.0,
            'available_qty' => 1,
        ]);

        // Award Item 1 to Supplier A, Item 2 to Supplier B
        $awardService = app(PrItemAwardService::class);
        $awards = $awardService->awardBatch($pr, [
            $item1->id => $qItemA1->id,
            $item2->id => $qItemB2->id,
        ], $this->purchasing);

        $poService = app(PurchaseOrderGenerationService::class);
        $pos = $poService->generateFromAwards($awards, $this->purchasing);

        $this->assertCount(2, $pos);
        $poA = $pos->firstWhere('supplier_id', $this->supplierA->id);

        $qAFresh = $qA->fresh();
        $this->assertSame(Quotation::STATUS_ACCEPTED, $qAFresh->status);
        $this->assertStringContainsString('Partial award: 1 of 2 items awarded', $qAFresh->reviewer_notes);

        // Verify supplier quotation detail view displays item-level award status
        $response = $this->actingAs($this->supplierA)->get(route('supplier.quotations.show', $qA));
        $response->assertOk();
        $response->assertSee('Partial award');
        $response->assertSee('Awarded ('.$poA->po_number.')');
        $response->assertSee('Not Awarded');
    }
}
