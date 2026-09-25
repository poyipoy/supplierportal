<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LocalInvoice;
use App\Models\Supplier;
use App\Models\SupplierBankAccount;
use App\Models\User;
use App\Notifications\LocalInvoice\InvoicePaidNotification;
use App\Notifications\LocalInvoice\InvoiceSubmissionReceivedNotification;
use App\Notifications\LocalInvoice\PhysicalDeliveryReminderNotification;
use App\Notifications\LocalInvoice\RevisionRequiredNotification;
use App\Services\LocalInvoice\InvoiceNotificationService;
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

    protected function createTestInvoice(array $attributes = []): LocalInvoice
    {
        return LocalInvoice::create(array_merge([
            'submission_number' => 'SUB-'.uniqid(),
            'invoice_number' => 'INV-'.uniqid(),
            'po_number' => 'PO-'.uniqid(),
            'currency' => 'IDR',
            'invoice_amount' => 10000000,
            'tax_amount' => 1100000,
            'status' => LocalInvoice::STATUS_READY_TO_PAY,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'payment_term_days_snapshot' => 30,
            'cashier_received_at' => now(),
            'submitted_at' => now(),
        ], $attributes));
    }

    public function test_ready_to_pay_event_and_weekly_monthly_bucketing(): void
    {
        [$supplier, $supplierProfile, $bank] = $this->createSupplierWithBank();

        // Target Month: September 2026
        $refMonth = '2026-09';

        // Invoice 1: Ready to pay in Week 1 (3 September 2026)
        $this->createTestInvoice([
            'supplier_id' => $supplier->id,
            'submission_number' => 'SUB-001',
            'invoice_number' => 'INV-001',
            'invoice_amount' => 10000000,
            'tax_amount' => 1100000,
            'invoice_date' => '2026-09-01',
            'due_date' => '2026-10-01',
            'ready_to_pay_at' => '2026-09-03 10:00:00',
            'approved_at' => '2026-09-03 10:00:00',
        ]);

        // Invoice 2: Ready to pay in Week 3 (18 September 2026)
        $this->createTestInvoice([
            'supplier_id' => $supplier->id,
            'submission_number' => 'SUB-002',
            'invoice_number' => 'INV-002',
            'invoice_amount' => 20000000,
            'tax_amount' => 2200000,
            'invoice_date' => '2026-09-10',
            'due_date' => '2026-10-10',
            'ready_to_pay_at' => '2026-09-18 14:00:00',
            'approved_at' => '2026-09-18 14:00:00',
        ]);

        $forecastService = app(PaymentForecastService::class);
        $weekly = $forecastService->getWeeklyForecast($refMonth);

        // September has 30 days => 5 weeks: 1-7, 8-14, 15-21, 22-28, 29-30
        $this->assertCount(5, $weekly);

        // Week 1 (Day 1-7)
        $w1 = $weekly[0];
        $this->assertEquals(1, $w1['week_number']);
        $this->assertEquals(1, $w1['count']);
        $this->assertEquals(11100000.0, $w1['period_amount']);
        $this->assertEquals(11100000.0, $w1['cumulative_amount']);

        // Week 2 (Day 8-14) - Empty
        $w2 = $weekly[1];
        $this->assertEquals(2, $w2['week_number']);
        $this->assertEquals(0, $w2['count']);
        $this->assertEquals(0.0, $w2['period_amount']);
        $this->assertEquals(11100000.0, $w2['cumulative_amount']);

        // Week 3 (Day 15-21)
        $w3 = $weekly[2];
        $this->assertEquals(3, $w3['week_number']);
        $this->assertEquals(1, $w3['count']);
        $this->assertEquals(22200000.0, $w3['period_amount']);
        $this->assertEquals(33300000.0, $w3['cumulative_amount']);

        // Monthly forecast should also capture both invoices
        $monthly = $forecastService->getMonthlyForecast(6, Carbon::parse('2026-09-24'));
        $this->assertCount(6, $monthly);
        $septemberMonth = collect($monthly)->firstWhere('start', '2026-09-01');
        $this->assertNotNull($septemberMonth);
        $this->assertEquals(2, $septemberMonth['count']);
        $this->assertEquals(33300000.0, $septemberMonth['period_amount']);
    }

    public function test_cumulative_progression_across_periods(): void
    {
        [$supplier, $supplierProfile, $bank] = $this->createSupplierWithBank();

        // 3 invoices in September 2026: Week 1, Week 2, Week 3
        $this->createTestInvoice([
            'supplier_id' => $supplier->id,
            'submission_number' => 'SUB-W1',
            'invoice_number' => 'INV-W1',
            'invoice_amount' => 10000000,
            'tax_amount' => 0,
            'ready_to_pay_at' => '2026-09-05 09:00:00',
        ]);

        $this->createTestInvoice([
            'supplier_id' => $supplier->id,
            'submission_number' => 'SUB-W2',
            'invoice_number' => 'INV-W2',
            'invoice_amount' => 7500000,
            'tax_amount' => 0,
            'ready_to_pay_at' => '2026-09-12 11:00:00',
        ]);

        $this->createTestInvoice([
            'supplier_id' => $supplier->id,
            'submission_number' => 'SUB-W3',
            'invoice_number' => 'INV-W3',
            'invoice_amount' => 12000000,
            'tax_amount' => 0,
            'ready_to_pay_at' => '2026-09-19 15:00:00',
        ]);

        $forecastService = app(PaymentForecastService::class);
        $weekly = $forecastService->getWeeklyForecast('2026-09');

        $w1 = $weekly[0];
        $w2 = $weekly[1];
        $w3 = $weekly[2];

        $this->assertEquals(10000000.0, $w1['period_amount']);
        $this->assertEquals(10000000.0, $w1['cumulative_amount']);

        $this->assertEquals(7500000.0, $w2['period_amount']);
        $this->assertEquals(17500000.0, $w2['cumulative_amount']);

        $this->assertEquals(12000000.0, $w3['period_amount']);
        $this->assertEquals(29500000.0, $w3['cumulative_amount']);

        // Cumulative must be non-decreasing within the month
        for ($i = 1; $i < count($weekly); $i++) {
            $this->assertGreaterThanOrEqual($weekly[$i - 1]['cumulative_amount'], $weekly[$i]['cumulative_amount']);
        }
    }

    public function test_weekly_forecast_resets_cumulative_each_month(): void
    {
        [$supplier, $supplierProfile, $bank] = $this->createSupplierWithBank();

        // Invoice in August Week 1 (5 August 2026) = 10M
        $this->createTestInvoice([
            'supplier_id' => $supplier->id,
            'submission_number' => 'SUB-AUG-W1',
            'invoice_number' => 'INV-AUG-W1',
            'invoice_amount' => 10000000,
            'tax_amount' => 0,
            'ready_to_pay_at' => '2026-08-05 10:00:00',
        ]);

        // Invoice in September Week 1 (4 September 2026) = 15M
        $this->createTestInvoice([
            'supplier_id' => $supplier->id,
            'submission_number' => 'SUB-SEP-W1',
            'invoice_number' => 'INV-SEP-W1',
            'invoice_amount' => 15000000,
            'tax_amount' => 0,
            'ready_to_pay_at' => '2026-09-04 10:00:00',
        ]);

        $forecastService = app(PaymentForecastService::class);

        // August Forecast
        $augustWeekly = $forecastService->getWeeklyForecast('2026-08');
        $this->assertEquals(10000000.0, $augustWeekly[0]['period_amount']);
        $this->assertEquals(10000000.0, $augustWeekly[0]['cumulative_amount']);

        // September Forecast: cumulative MUST reset and start fresh from September Week 1 (15M, NOT 25M)
        $septemberWeekly = $forecastService->getWeeklyForecast('2026-09');
        $this->assertEquals(15000000.0, $septemberWeekly[0]['period_amount']);
        $this->assertEquals(15000000.0, $septemberWeekly[0]['cumulative_amount']);
    }

    public function test_historical_paid_invoice_remains_in_its_ready_to_pay_period(): void
    {
        [$supplier, $supplierProfile, $bank] = $this->createSupplierWithBank();

        // Invoice approved on 5 September 2026 (Week 1), and subsequently marked PAID on 25 September 2026
        $this->createTestInvoice([
            'supplier_id' => $supplier->id,
            'submission_number' => 'SUB-PAID-HIST',
            'invoice_number' => 'INV-PAID-HIST',
            'invoice_amount' => 50000000,
            'tax_amount' => 5500000,
            'status' => LocalInvoice::STATUS_PAID,
            'ready_to_pay_at' => '2026-09-05 10:00:00',
            'approved_at' => '2026-09-05 10:00:00',
            'paid_at' => '2026-09-25 15:00:00',
        ]);

        $forecastService = app(PaymentForecastService::class);
        $weekly = $forecastService->getWeeklyForecast('2026-09');

        // Week 1 (Day 1-7) has the invoice
        $w1 = $weekly[0];
        $this->assertEquals(1, $w1['count']);
        $this->assertEquals(55500000.0, $w1['period_amount']);

        // Week 4 (Day 22-28, when it was paid) has 0 period amount
        $w4 = $weekly[3];
        $this->assertEquals(0, $w4['count']);
        $this->assertEquals(0.0, $w4['period_amount']);
        // Cumulative amount carries forward
        $this->assertEquals(55500000.0, $w4['cumulative_amount']);
    }

    public function test_due_date_independence(): void
    {
        [$supplier, $supplierProfile, $bank] = $this->createSupplierWithBank();

        // Both invoices have identical due date (25 September 2026)
        // But invA ready_to_pay_at is Week 1 (3 Sep), while invB is Week 4 (23 Sep)
        $this->createTestInvoice([
            'supplier_id' => $supplier->id,
            'submission_number' => 'SUB-DUE-A',
            'invoice_number' => 'INV-DUE-A',
            'invoice_amount' => 15000000,
            'tax_amount' => 0,
            'due_date' => '2026-09-25',
            'ready_to_pay_at' => '2026-09-03 10:00:00',
        ]);

        $this->createTestInvoice([
            'supplier_id' => $supplier->id,
            'submission_number' => 'SUB-DUE-B',
            'invoice_number' => 'INV-DUE-B',
            'invoice_amount' => 25000000,
            'tax_amount' => 0,
            'due_date' => '2026-09-25',
            'ready_to_pay_at' => '2026-09-23 10:00:00',
        ]);

        $forecastService = app(PaymentForecastService::class);
        $weekly = $forecastService->getWeeklyForecast('2026-09');

        // Week 1 (1-7 Sep)
        $this->assertEquals(1, $weekly[0]['count']);
        $this->assertEquals(15000000.0, $weekly[0]['period_amount']);

        // Week 4 (22-28 Sep)
        $this->assertEquals(1, $weekly[3]['count']);
        $this->assertEquals(25000000.0, $weekly[3]['period_amount']);
    }

    public function test_drp_and_paid_transitions_do_not_double_count(): void
    {
        [$supplier, $supplierProfile, $bank] = $this->createSupplierWithBank();
        $finance = User::factory()->create(['role' => 'finance', 'is_active' => true]);

        $invoice = $this->createTestInvoice([
            'supplier_id' => $supplier->id,
            'submission_number' => 'SUB-DRP-DC',
            'invoice_number' => 'INV-DRP-DC',
            'invoice_amount' => 40000000,
            'tax_amount' => 4400000,
            'ready_to_pay_at' => '2026-09-05 10:00:00',
            'approved_at' => '2026-09-05 10:00:00',
        ]);

        // Place into DRP Batch with transfer date in future
        $batchService = app(PaymentBatchService::class);
        $batch = $batchService->createSupplierBatch($finance, [$invoice->id], 'Test Anti Double Count');
        $group = $batch->groups()->first();
        $group->update(['transfer_date' => '2026-09-26']);

        $forecastService = app(PaymentForecastService::class);
        $weekly = $forecastService->getWeeklyForecast('2026-09');

        // Total count across all weeks of September must be exactly 1
        $totalCount = array_sum(array_column($weekly, 'count'));
        $totalAmount = array_sum(array_column($weekly, 'period_amount'));

        $this->assertEquals(1, $totalCount);
        $this->assertEquals(44400000.0, $totalAmount);
    }

    public function test_under_verification_invoices_are_excluded(): void
    {
        [$supplier, $supplierProfile, $bank] = $this->createSupplierWithBank();

        $this->createTestInvoice([
            'supplier_id' => $supplier->id,
            'submission_number' => 'SUB-UV-1',
            'invoice_number' => 'INV-UV-1',
            'invoice_amount' => 90000000,
            'tax_amount' => 9900000,
            'status' => LocalInvoice::STATUS_UNDER_VERIFICATION,
            'ready_to_pay_at' => null,
            'due_date' => '2026-09-10',
        ]);

        $this->createTestInvoice([
            'supplier_id' => $supplier->id,
            'submission_number' => 'SUB-WP-1',
            'invoice_number' => 'INV-WP-1',
            'invoice_amount' => 30000000,
            'tax_amount' => 3300000,
            'status' => LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT,
            'ready_to_pay_at' => null,
            'due_date' => '2026-09-10',
        ]);

        $forecastService = app(PaymentForecastService::class);
        $weekly = $forecastService->getWeeklyForecast('2026-09');

        $totalCount = array_sum(array_column($weekly, 'count'));
        $this->assertEquals(0, $totalCount);

        $summary = $forecastService->getCurrentReadyToPaySummary();
        $this->assertEquals(0, $summary['count']);
        $this->assertEquals(0.0, $summary['amount']);
    }

    public function test_current_ready_to_pay_summary_reflects_only_outstanding_invoices(): void
    {
        [$supplier, $supplierProfile, $bank] = $this->createSupplierWithBank();

        // 1. Outstanding Ready to Pay
        $this->createTestInvoice([
            'supplier_id' => $supplier->id,
            'submission_number' => 'SUB-RTP-ACTIVE',
            'invoice_number' => 'INV-RTP-ACTIVE',
            'invoice_amount' => 15000000,
            'tax_amount' => 1650000,
            'status' => LocalInvoice::STATUS_READY_TO_PAY,
            'ready_to_pay_at' => now(),
        ]);

        // 2. Paid invoice (not outstanding)
        $this->createTestInvoice([
            'supplier_id' => $supplier->id,
            'submission_number' => 'SUB-PAID-NOT-OUTSTANDING',
            'invoice_number' => 'INV-PAID-NOT-OUTSTANDING',
            'invoice_amount' => 50000000,
            'tax_amount' => 5500000,
            'status' => LocalInvoice::STATUS_PAID,
            'ready_to_pay_at' => now()->subDays(5),
            'paid_at' => now(),
        ]);

        $forecastService = app(PaymentForecastService::class);
        $summary = $forecastService->getCurrentReadyToPaySummary();

        $this->assertEquals(1, $summary['count']);
        $this->assertEquals(16650000.0, $summary['amount']);
        $this->assertEquals('16650000.00', $summary['total_amount_exact']);
    }

    public function test_empty_periods_preserve_timeline_and_carry_forward_cumulative(): void
    {
        [$supplier, $supplierProfile, $bank] = $this->createSupplierWithBank();

        // Only one invoice in Week 1 of September 2026 (4 September 2026)
        $this->createTestInvoice([
            'supplier_id' => $supplier->id,
            'submission_number' => 'SUB-SINGLE-EVENT',
            'invoice_number' => 'INV-SINGLE-EVENT',
            'invoice_amount' => 8000000,
            'tax_amount' => 0,
            'status' => LocalInvoice::STATUS_READY_TO_PAY,
            'ready_to_pay_at' => '2026-09-04 10:00:00',
        ]);

        $forecastService = app(PaymentForecastService::class);
        $weekly = $forecastService->getWeeklyForecast('2026-09');

        // Week 1 (Day 1-7)
        $this->assertEquals(1, $weekly[0]['count']);
        $this->assertEquals(8000000.0, $weekly[0]['period_amount']);
        $this->assertEquals(8000000.0, $weekly[0]['cumulative_amount']);

        // Subsequent empty weeks (Weeks 2, 3, 4, 5) must have count 0, amount 0, but preserve cumulative amount
        for ($i = 1; $i < count($weekly); $i++) {
            $this->assertEquals(0, $weekly[$i]['count'], "Week index {$i} should have 0 count");
            $this->assertEquals(0.0, $weekly[$i]['period_amount'], "Week index {$i} should have 0 period amount");
            $this->assertEquals(8000000.0, $weekly[$i]['cumulative_amount'], "Week index {$i} should carry forward cumulative amount");
        }
    }

    public function test_monetary_precision_with_verifications_and_withholdings(): void
    {
        [$supplier, $supplierProfile, $bank] = $this->createSupplierWithBank();
        $finance = User::factory()->create(['role' => 'finance', 'is_active' => true]);

        $invoice = $this->createTestInvoice([
            'supplier_id' => $supplier->id,
            'submission_number' => 'SUB-WITHHOLDING',
            'invoice_number' => 'INV-WITHHOLDING',
            'invoice_amount' => 10000000,
            'tax_amount' => 1100000,
            'status' => LocalInvoice::STATUS_READY_TO_PAY,
            'ready_to_pay_at' => '2026-09-03 10:00:00',
        ]);

        // Create verification with PPh 23 withholding (2% = 200,000)
        // Net payable = 10,000,000 + 1,100,000 - 200,000 = 10,900,000
        $invoice->verifications()->create([
            'revision_number' => 0,
            'verified_dpp' => 10000000,
            'verified_ppn' => 1100000,
            'pph_23_applicable' => true,
            'pph_23_rate' => 2.00,
            'pph_23_amount' => 200000,
            'is_section_a_passed' => true,
            'is_section_b_passed' => true,
            'is_locked' => true,
            'verified_by' => $finance->id,
        ]);

        $forecastService = app(PaymentForecastService::class);
        $weekly = $forecastService->getWeeklyForecast('2026-09');

        $w1 = $weekly[0];
        $this->assertEquals(10900000.0, $w1['period_amount']);
        $this->assertEquals('10900000.00', $w1['period_amount_exact']);
    }

    public function test_available_months_helper_returns_expected_structure(): void
    {
        $forecastService = app(PaymentForecastService::class);
        $months = $forecastService->getAvailableMonths(5, 1, Carbon::parse('2026-09-24'));

        // 5 past + 1 current + 1 future = 7 months
        $this->assertCount(7, $months);
        $this->assertEquals('2026-04', $months[0]['key']);
        $this->assertEquals('2026-09', $months[5]['key']);
        $this->assertTrue($months[5]['is_current']);
        $this->assertEquals('2026-10', $months[6]['key']);
        $this->assertFalse($months[6]['is_current']);
    }

    public function test_finance_dashboard_displays_new_forecast_module(): void
    {
        [$supplier, $supplierProfile, $bank] = $this->createSupplierWithBank();
        $finance = User::factory()->create(['role' => 'finance', 'is_active' => true]);

        $this->createTestInvoice([
            'supplier_id' => $supplier->id,
            'submission_number' => 'SUB-DASH-1',
            'invoice_number' => 'INV-DASH-1',
            'invoice_amount' => 12000000,
            'tax_amount' => 1320000,
            'status' => LocalInvoice::STATUS_READY_TO_PAY,
            'ready_to_pay_at' => now(),
        ]);

        $response = $this->actingAs($finance)->get(route('finance.dashboard'));
        $response->assertOk();
        $response->assertSee('Payment Forecast');
        $response->assertSee('Akumulasi Invoice Ready to Pay');
        $response->assertSee('Total Akumulasi Periode');
        $response->assertSee('Ready to Pay Saat Ini');
        $response->assertSee('Invoice Masuk Periode');
        $response->assertSee('Mingguan');
        $response->assertSee('Bulanan');
        $response->assertSee('forecastMonthSelect');
        $response->assertSee('Periode');
        $response->assertSee('Invoice Ready to Pay');
        $response->assertSee('Nilai Periode');
        $response->assertSee('Akumulasi');

        // Test JSON endpoint with month query
        $jsonResponse = $this->actingAs($finance)->get(route('finance.forecast', ['month' => '2026-09']));
        $jsonResponse->assertOk();
        $jsonResponse->assertJsonStructure([
            'selected_month',
            'available_months',
            'weekly' => [
                '*' => ['period_type', 'week_number', 'start', 'end', 'label', 'count', 'period_amount', 'cumulative_amount'],
            ],
            'monthly' => [
                '*' => ['period_type', 'start', 'end', 'label', 'count', 'period_amount', 'cumulative_amount'],
            ],
            'summary' => [
                'count',
                'total_amount',
                'total_amount_exact',
            ],
        ]);
        $this->assertEquals('2026-09', $jsonResponse->json('selected_month'));
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

        $notificationService = app(InvoiceNotificationService::class);

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
