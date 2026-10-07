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
use App\Support\BusinessTime;
use App\Support\StatusHelper;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
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

    private function submitInvoiceForReceipt(User $supplier, string $invoiceNumber, string $poNumber): LocalInvoice
    {
        LocalPoReferenceService::registerInternalPo(
            $supplier->id,
            $poNumber,
            value: 10000000.0,
            grReference: 'GR-'.$invoiceNumber
        );

        return $this->submissionService->submit(
            $supplier,
            [
                'invoice_number' => $invoiceNumber,
                'invoice_date' => '2026-09-10',
                'po_source' => 'INTERNAL',
                'po_number' => $poNumber,
                'internal_po_reference' => $poNumber,
                'invoice_amount' => 2000000,
            ],
            [
                'invoice' => UploadedFile::fake()->create($invoiceNumber.'.pdf', 100),
                'tax_invoice' => UploadedFile::fake()->create('tax-'.$invoiceNumber.'.pdf', 100),
                'delivery_note' => UploadedFile::fake()->create('sj-'.$invoiceNumber.'.pdf', 100),
            ]
        );
    }

    private function signedReceiptQr(LocalInvoice $invoice, ?int $revision = null): string
    {
        return URL::signedRoute('receipts.verify-supplier', [
            'receipt' => $invoice->receipt->receipt_number,
            'revision' => $revision ?? $invoice->revision_number,
        ]);
    }

    public function test_cashier_receipt_calculates_due_date_and_locks_term_snapshot(): void
    {
        $supplier = $this->createSupplier(paymentTermDays: 45);
        $finance = User::factory()->create(['role' => 'finance']);
        $finance->preference()->create([...config('user_preferences.defaults'), 'locale' => 'id']);

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

        // Cashier scans the supplier receipt before recording physical receipt.
        $this->actingAs($finance)->post(route('finance.invoices.receive-physical', $invoice), [
            'receipt_qr' => $this->signedReceiptQr($invoice),
        ])->assertRedirect();
        $receivedInvoice = $invoice->fresh();

        $this->assertSame(LocalInvoice::STATUS_UNDER_VERIFICATION, $receivedInvoice->status);
        $this->assertNotNull($receivedInvoice->cashier_received_at);
        $this->assertSame($finance->id, $receivedInvoice->cashier_received_by);
        $this->assertSame(45, $receivedInvoice->payment_term_days_snapshot);

        $expectedDueDate = BusinessTime::toBusiness($receivedInvoice->cashier_received_at)
            ->startOfDay()
            ->addDays(45)
            ->toDateString();
        $this->assertSame($expectedDueDate, $receivedInvoice->due_date->toDateString());
        $this->assertSame(
            trans('local_invoice.history.physical_received', ['payment_term' => 45, 'due_date' => $expectedDueDate], 'id'),
            $receivedInvoice->statusHistories()->where('event', 'physical_received')->latest('id')->value('notes'),
        );
        $this->assertSame(
            trans('local_invoice.history.physical_received', ['payment_term' => 45, 'due_date' => $expectedDueDate], 'id'),
            $receivedInvoice->physicalVerifications()->latest('id')->value('notes'),
        );

        // Now mutate Vendor Master payment term to 60 days
        $supplier->supplier->update(['payment_term_days' => 60]);

        // Verify historical invoice due date has NOT changed!
        $receivedInvoice->refresh();
        $this->assertSame(45, $receivedInvoice->payment_term_days_snapshot);
        $this->assertSame($expectedDueDate, $receivedInvoice->due_date->toDateString());
    }

    public function test_cashier_receipt_rejects_missing_invalid_foreign_and_stale_revision_qr(): void
    {
        $supplier = $this->createSupplier();
        $finance = User::factory()->create(['role' => 'finance']);
        $invoice = $this->submitInvoiceForReceipt($supplier, 'INV-QR-001', 'PO-QR-001');
        $otherInvoice = $this->submitInvoiceForReceipt($supplier, 'INV-QR-002', 'PO-QR-002');
        $staleQr = $this->signedReceiptQr($invoice);
        $signatureMarker = 'signature=';
        $signatureOffset = strpos($staleQr, $signatureMarker);
        $this->assertNotFalse($signatureOffset);
        $signatureCharacterOffset = $signatureOffset + strlen($signatureMarker);
        $replacementCharacter = $staleQr[$signatureCharacterOffset] === '0' ? '1' : '0';
        $tamperedQr = substr_replace($staleQr, $replacementCharacter, $signatureCharacterOffset, 1);

        $response = $this->actingAs($finance)->post(
            route('finance.invoices.receive-physical', $invoice),
            ['notes' => 'Dokumen fisik diterima.']
        );
        $response->assertSessionHasErrors('receipt_qr');

        $invoice->update(['revision_number' => 2]);
        $invalidQrs = [
            null,
            'not-a-signed-receipt-url',
            $tamperedQr,
            $this->signedReceiptQr($otherInvoice),
            $staleQr,
        ];

        foreach ($invalidQrs as $scannedQr) {
            try {
                $this->receiptService->recordReceipt($finance, $invoice, scannedReceiptQr: $scannedQr);
                $this->fail('An invalid receipt QR must not record physical receipt.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('receipt_qr', $exception->errors());
            }

            $unchangedInvoice = $invoice->fresh();
            $this->assertSame(LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT, $unchangedInvoice->status);
            $this->assertNull($unchangedInvoice->cashier_received_at);
            $this->assertNull($unchangedInvoice->due_date);
        }
    }

    public function test_legacy_signed_qr_is_accepted_for_an_initial_invoice_revision(): void
    {
        $supplier = $this->createSupplier();
        $finance = User::factory()->create(['role' => 'finance']);
        $invoice = $this->submitInvoiceForReceipt($supplier, 'INV-QR-LEGACY', 'PO-QR-LEGACY');
        $legacyQr = URL::signedRoute('receipts.verify-supplier', [
            'receipt' => $invoice->receipt->receipt_number,
        ]);

        $received = $this->receiptService->recordReceipt($finance, $invoice, scannedReceiptQr: $legacyQr);

        $this->assertSame(LocalInvoice::STATUS_UNDER_VERIFICATION, $received->status);
        $this->assertNotNull($received->cashier_received_at);
    }

    public function test_missed_delivery_rescheduling_and_expiry_after_second_miss(): void
    {
        app()->setLocale('id');
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
        $firstMissHistory = $invAfterFirstMiss->statusHistories()->where('event', 'delivery_missed')->latest('id')->firstOrFail();
        $this->assertSame(__('local_invoice.history.delivery_missed', [], 'id'), $firstMissHistory->notes);

        // Can reschedule to a future Wednesday
        $nextWed = Carbon::now()->next(Carbon::WEDNESDAY);
        $rescheduled = $this->expiryService->rescheduleDelivery($invAfterFirstMiss, $nextWed, $supplier);
        $this->assertSame($nextWed->toDateString(), $rescheduled->scheduled_physical_delivery_date->toDateString());
        $rescheduleHistory = $rescheduled->statusHistories()->where('event', 'rescheduled')->latest('id')->firstOrFail();
        $this->assertSame(__('local_invoice.history.rescheduled', ['date' => $nextWed->toDateString()], 'id'), $rescheduleHistory->notes);
        app()->setLocale('en');

        // 2nd missed delivery -> becomes EXPIRED
        $invAfterSecondMiss = $this->expiryService->recordMissedDelivery($rescheduled, $finance);
        $this->assertSame(LocalInvoice::STATUS_EXPIRED, $invAfterSecondMiss->status);
        $this->assertNotNull($invAfterSecondMiss->expired_at);
        $this->assertTrue($invAfterSecondMiss->isExpired());
        $expiredHistory = $invAfterSecondMiss->statusHistories()->where('event', 'expired')->latest('id')->firstOrFail();
        $this->assertSame(__('local_invoice.history.expired', [], 'en'), $expiredHistory->notes);
        $this->assertSame(__('local_invoice.history.delivery_missed', [], 'id'), $firstMissHistory->fresh()->notes);
    }

    public function test_expiry_validation_uses_localized_invoice_status_labels(): void
    {
        $supplier = $this->createSupplier();
        $finance = User::factory()->create(['role' => 'finance']);
        $invoice = $this->submitInvoiceForReceipt($supplier, 'INV-EXP-STATUS', 'PO-EXP-STATUS');
        $invoice->forceFill(['status' => LocalInvoice::STATUS_READY_TO_PAY])->save();

        foreach (['en', 'id'] as $locale) {
            app()->setLocale($locale);

            try {
                $this->expiryService->recordMissedDelivery($invoice, $finance);
                $this->fail('Expected invalid invoice status to reject the missed-delivery transition.');
            } catch (RuntimeException $exception) {
                $this->assertSame(__('local_invoice.validation.missed_status', [
                    'status' => StatusHelper::localInvoiceLabel(LocalInvoice::STATUS_READY_TO_PAY),
                ], $locale), $exception->getMessage());
                $this->assertStringNotContainsString(LocalInvoice::STATUS_READY_TO_PAY, $exception->getMessage());
            }

            try {
                $this->expiryService->rescheduleDelivery($invoice, Carbon::now()->next(Carbon::WEDNESDAY), $supplier);
                $this->fail('Expected invalid invoice status to reject the reschedule transition.');
            } catch (RuntimeException $exception) {
                $this->assertSame(__('local_invoice.validation.reschedule_status', [
                    'status' => StatusHelper::localInvoiceLabel(LocalInvoice::STATUS_READY_TO_PAY),
                ], $locale), $exception->getMessage());
                $this->assertStringNotContainsString(LocalInvoice::STATUS_READY_TO_PAY, $exception->getMessage());
            }
        }

        app()->setLocale('en');
        $this->assertSame(LocalInvoice::STATUS_READY_TO_PAY, $invoice->fresh()->status);
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
