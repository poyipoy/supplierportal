<?php

namespace Tests\Feature\LocalInvoice;

use App\Models\LocalPurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierScope;
use App\Models\User;
use App\Services\LocalInvoice\LocalPoImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LocalPoImportTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    private User $purchasing;

    private User $supplierUser;

    private LocalPoImportService $service;

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
            'company_name' => 'PT SURYA UTAMA TEKNOLOGI',
            'category' => 'Parts',
            'is_pkp' => false,
            'payment_term_days' => 30,
        ]);

        $this->service = app(LocalPoImportService::class);
    }

    public function test_service_validation_parses_valid_rows_and_resolves_supplier(): void
    {
        $rows = [
            [
                '_row' => 2,
                'po_number' => 'PNR261178',
                'supplier_name' => 'PT SURYA UTAMA TEKNOLOGI',
                'po_date' => '2026-09-16',
                'po_amount' => '633000.00',
                '_formula_columns' => [],
            ],
        ];

        $result = $this->service->validate($rows);

        $this->assertTrue($result['success']);
        $this->assertCount(0, $result['errors']);
        $this->assertSame(1, $result['summary']['new_po']);
        $this->assertSame($this->supplierUser->id, $result['rows'][0]['supplier_id']);
        $this->assertSame('NEW', $result['rows'][0]['action']);
    }

    public function test_unknown_supplier_is_rejected_with_clear_error(): void
    {
        $rows = [
            [
                '_row' => 2,
                'po_number' => 'PNR261178',
                'supplier_name' => 'PT SUPLIER GAIB',
                'po_date' => '2026-09-16',
                'po_amount' => '633000.00',
                '_formula_columns' => [],
            ],
        ];

        $result = $this->service->validate($rows);

        $this->assertFalse($result['success']);
        $this->assertCount(1, $result['errors']);
        $this->assertSame('supplier_name', $result['errors'][0]['column']);
        $this->assertStringContainsString('No exact active Local Supplier match was found', $result['errors'][0]['message']);
    }

    public function test_ambiguous_supplier_is_rejected(): void
    {
        // Create duplicate company name
        $dupUser = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        SupplierScope::create(['supplier_id' => $dupUser->id, 'scope' => 'local']);
        Supplier::create([
            'user_id' => $dupUser->id,
            'company_name' => 'pt surya utama teknologi',
            'category' => 'Parts',
        ]);

        $rows = [
            [
                '_row' => 2,
                'po_number' => 'PNR261178',
                'supplier_name' => 'PT SURYA UTAMA TEKNOLOGI',
                'po_date' => '2026-09-16',
                'po_amount' => '633000.00',
                '_formula_columns' => [],
            ],
        ];

        $result = $this->service->validate($rows);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('ambiguous', $result['errors'][0]['message']);
    }

    public function test_invalid_date_or_non_positive_amount_is_rejected(): void
    {
        $rows = [
            [
                '_row' => 2,
                'po_number' => 'PNR261178',
                'supplier_name' => 'PT SURYA UTAMA TEKNOLOGI',
                'po_date' => 'bukan-tanggal',
                'po_amount' => '-5000',
                '_formula_columns' => [],
            ],
        ];

        $result = $this->service->validate($rows);

        $this->assertFalse($result['success']);
        $columns = collect($result['errors'])->pluck('column')->all();
        $this->assertContains('po_date', $columns);
        $this->assertContains('po_amount', $columns);
    }

    public function test_formula_in_cells_is_rejected(): void
    {
        $rows = [
            [
                '_row' => 2,
                'po_number' => 'PNR261178',
                'supplier_name' => 'PT SURYA UTAMA TEKNOLOGI',
                'po_date' => '2026-09-16',
                'po_amount' => '633000.00',
                '_formula_columns' => ['po_amount'],
            ],
        ];

        $result = $this->service->validate($rows);

        $this->assertFalse($result['success']);
        $this->assertSame('po_amount', $result['errors'][0]['column']);
        $this->assertStringContainsString('formulas are not allowed', $result['errors'][0]['message']);
    }

    public function test_repeated_po_with_conflicting_header_in_workbook_is_rejected(): void
    {
        $rows = [
            [
                '_row' => 2,
                'po_number' => 'PNR261178',
                'supplier_name' => 'PT SURYA UTAMA TEKNOLOGI',
                'po_date' => '2026-09-16',
                'po_amount' => '633000.00',
                '_formula_columns' => [],
            ],
            [
                '_row' => 3,
                'po_number' => 'PNR261178',
                'supplier_name' => 'PT SURYA UTAMA TEKNOLOGI',
                'po_date' => '2026-09-16',
                'po_amount' => '999999.00', // differs!
                '_formula_columns' => [],
            ],
        ];

        $result = $this->service->validate($rows);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('conflicting header values', $result['errors'][0]['message']);
    }

    public function test_existing_identical_po_is_skipped_while_conflicting_po_is_blocked(): void
    {
        LocalPurchaseOrder::create([
            'po_number' => 'PNR261178',
            'supplier_id' => $this->supplierUser->id,
            'po_date' => '2026-09-16',
            'total_amount' => '633000.00',
            'currency' => 'IDR',
            'status' => LocalPurchaseOrder::STATUS_OPEN,
            'source' => LocalPurchaseOrder::SOURCE_IMPORT,
        ]);

        // 1. Identical PO -> should be marked as EXISTING (skipped)
        $result = $this->service->validate([
            [
                '_row' => 2,
                'po_number' => 'PNR261178',
                'supplier_name' => 'PT SURYA UTAMA TEKNOLOGI',
                'po_date' => '2026-09-16',
                'po_amount' => '633000.00',
                '_formula_columns' => [],
            ],
        ]);
        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['summary']['existing_po']);
        $this->assertSame(0, $result['summary']['new_po']);
        $this->assertSame('EXISTING', $result['rows'][0]['action']);

        // 2. Conflicting PO (different amount) -> should be blocked
        $resultConflict = $this->service->validate([
            [
                '_row' => 2,
                'po_number' => 'PNR261178',
                'supplier_name' => 'PT SURYA UTAMA TEKNOLOGI',
                'po_date' => '2026-09-16',
                'po_amount' => '700000.00', // Different amount!
                '_formula_columns' => [],
            ],
        ]);
        $this->assertFalse($resultConflict['success']);
        $this->assertSame(1, $resultConflict['summary']['conflicts']);
        $this->assertStringContainsString('conflicting values', $resultConflict['errors'][0]['message']);
    }

    public function test_import_is_atomic_and_creates_new_po_record(): void
    {
        $rows = [
            [
                '_row' => 2,
                'po_number' => 'PNR-NEW-001',
                'supplier_name' => 'PT SURYA UTAMA TEKNOLOGI',
                'po_date' => '2026-09-16',
                'po_amount' => '500000.00',
                '_formula_columns' => [],
            ],
        ];

        $counts = $this->service->import($this->finance, $rows);

        $this->assertSame(1, $counts['newPo']);
        $this->assertSame(0, $counts['existingPo']);

        $po = LocalPurchaseOrder::where('po_number', 'PNR-NEW-001')->firstOrFail();
        $this->assertSame($this->supplierUser->id, $po->supplier_id);
        $this->assertSame('500000.00', (string) $po->total_amount);
        $this->assertSame(LocalPurchaseOrder::STATUS_OPEN, $po->status);
        $this->assertSame(LocalPurchaseOrder::SOURCE_IMPORT, $po->source);

        $this->assertDatabaseHas('local_finance_audit_logs', [
            'action' => 'po_import_confirmed',
        ]);
    }

    public function test_http_preview_and_confirm_routes(): void
    {
        foreach ([
            'PT PRIMA NANO COATING',
            'PT. BINTANG UTAMA ENGINEERING',
            'CV SUMBER RIZKI SENTOSA',
        ] as $company) {
            $u = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
            SupplierScope::create(['supplier_id' => $u->id, 'scope' => 'local']);
            Supplier::create([
                'user_id' => $u->id,
                'company_name' => $company,
                'category' => 'Parts',
            ]);
        }

        $filePath = base_path('tdpur4100m000_0520_20260924-133401_101112.xlsx');
        $this->assertFileExists($filePath);

        $uploaded = new UploadedFile($filePath, 'tdpur_sample.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        // Preview route
        $response = $this->actingAs($this->finance)
            ->post(route('finance.local-procurement.import.po.preview'), [
                'import_file' => $uploaded,
            ], ['Accept' => 'application/json']);

        $response->assertOk();
        $data = $response->json();
        $this->assertArrayHasKey('preview', $data);
        $this->assertArrayHasKey('token', $data);
        $this->assertTrue($data['preview']['success'], json_encode($data['preview']));
        $token = $data['token'];

        // Confirm route
        $confirmResponse = $this->actingAs($this->finance)
            ->post(route('finance.local-procurement.import.po.confirm'), [
                'token' => $token,
            ]);

        $confirmResponse->assertRedirect(route('finance.local-procurement.index'));
        $this->assertDatabaseHas('local_purchase_orders', [
            'po_number' => 'PNR261178',
        ]);
    }

    public function test_template_download_route(): void
    {
        $this->actingAs($this->finance)
            ->get(route('finance.local-procurement.import.po.template'))
            ->assertOk()
            ->assertDownload('infor-erp-po-template.xlsx');
    }

    public function test_po_import_modal_uses_business_terminology_without_tdpur(): void
    {
        $response = $this->actingAs($this->finance)
            ->get(route('finance.local-procurement.index'));

        $response->assertOk();
        $response->assertSee('File Spreadsheet Purchase Order (PO) ERP (.xlsx)');
        $response->assertSee('Template Purchase Order (PO) ERP');
        $response->assertSee('Pemetaan Kolom ERP Infor Purchase Order (PO):');
        $response->assertDontSee('tdpur');
    }

    public function test_supplier_user_cannot_access_import_routes(): void
    {
        $this->actingAs($this->supplierUser)
            ->get(route('finance.local-procurement.import.po.template'))
            ->assertForbidden();

        $this->actingAs($this->supplierUser)
            ->post(route('finance.local-procurement.import.po.preview'))
            ->assertForbidden();
    }
}
