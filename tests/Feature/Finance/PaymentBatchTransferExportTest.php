<?php

namespace Tests\Feature\Finance;

use App\Exports\PaymentBatchDrpExport;
use App\Exports\PaymentBatchTransferExport;
use App\Exports\PaymentBatchTransferSheetRenderer;
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
use App\Support\BankTransferMapping;
use App\Support\ExportDispatcher;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class PaymentBatchTransferExportTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    private User $admin;

    private User $supplierUser;

    private User $purchasing;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');

        $this->finance = User::factory()->create(['role' => 'finance', 'is_active' => true]);
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->supplierUser = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $this->purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);

        SupplierScope::create(['supplier_id' => $this->supplierUser->id, 'scope' => 'local']);
        Supplier::create([
            'user_id' => $this->supplierUser->id,
            'company_name' => 'PT Supplier Testing',
            'category' => 'Raw Material',
            'is_pkp' => false,
            'payment_term_days' => 30,
            'pic_email' => 'supplier@example.com',
        ]);
    }

    private function createSupplierBatch(array $attributes = []): PaymentBatch
    {
        return PaymentBatch::create(array_merge([
            'batch_number' => 'DRP-'.now()->format('Ymd-His').'-'.rand(100, 999),
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

    private function createBatchWithGroup(
        string $batchNumber,
        string $bankName = 'BCA',
        float $netAmount = 5000000.00,
        string $invNumber = 'INV-001',
        ?string $createdAt = null,
    ): array {
        $batch = $this->createSupplierBatch([
            'batch_number' => $batchNumber,
            'created_at' => Carbon::parse($createdAt ?? '2026-09-08 10:00:00'),
        ]);

        $group = $batch->groups()->create([
            'payee_type' => 'supplier',
            'payee_id' => $this->supplierUser->id,
            'payee_name' => 'PT Supplier Testing',
            'bank_name' => $bankName,
            'account_number' => '0123456789',
            'account_holder_name' => 'PT Supplier Testing',
            'subtotal_amount' => $netAmount + 2500,
            'bank_fee' => 2500,
            'net_payment_amount' => $netAmount,
            'status' => PaymentGroup::STATUS_UNPAID,
        ]);

        $invoice = $this->createInvoice(['invoice_number' => $invNumber]);
        $group->items()->create([
            'payable_type' => LocalInvoice::class,
            'payable_id' => $invoice->id,
            'amount' => $netAmount + 2500,
            'status' => PaymentItem::STATUS_ACTIVE,
        ]);

        return [$batch, $group, $invoice];
    }

    // ─── Selection Tests ──────────────────────────────────────────────

    public function test_empty_selection_rejected(): void
    {
        $this->actingAs($this->finance)
            ->postJson(route('finance.drp.export-transfer'), ['batch_ids' => []])
            ->assertStatus(422);
    }

    public function test_one_batch_selected_dispatches_export(): void
    {
        [$batch] = $this->createBatchWithGroup('DRP-2026-00001');

        $response = $this->actingAs($this->finance)
            ->postJson(route('finance.drp.export-transfer'), [
                'batch_ids' => [$batch->hash],
            ]);

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
            'export_class' => PaymentBatchTransferExport::class,
        ]);
    }

    public function test_multiple_batches_selected_dispatches_single_export(): void
    {
        [$batch1] = $this->createBatchWithGroup('DRP-2026-00001', 'BCA', 5000000, 'INV-001', '2026-09-01');
        [$batch2] = $this->createBatchWithGroup('DRP-2026-00002', 'BNI', 7000000, 'INV-002', '2026-09-02');
        [$batch3] = $this->createBatchWithGroup('DRP-2026-00003', 'MANDIRI', 3000000, 'INV-003', '2026-09-03');

        $response = $this->actingAs($this->finance)
            ->postJson(route('finance.drp.export-transfer'), [
                'batch_ids' => [$batch1->hash, $batch2->hash, $batch3->hash],
            ]);

        $response->assertStatus(202);

        // Only one export job created
        $this->assertSame(1, ExportJob::where('export_class', PaymentBatchTransferExport::class)->count());
    }

    public function test_duplicate_batch_ids_handled_safely(): void
    {
        [$batch] = $this->createBatchWithGroup('DRP-2026-00001');

        $response = $this->actingAs($this->finance)
            ->postJson(route('finance.drp.export-transfer'), [
                'batch_ids' => [$batch->hash, $batch->hash, $batch->hash],
            ]);

        $response->assertStatus(202);
    }

    public function test_raw_integer_batch_ids_rejected(): void
    {
        [$batch] = $this->createBatchWithGroup('DRP-2026-00001');

        $this->actingAs($this->finance)
            ->postJson(route('finance.drp.export-transfer'), [
                'batch_ids' => [(string) $batch->id],
            ])
            ->assertStatus(422);
    }

    // ─── Authorization Tests ──────────────────────────────────────────

    public function test_guest_cannot_export_transfer(): void
    {
        $this->postJson(route('finance.drp.export-transfer'), ['batch_ids' => ['abc']])
            ->assertUnauthorized();
    }

    public function test_finance_allowed(): void
    {
        [$batch] = $this->createBatchWithGroup('DRP-2026-00001');

        $this->actingAs($this->finance)
            ->postJson(route('finance.drp.export-transfer'), ['batch_ids' => [$batch->hash]])
            ->assertStatus(202);
    }

    public function test_admin_allowed(): void
    {
        [$batch] = $this->createBatchWithGroup('DRP-2026-00001');

        $this->actingAs($this->admin)
            ->postJson(route('finance.drp.export-transfer'), ['batch_ids' => [$batch->hash]])
            ->assertStatus(202);
    }

    public function test_unauthorized_roles_rejected(): void
    {
        [$batch] = $this->createBatchWithGroup('DRP-2026-00001');

        $this->actingAs($this->supplierUser)
            ->postJson(route('finance.drp.export-transfer'), ['batch_ids' => [$batch->hash]])
            ->assertForbidden();

        $this->actingAs($this->purchasing)
            ->postJson(route('finance.drp.export-transfer'), ['batch_ids' => [$batch->hash]])
            ->assertForbidden();
    }

    public function test_ga_batch_rejected(): void
    {
        $gaBatch = PaymentBatch::create([
            'batch_number' => 'DRP-GA-001',
            'batch_type' => PaymentBatch::TYPE_GA,
            'status' => PaymentBatch::STATUS_FINALIZED,
            'total_subtotal' => 1000000,
            'total_bank_fee' => 0,
            'total_net_amount' => 1000000,
            'created_by' => $this->finance->id,
        ]);
        $group = $gaBatch->groups()->create([
            'payee_type' => 'supplier',
            'payee_id' => $this->supplierUser->id,
            'payee_name' => 'Test',
            'bank_name' => 'BCA',
            'account_number' => '111',
            'account_holder_name' => 'Test',
            'subtotal_amount' => 1000000,
            'bank_fee' => 0,
            'net_payment_amount' => 1000000,
            'status' => PaymentGroup::STATUS_UNPAID,
        ]);
        $inv = $this->createInvoice();
        $group->items()->create([
            'payable_type' => LocalInvoice::class,
            'payable_id' => $inv->id,
            'amount' => 1000000,
            'status' => PaymentItem::STATUS_ACTIVE,
        ]);

        $this->actingAs($this->finance)
            ->postJson(route('finance.drp.export-transfer'), ['batch_ids' => [$gaBatch->hash]])
            ->assertStatus(422);
    }

    public function test_cancelled_batch_rejected(): void
    {
        $batch = $this->createSupplierBatch([
            'batch_number' => 'DRP-CANCELLED',
            'status' => PaymentBatch::STATUS_CANCELLED,
        ]);

        $this->actingAs($this->finance)
            ->postJson(route('finance.drp.export-transfer'), ['batch_ids' => [$batch->hash]])
            ->assertStatus(422);
    }

    public function test_mixed_valid_and_invalid_selection_rejects_whole_export(): void
    {
        [$validBatch] = $this->createBatchWithGroup('DRP-2026-00001');

        $gaBatch = PaymentBatch::create([
            'batch_number' => 'DRP-GA-002',
            'batch_type' => PaymentBatch::TYPE_GA,
            'status' => PaymentBatch::STATUS_FINALIZED,
            'total_subtotal' => 1000000,
            'total_bank_fee' => 0,
            'total_net_amount' => 1000000,
            'created_by' => $this->finance->id,
        ]);

        $this->actingAs($this->finance)
            ->postJson(route('finance.drp.export-transfer'), [
                'batch_ids' => [$validBatch->hash, $gaBatch->hash],
            ])
            ->assertStatus(422);

        // No export job should have been created
        $this->assertSame(0, ExportJob::where('export_class', PaymentBatchTransferExport::class)->count());
    }

    // ─── Data Tests ───────────────────────────────────────────────────

    public function test_one_payment_group_equals_one_transfer_row(): void
    {
        [$batch, $group1] = $this->createBatchWithGroup('DRP-2026-00001', 'BCA', 5000000, 'INV-001');

        // Add a second group to the same batch
        $group2 = $batch->groups()->create([
            'payee_type' => 'supplier',
            'payee_id' => $this->supplierUser->id,
            'payee_name' => 'PT Second Supplier',
            'bank_name' => 'MANDIRI',
            'account_number' => '9876543210',
            'account_holder_name' => 'PT Second Supplier',
            'subtotal_amount' => 7002500,
            'bank_fee' => 2500,
            'net_payment_amount' => 7000000,
            'status' => PaymentGroup::STATUS_UNPAID,
        ]);
        $inv2 = $this->createInvoice(['invoice_number' => 'INV-002']);
        $group2->items()->create([
            'payable_type' => LocalInvoice::class,
            'payable_id' => $inv2->id,
            'amount' => 7002500,
            'status' => PaymentItem::STATUS_ACTIVE,
        ]);

        $renderer = new PaymentBatchTransferSheetRenderer;
        $batch->load(['groups.items.payable']);
        $rows = $renderer->buildAllTransferRows(collect([$batch]));

        $this->assertCount(2, $rows);
        $this->assertEquals(5000000, $rows[0]['amount']);
        $this->assertEquals(7000000, $rows[1]['amount']);
    }

    public function test_multiple_drps_populate_one_data_sheet(): void
    {
        [$batch1] = $this->createBatchWithGroup('DRP-2026-00001', 'BCA', 5000000, 'INV-001', '2026-09-01');
        [$batch2] = $this->createBatchWithGroup('DRP-2026-00002', 'BNI', 7000000, 'INV-002', '2026-09-02');
        [$batch3] = $this->createBatchWithGroup('DRP-2026-00003', 'MANDIRI', 3000000, 'INV-003', '2026-09-03');

        $batches = PaymentBatch::whereIn('id', [$batch1->id, $batch2->id, $batch3->id])
            ->with(['groups.items.payable'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $renderer = new PaymentBatchTransferSheetRenderer;
        $spreadsheet = $renderer->render($batches);

        // Exactly one sheet named "Data"
        $this->assertSame(['Data'], $spreadsheet->getSheetNames());
        $this->assertSame(1, $spreadsheet->getSheetCount());

        $sheet = $spreadsheet->getSheetByName('Data');
        $this->assertNotNull($sheet);

        // 3 data rows (one per batch's group)
        $this->assertEquals(1, $sheet->getCell('A2')->getValue());
        $this->assertEquals(2, $sheet->getCell('A3')->getValue());
        $this->assertEquals(3, $sheet->getCell('A4')->getValue());

        $spreadsheet->disconnectWorksheets();
    }

    public function test_same_supplier_across_different_drps_remains_separate_transactions(): void
    {
        [$batch1] = $this->createBatchWithGroup('DRP-2026-00001', 'BCA', 5000000, 'INV-001', '2026-09-01');
        [$batch2] = $this->createBatchWithGroup('DRP-2026-00002', 'BCA', 7000000, 'INV-002', '2026-09-02');

        $batches = PaymentBatch::whereIn('id', [$batch1->id, $batch2->id])
            ->with(['groups.items.payable'])
            ->orderBy('created_at')
            ->get();

        $renderer = new PaymentBatchTransferSheetRenderer;
        $rows = $renderer->buildAllTransferRows($batches);

        // Two separate rows, NOT consolidated
        $this->assertCount(2, $rows);
        $this->assertEquals(5000000, $rows[0]['amount']);
        $this->assertEquals(7000000, $rows[1]['amount']);
    }

    public function test_removed_payment_item_excluded(): void
    {
        [$batch, $group] = $this->createBatchWithGroup('DRP-2026-00001', 'BCA', 5000000, 'INV-ACTIVE');

        // Add a REMOVED item
        $inv2 = $this->createInvoice(['invoice_number' => 'INV-REMOVED']);
        $group->items()->create([
            'payable_type' => LocalInvoice::class,
            'payable_id' => $inv2->id,
            'amount' => 3000000,
            'status' => PaymentItem::STATUS_REMOVED,
        ]);

        $batch->load(['groups.items.payable']);
        $renderer = new PaymentBatchTransferSheetRenderer;
        $rows = $renderer->buildAllTransferRows(collect([$batch]));

        $this->assertCount(1, $rows);
        $this->assertStringContainsString('INV-ACTIVE', $rows[0]['remark_1']);
        $this->assertStringNotContainsString('INV-REMOVED', $rows[0]['remark_1']);
    }

    public function test_cancelled_payment_group_excluded(): void
    {
        [$batch, $group] = $this->createBatchWithGroup('DRP-2026-00001', 'BCA', 5000000, 'INV-001');

        // Add a cancelled group
        $cancelledGroup = $batch->groups()->create([
            'payee_type' => 'supplier',
            'payee_id' => $this->supplierUser->id,
            'payee_name' => 'Cancelled Supplier',
            'bank_name' => 'BCA',
            'account_number' => '999',
            'account_holder_name' => 'Cancelled',
            'subtotal_amount' => 2000000,
            'bank_fee' => 0,
            'net_payment_amount' => 2000000,
            'status' => PaymentGroup::STATUS_CANCELLED,
        ]);

        $batch->load(['groups.items.payable']);
        $renderer = new PaymentBatchTransferSheetRenderer;
        $rows = $renderer->buildAllTransferRows(collect([$batch]));

        $this->assertCount(1, $rows);
    }

    public function test_amount_uses_net_payment_amount(): void
    {
        [$batch, $group] = $this->createBatchWithGroup('DRP-2026-00001', 'BCA', 9997500, 'INV-001');

        $batch->load(['groups.items.payable']);
        $renderer = new PaymentBatchTransferSheetRenderer;
        $rows = $renderer->buildAllTransferRows(collect([$batch]));

        $this->assertEquals(9997500, $rows[0]['amount']);
    }

    public function test_account_number_preserves_leading_zero(): void
    {
        $batch = $this->createSupplierBatch(['batch_number' => 'DRP-2026-00001']);
        $group = $batch->groups()->create([
            'payee_type' => 'supplier',
            'payee_id' => $this->supplierUser->id,
            'payee_name' => 'Test',
            'bank_name' => 'BCA',
            'account_number' => '00123456789',
            'account_holder_name' => 'Test',
            'subtotal_amount' => 1000000,
            'bank_fee' => 0,
            'net_payment_amount' => 1000000,
            'status' => PaymentGroup::STATUS_UNPAID,
        ]);
        $inv = $this->createInvoice();
        $group->items()->create([
            'payable_type' => LocalInvoice::class,
            'payable_id' => $inv->id,
            'amount' => 1000000,
            'status' => PaymentItem::STATUS_ACTIVE,
        ]);

        $batch->load(['groups.items.payable']);
        $renderer = new PaymentBatchTransferSheetRenderer;
        $spreadsheet = $renderer->render(collect([$batch]));
        $sheet = $spreadsheet->getSheetByName('Data');

        $this->assertSame('00123456789', (string) $sheet->getCell('F2')->getValue());

        $spreadsheet->disconnectWorksheets();
    }

    public function test_bank_code_resolves_correctly(): void
    {
        $bca = BankTransferMapping::resolve('BCA');
        $this->assertNotNull($bca);
        $this->assertSame('CENAIDJA', $bca['sandi_bic']);
        $this->assertSame('BCA', $bca['transfer_type']);

        $mandiri = BankTransferMapping::resolve('MANDIRI');
        $this->assertNotNull($mandiri);
        $this->assertSame('BMRIIDJA', $mandiri['sandi_bic']);
        $this->assertSame('LLG', $mandiri['transfer_type']);

        $bni = BankTransferMapping::resolve('BNI');
        $this->assertNotNull($bni);
        $this->assertSame('BNINIDJA', $bni['sandi_bic']);
        $this->assertSame('LLG', $bni['transfer_type']);

        // BCA with branch suffix
        $bcaBranch = BankTransferMapping::resolve('BCA (KCU Karawang)');
        $this->assertNotNull($bcaBranch);
        $this->assertSame('CENAIDJA', $bcaBranch['sandi_bic']);
    }

    public function test_unresolvable_bank_causes_export_failure(): void
    {
        $batch = $this->createSupplierBatch(['batch_number' => 'DRP-2026-00001']);
        $group = $batch->groups()->create([
            'payee_type' => 'supplier',
            'payee_id' => $this->supplierUser->id,
            'payee_name' => 'Test',
            'bank_name' => 'BANK ACME UNKNOWN',
            'account_number' => '111',
            'account_holder_name' => 'Test',
            'subtotal_amount' => 1000000,
            'bank_fee' => 0,
            'net_payment_amount' => 1000000,
            'status' => PaymentGroup::STATUS_UNPAID,
        ]);
        $inv = $this->createInvoice();
        $group->items()->create([
            'payable_type' => LocalInvoice::class,
            'payable_id' => $inv->id,
            'amount' => 1000000,
            'status' => PaymentItem::STATUS_ACTIVE,
        ]);

        // Controller-level validation should reject
        $this->actingAs($this->finance)
            ->postJson(route('finance.drp.export-transfer'), [
                'batch_ids' => [$batch->hash],
            ])
            ->assertStatus(422)
            ->assertSee('BANK ACME UNKNOWN');
    }

    public function test_transaction_id_unique_and_deterministic(): void
    {
        [$batch1] = $this->createBatchWithGroup('DRP-2026-00001', 'BCA', 5000000, 'INV-001', '2026-09-01');

        // Add second group to batch1
        $group2 = $batch1->groups()->create([
            'payee_type' => 'supplier',
            'payee_id' => $this->supplierUser->id,
            'payee_name' => 'Supplier 2',
            'bank_name' => 'BNI',
            'account_number' => '222',
            'account_holder_name' => 'Supplier 2',
            'subtotal_amount' => 3000000,
            'bank_fee' => 0,
            'net_payment_amount' => 3000000,
            'status' => PaymentGroup::STATUS_UNPAID,
        ]);
        $inv2 = $this->createInvoice(['invoice_number' => 'INV-002']);
        $group2->items()->create([
            'payable_type' => LocalInvoice::class,
            'payable_id' => $inv2->id,
            'amount' => 3000000,
            'status' => PaymentItem::STATUS_ACTIVE,
        ]);

        [$batch2] = $this->createBatchWithGroup('DRP-2026-00004', 'MANDIRI', 7000000, 'INV-003', '2026-09-02');

        $batches = PaymentBatch::whereIn('id', [$batch1->id, $batch2->id])
            ->with(['groups.items.payable'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $renderer = new PaymentBatchTransferSheetRenderer;
        $rows = $renderer->buildAllTransferRows($batches);

        $transactionIds = array_column($rows, 'transaction_id');

        // All unique
        $this->assertSame(count($transactionIds), count(array_unique($transactionIds)));

        // Deterministic: same input → same output
        $rows2 = $renderer->buildAllTransferRows($batches);
        $transactionIds2 = array_column($rows2, 'transaction_id');
        $this->assertSame($transactionIds, $transactionIds2);
    }

    public function test_pic_email_resolved_from_supplier_profile(): void
    {
        [$batch] = $this->createBatchWithGroup('DRP-2026-00001');
        $batch->load(['groups.items.payable']);

        $renderer = new PaymentBatchTransferSheetRenderer;
        $rows = $renderer->buildAllTransferRows(collect([$batch]));

        $this->assertSame('supplier@example.com', $rows[0]['beneficiary_email']);
    }

    public function test_remarks_never_exceed_18_characters(): void
    {
        [$batch, $group] = $this->createBatchWithGroup('DRP-2026-00001', 'BCA', 5000000, 'INV-VERY-LONG-NUMBER-1');

        // Add multiple invoices so remarks would overflow without strict truncation
        $inv2 = $this->createInvoice(['invoice_number' => 'INV-VERY-LONG-NUMBER-2']);
        $group->items()->create([
            'payable_type' => LocalInvoice::class,
            'payable_id' => $inv2->id,
            'amount' => 1000000,
            'status' => PaymentItem::STATUS_ACTIVE,
        ]);

        $batch->load(['groups.items.payable']);

        $renderer = new PaymentBatchTransferSheetRenderer;
        $rows = $renderer->buildAllTransferRows(collect([$batch]));

        $this->assertLessThanOrEqual(18, strlen($rows[0]['remark_1']));
        $this->assertLessThanOrEqual(18, strlen($rows[0]['remark_2']));
    }

    public function test_data_validation_applied_to_remarks_and_receiver_name(): void
    {
        [$batch] = $this->createBatchWithGroup('DRP-2026-00001');
        $batch->load(['groups.items.payable']);

        $renderer = new PaymentBatchTransferSheetRenderer;
        $spreadsheet = $renderer->render(collect([$batch]));
        $sheet = $spreadsheet->getSheetByName('Data');

        // Check M1:N1048576 covers Remark 1 and Remark 2 across all rows (existing and newly added)
        $this->assertTrue($sheet->dataValidationExists('M1:N1048576'));
        $dvM = $sheet->getDataValidation('M1:N1048576');
        $this->assertSame('18', $dvM->getFormula1());
        $this->assertSame('M1:N1048576', $dvM->getSqref());
        $this->assertSame('Disamakan dengan kolom Transaction ID', $dvM->getPrompt());

        // Check Q1:Q1048576 covers Receiver Name across all rows (existing and newly added)
        $this->assertTrue($sheet->dataValidationExists('Q1:Q1048576'));
        $dvQ = $sheet->getDataValidation('Q1:Q1048576');
        $this->assertSame('70', $dvQ->getFormula1());
        $this->assertSame('Q1:Q1048576', $dvQ->getSqref());
        $this->assertSame('Tidak lebih dari 70 karakter', $dvQ->getPrompt());

        $spreadsheet->disconnectWorksheets();
    }

    // ─── Workbook Structure Tests ─────────────────────────────────────

    public function test_exactly_one_data_sheet(): void
    {
        [$batch] = $this->createBatchWithGroup('DRP-2026-00001');
        $batch->load(['groups.items.payable']);

        $renderer = new PaymentBatchTransferSheetRenderer;
        $spreadsheet = $renderer->render(collect([$batch]));

        $this->assertSame(1, $spreadsheet->getSheetCount());
        $this->assertSame(['Data'], $spreadsheet->getSheetNames());

        $spreadsheet->disconnectWorksheets();
    }

    public function test_columns_a_through_u_preserved(): void
    {
        [$batch] = $this->createBatchWithGroup('DRP-2026-00001');
        $batch->load(['groups.items.payable']);

        $renderer = new PaymentBatchTransferSheetRenderer;
        $spreadsheet = $renderer->render(collect([$batch]));
        $sheet = $spreadsheet->getSheetByName('Data');

        // Verify headers at row 7
        $headerRow = PaymentBatchTransferSheetRenderer::HEADER_ROW;
        $expectedHeaders = PaymentBatchTransferSheetRenderer::COLUMNS;

        foreach ($expectedHeaders as $col => $label) {
            $this->assertSame($label, $sheet->getCell($col.$headerRow)->getValue(), "Header at {$col}{$headerRow}");
        }

        $spreadsheet->disconnectWorksheets();
    }

    public function test_numeric_amount_values(): void
    {
        [$batch] = $this->createBatchWithGroup('DRP-2026-00001', 'BCA', 5000000);
        $batch->load(['groups.items.payable']);

        $renderer = new PaymentBatchTransferSheetRenderer;
        $spreadsheet = $renderer->render(collect([$batch]));
        $sheet = $spreadsheet->getSheetByName('Data');

        $amountValue = $sheet->getCell('G2')->getValue();
        $this->assertIsFloat($amountValue);
        $this->assertEquals(5000000, $amountValue);

        $spreadsheet->disconnectWorksheets();
    }

    public function test_currency_is_idr(): void
    {
        [$batch] = $this->createBatchWithGroup('DRP-2026-00001');
        $batch->load(['groups.items.payable']);

        $renderer = new PaymentBatchTransferSheetRenderer;
        $spreadsheet = $renderer->render(collect([$batch]));
        $sheet = $spreadsheet->getSheetByName('Data');

        $this->assertSame('IDR', $sheet->getCell('J2')->getValue());

        $spreadsheet->disconnectWorksheets();
    }

    public function test_charges_type_is_our(): void
    {
        [$batch] = $this->createBatchWithGroup('DRP-2026-00001');
        $batch->load(['groups.items.payable']);

        $renderer = new PaymentBatchTransferSheetRenderer;
        $spreadsheet = $renderer->render(collect([$batch]));
        $sheet = $spreadsheet->getSheetByName('Data');

        $this->assertSame('OUR', $sheet->getCell('K2')->getValue());

        $spreadsheet->disconnectWorksheets();
    }

    // ─── Full Export Pipeline Test ────────────────────────────────────

    public function test_full_bulk_export_generates_valid_workbook(): void
    {
        // DRP A → 2 groups
        [$batchA] = $this->createBatchWithGroup('DRP-2026-00001', 'BCA', 5000000, 'INV-A1', '2026-09-01');
        $groupA2 = $batchA->groups()->create([
            'payee_type' => 'supplier',
            'payee_id' => $this->supplierUser->id,
            'payee_name' => 'PT Supplier B',
            'bank_name' => 'BNI',
            'account_number' => '0222333444',
            'account_holder_name' => 'PT Supplier B',
            'subtotal_amount' => 3002500,
            'bank_fee' => 2500,
            'net_payment_amount' => 3000000,
            'status' => PaymentGroup::STATUS_UNPAID,
        ]);
        $invA2 = $this->createInvoice(['invoice_number' => 'INV-A2']);
        $groupA2->items()->create([
            'payable_type' => LocalInvoice::class,
            'payable_id' => $invA2->id,
            'amount' => 3002500,
            'status' => PaymentItem::STATUS_ACTIVE,
        ]);

        // DRP B → 1 group
        [$batchB] = $this->createBatchWithGroup('DRP-2026-00004', 'MANDIRI', 7000000, 'INV-B1', '2026-09-02');

        // DRP C → 3 groups
        [$batchC] = $this->createBatchWithGroup('DRP-2026-00007', 'BRI', 2000000, 'INV-C1', '2026-09-03');
        foreach ([['CIMB NIAGA', 4000000, 'INV-C2'], ['DANAMON', 6000000, 'INV-C3']] as [$bank, $amount, $invNum]) {
            $g = $batchC->groups()->create([
                'payee_type' => 'supplier',
                'payee_id' => $this->supplierUser->id,
                'payee_name' => 'PT Supplier Extra',
                'bank_name' => $bank,
                'account_number' => '0555666777',
                'account_holder_name' => 'PT Supplier Extra',
                'subtotal_amount' => $amount,
                'bank_fee' => 0,
                'net_payment_amount' => $amount,
                'status' => PaymentGroup::STATUS_UNPAID,
            ]);
            $inv = $this->createInvoice(['invoice_number' => $invNum]);
            $g->items()->create([
                'payable_type' => LocalInvoice::class,
                'payable_id' => $inv->id,
                'amount' => $amount,
                'status' => PaymentItem::STATUS_ACTIVE,
            ]);
        }

        // Dispatch through ExportDispatcher
        $this->actingAs($this->finance);
        $record = ExportDispatcher::dispatch(
            'Bulk Transfer Export Test',
            PaymentBatchTransferExport::class,
            [$this->finance->id, [$batchA->id, $batchB->id, $batchC->id]],
            'DRP_TRANSFER_test.xlsx'
        );

        // Execute jobs synchronously
        (new ProcessExportJob($record->id))->handle(app(ExportProgressService::class));
        (new GenerateWorkbookJob($record->id))->handle(app(ExportProgressService::class));

        $record->refresh();
        $this->assertSame(ExportJob::STATUS_COMPLETED, $record->status);
        $this->assertTrue(Storage::disk('private')->exists($record->file_path));

        // Inspect the generated workbook
        $tempFile = tempnam(sys_get_temp_dir(), 'test_transfer_');
        file_put_contents($tempFile, Storage::disk('private')->get($record->file_path));

        $spreadsheet = IOFactory::load($tempFile);

        // Exactly one sheet named "Data"
        $this->assertSame(['Data'], $spreadsheet->getSheetNames());

        $sheet = $spreadsheet->getSheetByName('Data');
        $this->assertNotNull($sheet);

        // 6 transfer rows total (2 + 1 + 3)
        $expectedRowCount = 6;
        for ($i = 0; $i < $expectedRowCount; $i++) {
            $r = PaymentBatchTransferSheetRenderer::DATA_START_ROW + $i;
            $this->assertEquals($i + 1, $sheet->getCell("A{$r}")->getValue(), "Row {$r} should have No = ".($i + 1));
        }

        // Row after last data should be empty (no leftover sample data)
        $afterLastRow = PaymentBatchTransferSheetRenderer::DATA_START_ROW + $expectedRowCount;
        $this->assertEmpty($sheet->getCell("A{$afterLastRow}")->getValue());

        // Verify transaction IDs are unique
        $transactionIds = [];
        for ($i = 0; $i < $expectedRowCount; $i++) {
            $r = PaymentBatchTransferSheetRenderer::DATA_START_ROW + $i;
            $tid = $sheet->getCell("B{$r}")->getValue();
            $this->assertNotEmpty($tid, "Transaction ID at B{$r} should not be empty");
            $transactionIds[] = $tid;
        }
        $this->assertSame(count($transactionIds), count(array_unique($transactionIds)), 'Transaction IDs must be unique');

        // Verify amounts are numeric
        for ($i = 0; $i < $expectedRowCount; $i++) {
            $r = PaymentBatchTransferSheetRenderer::DATA_START_ROW + $i;
            $amount = $sheet->getCell("G{$r}")->getValue();
            $this->assertIsNumeric($amount, "Amount at G{$r} should be numeric");
            $this->assertGreaterThan(0, $amount, "Amount at G{$r} should be positive");
        }

        // Verify headers
        foreach (PaymentBatchTransferSheetRenderer::COLUMNS as $col => $label) {
            $headerRow = PaymentBatchTransferSheetRenderer::HEADER_ROW;
            $this->assertSame($label, $sheet->getCell($col.$headerRow)->getValue());
        }

        // Scan for formula errors
        $highestRow = $sheet->getHighestRow();
        for ($r = 1; $r <= $highestRow; $r++) {
            for ($col = 'A'; $col <= 'U'; $col++) {
                $val = (string) $sheet->getCell($col.$r)->getValue();
                $this->assertFalse(str_contains($val, '#REF!'), "Cell {$col}{$r} contains #REF!");
                $this->assertFalse(str_contains($val, '#VALUE!'), "Cell {$col}{$r} contains #VALUE!");
            }
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
        @unlink($tempFile);
    }

    // ─── Regression Tests ─────────────────────────────────────────────

    public function test_export_dispatcher_supports_transfer_export(): void
    {
        $this->assertTrue(ExportDispatcher::isSupported(PaymentBatchTransferExport::class));
    }

    public function test_existing_drp_rekap_export_still_works(): void
    {
        $this->assertTrue(ExportDispatcher::isSupported(PaymentBatchDrpExport::class));
    }

    public function test_existing_single_batch_export_route_still_works(): void
    {
        [$batch] = $this->createBatchWithGroup('DRP-2026-00001');

        $this->actingAs($this->finance)
            ->getJson(route('finance.drp.export', $batch))
            ->assertStatus(202);
    }

    public function test_finance_can_download_completed_transfer_export(): void
    {
        [$batch] = $this->createBatchWithGroup('DRP-2026-00001');

        $record = ExportJob::create([
            'user_id' => $this->finance->id,
            'label' => 'Transfer DRP: DRP-2026-00001',
            'export_class' => PaymentBatchTransferExport::class,
            'export_args' => [$this->finance->id, [$batch->id]],
            'file_name' => 'DRP_TRANSFER_test.xlsx',
            'file_path' => 'exports/'.$this->finance->id.'/1/DRP_TRANSFER_test.xlsx',
            'disk' => 'private',
            'status' => ExportJob::STATUS_COMPLETED,
            'completed_at' => now(),
            'expires_at' => now()->addDays(3),
        ]);

        Storage::disk('private')->put($record->file_path, 'fake-excel-content');

        $this->actingAs($this->finance)
            ->get(route('exports.download', $record))
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename=DRP_TRANSFER_test.xlsx');
    }

    public function test_finance_can_see_transfer_export_in_exports_index(): void
    {
        [$batch] = $this->createBatchWithGroup('DRP-2026-00001');

        $record = ExportJob::create([
            'user_id' => $this->finance->id,
            'label' => 'Transfer DRP: DRP-2026-00001',
            'export_class' => PaymentBatchTransferExport::class,
            'export_args' => [$this->finance->id, [$batch->id]],
            'file_name' => 'DRP_TRANSFER_test.xlsx',
            'disk' => 'private',
            'status' => ExportJob::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        $response = $this->actingAs($this->finance)
            ->get(route('exports.index'))
            ->assertOk();

        $response->assertSee('Transfer DRP: DRP-2026-00001');
    }
}
