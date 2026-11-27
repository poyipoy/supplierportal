<?php

namespace Tests\Feature\Purchasing;

use App\Models\Attachment;
use App\Models\ExchangeRate;
use App\Models\LocalGoodsReceipt;
use App\Models\LocalInvoice;
use App\Models\LocalPurchaseOrder;
use App\Models\MaterialClaim;
use App\Models\Period;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\QcInspection;
use App\Models\Quotation;
use App\Models\Shipment;
use App\Models\SupplierScope;
use App\Models\User;
use App\Support\BusinessTime;
use App\Support\ServerTabsResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MmtWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasing;

    private User $finance;

    private User $importSupplier;

    private User $localSupplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true, 'account_status' => 'ACTIVE']);
        $this->finance = User::factory()->create(['role' => 'finance', 'is_active' => true, 'account_status' => 'ACTIVE']);

        $this->importSupplier = User::factory()->create(['role' => 'supplier', 'is_active' => true, 'account_status' => 'ACTIVE']);
        SupplierScope::query()->updateOrCreate(['supplier_id' => $this->importSupplier->id, 'scope' => 'import']);

        $this->localSupplier = User::factory()->create(['role' => 'supplier', 'is_active' => true, 'account_status' => 'ACTIVE']);
        SupplierScope::query()->where('supplier_id', $this->localSupplier->id)->delete();
        SupplierScope::query()->create(['supplier_id' => $this->localSupplier->id, 'scope' => 'local']);
    }

    public function test_purchasing_po_index_and_datatables_expose_mmt_workspace_and_row_attributes(): void
    {
        $rate = ExchangeRate::create([
            'currency' => 'USD',
            'rate_to_idr' => 16000,
            'valid_from' => BusinessTime::today()->toDateString(),
            'created_by' => $this->purchasing->id,
        ]);

        $po = PurchaseOrder::create([
            'supplier_id' => $this->importSupplier->id,
            'currency' => 'USD',
            'exchange_rate_id' => $rate->id,
            'po_number' => 'PO/MMT/001',
            'status' => 'active',
            'created_by' => $this->purchasing->id,
            'estimated_arrival' => BusinessTime::today()->addDays(10)->toDateString(),
        ]);

        $indexResponse = $this->actingAs($this->purchasing)
            ->get(route('purchasing.purchase-orders.index'));

        $indexResponse->assertOk()
            ->assertSee('id="poMmtWorkspace"', false)
            ->assertSee('id="po-mmt-satellite"', false)
            ->assertSee('data-mmt-satellite', false)
            ->assertDontSee('Ordered Items', false);

        $ajaxResponse = $this->actingAs($this->purchasing)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->getJson(route('purchasing.purchase-orders.index'));

        $ajaxResponse->assertOk();
        $row = collect($ajaxResponse->json('data'))->firstWhere('DT_RowId', $po->getRouteKey());
        $this->assertNotNull($row);
        $this->assertSame(route('purchasing.purchase-orders.satellite', $po, false), $row['DT_RowAttr']['data-mmt-row-url'] ?? null);
        $this->assertSame('PO/MMT/001', $row['DT_RowAttr']['data-mmt-row-ref'] ?? null);
        $this->assertSame('0', $row['DT_RowAttr']['tabindex'] ?? null);
        $this->assertSame('false', $row['DT_RowAttr']['aria-selected'] ?? null);
    }

    public function test_purchasing_po_satellite_renders_all_tabs_enforces_hashids_and_authorizes_actor(): void
    {
        $period = Period::create([
            'name' => 'MMT Period',
            'month' => 11,
            'year' => 2026,
            'status' => 'open',
            'created_by' => $this->purchasing->id,
        ]);
        $rate = ExchangeRate::create([
            'currency' => 'USD',
            'rate_to_idr' => 16000,
            'valid_from' => BusinessTime::today()->toDateString(),
            'created_by' => $this->purchasing->id,
        ]);
        $pr = PurchaseRequisition::create([
            'period_id' => $period->id,
            'created_by' => $this->purchasing->id,
            'pr_number' => 'PR/MMT/001',
            'status' => 'bidding',
        ]);
        $prItem = $pr->items()->create([
            'hs_code' => '7209.16.00',
            'material_name' => 'MMT Alloy Plate',
            'quantity' => 5,
            'shape' => 'Flat',
            'thickness' => 3.0,
            'width' => 1000,
            'length' => 2000,
            'weight_needed' => 120,
        ]);
        $quotation = Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $this->importSupplier->id,
            'exchange_rate_id' => $rate->id,
            'currency' => 'USD',
            'status' => 'accepted',
        ]);
        $quoteItem = $quotation->items()->create([
            'pr_item_id' => $prItem->id,
            'price_per_kg' => 3.25,
            'amount' => 1950,
            'is_available' => true,
            'available_qty' => 5,
            'offered_weight_per_unit' => 120,
        ]);
        $po = PurchaseOrder::create([
            'supplier_id' => $this->importSupplier->id,
            'currency' => 'USD',
            'exchange_rate_id' => $rate->id,
            'po_number' => 'PO/MMT/SAT/001',
            'status' => 'active',
            'created_by' => $this->purchasing->id,
        ]);
        $po->quotations()->attach($quotation->id);

        $shipment = Shipment::create([
            'shipment_number' => 'SHP/MMT/001',
            'supplier_id' => $this->importSupplier->id,
            'status' => Shipment::STATUS_SUBMITTED,
            'shipment_date' => BusinessTime::today()->toDateString(),
            'estimated_arrival_date' => BusinessTime::today()->addDays(5)->toDateString(),
            'created_by' => $this->importSupplier->id,
        ]);
        $shipment->items()->create([
            'purchase_order_id' => $po->id,
            'quotation_item_id' => $quoteItem->id,
            'shipped_qty' => 2,
            'actual_weight_kg' => '240.0000',
        ]);

        $qcUser = User::factory()->create(['role' => 'qc', 'is_active' => true, 'account_status' => 'ACTIVE']);
        $inspection = QcInspection::create([
            'po_id' => $po->id,
            'inspected_by' => $qcUser->id,
            'status' => 'ng',
            'inspected_at' => BusinessTime::now(),
        ]);
        $claim = MaterialClaim::create([
            'inspection_id' => $inspection->id,
            'po_id' => $po->id,
            'submitted_by' => $this->purchasing->id,
            'supplier_id' => $this->importSupplier->id,
            'status' => 'pending',
            'description' => 'Surface scratch on MMT plate',
            'resolution_expected' => 'Replacement lot',
            'deadline' => BusinessTime::today()->addDays(7)->toDateString(),
        ]);

        $headers = [ServerTabsResponse::HEADER => '1', 'Accept' => 'application/json'];

        DB::flushQueryLog();
        DB::enableQueryLog();
        $itemsRes = $this->actingAs($this->purchasing)
            ->withHeaders($headers)
            ->getJson(route('purchasing.purchase-orders.satellite', ['id' => $po, 'tab' => 'items']));
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $itemsRes->assertOk()
            ->assertJsonPath('tab', 'items');
        $this->assertLessThanOrEqual(20, $queryCount);
        $this->assertStringContainsString('MMT Alloy Plate', (string) $itemsRes->json('html'));
        $this->assertStringContainsString(route('purchasing.purchase-orders.show', $po, false), (string) $itemsRes->json('nav'));

        $shipmentsRes = $this->actingAs($this->purchasing)
            ->withHeaders($headers)
            ->getJson(route('purchasing.purchase-orders.satellite', ['id' => $po, 'tab' => 'shipments']));
        $shipmentsRes->assertOk()
            ->assertJsonPath('tab', 'shipments');
        $this->assertStringContainsString('SHP/MMT/001', (string) $shipmentsRes->json('html'));

        $claimsRes = $this->actingAs($this->purchasing)
            ->withHeaders($headers)
            ->getJson(route('purchasing.purchase-orders.satellite', ['id' => $po, 'tab' => 'claims']));
        $claimsRes->assertOk()
            ->assertJsonPath('tab', 'claims');
        $this->assertStringContainsString($claim->claim_number, (string) $claimsRes->json('html'));

        $fallbackRes = $this->actingAs($this->purchasing)
            ->withHeaders($headers)
            ->getJson(route('purchasing.purchase-orders.satellite', ['id' => $po, 'tab' => 'invalid-tab']));
        $fallbackRes->assertOk()->assertJsonPath('tab', 'items');

        // Direct non-fragment visit redirects cleanly to PO Show.
        $this->flushHeaders()
            ->actingAs($this->purchasing)
            ->get(route('purchasing.purchase-orders.satellite', ['id' => $po]))
            ->assertRedirect(route('purchasing.purchase-orders.show', $po));

        // Raw numeric ID returns 404 via DecodeHashids.
        $this->actingAs($this->purchasing)
            ->withHeaders($headers)
            ->getJson('/purchasing/purchase-orders/'.$po->id.'/satellite')
            ->assertNotFound();

        // Unauthorized roles are denied.
        $this->actingAs($this->importSupplier)
            ->withHeaders($headers)
            ->getJson(route('purchasing.purchase-orders.satellite', ['id' => $po]))
            ->assertForbidden();
        $this->actingAs($this->finance)
            ->withHeaders($headers)
            ->getJson(route('purchasing.purchase-orders.satellite', ['id' => $po]))
            ->assertForbidden();
    }

    public function test_local_procurement_mmt_workspace_and_satellite_work_for_finance_and_purchasing(): void
    {
        $localPo = LocalPurchaseOrder::create([
            'po_number' => 'LPO/MMT/001',
            'supplier_id' => $this->localSupplier->id,
            'total_amount' => '25000000.00',
            'currency' => 'IDR',
            'status' => LocalPurchaseOrder::STATUS_OPEN,
            'po_date' => BusinessTime::today()->toDateString(),
            'source' => LocalPurchaseOrder::SOURCE_MANUAL,
            'created_by' => $this->finance->id,
        ]);

        LocalGoodsReceipt::create([
            'local_purchase_order_id' => $localPo->id,
            'gr_number' => 'GR-MMT-001',
            'gr_date' => BusinessTime::today()->toDateString(),
            'qty' => '15.00',
            'uom' => 'pcs',
            'status' => LocalGoodsReceipt::STATUS_AVAILABLE,
            'source' => LocalPurchaseOrder::SOURCE_MANUAL,
            'created_by' => $this->finance->id,
        ]);

        $invoice = LocalInvoice::create([
            'submission_number' => 'SUB/MMT/001',
            'supplier_id' => $this->localSupplier->id,
            'local_purchase_order_id' => $localPo->id,
            'po_number' => $localPo->po_number,
            'submitted_at' => BusinessTime::now(),
            'payment_term_days_snapshot' => 30,
            'invoice_number' => 'INV-MMT-001',
            'invoice_date' => BusinessTime::today()->toDateString(),
            'invoice_amount' => '12000000.00',
            'tax_amount' => '1320000.00',
            'status' => LocalInvoice::STATUS_UNDER_VERIFICATION,
            'revision_number' => 1,
        ]);

        $financeIndex = $this->actingAs($this->finance)->get(route('finance.local-procurement.index'));
        $financeIndex->assertOk()
            ->assertSee('id="localPoMmtWorkspace"', false)
            ->assertSee('id="local-po-mmt-satellite"', false)
            ->assertSee('data-mmt-row-url="'.route('finance.local-procurement.satellite', $localPo, false).'"', false)
            ->assertSee('data-mmt-row-ref="LPO/MMT/001"', false);

        $purchasingIndex = $this->actingAs($this->purchasing)->get(route('purchasing.local-procurement.index'));
        $purchasingIndex->assertOk()
            ->assertSee('data-mmt-row-url="'.route('purchasing.local-procurement.satellite', $localPo, false).'"', false);

        $headers = [ServerTabsResponse::HEADER => '1', 'Accept' => 'application/json'];

        $grRes = $this->actingAs($this->finance)
            ->withHeaders($headers)
            ->getJson(route('finance.local-procurement.satellite', ['purchaseOrder' => $localPo, 'tab' => 'goods-receipts']));
        $grRes->assertOk()->assertJsonPath('tab', 'goods-receipts');
        $this->assertStringContainsString('GR-MMT-001', (string) $grRes->json('html'));

        $invFinanceRes = $this->actingAs($this->finance)
            ->withHeaders($headers)
            ->getJson(route('finance.local-procurement.satellite', ['purchaseOrder' => $localPo, 'tab' => 'invoices']));
        $invFinanceRes->assertOk()->assertJsonPath('tab', 'invoices');
        $this->assertStringContainsString('INV-MMT-001', (string) $invFinanceRes->json('html'));
        $this->assertStringContainsString(route('finance.invoices.show', $invoice, false), (string) $invFinanceRes->json('html'));

        $invPurchasingRes = $this->actingAs($this->purchasing)
            ->withHeaders($headers)
            ->getJson(route('purchasing.local-procurement.satellite', ['purchaseOrder' => $localPo, 'tab' => 'invoices']));
        $invPurchasingRes->assertOk();
        $this->assertStringContainsString(route('purchasing.local-invoices.show', $invoice, false), (string) $invPurchasingRes->json('html'));

        $docsRes = $this->actingAs($this->finance)
            ->withHeaders($headers)
            ->getJson(route('finance.local-procurement.satellite', ['purchaseOrder' => $localPo, 'tab' => 'documents']));
        $docsRes->assertOk()->assertJsonPath('tab', 'documents');

        // Direct hit redirects cleanly to Show.
        $this->flushHeaders()
            ->actingAs($this->finance)
            ->get(route('finance.local-procurement.satellite', ['purchaseOrder' => $localPo]))
            ->assertRedirect(route('finance.local-procurement.show', $localPo));

        // Raw numeric ID returns 404.
        $this->actingAs($this->finance)
            ->withHeaders($headers)
            ->getJson('/finance/local-procurement/'.$localPo->id.'/satellite')
            ->assertNotFound();

        // Supplier denied.
        $this->actingAs($this->localSupplier)
            ->withHeaders($headers)
            ->getJson(route('finance.local-procurement.satellite', ['purchaseOrder' => $localPo]))
            ->assertForbidden();
    }

    public function test_local_supplier_po_mmt_workspace_and_satellite_enforce_isolation_and_accessibility(): void
    {
        $otherLocalSupplier = User::factory()->create(['role' => 'supplier', 'is_active' => true, 'account_status' => 'ACTIVE']);
        SupplierScope::query()->where('supplier_id', $otherLocalSupplier->id)->delete();
        SupplierScope::query()->create(['supplier_id' => $otherLocalSupplier->id, 'scope' => 'local']);

        $ownPo = LocalPurchaseOrder::create([
            'po_number' => 'LPO/SUP/001',
            'supplier_id' => $this->localSupplier->id,
            'total_amount' => '18000000.00',
            'currency' => 'IDR',
            'status' => LocalPurchaseOrder::STATUS_OPEN,
            'po_date' => BusinessTime::today()->toDateString(),
            'source' => LocalPurchaseOrder::SOURCE_MANUAL,
            'created_by' => $this->finance->id,
        ]);

        $otherPo = LocalPurchaseOrder::create([
            'po_number' => 'LPO/OTHER/001',
            'supplier_id' => $otherLocalSupplier->id,
            'total_amount' => '9000000.00',
            'currency' => 'IDR',
            'status' => LocalPurchaseOrder::STATUS_OPEN,
            'po_date' => BusinessTime::today()->toDateString(),
            'source' => LocalPurchaseOrder::SOURCE_MANUAL,
            'created_by' => $this->finance->id,
        ]);

        LocalGoodsReceipt::create([
            'local_purchase_order_id' => $ownPo->id,
            'gr_number' => 'GR-SUP-ACTIVE',
            'gr_date' => BusinessTime::today()->toDateString(),
            'qty' => '10.00',
            'uom' => 'pcs',
            'status' => LocalGoodsReceipt::STATUS_AVAILABLE,
            'source' => LocalPurchaseOrder::SOURCE_MANUAL,
            'created_by' => $this->finance->id,
        ]);

        LocalGoodsReceipt::create([
            'local_purchase_order_id' => $ownPo->id,
            'gr_number' => 'GR-SUP-CANCELLED',
            'gr_date' => BusinessTime::today()->toDateString(),
            'qty' => '99.00',
            'uom' => 'pcs',
            'status' => LocalGoodsReceipt::STATUS_CANCELLED,
            'source' => LocalPurchaseOrder::SOURCE_MANUAL,
            'created_by' => $this->finance->id,
        ]);

        $invoice = LocalInvoice::create([
            'submission_number' => 'SUB/SUP/001',
            'supplier_id' => $this->localSupplier->id,
            'local_purchase_order_id' => $ownPo->id,
            'po_number' => $ownPo->po_number,
            'submitted_at' => BusinessTime::now(),
            'payment_term_days_snapshot' => 30,
            'invoice_number' => 'INV-SUP-001',
            'invoice_date' => BusinessTime::today()->toDateString(),
            'invoice_amount' => '10000000.00',
            'tax_amount' => '1100000.00',
            'status' => LocalInvoice::STATUS_UNDER_VERIFICATION,
            'revision_number' => 1,
        ]);

        $attachment = Attachment::create([
            'attachable_type' => LocalPurchaseOrder::class,
            'attachable_id' => $ownPo->id,
            'file_path' => 'local-po/lpo-sup-001.pdf',
            'file_name' => 'LPO-SUP-001.pdf',
            'file_type' => 'PO_DOCUMENT',
            'uploaded_by' => $this->finance->id,
        ]);

        $indexRes = $this->actingAs($this->localSupplier)
            ->get(route('local-supplier.purchase-orders.index'));

        $indexRes->assertOk()
            ->assertSee('id="supplierLocalPoMmtWorkspace"', false)
            ->assertSee('id="supplier-local-po-mmt-satellite"', false)
            ->assertSee('id="supplier-local-po-mmt-hint"', false)
            ->assertSee('data-mmt-row-url="'.route('local-supplier.purchase-orders.satellite', $ownPo, false).'"', false)
            ->assertSee('data-mmt-row-ref="LPO/SUP/001"', false)
            ->assertSee('aria-controls="supplier-local-po-mmt-satellite"', false)
            ->assertSee('aria-describedby="supplier-local-po-mmt-hint"', false)
            ->assertSee('tabindex="0"', false)
            ->assertSee('PDF<span class="tw:sr-only"> '.__('local_procurement.actions.view_po').' — LPO/SUP/001</span>', false)
            ->assertDontSee('LPO/OTHER/001', false)
            ->assertDontSee('ui-col-data-name', false);

        $headers = [ServerTabsResponse::HEADER => '1', 'Accept' => 'application/json'];

        DB::flushQueryLog();
        DB::enableQueryLog();
        $grRes = $this->actingAs($this->localSupplier)
            ->withHeaders($headers)
            ->getJson(route('local-supplier.purchase-orders.satellite', ['purchaseOrder' => $ownPo, 'tab' => 'goods-receipts']));
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(20, $queryCount);
        $grRes->assertOk()->assertJsonPath('tab', 'goods-receipts');

        $grHtml = (string) $grRes->json('html');
        $grNav = (string) $grRes->json('nav');
        $this->assertStringContainsString('GR-SUP-ACTIVE', $grHtml);
        $this->assertStringNotContainsString('GR-SUP-CANCELLED', $grHtml);
        $this->assertStringContainsString('role="region"', $grHtml);
        $this->assertStringContainsString('tabindex="0"', $grHtml);
        $this->assertStringContainsString('<nav aria-label="', $grNav);
        $this->assertStringContainsString(route('local-supplier.purchase-orders.show', $ownPo, false), $grNav);
        $this->assertStringContainsString(' — LPO/SUP/001</span>', $grNav);
        // Active GR count matches non-cancelled count (1), not 2.
        $this->assertMatchesRegularExpression('/data-tab-name="goods-receipts"[\s\S]*?>\s*1\s*<\/span>/', $grNav);

        $invRes = $this->actingAs($this->localSupplier)
            ->withHeaders($headers)
            ->getJson(route('local-supplier.purchase-orders.satellite', ['purchaseOrder' => $ownPo, 'tab' => 'invoices']));
        $invRes->assertOk()->assertJsonPath('tab', 'invoices');
        $this->assertStringContainsString('INV-SUP-001', (string) $invRes->json('html'));
        $this->assertStringContainsString(route('local-supplier.invoices.show', $invoice, false), (string) $invRes->json('html'));

        $docsRes = $this->actingAs($this->localSupplier)
            ->withHeaders($headers)
            ->getJson(route('local-supplier.purchase-orders.satellite', ['purchaseOrder' => $ownPo, 'tab' => 'documents']));
        $docsRes->assertOk()->assertJsonPath('tab', 'documents');
        $this->assertStringContainsString('LPO-SUP-001.pdf', (string) $docsRes->json('html'));
        $this->assertStringContainsString(route('attachments.show', $attachment, false), (string) $docsRes->json('html'));

        // Unknown tab falls back cleanly to goods-receipts.
        $this->actingAs($this->localSupplier)
            ->withHeaders($headers)
            ->getJson(route('local-supplier.purchase-orders.satellite', ['purchaseOrder' => $ownPo, 'tab' => 'unknown-tab']))
            ->assertOk()
            ->assertJsonPath('tab', 'goods-receipts');

        // Direct navigation without server-tabs header redirects cleanly to Show.
        $this->flushHeaders()
            ->actingAs($this->localSupplier)
            ->get(route('local-supplier.purchase-orders.satellite', ['purchaseOrder' => $ownPo]))
            ->assertRedirect(route('local-supplier.purchase-orders.show', $ownPo));

        // Raw numeric ID returns 404 (hashids enforced).
        $this->actingAs($this->localSupplier)
            ->withHeaders($headers)
            ->getJson('/local-supplier/purchase-orders/'.$ownPo->id.'/satellite')
            ->assertNotFound();

        // Cross-supplier and cross-role isolation.
        $this->actingAs($otherLocalSupplier)
            ->withHeaders($headers)
            ->getJson(route('local-supplier.purchase-orders.satellite', ['purchaseOrder' => $ownPo]))
            ->assertForbidden();

        $this->actingAs($this->localSupplier)
            ->withHeaders($headers)
            ->getJson(route('local-supplier.purchase-orders.satellite', ['purchaseOrder' => $otherPo]))
            ->assertForbidden();

        $this->actingAs($this->importSupplier)
            ->withHeaders($headers)
            ->getJson(route('local-supplier.purchase-orders.satellite', ['purchaseOrder' => $ownPo]))
            ->assertForbidden();

        $this->actingAs($this->finance)
            ->withHeaders($headers)
            ->getJson(route('local-supplier.purchase-orders.satellite', ['purchaseOrder' => $ownPo]))
            ->assertForbidden();
    }
}
