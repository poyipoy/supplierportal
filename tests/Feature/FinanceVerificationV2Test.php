<?php

namespace Tests\Feature;

use App\Models\LocalInvoice;
use App\Models\LocalInvoiceVerification;
use App\Models\Supplier;
use App\Models\SupplierScope;
use App\Models\User;
use App\Services\LocalInvoice\InvoicePhysicalReceiptService;
use App\Services\LocalInvoice\InvoiceSubmissionService;
use App\Services\LocalInvoice\InvoiceVerificationService;
use App\Services\LocalInvoice\LocalPoReferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

class FinanceVerificationV2Test extends TestCase
{
    use RefreshDatabase;

    protected InvoiceSubmissionService $submissionService;

    protected InvoicePhysicalReceiptService $receiptService;

    protected InvoiceVerificationService $verificationService;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        LocalPoReferenceService::clearMockPos();
        $this->submissionService = app(InvoiceSubmissionService::class);
        $this->receiptService = app(InvoicePhysicalReceiptService::class);
        $this->verificationService = app(InvoiceVerificationService::class);
    }

    private function createReadyForVerificationInvoice(): array
    {
        $supplierUser = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        SupplierScope::create(['supplier_id' => $supplierUser->id, 'scope' => 'local']);

        Supplier::create([
            'user_id' => $supplierUser->id,
            'company_name' => 'PT Metal Perkasa',
            'vendor_category' => 'Barang',
            'is_pkp' => true,
            'payment_term_days' => 30,
        ]);

        LocalPoReferenceService::registerInternalPo(
            $supplierUser->id,
            'PO-VERIF-001',
            value: 20000000.0,
            grReference: 'GR-VERIF-001'
        );

        $invoice = $this->submissionService->submit(
            $supplierUser,
            [
                'invoice_number' => 'INV-VERIF-001',
                'invoice_date' => '2026-09-10',
                'po_source' => 'INTERNAL',
                'po_number' => 'PO-VERIF-001',
                'internal_po_reference' => 'PO-VERIF-001',
                'invoice_amount' => 10000000,
                'ppn_scheme' => '11%',
            ],
            [
                'invoice' => UploadedFile::fake()->create('inv.pdf', 100),
                'tax_invoice' => UploadedFile::fake()->create('tax.pdf', 100),
                'delivery_note' => UploadedFile::fake()->create('sj.pdf', 100),
            ]
        );

        $finance = User::factory()->create(['role' => 'finance']);
        $invoice = $this->receiptService->recordReceipt($finance, $invoice);

        return [$invoice, $finance, $supplierUser];
    }

    public function test_complete_verification_workflow_to_ready_to_pay(): void
    {
        /** @var LocalInvoice $invoice */
        [$invoice, $finance] = $this->createReadyForVerificationInvoice();

        $this->assertSame(LocalInvoice::STATUS_UNDER_VERIFICATION, $invoice->status);

        // 1. Section A: Document & Reference verification
        $sectionA = $this->verificationService->verifySectionA($invoice, [
            'invoice_check' => LocalInvoiceVerification::CHECK_OK,
            'tax_invoice_check' => LocalInvoiceVerification::CHECK_OK,
            'po_check' => LocalInvoiceVerification::CHECK_OK,
            'delivery_note_check' => LocalInvoiceVerification::CHECK_OK,
            'gr_check' => LocalInvoiceVerification::CHECK_OK,
        ], $finance);

        $this->assertTrue($sectionA->is_section_a_passed);
        $this->assertFalse($sectionA->is_locked);

        // 2. Section B: Tax verification (PPN 11%, PPh 23 = 2% of 10,000,000 = 200,000)
        $sectionB = $this->verificationService->verifySectionB($invoice, [
            'ppn_status' => LocalInvoiceVerification::PPN_SESUAI,
            'verified_ppn' => 1100000,
            'pph_23_applicable' => true,
            'pph_23_base' => 10000000,
            'pph_23_rate' => 2.0,
            'pph_23_amount' => 200000,
            'tax_notes' => 'PPh 23 jasa teknik 2% confirmed.',
        ], $finance);

        $this->assertTrue($sectionB->is_section_b_passed);
        $this->assertEquals(200000.0, $sectionB->totalWithholding());
        // Net payable = 10,000,000 + 1,100,000 - 200,000 = 10,900,000
        $this->assertEquals(10900000.0, $sectionB->calculateNetPayable(10000000.0));

        // 3. Lock & Transition to READY_TO_PAY
        $readyToPayInvoice = $this->verificationService->lockAndApprove($invoice, $finance);

        $this->assertSame(LocalInvoice::STATUS_READY_TO_PAY, $readyToPayInvoice->status);
        $this->assertNotNull($readyToPayInvoice->ready_to_pay_at);
        $this->assertTrue($readyToPayInvoice->isReadyToPay());

        $verification = $readyToPayInvoice->currentVerification;
        $this->assertTrue($verification->is_locked);
        $this->assertSame($finance->id, $verification->verified_by);
    }

    public function test_purchasing_and_supplier_cannot_mutate_verification(): void
    {
        [$invoice] = $this->createReadyForVerificationInvoice();

        $purchasing = User::factory()->create(['role' => 'purchasing']);
        $supplier = $invoice->supplier;

        $this->expectException(InvalidArgumentException::class);
        $this->verificationService->verifySectionA($invoice, [
            'invoice_check' => LocalInvoiceVerification::CHECK_OK,
        ], $purchasing);

        $this->expectException(InvalidArgumentException::class);
        $this->verificationService->verifySectionA($invoice, [
            'invoice_check' => LocalInvoiceVerification::CHECK_OK,
        ], $supplier);
    }

    public function test_section_a_not_ok_requires_reason_and_blocks_ready_to_pay(): void
    {
        [$invoice, $finance] = $this->createReadyForVerificationInvoice();

        // NOT OK without reason must fail validation
        $this->expectException(InvalidArgumentException::class);
        $this->verificationService->verifySectionA($invoice, [
            'invoice_check' => LocalInvoiceVerification::CHECK_NOT_OK,
            'invoice_notes' => '', // Empty!
        ], $finance);
    }

    public function test_need_revision_flow_and_resubmission(): void
    {
        [$invoice, $finance, $supplierUser] = $this->createReadyForVerificationInvoice();

        // Request revision with mandatory reason
        $revised = $this->verificationService->requestRevision($invoice, 'Faktur pajak tidak terbaca QR codenya.', $finance);
        $this->assertSame(LocalInvoice::STATUS_NEED_REVISION, $revised->status);

        // Supplier resubmits
        $resubmitted = $this->submissionService->resubmit(
            $supplierUser,
            $revised,
            [
                'invoice_number' => $invoice->invoice_number,
                'invoice_date' => $invoice->invoice_date->toDateString(),
                'po_number' => $invoice->po_number,
                'invoice_amount' => $invoice->invoice_amount,
                'tax_amount' => $invoice->tax_amount,
            ],
            [
                'invoice' => UploadedFile::fake()->create($invoice->invoice_number.'.pdf', 100),
                'tax_invoice' => UploadedFile::fake()->create('tax_v2.pdf', 100),
                'delivery_note' => UploadedFile::fake()->create('sj_v2.pdf', 100),
            ]
        );

        // Resubmission returns invoice to WAITING_PHYSICAL_DOCUMENT, increments revision to 2, and resets physical receipt
        $this->assertSame(LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT, $resubmitted->status);
        $this->assertSame(2, $resubmitted->revision_number);
        $this->assertNull($resubmitted->cashier_received_at);
        $this->assertCount(2, $resubmitted->revisions);
    }

    public function test_finance_dashboard_renders_successfully(): void
    {
        $finance = User::factory()->create(['role' => 'finance', 'is_active' => true]);
        $response = $this->actingAs($finance)->get(route('finance.dashboard'));
        $response->assertOk();
        $response->assertSee('Payment Forecast');
        $response->assertSee('Akumulasi Invoice Ready to Pay');

        $this->actingAs($finance)->get(route('finance.invoices.index'))->assertOk();
        $this->actingAs($finance)->get(route('finance.drp.supplier'))->assertOk();
        $this->actingAs($finance)->get(route('finance.master-invoices'))->assertOk();
        $this->actingAs($finance)->get(route('finance.vendor-master.index'))->assertOk();
    }

    public function test_invoice_is_overdue_method_and_detail_views_render(): void
    {
        [$invoice, $finance] = $this->createReadyForVerificationInvoice();
        $purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);

        // Future due date -> not overdue
        $invoice->update(['due_date' => today()->addDays(10)]);
        $this->assertFalse($invoice->isOverdue());
        $this->assertFalse($invoice->is_overdue);

        // Past due date & unpaid -> overdue
        $invoice->update(['due_date' => today()->subDays(5)]);
        $this->assertTrue($invoice->isOverdue());
        $this->assertTrue($invoice->is_overdue);

        // Paid -> not overdue
        $invoice->update(['status' => LocalInvoice::STATUS_PAID]);
        $this->assertFalse($invoice->isOverdue());

        // Reset to WAITING_PHYSICAL_DOCUMENT and past due date
        $invoice->update(['status' => LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT, 'due_date' => today()->subDays(2)]);

        // Detail view in Finance renders OK
        $this->actingAs($finance)->get(route('finance.invoices.show', $invoice))->assertOk();

        // Read-only detail view in Purchasing renders OK
        $this->actingAs($purchasing)->get(route('purchasing.local-invoices.show', $invoice))->assertOk();
    }

    public function test_pkp_supplier_cannot_use_not_applicable_for_tax_invoice(): void
    {
        [$invoice, $finance] = $this->createReadyForVerificationInvoice();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Tax Invoice (Faktur Pajak) cannot be NOT APPLICABLE for PKP suppliers.');

        $this->verificationService->verifySectionA($invoice, [
            'invoice_check' => LocalInvoiceVerification::CHECK_OK,
            'tax_invoice_check' => LocalInvoiceVerification::CHECK_NOT_APPLICABLE,
            'po_check' => LocalInvoiceVerification::CHECK_OK,
            'delivery_note_check' => LocalInvoiceVerification::CHECK_OK,
            'gr_check' => LocalInvoiceVerification::CHECK_OK,
        ], $finance);
    }

    public function test_barang_vendor_cannot_use_not_applicable_for_surat_jalan(): void
    {
        [$invoice, $finance] = $this->createReadyForVerificationInvoice();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Delivery Note (Surat Jalan) cannot be NOT APPLICABLE for goods (Barang) suppliers.');

        $this->verificationService->verifySectionA($invoice, [
            'invoice_check' => LocalInvoiceVerification::CHECK_OK,
            'tax_invoice_check' => LocalInvoiceVerification::CHECK_OK,
            'po_check' => LocalInvoiceVerification::CHECK_OK,
            'delivery_note_check' => LocalInvoiceVerification::CHECK_NOT_APPLICABLE,
            'gr_check' => LocalInvoiceVerification::CHECK_OK,
        ], $finance);
    }

    public function test_tidak_sesuai_ppn_requires_corrected_amount_and_notes(): void
    {
        [$invoice, $finance] = $this->createReadyForVerificationInvoice();

        $this->verificationService->verifySectionA($invoice, [
            'invoice_check' => LocalInvoiceVerification::CHECK_OK,
            'tax_invoice_check' => LocalInvoiceVerification::CHECK_OK,
            'po_check' => LocalInvoiceVerification::CHECK_OK,
            'delivery_note_check' => LocalInvoiceVerification::CHECK_OK,
            'gr_check' => LocalInvoiceVerification::CHECK_OK,
        ], $finance);

        // Missing notes must throw
        try {
            $this->verificationService->verifySectionB($invoice, [
                'ppn_status' => LocalInvoiceVerification::PPN_TIDAK_SESUAI,
                'verified_ppn' => 990000,
                'tax_notes' => '',
            ], $finance);
            $this->fail('Expected InvalidArgumentException for empty notes');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Verification notes are mandatory', $e->getMessage());
        }

        // Missing verified_ppn must throw
        try {
            $this->verificationService->verifySectionB($invoice, [
                'ppn_status' => LocalInvoiceVerification::PPN_TIDAK_SESUAI,
                'verified_ppn' => null,
                'tax_notes' => 'Corrected note',
            ], $finance);
            $this->fail('Expected InvalidArgumentException for missing verified_ppn');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Corrected PPN amount is mandatory', $e->getMessage());
        }
    }

    public function test_verify_section_a_and_b_ajax_and_redirect_behavior(): void
    {
        [$invoice, $finance] = $this->createReadyForVerificationInvoice();

        // 1. AJAX Section A
        $responseA = $this->actingAs($finance)->postJson(route('finance.invoices.verify-section-a', $invoice), [
            'invoice_check' => 'OK',
            'tax_invoice_check' => 'OK',
            'po_check' => 'OK',
            'delivery_note_check' => 'OK',
            'gr_check' => 'OK',
        ]);

        $responseA->assertOk()
            ->assertJson([
                'success' => true,
                'section' => 'A',
                'is_section_a_passed' => true,
                'can_approve' => false,
            ]);

        // 2. AJAX Section B
        $responseB = $this->actingAs($finance)->postJson(route('finance.invoices.verify-section-b', $invoice), [
            'ppn_status' => 'SESUAI',
            'verified_ppn' => 1100000,
            'pph_23_applicable' => false,
        ]);

        $responseB->assertOk()
            ->assertJson([
                'success' => true,
                'section' => 'B',
                'is_section_b_passed' => true,
                'can_approve' => true,
            ]);

        // 3. Non-AJAX redirect includes anchor
        $responseRedirectA = $this->actingAs($finance)->post(route('finance.invoices.verify-section-a', $invoice), [
            'invoice_check' => 'OK',
            'tax_invoice_check' => 'OK',
            'po_check' => 'OK',
            'delivery_note_check' => 'OK',
            'gr_check' => 'OK',
        ]);

        $responseRedirectA->assertRedirect(route('finance.invoices.show', $invoice).'#section-a');
    }

    public function test_section_a_and_b_are_disabled_and_locked_when_invoice_is_approved_ready_to_pay(): void
    {
        [$invoice, $finance] = $this->createReadyForVerificationInvoice();

        // 1. Initially in UNDER_VERIFICATION, form inputs are editable and save buttons are present
        $responseInitial = $this->actingAs($finance)->get(route('finance.invoices.show', $invoice));
        $responseInitial->assertOk();
        $responseInitial->assertDontSee('Terkunci (Read-Only)');
        $responseInitial->assertSee('Simpan Section A');
        $responseInitial->assertSee('Simpan Section B');
        $responseInitial->assertDontSee('<fieldset disabled', false);

        // 2. Perform verification and approve to READY_TO_PAY
        $this->verificationService->verifySectionA($invoice, [
            'invoice_check' => LocalInvoiceVerification::CHECK_OK,
            'tax_invoice_check' => LocalInvoiceVerification::CHECK_OK,
            'po_check' => LocalInvoiceVerification::CHECK_OK,
            'delivery_note_check' => LocalInvoiceVerification::CHECK_OK,
            'gr_check' => LocalInvoiceVerification::CHECK_OK,
        ], $finance);

        $this->verificationService->verifySectionB($invoice, [
            'ppn_status' => LocalInvoiceVerification::PPN_SESUAI,
            'verified_ppn' => 1100000,
            'pph_23_applicable' => true,
            'pph_23_base' => 10000000,
            'pph_23_rate' => 2.0,
            'pph_23_amount' => 200000,
        ], $finance);

        $this->verificationService->lockAndApprove($invoice, $finance);
        $invoice->refresh();

        $this->assertSame(LocalInvoice::STATUS_READY_TO_PAY, $invoice->status);
        $this->assertTrue($invoice->currentVerification->is_locked);

        // 3. Re-visit show page: must be locked
        $responseLocked = $this->actingAs($finance)->get(route('finance.invoices.show', $invoice));
        $responseLocked->assertOk();

        // Must display locked chips and notices
        $responseLocked->assertSee('Terkunci (Read-Only)');
        $responseLocked->assertSee('Section A telah terkunci dan disetujui');
        $responseLocked->assertSee('Section B telah diverifikasi dan dikunci');

        // Form controls must be inside fieldset disabled
        $responseLocked->assertSee('<fieldset disabled', false);

        // Save buttons and Final Approval button must NOT be present
        $responseLocked->assertDontSee('Simpan Section A');
        $responseLocked->assertDontSee('Simpan Section B');
        $responseLocked->assertDontSee('Kunci & Setujui Ready to Pay');
        $responseLocked->assertDontSee('btn-approve-ready-to-pay');
    }
}
