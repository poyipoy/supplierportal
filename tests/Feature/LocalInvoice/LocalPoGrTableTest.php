<?php

namespace Tests\Feature\LocalInvoice;

use App\Models\LocalGoodsReceipt;
use App\Models\LocalInvoice;
use App\Models\LocalPurchaseOrder;
use App\Models\SupplierScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LocalPoGrTableTest extends TestCase
{
    use RefreshDatabase;

    private User $supplier;

    private LocalPurchaseOrder $po;

    protected function setUp(): void
    {
        parent::setUp();
        $this->supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        SupplierScope::create(['supplier_id' => $this->supplier->id, 'scope' => 'local']);
        $this->po = LocalPurchaseOrder::create(['supplier_id' => $this->supplier->id, 'po_number' => 'TABLE-PO',
            'po_date' => '2026-10-01', 'total_amount' => '1000000.00', 'currency' => 'IDR', 'status' => 'OPEN']);
        foreach (range(1, 125) as $number) {
            LocalGoodsReceipt::create(['local_purchase_order_id' => $this->po->id, 'gr_number' => 'TABLE-GR-'.str_pad($number, 3, '0', STR_PAD_LEFT),
                'gr_date' => '2026-10-01', 'qty' => '1.000', 'uom' => 'pcs', 'description' => $number === 1 ? '<img src=x onerror=alert(1)>' : 'Material '.$number,
                'status' => $number === 125 ? 'CANCELLED' : 'AVAILABLE']);
        }
    }

    public static function audiences(): array
    {
        return [['finance', 'finance.local-procurement'], ['purchasing', 'purchasing.local-procurement'], ['supplier', 'local-supplier.purchase-orders']];
    }

    #[DataProvider('audiences')]
    public function test_paging_is_bounded_numbered_and_preserves_full_totals(string $role, string $prefix): void
    {
        $actor = $role === 'supplier' ? $this->supplier : User::factory()->create(['role' => $role, 'is_active' => true]);
        $url = route($prefix.'.goods-receipts.data', $this->po);
        $first = $this->actingAs($actor)->getJson($url.'?draw=1&start=0&length=25');
        $first->assertOk()->assertJsonCount(25, 'data')->assertJsonPath('data.0.DT_RowIndex', 1)
            ->assertJsonPath('recordsTotal', $role === 'supplier' ? 124 : 125);
        $second = $this->getJson($url.'?draw=2&start=25&length=25');
        $second->assertOk()->assertJsonPath('data.0.DT_RowIndex', 26)->assertJsonCount(25, 'data');
        $this->assertNotSame($first->json('data.0.gr_number'), $second->json('data.0.gr_number'));
        $last = $this->getJson($url.'?draw=3&start=100&length=25');
        $last->assertOk()->assertJsonCount($role === 'supplier' ? 24 : 25, 'data');
        $this->get(route($prefix.'.show', $this->po))->assertOk()->assertSee('poGoodsReceiptsTable')
            ->assertViewHas('purchaseOrder', fn ($order) => (int) $order->active_gr_count === 124 && $order->goodsReceipts->count() === 25);
        $this->getJson($url.'?length=100')->assertOk()->assertJsonCount(100, 'data');
        $this->getJson($url.'?length=-1')->assertUnprocessable();
        $this->getJson($url.'?length=101')->assertUnprocessable();
        if ($role === 'supplier') {
            $this->assertNull($first->json('data.0.edit_data'));
            $this->getJson($url.'?status=CANCELLED')->assertUnprocessable();
        }
    }

    public function test_search_filter_order_and_output_escaping_are_server_controlled(): void
    {
        $actor = User::factory()->create(['role' => 'finance', 'is_active' => true]);
        $url = route('finance.local-procurement.goods-receipts.data', $this->po);
        $this->actingAs($actor)->getJson($url.'?search[value]=TABLE-GR-001')
            ->assertOk()->assertJsonPath('recordsFiltered', 1)->assertJsonPath('recordsTotal', 125)
            ->assertJsonPath('data.0.DT_RowIndex', 1)->assertJsonPath('data.0.description', '&lt;img src=x onerror=alert(1)&gt;');
        $this->getJson($url.'?status=CANCELLED')->assertOk()->assertJsonPath('recordsFiltered', 1);
        $this->getJson($url.'?order[0][column]=1&order[0][dir]=asc&columns[1][name]=password')
            ->assertOk()->assertJsonPath('data.0.gr_number', 'TABLE-GR-001');
        $this->getJson($url.'?search[value]=not-found')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_financial_summary_uses_all_invoices_and_preserves_exact_money(): void
    {
        LocalInvoice::create(['supplier_id' => $this->supplier->id, 'submission_number' => 'GR-TABLE-SUBMISSION', 'invoice_number' => 'GR-TABLE-INVOICE',
            'invoice_date' => '2026-10-01', 'local_purchase_order_id' => $this->po->id, 'po_number' => $this->po->po_number,
            'po_source' => 'INTERNAL', 'invoice_amount' => '250000.00', 'tax_amount' => '0.00', 'status' => 'PAID',
            'payment_term_days_snapshot' => 30, 'revision_number' => 1, 'submitted_at' => now()]);
        foreach (['finance', 'purchasing'] as $role) {
            $actor = User::factory()->create(['role' => $role, 'is_active' => true]);
            $response = $this->actingAs($actor)->get(route($role.'.local-procurement.show', $this->po))->assertOk();
            $response->assertSee('250.000,00')->assertSee('750.000,00');
        }
    }

    public function test_edit_metadata_can_be_saved_unchanged_without_invalid_decimal_padding(): void
    {
        $actor = User::factory()->create(['role' => 'finance', 'is_active' => true]);
        $response = $this->actingAs($actor)->getJson(route('finance.local-procurement.goods-receipts.data', $this->po).'?status=AVAILABLE')->assertOk();
        $edit = $response->json('data.0.edit_data');
        $this->assertSame('1', $edit['qty']);
        $this->put($edit['update_url'], $edit)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(125, LocalGoodsReceipt::count());
    }

    public function test_po_and_role_isolation_and_raw_identifier_rejection(): void
    {
        $other = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        SupplierScope::create(['supplier_id' => $other->id, 'scope' => 'local']);
        $this->actingAs($other)->getJson(route('local-supplier.purchase-orders.goods-receipts.data', $this->po))->assertForbidden();
        $qc = User::factory()->create(['role' => 'qc', 'is_active' => true]);
        $this->actingAs($qc)->getJson(route('finance.local-procurement.goods-receipts.data', $this->po))->assertForbidden();
        $this->actingAs($this->supplier)->getJson('/local-supplier/purchase-orders/'.$this->po->id.'/goods-receipts/data')->assertNotFound();
        $empty = LocalPurchaseOrder::create(['supplier_id' => $this->supplier->id, 'po_number' => 'EMPTY-PO', 'po_date' => '2026-10-01',
            'total_amount' => '1.00', 'currency' => 'IDR', 'status' => 'OPEN']);
        $this->getJson(route('local-supplier.purchase-orders.goods-receipts.data', $empty))->assertOk()->assertJsonCount(0, 'data');
    }
}
