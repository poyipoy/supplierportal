<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PriceComparisonServerTabsTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasing = User::factory()->create([
            'role' => 'purchasing',
            'is_active' => true,
        ]);
    }

    public function test_inter_supplier_full_page_renders_server_tabs_container(): void
    {
        $response = $this->actingAs($this->purchasing)
            ->get(route('purchasing.comparison.inter-supplier'));

        $response->assertOk();
        $response->assertSee('id="purchasingComparisonContainer"', false);
        $response->assertSee('id="purchasingComparisonContent"', false);
        $response->assertSee('data-server-tab', false);
        $response->assertSee('data-tab-name="inter-supplier"', false);
        $response->assertSee('data-tab-name="historical"', false);
        $response->assertSee('data-tab-name="vs-best"', false);
    }

    public function test_inter_supplier_ajax_returns_json_fragment(): void
    {
        $response = $this->actingAs($this->purchasing)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->get(route('purchasing.comparison.inter-supplier'));

        $response->assertOk();
        $response->assertJsonStructure(['html', 'tab', 'url']);
        $this->assertSame('inter-supplier', $response->json('tab'));
        $this->assertStringContainsString('interSupplierFilterForm', $response->json('html'));
        $this->assertStringContainsString('id="interSupplierConfig"', $response->json('html'));
    }

    public function test_historical_full_page_renders_server_tabs_container(): void
    {
        $response = $this->actingAs($this->purchasing)
            ->get(route('purchasing.comparison.historical'));

        $response->assertOk();
        $response->assertSee('id="purchasingComparisonContainer"', false);
        $response->assertSee('id="purchasingComparisonContent"', false);
        $response->assertSee('data-server-tab', false);
    }

    public function test_historical_ajax_returns_json_fragment(): void
    {
        $response = $this->actingAs($this->purchasing)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->get(route('purchasing.comparison.historical'));

        $response->assertOk();
        $response->assertJsonStructure(['html', 'tab', 'url']);
        $this->assertSame('historical', $response->json('tab'));
        $this->assertStringContainsString('historicalFilterForm', $response->json('html'));
        $this->assertStringContainsString('id="historicalConfig"', $response->json('html'));
    }

    public function test_historical_data_payload_request_preserves_json_contract(): void
    {
        $response = $this->actingAs($this->purchasing)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->get(route('purchasing.comparison.historical', ['view' => 'json']));

        $response->assertOk();
        $response->assertJsonStructure([
            'chartData',
            'tableData',
            'summary',
            'pagination',
            'periodView',
            'range',
            'rangeOptions',
            'materialName',
            'supplierName',
        ]);
        $this->assertNull($response->json('html'));
    }

    public function test_vs_best_full_page_renders_server_tabs_container(): void
    {
        $response = $this->actingAs($this->purchasing)
            ->get(route('purchasing.comparison.vs-best'));

        $response->assertOk();
        $response->assertSee('id="purchasingComparisonContainer"', false);
        $response->assertSee('id="purchasingComparisonContent"', false);
        $response->assertSee('id="vsBestTable"', false);
        $response->assertSee('id="vsBestMonthRange"', false);
    }

    public function test_vs_best_ajax_returns_json_fragment(): void
    {
        $response = $this->actingAs($this->purchasing)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->get(route('purchasing.comparison.vs-best'));

        $response->assertOk();
        $response->assertJsonStructure(['html', 'tab', 'url']);
        $this->assertSame('vs-best', $response->json('tab'));
        $this->assertStringContainsString('id="vsBestTable"', $response->json('html'));
        $this->assertStringContainsString('id="vsBestConfig"', $response->json('html'));
        $this->assertStringContainsString('id="vsBestMonthRange"', $response->json('html'));
        $this->assertStringContainsString('data-adasi-date-range', $response->json('html'));
    }

    public function test_vs_best_data_endpoint_supports_unfiltered_date_range(): void
    {
        $response = $this->actingAs($this->purchasing)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->getJson(route('purchasing.comparison.vs-best.data', [
                'draw' => 1,
                'start' => 0,
                'length' => 10,
            ]));

        $response->assertOk();
        $response->assertJsonStructure([
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
        ]);
        $this->assertSame(1, $response->json('draw'));
        $this->assertIsInt($response->json('recordsTotal'));
    }

    public function test_vs_best_caches_unfiltered_summary_and_prepopulates_view(): void
    {
        Cache::flush();

        // 1. Initial full page load with cold cache gets empty summary fallback
        $coldResponse = $this->actingAs($this->purchasing)
            ->get(route('purchasing.comparison.vs-best'));
        $coldResponse->assertOk();
        $this->assertSame(0, $coldResponse->viewData('summary')['total_rows']);

        // 2. Data endpoint warms the cache for unfiltered default view
        $dataResponse = $this->actingAs($this->purchasing)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->getJson(route('purchasing.comparison.vs-best.data', [
                'draw' => 1,
                'start' => 0,
                'length' => 10,
            ]));
        $dataResponse->assertOk();

        $this->assertTrue(Cache::has('purchasing:vs_best_summary_unfiltered:v1'));
        $cachedSummary = Cache::get('purchasing:vs_best_summary_unfiltered:v1');
        $this->assertIsArray($cachedSummary);
        $this->assertArrayHasKey('total_rows', $cachedSummary);
        $this->assertArrayHasKey('competitive_count', $cachedSummary);

        // 3. Subsequent full page load pre-populates with cached summary (eliminates zero-flicker)
        $this->flushHeaders();
        $warmResponse = $this->actingAs($this->purchasing)
            ->get(route('purchasing.comparison.vs-best'));
        $warmResponse->assertOk();
        $this->assertSame($cachedSummary, $warmResponse->viewData('summary'));
    }
}
