<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\Period;
use App\Models\PrItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PriceComparisonPerformanceRegressionTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasing;

    private User $supplierA;

    private User $supplierB;

    private Period $period;

    private ExchangeRate $quotationRate;

    private ExchangeRate $historicalPoRate;

    private int $requisitionSequence = 0;

    private int $purchaseOrderSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasing = User::factory()->create([
            'role' => 'purchasing',
            'is_active' => true,
        ]);
        $this->supplierA = User::factory()->create([
            'role' => 'supplier',
            'is_active' => true,
            'name' => 'Performance Supplier A',
        ]);
        $this->supplierB = User::factory()->create([
            'role' => 'supplier',
            'is_active' => true,
            'name' => 'Performance Supplier B',
        ]);
        $this->period = Period::create([
            'name' => 'Performance Period',
            'month' => 8,
            'year' => 2026,
            'status' => 'open',
            'created_by' => $this->purchasing->id,
        ]);
        $this->quotationRate = $this->createRate(16000, '2025-01-01');
        $this->historicalPoRate = $this->createRate(15000, '2024-01-01');
    }

    public function test_inter_supplier_preserves_options_matrix_snapshot_rates_and_authoritative_amounts(): void
    {
        $requisition = $this->createRequisition('Inter Supplier Material');
        $items = collect(range(1, 4))->map(fn (int $sequence): PrItem => $this->createItem(
            $requisition,
            "Inter Supplier Material {$sequence}",
            $sequence === 1 ? 3 : 1,
            $sequence === 1 ? 2.5 : 10,
        ));

        $submitted = $this->createQuotation(
            $requisition,
            $this->supplierA,
            'submitted',
            $this->quotationRate,
            '2026-06-15 09:00:00',
        );
        $rejected = $this->createQuotation(
            $requisition,
            $this->supplierB,
            'rejected',
            $this->historicalPoRate,
            '2026-06-16 09:00:00',
        );
        $this->createQuotation(
            $requisition,
            $this->supplierB,
            'draft',
            $this->quotationRate,
            null,
        );

        foreach ($items as $index => $item) {
            $submitted->items()->create([
                'pr_item_id' => $item->id,
                'price_per_kg' => 10.125 + $index,
                'amount' => $index === 0 ? 1 : 999,
            ]);
            $rejected->items()->create([
                'pr_item_id' => $item->id,
                'price_per_kg' => 9.5 + $index,
                'amount' => 1,
            ]);
        }

        // A newer rate must not change either quotation's stored snapshot.
        $this->createRate(20000, '2026-08-01');

        $response = $this->actingAs($this->purchasing)
            ->get(route('purchasing.comparison.inter-supplier', [
                'pr_id' => $requisition,
            ]))
            ->assertOk();

        $options = $response->viewData('eligiblePrOptions');
        $option = $options->firstWhere('id', $requisition->getRouteKey());
        $this->assertNotNull($option);
        $this->assertSame($requisition->getRouteKey(), $option['id']);
        $this->assertSame(2, $option['quotationCount']);
        $this->assertStringContainsString('(2 quotation(s))', $option['label']);
        $this->assertSame(
            'Inter Supplier Material 1, Inter Supplier Material 2, Inter Supplier Material 3 (+1 lainnya)',
            $option['previewMaterials'],
        );

        $comparison = $response->viewData('comparison');
        $this->assertCount(2, $comparison['suppliers']);
        $this->assertSame(
            [$submitted->id, $rejected->id],
            $comparison['suppliers']->pluck('quotation_id')->all(),
        );
        $this->assertCount(4, $comparison['matrix']);

        $submittedPrice = $comparison['matrix'][0]['prices'][$submitted->id];
        $rejectedPrice = $comparison['matrix'][0]['prices'][$rejected->id];
        $this->assertEqualsWithDelta(162000, (float) $submittedPrice['price_idr'], 0.0001);
        $this->assertEqualsWithDelta(142500, (float) $rejectedPrice['price_idr'], 0.0001);
        $this->assertEqualsWithDelta(
            QuotationItem::calculateAmount($items->first(), 10.125),
            (float) $submittedPrice['amount'],
            0.0001,
        );
        $this->assertNotSame(1.0, (float) $submittedPrice['amount']);
        $this->assertStringStartsWith(
            route('purchasing.quotations.show', $submitted),
            $submittedPrice['detail_url'],
        );

        $chartData = $response->viewData('chartData');
        $submittedDataset = collect($chartData['datasets'])->firstWhere('label', $this->supplierA->name);
        $rejectedDataset = collect($chartData['datasets'])->firstWhere('label', $this->supplierB->name);
        $this->assertSame(162000.0, (float) $submittedDataset['data'][0]);
        $this->assertSame(142500.0, (float) $rejectedDataset['data'][0]);
    }

    public function test_inter_supplier_query_growth_does_not_scale_with_eligible_requisition_count(): void
    {
        foreach (range(1, 2) as $sequence) {
            $this->createEligibleRequisition("Small Query Material {$sequence}");
        }

        $this->actingAs($this->purchasing)
            ->get(route('purchasing.comparison.inter-supplier'))
            ->assertOk();

        [$smallResponse, $smallQueries] = $this->captureQueries(
            fn () => $this->get(route('purchasing.comparison.inter-supplier')),
        );
        $smallResponse->assertOk();

        foreach (range(3, 10) as $sequence) {
            $this->createEligibleRequisition("Large Query Material {$sequence}");
        }

        [$largeResponse, $largeQueries] = $this->captureQueries(
            fn () => $this->get(route('purchasing.comparison.inter-supplier')),
        );
        $largeResponse->assertOk();
        $this->assertCount(10, $largeResponse->viewData('eligiblePrOptions'));

        $this->assertLessThanOrEqual(
            count($smallQueries) + 1,
            count($largeQueries),
            'Inter-supplier query count grew with the number of eligible requisitions.',
        );
        $this->assertSame(1, $this->countQueriesContaining($smallQueries, 'from `pr_items`'));
        $this->assertSame(1, $this->countQueriesContaining($largeQueries, 'from `pr_items`'));
    }

    public function test_monthly_history_paginates_details_without_truncating_chart_or_summary(): void
    {
        $materialName = 'Paginated Historical Alloy';
        $requisitions = collect();

        foreach (range(1, 51) as $sequence) {
            $requisition = $this->createRequisition("Historical page {$sequence}");
            $requisitions->push($requisition);
            $item = $this->createItem($requisition, $materialName, 2, 5);
            $price = 10 + $sequence;
            $quotation = $this->createQuotation(
                $requisition,
                $this->supplierA,
                'accepted',
                $this->quotationRate,
                Carbon::parse('2024-01-01')->addDays($sequence)->toDateTimeString(),
            );
            $quotation->items()->create([
                'pr_item_id' => $item->id,
                'price_per_kg' => $price,
                'amount' => 1,
            ]);

            $purchaseAt = Carbon::parse('2024-01-01')->addDays($sequence);
            $purchaseOrder = PurchaseOrder::create([
                'supplier_id' => $this->supplierA->id,
                'currency' => 'USD',
                'exchange_rate_id' => $this->historicalPoRate->id,
                'po_number' => $this->nextPurchaseOrderNumber(),
                'status' => 'completed',
                'created_by' => $this->purchasing->id,
            ]);
            $purchaseOrder->forceFill([
                'created_at' => $purchaseAt,
                'updated_at' => $purchaseAt,
            ])->saveQuietly();
            $purchaseOrder->quotations()->attach($quotation->id);
        }

        $parameters = [
            'supplier_id' => $this->supplierA->getRouteKey(),
            'material_name' => $materialName,
            'period_view' => 'monthly',
            'range' => 'all',
            'view' => 'json',
        ];
        $this->actingAs($this->purchasing);
        $requestPage = fn (int $page) => $this
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->getJson(route('purchasing.comparison.historical', [
                ...$parameters,
                'history_page' => $page,
            ]));

        $firstPage = $requestPage(1)
            ->assertOk()
            ->assertJsonCount(51, 'chartData.labels')
            ->assertJsonCount(51, 'chartData.pricesIdr')
            ->assertJsonCount(50, 'tableData')
            ->assertJsonPath('pagination.current_page', 1)
            ->assertJsonPath('pagination.last_page', 2)
            ->assertJsonPath('pagination.total', 51)
            ->assertJsonPath('pagination.from', 1)
            ->assertJsonPath('pagination.to', 50);

        $this->assertEqualsWithDelta(165000, (float) $firstPage->json('tableData.0.price_idr'), 0.0001);
        $this->assertEqualsWithDelta(1650000, (float) $firstPage->json('tableData.0.total_idr'), 0.0001);
        $this->assertStringStartsWith(
            route('purchasing.requisitions.show', $requisitions->first()),
            (string) $firstPage->json('tableData.0.pr_url'),
        );

        $secondPage = $requestPage(2)
            ->assertOk()
            ->assertJsonCount(51, 'chartData.labels')
            ->assertJsonCount(1, 'tableData')
            ->assertJsonPath('pagination.current_page', 2)
            ->assertJsonPath('pagination.from', 51)
            ->assertJsonPath('pagination.to', 51);

        $this->assertSame(
            hash('sha256', json_encode($firstPage->json('chartData'), JSON_THROW_ON_ERROR)),
            hash('sha256', json_encode($secondPage->json('chartData'), JSON_THROW_ON_ERROR)),
        );
        $this->assertSame($firstPage->json('summary'), $secondPage->json('summary'));
        $this->assertEqualsWithDelta(1.67, (float) $secondPage->json('tableData.0.change_pct'), 0.0001);

        $requestPage(999)
            ->assertOk()
            ->assertJsonPath('pagination.current_page', 2)
            ->assertJsonCount(1, 'tableData');

        $yearly = $this
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->getJson(route('purchasing.comparison.historical', [
                ...$parameters,
                'period_view' => 'yearly',
                'history_page' => 2,
            ]))
            ->assertOk()
            ->assertJsonPath('pagination', null)
            ->assertJsonCount(1, 'chartData.labels')
            ->assertJsonCount(1, 'tableData');

        $this->assertSame('yearly', $yearly->json('periodView'));
    }

    public function test_vs_best_preserves_financial_summary_search_and_hash_link_contracts(): void
    {
        $pair = $this->createVsBestPair(
            'Precision Alloy Alpha',
            historicalPrice: 8,
            currentPrice: 10,
            quantity: 3,
            weightNeeded: 2.5,
            currentAmount: 1,
        );

        // The calculation must remain tied to document snapshots, not this newer rate.
        $this->createRate(20000, '2026-08-01');

        $response = $this->actingAs($this->purchasing);
        $response = $this->vsBestRequest(length: 25)->assertOk();

        $response
            ->assertJsonStructure([
                'draw',
                'recordsTotal',
                'recordsFiltered',
                'data',
                'summary' => [
                    'total_rows',
                    'competitive_count',
                    'above_count',
                    'total_potential_difference_idr',
                    'average_diff_idr_per_kg',
                ],
            ])
            ->assertJsonPath('draw', 1)
            ->assertJsonPath('recordsTotal', 1)
            ->assertJsonPath('recordsFiltered', 1)
            ->assertJsonPath('summary.total_rows', 1)
            ->assertJsonPath('summary.competitive_count', 0)
            ->assertJsonPath('summary.above_count', 1)
            ->assertJsonPath('summary.total_potential_difference_idr', 300000)
            ->assertJsonPath('summary.average_diff_idr_per_kg', 40000);

        $row = $response->json('data.0');
        $this->assertSame(120000.0, (float) $row['best_price_idr']);
        $this->assertEmpty(array_diff([
            'material_display',
            'current_price_display',
            'best_price_display',
            'diff_display',
            'potential_difference_display',
            'status_badge',
            'action',
        ], array_keys($row)));
        $this->assertStringContainsString('Qty: 3', $row['material_display']);
        $this->assertStringContainsString('Berat/unit: 2.5 kg', $row['material_display']);
        $this->assertStringContainsString('Total weight: 7.5 kg', $row['material_display']);
        $this->assertStringContainsString('Rp 160.000', $row['current_price_display']);
        $this->assertStringContainsString('10 USD/kg', $row['current_price_display']);
        $this->assertStringContainsString('Rp 120.000', $row['best_price_display']);
        $this->assertStringContainsString('8 USD/kg', $row['best_price_display']);
        $this->assertStringContainsString('+Rp 40.000', $row['diff_display']);
        $this->assertStringContainsString('+33.33%', $row['diff_display']);
        $this->assertStringContainsString('Rp 300.000', $row['potential_difference_display']);
        $this->assertStringContainsString('Above History', $row['status_badge']);

        $this->assertStringContainsString(
            route('purchasing.requisitions.show', $pair['current_pr']),
            $row['material_display'],
        );
        $this->assertStringContainsString(
            route('purchasing.requisitions.show', $pair['historical_pr']),
            $row['best_price_display'],
        );
        $this->assertStringContainsString(
            route('purchasing.quotations.show', $pair['current_quotation']),
            $row['action'],
        );
        $this->assertStringContainsString(
            route('purchasing.quotations.show', $pair['historical_quotation']),
            $row['action'],
        );
        $this->assertFalse(ctype_digit($pair['current_pr']->getRouteKey()));
        $this->assertFalse(ctype_digit($pair['current_quotation']->getRouteKey()));

        $this->createVsBestPair(
            'Precision Alloy Beta',
            historicalPrice: 9,
            currentPrice: 9.1,
            quantity: 1,
            weightNeeded: 5,
        );

        $filtered = $this->vsBestRequest(length: 25, search: 'Precision Alloy Alpha')
            ->assertOk()
            ->assertJsonPath('recordsTotal', 2)
            ->assertJsonPath('recordsFiltered', 1)
            ->assertJsonPath('summary.total_rows', 1)
            ->assertJsonCount(1, 'data');
        $this->assertStringContainsString(
            'Precision Alloy Alpha',
            $filtered->json('data.0.material_display'),
        );
    }

    public function test_vs_best_link_generation_query_count_does_not_scale_with_page_length(): void
    {
        foreach (range(1, 6) as $sequence) {
            $this->createVsBestPair(
                'Query Growth Material '.str_pad((string) $sequence, 2, '0', STR_PAD_LEFT),
                historicalPrice: 8 + ($sequence / 10),
                currentPrice: 10 + ($sequence / 10),
                quantity: 2,
                weightNeeded: 10,
            );
        }

        $this->actingAs($this->purchasing);
        $this->vsBestRequest(length: 6)->assertOk();

        [$oneRowResponse, $oneRowQueries] = $this->captureQueries(
            fn () => $this->vsBestRequest(length: 1),
        );
        $oneRowResponse->assertOk()->assertJsonCount(1, 'data');

        [$sixRowResponse, $sixRowQueries] = $this->captureQueries(
            fn () => $this->vsBestRequest(length: 6),
        );
        $sixRowResponse
            ->assertOk()
            ->assertJsonPath('recordsFiltered', 6)
            ->assertJsonCount(6, 'data');

        $this->assertLessThanOrEqual(
            count($oneRowQueries) + 1,
            count($sixRowQueries),
            'vs-best query count grew with rendered DataTable rows; check route-link model lookups.',
        );
        $this->assertLessThanOrEqual(
            $this->routeModelLookupCount($oneRowQueries) + 1,
            $this->routeModelLookupCount($sixRowQueries),
            'vs-best generated per-row PurchaseRequisition or Quotation lookup queries.',
        );
    }

    public function test_vs_best_excludes_current_rows_from_soft_deleted_requisitions(): void
    {
        $pair = $this->createVsBestPair(
            'Soft Deleted Current Material',
            historicalPrice: 8,
            currentPrice: 10,
            quantity: 1,
            weightNeeded: 10,
        );
        $pair['current_pr']->delete();

        $this->actingAs($this->purchasing);
        $this->vsBestRequest(length: 25)
            ->assertOk()
            ->assertJsonPath('recordsTotal', 0)
            ->assertJsonPath('recordsFiltered', 0)
            ->assertJsonPath('summary.total_rows', 0)
            ->assertJsonCount(0, 'data');
    }

    public function test_vs_best_does_not_link_to_a_soft_deleted_historical_requisition(): void
    {
        $pair = $this->createVsBestPair(
            'Soft Deleted Historical Material',
            historicalPrice: 8,
            currentPrice: 10,
            quantity: 1,
            weightNeeded: 10,
        );
        $historicalUrl = route('purchasing.requisitions.show', $pair['historical_pr']);
        $pair['historical_pr']->delete();

        $this->actingAs($this->purchasing);
        $response = $this->vsBestRequest(length: 25)
            ->assertOk()
            ->assertJsonPath('recordsTotal', 1)
            ->assertJsonPath('recordsFiltered', 1)
            ->assertJsonPath('summary.total_rows', 1)
            ->assertJsonCount(1, 'data');

        $this->assertStringContainsString('PR: -', $response->json('data.0.best_price_display'));
        $this->assertStringNotContainsString($historicalUrl, $response->json('data.0.best_price_display'));
    }

    private function createEligibleRequisition(string $materialName): PurchaseRequisition
    {
        $requisition = $this->createRequisition($materialName);
        $this->createItem($requisition, $materialName, 1, 10);
        $this->createQuotation(
            $requisition,
            $this->supplierA,
            'submitted',
            $this->quotationRate,
            '2026-06-15 09:00:00',
        );
        $this->createQuotation(
            $requisition,
            $this->supplierB,
            'accepted',
            $this->quotationRate,
            '2026-06-16 09:00:00',
        );

        return $requisition;
    }

    /**
     * @return array{
     *     historical_pr: PurchaseRequisition,
     *     historical_quotation: Quotation,
     *     current_pr: PurchaseRequisition,
     *     current_quotation: Quotation
     * }
     */
    private function createVsBestPair(
        string $materialName,
        float $historicalPrice,
        float $currentPrice,
        int $quantity,
        float $weightNeeded,
        ?float $currentAmount = null,
    ): array {
        $historicalPr = $this->createRequisition($materialName.' History');
        $historicalItem = $this->createItem(
            $historicalPr,
            $materialName,
            $quantity,
            $weightNeeded,
        );
        $historicalQuotation = $this->createQuotation(
            $historicalPr,
            $this->supplierA,
            'accepted',
            $this->quotationRate,
            '2025-06-15 09:00:00',
        );
        $historicalQuotation->items()->create([
            'pr_item_id' => $historicalItem->id,
            'price_per_kg' => $historicalPrice,
            'amount' => QuotationItem::calculateAmount($historicalItem, $historicalPrice),
        ]);

        $purchaseOrder = PurchaseOrder::create([
            'supplier_id' => $this->supplierA->id,
            'currency' => 'USD',
            'exchange_rate_id' => $this->historicalPoRate->id,
            'po_number' => $this->nextPurchaseOrderNumber(),
            'status' => 'completed',
            'created_by' => $this->purchasing->id,
        ]);
        $purchaseOrder->forceFill([
            'created_at' => '2025-06-20 10:00:00',
            'updated_at' => '2025-06-20 10:00:00',
        ])->saveQuietly();
        $purchaseOrder->quotations()->attach($historicalQuotation->id);

        $currentPr = $this->createRequisition($materialName.' Current');
        $currentItem = $this->createItem(
            $currentPr,
            $materialName,
            $quantity,
            $weightNeeded,
        );
        $currentQuotation = $this->createQuotation(
            $currentPr,
            $this->supplierB,
            'submitted',
            $this->quotationRate,
            '2026-06-15 09:00:00',
        );
        $currentQuotation->items()->create([
            'pr_item_id' => $currentItem->id,
            'price_per_kg' => $currentPrice,
            'amount' => $currentAmount ?? QuotationItem::calculateAmount($currentItem, $currentPrice),
        ]);

        return [
            'historical_pr' => $historicalPr,
            'historical_quotation' => $historicalQuotation,
            'current_pr' => $currentPr,
            'current_quotation' => $currentQuotation,
        ];
    }

    private function createRequisition(string $label): PurchaseRequisition
    {
        $this->requisitionSequence++;

        return PurchaseRequisition::create([
            'period_id' => $this->period->id,
            'created_by' => $this->purchasing->id,
            'pr_number' => 'REQ/PERF/'.str_pad((string) $this->requisitionSequence, 3, '0', STR_PAD_LEFT),
            'notes' => $label,
            'status' => 'bidding',
        ]);
    }

    private function createItem(
        PurchaseRequisition $requisition,
        string $materialName,
        int $quantity,
        float $weightNeeded,
    ): PrItem {
        return $requisition->items()->create([
            'hs_code' => '7209.16.00',
            'material_name' => $materialName,
            'quantity' => $quantity,
            'shape' => 'Flat',
            'thickness' => 2.5,
            'width' => 1000,
            'length' => 2000,
            'weight_needed' => $weightNeeded,
        ]);
    }

    private function createQuotation(
        PurchaseRequisition $requisition,
        User $supplier,
        string $status,
        ExchangeRate $exchangeRate,
        ?string $submittedAt,
    ): Quotation {
        return Quotation::create([
            'pr_id' => $requisition->id,
            'supplier_id' => $supplier->id,
            'exchange_rate_id' => $exchangeRate->id,
            'currency' => 'USD',
            'status' => $status,
            'submitted_at' => $submittedAt,
        ]);
    }

    private function createRate(float $rate, string $validFrom): ExchangeRate
    {
        return ExchangeRate::create([
            'currency' => 'USD',
            'rate_to_idr' => $rate,
            'valid_from' => $validFrom,
            'created_by' => $this->purchasing->id,
        ]);
    }

    private function nextPurchaseOrderNumber(): string
    {
        $this->purchaseOrderSequence++;

        return 'PO/PERF/'.str_pad((string) $this->purchaseOrderSequence, 3, '0', STR_PAD_LEFT);
    }

    private function vsBestRequest(int $length, string $search = '')
    {
        $parameters = [
            'date_from' => '2026-01',
            'date_to' => '2026-12',
            'draw' => 1,
            'start' => 0,
            'length' => $length,
            'columns' => $this->vsBestColumns(),
            'order' => [],
            'search' => ['value' => $search, 'regex' => 'false'],
        ];

        return $this->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->getJson(route('purchasing.comparison.vs-best.data').'?'.http_build_query($parameters));
    }

    private function vsBestColumns(): array
    {
        return $this->dataTableColumns([
            ['material_display', 'current_pr_items.material_name', true],
            ['current_price_display', 'current_price_idr', false],
            ['best_price_display', 'best_price_idr', false],
            ['diff_display', 'diff_idr_per_kg', false],
            ['potential_difference_display', 'potential_difference_idr', false],
            ['status_badge', 'diff_percent', false],
            ['action', 'action', false],
        ]);
    }

    private function dataTableColumns(array $definitions): array
    {
        return array_map(fn (array $definition): array => [
            'data' => $definition[0],
            'name' => $definition[1],
            'searchable' => $definition[2] ? 'true' : 'false',
            'orderable' => 'false',
            'search' => ['value' => '', 'regex' => 'false'],
        ], $definitions);
    }

    private function captureQueries(callable $callback): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $response = $callback();
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        return [$response, $queries];
    }

    private function countQueriesContaining(array $queries, string $needle): int
    {
        return collect($queries)->filter(
            fn (array $query): bool => str_contains(strtolower($query['query']), strtolower($needle)),
        )->count();
    }

    private function routeModelLookupCount(array $queries): int
    {
        return collect($queries)->filter(function (array $query): bool {
            $sql = strtolower($query['query']);
            $isRouteModelTable = str_contains($sql, 'from `purchase_requisitions`')
                || str_contains($sql, 'from `quotations`');
            $isPrimaryKeyLookup = str_contains($sql, '`.`id` = ?')
                && str_contains($sql, 'limit 1');

            return $isRouteModelTable && $isPrimaryKeyLookup;
        })->count();
    }
}
