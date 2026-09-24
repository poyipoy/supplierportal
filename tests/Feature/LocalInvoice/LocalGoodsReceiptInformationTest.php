<?php

namespace Tests\Feature\LocalInvoice;

use App\Models\LocalGoodsReceipt;
use App\Models\LocalPurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierScope;
use App\Models\User;
use App\Services\LocalInvoice\LocalProcurementMasterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LocalGoodsReceiptInformationTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    private User $supplier;

    private LocalPurchaseOrder $po;

    private LocalProcurementMasterService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->finance = User::factory()->create([
            'role' => 'finance',
            'is_active' => true,
        ]);

        $this->supplier = User::factory()->create([
            'role' => 'supplier',
            'is_active' => true,
        ]);
        SupplierScope::create(['supplier_id' => $this->supplier->id, 'scope' => 'local']);
        Supplier::create([
            'user_id' => $this->supplier->id,
            'company_name' => 'PT Steel Supplier',
            'category' => 'Raw Material',
            'is_pkp' => false,
            'payment_term_days' => 30,
        ]);

        $this->service = app(LocalProcurementMasterService::class);

        $this->po = $this->service->createPurchaseOrder($this->finance, [
            'po_number' => 'PO-LOC-2026-001',
            'supplier_id' => $this->supplier->id,
            'po_date' => '2026-09-24',
            'total_amount' => '1000000.00',
            'description' => 'Test Local PO',
        ]);
    }

    public function test_can_create_goods_receipt_with_qty_and_description(): void
    {
        $response = $this->actingAs($this->finance)->post(route('finance.local-procurement.goods-receipts.store', $this->po), [
            'gr_number' => 'GR-2026-001',
            'gr_date' => '2026-09-24',
            'received_amount' => '250000.00',
            'qty' => '15.5000',
            'description' => 'Steel Plates Grade A',
            'notes' => 'Delivered in good condition',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('local_goods_receipts', [
            'gr_number' => 'GR-2026-001',
            'local_purchase_order_id' => $this->po->id,
            'received_amount' => '250000.00',
            'qty' => '15.5000',
            'description' => 'Steel Plates Grade A',
            'notes' => 'Delivered in good condition',
            'status' => LocalGoodsReceipt::STATUS_AVAILABLE,
        ]);

        $gr = LocalGoodsReceipt::where('gr_number', 'GR-2026-001')->firstOrFail();
        $this->assertSame('15.5000', (string) $gr->qty);
        $this->assertSame('Steel Plates Grade A', $gr->description);
    }

    public function test_can_update_available_goods_receipt_with_qty_and_description(): void
    {
        $gr = $this->service->createGoodsReceipt($this->finance, $this->po, [
            'gr_number' => 'GR-2026-002',
            'gr_date' => '2026-09-24',
            'received_amount' => '100000.00',
            'qty' => '10.0000',
            'description' => 'Initial Description',
        ]);

        $response = $this->actingAs($this->finance)->put(route('finance.local-procurement.goods-receipts.update', $gr), [
            'gr_number' => 'GR-2026-002',
            'gr_date' => '2026-09-24',
            'received_amount' => '120000.00',
            'qty' => '12.2500',
            'description' => 'Updated Description Material B',
            'notes' => 'Updated notes',
        ]);

        $response->assertRedirect();

        $gr->refresh();
        $this->assertSame('12.2500', (string) $gr->qty);
        $this->assertSame('Updated Description Material B', $gr->description);
        $this->assertSame('120000.00', (string) $gr->received_amount);
    }

    public function test_validation_fails_for_invalid_qty(): void
    {
        // 1. Missing qty
        $response = $this->actingAs($this->finance)->post(route('finance.local-procurement.goods-receipts.store', $this->po), [
            'gr_number' => 'GR-FAIL-001',
            'gr_date' => '2026-09-24',
            'received_amount' => '50000.00',
        ]);
        $response->assertSessionHasErrors(['qty']);

        // 2. Zero qty
        $response = $this->actingAs($this->finance)->post(route('finance.local-procurement.goods-receipts.store', $this->po), [
            'gr_number' => 'GR-FAIL-002',
            'gr_date' => '2026-09-24',
            'received_amount' => '50000.00',
            'qty' => '0',
        ]);
        $response->assertSessionHasErrors(['qty']);

        // 3. Negative qty
        $response = $this->actingAs($this->finance)->post(route('finance.local-procurement.goods-receipts.store', $this->po), [
            'gr_number' => 'GR-FAIL-003',
            'gr_date' => '2026-09-24',
            'received_amount' => '50000.00',
            'qty' => '-5.5',
        ]);
        $response->assertSessionHasErrors(['qty']);

        // 4. Exceeds decimal precision (more than 4 decimal places)
        $response = $this->actingAs($this->finance)->post(route('finance.local-procurement.goods-receipts.store', $this->po), [
            'gr_number' => 'GR-FAIL-004',
            'gr_date' => '2026-09-24',
            'received_amount' => '50000.00',
            'qty' => '1.12345',
        ]);
        $response->assertSessionHasErrors(['qty']);
    }

    public function test_qty_does_not_affect_po_cap_validation(): void
    {
        // PO total amount is 1,000,000.00
        // A huge qty (e.g. 999999) with valid monetary received_amount (500,000.00) must succeed because PO cap is monetary
        $gr = $this->service->createGoodsReceipt($this->finance, $this->po, [
            'gr_number' => 'GR-CAP-001',
            'gr_date' => '2026-09-24',
            'received_amount' => '500000.00',
            'qty' => '99999.0000',
            'description' => 'High quantity items',
        ]);
        $this->assertNotNull($gr->id);

        // However, if received_amount exceeds remaining PO cap (500,000.00 + 600,000.00 > 1,000,000.00), it must fail regardless of qty
        $this->expectException(ValidationException::class);
        $this->service->createGoodsReceipt($this->finance, $this->po, [
            'gr_number' => 'GR-CAP-002',
            'gr_date' => '2026-09-24',
            'received_amount' => '600000.00',
            'qty' => '1.0000',
        ]);
    }
}
