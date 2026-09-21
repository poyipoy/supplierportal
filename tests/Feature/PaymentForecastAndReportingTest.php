<?php

namespace Tests\Feature;

use App\Exports\LocalInvoicesExport;
use App\Models\Employee;
use App\Models\GaClaim;
use App\Models\LocalInvoice;
use App\Models\PaymentBatch;
use App\Models\PaymentGroup;
use App\Models\PaymentItem;
use App\Models\Supplier;
use App\Models\SupplierBankAccount;
use App\Models\User;
use App\Notifications\LocalInvoice\InvoicePaidNotification;
use App\Notifications\LocalInvoice\InvoiceSubmissionReceivedNotification;
use App\Notifications\LocalInvoice\PhysicalDeliveryReminderNotification;
use App\Notifications\LocalInvoice\RevisionRequiredNotification;
use App\Services\Payment\PaymentBatchService;
use App\Services\Payment\PaymentExecutionService;
use App\Services\Payment\PaymentForecastService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PaymentForecastAndReportingTest extends TestCase
{
    use RefreshDatabase;

    protected function createSupplierWithBank(): array
    {
        $user = User::factory()->create([
            'role' => 'supplier',
            'is_active' => true,
        ]);

        $user->supplierScopes()->create(['scope' => 'local']);

        $supplier = Supplier::create([
            'user_id' => $user->id,
            'company_name' => 'PT Vendor Jaya Sentosa',
            'business_type' => 'PT',
            'vendor_category' => 'Barang',
            'is_pkp' => true,
            'payment_term' => 30,
        ]);

        $bank = SupplierBankAccount::create([
            'supplier_id' => $user->id,
            'bank_name' => 'BCA',
            'account_number' => '1234567890',
            'account_holder_name' => 'PT Vendor Jaya Sentosa',
            'is_primary' => true,
            'is_active' => true,
            'status' => SupplierBankAccount::STATUS_VERIFIED,
            'verified_at' => now(),
        ]);

        return [$user, $supplier, $bank];
    }

    protected function createEmployee(): Employee
    {
        return Employee::create([
            'nik' => 'EMP-'.uniqid(),
            'name' => 'Budi Santoso',
            'department' => 'GA Operations',
            'bank_name' => 'BCA',
            'account_number' => '9876543210',
            'account_holder_name' => 'Budi Santoso',
            'is_active' => true,
        ]);
    }

    public function test_forecast_precedence_and_double_counting_prevention(): void
    {
        [$supplier, $supplierProfile, $bank] = $this->createSupplierWithBank();
        $finance = User::factory()->create(['role' => 'finance', 'is_active' => true]);

        $currentWeekMonday = Carbon::now()->startOfWeek();
        $nextWeekMonday = (clone $currentWeekMonday)->addWeeks(1);

        // Invoice 1: READY_TO_PAY, due this week, NOT in DRP
        $inv1 = LocalInvoice::create([
            'supplier_id' => $supplier->id,
            'submission_number' => 'SUB-001',
            'invoice_number' => 'INV-001',
            'po_number' => 'PO-001',
            'currency' => 'IDR',
            'invoice_amount' => 10000000,
            'tax_amount' => 1100000,
            'status' => LocalInvoice::STATUS_READY_TO_PAY,
            'invoice_date' => $currentWeekMonday->toDateString(),
            'due_date' => $currentWeekMonday->toDateString(),
            'payment_term_days_snapshot' => 30,
            'cashier_received_at' => now(),
            'submitted_at' => now(),
        ]);

        // Invoice 2: READY_TO_PAY, due this week, BUT placed into active DRP planned for next week
        $inv2 = LocalInvoice::create([
            'supplier_id' => $supplier->id,
            'submission_number' => 'SUB-002',
            'invoice_number' => 'INV-002',
            'po_number' => 'PO-002',
            'currency' => 'IDR',
            'invoice_amount' => 20000000,
            'tax_amount' => 2200000,
            'status' => LocalInvoice::STATUS_READY_TO_PAY,
            'invoice_date' => $currentWeekMonday->toDateString(),
            'due_date' => $currentWeekMonday->toDateString(),
            'payment_term_days_snapshot' => 30,
            'cashier_received_at' => now(),
            'submitted_at' => now(),
        ]);

        // Put Invoice 2 into a DRP batch
        $batchService = app(PaymentBatchService::class);
        $batch = $batchService->createSupplierBatch($finance, [$inv2->id], 'DRP Week 2 Batch');

        // Set DRP group transfer/planned date to next week
        $group = $batch->groups()->first();
        $group->update(['transfer_date' => $nextWeekMonday->toDateString()]);

        // Invoice 3: UNDER_VERIFICATION (should be excluded completely)
        $inv3 = LocalInvoice::create([
            'supplier_id' => $supplier->id,
            'submission_number' => 'SUB-003',
            'invoice_number' => 'INV-003',
            'po_number' => 'PO-003',
            'currency' => 'IDR',
            'invoice_amount' => 50000000,
            'tax_amount' => 5500000,
            'status' => LocalInvoice::STATUS_UNDER_VERIFICATION,
            'invoice_date' => $currentWeekMonday->toDateString(),
            'due_date' => $currentWeekMonday->toDateString(),
            'payment_term_days_snapshot' => 30,
            'cashier_received_at' => now(),
            'submitted_at' => now(),
        ]);

        // GA Claim: READY_TO_PAY in current week
        $emp = $this->createEmployee();
        $claim = GaClaim::create([
            'claim_number' => 'GA-CLM-001',
            'employee_id' => $emp->id,
            'submitted_by' => $finance->id,
            'claim_type' => GaClaim::TYPE_REIMBURSE_CLAIM,
            'amount' => 1500000,
            'status' => GaClaim::STATUS_READY_TO_PAY,
            'claim_date' => $currentWeekMonday->toDateString(),
            'ready_to_pay_at' => $currentWeekMonday->toDateTimeString(),
            'submitted_at' => now(),
        ]);

        $forecastService = app(PaymentForecastService::class);
        $weekly = $forecastService->getWeeklyForecast();

        // Week 1 should have:
        // - inv1 (10,000,000 + 1,100,000 = 11,100,000)
        // - claim (1,500,000)
        // - inv2 must NOT be in Week 1 even though its due_date was in Week 1!
        // - inv3 must NOT be anywhere because it is UNDER_VERIFICATION!
        $this->assertEquals(2, $weekly[0]['count']);
        $this->assertEquals(11100000.0, $weekly[0]['supplier_amount']);
        $this->assertEquals(1500000.0, $weekly[0]['ga_amount']);
        $this->assertEquals(12600000.0, $weekly[0]['total']);

        // Week 2 should have:
        // - inv2 via DRP (20,000,000 + 2,200,000 = 22,200,000)
        $this->assertEquals(1, $weekly[1]['count']);
        $this->assertEquals(22200000.0, $weekly[1]['supplier_amount']);
        $this->assertEquals(22200000.0, $weekly[1]['drp_amount']);
        $this->assertEquals(22200000.0, $weekly[1]['total']);

        // Test monthly forecast uses identical logic
        $monthly = $forecastService->getMonthlyForecast();
        $this->assertNotEmpty($monthly);
        $firstMonth = $monthly[0];
        // All active items in the month should be sum of week 1 + week 2 (if in same month)
        $this->assertGreaterThanOrEqual(33300000.0, $firstMonth['total']);
    }

    public function test_master_invoice_query_and_filtering(): void
    {
        [$supplier, $supplierProfile, $bank] = $this->createSupplierWithBank();
        $finance = User::factory()->create(['role' => 'finance', 'is_active' => true]);

        // Unpaid invoice
        LocalInvoice::create([
            'supplier_id' => $supplier->id,
            'submission_number' => 'SUB-UNPAID-01',
            'invoice_number' => 'INV-UNPAID-01',
            'po_number' => 'PO-UNPAID-01',
            'currency' => 'IDR',
            'invoice_amount' => 5000000,
            'tax_amount' => 550000,
            'status' => LocalInvoice::STATUS_READY_TO_PAY,
            'invoice_date' => '2026-05-10',
            'submitted_at' => now(),
        ]);

        // Paid invoice
        LocalInvoice::create([
            'supplier_id' => $supplier->id,
            'submission_number' => 'SUB-PAID-01',
            'invoice_number' => 'INV-PAID-01',
            'po_number' => 'PO-PAID-01',
            'currency' => 'IDR',
            'invoice_amount' => 8000000,
            'tax_amount' => 880000,
            'status' => LocalInvoice::STATUS_PAID,
            'invoice_date' => '2026-05-12',
            'paid_at' => now(),
            'submitted_at' => now(),
        ]);

        // 1. Filter ALL
        $resAll = $this->actingAs($finance)->get(route('finance.master-invoices'));
        $resAll->assertOk();
        $resAll->assertSee('SUB-UNPAID-01');
        $resAll->assertSee('SUB-PAID-01');

        // 2. Filter UNPAID
        $resUnpaid = $this->actingAs($finance)->get(route('finance.master-invoices', ['payment_status' => 'UNPAID']));
        $resUnpaid->assertOk();
        $resUnpaid->assertSee('SUB-UNPAID-01');
        $resUnpaid->assertDontSee('SUB-PAID-01');

        // 3. Filter PAID
        $resPaid = $this->actingAs($finance)->get(route('finance.master-invoices', ['payment_status' => 'PAID']));
        $resPaid->assertOk();
        $resPaid->assertDontSee('SUB-UNPAID-01');
        $resPaid->assertSee('SUB-PAID-01');

        // 4. Filter by month and year
        $resMonth = $this->actingAs($finance)->get(route('finance.master-invoices', ['month' => 5, 'year' => 2026]));
        $resMonth->assertOk();
        $resMonth->assertSee('SUB-UNPAID-01');

        // 5. Export Excel route dispatches export job
        $resExport = $this->actingAs($finance)->get(route('finance.master-invoices.export'));
        $resExport->assertRedirect(route('exports.index'));
        $resExport->assertSessionHas('success', 'Master invoices export queued.');
    }

    public function test_supplier_email_events_and_no_internal_dates_exposed(): void
    {
        Notification::fake();

        [$supplier, $supplierProfile, $bank] = $this->createSupplierWithBank();
        $finance = User::factory()->create(['role' => 'finance', 'is_active' => true]);

        $invoice = LocalInvoice::create([
            'supplier_id' => $supplier->id,
            'submission_number' => 'SUB-MAIL-001',
            'invoice_number' => 'INV-MAIL-001',
            'po_number' => 'PO-MAIL-001',
            'currency' => 'IDR',
            'invoice_amount' => 15000000,
            'tax_amount' => 1650000,
            'status' => LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT,
            'invoice_date' => now()->toDateString(),
            'scheduled_physical_delivery_date' => Carbon::now()->next(Carbon::WEDNESDAY),
            'submitted_at' => now(),
        ]);

        $notificationService = app(\App\Services\LocalInvoice\InvoiceNotificationService::class);

        // 1. Submission received event
        $historySub = $invoice->statusHistories()->create([
            'from_status' => null,
            'to_status' => LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT,
            'actor_id' => $supplier->id,
            'event' => 'submitted',
            'notes' => 'Invoice submitted by supplier',
        ]);
        $notificationService->send($invoice, $historySub);

        Notification::assertSentTo($supplier, InvoiceSubmissionReceivedNotification::class, function ($n) use ($invoice) {
            $mail = $n->toMail($invoice->supplier);
            $this->assertStringContainsString($invoice->submissionNumber ?? $invoice->submission_number, $mail->subject);
            return true;
        });

        // 2. Physical delivery reminder
        $notificationService->sendPhysicalDeliveryReminder($invoice);

        Notification::assertSentTo($supplier, PhysicalDeliveryReminderNotification::class, function ($n) use ($invoice) {
            $mail = $n->toMail($invoice->supplier);
            $this->assertStringContainsString('Pengingat Pengiriman Berkas Fisik', $mail->subject);
            return true;
        });

        // 3. Need Revision event
        $historyRev = $invoice->statusHistories()->create([
            'from_status' => LocalInvoice::STATUS_UNDER_VERIFICATION,
            'to_status' => LocalInvoice::STATUS_NEED_REVISION,
            'actor_id' => $finance->id,
            'event' => 'revision_requested',
            'notes' => 'Faktur pajak tidak valid',
        ]);
        $notificationService->send($invoice, $historyRev);

        Notification::assertSentTo($supplier, RevisionRequiredNotification::class, function ($n) {
            return $n->reason === 'Faktur pajak tidak valid';
        });

        // 4. Paid event (Execution via PaymentExecutionService)
        $invoice->update(['status' => LocalInvoice::STATUS_READY_TO_PAY]);
        $batchService = app(PaymentBatchService::class);
        $batch = $batchService->createSupplierBatch($finance, [$invoice->id], 'DRP Final Batch');
        $batchService->finalizeBatch($batch, $finance);

        $executionService = app(PaymentExecutionService::class);
        $group = $batch->groups()->first();
        $executionService->markGroupPaid($group, [
            'transfer_reference' => 'TRF-BCA-TEST-8899',
            'transfer_date' => now()->toDateString(),
            'payment_notes' => 'Lunas via batch',
        ], $finance);

        Notification::assertSentTo($supplier, InvoicePaidNotification::class, function ($n) use ($invoice) {
            $mail = $n->toMail($invoice->supplier);
            $this->assertStringContainsString('Konfirmasi Pembayaran Invoice', $mail->subject);
            $this->assertStringContainsString('TRF-BCA-TEST-8899', implode(' ', $mail->introLines));

            // CRITICAL INVARIANT: Never expose internal planned payment date to Supplier
            foreach ($mail->introLines as $line) {
                $this->assertStringNotContainsString('scheduled_payment_date', $line);
                $this->assertStringNotContainsString('planned_date', $line);
                $this->assertStringNotContainsString('internal_date', $line);
            }
            return true;
        });
    }
}
