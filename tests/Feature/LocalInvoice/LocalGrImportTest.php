<?php

namespace Tests\Feature\LocalInvoice;

use App\Imports\LocalGrImport;
use App\Models\LocalGoodsReceipt;
use App\Models\LocalPurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierScope;
use App\Models\User;
use App\Services\LocalInvoice\LocalGrImportService;
use App\Support\SpreadsheetImportReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class LocalGrImportTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    private User $purchasing;

    private User $supplierUser;

    private LocalPurchaseOrder $openPo;

    private LocalGrImportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');

        $this->finance = User::factory()->create(['role' => 'finance', 'is_active' => true]);
        $this->purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);

        $this->supplierUser = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        SupplierScope::create(['supplier_id' => $this->supplierUser->id, 'scope' => 'local']);
        Supplier::create([
            'user_id' => $this->supplierUser->id,
            'company_name' => 'PT TEST SUPPLIER',
            'category' => 'Parts',
            'is_pkp' => false,
            'payment_term_days' => 30,
        ]);

        $this->openPo = LocalPurchaseOrder::create([
            'po_number' => 'PO-TEST-001',
            'supplier_id' => $this->supplierUser->id,
            'po_date' => '2026-09-01',
            'total_amount' => '10000000.00',
            'currency' => 'IDR',
            'status' => LocalPurchaseOrder::STATUS_OPEN,
            'source' => LocalPurchaseOrder::SOURCE_MANUAL,
        ]);

        $this->service = app(LocalGrImportService::class);
    }

    public function test_service_validation_aggregates_multiple_erp_rows_into_single_gr_and_sums_qty(): void
    {
        $rows = [
            [
                '_row' => 2,
                'gr_number' => 'REC060436',
                'po_number' => 'PO-TEST-001',
                'description' => 'Item A',
                'qty' => '1.5000',
                'gr_date' => '2026-09-10',
                '_formula_columns' => [],
            ],
            [
                '_row' => 3,
                'gr_number' => 'REC060436',
                'po_number' => 'PO-TEST-001',
                'description' => 'Item B',
                'qty' => '2.5000',
                'gr_date' => '2026-09-10',
                '_formula_columns' => [],
            ],
            [
                '_row' => 4,
                'gr_number' => 'REC060436',
                'po_number' => 'PO-TEST-001',
                'description' => 'Item A',
                'qty' => '1.0000',
                'gr_date' => '2026-09-10',
                '_formula_columns' => [],
            ],
        ];

        $result = $this->service->validate($rows);

        $this->assertTrue($result['success']);
        $this->assertCount(0, $result['errors']);
        $this->assertSame(3, $result['source_row_count']);
        $this->assertCount(1, $result['rows']);

        $group = $result['rows'][0];
        $this->assertSame('REC060436', $group['gr_number']);
        $this->assertSame('PO-TEST-001', $group['po_number']);
        $this->assertSame('Item A, Item B', $group['description']);
        $this->assertSame($this->openPo->id, $group['po_id']);
        $this->assertSame('5.0000', $group['qty']);
        $this->assertSame(3, $group['source_rows_count']);
        $this->assertSame('NEW', $group['action']);
    }

    public function test_unmatched_po_is_blocked_with_clear_error(): void
    {
        $rows = [
            [
                '_row' => 2,
                'gr_number' => 'REC060436',
                'po_number' => 'PO-UNKNOWN-999',
                'qty' => '1.0000',
                'gr_date' => '2026-09-10',
                '_formula_columns' => [],
            ],
        ];

        $result = $this->service->validate($rows);

        $this->assertFalse($result['success']);
        $this->assertCount(1, $result['errors']);
        $this->assertSame('po_number', $result['errors'][0]['column']);
        $this->assertStringContainsString('no matching Local PO exists', $result['errors'][0]['message']);
        $this->assertSame(1, $result['summary']['unmatched_po']);
    }

    public function test_gr_linked_to_multiple_different_pos_is_rejected(): void
    {
        $secondPo = LocalPurchaseOrder::create([
            'po_number' => 'PO-TEST-002',
            'supplier_id' => $this->supplierUser->id,
            'po_date' => '2026-09-02',
            'total_amount' => '5000000.00',
            'currency' => 'IDR',
            'status' => LocalPurchaseOrder::STATUS_OPEN,
            'source' => LocalPurchaseOrder::SOURCE_MANUAL,
        ]);

        $rows = [
            [
                '_row' => 2,
                'gr_number' => 'REC060436',
                'po_number' => 'PO-TEST-001',
                'qty' => '1.0000',
                'gr_date' => '2026-09-10',
                '_formula_columns' => [],
            ],
            [
                '_row' => 3,
                'gr_number' => 'REC060436',
                'po_number' => 'PO-TEST-002',
                'qty' => '2.0000',
                'gr_date' => '2026-09-10',
                '_formula_columns' => [],
            ],
        ];

        $result = $this->service->validate($rows);

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['errors']);
        $found = collect($result['errors'])->firstWhere('column', 'po_number');
        $this->assertNotNull($found);
        $this->assertStringContainsString('is linked to multiple different PO numbers', $found['message']);
    }

    public function test_gr_with_conflicting_dates_is_rejected(): void
    {
        $rows = [
            [
                '_row' => 2,
                'gr_number' => 'REC060436',
                'po_number' => 'PO-TEST-001',
                'qty' => '1.0000',
                'gr_date' => '2026-09-10',
                '_formula_columns' => [],
            ],
            [
                '_row' => 3,
                'gr_number' => 'REC060436',
                'po_number' => 'PO-TEST-001',
                'qty' => '2.0000',
                'gr_date' => '2026-09-15', // Conflicting calendar date!
                '_formula_columns' => [],
            ],
        ];

        $result = $this->service->validate($rows);

        $this->assertFalse($result['success']);
        $found = collect($result['errors'])->firstWhere('column', 'gr_date');
        $this->assertNotNull($found);
        $this->assertStringContainsString('has conflicting calendar dates', $found['message']);
    }

    public function test_invalid_zero_or_negative_qty_is_rejected(): void
    {
        $rows = [
            [
                '_row' => 2,
                'gr_number' => 'REC060436',
                'po_number' => 'PO-TEST-001',
                'qty' => '0',
                'gr_date' => '2026-09-10',
                '_formula_columns' => [],
            ],
        ];

        $result = $this->service->validate($rows);

        $this->assertFalse($result['success']);
        $found = collect($result['errors'])->firstWhere('column', 'qty');
        $this->assertNotNull($found);
        $this->assertStringContainsString('Quantity must be a positive number', $found['message']);
    }

    public function test_formula_in_cells_is_rejected(): void
    {
        $rows = [
            [
                '_row' => 2,
                'gr_number' => 'REC060436',
                'po_number' => 'PO-TEST-001',
                'qty' => '5.0000',
                'gr_date' => '2026-09-10',
                '_formula_columns' => ['qty'],
            ],
        ];

        $result = $this->service->validate($rows);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('formulas are not allowed', $result['errors'][0]['message']);
    }

    public function test_gr_cannot_be_added_to_closed_or_cancelled_po(): void
    {
        $closedPo = LocalPurchaseOrder::create([
            'po_number' => 'PO-CLOSED-999',
            'supplier_id' => $this->supplierUser->id,
            'po_date' => '2026-09-01',
            'total_amount' => '1000000.00',
            'currency' => 'IDR',
            'status' => LocalPurchaseOrder::STATUS_CLOSED,
            'source' => LocalPurchaseOrder::SOURCE_MANUAL,
        ]);

        $rows = [
            [
                '_row' => 2,
                'gr_number' => 'REC060436',
                'po_number' => 'PO-CLOSED-999',
                'qty' => '5.0000',
                'gr_date' => '2026-09-10',
                '_formula_columns' => [],
            ],
        ];

        $result = $this->service->validate($rows);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('closed or cancelled PO', $result['errors'][0]['message']);
    }

    public function test_existing_identical_gr_is_skipped_while_conflicting_gr_is_blocked(): void
    {
        LocalGoodsReceipt::create([
            'local_purchase_order_id' => $this->openPo->id,
            'gr_number' => 'REC060436',
            'gr_date' => '2026-09-10',
            'qty' => '5.0000',
            'status' => LocalGoodsReceipt::STATUS_AVAILABLE,
            'created_by' => $this->finance->id,
        ]);

        // 1. Identical GR -> should be skipped
        $resultSkipped = $this->service->validate([
            [
                '_row' => 2,
                'gr_number' => 'REC060436',
                'po_number' => 'PO-TEST-001',
                'qty' => '5.0000',
                'gr_date' => '2026-09-10',
                '_formula_columns' => [],
            ],
        ]);

        $this->assertTrue($resultSkipped['success']);
        $this->assertSame(1, $resultSkipped['summary']['existing_gr']);
        $this->assertSame(0, $resultSkipped['summary']['new_gr']);
        $this->assertSame('EXISTING', $resultSkipped['rows'][0]['action']);

        // 2. Conflicting GR (different Qty) -> should be blocked
        $resultConflict = $this->service->validate([
            [
                '_row' => 2,
                'gr_number' => 'REC060436',
                'po_number' => 'PO-TEST-001',
                'qty' => '10.0000', // Different Qty!
                'gr_date' => '2026-09-10',
                '_formula_columns' => [],
            ],
        ]);

        $this->assertFalse($resultConflict['success']);
        $this->assertSame(1, $resultConflict['summary']['conflicts']);
        $this->assertStringContainsString('already exists with conflicting data', $resultConflict['errors'][0]['message']);
    }

    public function test_import_is_atomic_and_creates_amount_less_gr_with_audit_log(): void
    {
        $rows = [
            [
                '_row' => 2,
                'gr_number' => 'REC-NEW-888',
                'po_number' => 'PO-TEST-001',
                'description' => 'Material Alpha',
                'qty' => '3.0000',
                'gr_date' => '2026-09-10',
                '_formula_columns' => [],
            ],
            [
                '_row' => 3,
                'gr_number' => 'REC-NEW-888',
                'po_number' => 'PO-TEST-001',
                'description' => 'Material Beta',
                'qty' => '2.0000',
                'gr_date' => '2026-09-10',
                '_formula_columns' => [],
            ],
        ];

        $counts = $this->service->import($this->finance, $rows);

        $this->assertSame(1, $counts['newGr']);
        $this->assertSame(0, $counts['existingGr']);
        $this->assertSame(2, $counts['sourceRows']);

        $gr = LocalGoodsReceipt::where('gr_number', 'REC-NEW-888')->firstOrFail();
        $this->assertSame($this->openPo->id, $gr->local_purchase_order_id);
        $this->assertSame('Material Alpha, Material Beta', $gr->description);
        $this->assertSame('5.0000', (string) $gr->qty);
        $this->assertSame('2026-09-10', $gr->gr_date->format('Y-m-d'));

        $this->assertDatabaseHas('local_finance_audit_logs', [
            'action' => 'gr_import_confirmed',
        ]);
    }

    public function test_http_preview_and_confirm_routes(): void
    {
        $rows = [
            [
                '_row' => 2,
                'gr_number' => 'REC-HTTP-001',
                'po_number' => 'PO-TEST-001',
                'qty' => '4.0000',
                'gr_date' => '2026-09-10',
                '_formula_columns' => [],
            ],
        ];

        // Seed session token manually to test confirm route
        $token = Str::random(40);
        session()->put('local_gr_import.'.$token, $rows);

        $confirmResponse = $this->actingAs($this->finance)
            ->post(route('finance.local-procurement.import.gr.confirm'), [
                'token' => $token,
            ]);

        $confirmResponse->assertRedirect(route('finance.local-procurement.index'));
        $this->assertDatabaseHas('local_goods_receipts', [
            'gr_number' => 'REC-HTTP-001',
        ]);
    }

    public function test_real_whinh_file_preview_with_seeded_pos(): void
    {
        $filePath = base_path('whinh3512m600_0520_20260924-134847_116644.xlsx');
        $this->assertFileExists($filePath);

        $uploaded = new UploadedFile($filePath, 'whinh_sample.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $import = new LocalGrImport;
        SpreadsheetImportReader::import($import, $uploaded);
        $base = $import->preview();
        $this->assertTrue($base['success']);

        $firstRow = $base['rows'][0];
        $this->assertArrayHasKey('description', $firstRow);
        $this->assertNotEmpty($firstRow['description']);

        // Collect all unique POs referenced in the real whinh file and seed them
        $poNumbers = collect($base['rows'])->pluck('po_number')->unique()->filter()->values();
        foreach ($poNumbers as $poNum) {
            LocalPurchaseOrder::firstOrCreate(
                ['po_number' => $poNum],
                [
                    'supplier_id' => $this->supplierUser->id,
                    'po_date' => '2026-09-01',
                    'total_amount' => '100000000.00',
                    'currency' => 'IDR',
                    'status' => LocalPurchaseOrder::STATUS_OPEN,
                    'source' => LocalPurchaseOrder::SOURCE_MANUAL,
                ]
            );
        }

        $fileForPost = new UploadedFile($filePath, 'whinh_sample.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $response = $this->actingAs($this->finance)
            ->post(route('finance.local-procurement.import.gr.preview'), [
                'import_file' => $fileForPost,
            ], ['Accept' => 'application/json']);

        $response->assertOk();
        $data = $response->json();
        $this->assertTrue($data['preview']['success'], json_encode($data['preview']));
        $this->assertSame(count($base['rows']), $data['preview']['source_row_count']);
        $this->assertGreaterThan(0, $data['preview']['summary']['new_gr']);
    }

    public function test_template_download_route(): void
    {
        $this->actingAs($this->finance)
            ->get(route('finance.local-procurement.import.gr.template'))
            ->assertOk()
            ->assertDownload('infor-erp-gr-template.xlsx');
    }

    public function test_gr_import_modal_uses_business_terminology_without_whinh(): void
    {
        $response = $this->actingAs($this->finance)
            ->get(route('finance.local-procurement.index'));

        $response->assertOk();
        $response->assertSee('File Spreadsheet Goods Receipt (GR) ERP (.xlsx)');
        $response->assertSee('Template Goods Receipt (GR) ERP');
        $response->assertSee('Ketentuan Agregasi & Pemetaan ERP Infor Goods Receipt (GR):', false);
        $response->assertDontSee('whinh');
    }

    public function test_supplier_user_cannot_access_gr_import_routes(): void
    {
        $this->actingAs($this->supplierUser)
            ->get(route('finance.local-procurement.import.gr.template'))
            ->assertForbidden();

        $this->actingAs($this->supplierUser)
            ->post(route('finance.local-procurement.import.gr.preview'))
            ->assertForbidden();
    }
}
