<?php

namespace Tests\Feature;

use App\Models\LocalInvoice;
use App\Models\Supplier;
use App\Models\SupplierScope;
use App\Models\User;
use App\Services\LocalInvoice\InvoiceExpiryService;
use App\Services\LocalInvoice\InvoicePhysicalReceiptService;
use App\Services\LocalInvoice\InvoiceSubmissionService;
use App\Services\LocalInvoice\LocalPoReferenceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

class CashierReceiptAndExpiryTest extends TestCase
{
    use RefreshDatabase;

    protected InvoiceSubmissionService $submissionService;

    protected InvoicePhysicalReceiptService $receiptService;

    protected InvoiceExpiryService $expiryService;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        LocalPoReferenceService::clearMockPos();
        $this->submissionService = app(InvoiceSubmissionService::class);
        $this->receiptService = app(InvoicePhysicalReceiptService::class);
        $this->expiryService = app(InvoiceExpiryService::class);
    }

    private function createSupplier(int $paymentTermDays = 30): User
    {
        $user = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        SupplierScope::create(['supplier_id' => $user->id, 'scope' => 'local']);

        Supplier::create([
            'user_id' => $user->id,
            'company_name' => 'PT Baja Bersama',
            'vendor_category' => 'Barang',
            'is_pkp' => true,
            'payment_term_days' => $paymentTermDays,
        ]);

        return $user;
    }

    public function test_cashier_receipt_calculates_due_date_and_locks_term_snapshot(): void
    {
        $supplier = $this->createSupplier(paymentTermDays: 45);
        $finance = User::factory()->create(['role' => 'finance']);

        LocalPoReferenceService::registerInternalPo(
            $supplier->id,
            'PO-RECEIPT-001',
            value: 10000000.0,
            grReference: 'GR-001'
        );

        $invoice = $this->submissionService->submit(
            $supplier,
            [
                'invoice_number' => 'INV-REC-001',
                'invoice_date' => '2026-09-10',
                'po_source' => 'INTERNAL',
                'po_number' => 'PO-RECEIPT-001',
                'internal_po_reference' => 'PO-RECEIPT-001',
                'invoice_amount' => 5000000,
            ],
            [
                'invoice' => UploadedFile::fake()->create('inv.pdf', 100),
                'tax_invoice' => UploadedFile::fake()->create('tax.pdf', 100),
                'delivery_note' => UploadedFile::fake()->create('sj.pdf', 100),
            ]
        );

        // Before cashier receipt: status is WAITING_PHYSICAL_DOCUMENT and due_date is null!
        $this->assertSame(LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT, $invoice->status);
        $this->assertNull($invoice->due_date);

        // Cashier records physical receipt
        $receivedInvoice = $this->receiptService->recordReceipt($finance, $invoice, 'Physical docs received in good order.');

        $this->assertSame(LocalInvoice::STATUS_UNDER_VERIFICATION, $receivedInvoice->status);
        $this->assertNotNull($receivedInvoice->cashier_received_at);
        $this->assertSame($finance->id, $receivedInvoice->cashier_received_by);
        $this->assertSame(45, $receivedInvoice->payment_term_days_snapshot);

        $expectedDueDate = Carbon::parse($receivedInvoice->cashier_received_at)->addDays(45)->toDateString();
        $this->assertSame($expectedDueDate, $receivedInvoice->due_date->toDateString());

        // Now mutate Vendor Master payment term to 60 days
        $supplier->supplier->update(['payment_term_days' => 60]);

        // Verify historical invoice due date has NOT changed!
        $receivedInvoice->refresh();
        $this->assertSame(45, $receivedInvoice->payment_term_days_snapshot);
        $this->assertSame($expectedDueDate, $receivedInvoice->due_date->toDateString());
    }

    public function test_missed_delivery_rescheduling_and_expiry_after_second_miss(): void
    {
        $supplier = $this->createSupplier();
        $finance = User::factory()->create(['role' => 'finance']);

        LocalPoReferenceService::registerInternalPo(
            $supplier->id,
            'PO-EXP-001',
            value: 5000000.0,
            grReference: 'GR-EXP-001'
        );

        $invoice = $this->submissionService->submit(
            $supplier,
            [
                'invoice_number' => 'INV-EXP-001',
                'invoice_date' => '2026-09-10',
                'po_source' => 'INTERNAL',
                'po_number' => 'PO-EXP-001',
                'internal_po_reference' => 'PO-EXP-001',
                'invoice_amount' => 2000000,
            ],
            [
                'invoice' => UploadedFile::fake()->create('inv.pdf', 100),
                'tax_invoice' => UploadedFile::fake()->create('tax.pdf', 100),
                'delivery_note' => UploadedFile::fake()->create('sj.pdf', 100),
            ]
        );

        // 1st missed delivery
        $invAfterFirstMiss = $this->expiryService->recordMissedDelivery($invoice, $finance);
        $this->assertSame(1, $invAfterFirstMiss->missed_delivery_count);
        $this->assertSame(LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT, $invAfterFirstMiss->status);

        // Can reschedule to a future Wednesday
        $nextWed = Carbon::now()->next(Carbon::WEDNESDAY);
        $rescheduled = $this->expiryService->rescheduleDelivery($invAfterFirstMiss, $nextWed, $supplier);
        $this->assertSame($nextWed->toDateString(), $rescheduled->scheduled_physical_delivery_date->toDateString());

        // 2nd missed delivery -> becomes EXPIRED
        $invAfterSecondMiss = $this->expiryService->recordMissedDelivery($rescheduled, $finance);
        $this->assertSame(LocalInvoice::STATUS_EXPIRED, $invAfterSecondMiss->status);
        $this->assertNotNull($invAfterSecondMiss->expired_at);
        $this->assertTrue($invAfterSecondMiss->isExpired());
    }

    public function test_cannot_reschedule_to_non_wednesday(): void
    {
        $supplier = $this->createSupplier();
        $finance = User::factory()->create(['role' => 'finance']);

        LocalPoReferenceService::registerInternalPo(
            $supplier->id,
            'PO-EXP-002',
            value: 5000000.0,
            grReference: 'GR-EXP-002'
        );

        $invoice = $this->submissionService->submit(
            $supplier,
            [
                'invoice_number' => 'INV-EXP-002',
                'invoice_date' => '2026-09-10',
                'po_source' => 'INTERNAL',
                'po_number' => 'PO-EXP-002',
                'internal_po_reference' => 'PO-EXP-002',
                'invoice_amount' => 2000000,
            ],
            [
                'invoice' => UploadedFile::fake()->create('inv.pdf', 100),
                'tax_invoice' => UploadedFile::fake()->create('tax.pdf', 100),
                'delivery_note' => UploadedFile::fake()->create('sj.pdf', 100),
            ]
        );

        $invAfterFirstMiss = $this->expiryService->recordMissedDelivery($invoice, $finance);

        $tuesday = Carbon::now()->next(Carbon::TUESDAY);
        $this->expectException(InvalidArgumentException::class);
        $this->expiryService->rescheduleDelivery($invAfterFirstMiss, $tuesday, $supplier);
    }
}
