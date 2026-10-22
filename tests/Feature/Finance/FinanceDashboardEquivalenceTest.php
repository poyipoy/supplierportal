<?php

namespace Tests\Feature\Finance;

use App\Models\LocalInvoice;
use App\Models\User;
use App\Services\LocalInvoice\InvoiceQuery;
use App\Services\Payment\PaymentForecastService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The finance dashboard reads KPIs from one grouped count and builds the weekly
 * and monthly forecasts from one invoice read. Every figure must stay
 * identical to the former per-query results, including exact money strings.
 */
class FinanceDashboardEquivalenceTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    private User $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        // 15 Oct 2026 10:00 WIB.
        $this->travelTo('2026-10-15 03:00:00');
        $this->finance = User::factory()->create(['role' => 'finance', 'is_active' => true]);
        $this->supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $this->seedInvoices();
    }

    public function test_combined_forecasts_equal_the_separate_forecasts(): void
    {
        $service = app(PaymentForecastService::class);

        foreach (['2026-10', '2026-09', '2026-11', '2026-05', '2026-04'] as $month) {
            $this->assertSame(
                ['weekly' => $service->getWeeklyForecast($month), 'monthly' => $service->getMonthlyForecast()],
                $service->getDashboardForecasts($month),
                "Forecast mismatch for {$month}",
            );
        }

        // Sanity: the fixture really exercises money, boundaries and exclusions.
        // Week 1 of October: 1,234,567.89 + 135,802.47 - 24,691.36 (verified)
        // plus 1,500,000.10 + 165,000.01 (00:00 WIB on 1 October).
        $october = $service->getDashboardForecasts('2026-10')['weekly'];
        $this->assertSame('3010679.11', $october[0]['period_amount_exact']);
        $this->assertSame(2, $october[0]['invoice_count']);
    }

    public function test_kpis_and_overdue_equal_the_former_per_status_queries(): void
    {
        $expected = [
            'waiting_physical' => LocalInvoice::where('status', LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT)->count(),
            'under_verification' => LocalInvoice::whereIn('status', [LocalInvoice::STATUS_UNDER_VERIFICATION, 'UNDER_REVIEW'])->count(),
            'need_revision' => LocalInvoice::where('status', LocalInvoice::STATUS_NEED_REVISION)->count(),
            'ready_to_pay' => LocalInvoice::whereIn('status', [LocalInvoice::STATUS_READY_TO_PAY, 'APPROVED', 'PAYMENT_SCHEDULED'])->count(),
            'paid' => LocalInvoice::whereIn('status', [LocalInvoice::STATUS_PAID, 'COMPLETED'])->count(),
            'expired' => LocalInvoice::where('status', LocalInvoice::STATUS_EXPIRED)->count(),
            'overdue' => LocalInvoice::whereIn('status', [LocalInvoice::STATUS_READY_TO_PAY, 'APPROVED', 'PAYMENT_SCHEDULED'])
                ->whereDate('due_date', '<', '2026-10-15')->count(),
        ];
        $this->assertGreaterThan(0, $expected['overdue']);

        $response = $this->actingAs($this->finance)->get(route('finance.dashboard'))->assertOk();

        $this->assertSame($expected, $response->viewData('kpis'));
        $this->assertSame(
            LocalInvoice::whereIn('status', [LocalInvoice::STATUS_READY_TO_PAY, 'APPROVED', 'PAYMENT_SCHEDULED'])
                ->whereDate('due_date', '<', '2026-10-15')->orderBy('id')->pluck('id')->all(),
            app(InvoiceQuery::class)->filtered(['overdue' => 1])->orderBy('id')->pluck('id')->all(),
        );
    }

    private function seedInvoices(): void
    {
        $verified = $this->invoice(['invoice_amount' => '1234567.89', 'tax_amount' => '135802.47', 'ready_to_pay_at' => '2026-10-02 02:00:00']);
        $verified->verifications()->create([
            'revision_number' => 0, 'verified_dpp' => '1234567.89', 'verified_ppn' => '135802.47',
            'pph_23_applicable' => true, 'pph_23_rate' => 2.00, 'pph_23_amount' => '24691.36',
            'is_section_a_passed' => true, 'is_section_b_passed' => true, 'is_locked' => true, 'verified_by' => $this->finance->id,
        ]);
        // 2026-10-01 00:00 WIB exactly, and one second earlier (still September).
        $this->invoice(['invoice_amount' => '1500000.10', 'tax_amount' => '165000.01', 'ready_to_pay_at' => '2026-09-30 17:00:00']);
        $this->invoice(['invoice_amount' => '700000.00', 'tax_amount' => '0', 'ready_to_pay_at' => '2026-09-30 16:59:59']);
        // Legacy approval date used only when ready_to_pay_at is missing.
        $this->invoice(['status' => 'APPROVED', 'ready_to_pay_at' => null, 'approved_at' => '2026-09-10 05:00:00', 'invoice_amount' => '250000.50', 'tax_amount' => '27500.05']);
        $this->invoice(['status' => 'PAYMENT_SCHEDULED', 'ready_to_pay_at' => '2026-01-10 05:00:00', 'approved_at' => '2026-09-12 05:00:00']);
        $this->invoice(['status' => LocalInvoice::STATUS_PAID, 'ready_to_pay_at' => '2026-08-20 05:00:00']);
        $this->invoice(['status' => 'COMPLETED', 'ready_to_pay_at' => '2026-05-03 05:00:00']);
        $this->invoice(['status' => LocalInvoice::STATUS_REJECTED, 'ready_to_pay_at' => '2026-10-03 05:00:00']);
        $this->invoice(['status' => LocalInvoice::STATUS_CANCELLED, 'ready_to_pay_at' => '2026-10-04 05:00:00']);
        // Future month outside the rolling monthly window.
        $this->invoice(['ready_to_pay_at' => '2026-11-05 05:00:00', 'invoice_amount' => '999.99', 'tax_amount' => '0.01']);
        // Month end (week 5) and the window start.
        $this->invoice(['ready_to_pay_at' => '2026-10-31 16:00:00']);
        $this->invoice(['ready_to_pay_at' => '2026-04-30 18:00:00']);
        // KPI statuses and overdue rows.
        $this->invoice(['status' => LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT, 'ready_to_pay_at' => null]);
        $this->invoice(['status' => LocalInvoice::STATUS_UNDER_VERIFICATION, 'ready_to_pay_at' => null]);
        $this->invoice(['status' => 'UNDER_REVIEW', 'ready_to_pay_at' => null]);
        $this->invoice(['status' => LocalInvoice::STATUS_NEED_REVISION, 'ready_to_pay_at' => null]);
        $this->invoice(['status' => LocalInvoice::STATUS_EXPIRED, 'ready_to_pay_at' => null]);
        $this->invoice(['due_date' => '2026-10-14', 'ready_to_pay_at' => '2026-09-01 05:00:00']);
        $this->invoice(['status' => 'APPROVED', 'due_date' => '2026-10-15', 'ready_to_pay_at' => null, 'approved_at' => '2026-09-01 05:00:00']);
    }

    private function invoice(array $attributes): LocalInvoice
    {
        return LocalInvoice::create(array_merge([
            'supplier_id' => $this->supplier->id,
            'submission_number' => 'SUB-'.uniqid('', true),
            'invoice_number' => 'INV-'.uniqid('', true),
            'po_number' => 'PO-'.uniqid('', true),
            'currency' => 'IDR',
            'invoice_amount' => '1000000.00',
            'tax_amount' => '110000.00',
            'status' => LocalInvoice::STATUS_READY_TO_PAY,
            'invoice_date' => '2026-09-01',
            'due_date' => '2026-12-31',
            'payment_term_days_snapshot' => 30,
            'cashier_received_at' => '2026-09-01 05:00:00',
            'submitted_at' => '2026-09-01 05:00:00',
        ], $attributes));
    }
}
