<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\Period;
use App\Models\PrItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PriceComparisonItemAwardTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasing;

    private User $supplierA;

    private User $supplierB;

    private ExchangeRate $exchangeRate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $this->supplierA = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $this->supplierB = User::factory()->create(['role' => 'supplier', 'is_active' => true]);

        $this->exchangeRate = ExchangeRate::create([
            'currency' => 'USD',
            'rate_to_idr' => 16000,
            'valid_from' => now()->subDay(),
            'created_by' => $this->purchasing->id,
        ]);
    }

    public function test_comparison_view_displays_item_award_selection_and_coverage(): void
    {
        $pr = $this->createRequisition(2);
        $qA = $this->createSubmittedQuotation($pr, $this->supplierA, 2.0);
        $qB = $this->createSubmittedQuotation($pr, $this->supplierB, 1.8);

        $response = $this->actingAs($this->purchasing)
            ->get(route('purchasing.comparison.inter-supplier', ['pr_id' => $pr]));

        $response->assertOk();
        $response->assertSee('Item-Level Award Status');
        $response->assertSee('award-radio');
        $response->assertSee('Save Award Selections');
        $response->assertSee('Confirm Awards &amp; Generate PO(s)', false);
    }

    public function test_purchasing_can_save_item_level_awards_via_http(): void
    {
        $pr = $this->createRequisition(2);
        $item1 = $pr->items[0];
        $item2 = $pr->items[1];

        $qA = $this->createSubmittedQuotation($pr, $this->supplierA, 2.0);
        $qB = $this->createSubmittedQuotation($pr, $this->supplierB, 1.8);

        $response = $this->actingAs($this->purchasing)
            ->post(route('purchasing.comparison.save-awards'), [
                'pr_id' => $pr->getRouteKey(),
                'awards' => [
                    $item1->id => $qA->items->firstWhere('pr_item_id', $item1->id)->id,
                    $item2->id => $qB->items->firstWhere('pr_item_id', $item2->id)->id,
                ],
                'action' => 'save',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('pr_item_awards', [
            'pr_id' => $pr->id,
            'pr_item_id' => $item1->id,
            'supplier_id' => $this->supplierA->id,
            'purchase_order_id' => null,
        ]);

        $this->assertDatabaseHas('pr_item_awards', [
            'pr_id' => $pr->id,
            'pr_item_id' => $item2->id,
            'supplier_id' => $this->supplierB->id,
            'purchase_order_id' => null,
        ]);
    }

    public function test_purchasing_can_save_awards_and_generate_multiple_pos_atomically(): void
    {
        $pr = $this->createRequisition(2);
        $item1 = $pr->items[0];
        $item2 = $pr->items[1];

        $qA = $this->createSubmittedQuotation($pr, $this->supplierA, 2.0);
        $qB = $this->createSubmittedQuotation($pr, $this->supplierB, 1.8);

        $response = $this->actingAs($this->purchasing)
            ->post(route('purchasing.comparison.save-awards'), [
                'pr_id' => $pr->getRouteKey(),
                'awards' => [
                    $item1->id => $qA->items->firstWhere('pr_item_id', $item1->id)->id,
                    $item2->id => $qB->items->firstWhere('pr_item_id', $item2->id)->id,
                ],
                'action' => 'generate_pos',
                'estimated_arrival' => now()->addDays(14)->toDateString(),
                'notes' => 'Generated from inter-supplier comparison awards',
            ]);

        $response->assertRedirect();

        $this->assertDatabaseCount('purchase_orders', 2);

        $poA = PurchaseOrder::where('supplier_id', $this->supplierA->id)->first();
        $poB = PurchaseOrder::where('supplier_id', $this->supplierB->id)->first();

        $this->assertNotNull($poA);
        $this->assertNotNull($poB);

        $this->assertDatabaseHas('pr_item_awards', [
            'pr_item_id' => $item1->id,
            'purchase_order_id' => $poA->id,
        ]);

        $this->assertDatabaseHas('pr_item_awards', [
            'pr_item_id' => $item2->id,
            'purchase_order_id' => $poB->id,
        ]);

        $this->assertSame('completed', $pr->fresh()->status);
    }

    public function test_supplier_cannot_submit_awards(): void
    {
        $pr = $this->createRequisition(1);
        $qA = $this->createSubmittedQuotation($pr, $this->supplierA, 2.0);

        $response = $this->actingAs($this->supplierA)
            ->post(route('purchasing.comparison.save-awards'), [
                'pr_id' => $pr->getRouteKey(),
                'awards' => [
                    $pr->items[0]->id => $qA->items[0]->id,
                ],
            ]);

        $response->assertForbidden();
    }

    public function test_generate_pos_rolls_back_awards_when_po_generation_fails(): void
    {
        $pr = $this->createRequisition(1);
        $item = $pr->items[0];
        $quotation = $this->createSubmittedQuotation($pr, $this->supplierA, 2.0);

        $existingPo = PurchaseOrder::create([
            'supplier_id' => $this->supplierA->id,
            'currency' => 'USD',
            'exchange_rate_id' => $this->exchangeRate->id,
            'po_number' => PurchaseOrder::generatePoNumber(),
            'status' => 'active',
            'created_by' => $this->purchasing->id,
            'estimated_arrival' => now()->addDays(14),
        ]);
        $existingPo->quotations()->attach($quotation->id);

        $response = $this->actingAs($this->purchasing)
            ->post(route('purchasing.comparison.save-awards'), [
                'pr_id' => $pr->getRouteKey(),
                'awards' => [
                    $item->id => $quotation->items->first()->id,
                ],
                'action' => 'generate_pos',
            ]);

        $response->assertRedirect()->assertSessionHas('error');
        $this->assertDatabaseMissing('pr_item_awards', ['pr_item_id' => $item->id]);
        $this->assertSame('submitted', $quotation->fresh()->status);
        $this->assertSame('bidding', $pr->fresh()->status);
    }

    public function test_generate_pos_rejects_stale_ineligible_quotation_status_without_writes(): void
    {
        $pr = $this->createRequisition(1);
        $item = $pr->items[0];
        $quotation = $this->createSubmittedQuotation($pr, $this->supplierA, 2.0);
        $quotation->update(['status' => Quotation::STATUS_REJECTED]);

        $response = $this->actingAs($this->purchasing)
            ->post(route('purchasing.comparison.save-awards'), [
                'pr_id' => $pr->getRouteKey(),
                'awards' => [
                    $item->id => $quotation->items->first()->id,
                ],
                'action' => 'generate_pos',
            ]);

        $response->assertRedirect()->assertSessionHas('error');
        $this->assertDatabaseMissing('pr_item_awards', ['pr_item_id' => $item->id]);
        $this->assertDatabaseCount('purchase_orders', 0);
    }

    private function createRequisition(int $itemCount = 2): PurchaseRequisition
    {
        $period = Period::create([
            'name' => 'Comparison Award Period '.rand(100, 9999),
            'month' => 9,
            'year' => 2026,
            'status' => 'open',
            'created_by' => $this->purchasing->id,
        ]);

        $pr = PurchaseRequisition::create([
            'period_id' => $period->id,
            'created_by' => $this->purchasing->id,
            'pr_number' => 'REQ/09/2026/'.str_pad((string) rand(1, 999), 3, '0', STR_PAD_LEFT),
            'status' => 'bidding',
            'notes' => 'Comparison award requisition',
        ]);

        for ($i = 1; $i <= $itemCount; $i++) {
            PrItem::create([
                'pr_id' => $pr->id,
                'hs_code' => '7209.16.00',
                'material_name' => "Material {$i}",
                'quantity' => 2,
                'shape' => PrItem::SHAPE_FLAT,
                'thickness' => 1.5,
                'width' => 100,
                'length' => 200,
                'weight_needed' => 10,
            ]);
        }

        return $pr->fresh(['items']);
    }

    private function createSubmittedQuotation(PurchaseRequisition $pr, User $supplier, float $price): Quotation
    {
        $quotation = Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $supplier->id,
            'currency' => 'USD',
            'exchange_rate_id' => $this->exchangeRate->id,
            'status' => Quotation::STATUS_SUBMITTED,
            'estimated_delivery' => now()->addDays(14),
            'payment_terms' => 'TT 30 Days',
            'validity_period' => now()->addDays(30),
            'submitted_at' => now(),
        ]);

        foreach ($pr->items as $item) {
            $quotation->items()->create([
                'pr_item_id' => $item->id,
                'is_available' => true,
                'price_per_kg' => $price,
                'amount' => $price * $item->total_weight,
            ]);
        }

        return $quotation->fresh(['items']);
    }
}
