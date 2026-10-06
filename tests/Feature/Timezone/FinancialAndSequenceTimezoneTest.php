<?php

namespace Tests\Feature\Timezone;

use App\Models\LocalInvoice;
use App\Models\Supplier;
use App\Models\SupplierScope;
use App\Models\User;
use App\Services\LocalInvoice\InvoiceExpiryService;
use App\Services\LocalInvoice\InvoicePhysicalReceiptService;
use App\Services\LocalInvoice\InvoiceSubmissionService;
use App\Services\Payment\PaymentForecastService;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\TestCase;

class FinancialAndSequenceTimezoneTest extends TestCase
{
    use RefreshDatabase;

    /**
     * T1: 2026-10-13 23:30 UTC = Rabu 14 Okt 2026 06:30 WIB.
     * Cashier receives physical document, payment term 30 days.
     * Expected: due_date = 2026-11-13.
     * Old buggy behavior: 2026-11-12.
     */
    public function test_t1_cashier_physical_receipt_calculates_due_date_based_on_business_calendar(): void
    {
        $this->travelTo(Carbon::parse('2026-10-13 23:30:00', 'UTC'));

        $supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        SupplierScope::create(['supplier_id' => $supplier->id, 'scope' => 'local']);
        Supplier::create([
            'user_id' => $supplier->id,
            'company_name' => 'PT Test Supplier T1',
            'category' => 'Barang',
            'vendor_category' => 'Barang',
            'is_pkp' => true,
            'payment_term_days' => 30,
        ]);

        $cashier = User::factory()->create(['role' => 'finance', 'is_active' => true]);

        $invoice = LocalInvoice::create([
            'supplier_id' => $supplier->id,
            'invoice_number' => 'INV-T1-001',
            'submission_number' => 'SUB-2026-00001',
            'invoice_date' => '2026-10-14',
            'po_source' => 'MANUAL',
            'po_number' => 'PO-MANUAL-T1',
            'status' => LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT,
            'currency' => 'IDR',
            'invoice_amount' => 10000000,
            'tax_amount' => 1100000,
            'submitted_at' => now(),
            'scheduled_physical_delivery_date' => '2026-10-14',
        ]);

        $receipt = $invoice->receipt()->create([
            'receipt_number' => 'TT-T1-00001',
            'issued_at' => now(),
        ]);
        $service = app(InvoicePhysicalReceiptService::class);
        $updated = $service->recordReceipt(
            $cashier,
            $invoice,
            scannedReceiptQr: URL::signedRoute('receipts.verify-supplier', [
                'receipt' => $receipt->receipt_number,
                'revision' => $invoice->revision_number,
            ])
        );

        $this->assertSame('2026-11-13', $updated->due_date->toDateString());
    }

