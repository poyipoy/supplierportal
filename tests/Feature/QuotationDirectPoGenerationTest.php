<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\Period;
use App\Models\PrItem;
use App\Models\PrItemAward;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\User;
use App\Services\PurchaseOrderGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class QuotationDirectPoGenerationTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasing;

    private User $supplier;

    private User $qc;

    private ExchangeRate $exchangeRate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $this->supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $this->qc = User::factory()->create(['role' => 'qc', 'is_active' => true]);

        $this->exchangeRate = ExchangeRate::create([
            'currency' => 'USD',
            'rate_to_idr' => 16000,
            'valid_from' => now()->subDay(),
            'created_by' => $this->purchasing->id,
        ]);
    }

    public function test_purchasing_can_generate_po_directly_from_submitted_quotation(): void
    {
        $pr = $this->createRequisition();
        $quotation = $this->createQuotation($pr, Quotation::STATUS_SUBMITTED);
        $estimatedArrival = now()->addDays(21)->toDateString();

        $response = $this->actingAs($this->purchasing)
            ->post(route('purchasing.quotations.generate-po', $quotation), [
                'estimated_arrival' => $estimatedArrival,
                'notes' => 'Direct quotation approval',
            ]);

        $po = PurchaseOrder::sole();

        $response
            ->assertRedirect(route('purchasing.purchase-orders.show', $po))
            ->assertSessionHas('success', "Purchase Order {$po->po_number} successfully created from this quotation!");

        $this->assertSame(Quotation::STATUS_ACCEPTED, $quotation->fresh()->status);
        $this->assertSame('completed', $pr->fresh()->status);
        $this->assertSame($this->supplier->id, $po->supplier_id);
        $this->assertSame($estimatedArrival, $po->estimated_arrival?->toDateString());
        $this->assertSame('Direct quotation approval', $po->notes);
        $this->assertTrue($po->quotations()->whereKey($quotation->id)->exists());
        $this->assertDatabaseHas('pr_item_awards', [
            'pr_item_id' => $pr->items->first()->id,
            'quotation_id' => $quotation->id,
            'supplier_id' => $this->supplier->id,
            'purchase_order_id' => $po->id,
        ]);
    }

    public function test_purchasing_can_generate_po_directly_from_accepted_quotation(): void
    {
        $pr = $this->createRequisition();
        $quotation = $this->createQuotation($pr, Quotation::STATUS_ACCEPTED);

        $response = $this->actingAs($this->purchasing)
            ->post(route('purchasing.quotations.generate-po', $quotation));

        $po = PurchaseOrder::sole();

        $response->assertRedirect(route('purchasing.purchase-orders.show', $po));
        $this->assertSame(Quotation::STATUS_ACCEPTED, $quotation->fresh()->status);
        $this->assertSame('completed', $pr->fresh()->status);
    }

    public function test_direct_generation_rejects_expired_quotation_without_writes(): void
    {
        $pr = $this->createRequisition();
        $quotation = $this->createQuotation($pr, Quotation::STATUS_SUBMITTED, [true], now()->subDay());

        $response = $this->actingAs($this->purchasing)
            ->post(route('purchasing.quotations.generate-po', $quotation));

        $response->assertRedirect()->assertSessionHas('error');
        $this->assertDatabaseCount('purchase_orders', 0);
        $this->assertDatabaseCount('pr_item_awards', 0);
        $this->assertSame(Quotation::STATUS_SUBMITTED, $quotation->fresh()->status);
        $this->assertSame('bidding', $pr->fresh()->status);
    }

    public function test_direct_generation_rejects_completed_requisition_without_writes(): void
    {
        $pr = $this->createRequisition(status: 'completed');
        $quotation = $this->createQuotation($pr, Quotation::STATUS_SUBMITTED);

        $response = $this->actingAs($this->purchasing)
            ->post(route('purchasing.quotations.generate-po', $quotation));

        $response->assertRedirect()->assertSessionHas('error');
        $this->assertDatabaseCount('purchase_orders', 0);
        $this->assertDatabaseCount('pr_item_awards', 0);
        $this->assertSame(Quotation::STATUS_SUBMITTED, $quotation->fresh()->status);
    }

    public function test_direct_generation_skips_unavailable_items_and_keeps_pr_actionable(): void
    {
        $pr = $this->createRequisition(itemCount: 2);
        $quotation = $this->createQuotation($pr, Quotation::STATUS_SUBMITTED, [true, false]);
        $availableItem = $quotation->items->firstWhere('is_available', true);
        $unavailableItem = $quotation->items->firstWhere('is_available', false);

        $response = $this->actingAs($this->purchasing)
            ->post(route('purchasing.quotations.generate-po', $quotation));

        $po = PurchaseOrder::sole();

        $response->assertRedirect(route('purchasing.purchase-orders.show', $po));
        $this->assertDatabaseHas('pr_item_awards', [
            'quotation_item_id' => $availableItem->id,
            'purchase_order_id' => $po->id,
        ]);
        $this->assertDatabaseMissing('pr_item_awards', [
            'quotation_item_id' => $unavailableItem->id,
        ]);
        $this->assertSame('bidding', $pr->fresh()->status);
    }

    public function test_direct_generation_does_not_overwrite_item_already_assigned_to_another_po(): void
    {
        $otherSupplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $pr = $this->createRequisition(itemCount: 2);
        $currentQuotation = $this->createQuotation($pr, Quotation::STATUS_SUBMITTED, [true, true]);
        $otherQuotation = $this->createQuotation(
            $pr,
            Quotation::STATUS_ACCEPTED,
            [true, true],
            supplier: $otherSupplier
        );
        $assignedPrItem = $pr->items->first();
        $remainingPrItem = $pr->items->last();
        $assignedQuotationItem = $otherQuotation->items->firstWhere('pr_item_id', $assignedPrItem->id);

        $existingPo = PurchaseOrder::create([
            'supplier_id' => $otherSupplier->id,
            'currency' => 'USD',
            'exchange_rate_id' => $this->exchangeRate->id,
            'po_number' => PurchaseOrder::generatePoNumber(),
            'status' => 'active',
            'created_by' => $this->purchasing->id,
            'estimated_arrival' => now()->addDays(14),
        ]);
        $existingPo->quotations()->attach($otherQuotation->id);
        $existingAward = PrItemAward::create([
            'pr_id' => $pr->id,
            'pr_item_id' => $assignedPrItem->id,
            'quotation_id' => $otherQuotation->id,
            'quotation_item_id' => $assignedQuotationItem->id,
            'supplier_id' => $otherSupplier->id,
            'purchase_order_id' => $existingPo->id,
            'awarded_by' => $this->purchasing->id,
            'awarded_at' => now(),
        ]);

        $response = $this->actingAs($this->purchasing)
            ->post(route('purchasing.quotations.generate-po', $currentQuotation));

        $newPo = PurchaseOrder::where('id', '<>', $existingPo->id)->sole();

        $response->assertRedirect(route('purchasing.purchase-orders.show', $newPo));
        $this->assertSame($existingPo->id, $existingAward->fresh()->purchase_order_id);
        $this->assertSame($otherQuotation->id, $existingAward->fresh()->quotation_id);
        $this->assertDatabaseHas('pr_item_awards', [
            'pr_item_id' => $remainingPrItem->id,
            'quotation_id' => $currentQuotation->id,
            'purchase_order_id' => $newPo->id,
        ]);
        $this->assertSame('completed', $pr->fresh()->status);
    }

    public function test_direct_generation_is_atomic_when_po_generation_fails(): void
    {
        $pr = $this->createRequisition();
        $quotation = $this->createQuotation($pr, Quotation::STATUS_SUBMITTED);

        $poService = \Mockery::mock(PurchaseOrderGenerationService::class);
        $poService->shouldReceive('generateFromAwards')
            ->once()
            ->andThrow(new InvalidArgumentException('Forced PO generation failure.'));
        $this->app->instance(PurchaseOrderGenerationService::class, $poService);

        $response = $this->actingAs($this->purchasing)
            ->post(route('purchasing.quotations.generate-po', $quotation));

        $response->assertRedirect()->assertSessionHas('error', 'Forced PO generation failure.');
        $this->assertDatabaseCount('purchase_orders', 0);
        $this->assertDatabaseCount('pr_item_awards', 0);
        $this->assertSame(Quotation::STATUS_SUBMITTED, $quotation->fresh()->status);
        $this->assertSame('bidding', $pr->fresh()->status);
    }

    public function test_supplier_and_qc_cannot_generate_po_from_quotation(): void
    {
        $pr = $this->createRequisition();
        $quotation = $this->createQuotation($pr, Quotation::STATUS_SUBMITTED);

        $this->actingAs($this->supplier)
            ->post(route('purchasing.quotations.generate-po', $quotation))
            ->assertForbidden();

        $this->actingAs($this->qc)
            ->post(route('purchasing.quotations.generate-po', $quotation))
            ->assertForbidden();

        $this->assertDatabaseCount('purchase_orders', 0);
        $this->assertDatabaseCount('pr_item_awards', 0);
    }

    public function test_direct_generation_validates_date_and_notes_before_writing(): void
    {
        $pr = $this->createRequisition();
        $quotation = $this->createQuotation($pr, Quotation::STATUS_SUBMITTED);

        $response = $this->actingAs($this->purchasing)
            ->post(route('purchasing.quotations.generate-po', $quotation), [
                'estimated_arrival' => 'not-a-date',
                'notes' => str_repeat('x', 1001),
            ]);

        $response->assertSessionHasErrors(['estimated_arrival', 'notes']);
        $this->assertDatabaseCount('purchase_orders', 0);
        $this->assertDatabaseCount('pr_item_awards', 0);
    }

    public function test_direct_generation_route_rejects_plain_numeric_quotation_id(): void
    {
        $pr = $this->createRequisition();
        $quotation = $this->createQuotation($pr, Quotation::STATUS_SUBMITTED);

        $this->actingAs($this->purchasing)
            ->post("/purchasing/quotations/{$quotation->id}/generate-po")
            ->assertNotFound();

        $this->assertDatabaseCount('purchase_orders', 0);
        $this->assertDatabaseCount('pr_item_awards', 0);
    }

    public function test_quotation_detail_shows_direct_generation_modal_and_comparison_link(): void
    {
        $pr = $this->createRequisition(itemCount: 2);
        $quotation = $this->createQuotation($pr, Quotation::STATUS_SUBMITTED, [true, false]);

        $response = $this->actingAs($this->purchasing)
            ->get(route('purchasing.quotations.show', $quotation));

        $response
            ->assertOk()
            ->assertSee('Generate Purchase Order')
            ->assertSee('Open Inter-Supplier Comparison')
            ->assertSee('Included in PO')
            ->assertSee('Unavailable - skipped')
            ->assertSee(route('purchasing.quotations.generate-po', $quotation), false);
    }

    private function createRequisition(int $itemCount = 1, string $status = 'bidding'): PurchaseRequisition
    {
        $period = Period::create([
            'name' => 'Direct PO Period '.uniqid(),
            'month' => 9,
            'year' => 2026,
            'status' => 'open',
            'created_by' => $this->purchasing->id,
        ]);

        $pr = PurchaseRequisition::create([
            'period_id' => $period->id,
            'created_by' => $this->purchasing->id,
            'pr_number' => 'REQ/09/2026/'.str_pad((string) random_int(1, 999), 3, '0', STR_PAD_LEFT),
            'status' => $status,
            'notes' => 'Direct PO generation test',
        ]);

        for ($index = 1; $index <= $itemCount; $index++) {
            PrItem::create([
                'pr_id' => $pr->id,
                'hs_code' => '7209.16.00',
                'material_name' => "Direct PO Material {$index}",
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

    /**
     * @param  list<bool>  $availability
     */
    private function createQuotation(
        PurchaseRequisition $pr,
        string $status,
        array $availability = [true],
        mixed $validityPeriod = null,
        ?User $supplier = null
    ): Quotation {
        $supplier ??= $this->supplier;

        $quotation = Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $supplier->id,
            'currency' => 'USD',
            'exchange_rate_id' => $this->exchangeRate->id,
            'status' => $status,
            'estimated_delivery' => now()->addDays(14),
            'payment_terms' => 'TT 30 Days',
            'validity_period' => $validityPeriod ?? now()->addDays(30),
            'submitted_at' => now(),
        ]);

        foreach ($pr->items->values() as $index => $item) {
            $isAvailable = $availability[$index] ?? true;
            $quotation->items()->create([
                'pr_item_id' => $item->id,
                'is_available' => $isAvailable,
                'price_per_kg' => $isAvailable ? 2.5 : null,
                'amount' => $isAvailable ? 2.5 * $item->total_weight : 0,
            ]);
        }

        return $quotation->fresh(['items']);
    }
}
