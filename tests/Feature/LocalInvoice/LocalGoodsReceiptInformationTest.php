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
            'uom' => 'pcs',
            'gr_date' => '2026-09-24',
            'qty' => '15.5',
            'description' => 'Steel Plates Grade A',
            'notes' => 'Delivered in good condition',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('local_goods_receipts', [
            'gr_number' => 'GR-2026-001',
            'local_purchase_order_id' => $this->po->id,
            'qty' => '15.5',
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
            'uom' => 'pcs',
            'gr_date' => '2026-09-24',
            'qty' => '10.0',
            'description' => 'Initial Description',
        ]);

        $response = $this->actingAs($this->finance)->put(route('finance.local-procurement.goods-receipts.update', $gr), [
            'gr_number' => 'GR-2026-002',
            'uom' => 'pcs',
            'gr_date' => '2026-09-24',
            'qty' => '12.25',
            'description' => 'Updated Description Material B',
            'notes' => 'Updated notes',
        ]);

        $response->assertRedirect();

        $gr->refresh();
        $this->assertSame('12.2500', (string) $gr->qty);
        $this->assertSame('Updated Description Material B', $gr->description);
    }

    public function test_validation_fails_for_invalid_qty(): void
    {
        // 1. Missing qty
        $response = $this->actingAs($this->finance)->post(route('finance.local-procurement.goods-receipts.store', $this->po), [
            'gr_number' => 'GR-FAIL-001',
            'uom' => 'pcs',
            'gr_date' => '2026-09-24',
        ]);
        $response->assertSessionHasErrors(['qty']);

        // 2. Zero qty
        $response = $this->actingAs($this->finance)->post(route('finance.local-procurement.goods-receipts.store', $this->po), [
            'gr_number' => 'GR-FAIL-002',
            'uom' => 'pcs',
            'gr_date' => '2026-09-24',
            'qty' => '0',
        ]);
        $response->assertSessionHasErrors(['qty']);

        // 3. Negative qty
        $response = $this->actingAs($this->finance)->post(route('finance.local-procurement.goods-receipts.store', $this->po), [
            'gr_number' => 'GR-FAIL-003',
            'uom' => 'pcs',
            'gr_date' => '2026-09-24',
            'qty' => '-5.5',
        ]);
        $response->assertSessionHasErrors(['qty']);

        // 4. Exceeds the application boundary of three decimal places.
        $response = $this->actingAs($this->finance)->post(route('finance.local-procurement.goods-receipts.store', $this->po), [
            'gr_number' => 'GR-FAIL-004',
            'uom' => 'pcs',
            'gr_date' => '2026-09-24',
            'qty' => '10.1256',
        ]);
        $response->assertSessionHasErrors(['qty']);
    }

    public function test_goods_receipt_created_with_valid_quantities(): void
    {
        $gr1 = $this->service->createGoodsReceipt($this->finance, $this->po, [
            'gr_number' => 'GR-CAP-001',
            'uom' => 'pcs',
            'gr_date' => '2026-09-24',
            'qty' => '99999.0',
            'description' => 'High quantity items',
        ]);
        $this->assertNotNull($gr1->id);
        $this->assertSame('99999.0000', (string) $gr1->qty);

        $gr2 = $this->service->createGoodsReceipt($this->finance, $this->po, [
            'gr_number' => 'GR-CAP-002',
            'uom' => 'pcs',
            'gr_date' => '2026-09-24',
            'qty' => '500.5',
            'description' => 'Additional items',
        ]);
        $this->assertNotNull($gr2->id);
        $this->assertSame('500.5000', (string) $gr2->qty);
    }

    public function test_domain_rejects_precision_and_missing_or_unknown_uom(): void
    {
        foreach ([['10.1256', 'kg'], ['5', ''], ['5', 'unknown']] as [$qty, $uom]) {
            try {
                $this->service->createGoodsReceipt($this->finance, $this->po, [
                    'gr_number' => 'GR-INVALID', 'gr_date' => '2026-09-24', 'qty' => $qty, 'uom' => $uom,
                ]);
                $this->fail('Invalid GR accepted.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
        foreach (['5', '2.5', '2.55', '10.125'] as $index => $qty) {
            $gr = $this->service->createGoodsReceipt($this->finance, $this->po, [
                'gr_number' => 'GR-UOM-'.$index, 'gr_date' => '2026-09-24', 'qty' => $qty, 'uom' => 'KG',
            ]);
            $this->assertSame('kg', $gr->uom);
            $this->assertSame(0, bccomp($qty, $gr->qty, 4));
        }
    }

    public function test_manual_uom_whitelist_is_enforced_and_normalized(): void
    {
        foreach (['', 'unknown', '   '] as $uom) {
            $this->actingAs($this->finance)->post(route('finance.local-procurement.goods-receipts.store', $this->po), [
                'gr_number' => 'GR-UOM-HTTP', 'gr_date' => '2026-09-24', 'qty' => '5', 'uom' => $uom,
            ])->assertSessionHasErrors('uom');
        }
        $this->post(route('finance.local-procurement.goods-receipts.store', $this->po), [
            'gr_number' => 'GR-UOM-HTTP', 'gr_date' => '2026-09-24', 'qty' => '10.125', 'uom' => 'KG',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('local_goods_receipts', ['gr_number' => 'GR-UOM-HTTP', 'qty' => '10.1250', 'uom' => 'kg']);
    }
}
