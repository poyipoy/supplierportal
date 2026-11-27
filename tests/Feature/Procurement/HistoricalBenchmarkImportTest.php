<?php

namespace Tests\Feature\Procurement;

use App\Models\AnnualExchangeRate;
use App\Models\ExchangeRate;
use App\Models\HistoricalBenchmarkLine;
use App\Models\MasterDataAuditLog;
use App\Models\MaterialMaster;
use App\Models\Period;
use App\Models\PrItem;
use App\Models\PrItemAward;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\ReferencePriceImport;
use App\Models\Supplier;
use App\Models\SupplierScope;
use App\Models\User;
use App\Services\Materials\MasterDataDeletionService;
use App\Services\Pricing\ReferencePriceBenchmarkResolver;
use Database\Seeders\ReferencePriceRulesInitialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HistoricalBenchmarkImportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $purchasing;

    private User $purchasingOther;

    private User $supplier;

    private User $qc;

    private User $finance;

    private MaterialMaster $materialSkd11;

    private MaterialMaster $materialSkd61;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $this->purchasingOther = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $this->supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $this->qc = User::factory()->create(['role' => 'qc', 'is_active' => true]);
        $this->finance = User::factory()->create(['role' => 'finance', 'is_active' => true]);

        Supplier::create([
            'user_id' => $this->supplier->id,
            'company_name' => 'NIPPON BENCHMARK SUPPLIER',
        ]);

        $this->seed(ReferencePriceRulesInitialSeeder::class);

        $this->materialSkd11 = MaterialMaster::create([
            'material_code' => 'SKD11',
            'normalized_code' => 'SKD11',
            'raw_category' => 'Alloy Tool Steel',
            'hs_category' => MaterialMaster::HS_CATEGORY_ALLOY,
            'density_profile' => MaterialMaster::DENSITY_STEEL,
            'manufacturer_scope' => MaterialMaster::MANUFACTURER_DAIDO,
            'is_active' => true,
            'created_by' => $this->purchasing->id,
        ]);

        $this->materialSkd61 = MaterialMaster::create([
            'material_code' => 'SKD61',
            'normalized_code' => 'SKD61',
            'raw_category' => 'Hot Work Tool Steel',
            'hs_category' => MaterialMaster::HS_CATEGORY_ALLOY,
            'density_profile' => MaterialMaster::DENSITY_STEEL,
            'manufacturer_scope' => MaterialMaster::MANUFACTURER_DAIDO,
            'is_active' => true,
            'created_by' => $this->purchasing->id,
        ]);

        AnnualExchangeRate::create([
            'year' => 2025,
            'revision' => 1,
            'currency' => 'USD',
            'rate_to_idr' => '15500.0000',
            'created_by' => $this->admin->id,
        ]);

        AnnualExchangeRate::create([
            'year' => 2026,
            'revision' => 1,
            'currency' => 'USD',
            'rate_to_idr' => '16000.0000',
            'created_by' => $this->admin->id,
        ]);
    }

    public function test_admin_and_purchasing_can_download_benchmark_template(): void
    {
        $purchasingResp = $this->actingAs($this->purchasing)
            ->get(route('purchasing.master-data.reference-prices.benchmark.template'));
        $purchasingResp->assertOk();
        $this->assertStringContainsString(
            'historical-benchmark-reference-prices-template.xlsx',
            (string) $purchasingResp->headers->get('content-disposition')
        );

        $adminResp = $this->actingAs($this->admin)
            ->get(route('admin.reference-prices.benchmark.template'));
        $adminResp->assertOk();
    }

    public function test_non_authorized_roles_are_forbidden_across_all_benchmark_import_endpoints(): void
    {
        $line = $this->seedBenchmarkLine('PO-SEC-01', '2025-05-10', '10.0000', '100.0000', '5.0000');
        $csvFile = UploadedFile::fake()->createWithContent(
            'history.csv',
            "po_number,po_date,supplier_name,material_code,shape,key_dimension,ordered_weight_kg,currency,price_per_kg\nPO-X,2025-05-01,Vendor,SKD11,Flat,10,100,USD,5.0\n"
        );

        foreach ([$this->supplier, $this->qc, $this->finance] as $unauthorized) {
            $this->actingAs($unauthorized)
                ->get(route('purchasing.master-data.reference-prices.benchmark.template'))
                ->assertForbidden();

            $this->actingAs($unauthorized)
                ->post(route('purchasing.master-data.reference-prices.benchmark.import-preview'), [
                    'file' => $csvFile,
                    'import_mode' => ReferencePriceImport::MODE_MERGE,
                ])
                ->assertForbidden();

            $this->actingAs($unauthorized)
                ->delete(route('purchasing.master-data.reference-prices.benchmark.lines.destroy', $line->hashid))
                ->assertForbidden();
        }
    }

    public function test_merge_and_replace_modes_preserve_portal_pos_and_feed_benchmark_resolver(): void
    {
        $portalPo = $this->createCompletedPortalPo('PO-PORTAL-2026-01', '2026-02-15 08:00:00', 12.0, '10.0000', '100.0000');

        // Batch 1 (Merge): 2025 + 2026 imported lines
        $csvBatch1 = "po_number,po_date,supplier_name,material_code,shape,key_dimension,ordered_weight_kg,currency,price_per_kg,notes\n"
            ."PO-IMP-2025-01,2025-04-10,NIPPON BENCHMARK SUPPLIER,SKD11,Flat,10.0000,200.0000,USD,8.0000,Initial 2025\n"
            ."PO-IMP-2026-01,2026-01-20,LEGACY OVERSEAS CORP,SKD11,Flat,14.0000,300.0000,USD,6.0000,Initial 2026\n";

        $previewResp = $this->actingAs($this->purchasing)
            ->postJson(route('purchasing.master-data.reference-prices.benchmark.import-preview'), [
                'file' => UploadedFile::fake()->createWithContent('batch1.csv', $csvBatch1),
                'import_mode' => ReferencePriceImport::MODE_MERGE,
            ]);

        $previewResp->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('preview.valid', true)
            ->assertJsonPath('preview.counts.new', 2)
            ->assertJsonPath('redirect', route('purchasing.master-data.reference-prices.index', ['tab' => 'benchmark']));

        $importId1 = (int) $previewResp->json('import_id');
        $stagedPath1 = ReferencePriceImport::findOrFail($importId1)->private_storage_path;
        $this->assertTrue(Storage::exists($stagedPath1));

        // Tab 2 renders preview card before confirmation
        $this->actingAs($this->purchasing)
            ->get(route('purchasing.master-data.reference-prices.index', ['tab' => 'benchmark']))
            ->assertOk()
            ->assertSee('batch1.csv')
            ->assertSee('PO-IMP-2025-01');

        // Confirm Batch 1
        $this->actingAs($this->purchasing)
            ->post(route('purchasing.master-data.reference-prices.benchmark.import-confirm'), [
                'import_id' => $importId1,
            ])
            ->assertRedirect(route('purchasing.master-data.reference-prices.index', ['tab' => 'benchmark']))
            ->assertSessionHas('success');

        $this->assertFalse(Storage::exists($stagedPath1));
        $this->assertSame(2, HistoricalBenchmarkLine::count());
        $this->assertDatabaseHas('master_data_audit_logs', [
            'entity_type' => MasterDataAuditLog::ENTITY_REFERENCE_PRICE_IMPORT,
            'entity_id' => $importId1,
            'action' => MasterDataAuditLog::CREATED,
        ]);

        // Verify Inter-Supplier Benchmark Resolver combines Portal PO (100 kg @ 10 USD) + Imported PO (300 kg @ 6 USD) = 2800 / 400 = 7.0000 USD/kg
        $prItemForEval = new PrItem([
            'material_master_id' => $this->materialSkd11->id,
            'shape' => 'Flat',
            'thickness' => 11.0,
        ]);
        $benchmark = app(ReferencePriceBenchmarkResolver::class)->resolve(
            prItem: $prItemForEval,
            targetCurrency: 'USD',
            benchmarkYear: 2026,
            historyAsOfAt: '2026-12-31 23:59:59'
        );

        $this->assertTrue($benchmark->isMainMatched());
        $this->assertSame('6.0000', $benchmark->main->lowestPricePerKg);
        $this->assertSame('7.0000', $benchmark->main->weightedAveragePricePerKg);
        $this->assertSame(2, $benchmark->main->poCount);

        // Batch 2 (Merge): update PO-IMP-2026-01 price and retain PO-IMP-2025-01
        $csvBatch2 = "po_number,po_date,supplier_name,material_code,shape,key_dimension,ordered_weight_kg,currency,price_per_kg,notes\n"
            ."PO-IMP-2026-01,2026-01-20,LEGACY OVERSEAS CORP,SKD11,Flat,14.0000,300.0000,USD,5.5000,Updated 2026\n";

        $previewResp2 = $this->actingAs($this->admin)
            ->postJson(route('admin.reference-prices.benchmark.import-preview'), [
                'file' => UploadedFile::fake()->createWithContent('batch2.csv', $csvBatch2),
                'import_mode' => ReferencePriceImport::MODE_MERGE,
            ]);
        $previewResp2->assertOk()
            ->assertJsonPath('preview.counts.updated', 1)
            ->assertJsonPath('preview.counts.new', 0);

        $this->actingAs($this->admin)
            ->post(route('admin.reference-prices.benchmark.import-confirm'), [
                'import_id' => $previewResp2->json('import_id'),
            ])
            ->assertRedirect(route('admin.reference-prices.index', ['tab' => 'benchmark']));

        $this->assertSame(2, HistoricalBenchmarkLine::count());
        $updatedLine = HistoricalBenchmarkLine::where('po_number_normalized', 'PO-IMP-2026-01')->firstOrFail();
        $this->assertSame('5.5000', (string) $updatedLine->price_per_kg);
        $this->assertSame($this->purchasing->id, $updatedLine->created_by);
        $this->assertSame($this->admin->id, $updatedLine->updated_by);

        // Batch 3 (Replace): replaces all imported lines with 1 new row while keeping Portal PO untouched
        $csvBatch3 = "po_number,po_date,supplier_name,material_code,shape,key_dimension,ordered_weight_kg,currency,price_per_kg\n"
            ."PO-IMP-REPLACED,2026-03-01,REPLACED VENDOR,SKD61,Round,40.0000,500.0000,USD,9.2500\n";

        $previewResp3 = $this->actingAs($this->purchasing)
            ->postJson(route('purchasing.master-data.reference-prices.benchmark.import-preview'), [
                'file' => UploadedFile::fake()->createWithContent('batch3.csv', $csvBatch3),
                'import_mode' => ReferencePriceImport::MODE_REPLACE,
            ]);
        $previewResp3->assertOk()
            ->assertJsonPath('preview.counts.new', 1)
            ->assertJsonPath('preview.counts.removed', 2);

        $this->actingAs($this->purchasing)
            ->post(route('purchasing.master-data.reference-prices.benchmark.import-confirm'), [
                'import_id' => $previewResp3->json('import_id'),
            ])
            ->assertRedirect(route('purchasing.master-data.reference-prices.index', ['tab' => 'benchmark']));

        $this->assertSame(1, HistoricalBenchmarkLine::count());
        $this->assertDatabaseHas('historical_benchmark_lines', ['po_number' => 'PO-IMP-REPLACED']);
        $this->assertDatabaseHas('purchase_orders', ['id' => $portalPo->id, 'po_number' => 'PO-PORTAL-2026-01']);

        // Tab 2 renders both Portal PO (as link) and Imported line (without broken PO link)
        $tabResponse = $this->actingAs($this->purchasing)
            ->get(route('purchasing.master-data.reference-prices.index', ['tab' => 'benchmark']));
        $tabResponse->assertOk()
            ->assertSee('PO-PORTAL-2026-01')
            ->assertSee('PO-IMP-REPLACED');
    }

    public function test_concurrent_portal_po_created_after_preview_blocks_confirm_and_other_user_cannot_confirm(): void
    {
        $csv = "po_number,po_date,supplier_name,material_code,shape,key_dimension,ordered_weight_kg,currency,price_per_kg\n"
            ."PO-RACE-2026,2026-02-01,Vendor Race,SKD11,Flat,10.0000,100.0000,USD,6.0000\n";

        $previewResp = $this->actingAs($this->purchasing)
            ->postJson(route('purchasing.master-data.reference-prices.benchmark.import-preview'), [
                'file' => UploadedFile::fake()->createWithContent('race.csv', $csv),
                'import_mode' => ReferencePriceImport::MODE_MERGE,
            ])
            ->assertOk();

        $importId = (int) $previewResp->json('import_id');

        // Different purchasing user cannot hijack or confirm another user's preview session
        $this->actingAs($this->purchasingOther)
            ->postJson(route('purchasing.master-data.reference-prices.benchmark.import-confirm'), [
                'import_id' => $importId,
            ])
            ->assertStatus(422);

        // Portal PO with colliding po_number is created between preview and confirm
        $spotRate = ExchangeRate::firstOrCreate(
            ['currency' => 'USD', 'valid_from' => '2026-01-01'],
            ['rate_to_idr' => '16000.0000', 'created_by' => $this->purchasing->id]
        );
        PurchaseOrder::create([
            'po_number' => 'PO-RACE-2026',
            'supplier_id' => $this->supplier->id,
            'currency' => 'USD',
            'exchange_rate_id' => $spotRate->id,
            'created_by' => $this->purchasing->id,
            'status' => 'issued',
        ]);

        $this->actingAs($this->purchasing)
            ->postJson(route('purchasing.master-data.reference-prices.benchmark.import-confirm'), [
                'import_id' => $importId,
            ])
            ->assertStatus(422);

        $this->assertSame(0, HistoricalBenchmarkLine::count());
    }

    public function test_cancel_preview_and_hashid_guarded_delete_and_material_master_delete_guard(): void
    {
        $csv = "po_number,po_date,supplier_name,material_code,shape,key_dimension,ordered_weight_kg,currency,price_per_kg\n"
            ."PO-CANCEL-01,2025-05-01,Vendor Cancel,SKD11,Flat,10.0000,100.0000,USD,6.0000\n";

        $previewResp = $this->actingAs($this->purchasing)
            ->postJson(route('purchasing.master-data.reference-prices.benchmark.import-preview'), [
                'file' => UploadedFile::fake()->createWithContent('cancel.csv', $csv),
                'import_mode' => ReferencePriceImport::MODE_MERGE,
            ])
            ->assertOk();

        $importId = (int) $previewResp->json('import_id');
        $stagedPath = ReferencePriceImport::findOrFail($importId)->private_storage_path;

        $this->actingAs($this->purchasing)
            ->post(route('purchasing.master-data.reference-prices.benchmark.import-cancel'), [
                'import_id' => $importId,
            ])
            ->assertRedirect(route('purchasing.master-data.reference-prices.index', ['tab' => 'benchmark']));

        $this->assertFalse(Storage::exists($stagedPath));
        $this->assertSame(ReferencePriceImport::STATUS_REJECTED, ReferencePriceImport::findOrFail($importId)->status);

        // Seed an imported line: verify MaterialMaster deletion is cleanly refused while referenced
        $line = $this->seedBenchmarkLine('PO-DEL-01', '2025-07-10', '10.0000', '250.0000', '5.5000');

        $deletionOutcome = app(MasterDataDeletionService::class)->delete($this->materialSkd11, $this->admin);
        $this->assertFalse($deletionOutcome->deleted);
        $this->assertGreaterThan(0, $deletionOutcome->counts['reference_prices']);

        // Raw integer ID in URL must return 404
        $this->actingAs($this->purchasing)
            ->delete('/purchasing/master-data/reference-prices/benchmark/lines/'.$line->id)
            ->assertNotFound();

        // Valid Hashid deletes the row and writes append-only MasterDataAuditLog::DELETED
        $this->actingAs($this->purchasing)
            ->delete(route('purchasing.master-data.reference-prices.benchmark.lines.destroy', $line->hashid))
            ->assertRedirect(route('purchasing.master-data.reference-prices.index', ['tab' => 'benchmark']))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('historical_benchmark_lines', ['id' => $line->id]);
        $this->assertDatabaseHas('master_data_audit_logs', [
            'entity_type' => MasterDataAuditLog::ENTITY_HISTORICAL_BENCHMARK_LINE,
            'entity_id' => $line->id,
            'action' => MasterDataAuditLog::DELETED,
        ]);
    }

    private function seedBenchmarkLine(
        string $poNumber,
        string $poDate,
        string $keyDimension,
        string $weightKg,
        string $pricePerKg
    ): HistoricalBenchmarkLine {
        return HistoricalBenchmarkLine::create([
            'po_number' => $poNumber,
            'po_number_normalized' => HistoricalBenchmarkLine::normalizePoNumber($poNumber),
            'line_no' => 1,
            'po_date' => $poDate,
            'po_datetime_utc' => $poDate.' 00:00:00',
            'po_year' => (int) substr($poDate, 0, 4),
            'supplier_name' => 'SEEDED HISTORICAL VENDOR',
            'material_master_id' => $this->materialSkd11->id,
            'material_code_snapshot' => $this->materialSkd11->material_code,
            'shape' => 'Flat',
            'key_dimension' => $keyDimension,
            'rounded_dimension' => (int) ceil((float) $keyDimension),
            'ordered_weight_kg' => $weightKg,
            'currency' => 'USD',
            'price_per_kg' => $pricePerKg,
            'created_by' => $this->purchasing->id,
            'updated_by' => $this->purchasing->id,
        ]);
    }

    private function createCompletedPortalPo(
        string $poNumber,
        string $createdAtUtc,
        float $thickness,
        string $pricePerKg,
        string $kg
    ): PurchaseOrder {
        $period = Period::firstOrCreate(
            ['month' => 2, 'year' => 2026],
            ['name' => 'Feb 2026', 'status' => 'open', 'created_by' => $this->purchasing->id]
        );

        $pr = PurchaseRequisition::create([
            'period_id' => $period->id,
            'created_by' => $this->purchasing->id,
            'pr_number' => 'PR-'.$poNumber,
            'status' => 'completed',
        ]);

        $prItem = PrItem::create([
            'pr_id' => $pr->id,
            'material_master_id' => $this->materialSkd11->id,
            'material_name' => $this->materialSkd11->material_code,
            'shape' => 'Flat',
            'thickness' => $thickness,
            'width' => 100,
            'length' => 1000,
            'quantity' => 1,
            'weight_needed' => $kg,
        ]);

        $spotRate = ExchangeRate::firstOrCreate(
            ['currency' => 'USD', 'valid_from' => '2026-01-01'],
            ['rate_to_idr' => '16000.0000', 'created_by' => $this->purchasing->id]
        );

        $quotation = Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $this->supplier->id,
            'exchange_rate_id' => $spotRate->id,
            'currency' => 'USD',
            'status' => 'accepted',
        ]);

        $qItem = $quotation->items()->create([
            'pr_item_id' => $prItem->id,
            'is_available' => true,
            'available_qty' => 1,
            'available_thickness' => $thickness,
            'offered_weight_per_unit' => $kg,
            'price_per_kg' => $pricePerKg,
            'amount' => bcmul($pricePerKg, $kg, 4),
        ]);

        $po = PurchaseOrder::create([
            'po_number' => $poNumber,
            'supplier_id' => $this->supplier->id,
            'currency' => 'USD',
            'exchange_rate_id' => $spotRate->id,
            'created_by' => $this->purchasing->id,
            'status' => 'completed',
        ]);
        DB::table('purchase_orders')->where('id', $po->id)->update(['created_at' => $createdAtUtc]);
        DB::table('po_quotations')->insert([
            'po_id' => $po->id,
            'quotation_id' => $quotation->id,
            'created_at' => $createdAtUtc,
            'updated_at' => $createdAtUtc,
        ]);

        PrItemAward::create([
            'pr_id' => $pr->id,
            'pr_item_id' => $prItem->id,
            'quotation_id' => $quotation->id,
            'quotation_item_id' => $qItem->id,
            'supplier_id' => $this->supplier->id,
            'purchase_order_id' => $po->id,
            'awarded_by' => $this->purchasing->id,
            'awarded_at' => $createdAtUtc,
        ])->forceFill([
            'ordered_qty' => 1,
            'ordered_total_weight_kg' => $kg,
            'ordered_price_per_kg' => $pricePerKg,
            'ordered_amount' => bcmul($pricePerKg, $kg, 4),
        ])->save();

        return $po;
    }
}