    /**
     * T4: 2026-12-31 18:00 UTC = Jumat 1 Jan 2027 01:00 WIB.
     * Submit invoice.
     * Expected: SUB-2027-00001 and TT-2027-00001.
     * Old buggy behavior: SUB-2026-00001 and TT-2026-00001.
     */
    public function test_t4_invoice_submission_number_and_receipt_use_business_calendar_year(): void
    {
        $this->travelTo(Carbon::parse('2026-12-31 18:00:00', 'UTC'));

        $supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        SupplierScope::create(['supplier_id' => $supplier->id, 'scope' => 'local']);
        Supplier::create([
            'user_id' => $supplier->id,
            'company_name' => 'PT Test Supplier T4',
            'category' => 'Barang',
            'vendor_category' => 'Barang',
            'is_pkp' => true,
            'payment_term_days' => 30,
        ]);

        $service = app(InvoiceSubmissionService::class);
        $invoice = $service->submit($supplier, [
            'invoice_number' => 'INV-T4-NEWYEAR',
            'invoice_date' => '2027-01-01',
            'invoice_amount' => 5000000,
            'tax_amount' => 550000,
            'ppn_type' => '11%',
            'po_source' => 'MANUAL',
            'manual_po_number' => 'PO-MANUAL-T4',
            'manual_gr_reference' => 'GR-MANUAL-T4',
            'scheduled_physical_delivery_date' => '2027-01-06',
        ], [
            'invoice' => UploadedFile::fake()->createWithContent('invoice.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF"),
            'tax_invoice' => UploadedFile::fake()->image('tax.png'),
            'delivery_note' => UploadedFile::fake()->createWithContent('surat_jalan.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF"),
        ]);

        $this->assertStringStartsWith('SUB-2027-', $invoice->submission_number);
        $this->assertStringStartsWith('TT-2027-', $invoice->receipt->receipt_number);
    }

    /**
     * T5: Instant 2026-09-30 20:00 UTC = Kamis 1 Okt 2026 03:00 WIB.
     * Invoice ready_to_pay_at at this instant.
     * Expected: included in October Week 1, NOT in September Week 5.
     * Old buggy behavior: included in September Week 5.
     */
    public function test_t5_payment_forecast_aggregates_into_business_calendar_month_and_week(): void
    {
        $eventAt = Carbon::parse('2026-09-30 20:00:00', 'UTC');

        $supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        SupplierScope::create(['supplier_id' => $supplier->id, 'scope' => 'local']);
        Supplier::create([
            'user_id' => $supplier->id,
            'company_name' => 'PT Test Supplier T5',
            'category' => 'Barang',
            'vendor_category' => 'Barang',
            'is_pkp' => true,
            'payment_term_days' => 30,
        ]);

        $invoice = LocalInvoice::create([
            'supplier_id' => $supplier->id,
            'invoice_number' => 'INV-T5-001',
            'submission_number' => 'SUB-2026-00005',
            'invoice_date' => '2026-09-30',
            'po_source' => 'MANUAL',
            'po_number' => 'PO-MANUAL-T5',
            'status' => LocalInvoice::STATUS_READY_TO_PAY,
            'currency' => 'IDR',
            'invoice_amount' => 10000000,
            'tax_amount' => 1100000,
            'submitted_at' => now(),
            'scheduled_physical_delivery_date' => '2026-09-30',
        ]);
        $invoice->forceFill(['ready_to_pay_at' => $eventAt])->saveQuietly();

        $forecastService = app(PaymentForecastService::class);

        $octoberWeekly = $forecastService->getWeeklyForecast('2026-10');
        $this->assertSame(1, $octoberWeekly[0]['invoice_count'], 'Invoice must be included in October Week 1');
        $this->assertEquals(11100000, $octoberWeekly[0]['period_amount']);

        $septemberWeekly = $forecastService->getWeeklyForecast('2026-09');
        $week5Sep = collect($septemberWeekly)->firstWhere('week_number', 5);
        $this->assertSame(0, $week5Sep['invoice_count'] ?? 0, 'Invoice must not be included in September Week 5');
    }

    /**
     * T2: 2026-10-14 20:00 UTC = Kamis 15 Okt 2026 03:00 WIB.
     * Submit invoice scheduled for Wednesday 14 Okt 2026.
     * Expected: Rejected because Wednesday 14 Okt is already in the past in WIB.
     */
    public function test_t2_delivery_schedule_rejects_past_date_according_to_business_calendar(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 20:00:00', 'UTC'));

        $supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        SupplierScope::create(['supplier_id' => $supplier->id, 'scope' => 'local']);
        Supplier::create([
            'user_id' => $supplier->id,
            'company_name' => 'PT Test Supplier T2',
            'category' => 'Barang',
            'vendor_category' => 'Barang',
            'is_pkp' => true,
            'payment_term_days' => 30,
        ]);

        $service = app(InvoiceSubmissionService::class);

        $this->expectException(ValidationException::class);

        $service->submit($supplier, [
            'invoice_number' => 'INV-T2-PAST',
            'invoice_date' => '2026-10-14',
            'invoice_amount' => 5000000,
            'tax_amount' => 550000,
            'ppn_type' => '11%',
            'po_source' => 'MANUAL',
            'manual_po_number' => 'PO-MANUAL-T2',
            'manual_gr_reference' => 'GR-MANUAL-T2',
            'scheduled_physical_delivery_date' => '2026-10-14', // Past date in WIB!
        ], [
            'invoice' => UploadedFile::fake()->createWithContent('invoice.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF"),
            'tax_invoice' => UploadedFile::fake()->image('tax.png'),
            'delivery_note' => UploadedFile::fake()->createWithContent('surat_jalan.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF"),
        ]);
    }

    /**
     * T3: 2026-10-13 18:00 UTC = Rabu 14 Okt 2026 01:00 WIB.
     * Submit invoice scheduled for Wednesday 14 Okt 2026.
     * Expected: Accepted because Wednesday 14 Okt is today in WIB.
     */
    public function test_t3_delivery_schedule_accepts_same_day_wednesday_in_business_calendar(): void
    {
        $this->travelTo(Carbon::parse('2026-10-13 18:00:00', 'UTC'));

        $supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        SupplierScope::create(['supplier_id' => $supplier->id, 'scope' => 'local']);
        Supplier::create([
            'user_id' => $supplier->id,
            'company_name' => 'PT Test Supplier T3',
            'category' => 'Barang',
            'vendor_category' => 'Barang',
            'is_pkp' => true,
            'payment_term_days' => 30,
        ]);

        $service = app(InvoiceSubmissionService::class);

        $invoice = $service->submit($supplier, [
            'invoice_number' => 'INV-T3-TODAY',
            'invoice_date' => '2026-10-14',
            'invoice_amount' => 5000000,
            'tax_amount' => 550000,
            'ppn_type' => '11%',
            'po_source' => 'MANUAL',
            'manual_po_number' => 'PO-MANUAL-T3',
            'manual_gr_reference' => 'GR-MANUAL-T3',
            'scheduled_physical_delivery_date' => '2026-10-14', // Same-day Wednesday in WIB
        ], [
            'invoice' => UploadedFile::fake()->createWithContent('invoice.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF"),
            'tax_invoice' => UploadedFile::fake()->image('tax.png'),
            'delivery_note' => UploadedFile::fake()->createWithContent('surat_jalan.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF"),
        ]);

        $this->assertNotNull($invoice);
        $this->assertSame('2026-10-14', $invoice->scheduled_physical_delivery_date?->toDateString());
    }

    /**
     * T7: Scheduler send-delivery-reminders uses business timezone.
     * Expected: timezone === 'Asia/Jakarta', expression === '0 8 * * *'.
     */
    public function test_t7_scheduler_send_delivery_reminders_uses_business_timezone(): void
    {
        $events = app(Schedule::class)->events();
        $reminderEvent = collect($events)->first(fn ($e) => str_contains($e->command, 'local-invoices:send-delivery-reminders'));

        $this->assertNotNull($reminderEvent, 'Scheduled command local-invoices:send-delivery-reminders must exist');
        $this->assertSame('Asia/Jakarta', $reminderEvent->timezone);
        $this->assertSame('0 8 * * *', $reminderEvent->expression);
    }

    /**
     * T8: 2026-10-14 20:00 UTC = Kamis 15 Okt 2026 03:00 WIB.
     * Invoice scheduled for Wednesday 14 Okt 2026.
     * Command send-delivery-reminders should NOT fire because the schedule is in the past in WIB.
     */
    public function test_t8_delivery_reminder_does_not_fire_for_date_already_past_in_business_calendar(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 20:00:00', 'UTC'));

        $supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        SupplierScope::create(['supplier_id' => $supplier->id, 'scope' => 'local']);
        Supplier::create([
            'user_id' => $supplier->id,
            'company_name' => 'PT Test Supplier T8',
            'category' => 'Barang',
            'vendor_category' => 'Barang',
            'is_pkp' => true,
            'payment_term_days' => 30,
        ]);

        $invoice = LocalInvoice::create([
            'supplier_id' => $supplier->id,
            'invoice_number' => 'INV-T8-001',
            'submission_number' => 'SUB-2026-00008',
            'invoice_date' => '2026-10-14',
            'po_source' => 'MANUAL',
            'po_number' => 'PO-MANUAL-T8',
            'status' => LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT,
            'currency' => 'IDR',
            'invoice_amount' => 10000000,
            'tax_amount' => 1100000,
            'submitted_at' => now(),
            'scheduled_physical_delivery_date' => '2026-10-14',
            'delivery_reminder_sent_at' => null,
        ]);

        Artisan::call('local-invoices:send-delivery-reminders');

        $this->assertNull($invoice->fresh()->delivery_reminder_sent_at, 'Reminder must NOT be sent for a past delivery date');
    }

    /**
     * T9: 2026-10-13 23:30 UTC = Rabu 14 Okt 2026 06:30 WIB.
     * Reschedule to a date in the past (e.g. 2026-10-07) must be rejected.
     */
    public function test_t9_reschedule_delivery_rejects_past_date_in_business_calendar(): void
    {
        $this->travelTo(Carbon::parse('2026-10-13 23:30:00', 'UTC'));

        $supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        SupplierScope::create(['supplier_id' => $supplier->id, 'scope' => 'local']);
        Supplier::create([
            'user_id' => $supplier->id,
            'company_name' => 'PT Test Supplier T9',
            'category' => 'Barang',
            'vendor_category' => 'Barang',
            'is_pkp' => true,
            'payment_term_days' => 30,
        ]);

        $invoice = LocalInvoice::create([
            'supplier_id' => $supplier->id,
            'invoice_number' => 'INV-T9-001',
            'submission_number' => 'SUB-2026-00009',
            'invoice_date' => '2026-10-14',
            'po_source' => 'MANUAL',
            'po_number' => 'PO-MANUAL-T9',
            'status' => LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT,
            'currency' => 'IDR',
            'invoice_amount' => 10000000,
            'tax_amount' => 1100000,
            'submitted_at' => now(),
            'scheduled_physical_delivery_date' => '2026-10-14',
            'missed_delivery_count' => 1,
        ]);

        $expiryService = app(InvoiceExpiryService::class);

        $this->expectException(InvalidArgumentException::class);

        // Reschedule to previous Wednesday (7 Okt 2026) -> in the past
        $pastWednesday = Carbon::parse('2026-10-07');
        $expiryService->rescheduleDelivery($invoice, $pastWednesday, $supplier);
    }
}
