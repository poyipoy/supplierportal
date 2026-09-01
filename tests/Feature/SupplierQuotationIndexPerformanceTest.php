<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\Period;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupplierQuotationIndexPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private User $supplier;

    private User $otherSupplier;

    private User $purchasing;

    private User $admin;

    private ExchangeRate $rate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $this->supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $this->otherSupplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);

        $this->rate = ExchangeRate::create([
            'currency' => 'USD',
            'rate_to_idr' => 16000,
            'valid_from' => now(),
            'created_by' => $this->admin->id,
        ]);
    }

    public function test_metrics_preserve_visibility_status_and_closed_period_semantics(): void
    {
        $period1 = $this->createPeriod('Period One', 1);
        $prDraft = $this->createRequisition($period1, 'submitted');
        $prSubmitted = $this->createRequisition($period1, 'bidding');
        $prUnresponded = $this->createRequisition($period1, 'submitted');
        $prInvisible = $this->createRequisition($period1, 'submitted');
        $prInvisible->invitedSuppliers()->attach($this->otherSupplier->id);

        $this->createQuotation($prDraft, $this->supplier, Quotation::STATUS_DRAFT);
        $this->createQuotation($prSubmitted, $this->supplier, Quotation::STATUS_SUBMITTED);
        $this->createQuotation($prUnresponded, $this->otherSupplier, Quotation::STATUS_SUBMITTED);

        $period2 = $this->createPeriod('Period Two', 2);
        $prRejected = $this->createRequisition($period2, 'completed');
        $this->createQuotation($prRejected, $this->supplier, Quotation::STATUS_REJECTED);

        $period2Invisible = $this->createRequisition($period2, 'submitted');
        $period2Invisible->invitedSuppliers()->attach($this->otherSupplier->id);
        $this->createQuotation($period2Invisible, $this->otherSupplier, Quotation::STATUS_SUBMITTED);

        $period3 = $this->createPeriod('Period Three', 3);
        $this->createRequisition($period3, 'submitted');
        $this->createRequisition($period3, 'bidding');

        $closedPeriod = $this->createPeriod('Closed Historical Period', 4, 'closed');
        $closedPr = $this->createRequisition($closedPeriod, 'completed');
        $this->createQuotation($closedPr, $this->supplier, Quotation::STATUS_ACCEPTED);

        $response = $this->actingAs($this->supplier)
            ->get(route('supplier.quotations.index'))
            ->assertOk();

        $periods = $response->viewData('periods');
        $this->assertCount(4, $periods);

        $this->assertPeriodMetrics($periods->firstWhere('id', $period1->id), 3, 2, 0, 1);
        $this->assertPeriodMetrics($periods->firstWhere('id', $period2->id), 1, 1, 1, 0);
        $this->assertPeriodMetrics($periods->firstWhere('id', $period3->id), 2, 0, 0, 2);
        $this->assertPeriodMetrics($periods->firstWhere('id', $closedPeriod->id), 1, 1, 0, 0);
    }

    public function test_query_count_does_not_grow_with_pr_or_quotation_count(): void
    {
        $period = $this->createPeriod('Scale Test Period', 5);
        $initialPrs = [
            $this->createRequisition($period, 'submitted'),
            $this->createRequisition($period, 'submitted'),
        ];
        $this->createQuotation($initialPrs[0], $this->supplier, Quotation::STATUS_DRAFT);
        $this->createQuotation($initialPrs[1], $this->otherSupplier, Quotation::STATUS_SUBMITTED);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->actingAs($this->supplier)->get(route('supplier.quotations.index'))->assertOk();
        $queryCountInitial = count(DB::getQueryLog());

        for ($i = 0; $i < 8; $i++) {
            $pr = $this->createRequisition($period, 'submitted');
            if ($i % 2 === 0) {
                $this->createQuotation($pr, $this->supplier, Quotation::STATUS_SUBMITTED);
            }
        }

        DB::flushQueryLog();
        $this->actingAs($this->supplier)->get(route('supplier.quotations.index'))->assertOk();
        $queryCountWithTenPrs = count(DB::getQueryLog());

        // SQL statement count stays constant; hydrated rows still scale with data volume.
        $this->assertSame(
            $queryCountInitial,
            $queryCountWithTenPrs,
            "Query count should remain constant regardless of PR count (got {$queryCountInitial} vs {$queryCountWithTenPrs})."
        );
    }

    private function createPeriod(string $name, int $month, string $status = 'open'): Period
    {
        return Period::create([
            'name' => $name,
            'month' => $month,
            'year' => 2026,
            'status' => $status,
            'created_by' => $this->admin->id,
        ]);
    }

    private function createRequisition(Period $period, string $status): PurchaseRequisition
    {
        return PurchaseRequisition::create([
            'period_id' => $period->id,
            'created_by' => $this->purchasing->id,
            'status' => $status,
        ]);
    }

    private function createQuotation(PurchaseRequisition $pr, User $supplier, string $status): Quotation
    {
        return Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $supplier->id,
            'currency' => 'USD',
            'exchange_rate_id' => $this->rate->id,
            'status' => $status,
        ]);
    }

    private function assertPeriodMetrics(
        ?Period $period,
        int $total,
        int $responded,
        int $rejected,
        int $unresponded,
    ): void {
        $this->assertNotNull($period);
        $this->assertSame($total, $period->total_prs);
        $this->assertSame($responded, $period->responded_prs);
        $this->assertSame($rejected, $period->rejected_prs);
        $this->assertSame($unresponded, $period->unresponded_prs);
    }
}
