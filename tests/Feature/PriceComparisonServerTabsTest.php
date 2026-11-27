<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\BusinessTime;
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
        // #purchasingComparisonContent is a CSS Grid item of #purchasingComparisonContainer
        // (tw:grid); without tw:min-w-0 the grid item's default min-width: auto lets the wide
        // comparison-matrix-table (width: max-content) blow out the whole page horizontally
        // instead of scrolling inside its own <x-ui.data-table> wrapper.
        $response->assertSee('id="purchasingComparisonContent" class="tw:min-w-0 tw:max-w-full"', false);
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
        $this->assertStringContainsString('id="comparisonPrSearch"', $response->json('html'));
        $this->assertStringContainsString('aria-describedby="comparisonPrHelp"', $response->json('html'));
        $this->assertStringContainsString('tw:pointer-events-none tw:absolute tw:start-3', $response->json('html'));
        $this->assertStringContainsString('tw:md:grid-cols-[minmax(0,1fr)_auto] tw:md:items-center', $response->json('html'));
        $this->assertStringContainsString('id="interSupplierConfig"', $response->json('html'));
    }

    public function test_historical_full_page_renders_server_tabs_container(): void
    {
        $response = $this->actingAs($this->purchasing)
            ->get(route('purchasing.comparison.historical'));

        $response->assertOk();
        $response->assertSee('id="purchasingComparisonContainer"', false);
        $response->assertSee('id="purchasingComparisonContent" class="tw:min-w-0 tw:max-w-full"', false);
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
        $response->assertSee('id="purchasingComparisonContent" class="tw:min-w-0 tw:max-w-full"', false);
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

        // Mission 07: the cached summary is keyed by evaluation date and currency (Q9).
        // Gate 2 §3-C bumped v2 → v3 so summaries cached under the old "best" eligibility are never served.
        $cacheKey = 'purchasing:vs_best_summary_unfiltered:v3:'.BusinessTime::today()->toDateString().':IDR';
        $this->assertTrue(Cache::has($cacheKey));
        $cachedSummary = Cache::get($cacheKey);
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

    public function test_historical_view_exposes_accessible_live_status_region_and_prevents_synthetic_submit(): void
    {
        $response = $this->actingAs($this->purchasing)
            ->get(route('purchasing.comparison.historical'));

        $response->assertOk();
        $response->assertSee('id="historicalResultsStatus" class="tw:sr-only" aria-live="polite"', false);
        $response->assertSee('id="historicalResults" class="tw:grid tw:gap-4" aria-busy="false"', false);
        $response->assertSee('id="historicalChartAxisSwitch"', false);
        $response->assertSee('data-historical-axis-mode="period"', false);
        $response->assertSee('data-historical-axis-mode="material"', false);
        $response->assertSee('id="historicalChartAxisCapNote"', false);
        $response->assertDontSee('id="historicalResults" class="tw:grid tw:gap-4" aria-live="polite"', false);

        $fragment = $this->actingAs($this->purchasing)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->get(route('purchasing.comparison.historical'));

        $fragment->assertOk();
        $this->assertStringContainsString('id="historicalResultsStatus" class="tw:sr-only" aria-live="polite"', $fragment->json('html'));
        $this->assertStringContainsString('aria-describedby="historicalKeyHint"', $fragment->json('html'));
        $this->assertStringContainsString('id="historicalKeyHint"', $fragment->json('html'));

        $scripts = file_get_contents(resource_path('views/purchasing/comparison/_scripts.blade.php'));
        $this->assertIsString($scripts);
        $this->assertStringNotContainsString(
            "filterForm.dispatchEvent(new Event('submit'",
            $scripts,
            'Historical filters without a selected material must sync URL state instead of dispatching an unhandled synthetic submit that triggers AdasiLoader'
        );
    }

    public function test_comparison_tabs_include_embed_param_when_rendered_inside_workspace_shell(): void
    {
        config(['ui.workspace_shell.enabled' => true]);

        $response = $this->actingAs($this->purchasing)
            ->get(route('purchasing.comparison.inter-supplier', [
                '_embed' => '1',
                'currency' => 'USD',
            ]));

        $response->assertOk();
        $response->assertSee('data-server-tabs-prefetch="false"', false);

        $html = $response->getContent();
        $this->assertIsString($html);
        $this->assertSame(1, preg_match_all('/<a\b(?=[^>]*\bdata-server-tab\b)(?=[^>]*\bdata-tab-name="historical")(?=[^>]*\bhref="([^"]+)")[^>]*>/', $html, $matches));
        $historicalHref = html_entity_decode($matches[1][0], ENT_QUOTES);
        $query = [];
        parse_str((string) parse_url($historicalHref, PHP_URL_QUERY), $query);

        $this->assertSame('1', $query['_embed'] ?? null, 'SSR comparison tab href inside _embed=1 frame must keep _embed=1 before JS/CDN boot');
        $this->assertSame('USD', $query['currency'] ?? null);
    }

    public function test_comparison_controllers_use_server_tabs_response_and_strip_embed_from_json_url(): void
    {
        foreach ([
            'inter-supplier' => 'purchasing.comparison.inter-supplier',
            'historical' => 'purchasing.comparison.historical',
            'vs-best' => 'purchasing.comparison.vs-best',
        ] as $expectedTab => $routeName) {
            $response = $this->actingAs($this->purchasing)
                ->withHeaders([
                    'X-Requested-With' => 'XMLHttpRequest',
                    'X-Adasi-Server-Tabs' => '1',
                ])
                ->get(route($routeName, ['_embed' => '1', 'currency' => 'USD']));

            $response->assertOk();
            $this->assertSame($expectedTab, $response->json('tab'));
            $resolvedUrl = (string) $response->json('url');
            $this->assertStringContainsString('currency=USD', $resolvedUrl);
            $this->assertStringNotContainsString('_embed=', $resolvedUrl, "ServerTabsResponse on {$routeName} must strip _embed=1 from history url");
        }
    }

    public function test_vs_best_renders_integrated_accessible_table_toolbar_without_default_datatables_chrome(): void
    {
        $response = $this->actingAs($this->purchasing)
            ->get(route('purchasing.comparison.vs-best'));

        $response->assertOk();
        $response->assertSee('<search class="tw:block tw:w-full"', false);
        $response->assertSee('class="ui-vs-best-filter-grid"', false);
        $response->assertSee('id="vsBestTableSearch"', false);
        $response->assertSee('id="vsBestTableSearchClear"', false);
        $response->assertSee('ui-date-range-picker--single-line', false);
        $response->assertSee('enterkeyhint="search"', false);
        $response->assertDontSee('id="vsBestPageLength"', false);

        $fragment = $this->actingAs($this->purchasing)
            ->withHeaders([
                'X-Requested-With' => 'XMLHttpRequest',
                'X-Adasi-Server-Tabs' => '1',
            ])
            ->get(route('purchasing.comparison.vs-best', ['search' => 'SKD11']));

        $fragment->assertOk();
        $html = (string) $fragment->json('html');
        $this->assertStringContainsString('class="ui-vs-best-filter-grid"', $html);
        $this->assertStringContainsString('id="vsBestTableSearch"', $html);
        $this->assertStringContainsString('value="SKD11"', $html);
        $this->assertStringNotContainsString('id="vsBestPageLength"', $html);

        // Array query string (?search[value]=...) must not cause Array-to-string errors.
        $malformedSearch = $this->actingAs($this->purchasing)
            ->get(route('purchasing.comparison.vs-best', ['search' => ['value' => 'SKD11']]));
        $malformedSearch->assertOk();

        $scripts = file_get_contents(resource_path('views/purchasing/comparison/_scripts.blade.php'));
        $this->assertIsString($scripts);
        $this->assertStringNotContainsString("topStart: 'pageLength'", $scripts);
        $this->assertStringNotContainsString("topEnd: 'search'", $scripts);
        $this->assertStringContainsString('topStart: null', $scripts);
        $this->assertStringContainsString('topEnd: null', $scripts);
    }
}
