<?php

namespace Tests\Feature\Procurement;

use App\Exports\PurchasingHistoricalPriceExport;
use App\Models\AnnualExchangeRate;
use App\Models\AwardReferencePriceSnapshot;
use App\Models\ExchangeRate;
use App\Models\MaintenanceRecord;
use App\Models\MasterDataAuditLog;
use App\Models\MaterialMaster;
use App\Models\MaterialReferencePrice;
use App\Models\Period;
use App\Models\PrItemAward;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\ReferencePriceRule;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Pricing\HistoricalReferencePriceService;
use App\Support\BusinessTime;
use App\Support\Export\ExportDefinitions;
use App\Support\Export\ExportOptions;
use Carbon\Carbon;
use Database\Seeders\ReferencePriceRulesInitialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PortalUpdateNovember2026Test extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $purchasing;

    private User $supplier;

    private User $qc;

    private Period $period;

    private ExchangeRate $usdRate;

    private MaterialMaster $skd11;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->travelTo('2026-11-26 10:00:00');

        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $this->qc = User::factory()->create(['role' => 'qc', 'is_active' => true]);
        $this->supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        Supplier::create(['user_id' => $this->supplier->id, 'company_name' => 'PT Test Steel']);

        $this->period = Period::create([
            'name' => 'Nov 2026',
            'month' => 11,
            'year' => 2026,
            'status' => 'open',
            'created_by' => $this->purchasing->id,
        ]);

        $this->usdRate = ExchangeRate::create([
            'currency' => 'USD',
            'rate_to_idr' => 16000,
            'valid_from' => '2025-01-01',
            'created_by' => $this->purchasing->id,
        ]);

        foreach ([2024 => 15000, 2025 => 15500, 2026 => 16000] as $year => $rate) {
            AnnualExchangeRate::create([
                'year' => $year,
                'revision' => 1,
                'currency' => 'USD',
                'rate_to_idr' => $rate,
                'created_by' => $this->purchasing->id,
            ]);
        }

        $this->skd11 = MaterialMaster::create([
            'material_code' => 'SKD11',
            'normalized_code' => 'SKD11',
            'raw_category' => 'Tool Steel',
            'hs_category' => 'alloy_steel',
            'density_profile' => 'steel',
            'manufacturer_scope' => 'unknown',
            'is_active' => true,
        ]);

        $this->seed(ReferencePriceRulesInitialSeeder::class);
    }

    public function test_r1_inter_supplier_benchmarks_show_best_and_average_and_honor_year_month_and_all_years(): void
    {
        // Historical PO in 2025-03 at USD 10/kg (= 155,000 IDR at 2025 annual rate), thickness 10mm (rule 1-15)
        $this->createHistoryPo('SKD11', 'Flat', ['thickness' => 10, 'width' => 100, 'length' => 200], 10, 'USD', '2025-03-10 08:00:00', $this->skd11->id);
        // Historical PO in 2026-03 at USD 12/kg (= 192,000 IDR at 2026 annual rate), thickness 12mm (rule 1-15)
        $this->createHistoryPo('SKD11', 'Flat', ['thickness' => 12, 'width' => 100, 'length' => 200], 12, 'USD', '2026-03-15 08:00:00', $this->skd11->id);
        // Historical PO in 2026-08 at USD 14/kg (= 224,000 IDR at 2026 annual rate), thickness 14mm (rule 1-15)
        $this->createHistoryPo('SKD11', 'Flat', ['thickness' => 14, 'width' => 100, 'length' => 200], 14, 'USD', '2026-08-15 08:00:00', $this->skd11->id);

        // Current PR for Inter-Supplier comparison
        $pr = PurchaseRequisition::create([
            'period_id' => $this->period->id,
            'created_by' => $this->purchasing->id,
            'pr_number' => 'REQ/R1/001',
            'status' => 'bidding',
        ]);
        $item = $pr->items()->create([
            'material_master_id' => $this->skd11->id,
            'material_name' => 'SKD11',
            'shape' => 'Flat',
            'thickness' => 10,
            'width' => 100,
            'length' => 200,
            'quantity' => 1,
            'weight_needed' => 10,
        ]);
        $quote = Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $this->supplier->id,
            'exchange_rate_id' => $this->usdRate->id,
            'currency' => 'USD',
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
        $quote->items()->create([
            'pr_item_id' => $item->id,
            'is_available' => true,
            'price_per_kg' => 13,
            'amount' => 130,
        ]);

        // Default (All Years): aggregates 2025 + 2026 using each PO year's annual rate
        $service = app(HistoricalReferencePriceService::class);
        $allYearsCalc = $service->calculateForPrItem($item, targetCurrency: 'IDR', allYears: true);
        $this->assertTrue($allYearsCalc->isMatched());
        $this->assertEqualsWithDelta(155000.0, (float) $allYearsCalc->lowestPricePerKg, 0.01);
        $this->assertEqualsWithDelta((155000 + 192000 + 224000) / 3, (float) $allYearsCalc->weightedAveragePricePerKg, 0.01);

        // PO-finalization default (allYears = false) still scopes strictly to current YTD (2026 only)
        $ytdCalc = $service->calculateForPrItem($item, targetCurrency: 'IDR', allYears: false);
        $this->assertTrue($ytdCalc->isMatched());
        $this->assertEqualsWithDelta(192000.0, (float) $ytdCalc->lowestPricePerKg, 0.01);

        // Month filter across All Years (March = 2025-03 + 2026-03)
        $marchAllYears = $service->calculateForPrItem($item, targetCurrency: 'IDR', benchmarkMonth: 3, allYears: true);
        $this->assertTrue($marchAllYears->isMatched());
        $this->assertEqualsWithDelta(155000.0, (float) $marchAllYears->lowestPricePerKg, 0.01);
        $this->assertEqualsWithDelta((155000 + 192000) / 2, (float) $marchAllYears->weightedAveragePricePerKg, 0.01);

        // UI asserts both Best Price Reference and Price Reference Average columns
        $this->actingAs($this->purchasing)
            ->get(route('purchasing.comparison.inter-supplier', ['pr_id' => $pr->getRouteKey(), 'benchmark_year' => 2026, 'benchmark_month' => 3]))
            ->assertOk()
            ->assertSee(__('purchasing.comparison_analytics.best_price_reference'))
            ->assertSee(__('purchasing.comparison_analytics.price_reference_average'))
            ->assertSee('data-indicator="best-price-reference"', false)
            ->assertSee('IDR 192,000');
    }

    public function test_r2_reference_price_category_delete_enforces_auth_audits_resequences_and_preserves_snapshots(): void
    {
        $rule = ReferencePriceRule::where('category_key', '16-50')->firstOrFail();

        // Non-authorized roles get 403
        $this->actingAs($this->supplier)
            ->deleteJson(route('purchasing.master-data.reference-prices.rules.category.destroy', $rule))
            ->assertForbidden();
        $this->actingAs($this->qc)
            ->deleteJson(route('purchasing.master-data.reference-prices.rules.category.destroy', $rule))
            ->assertForbidden();

        // Create an award snapshot referencing the category_key to verify it is untouched on delete
        $pr = PurchaseRequisition::create([
            'period_id' => $this->period->id,
            'created_by' => $this->purchasing->id,
            'pr_number' => 'REQ/R2/SNAP',
            'status' => 'completed',
        ]);
        $item = $pr->items()->create([
            'material_name' => 'SKD11',
            'shape' => 'Flat',
            'thickness' => 25,
            'width' => 100,
            'length' => 200,
            'quantity' => 1,
            'weight_needed' => 10,
        ]);
        $quote = Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $this->supplier->id,
            'exchange_rate_id' => $this->usdRate->id,
            'currency' => 'USD',
            'status' => 'accepted',
            'submitted_at' => now(),
        ]);
        $qItem = $quote->items()->create(['pr_item_id' => $item->id, 'is_available' => true, 'price_per_kg' => 10, 'amount' => 100]);
        $po = PurchaseOrder::create([
            'supplier_id' => $this->supplier->id,
            'currency' => 'USD',
            'exchange_rate_id' => $this->usdRate->id,
            'po_number' => 'PO/R2/SNAP',
            'status' => 'completed',
            'created_by' => $this->purchasing->id,
        ]);
        $po->quotations()->attach($quote->id);
        $award = PrItemAward::create([
            'pr_id' => $pr->id,
            'pr_item_id' => $item->id,
            'quotation_id' => $quote->id,
            'quotation_item_id' => $qItem->id,
            'supplier_id' => $this->supplier->id,
            'purchase_order_id' => $po->id,
            'awarded_by' => $this->purchasing->id,
            'awarded_at' => now(),
        ]);
        $legacyRef = MaterialReferencePrice::create([
            'material_master_id' => $this->skd11->id,
            'shape' => 'Flat',
            'dimension_field' => 'thickness',
            'dimension_min' => 16,
            'dimension_max' => 50,
            'currency' => 'IDR',
            'price_per_kg' => 180000,
            'valid_from' => '2026-01-01',
            'status' => 'active',
            'version_no' => 1,
            'created_by' => $this->purchasing->id,
        ]);
        $snapshot = AwardReferencePriceSnapshot::create([
            'pr_item_award_id' => $award->id,
            'purchase_order_id' => $po->id,
            'material_reference_price_id' => $legacyRef->id,
            'status' => AwardReferencePriceSnapshot::STATUS_MATCHED,
            'evaluation_date' => '2026-11-26',
            'dimension_field' => 'thickness',
            'dimension_value' => 25,
            'reference_currency' => 'IDR',
            'reference_price_per_kg' => 180000,
            'po_currency' => 'USD',
            'converted_reference_price_per_kg' => 11.25,
            'offer_price_per_kg' => 10,
            'diff_amount_per_kg' => -1.25,
            'diff_percent' => -11.11,
        ]);

        // Purchasing deletes rule via JSON
        $ruleId = $rule->id;
        $initialCount = ReferencePriceRule::count();
        $this->actingAs($this->purchasing)
            ->deleteJson(route('purchasing.master-data.reference-prices.rules.category.destroy', $rule))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('reference_price_rules', ['id' => $ruleId]);
        $this->assertSame($initialCount - 1, ReferencePriceRule::count());

        // Sort orders are cleanly resequenced 1..N
        $orders = ReferencePriceRule::query()->orderBy('sort_order')->pluck('sort_order')->all();
        $this->assertSame(range(1, $initialCount - 1), $orders);

        // Audit log recorded
        $this->assertDatabaseHas('master_data_audit_logs', [
            'entity_type' => MasterDataAuditLog::ENTITY_REFERENCE_PRICE_RULE,
            'entity_id' => $ruleId,
            'action' => MasterDataAuditLog::DELETED,
            'actor_id' => $this->purchasing->id,
        ]);

        // Snapshot untouched
        $this->assertDatabaseHas('award_reference_price_snapshots', ['id' => $snapshot->id, 'dimension_value' => 25]);

        // Admin route also works via redirect
        $nextRule = ReferencePriceRule::orderBy('sort_order')->firstOrFail();
        $this->actingAs($this->admin)
            ->delete(route('admin.reference-prices.rules.category.destroy', $nextRule))
            ->assertRedirect(route('admin.reference-prices.index', ['tab' => 'rules']))
            ->assertSessionHas('success');
        $this->assertDatabaseMissing('reference_price_rules', ['id' => $nextRule->id]);
    }

    public function test_r5_vs_best_includes_dimensions_display_offered_diff_and_keyword_search(): void
    {
        $this->createHistoryPo('HOLLOW-MAT', 'Hollow', ['d_outer' => 60, 'd_inner' => 40, 'length' => 200], 8, 'USD', '2026-02-01 08:00:00');
        $this->createHistoryPo('FLAT-MAT', 'Flat', ['thickness' => 12, 'width' => 120, 'length' => 300], 9, 'USD', '2026-02-01 08:00:00');

        $pr = PurchaseRequisition::create([
            'period_id' => $this->period->id,
            'created_by' => $this->purchasing->id,
            'pr_number' => 'REQ/R5/001',
            'status' => 'bidding',
        ]);
        $hollowItem = $pr->items()->create([
            'material_name' => 'HOLLOW-MAT',
            'shape' => 'Hollow',
            'd_outer' => 60,
            'd_inner' => 40,
            'length' => 200,
            'quantity' => 2,
            'weight_needed' => 5,
        ]);
        $flatItem = $pr->items()->create([
            'material_name' => 'FLAT-MAT',
            'shape' => 'Flat',
            'thickness' => 12,
            'width' => 150,
            'length' => 300,
            'quantity' => 2,
            'weight_needed' => 5,
        ]);

        $quote = Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $this->supplier->id,
            'exchange_rate_id' => $this->usdRate->id,
            'currency' => 'USD',
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
        // Hollow offered with different OD (65 instead of 60) -> triggers "Offered:" sublabel
        $quote->items()->create([
            'pr_item_id' => $hollowItem->id,
            'is_available' => true,
            'available_d_outer' => 65,
            'available_d_inner' => 40,
            'available_length' => 200,
            'price_per_kg' => 10,
            'amount' => 100,
        ]);
        $quote->items()->create([
            'pr_item_id' => $flatItem->id,
            'is_available' => true,
            'price_per_kg' => 11,
            'amount' => 110,
        ]);

        $resp = $this->actingAs($this->purchasing)
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->getJson(route('purchasing.comparison.vs-best.data', [
                'draw' => 1,
                'start' => 0,
                'length' => 25,
                'currency' => 'USD',
            ]))
            ->assertOk();

        $rows = collect($resp->json('data'));
        $hollowRow = $rows->first(fn (array $r) => str_contains($r['material_display'], 'HOLLOW-MAT'));
        $flatRow = $rows->first(fn (array $r) => str_contains($r['material_display'], 'FLAT-MAT'));

        $this->assertNotNull($hollowRow);
        $this->assertStringContainsString('60', $hollowRow['dimensions_display']);
        $this->assertStringContainsString('40', $hollowRow['dimensions_display']);
        $this->assertStringContainsString('65', $hollowRow['dimensions_display'], 'Different offered dimension appears as sublabel.');

        $this->assertNotNull($flatRow);
        $this->assertStringContainsString('12', $flatRow['dimensions_display']);
        $this->assertStringContainsString('150', $flatRow['dimensions_display']);

        // Keyword search by shape filters strictly to Hollow rows (2 of 4 total rows)
        $searchResp = $this->actingAs($this->purchasing)
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->getJson(route('purchasing.comparison.vs-best.data', [
                'draw' => 2,
                'start' => 0,
                'length' => 25,
                'currency' => 'USD',
                'search' => ['value' => 'Hollow', 'regex' => 'false'],
            ]))
            ->assertOk();

        $this->assertSame(2, $searchResp->json('recordsFiltered'));
        foreach ($searchResp->json('data') as $row) {
            $this->assertStringContainsString('HOLLOW-MAT', $row['material_display']);
        }

        // Keyword search by numeric dimension (width 150) matches only the current FLAT-MAT item
        $dimSearchResp = $this->actingAs($this->purchasing)
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->getJson(route('purchasing.comparison.vs-best.data', [
                'draw' => 3,
                'start' => 0,
                'length' => 25,
                'currency' => 'USD',
                'search' => ['value' => '150', 'regex' => 'false'],
            ]))
            ->assertOk();

        $this->assertSame(1, $dimSearchResp->json('recordsFiltered'));
        $this->assertStringContainsString('FLAT-MAT', $dimSearchResp->json('data.0.material_display'));
    }

    public function test_r6_and_r4_kpi_historical_multi_select_separates_per_material_kpis_and_redesigned_yearly_rows(): void
    {
        // Material A across 3 years (2024, 2025, 2026) -> >= 3 periods
        $this->createHistoryPo('MAT-ALPHA', 'Flat', ['thickness' => 10, 'width' => 100, 'length' => 200], 10, 'USD', '2024-06-01 08:00:00');
        $this->createHistoryPo('MAT-ALPHA', 'Flat', ['thickness' => 10, 'width' => 100, 'length' => 200], 12, 'USD', '2025-06-01 08:00:00');
        $this->createHistoryPo('MAT-ALPHA', 'Flat', ['thickness' => 10, 'width' => 100, 'length' => 200], 15, 'USD', '2026-06-01 08:00:00');
        // Material B across 2 years (2024, 2026) -> null gap in 2025
        $this->createHistoryPo('MAT-BETA', 'Round', ['d_outer' => 50, 'length' => 200], 20, 'USD', '2024-06-01 08:00:00');
        $this->createHistoryPo('MAT-BETA', 'Round', ['d_outer' => 50, 'length' => 200], 18, 'USD', '2026-06-01 08:00:00');

        $payload = $this->actingAs($this->purchasing)
            ->getJson(route('purchasing.comparison.historical', [
                'materials' => ['MAT-ALPHA|Flat', 'MAT-BETA|Round'],
                'period_view' => 'yearly',
                'currency' => 'USD',
                'view' => 'json',
            ]))
            ->assertOk()
            ->json();

        $this->assertTrue($payload['chartData']['isMultiMaterial']);
        $this->assertSame(3, $payload['chartData']['periodCount']);
        $this->assertSame(['2024', '2025', '2026'], $payload['chartData']['labels']);
        $this->assertCount(2, $payload['chartData']['series']);
        $this->assertNull($payload['chartData']['series'][1]['pricesIdr'][1], 'Missing 2025 for MAT-BETA stays null.');

        // Per-material KPIs stay strictly separated (never averaged across distinct materials)
        $this->assertTrue($payload['summary']['is_multi']);
        $this->assertNull($payload['summary']['weighted_average']);
        $this->assertCount(2, $payload['summary']['per_material']);
        $this->assertSame('10.0000', $payload['summary']['per_material'][0]['best']);
        $this->assertSame('18.0000', $payload['summary']['per_material'][1]['best']);

        // Redesigned yearly supporting data rows: Year x Material with per-material YoY %
        $this->assertCount(5, $payload['tableData']);
        $beta2026 = collect($payload['tableData'])->first(fn ($r) => $r['material_name'] === 'MAT-BETA' && $r['period'] === '2026');
        $this->assertNotNull($beta2026);
        $this->assertEqualsWithDelta(-10.0, (float) $beta2026['change_pct'], 0.001, 'YoY change for MAT-BETA compares 18 vs 20 (-10%), never MAT-ALPHA.');
        $this->assertSame(1, $beta2026['po_count']);
        $this->assertSame('mixed', $payload['keyDimension']['field']);
    }

    public function test_r4_tier1_quick_and_advanced_export_preview_presets_sanitization_and_maintenance(): void
    {
        Queue::fake();

        // Include formula-injection attempt in material name to verify SpreadsheetCellSanitizer
        $this->createHistoryPo('=SUM(1,1)', 'Flat', ['thickness' => 10, 'width' => 100, 'length' => 200], 10, 'USD', '2025-05-01 08:00:00');
        $this->createHistoryPo('=SUM(1,1)', 'Flat', ['thickness' => 10, 'width' => 100, 'length' => 200], 12, 'USD', '2026-05-01 08:00:00');

        // 1. Definition endpoint
        $this->actingAs($this->purchasing)
            ->getJson(route('exports.definitions.show', 'purchasing.price-history'))
            ->assertOk()
            ->assertJsonPath('export_key', 'purchasing.price-history')
            ->assertJsonPath('supports_columns', true)
            ->assertJsonPath('supports_presets', true);

        // Supplier denied
        $this->actingAs($this->supplier)
            ->getJson(route('exports.definitions.show', 'purchasing.price-history'))
            ->assertNotFound();

        // 2. Preview count (monthly = 2 rows, yearly = 2 rows)
        $this->actingAs($this->purchasing)
            ->postJson(route('exports.preview-count'), [
                'export_key' => 'purchasing.price-history',
                'materials' => ['=SUM(1,1)|Flat'],
                'period_view' => 'monthly',
                'currency' => 'USD',
            ])
            ->assertOk()
            ->assertJsonPath('total_rows', 2)
            ->assertJsonPath('is_empty', false);

        // 3. Raw integer supplier_id rejected with 404 (anti-IDOR)
        $this->actingAs($this->purchasing)
            ->postJson(route('purchasing.export.price-history'), [
                'supplier_id' => (string) $this->supplier->id,
                'options' => ['columns' => ['material_name', 'converted_price_per_kg'], 'format' => 'xlsx'],
            ])
            ->assertNotFound();

        // 4. Async dispatch accepted for Purchasing (202)
        $this->actingAs($this->purchasing)
            ->postJson(route('purchasing.export.price-history'), [
                'supplier_id' => $this->supplier->getRouteKey(),
                'materials' => ['=SUM(1,1)|Flat'],
                'period_view' => 'monthly',
                'currency' => 'USD',
                'options' => ['columns' => ['material_name', 'converted_price_per_kg', 'period_change_pct'], 'format' => 'csv', 'csv_delimiter' => ';'],
            ])
            ->assertStatus(202)
            ->assertJsonStructure(['export_job_id', 'status_url']);

        $this->assertDatabaseHas('export_jobs', [
            'user_id' => $this->purchasing->id,
            'export_class' => PurchasingHistoricalPriceExport::class,
            'format' => 'csv',
        ]);

        // Supplier denied export dispatch
        $this->actingAs($this->supplier)
            ->postJson(route('purchasing.export.price-history'), [
                'options' => ['columns' => ['material_name'], 'format' => 'xlsx'],
            ])
            ->assertForbidden();

        // 5. SpreadsheetCellSanitizer neutralizes formula payload in exported collection
        $export = new PurchasingHistoricalPriceExport([
            'materials' => ['=SUM(1,1)|Flat'],
            'period_view' => 'monthly',
            'currency' => 'USD',
            'range' => 'all',
        ]);
        $def = ExportDefinitions::get('purchasing.price-history');
        $export->applyOptions(new ExportOptions(['material_name', 'converted_price_per_kg', 'period_change_pct'], 'xlsx', 'purchasing'));
        $rows = $export->collection();
        $this->assertCount(2, $rows);
        $this->assertSame("'=SUM(1,1)", $rows[0][0], 'Formula prefix is escaped by SpreadsheetCellSanitizer.');
        // Monthly export mirrors Supporting Data newest-first ordering while preserving chronological % change.
        $this->assertEqualsWithDelta(12.0, $rows[0][1], 0.001);
        $this->assertEqualsWithDelta(20.0, $rows[0][2], 0.01);
        $this->assertEqualsWithDelta(10.0, $rows[1][1], 0.001);
        $this->assertNull($rows[1][2]);

        // 6. Preset save with multi-material filter succeeds
        $this->actingAs($this->purchasing)
            ->postJson(route('export-presets.store'), [
                'export_key' => 'purchasing.price-history',
                'name' => 'Steel History',
                'columns' => ['material_name', 'period', 'converted_price_per_kg'],
                'filters' => [
                    'materials' => ['=SUM(1,1)|Flat'],
                    'currency' => 'USD',
                    'period_view' => 'yearly',
                    'range' => 'all',
                ],
                'format' => 'xlsx',
            ])
            ->assertCreated()
            ->assertJsonPath('filters.materials.0', '=SUM(1,1)|Flat');

        // 7. Maintenance mode on import.price_history blocks export endpoint
        $record = MaintenanceRecord::create([
            'type' => MaintenanceRecord::TYPE_MODULE,
            'status' => MaintenanceRecord::STATUS_ACTIVE,
            'title' => 'Price History Maintenance',
            'description' => 'Testing export maintenance block',
            'starts_at' => now()->subMinutes(5),
            'estimated_end_at' => now()->addHour(),
            'created_by' => $this->admin->id,
        ]);
        $record->modules()->create(['module_key' => 'import.price_history']);
        Cache::flush();

        $this->actingAs($this->purchasing)
            ->postJson(route('purchasing.export.price-history'), [
                'options' => ['columns' => ['material_name'], 'format' => 'xlsx'],
            ])
            ->assertStatus(503);
    }

    public function test_supporting_data_filter_modal_syncs_chart_kpi_table_and_export(): void
    {
        Queue::fake();

        // PO 1 (2025-02): P20 Flat 10mm (1-15), USD 10/kg (= 155,000 IDR at 2025 rate)
        $po1 = $this->createHistoryPo('P20', 'Flat', ['thickness' => 10, 'width' => 100, 'length' => 200], 10, 'USD', '2025-02-10 08:00:00');
        // PO 2 (2026-03): P20 Flat 25mm (16-50), USD 12/kg (= 192,000 IDR at 2026 rate -> +23.87% vs PO1)
        $po2 = $this->createHistoryPo('P20', 'Flat', ['thickness' => 25, 'width' => 100, 'length' => 200], 12, 'USD', '2026-03-10 08:00:00');
        // PO 3 (2026-08): P20 Flat 20mm (16-50), IDR 150,000/kg (-> -21.88% vs PO2)
        $po3 = $this->createHistoryPo('P20', 'Flat', ['thickness' => 20, 'width' => 100, 'length' => 200], 150000, 'IDR', '2026-08-15 08:00:00');
        // PO 4 (2026-10): P20 Flat 85mm (51-100), IDR 150,000/kg (-> 0% vs PO3)
        $po4 = $this->createHistoryPo('P20', 'Flat', ['thickness' => 85, 'width' => 100, 'length' => 200], 150000, 'IDR', '2026-10-20 08:00:00');

        // 1. HTML page renders Filter button, modal, active count badge, chips, and anti-trap recovery controls
        $this->actingAs($this->purchasing)
            ->get(route('purchasing.comparison.historical', [
                'materials' => ['P20|Flat'],
                'categories' => ['16-50'],
                'source_currencies' => ['USD', 'IDR'],
                'currency' => 'IDR',
            ]))
            ->assertOk()
            ->assertSee('id="historicalSupportingFilterBtn"', false)
            ->assertSee('id="historicalSupportingFilterModal"', false)
            ->assertSee('id="historicalSupportingFilterChips"', false)
            ->assertSee('id="historicalNoDataAdjustFiltersBtn"', false)
            ->assertSee('data-supporting-chip-remove="categories"', false)
            ->assertSee('data-supporting-chip-remove="source_currencies"', false)
            ->assertSee('<caption class="tw:sr-only">', false)
            ->assertSee('id="historicalSupportingTable"', false)
            ->assertSee('id="historicalSortPoDateBtn"', false)
            ->assertSee('commandfor="historicalSupportingTable"', false)
            ->assertSee('command="--sort-po-date"', false)
            ->assertSee('aria-sort="descending"', false);

        // 1b. Unfiltered Monthly Supporting Data defaults to newest PO first while preserving chronological % change
        $newestFirstPayload = $this->actingAs($this->purchasing)
            ->getJson(route('purchasing.comparison.historical', [
                'materials' => ['P20|Flat'],
                'currency' => 'IDR',
                'view' => 'json',
            ]))
            ->assertOk()
            ->json();

        $this->assertSame(
            [$po4->po_number, $po3->po_number, $po2->po_number, $po1->po_number],
            array_column($newestFirstPayload['tableData'], 'purchase_order_number'),
            'Monthly Supporting Data must default to newest PO date first.',
        );
        $this->assertEqualsWithDelta(0.0, (float) $newestFirstPayload['tableData'][0]['change_pct'], 0.01);
        $this->assertEqualsWithDelta(-21.88, (float) $newestFirstPayload['tableData'][1]['change_pct'], 0.01);
        $this->assertEqualsWithDelta(23.87, (float) $newestFirstPayload['tableData'][2]['change_pct'], 0.01);
        $this->assertNull($newestFirstPayload['tableData'][3]['change_pct']);

        // 1c. Explicit sort_dir=asc sorts oldest PO first, preserves chronological % change, reflects aria-sort="ascending" in SSR, and syncs Monthly Export
        $this->actingAs($this->purchasing)
            ->get(route('purchasing.comparison.historical', [
                'materials' => ['P20|Flat'],
                'sort_dir' => 'asc',
                'currency' => 'IDR',
            ]))
            ->assertOk()
            ->assertSee('aria-sort="ascending"', false);

        $oldestFirstPayload = $this->actingAs($this->purchasing)
            ->getJson(route('purchasing.comparison.historical', [
                'materials' => ['P20|Flat'],
                'sort_dir' => 'asc',
                'currency' => 'IDR',
                'view' => 'json',
            ]))
            ->assertOk()
            ->json();

        $this->assertSame(
            [$po1->po_number, $po2->po_number, $po3->po_number, $po4->po_number],
            array_column($oldestFirstPayload['tableData'], 'purchase_order_number'),
            'Monthly Supporting Data sort_dir=asc must order oldest PO date first.',
        );
        $this->assertNull($oldestFirstPayload['tableData'][0]['change_pct']);
        $this->assertEqualsWithDelta(23.87, (float) $oldestFirstPayload['tableData'][1]['change_pct'], 0.01);
        $this->assertEqualsWithDelta(-21.88, (float) $oldestFirstPayload['tableData'][2]['change_pct'], 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $oldestFirstPayload['tableData'][3]['change_pct'], 0.01);

        // Invalid sort_dir safely falls back to desc
        $invalidSortPayload = $this->actingAs($this->purchasing)
            ->getJson(route('purchasing.comparison.historical', [
                'materials' => ['P20|Flat'],
                'sort_dir' => 'invalid;DROP TABLE',
                'currency' => 'IDR',
                'view' => 'json',
            ]))
            ->assertOk()
            ->json();
        $this->assertSame(
            [$po4->po_number, $po3->po_number, $po2->po_number, $po1->po_number],
            array_column($invalidSortPayload['tableData'], 'purchase_order_number'),
        );

        // Monthly Export honors sort_dir=asc
        $ascExport = new PurchasingHistoricalPriceExport([
            'materials' => ['P20|Flat'],
            'period_view' => 'monthly',
            'currency' => 'IDR',
            'range' => 'all',
            'sort_dir' => 'asc',
        ]);
        $ascExport->applyOptions(new ExportOptions(['material_name', 'po_number', 'period_change_pct'], 'xlsx', 'purchasing'));
        $ascExportRows = $ascExport->collection();
        $this->assertSame([$po1->po_number, $po2->po_number, $po3->po_number, $po4->po_number], $ascExportRows->pluck(1)->all());
        $this->assertNull($ascExportRows[0][2]);
        $this->assertEqualsWithDelta(23.87, (float) $ascExportRows[1][2], 0.01);

        // 2. Category filter (16-50) filters Chart, KPI, and Supporting Data synchronously & preserves exact 14 top-level JSON keys
        $catPayload = $this->actingAs($this->purchasing)
            ->getJson(route('purchasing.comparison.historical', [
                'materials' => ['P20|Flat'],
                'categories' => ['16-50', 'nonexistent-category'],
                'currency' => 'IDR',
                'view' => 'json',
            ]))
            ->assertOk()
            ->json();

        $this->assertSame([
            'chartData', 'tableData', 'summary', 'pagination', 'periodView', 'range', 'rangeOptions',
            'materialName', 'shape', 'keyOptions', 'supplierName', 'currency', 'evaluation', 'keyDimension',
        ], array_keys($catPayload));
        $this->assertSame(2, $catPayload['summary']['line_count']);
        $this->assertCount(2, $catPayload['tableData']);
        $this->assertEqualsWithDelta(150000.0, (float) $catPayload['summary']['best'], 0.01);
        $this->assertEqualsWithDelta((192000 + 150000) / 2, (float) $catPayload['summary']['weighted_average'], 0.01);

        // 3. Source currency filter (USD only) matches PO1 + PO2 only
        $currPayload = $this->actingAs($this->purchasing)
            ->getJson(route('purchasing.comparison.historical', [
                'materials' => ['P20|Flat'],
                'source_currencies' => ['USD'],
                'currency' => 'IDR',
                'view' => 'json',
            ]))
            ->assertOk()
            ->json();

        $this->assertSame(2, $currPayload['summary']['line_count']);
        $this->assertEqualsCanonicalizing([$po1->po_number, $po2->po_number], array_column($currPayload['tableData'], 'purchase_order_number'));

        // 4. Document search (PO number) & wildcard escaping
        $docPayload = $this->actingAs($this->purchasing)
            ->getJson(route('purchasing.comparison.historical', [
                'materials' => ['P20|Flat'],
                'doc_search' => $po4->po_number,
                'currency' => 'IDR',
                'view' => 'json',
            ]))
            ->assertOk()
            ->json();

        $this->assertSame(1, $docPayload['summary']['line_count']);
        $this->assertSame($po4->po_number, $docPayload['tableData'][0]['purchase_order_number']);

        $wildcardPayload = $this->actingAs($this->purchasing)
            ->getJson(route('purchasing.comparison.historical', [
                'materials' => ['P20|Flat'],
                'doc_search' => '%',
                'currency' => 'IDR',
                'view' => 'json',
            ]))
            ->assertOk()
            ->json();
        $this->assertNull($wildcardPayload['chartData'], 'Percent wildcard must be escaped, not match every PO.');

        // 5. Explicit PO Date Range (inverted bounds auto-swapped) overrides relative 3m preset
        $datePayload = $this->actingAs($this->purchasing)
            ->getJson(route('purchasing.comparison.historical', [
                'materials' => ['P20|Flat'],
                'range' => '3m',
                'start_date' => '2026-04-01',
                'end_date' => '2025-01-01',
                'currency' => 'IDR',
                'view' => 'json',
            ]))
            ->assertOk()
            ->json();

        $this->assertSame(2, $datePayload['summary']['line_count']);
        $this->assertEqualsCanonicalizing([$po1->po_number, $po2->po_number], array_column($datePayload['tableData'], 'purchase_order_number'));

        // 6. BCMath exact Price/kg Range (152,000 - 200,000 IDR) matches PO1 (155k) and PO2 (192k), excludes PO3/PO4 (150k)
        $pricePayload = $this->actingAs($this->purchasing)
            ->getJson(route('purchasing.comparison.historical', [
                'materials' => ['P20|Flat'],
                'price_min' => '200000',
                'price_max' => '152000',
                'currency' => 'IDR',
                'view' => 'json',
            ]))
            ->assertOk()
            ->json();

        $this->assertSame(2, $pricePayload['summary']['line_count']);
        $this->assertEqualsWithDelta(155000.0, (float) $pricePayload['summary']['best'], 0.01);
        $this->assertEqualsCanonicalizing([$po1->po_number, $po2->po_number], array_column($pricePayload['tableData'], 'purchase_order_number'));

        // 7. Price Change Direction (Monthly: decreased -> only PO3; Yearly: increased -> only 2026 YoY) while Chart baseline stays intact
        $decreasedPayload = $this->actingAs($this->purchasing)
            ->getJson(route('purchasing.comparison.historical', [
                'materials' => ['P20|Flat'],
                'change_direction' => 'decreased',
                'currency' => 'IDR',
                'view' => 'json',
            ]))
            ->assertOk()
            ->json();

        $this->assertSame(4, $decreasedPayload['summary']['line_count'], 'Chart and KPI baseline stay intact.');
        $this->assertSame(1, $decreasedPayload['pagination']['total']);
        $this->assertCount(1, $decreasedPayload['tableData']);
        $this->assertSame($po3->po_number, $decreasedPayload['tableData'][0]['purchase_order_number']);
        $this->assertLessThan(0, $decreasedPayload['tableData'][0]['change_pct']);

        $yearlyIncreased = $this->actingAs($this->purchasing)
            ->getJson(route('purchasing.comparison.historical', [
                'materials' => ['P20|Flat'],
                'period_view' => 'yearly',
                'change_direction' => 'increased',
                'currency' => 'IDR',
                'view' => 'json',
            ]))
            ->assertOk()
            ->json();

        $this->assertSame(['2025', '2026'], $yearlyIncreased['chartData']['labels']);
        $this->assertCount(1, $yearlyIncreased['tableData']);
        $this->assertSame('2026', $yearlyIncreased['tableData'][0]['period']);

        // 8. Export preview count, ExportPreset save, and exported collection honor all 6 modal filters identically
        $this->actingAs($this->purchasing)
            ->postJson(route('exports.preview-count'), [
                'export_key' => 'purchasing.price-history',
                'materials' => ['P20|Flat'],
                'categories' => ['16-50'],
                'source_currencies' => ['IDR'],
                'price_min' => '140000',
                'price_max' => '160000',
                'period_view' => 'monthly',
                'currency' => 'IDR',
            ])
            ->assertOk()
            ->assertJsonPath('total_rows', 1);

        $this->actingAs($this->purchasing)
            ->postJson(route('export-presets.store'), [
                'export_key' => 'purchasing.price-history',
                'name' => 'P20 Mid Category Decreased',
                'columns' => ['material_name', 'po_number', 'converted_price_per_kg', 'period_change_pct'],
                'filters' => [
                    'materials' => ['P20|Flat'],
                    'categories' => ['16-50'],
                    'source_currencies' => ['USD', 'IDR'],
                    'price_min' => '140000',
                    'price_max' => '200000',
                    'change_direction' => 'decreased',
                    'currency' => 'IDR',
                    'period_view' => 'monthly',
                    'range' => 'all',
                ],
                'format' => 'xlsx',
            ])
            ->assertCreated()
            ->assertJsonPath('filters.categories.0', '16-50')
            ->assertJsonPath('filters.change_direction', 'decreased');

        $export = new PurchasingHistoricalPriceExport([
            'materials' => ['P20|Flat'],
            'categories' => ['16-50'],
            'source_currencies' => ['USD', 'IDR'],
            'change_direction' => 'decreased',
            'period_view' => 'monthly',
            'currency' => 'IDR',
            'range' => 'all',
        ]);
        $export->applyOptions(new ExportOptions(['material_name', 'po_number', 'converted_price_per_kg', 'period_change_pct'], 'xlsx', 'purchasing'));
        $rows = $export->collection();
        $this->assertCount(1, $rows);
        $this->assertSame($po3->po_number, $rows[0][1]);
        $this->assertLessThan(0, $rows[0][3]);
    }

    private function createHistoryPo(
        string $materialName,
        string $shape,
        array $dims,
        float $pricePerKg,
        string $currency,
        string $createdAt,
        ?int $materialMasterId = null
    ): PurchaseOrder {
        $this->seq++;
        $pr = PurchaseRequisition::create([
            'period_id' => $this->period->id,
            'created_by' => $this->purchasing->id,
            'pr_number' => 'REQ/NOV26/'.$this->seq,
            'status' => 'completed',
        ]);
        $item = $pr->items()->create([
            'material_master_id' => $materialMasterId,
            'material_name' => $materialName,
            'shape' => $shape,
            'quantity' => 1,
            'weight_needed' => 10,
            ...$dims,
        ]);
        $quote = Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $this->supplier->id,
            'exchange_rate_id' => $this->usdRate->id,
            'currency' => $currency,
            'status' => 'accepted',
            'submitted_at' => $createdAt,
        ]);
        $qItem = $quote->items()->create([
            'pr_item_id' => $item->id,
            'is_available' => true,
            'price_per_kg' => $pricePerKg,
            'amount' => $pricePerKg * 10,
            ...collect($dims)->mapWithKeys(fn ($v, $k) => ['available_'.$k => $v])->all(),
        ]);
        $po = PurchaseOrder::create([
            'supplier_id' => $this->supplier->id,
            'currency' => $currency,
            'exchange_rate_id' => $this->usdRate->id,
            'po_number' => 'PO/NOV26/'.$this->seq,
            'status' => 'completed',
            'created_by' => $this->purchasing->id,
        ]);
        $po->forceFill(['created_at' => BusinessTime::toStorage(Carbon::parse($createdAt, BusinessTime::tz()))])->save();
        $po->quotations()->attach($quote->id);
        PrItemAward::create([
            'pr_id' => $pr->id,
            'pr_item_id' => $item->id,
            'quotation_id' => $quote->id,
            'quotation_item_id' => $qItem->id,
            'supplier_id' => $this->supplier->id,
            'purchase_order_id' => $po->id,
            'awarded_by' => $this->purchasing->id,
            'awarded_at' => $createdAt,
        ])->forceFill([
            'ordered_qty' => 1,
            'ordered_total_weight_kg' => '10.0000',
            'ordered_price_per_kg' => number_format($pricePerKg, 4, '.', ''),
            'ordered_amount' => number_format($pricePerKg * 10, 4, '.', ''),
        ])->save();

        return $po;
    }
}
