<?php

namespace Tests\Feature\Procurement;

use App\Models\AnnualExchangeRate;
use App\Models\ExchangeRate;
use App\Models\MaterialMaster;
use App\Models\Period;
use App\Models\PrItem;
use App\Models\PrItemAward;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\Shipment;
use App\Models\ShipmentDocument;
use App\Models\User;
use App\Support\BusinessTime;
use Database\Seeders\QuotationHistoryDemoSeeder;
use Database\Seeders\ReferencePriceRulesInitialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InterSupplierReferenceBenchmarkTest extends TestCase
{
    use ProcurementFixtures;
    use RefreshDatabase;

    private MaterialMaster $material;

    private User $secondSupplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurementActors();
        $this->secondSupplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $this->seed(ReferencePriceRulesInitialSeeder::class);

        $this->material = MaterialMaster::create([
            'material_code' => 'SKD11',
            'normalized_code' => 'SKD11',
            'raw_category' => 'Alloy Steel',
            'hs_category' => MaterialMaster::HS_CATEGORY_ALLOY_FLAT_BAR_ROLLED,
            'density_profile' => MaterialMaster::DENSITY_STEEL,
            'manufacturer_scope' => MaterialMaster::MANUFACTURER_DAIDO,
            'is_active' => true,
            'created_by' => $this->purchasing->id,
        ]);

        AnnualExchangeRate::create([
            'year' => 2025,
            'revision' => 1,
            'currency' => 'USD',
            'rate_to_idr' => '15000.0000',
            'created_by' => $this->purchasing->id,
        ]);
        AnnualExchangeRate::create([
            'year' => 2026,
            'revision' => 1,
            'currency' => 'USD',
            'rate_to_idr' => '16000.0000',
            'created_by' => $this->purchasing->id,
        ]);
    }

    public function test_inter_supplier_price_benchmarks_shows_rule_based_reference_price_and_respects_year_filter(): void
    {
        $this->travelTo(BusinessTime::parseDate('2026-11-10')->setTime(10, 0)->utc());

        // 2026 historical PO in 16-50 (thickness 30): 10 kg @ USD 5 = IDR 80,000/kg (16,000 rate).
        $this->createHistoricalPo(thickness: 30, pricePerKg: '5.0000', kg: '10.0000', currency: 'USD', createdAt: '2026-03-10 10:00:00');
        // 2025 historical PO in 16-50 (thickness 40): 10 kg @ USD 4 = IDR 60,000/kg (15,000 rate).
        $this->createHistoricalPo(thickness: 40, pricePerKg: '4.0000', kg: '10.0000', currency: 'USD', createdAt: '2025-07-10 10:00:00');

        // Current PR item has thickness 20 (same 16-50 category, different exact dimension)
        // plus one item in 51-100 with no PO history, plus one unmapped item.
        $period = Period::create([
            'name' => 'Nov 2026',
            'month' => 11,
            'year' => 2026,
            'status' => 'open',
            'created_by' => $this->purchasing->id,
        ]);
        $pr = PurchaseRequisition::create([
            'period_id' => $period->id,
            'created_by' => $this->purchasing->id,
            'pr_number' => 'REQ/11/2026/001',
            'status' => 'bidding',
        ]);
        $matchedItem = PrItem::create([
            'pr_id' => $pr->id,
            'material_master_id' => $this->material->id,
            'material_name' => 'SKD11',
            'quantity' => 1,
            'shape' => PrItem::SHAPE_FLAT,
            'thickness' => 20,
            'width' => 300,
            'length' => 2000,
            'weight_needed' => 94.2,
        ]);
        $noHistoryItem = PrItem::create([
            'pr_id' => $pr->id,
            'material_master_id' => $this->material->id,
            'material_name' => 'SKD11',
            'quantity' => 1,
            'shape' => PrItem::SHAPE_FLAT,
            'thickness' => 80,
            'width' => 300,
            'length' => 2000,
            'weight_needed' => 376.8,
        ]);
        $unmappedItem = PrItem::create([
            'pr_id' => $pr->id,
            'material_master_id' => null,
            'material_name' => 'CUSTOM-UNMAPPED',
            'quantity' => 1,
            'shape' => PrItem::SHAPE_FLAT,
            'thickness' => 20,
            'width' => 300,
            'length' => 2000,
            'weight_needed' => 94.2,
        ]);

        $this->createQuotation($pr, [['price_per_kg' => 5.2], ['price_per_kg' => 5.5], ['price_per_kg' => 5.0]]);
        $this->createQuotation($pr, [['price_per_kg' => 5.4], ['price_per_kg' => 5.6], ['price_per_kg' => 5.1]], Quotation::STATUS_SUBMITTED, $this->secondSupplier);

        // Default (Year: All -> current year 2026 YTD)
        $html2026 = $this->actingAs($this->purchasing)
            ->get(route('purchasing.comparison.inter-supplier', ['pr_id' => $pr->getRouteKey()]))
            ->assertOk()
            ->getContent();

        $matchedCell2026 = $this->referenceCell($html2026, $matchedItem->id);
        $this->assertStringContainsString('IDR 80,000', $matchedCell2026);
        $this->assertStringContainsString('16-50', $matchedCell2026);
        $this->assertStringContainsString('2026', $matchedCell2026);

        $noHistoryCell = $this->referenceCell($html2026, $noHistoryItem->id);
        $this->assertStringContainsString(__('materials.reference_prices.reasons.no_eligible_historical_line'), $noHistoryCell);
        $this->assertStringContainsString('51-100', $noHistoryCell);

        $unmappedCell = $this->referenceCell($html2026, $unmappedItem->id);
        $this->assertStringContainsString(__('materials.reference_prices.reasons.material_unmapped'), $unmappedCell);

        // Filtered to 2025 -> uses 2025 PO and 2025 annual rate (IDR 60,000)
        $html2025 = $this->actingAs($this->purchasing)
            ->get(route('purchasing.comparison.inter-supplier', [
                'pr_id' => $pr->getRouteKey(),
                'benchmark_year' => 2025,
            ]))
            ->assertOk()
            ->getContent();

        $matchedCell2025 = $this->referenceCell($html2025, $matchedItem->id);
        $this->assertStringContainsString('IDR 60,000', $matchedCell2025);
        $this->assertStringContainsString('2025', $matchedCell2025);
    }

    public function test_inter_supplier_reports_excluded_lines_when_annual_rate_is_missing(): void
    {
        $this->travelTo(BusinessTime::parseDate('2026-11-10')->setTime(10, 0)->utc());

        // One convertible USD PO and one JPY PO without an annual JPY rate in 2026.
        $this->createHistoricalPo(thickness: 25, pricePerKg: '5.0000', kg: '10.0000', currency: 'USD', createdAt: '2026-03-10 10:00:00');
        $this->createHistoricalPo(thickness: 30, pricePerKg: '8000.0000', kg: '5.0000', currency: 'JPY', createdAt: '2026-04-10 10:00:00');

        $pr = $this->createRequisition([['quantity' => 1, 'weight_needed' => 50]]);
        $prItem = $pr->items->sole();
        $prItem->forceFill([
            'material_master_id' => $this->material->id,
            'material_name' => 'SKD11',
            'thickness' => 20,
        ])->save();

        $this->createQuotation($pr, [['price_per_kg' => 5.2]]);
        $this->createQuotation($pr, [['price_per_kg' => 5.4]], Quotation::STATUS_SUBMITTED, $this->secondSupplier);

        $html = $this->actingAs($this->purchasing)
            ->get(route('purchasing.comparison.inter-supplier', ['pr_id' => $pr->getRouteKey()]))
            ->assertOk()
            ->getContent();

        $cell = $this->referenceCell($html, $prItem->id);
        $this->assertStringContainsString('IDR 80,000', $cell);
        $this->assertStringContainsString('data-indicator-reference-excluded', $cell);
    }

    public function test_quotation_history_demo_seeder_maps_material_masters_and_populates_reference_prices(): void
    {
        $this->travelTo(BusinessTime::parseDate('2026-11-10')->setTime(10, 0)->utc());

        ExchangeRate::create(['currency' => 'CNY', 'rate_to_idr' => 2250, 'valid_from' => '2026-01-01', 'created_by' => $this->purchasing->id]);
        ExchangeRate::create(['currency' => 'JPY', 'rate_to_idr' => 110, 'valid_from' => '2026-01-01', 'created_by' => $this->purchasing->id]);
        ExchangeRate::create(['currency' => 'IDR', 'rate_to_idr' => 1, 'valid_from' => '2026-01-01', 'created_by' => $this->purchasing->id]);

        foreach ([2025, 2026] as $year) {
            AnnualExchangeRate::firstOrCreate(
                ['year' => $year, 'revision' => 1, 'currency' => 'USD'],
                ['rate_to_idr' => '16000.0000', 'created_by' => $this->purchasing->id]
            );
            AnnualExchangeRate::firstOrCreate(
                ['year' => $year, 'revision' => 1, 'currency' => 'CNY'],
                ['rate_to_idr' => '2250.0000', 'created_by' => $this->purchasing->id]
            );
            AnnualExchangeRate::firstOrCreate(
                ['year' => $year, 'revision' => 1, 'currency' => 'JPY'],
                ['rate_to_idr' => '110.0000', 'created_by' => $this->purchasing->id]
            );
        }

        $this->seed(QuotationHistoryDemoSeeder::class);

        $demoPrIds = DB::table('purchase_requisitions')
            ->where('pr_number', 'like', QuotationHistoryDemoSeeder::PR_PREFIX.'%')
            ->pluck('id');

        $this->assertGreaterThan(0, $demoPrIds->count());
        $this->assertSame(0, DB::table('pr_items')->whereIn('pr_id', $demoPrIds)->whereNull('material_master_id')->count());

        $openPr = PurchaseRequisition::where('pr_number', QuotationHistoryDemoSeeder::PR_PREFIX.'2026/10-OPEN-3')->firstOrFail();

        $html = $this->actingAs($this->purchasing)
            ->get(route('purchasing.comparison.inter-supplier', ['pr_id' => $openPr->getRouteKey()]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-price-indicators', $html);
        $this->assertMatchesRegularExpression(
            '/<table[^>]*data-price-indicators[\s\S]*?<thead[\s\S]*?<th scope="col"[^>]*>'.preg_quote(__('purchasing.copy.material'), '/').'<\/th>[\s\S]*?<th scope="col"[^>]*>'.preg_quote(__('purchasing.copy.dimensions'), '/').'<\/th>[\s\S]*?<th scope="col"[^>]*>'.preg_quote(__('purchasing.copy.supplier'), '/').'<\/th>[\s\S]*?<th scope="col"[^>]*>'.preg_quote(__('purchasing.comparison_analytics.best_current'), '/').'<\/th>/s',
            $html
        );
        $this->assertMatchesRegularExpression('/data-indicator="dimensions">[^<]*[0-9]/', $html);
        $this->assertMatchesRegularExpression('/data-indicator="supplier">[^<—\s][^<]*/', $html);
        $this->assertDoesNotMatchRegularExpression('/data-indicator="best-current">[\s\S]*?·\s*Demo/', $html);
        $this->assertStringNotContainsString(__('materials.reference_prices.reasons.material_unmapped'), $html);
        $this->assertMatchesRegularExpression('/data-indicator="reference">[\s\S]*?IDR [0-9]/', $html);
    }

    public function test_quotation_history_demo_seeder_cleans_dependent_child_rows_on_reseed(): void
    {
        foreach ([2025, 2026] as $year) {
            ExchangeRate::firstOrCreate(
                ['currency' => 'USD', 'valid_from' => "{$year}-01-01"],
                ['rate_to_idr' => '15500.0000', 'created_by' => $this->purchasing->id]
            );
            ExchangeRate::firstOrCreate(
                ['currency' => 'CNY', 'valid_from' => "{$year}-01-01"],
                ['rate_to_idr' => '2250.0000', 'created_by' => $this->purchasing->id]
            );
            ExchangeRate::firstOrCreate(
                ['currency' => 'JPY', 'valid_from' => "{$year}-01-01"],
                ['rate_to_idr' => '110.0000', 'created_by' => $this->purchasing->id]
            );
        }

        // Non-demo award + progress update must remain untouched across demo re-seeds.
        $this->createHistoricalPo(20, '50000.0000', '100.0000', 'IDR', '2026-09-10 10:00:00');
        $nonDemoAward = PrItemAward::query()->latest('id')->firstOrFail();
        $nonDemoProgressId = DB::table('po_item_progress_updates')->insertGetId([
            'pr_item_award_id' => $nonDemoAward->id,
            'status' => 'in_production',
            'supplier_controlled_qty_snapshot' => 1,
            'updated_by' => $this->supplier->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->seed(QuotationHistoryDemoSeeder::class);

        $demoAward = DB::table('pr_item_awards')
            ->whereIn('pr_id', DB::table('purchase_requisitions')->where('pr_number', 'like', QuotationHistoryDemoSeeder::PR_PREFIX.'%')->pluck('id'))
            ->whereNotNull('purchase_order_id')
            ->first();
        $this->assertNotNull($demoAward);

        $oldProgressId = DB::table('po_item_progress_updates')->insertGetId([
            'pr_item_award_id' => $demoAward->id,
            'status' => 'in_production',
            'supplier_controlled_qty_snapshot' => 1,
            'updated_by' => $demoAward->supplier_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $oldEventId = DB::table('pr_item_award_events')->insertGetId([
            'pr_item_award_id' => $demoAward->id,
            'pr_id' => $demoAward->pr_id,
            'pr_item_id' => $demoAward->pr_item_id,
            'quotation_item_id' => $demoAward->quotation_item_id,
            'purchase_order_id' => $demoAward->purchase_order_id,
            'event' => 'awarded',
            'actor_id' => $this->purchasing->id,
            'created_at' => now(),
        ]);
        $oldSnapshotId = DB::table('award_reference_price_snapshots')->insertGetId([
            'pr_item_award_id' => $demoAward->id,
            'purchase_order_id' => $demoAward->purchase_order_id,
            'status' => 'not_available',
            'reason_code' => 'no_reference_price',
            'evaluation_date' => '2026-10-01',
            'po_currency' => 'USD',
            'created_at' => now(),
        ]);
        $oldShipmentId = DB::table('shipments')->insertGetId([
            'shipment_number' => 'SHP-DEMO-TEST-001',
            'supplier_id' => $demoAward->supplier_id,
            'created_by' => $demoAward->supplier_id,
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $oldShipmentItemId = DB::table('shipment_items')->insertGetId([
            'shipment_id' => $oldShipmentId,
            'purchase_order_id' => $demoAward->purchase_order_id,
            'quotation_item_id' => $demoAward->quotation_item_id,
            'pr_item_award_id' => $demoAward->id,
            'shipped_qty' => 1,
            'actual_weight_kg' => 10.5000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $oldShipmentDocId = DB::table('shipment_documents')->insertGetId([
            'shipment_id' => $oldShipmentId,
            'doc_type' => 'invoice',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('shipment_preshipment_inspections')->insert([
            'shipment_id' => $oldShipmentId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $oldQcId = DB::table('qc_inspections')->insertGetId([
            'po_id' => $demoAward->purchase_order_id,
            'shipment_id' => $oldShipmentId,
            'inspected_by' => $this->purchasing->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('qc_items')->insert([
            'inspection_id' => $oldQcId,
            'pr_item_id' => $demoAward->pr_item_id,
            'shipment_item_id' => $oldShipmentItemId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $oldClaimId = DB::table('material_claims')->insertGetId([
            'inspection_id' => $oldQcId,
            'po_id' => $demoAward->purchase_order_id,
            'submitted_by' => $this->purchasing->id,
            'supplier_id' => $demoAward->supplier_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('claim_attachments')->insert([
            'claim_id' => $oldClaimId,
            'file_path' => 'claims/demo-test.pdf',
            'uploaded_by' => $demoAward->supplier_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $oldConversationId = DB::table('conversations')->insertGetId([
            'conversable_type' => PurchaseRequisition::class,
            'conversable_id' => $demoAward->pr_id,
            'purchasing_user_id' => $this->purchasing->id,
            'supplier_user_id' => $demoAward->supplier_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('messages')->insert([
            'conversation_id' => $oldConversationId,
            'sender_id' => $demoAward->supplier_id,
            'body' => 'Demo thread message',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $oldAttachmentId = DB::table('attachments')->insertGetId([
            'attachable_type' => Shipment::class,
            'attachable_id' => $oldShipmentId,
            'file_path' => 'shipments/demo-test.pdf',
            'file_name' => 'demo-test.pdf',
            'uploaded_by' => $demoAward->supplier_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $oldDocAttachmentId = DB::table('attachments')->insertGetId([
            'attachable_type' => ShipmentDocument::class,
            'attachable_id' => $oldShipmentDocId,
            'file_path' => 'shipments/demo-doc.pdf',
            'file_name' => 'demo-doc.pdf',
            'uploaded_by' => $demoAward->supplier_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Re-seeding must succeed without FK 1451 failures and preserve non-demo records.
        $this->seed(QuotationHistoryDemoSeeder::class);

        $this->assertDatabaseMissing('po_item_progress_updates', ['id' => $oldProgressId]);
        $this->assertDatabaseMissing('pr_item_award_events', ['id' => $oldEventId]);
        $this->assertDatabaseMissing('award_reference_price_snapshots', ['id' => $oldSnapshotId]);
        $this->assertDatabaseMissing('shipments', ['id' => $oldShipmentId]);
        $this->assertDatabaseMissing('qc_inspections', ['id' => $oldQcId]);
        $this->assertDatabaseMissing('material_claims', ['id' => $oldClaimId]);
        $this->assertDatabaseMissing('conversations', ['id' => $oldConversationId]);
        $this->assertDatabaseMissing('attachments', ['id' => $oldAttachmentId]);
        $this->assertDatabaseMissing('attachments', ['id' => $oldDocAttachmentId]);
        $this->assertDatabaseMissing('users', ['id' => $demoAward->supplier_id]);

        $this->assertDatabaseHas('po_item_progress_updates', ['id' => $nonDemoProgressId]);
        $this->assertDatabaseHas('pr_item_awards', ['id' => $nonDemoAward->id]);
        $this->assertGreaterThan(0, DB::table('purchase_requisitions')->where('pr_number', 'like', QuotationHistoryDemoSeeder::PR_PREFIX.'%')->count());
    }

    private function referenceCell(string $html, int $prItemId): string
    {
        $pattern = '/<tr[^>]*data-indicator-row="'.preg_quote((string) $prItemId, '/').'"[^>]*>.*?<td[^>]*data-indicator="reference"[^>]*>(.*?)<\/td>/s';
        $this->assertMatchesRegularExpression($pattern, $html);
        preg_match($pattern, $html, $matches);

        return $matches[1] ?? '';
    }

    private function createHistoricalPo(float $thickness, string $pricePerKg, string $kg, string $currency, string $createdAt): void
    {
        $period = Period::firstOrCreate(
            ['year' => (int) substr($createdAt, 0, 4), 'month' => (int) substr($createdAt, 5, 2)],
            ['name' => 'Hist '.substr($createdAt, 0, 7), 'status' => 'closed', 'created_by' => $this->purchasing->id]
        );
        $pr = PurchaseRequisition::create([
            'period_id' => $period->id,
            'created_by' => $this->purchasing->id,
            'pr_number' => 'REQ-HIST-'.uniqid(),
            'status' => 'completed',
        ]);
        $prItem = PrItem::create([
            'pr_id' => $pr->id,
            'material_master_id' => $this->material->id,
            'material_name' => 'SKD11',
            'quantity' => 1,
            'shape' => PrItem::SHAPE_FLAT,
            'thickness' => $thickness,
            'width' => 300,
            'length' => 2000,
            'weight_needed' => (float) $kg,
        ]);

        $rate = ExchangeRate::firstOrCreate(
            ['currency' => $currency],
            ['rate_to_idr' => $currency === 'IDR' ? 1 : 100, 'valid_from' => '2025-01-01', 'created_by' => $this->purchasing->id]
        );
        $quotation = Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $this->supplier->id,
            'currency' => $currency,
            'exchange_rate_id' => $rate->id,
            'status' => Quotation::STATUS_ACCEPTED,
            'submitted_at' => $createdAt,
        ]);
        $qi = $quotation->items()->create([
            'pr_item_id' => $prItem->id,
            'is_available' => true,
            'available_thickness' => $thickness,
            'price_per_kg' => $pricePerKg,
            'amount' => bcmul($pricePerKg, $kg, 4),
            'available_qty' => 1,
            'offered_weight_per_unit' => (float) $kg,
        ]);

        $po = PurchaseOrder::create([
            'supplier_id' => $this->supplier->id,
            'currency' => $currency,
            'exchange_rate_id' => $rate->id,
            'po_number' => 'PO-HIST-'.uniqid(),
            'status' => 'completed',
            'created_by' => $this->purchasing->id,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
        DB::table('purchase_orders')->where('id', $po->id)->update(['created_at' => $createdAt, 'updated_at' => $createdAt]);
        $po->quotations()->attach($quotation->id);

        PrItemAward::create([
            'pr_id' => $pr->id,
            'pr_item_id' => $prItem->id,
            'quotation_id' => $quotation->id,
            'quotation_item_id' => $qi->id,
            'supplier_id' => $this->supplier->id,
            'purchase_order_id' => $po->id,
            'awarded_by' => $this->purchasing->id,
            'awarded_at' => $createdAt,
        ])->forceFill([
            'ordered_qty' => 1,
            'ordered_total_weight_kg' => $kg,
            'ordered_price_per_kg' => $pricePerKg,
            'ordered_amount' => bcmul($pricePerKg, $kg, 4),
        ])->save();
    }
}
