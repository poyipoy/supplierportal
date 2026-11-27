<?php

namespace Tests\Unit\Services\Pricing;

use App\Exports\HistoricalBenchmarkTemplateExport;
use App\Models\AnnualExchangeRate;
use App\Models\ExchangeRate;
use App\Models\MaterialAlias;
use App\Models\MaterialMaster;
use App\Models\Period;
use App\Models\PurchaseOrder;
use App\Models\ReferencePriceImport;
use App\Models\Supplier;
use App\Models\SupplierScope;
use App\Models\User;
use App\Services\Pricing\HistoricalBenchmarkImportService;
use Database\Seeders\ReferencePriceRulesInitialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class HistoricalBenchmarkImportServiceTest extends TestCase
{
    use RefreshDatabase;

    private HistoricalBenchmarkImportService $service;

    private User $purchasing;

    private User $importSupplierUser;

    private User $localSupplierUser;

    private MaterialMaster $materialSkd11;

    private MaterialMaster $materialSkd61;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);

        $this->importSupplierUser = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        Supplier::create([
            'user_id' => $this->importSupplierUser->id,
            'company_name' => 'DAIDO STEEL CO., LTD.',
        ]);

        $this->localSupplierUser = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        Supplier::create([
            'user_id' => $this->localSupplierUser->id,
            'company_name' => 'PT LOCAL ONLY VENDOR',
        ]);
        $this->localSupplierUser->supplierScopes()->delete();
        SupplierScope::create([
            'supplier_id' => $this->localSupplierUser->id,
            'scope' => 'local',
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

        MaterialAlias::create([
            'material_master_id' => $this->materialSkd11->id,
            'alias' => 'DC11',
            'normalized_alias' => 'DC11',
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
            'year' => 2024,
            'revision' => 1,
            'currency' => 'USD',
            'rate_to_idr' => '15800.0000',
            'created_by' => $this->purchasing->id,
        ]);

        AnnualExchangeRate::create([
            'year' => 2024,
            'revision' => 1,
            'currency' => 'JPY',
            'rate_to_idr' => '105.0000',
            'created_by' => $this->purchasing->id,
        ]);

        $this->service = app(HistoricalBenchmarkImportService::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }

    public function test_official_template_export_parses_cleanly_and_links_import_supplier(): void
    {
        $rawXlsx = Excel::raw(new HistoricalBenchmarkTemplateExport, ExcelFormat::XLSX);
        $path = $this->writeTempFile($rawXlsx, 'xlsx');

        $analysis = $this->service->analyzeFile($path, ReferencePriceImport::MODE_MERGE, null, 'xlsx');

        $this->assertTrue($analysis['preview']['valid']);
        $this->assertSame(2, $analysis['parsed_count']);
        $this->assertSame(2, $analysis['preview']['counts']['new']);
        $this->assertSame(0, $analysis['preview']['counts']['warnings']);

        $first = $analysis['candidates'][0];
        $this->assertSame('PO-HIST-2024-001', $first['po_number']);
        $this->assertSame($this->importSupplierUser->id, $first['supplier_id']);
        $this->assertSame($this->materialSkd11->id, $first['material_master_id']);
        $this->assertSame('12.5000', $first['key_dimension']);
        $this->assertSame(13, $first['rounded_dimension']);
        $this->assertSame('2024-06-14 17:00:00', $first['po_datetime_utc']);

        $second = $analysis['candidates'][1];
        $this->assertNull($second['supplier_id']);
        $this->assertSame($this->materialSkd61->id, $second['material_master_id']);
    }

    public function test_csv_supports_utf8_bom_material_alias_and_distinct_line_numbers_without_linking_local_only_supplier(): void
    {
        $csv = "\xEF\xBB\xBFpo_number,po_date,supplier_name,material_code,shape,key_dimension,ordered_weight_kg,currency,price_per_kg,line_no,pr_number,notes\n"
            ."PO-LEG-01,2024-01-01,PT LOCAL ONLY VENDOR,dc11,flat,10.2,500,USD,4.50,1,PR-01,Sheet width 200mm\n"
            ."PO-LEG-01,2024-01-01,PT LOCAL ONLY VENDOR,SKD11,Flat,10.2000,750,USD,4.40,2,PR-01,Sheet width 300mm\n";

        $path = $this->writeTempFile($csv, 'csv');
        $analysis = $this->service->analyzeFile($path, ReferencePriceImport::MODE_MERGE, null, 'csv');

        $this->assertTrue($analysis['preview']['valid']);
        $this->assertCount(2, $analysis['candidates']);
        $this->assertNull($analysis['candidates'][0]['supplier_id']);
        $this->assertSame($this->materialSkd11->id, $analysis['candidates'][0]['material_master_id']);
        $this->assertSame(11, $analysis['candidates'][0]['rounded_dimension']);
        $this->assertSame('2023-12-31 17:00:00', $analysis['candidates'][0]['po_datetime_utc']);
        $this->assertSame(2024, $analysis['candidates'][0]['po_year']);
    }

    public function test_rejects_formula_cells_and_duplicate_natural_keys(): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([
            ['po_number', 'po_date', 'supplier_name', 'material_code', 'shape', 'key_dimension', 'ordered_weight_kg', 'currency', 'price_per_kg', 'line_no'],
            ['PO-DUP-1', '2024-05-10', 'Vendor A', 'SKD11', 'Flat', '10', '100', 'USD', '=2+3', '1'],
            ['PO-DUP-2', '2024-05-10', 'Vendor A', 'SKD11', 'Flat', '10', '100', 'USD', '5.00', '1'],
            ['po-dup-2', '2024-05-12', 'Vendor B', 'DC11', 'flat', '10.0000', '200', 'USD', '5.20', '1'],
        ]);

        $path = tempnam(sys_get_temp_dir(), 'bench_test_').'.xlsx';
        $this->tempFiles[] = $path;
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $analysis = $this->service->analyzeFile($path, ReferencePriceImport::MODE_MERGE, null, 'xlsx');

        $this->assertFalse($analysis['preview']['valid']);
        $this->assertCount(2, $analysis['preview']['conflicts']);
        $this->assertStringContainsString('Row 2', $analysis['preview']['conflicts'][0]);
        $this->assertStringContainsString('price_per_kg', $analysis['preview']['conflicts'][0]);
        $this->assertStringContainsString('Row 4', $analysis['preview']['conflicts'][1]);
    }

    public function test_blocks_active_portal_po_collision_but_allows_cancelled_or_deleted_portal_po(): void
    {
        Period::create([
            'name' => '2026 P1',
            'month' => 1,
            'year' => 2026,
            'status' => 'open',
            'created_by' => $this->purchasing->id,
        ]);

        $spotRate = ExchangeRate::create([
            'currency' => 'USD',
            'rate_to_idr' => '16000.0000',
            'valid_from' => '2026-01-01',
            'created_by' => $this->purchasing->id,
        ]);

        PurchaseOrder::create([
            'po_number' => 'PO-PORTAL-ACTIVE',
            'supplier_id' => $this->importSupplierUser->id,
            'currency' => 'USD',
            'exchange_rate_id' => $spotRate->id,
            'created_by' => $this->purchasing->id,
            'status' => 'completed',
        ]);

        PurchaseOrder::create([
            'po_number' => 'PO-PORTAL-CANCELLED',
            'supplier_id' => $this->importSupplierUser->id,
            'currency' => 'USD',
            'exchange_rate_id' => $spotRate->id,
            'created_by' => $this->purchasing->id,
            'status' => 'cancelled',
        ]);

        $deletedPo = PurchaseOrder::create([
            'po_number' => 'PO-PORTAL-DELETED',
            'supplier_id' => $this->importSupplierUser->id,
            'currency' => 'USD',
            'exchange_rate_id' => $spotRate->id,
            'created_by' => $this->purchasing->id,
            'status' => 'completed',
        ]);
        $deletedPo->delete();

        $blockedCsv = "po_number,po_date,supplier_name,material_code,shape,key_dimension,ordered_weight_kg,currency,price_per_kg\n"
            ."po-portal-active,2024-06-10,Vendor A,SKD11,Flat,10,100,USD,5.00\n";
        $blockedAnalysis = $this->service->analyzeFile($this->writeTempFile($blockedCsv, 'csv'), ReferencePriceImport::MODE_MERGE, null, 'csv');
        $this->assertFalse($blockedAnalysis['preview']['valid']);
        $this->assertStringContainsString('po-portal-active', $blockedAnalysis['preview']['conflicts'][0]);

        $allowedCsv = "po_number,po_date,supplier_name,material_code,shape,key_dimension,ordered_weight_kg,currency,price_per_kg\n"
            ."PO-PORTAL-CANCELLED,2024-06-10,Vendor A,SKD11,Flat,10,100,USD,5.00\n"
            ."PO-PORTAL-DELETED,2024-06-11,Vendor A,SKD11,Flat,12,150,USD,5.10\n";
        $allowedAnalysis = $this->service->analyzeFile($this->writeTempFile($allowedCsv, 'csv'), ReferencePriceImport::MODE_MERGE, null, 'csv');
        $this->assertTrue($allowedAnalysis['preview']['valid']);
        $this->assertCount(2, $allowedAnalysis['candidates']);
    }

    public function test_missing_annual_rate_and_uncovered_dimension_emit_non_blocking_warnings(): void
    {
        $csv = "po_number,po_date,supplier_name,material_code,shape,key_dimension,ordered_weight_kg,currency,price_per_kg\n"
            ."PO-WARN-01,2022-04-15,Vendor Legacy,SKD11,Flat,950,300,CNY,32.50\n";

        $analysis = $this->service->analyzeFile($this->writeTempFile($csv, 'csv'), ReferencePriceImport::MODE_MERGE, null, 'csv');

        $this->assertTrue($analysis['preview']['valid']);
        $this->assertSame(2, $analysis['preview']['counts']['warnings']);
        $this->assertCount(2, $analysis['preview']['warnings']);
    }

    public function test_semicolon_csv_with_comma_decimals_is_rejected_instead_of_silently_corrupting_scale(): void
    {
        $csv = "po_number;po_date;supplier_name;material_code;shape;key_dimension;ordered_weight_kg;currency;price_per_kg\n"
            ."PO-COMMA-01;2024-06-15;Vendor Comma;SKD11;Flat;12,5;1,250;USD;4,85\n";

        $analysis = $this->service->analyzeFile($this->writeTempFile($csv, 'csv'), ReferencePriceImport::MODE_MERGE, null, 'csv');

        $this->assertFalse($analysis['preview']['valid']);
        $this->assertCount(0, $analysis['candidates']);
        $this->assertNotEmpty($analysis['preview']['conflicts']);
        $this->assertStringContainsString('key_dimension', $analysis['preview']['conflicts'][0]);
        $this->assertStringContainsString('ordered_weight_kg', $analysis['preview']['conflicts'][0]);
        $this->assertStringContainsString('price_per_kg', $analysis['preview']['conflicts'][0]);
    }

    private function writeTempFile(string $contents, string $extension): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bench_test_').'.'.$extension;
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }
}
