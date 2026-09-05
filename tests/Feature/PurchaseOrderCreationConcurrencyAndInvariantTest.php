<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\Period;
use App\Models\PrItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PrItemAwardService;
use App\Services\PurchaseOrderGenerationService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PurchaseOrderCreationConcurrencyAndInvariantTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasing;

    private User $supplierUser;

    private Period $period;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasing = User::factory()->create([
            'role' => 'purchasing',
            'is_active' => true,
        ]);

        $this->supplierUser = User::factory()->create([
            'role' => 'supplier',
            'is_active' => true,
        ]);

        Supplier::create([
            'user_id' => $this->supplierUser->id,
            'company_name' => 'PT Supplier Concurrency Test',
            'address' => 'Jl. Test 123',
            'phone' => '08123456789',
            'npwp' => '01.234.567.8-901.000',
        ]);

        $this->period = Period::create([
            'name' => '2026-09 Period',
            'year' => 2026,
            'month' => 9,
            'status' => 'open',
            'created_by' => $this->purchasing->id,
        ]);

        ExchangeRate::create([
            'currency' => 'USD',
            'rate_to_idr' => 16000,
            'valid_from' => now()->subDay(),
            'created_by' => $this->purchasing->id,
        ]);
    }

    private function createRequisitionAndQuotation(string $prNumber, User $supplier, string $currency = 'USD'): array
    {
        $pr = PurchaseRequisition::create([
            'period_id' => $this->period->id,
            'created_by' => $this->purchasing->id,
            'pr_number' => $prNumber,
            'status' => 'bidding',
        ]);

        $item = PrItem::create([
            'pr_id' => $pr->id,
            'hs_code' => '7209.16.00',
            'material_name' => 'Steel Plate Invariant Test',
            'quantity' => 1,
            'shape' => 'Flat',
            'thickness' => 10,
            'width' => 1000,
            'length' => 2000,
            'weight_needed' => 500,
        ]);

        $quotation = Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $supplier->id,
            'currency' => $currency,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $quotation->items()->create([
            'pr_item_id' => $item->id,
            'is_available' => true,
            'price_per_kg' => 2.50,
            'amount' => 1250,
        ]);

        return [$pr, $quotation, $item];
    }

    public function test_direct_legacy_quotation_post_cannot_create_a_new_purchase_order(): void
    {
        [$pr, $quotation] = $this->createRequisitionAndQuotation('REQ/09/2026/001', $this->supplierUser);

        $response = $this->actingAs($this->purchasing)->post(route('purchasing.purchase-orders.store'), [
            'quotation_ids' => [$quotation->id],
            'estimated_arrival' => now()->addMonth()->toDateString(),
            'notes' => 'Single PO test',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertStringContainsString('item-level award', session('error'));
        $this->assertDatabaseCount('purchase_orders', 0);
        $this->assertDatabaseMissing('po_quotations', ['quotation_id' => $quotation->id]);
        $this->assertSame('submitted', $quotation->fresh()->status);
        $this->assertSame('bidding', $pr->fresh()->status);
    }

    public function test_historical_legacy_purchase_order_without_awards_remains_readable(): void
    {
        [, $quotation] = $this->createRequisitionAndQuotation('REQ/09/2026/008', $this->supplierUser);

        $po = PurchaseOrder::create([
            'supplier_id' => $this->supplierUser->id,
            'currency' => 'USD',
            'po_number' => PurchaseOrder::generatePoNumber(),
            'status' => 'active',
            'created_by' => $this->purchasing->id,
            'estimated_arrival' => now()->addMonth(),
        ]);
        $po->quotations()->attach($quotation->id);

        $this->actingAs($this->purchasing)
            ->get(route('purchasing.purchase-orders.show', $po))
            ->assertOk()
            ->assertSee($po->po_number);
    }

    public function test_cannot_assign_quotation_that_already_has_a_purchase_order(): void
    {
        [$pr, $quotation] = $this->createRequisitionAndQuotation('REQ/09/2026/002', $this->supplierUser);

        $po = PurchaseOrder::create([
            'supplier_id' => $this->supplierUser->id,
            'currency' => 'USD',
            'po_number' => PurchaseOrder::generatePoNumber(),
            'status' => 'active',
            'created_by' => $this->purchasing->id,
            'estimated_arrival' => now()->addMonth(),
        ]);
        $po->quotations()->attach($quotation->id);

        // The retired quotation-level write contract is rejected regardless of record history.
        $response = $this->actingAs($this->purchasing)->post(route('purchasing.purchase-orders.store'), [
            'quotation_ids' => [$quotation->id],
            'estimated_arrival' => now()->addMonth()->toDateString(),
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertStringContainsString('item-level awards', session('error'));

        // Database unique invariant holds: only 1 po_quotations record for this quotation
        $this->assertSame(1, DB::table('po_quotations')->where('quotation_id', $quotation->id)->count());
    }

    public function test_duplicate_quotation_ids_in_request_are_rejected(): void
    {
        [$pr, $quotation] = $this->createRequisitionAndQuotation('REQ/09/2026/003', $this->supplierUser);

        $response = $this->actingAs($this->purchasing)->post(route('purchasing.purchase-orders.store'), [
            'quotation_ids' => [$quotation->id, $quotation->id],
            'estimated_arrival' => now()->addMonth()->toDateString(),
        ]);

        $response->assertSessionHasErrors('quotation_ids.0');
    }

    public function test_legacy_post_cannot_consolidate_multiple_quotations_from_the_same_pr(): void
    {
        [$pr, $q1] = $this->createRequisitionAndQuotation('REQ/09/2026/004', $this->supplierUser);

        // Fabricate a second quotation for the same supplier and PR to test defense
        $q2 = Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $this->supplierUser->id,
            'currency' => 'USD',
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
        $q2->items()->create([
            'pr_item_id' => $pr->items()->first()->id,
            'is_available' => true,
            'price_per_kg' => 2.40,
            'amount' => 1200,
        ]);

        $response = $this->actingAs($this->purchasing)->post(route('purchasing.purchase-orders.store'), [
            'quotation_ids' => [$q1->id, $q2->id],
            'estimated_arrival' => now()->addMonth()->toDateString(),
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertStringContainsString('item-level awards', session('error'));
        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_multi_pr_consolidation_succeeds_and_does_not_reject_selected_quotations(): void
    {
        [$pr1, $q1, $item1] = $this->createRequisitionAndQuotation('REQ/09/2026/005', $this->supplierUser);
        [$pr2, $q2, $item2] = $this->createRequisitionAndQuotation('REQ/09/2026/006', $this->supplierUser);

        // Another supplier bids on PR1 and should be rejected
        $otherSupplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $otherQ = Quotation::create([
            'pr_id' => $pr1->id,
            'supplier_id' => $otherSupplier->id,
            'currency' => 'USD',
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
        $otherQ->items()->create([
            'pr_item_id' => $pr1->items()->first()->id,
            'is_available' => true,
            'price_per_kg' => 3.00,
            'amount' => 1500,
        ]);

        $awardService = app(PrItemAwardService::class);
        $awards = collect([
            $awardService->awardItem($item1, $q1->items()->firstOrFail(), $this->purchasing),
            $awardService->awardItem($item2, $q2->items()->firstOrFail(), $this->purchasing),
        ]);
        app(PurchaseOrderGenerationService::class)->generateFromAwards($awards, $this->purchasing, [
            'estimated_arrival' => now()->addMonth()->toDateString(),
        ]);

        // Both selected quotations MUST be accepted, neither should reject the other!
        $this->assertSame('accepted', $q1->fresh()->status);
        $this->assertSame('accepted', $q2->fresh()->status);

        // The other competitor quotation MUST be rejected
        $this->assertSame('rejected', $otherQ->fresh()->status);

        // Both PRs are completed
        $this->assertSame('completed', $pr1->fresh()->status);
        $this->assertSame('completed', $pr2->fresh()->status);

        // Both quotations attached to the same PO
        $po = PurchaseOrder::sole();
        $this->assertSame(2, $po->quotations()->count());
    }

    public function test_database_unique_constraint_enforces_one_po_per_quotation(): void
    {
        [$pr, $quotation] = $this->createRequisitionAndQuotation('REQ/09/2026/007', $this->supplierUser);

        $po1 = PurchaseOrder::create([
            'supplier_id' => $this->supplierUser->id,
            'currency' => 'USD',
            'po_number' => PurchaseOrder::generatePoNumber(),
            'status' => 'active',
            'created_by' => $this->purchasing->id,
            'estimated_arrival' => now()->addMonth(),
        ]);

        $po2 = PurchaseOrder::create([
            'supplier_id' => $this->supplierUser->id,
            'currency' => 'USD',
            'po_number' => PurchaseOrder::generatePoNumber(),
            'status' => 'active',
            'created_by' => $this->purchasing->id,
            'estimated_arrival' => now()->addMonth(),
        ]);

        DB::table('po_quotations')->insert([
            'po_id' => $po1->id,
            'quotation_id' => $quotation->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Attempting to insert a second PO link for the same quotation MUST trigger UniqueConstraintViolationException
        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('po_quotations')->insert([
            'po_id' => $po2->id,
            'quotation_id' => $quotation->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
