<?php

namespace Tests\Feature;

use App\Exports\RequisitionsExport;
use App\Models\LocalInvoice;
use App\Models\Period;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\Shipment;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Export\ExportOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExportSearchSuggestionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $defaultConnection = (string) config('database.default');
        $databaseName = (string) config("database.connections.{$defaultConnection}.database");
        $this->assertSame('adasi_portal_test', $databaseName);
    }

    public function test_endpoint_enforces_authentication_known_keys_and_role_authorization(): void
    {
        $this->getJson(route('exports.search-suggestions', ['export_key' => 'purchasing.po']))
            ->assertUnauthorized();

        $purchasing = User::factory()->create(['role' => 'purchasing']);
        $supplier = User::factory()->create(['role' => 'supplier']);
        $finance = User::factory()->create(['role' => 'finance']);

        $this->actingAs($purchasing)
            ->getJson(route('exports.search-suggestions', ['export_key' => 'nonexistent.export']))
            ->assertNotFound();

        foreach (['purchasing.po', 'purchasing.quotations', 'purchasing.pr', 'purchasing.shipments', 'finance.local-invoices', 'accounting.local-invoices'] as $internalKey) {
            $this->actingAs($supplier)
                ->getJson(route('exports.search-suggestions', ['export_key' => $internalKey]))
                ->assertForbidden();
        }

        $this->actingAs($finance)
            ->getJson(route('exports.search-suggestions', ['export_key' => 'purchasing.po']))
            ->assertForbidden();

        $this->actingAs($purchasing)
            ->getJson(route('exports.search-suggestions', ['export_key' => 'finance.local-invoices']))
            ->assertForbidden();
    }

    public function test_purchase_order_suggestions_support_preload_contextual_filters_wildcard_escaping_and_supplier_isolation(): void
    {
        $purchasing = User::factory()->create(['role' => 'purchasing']);
        $supplierA = User::factory()->create(['role' => 'supplier', 'name' => 'PT Alpha Metals']);
        $supplierB = User::factory()->create(['role' => 'supplier', 'name' => 'PT Beta Steel']);

        PurchaseOrder::create([
            'supplier_id' => $supplierA->id,
            'created_by' => $purchasing->id,
            'po_number' => 'PO/11/2026/001',
            'currency' => 'USD',
            'status' => 'active',
            'notes' => 'Urgent 100% priority coil',
            'estimated_arrival' => '2026-12-15',
        ]);
        PurchaseOrder::create([
            'supplier_id' => $supplierA->id,
            'created_by' => $purchasing->id,
            'po_number' => 'PO/11/2026/002',
            'currency' => 'USD',
            'status' => 'completed',
            'notes' => 'Standard delivery',
            'estimated_arrival' => '2026-12-20',
        ]);
        PurchaseOrder::create([
            'supplier_id' => $supplierB->id,
            'created_by' => $purchasing->id,
            'po_number' => 'PO/11/2026/003',
            'currency' => 'USD',
            'status' => 'active',
            'notes' => 'Beta batch',
            'estimated_arrival' => '2026-12-25',
        ]);

        // Preload (empty q) returns all 3 POs for purchasing with private no-store headers
        $preload = $this->actingAs($purchasing)
            ->getJson(route('exports.search-suggestions', ['export_key' => 'purchasing.po']))
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $cacheControl = (string) $preload->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);

        // Contextual sibling filters (supplier_id hash + status) narrow suggestions
        $filtered = $this->actingAs($purchasing)
            ->getJson(route('exports.search-suggestions', [
                'export_key' => 'purchasing.po',
                'supplier_id' => $supplierA->getRouteKey(),
                'status' => 'active',
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->json('data.0');

        $this->assertSame('PO/11/2026/001', $filtered['value']);
        $this->assertStringContainsString('PT Alpha Metals', (string) $filtered['sublabel']);

        // Literal % wildcard in q is escaped so '100%' only matches the PO with '100%' in notes
        $this->actingAs($purchasing)
            ->getJson(route('exports.search-suggestions', [
                'export_key' => 'purchasing.po',
                'q' => '100%',
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.value', 'PO/11/2026/001');

        // Supplier tenant isolation: Supplier A never sees Supplier B's PO even if tampering supplier_id
        $supplierResult = $this->actingAs($supplierA)
            ->getJson(route('exports.search-suggestions', [
                'export_key' => 'supplier.po',
                'supplier_id' => $supplierB->getRouteKey(),
            ]))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->json('data');

        $returnedValues = array_column($supplierResult, 'value');
        $this->assertContains('PO/11/2026/001', $returnedValues);
        $this->assertContains('PO/11/2026/002', $returnedValues);
        $this->assertNotContains('PO/11/2026/003', $returnedValues);

        foreach ($supplierResult as $row) {
            $this->assertStringNotContainsString('PT Alpha Metals', (string) ($row['sublabel'] ?? ''));
            $this->assertStringNotContainsString('PT Beta Steel', (string) ($row['sublabel'] ?? ''));
        }

        // Supplier searching foreign supplier name returns zero items
        $this->actingAs($supplierA)
            ->getJson(route('exports.search-suggestions', [
                'export_key' => 'supplier.po',
                'q' => 'Beta Steel',
            ]))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_quotation_and_requisition_suggestions_include_distinct_materials_and_pr_material_filter_parity(): void
    {
        $purchasing = User::factory()->create(['role' => 'purchasing']);
        $supplierA = User::factory()->create(['role' => 'supplier', 'name' => 'Alpha User']);
        Supplier::create(['user_id' => $supplierA->id, 'company_name' => 'PT Alpha Baja']);
        $supplierB = User::factory()->create(['role' => 'supplier', 'name' => 'Beta User']);
        Supplier::create(['user_id' => $supplierB->id, 'company_name' => 'PT Beta Alloy']);

        $periodNov = Period::create(['name' => 'Nov 2026 Batch', 'month' => 11, 'year' => 2026, 'status' => 'open', 'created_by' => $purchasing->id]);
        $periodDec = Period::create(['name' => 'Dec 2026 Batch', 'month' => 12, 'year' => 2026, 'status' => 'open', 'created_by' => $purchasing->id]);

        $prNov = PurchaseRequisition::create(['period_id' => $periodNov->id, 'created_by' => $purchasing->id, 'pr_number' => 'REQ/11/2026/501', 'status' => 'bidding']);
        $itemSpcc1 = $prNov->items()->create(['hs_code' => '7209', 'material_name' => 'JIS G3141 SPCC-SD', 'quantity' => 5, 'shape' => 'Flat', 'weight_needed' => 50]);
        $itemSpccDup = $prNov->items()->create(['hs_code' => '7209', 'material_name' => 'jis g3141 spcc-sd', 'quantity' => 3, 'shape' => 'Coil', 'weight_needed' => 30]);
        $itemSecret = $prNov->items()->create(['hs_code' => '7219', 'material_name' => 'SUS316 Secret Alloy', 'quantity' => 2, 'shape' => 'Plate', 'weight_needed' => 20]);

        $prDec = PurchaseRequisition::create(['period_id' => $periodDec->id, 'created_by' => $purchasing->id, 'pr_number' => 'REQ/12/2026/502', 'status' => 'bidding']);
        $prDec->items()->create(['hs_code' => '7208', 'material_name' => 'SGHC Galvanized', 'quantity' => 4, 'shape' => 'Coil', 'weight_needed' => 40]);

        $quoteA = Quotation::create(['pr_id' => $prNov->id, 'supplier_id' => $supplierA->id, 'currency' => 'USD', 'status' => Quotation::STATUS_SUBMITTED, 'submitted_at' => '2026-11-12 09:00:00']);
        $quoteA->items()->create(['pr_item_id' => $itemSpcc1->id, 'price_per_kg' => 10, 'amount' => 500, 'is_available' => true]);
        $quoteA->items()->create(['pr_item_id' => $itemSpccDup->id, 'price_per_kg' => 10, 'amount' => 300, 'is_available' => true]);

        $quoteB = Quotation::create(['pr_id' => $prNov->id, 'supplier_id' => $supplierB->id, 'currency' => 'USD', 'status' => Quotation::STATUS_SUBMITTED, 'submitted_at' => '2026-11-12 10:00:00']);
        $quoteB->items()->create(['pr_item_id' => $itemSecret->id, 'price_per_kg' => 25, 'amount' => 500, 'is_available' => true]);

        // Purchasing quotations: sees PR + both distinct materials (SPCC-SD deduplicated case-insensitively)
        $purchasingQuotes = $this->actingAs($purchasing)
            ->getJson(route('exports.search-suggestions', [
                'export_key' => 'purchasing.quotations',
                'period_id' => $periodNov->id,
            ]))
            ->assertOk()
            ->json('data');

        $purchasingValues = array_column($purchasingQuotes, 'value');
        $this->assertContains('REQ/11/2026/501', $purchasingValues);
        $this->assertContains('SUS316 Secret Alloy', $purchasingValues);
        $spccMatches = array_filter($purchasingValues, fn (string $v) => strcasecmp($v, 'JIS G3141 SPCC-SD') === 0);
        $this->assertCount(1, $spccMatches, 'Material suggestions must be deduplicated case-insensitively');

        // Supplier A quotations: sees own PR & SPCC-SD, NEVER sees Supplier B's SUS316 Secret Alloy or Beta Alloy
        $supplierQuotes = $this->actingAs($supplierA)
            ->getJson(route('exports.search-suggestions', [
                'export_key' => 'supplier.quotations',
                'supplier_id' => $supplierB->getRouteKey(),
            ]))
            ->assertOk()
            ->json('data');

        $supplierValues = array_column($supplierQuotes, 'value');
        $this->assertContains('REQ/11/2026/501', $supplierValues);
        $this->assertNotContains('SUS316 Secret Alloy', $supplierValues);
        foreach ($supplierQuotes as $item) {
            $this->assertStringNotContainsString('Beta Alloy', (string) ($item['sublabel'] ?? ''));
            $this->assertStringNotContainsString('Alpha Baja', (string) ($item['sublabel'] ?? ''));
        }

        // Purchasing PR suggestions scoped to Dec period only returns Dec PR & SGHC Galvanized
        $prSuggestions = $this->actingAs($purchasing)
            ->getJson(route('exports.search-suggestions', [
                'export_key' => 'purchasing.pr',
                'period_id' => $periodDec->id,
            ]))
            ->assertOk()
            ->json('data');

        $prValues = array_column($prSuggestions, 'value');
        $this->assertContains('REQ/12/2026/502', $prValues);
        $this->assertContains('SGHC Galvanized', $prValues);
        $this->assertNotContains('REQ/11/2026/501', $prValues);

        // End-to-end PR material filter parity: preview-count and RequisitionsExport match PRs by selected material_name
        $this->actingAs($purchasing)
            ->postJson(route('exports.preview-count'), [
                'export_key' => 'purchasing.pr',
                'search' => 'SGHC Galvanized',
            ])
            ->assertOk()
            ->assertJsonPath('total_rows', 1);

        $prExport = new RequisitionsExport(null, null, 'SGHC Galvanized');
        $prExport->applyOptions(new ExportOptions(['pr_number'], 'xlsx', 'purchasing'));
        $this->assertSame(1, $prExport->progressTotalRows());
        $matchedItem = $prExport->query()->firstOrFail();
        $this->assertSame('REQ/12/2026/502', $matchedItem->purchaseRequisition->pr_number);
        $this->assertSame('SGHC Galvanized', $matchedItem->material_name);
    }

    public function test_shipment_and_local_invoice_suggestions_return_documents_materials_and_report_scoped_invoices(): void
    {
        $purchasing = User::factory()->create(['role' => 'purchasing']);
        $finance = User::factory()->create(['role' => 'finance']);
        $accounting = User::factory()->create(['role' => 'accounting']);
        $supplierA = User::factory()->create(['role' => 'supplier', 'name' => 'Supplier One']);
        $supplierB = User::factory()->create(['role' => 'supplier', 'name' => 'Supplier Two']);

        $period = Period::create(['name' => 'Ship Period', 'month' => 11, 'year' => 2026, 'status' => 'open', 'created_by' => $purchasing->id]);
        $pr = PurchaseRequisition::create(['period_id' => $period->id, 'created_by' => $purchasing->id, 'pr_number' => 'REQ/11/2026/900', 'status' => 'completed']);
        $prItem = $pr->items()->create(['hs_code' => '7209', 'material_name' => 'SPHC Pickled Coil', 'quantity' => 2, 'shape' => 'Coil', 'weight_needed' => 20]);
        $quote = Quotation::create(['pr_id' => $pr->id, 'supplier_id' => $supplierA->id, 'currency' => 'USD', 'status' => Quotation::STATUS_ACCEPTED, 'submitted_at' => '2026-11-01 08:00:00']);
        $quoteItem = $quote->items()->create(['pr_item_id' => $prItem->id, 'price_per_kg' => 15, 'amount' => 300, 'is_available' => true]);
        $po = PurchaseOrder::create(['supplier_id' => $supplierA->id, 'created_by' => $purchasing->id, 'po_number' => 'PO/11/2026/900', 'currency' => 'USD', 'status' => 'active']);

        $shipmentA = Shipment::create([
            'shipment_number' => 'SHP/11/2026/001',
            'supplier_id' => $supplierA->id,
            'created_by' => $supplierA->id,
            'status' => Shipment::STATUS_SUBMITTED,
            'shipment_date' => '2026-11-15',
            'invoice_number' => 'INV-SHP-001',
        ]);
        $shipmentA->items()->create([
            'purchase_order_id' => $po->id,
            'quotation_item_id' => $quoteItem->id,
            'shipped_qty' => 2,
            'actual_weight_kg' => 20,
        ]);

        Shipment::create([
            'shipment_number' => 'SHP/11/2026/002',
            'supplier_id' => $supplierB->id,
            'created_by' => $supplierB->id,
            'status' => Shipment::STATUS_SUBMITTED,
            'shipment_date' => '2026-11-16',
        ]);

        $purchasingShipments = $this->actingAs($purchasing)
            ->getJson(route('exports.search-suggestions', ['export_key' => 'purchasing.shipments']))
            ->assertOk()
            ->json('data');

        $shipValues = array_column($purchasingShipments, 'value');
        $this->assertContains('SHP/11/2026/001', $shipValues);
        $this->assertContains('SHP/11/2026/002', $shipValues);
        $this->assertContains('SPHC Pickled Coil', $shipValues);

        $supplierShipments = $this->actingAs($supplierA)
            ->getJson(route('exports.search-suggestions', ['export_key' => 'supplier.shipments']))
            ->assertOk()
            ->json('data');

        $supplierShipValues = array_column($supplierShipments, 'value');
        $this->assertContains('SHP/11/2026/001', $supplierShipValues);
        $this->assertContains('SPHC Pickled Coil', $supplierShipValues);
        $this->assertNotContains('SHP/11/2026/002', $supplierShipValues);

        // Local Invoices: READY_TO_PAY vs WAITING_PHYSICAL_DOCUMENT
        LocalInvoice::create([
            'supplier_id' => $supplierA->id,
            'submission_number' => 'LSI-2026-001',
            'invoice_number' => 'INV-LOCAL-001',
            'invoice_date' => '2026-11-10',
            'submitted_at' => '2026-11-10 10:00:00',
            'po_number' => 'LPO-001',
            'currency' => 'IDR',
            'invoice_amount' => '1500000.00',
            'tax_amount' => '165000.00',
            'status' => LocalInvoice::STATUS_READY_TO_PAY,
            'due_date' => '2026-12-10',
        ]);
        LocalInvoice::create([
            'supplier_id' => $supplierB->id,
            'submission_number' => 'LSI-2026-002',
            'invoice_number' => 'INV-LOCAL-002',
            'invoice_date' => '2026-11-11',
            'submitted_at' => '2026-11-11 10:00:00',
            'po_number' => 'LPO-002',
            'currency' => 'IDR',
            'invoice_amount' => '2500000.00',
            'tax_amount' => '275000.00',
            'status' => LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT,
            'due_date' => '2026-12-11',
        ]);

        $financeInvoices = $this->actingAs($finance)
            ->getJson(route('exports.search-suggestions', ['export_key' => 'finance.local-invoices']))
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $financeInvoices);

        // Accounting payments report only includes payment-ready/paid invoices
        $accountingPayments = $this->actingAs($accounting)
            ->getJson(route('exports.search-suggestions', [
                'export_key' => 'accounting.local-invoices',
                'report' => 'payments',
            ]))
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $accountingPayments);
        $this->assertSame('INV-LOCAL-001', $accountingPayments[0]['value']);
    }
}
