<?php

namespace Tests\Feature\Finance;

use App\Exports\PaymentBatchDrpExport;
use App\Exports\PaymentBatchDrpSheetRenderer;
use App\Jobs\GenerateWorkbookJob;
use App\Jobs\ProcessExportJob;
use App\Models\ExportJob;
use App\Models\LocalInvoice;
use App\Models\PaymentBatch;
use App\Models\PaymentGroup;
use App\Models\PaymentItem;
use App\Models\Supplier;
use App\Models\SupplierScope;
use App\Models\User;
use App\Services\ExportProgressService;
use App\Support\ExportDispatcher;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class PaymentBatchDrpExportTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    private User $admin;

    private User $supplierUser;

    private User $purchasing;

    private User $qc;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');

        $this->finance = User::factory()->create(['role' => 'finance', 'is_active' => true]);
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->supplierUser = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $this->purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $this->qc = User::factory()->create(['role' => 'qc', 'is_active' => true]);

        SupplierScope::create(['supplier_id' => $this->supplierUser->id, 'scope' => 'local']);
        Supplier::create([
            'user_id' => $this->supplierUser->id,
            'company_name' => 'PT Supplier Testing',
            'category' => 'Raw Material',
            'is_pkp' => false,
            'payment_term_days' => 30,
        ]);
    }

    private function createSupplierBatch(array $attributes = []): PaymentBatch
    {
        return PaymentBatch::create(array_merge([
            'batch_number' => 'DRP-SUPP-'.now()->format('Ymd-His').'-'.rand(100, 999),
            'batch_type' => PaymentBatch::TYPE_SUPPLIER,
            'status' => PaymentBatch::STATUS_FINALIZED,
            'total_subtotal' => 10000000,
            'total_bank_fee' => 2500,
            'total_net_amount' => 9997500,
            'created_by' => $this->finance->id,
            'created_at' => Carbon::parse('2026-09-08 10:00:00'),
        ], $attributes));
    }

    private function createInvoice(array $attributes = []): LocalInvoice
    {
        return LocalInvoice::create(array_merge([
            'supplier_id' => $this->supplierUser->id,
            'submission_number' => 'SUB-'.now()->format('Ymd-His').'-'.rand(1000, 9999).'-'.rand(1000, 9999),
            'invoice_number' => 'INV-TEST-'.rand(1000, 9999).'-'.rand(1000, 9999),
            'invoice_date' => '2026-09-01',
            'po_number' => 'PO-TEST-'.rand(1000, 9999),
            'invoice_amount' => 10000000,
            'tax_amount' => 0,
            'payment_term_days_snapshot' => 30,
            'status' => LocalInvoice::STATUS_READY_TO_PAY,
            'submitted_at' => now(),
        ], $attributes));
    }

    public function test_guest_cannot_export_drp(): void
    {
        $batch = $this->createSupplierBatch();

        $response = $this->get(route('finance.drp.export', $batch));

        $response->assertRedirect(route('login'));
    }

    public function test_non_finance_and_non_admin_roles_receive_forbidden(): void
    {
        $batch = $this->createSupplierBatch();

        $this->actingAs($this->supplierUser)
            ->get(route('finance.drp.export', $batch))
            ->assertForbidden();

        $this->actingAs($this->purchasing)
            ->get(route('finance.drp.export', $batch))
            ->assertForbidden();

        $this->actingAs($this->qc)
            ->get(route('finance.drp.export', $batch))
            ->assertForbidden();
    }

    public function test_cannot_export_ga_batch(): void
    {
        $gaBatch = $this->createSupplierBatch([
            'batch_type' => PaymentBatch::TYPE_GA,
            'batch_number' => 'DRP-GA-001',
        ]);

        $this->actingAs($this->finance)
            ->get(route('finance.drp.export', $gaBatch))
            ->assertStatus(422);
    }

    public function test_cannot_export_cancelled_batch(): void
    {
        $cancelledBatch = $this->createSupplierBatch([
            'status' => PaymentBatch::STATUS_CANCELLED,
            'batch_number' => 'DRP-CANCELLED-001',
        ]);

        $this->actingAs($this->finance)
            ->get(route('finance.drp.export', $cancelledBatch))
            ->assertStatus(422);
    }

    public function test_cannot_export_batch_without_active_items(): void
    {
        $batch = $this->createSupplierBatch();
        // No groups or items created

        $this->actingAs($this->finance)
            ->get(route('finance.drp.export', $batch))
            ->assertStatus(422);
    }

    public function test_finance_and_admin_can_dispatch_export_successfully(): void
    {
        $batch = $this->createSupplierBatch();
        $group = $batch->groups()->create([
            'payee_type' => 'supplier',
            'payee_id' => $this->supplierUser->id,
            'payee_name' => 'PT Supplier Testing',
            'bank_name' => 'BCA',
            'account_number' => '0123456789',
            'account_holder_name' => 'PT Supplier Testing',
            'subtotal_amount' => 10000000,
            'bank_fee' => 2500,
            'net_payment_amount' => 9997500,
            'status' => PaymentGroup::STATUS_UNPAID,
        ]);
        $invoice = $this->createInvoice(['invoice_number' => 'INV-001']);
        $group->items()->create([
            'payable_type' => LocalInvoice::class,
            'payable_id' => $invoice->id,
            'amount' => 10000000,
            'status' => PaymentItem::STATUS_ACTIVE,
        ]);

        // AJAX request by Finance returns 202 JSON
        $response = $this->actingAs($this->finance)
            ->getJson(route('finance.drp.export', $batch));

        $response->assertStatus(202)
            ->assertJsonStructure([
                'message',
                'export_job_id',
                'exports_url',
                'status_url',
                'cancel_url',
            ]);

        $this->assertDatabaseHas('export_jobs', [
            'user_id' => $this->finance->id,
            'export_class' => PaymentBatchDrpExport::class,
        ]);
    }

    public function test_export_dispatcher_supports_payment_batch_drp_export(): void
    {
        $this->assertTrue(ExportDispatcher::isSupported(PaymentBatchDrpExport::class));
    }

    public function test_full_export_execution_generates_valid_workbook_with_template_fidelity(): void
    {
        $batch = $this->createSupplierBatch([
            'batch_number' => 'DRP-TEST-2026',
            'created_at' => Carbon::parse('2026-09-08 14:30:00'),
        ]);

        $group1 = $batch->groups()->create([
            'payee_type' => 'supplier',
            'payee_id' => $this->supplierUser->id,
            'payee_name' => 'PT Baja Mulia Abadi',
            'bank_name' => 'BCA',
            'account_number' => '0887766554',
            'account_holder_name' => 'PT Baja Mulia Abadi',
            'subtotal_amount' => 15000000,
            'bank_fee' => 2500,
            'net_payment_amount' => 14997500,
            'status' => PaymentGroup::STATUS_UNPAID,
        ]);
        $inv1 = $this->createInvoice(['invoice_number' => 'INV-BMA-001']);
        $group1->items()->create([
            'payable_type' => LocalInvoice::class,
            'payable_id' => $inv1->id,
            'amount' => 15000000,
            'status' => PaymentItem::STATUS_ACTIVE,
        ]);

        $group2 = $batch->groups()->create([
            'payee_type' => 'supplier',
            'payee_id' => $this->supplierUser->id,
            'payee_name' => 'PT Sinar Logam Makmur',
            'bank_name' => 'MANDIRI',
            'account_number' => '1234567890',
            'account_holder_name' => 'PT Sinar Logam Makmur',
            'subtotal_amount' => 25000000,
            'bank_fee' => 0,
            'net_payment_amount' => 25000000,
            'status' => PaymentGroup::STATUS_UNPAID,
        ]);
        $inv2 = $this->createInvoice(['invoice_number' => 'INV-SLM-002']);
        $group2->items()->create([
            'payable_type' => LocalInvoice::class,
            'payable_id' => $inv2->id,
            'amount' => 25000000,
            'status' => PaymentItem::STATUS_ACTIVE,
        ]);

        $this->actingAs($this->finance);
        $record = ExportDispatcher::dispatch(
            'Test DRP Export',
            PaymentBatchDrpExport::class,
            [$this->finance->id, [$batch->id]],
            'drp_test_output.xlsx'
        );

        // Execute ProcessExportJob synchronously
        $processJob = new ProcessExportJob($record->id);
        $processJob->handle(app(ExportProgressService::class));

        // Execute GenerateWorkbookJob synchronously
        $generateJob = new GenerateWorkbookJob($record->id);
        $generateJob->handle(app(ExportProgressService::class));

        $record->refresh();
        $this->assertSame(ExportJob::STATUS_COMPLETED, $record->status);
        $this->assertTrue(Storage::disk('private')->exists($record->file_path));

        // Inspect the generated workbook
        $tempFile = tempnam(sys_get_temp_dir(), 'test_insp_');
        file_put_contents($tempFile, Storage::disk('private')->get($record->file_path));

        $spreadsheet = IOFactory::load($tempFile);
        $this->assertSame(['01'], $spreadsheet->getSheetNames());

        $sheet = $spreadsheet->getSheetByName('01');
        $this->assertNotNull($sheet);

        // Header assertions
        $this->assertStringContainsString('PT ASTRA DAIDO STEEL INDONESIA', (string) $sheet->getCell('D1')->getValue());
        $this->assertStringContainsString('REKAP PEMBAYARAN SUPPLIER', (string) $sheet->getCell('D2')->getValue());
        $this->assertSame('SEPTEMBER', (string) $sheet->getCell('D3')->getValue());
        $this->assertSame('MINGGU KE -2  (08/09/2026 )', (string) $sheet->getCell('D5')->getValue());

        // Data row 1 (Row 8)
        $this->assertEquals(1, $sheet->getCell('A8')->getValue());
        $this->assertSame('PT Baja Mulia Abadi', $sheet->getCell('D8')->getValue());
        $this->assertSame('I - INV-BMA-001', $sheet->getCell('E8')->getValue());
        $this->assertSame('BCA', $sheet->getCell('F8')->getValue());
        $this->assertSame('0887766554', (string) $sheet->getCell('G8')->getValue());
        $this->assertSame('PT Baja Mulia Abadi', $sheet->getCell('H8')->getValue());
        $this->assertEquals(15000000, $sheet->getCell('I8')->getValue());

        // Data row 2 (Row 9)
        $this->assertEquals(2, $sheet->getCell('A9')->getValue());
        $this->assertSame('PT Sinar Logam Makmur', $sheet->getCell('D9')->getValue());
        $this->assertSame('I - INV-SLM-002', $sheet->getCell('E9')->getValue());
        $this->assertSame('MANDIRI', $sheet->getCell('F9')->getValue());
        $this->assertSame('1234567890', (string) $sheet->getCell('G9')->getValue());
        $this->assertSame('PT Sinar Logam Makmur', $sheet->getCell('H9')->getValue());
        $this->assertEquals(25000000, $sheet->getCell('I9')->getValue());

        // Total row at Row 10
        $this->assertStringContainsString('TOTAL PEMBAYARAN', (string) $sheet->getCell('E10')->getValue());
        $this->assertSame('=SUM(I8:I9)', (string) $sheet->getCell('I10')->getValue());
        $this->assertEquals(40000000, $sheet->getCell('I10')->getCalculatedValue());

        // Scan for formula errors
        $highestRow = $sheet->getHighestRow();
        for ($r = 1; $r <= $highestRow; $r++) {
            for ($col = 'A'; $col <= 'L'; $col++) {
                $val = (string) $sheet->getCell($col.$r)->getValue();
                $this->assertFalse(str_contains($val, '#REF!'), "Cell {$col}{$r} contains #REF!");
                $this->assertFalse(str_contains($val, '#VALUE!'), "Cell {$col}{$r} contains #VALUE!");
                $this->assertFalse(str_contains($val, '#DIV/0!'), "Cell {$col}{$r} contains #DIV/0!");
                $this->assertFalse(str_contains($val, '#NAME?'), "Cell {$col}{$r} contains #NAME?");
                $this->assertFalse(str_contains($val, '#N/A'), "Cell {$col}{$r} contains #N/A");
            }
        }

        // Drawings (logo) preserved
        $this->assertGreaterThanOrEqual(1, count($sheet->getDrawingCollection()));

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
        @unlink($tempFile);
    }

    public function test_snapshots_are_used_and_cancelled_or_removed_items_are_excluded(): void
    {
        $batch = $this->createSupplierBatch();

        // Group 1: active group with 2 items
        $group1 = $batch->groups()->create([
            'payee_type' => 'supplier',
            'payee_id' => $this->supplierUser->id,
            'payee_name' => 'Snapshot Payee Name',
            'bank_name' => 'Snapshot Bank',
            'account_number' => '00112233',
            'account_holder_name' => 'Snapshot Account Holder',
            'subtotal_amount' => 12000000,
            'bank_fee' => 5000,
            'net_payment_amount' => 11995000,
            'status' => PaymentGroup::STATUS_UNPAID,
        ]);
        $inv1 = $this->createInvoice(['invoice_number' => 'INV-ACTIVE-01']);
        $group1->items()->create([
            'payable_type' => LocalInvoice::class,
            'payable_id' => $inv1->id,
            'amount' => 7000000,
            'status' => PaymentItem::STATUS_ACTIVE,
        ]);
        $inv2 = $this->createInvoice(['invoice_number' => 'INV-REMOVED-02']);
        $group1->items()->create([
            'payable_type' => LocalInvoice::class,
            'payable_id' => $inv2->id,
            'amount' => 5000000,
            'status' => PaymentItem::STATUS_REMOVED, // Must be excluded!
        ]);

        // Group 2: cancelled group (Must be excluded!)
        $group2 = $batch->groups()->create([
            'payee_type' => 'supplier',
            'payee_id' => $this->supplierUser->id,
            'payee_name' => 'Cancelled Supplier',
            'bank_name' => 'Cancelled Bank',
            'account_number' => '999999',
            'account_holder_name' => 'Cancelled Holder',
            'subtotal_amount' => 8000000,
            'bank_fee' => 0,
            'net_payment_amount' => 8000000,
            'status' => PaymentGroup::STATUS_CANCELLED,
        ]);
        $inv3 = $this->createInvoice(['invoice_number' => 'INV-CANCELLED-03']);
        $group2->items()->create([
            'payable_type' => LocalInvoice::class,
            'payable_id' => $inv3->id,
            'amount' => 8000000,
            'status' => PaymentItem::STATUS_ACTIVE,
        ]);

        // Mutate vendor master in DB to ensure export does NOT use master values
        $this->supplierUser->supplier()->update([
            'company_name' => 'Mutated Master Name',
        ]);

        $renderer = new PaymentBatchDrpSheetRenderer;
        $spreadsheet = $renderer->render(collect([$batch]));
        $sheet = $spreadsheet->getSheetByName('01');

        // Assert only 1 data row is generated (row 8)
        $this->assertSame('Snapshot Payee Name', $sheet->getCell('D8')->getValue());
        $this->assertNotSame('Mutated Master Name', $sheet->getCell('D8')->getValue());
        $this->assertSame('Snapshot Bank', $sheet->getCell('F8')->getValue());
        $this->assertSame('00112233', (string) $sheet->getCell('G8')->getValue());
        $this->assertSame('Snapshot Account Holder', $sheet->getCell('H8')->getValue());

        // NOMINAL is subtotal_amount (gross), not net_payment_amount
        $this->assertEquals(12000000, $sheet->getCell('I8')->getValue());
        $this->assertNotEquals(11995000, $sheet->getCell('I8')->getValue());

        // Invoice text includes active item, excludes removed item
        $invoiceText = (string) $sheet->getCell('E8')->getValue();
        $this->assertStringContainsString('INV-ACTIVE-01', $invoiceText);
        $this->assertStringNotContainsString('INV-REMOVED-02', $invoiceText);

        // Cancelled group is not present
        $this->assertStringNotContainsString('Cancelled Supplier', (string) $sheet->getCell('D9')->getValue());

        $spreadsheet->disconnectWorksheets();
    }

    public function test_multi_invoice_continuation_rows(): void
    {
        $batch = $this->createSupplierBatch();

        $group = $batch->groups()->create([
            'payee_type' => 'supplier',
            'payee_id' => $this->supplierUser->id,
            'payee_name' => 'PT Multi Invoice Supplier',
            'bank_name' => 'BCA',
            'account_number' => '555666777',
            'account_holder_name' => 'PT Multi Invoice Supplier',
            'subtotal_amount' => 20000000,
            'bank_fee' => 0,
            'net_payment_amount' => 20000000,
            'status' => PaymentGroup::STATUS_UNPAID,
        ]);

        // 4 invoices to force continuation row
        for ($k = 1; $k <= 4; $k++) {
            $inv = $this->createInvoice(['invoice_number' => "INV-MULTI-00{$k}"]);
            $group->items()->create([
                'payable_type' => LocalInvoice::class,
                'payable_id' => $inv->id,
                'amount' => 5000000,
                'status' => PaymentItem::STATUS_ACTIVE,
            ]);
        }

        $renderer = new PaymentBatchDrpSheetRenderer;
        $spreadsheet = $renderer->render(collect([$batch]));
        $sheet = $spreadsheet->getSheetByName('01');

        // Primary row at Row 8
        $this->assertEquals(1, $sheet->getCell('A8')->getValue());
        $this->assertSame('PT Multi Invoice Supplier', $sheet->getCell('D8')->getValue());
        $this->assertStringContainsString('I - INV-MULTI-001', (string) $sheet->getCell('E8')->getValue());
        $this->assertEquals(20000000, $sheet->getCell('I8')->getValue());

        // Continuation row at Row 9
        $this->assertSame('', (string) $sheet->getCell('A9')->getValue());
        $this->assertSame('', (string) $sheet->getCell('D9')->getValue());
        $this->assertStringContainsString('INV-MULTI-004', (string) $sheet->getCell('E9')->getValue());
        $this->assertSame('', (string) $sheet->getCell('F9')->getValue());
        $this->assertSame('', (string) $sheet->getCell('G9')->getValue());
        $this->assertSame('', (string) $sheet->getCell('H9')->getValue());
        // Nominal must be blank so formula sum is not duplicated
        $this->assertSame('', (string) $sheet->getCell('I9')->getValue());

        // Total row at Row 10
        $this->assertSame('=SUM(I8:I9)', (string) $sheet->getCell('I10')->getValue());
        $this->assertEquals(20000000, $sheet->getCell('I10')->getCalculatedValue());

        $spreadsheet->disconnectWorksheets();
    }

    public function test_multi_batch_creates_multiple_sheets(): void
    {
        $batch1 = $this->createSupplierBatch(['batch_number' => 'DRP-BATCH-1']);
        $group1 = $batch1->groups()->create([
            'payee_type' => 'supplier',
            'payee_id' => $this->supplierUser->id,
            'payee_name' => 'Supplier 1',
            'bank_name' => 'BCA',
            'account_number' => '111',
            'account_holder_name' => 'Supplier 1',
            'subtotal_amount' => 5000000,
            'bank_fee' => 0,
            'net_payment_amount' => 5000000,
            'status' => PaymentGroup::STATUS_UNPAID,
        ]);
        $inv1 = $this->createInvoice(['invoice_number' => 'INV-B1']);
        $group1->items()->create([
            'payable_type' => LocalInvoice::class,
            'payable_id' => $inv1->id,
            'amount' => 5000000,
            'status' => PaymentItem::STATUS_ACTIVE,
        ]);

        $batch2 = $this->createSupplierBatch(['batch_number' => 'DRP-BATCH-2']);
        $group2 = $batch2->groups()->create([
            'payee_type' => 'supplier',
            'payee_id' => $this->supplierUser->id,
            'payee_name' => 'Supplier 2',
            'bank_name' => 'BNI',
            'account_number' => '222',
            'account_holder_name' => 'Supplier 2',
            'subtotal_amount' => 7000000,
            'bank_fee' => 0,
            'net_payment_amount' => 7000000,
            'status' => PaymentGroup::STATUS_UNPAID,
        ]);
        $inv2 = $this->createInvoice(['invoice_number' => 'INV-B2']);
        $group2->items()->create([
            'payable_type' => LocalInvoice::class,
            'payable_id' => $inv2->id,
            'amount' => 7000000,
            'status' => PaymentItem::STATUS_ACTIVE,
        ]);

        $renderer = new PaymentBatchDrpSheetRenderer;
        $spreadsheet = $renderer->render(collect([$batch1, $batch2]));

        $this->assertSame(['01', '02'], $spreadsheet->getSheetNames());

        $sheet1 = $spreadsheet->getSheetByName('01');
        $this->assertSame('Supplier 1', $sheet1->getCell('D8')->getValue());
        $this->assertEquals(5000000, $sheet1->getCell('I8')->getValue());

        $sheet2 = $spreadsheet->getSheetByName('02');
        $this->assertSame('Supplier 2', $sheet2->getCell('D8')->getValue());
        $this->assertEquals(7000000, $sheet2->getCell('I8')->getValue());

        $spreadsheet->disconnectWorksheets();
    }

    public function test_finance_can_download_drp_export_and_isolation_prevents_unauthorized_download(): void
    {
        $batch = $this->createSupplierBatch();
        $group = $batch->groups()->create([
            'payee_type' => 'supplier',
            'payee_id' => $this->supplierUser->id,
            'payee_name' => 'Supplier 1',
            'bank_name' => 'BCA',
            'account_number' => '111',
            'account_holder_name' => 'Supplier 1',
            'subtotal_amount' => 5000000,
            'bank_fee' => 0,
            'net_payment_amount' => 5000000,
            'status' => PaymentGroup::STATUS_UNPAID,
        ]);
        $inv = $this->createInvoice(['invoice_number' => 'INV-DL-01']);
        $group->items()->create([
            'payable_type' => LocalInvoice::class,
            'payable_id' => $inv->id,
            'amount' => 5000000,
            'status' => PaymentItem::STATUS_ACTIVE,
        ]);

        $this->actingAs($this->finance);
        $record = ExportDispatcher::dispatch(
            'Downloadable DRP Export',
            PaymentBatchDrpExport::class,
            [$this->finance->id, [$batch->id]],
            'download_test.xlsx'
        );

        $processJob = new ProcessExportJob($record->id);
        $processJob->handle(app(ExportProgressService::class));

        $generateJob = new GenerateWorkbookJob($record->id);
        $generateJob->handle(app(ExportProgressService::class));

        $record->refresh();
        $this->assertSame(ExportJob::STATUS_COMPLETED, $record->status);

        // Finance user can see the job in export history
        $responseIndex = $this->actingAs($this->finance)->get(route('exports.index'));
        $responseIndex->assertOk()
            ->assertSee('Downloadable DRP Export');

        // Finance user can download the file
        $responseDownload = $this->actingAs($this->finance)->get(route('exports.download', $record));
        $responseDownload->assertOk();

        // Another user (purchasing) cannot download it
        $this->actingAs($this->purchasing)->get(route('exports.download', $record))->assertForbidden();

        // Supplier cannot download it
        $this->actingAs($this->supplierUser)->get(route('exports.download', $record))->assertForbidden();
    }
}
