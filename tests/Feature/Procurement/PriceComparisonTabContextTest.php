<?php

namespace Tests\Feature\Procurement;

use App\Models\Quotation;
use App\Models\User;
use App\Support\HashidCodec;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gate 1 C6 / AC5 navigation cases (server side). The active PR is the current URL's
 * pr_id; remembered session URLs never re-activate a PR on Historical or Vs Best; Clear PR
 * strips only the comparison PR context. Browser-only steps (N5, N7) are covered by the JS
 * state test and the manual browser QA.
 */
class PriceComparisonTabContextTest extends TestCase
{
    use QuotationHistoryFixtures;
    use RefreshDatabase;

    private User $sa;

    private User $sb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpQuotationHistory();
        $this->sa = $this->supplierNamed('S-A');
        $this->sb = $this->supplierNamed('S-B');
    }

    public function test_n1_first_historical_click_from_inter_supplier_carries_the_pr(): void
    {
        $prA = $this->prWithQuotes();

        $html = $this->page('purchasing.comparison.inter-supplier', ['pr_id' => $prA->getRouteKey(), 'currency' => 'USD']);

        $href = $this->tabHref($html, 'historical');
        $this->assertSame($prA->getRouteKey(), $this->query($href)['pr_id'] ?? null);
        $this->assertSame('USD', $this->query($href)['currency'] ?? null);

        // Following that link renders PR mode with every item and supplier, chart Current vs Previous.
        $payload = $this->actingAs($this->purchasingUser)->getJson($href.'&view=json')->assertOk()->json('quotationHistory');
        $this->assertCount(4, $payload['rows']);
        $this->assertSame('current_previous', $payload['chart']);
        $this->assertSame(HashidCodec::encode($prA->items[0]->id), $payload['chart_item']['hash']);
    }

    public function test_n2_n10_clear_pr_strips_only_the_comparison_pr_context(): void
    {
        $prA = $this->prWithQuotes();
        $historical = route('purchasing.comparison.historical', ['pr_id' => $prA->getRouteKey(), 'pr_item' => 'x', 'chart' => 'quote_trend', 'currency' => 'USD']);
        $this->withSession([
            'purchasing.index_urls.purchasing.comparison.inter-supplier' => route('purchasing.comparison.inter-supplier', ['pr_id' => $prA->getRouteKey(), 'currency' => 'USD']),
            'purchasing.index_urls.purchasing.comparison.vs-best' => route('purchasing.comparison.vs-best', ['pr_id' => $prA->getRouteKey(), 'date_from' => '2026-01']),
            'purchasing.index_urls.purchasing.purchase-orders.index' => route('purchasing.purchase-orders.index', ['status' => 'active']),
        ]);

        $this->actingAs($this->purchasingUser)
            ->get(route('purchasing.comparison.historical', ['currency' => 'USD', 'clear_pr' => 1]))
            ->assertRedirect(route('purchasing.comparison.historical', ['currency' => 'USD']));

        $this->assertArrayNotHasKey('pr_id', $this->query(session('purchasing.index_urls.purchasing.comparison.inter-supplier')));
        $this->assertSame(['date_from' => '2026-01'], $this->query(session('purchasing.index_urls.purchasing.comparison.vs-best')));
        $this->assertSame(['currency' => 'USD'], $this->query(session('purchasing.index_urls.purchasing.comparison.historical')));
        $this->assertSame(['status' => 'active'], $this->query(session('purchasing.index_urls.purchasing.purchase-orders.index')), 'Unrelated navigation is untouched.');
        unset($historical);
    }

    public function test_n3_after_clear_pr_inter_supplier_and_historical_have_no_pr(): void
    {
        $prA = $this->prWithQuotes();
        $this->page('purchasing.comparison.historical', ['pr_id' => $prA->getRouteKey()]);
        $this->actingAs($this->purchasingUser)->get(route('purchasing.comparison.historical', ['clear_pr' => 1]))->assertRedirect();

        $html = $this->page('purchasing.comparison.historical', []);

        $this->assertArrayNotHasKey('pr_id', $this->query($this->tabHref($html, 'inter-supplier')));
        $this->assertArrayNotHasKey('pr_id', $this->query($this->tabHref($html, 'historical')));
        $this->assertStringNotContainsString('data-historical-pr-context', $html, 'Legacy Historical mode.');
    }

    public function test_n4_switching_pr_a_to_pr_b_carries_b(): void
    {
        $prA = $this->prWithQuotes();
        $prB = $this->prWithQuotes();
        $this->page('purchasing.comparison.inter-supplier', ['pr_id' => $prA->getRouteKey()]);

        $html = $this->page('purchasing.comparison.inter-supplier', ['pr_id' => $prB->getRouteKey()]);

        $this->assertSame($prB->getRouteKey(), $this->query($this->tabHref($html, 'historical'))['pr_id']);
        $this->assertSame($prB->getRouteKey(), $this->query($this->tabHref($html, 'vs-best'))['pr_id']);
    }

    public function test_n4_server_tab_fragment_keeps_the_pr_in_its_url(): void
    {
        $prA = $this->prWithQuotes();

        $response = $this->actingAs($this->purchasingUser)
            ->getJson(route('purchasing.comparison.historical', ['pr_id' => $prA->getRouteKey()]), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk();

        $this->assertSame('historical', $response->json('tab'));
        $this->assertSame($prA->getRouteKey(), $this->query($response->json('url'))['pr_id']);
        $this->assertStringContainsString('historicalPrConfig', $response->json('html'));
    }

    public function test_n6_refresh_and_direct_urls(): void
    {
        $prA = $this->prWithQuotes();

        $html = $this->page('purchasing.comparison.historical', ['pr_id' => $prA->getRouteKey(), 'chart' => 'quote_trend']);

        $this->assertStringContainsString('data-historical-pr-context', $html);
        $this->assertStringContainsString('"chart":"quote_trend"', $html);
        $this->actingAs($this->purchasingUser)->get(route('purchasing.comparison.historical', ['pr_id' => (string) $prA->id]))->assertNotFound();
    }

    public function test_n8_a_remembered_pr_never_reactivates_on_historical_or_vs_best(): void
    {
        $prA = $this->prWithQuotes();
        $this->withSession([
            'purchasing.index_urls.purchasing.comparison.historical' => route('purchasing.comparison.historical', ['pr_id' => $prA->getRouteKey()]),
            'purchasing.index_urls.purchasing.comparison.vs-best' => route('purchasing.comparison.vs-best', ['pr_id' => $prA->getRouteKey()]),
        ]);

        $html = $this->page('purchasing.comparison.vs-best', []);

        $this->assertArrayNotHasKey('pr_id', $this->query($this->tabHref($html, 'historical')));
    }

    public function test_n9_vs_best_and_its_reset_keep_the_active_pr(): void
    {
        $prA = $this->prWithQuotes();

        $html = $this->page('purchasing.comparison.vs-best', ['pr_id' => $prA->getRouteKey(), 'currency' => 'USD']);

        $this->assertSame($prA->getRouteKey(), $this->query($this->tabHref($html, 'historical'))['pr_id']);
        $this->assertMatchesRegularExpression('/href="[^"]*vs-best\?[^"]*pr_id='.preg_quote($prA->getRouteKey(), '/').'[^"]*"[^>]*data-server-tab/', $html);
    }

    public function test_n11_explicit_material_focus_selects_the_chart_item(): void
    {
        $pr = $this->prAt('2026-10-01', [self::skd(), self::round('S45C', 60)]);
        $this->quote($pr, $this->sa, '2026-10-15', 'USD', 'USD', [3.80, 2.10]);

        $focused = $this->payload($pr, ['material_name' => 'S45C', 'shape' => 'Round']);
        $this->assertSame(HashidCodec::encode($pr->items[1]->id), $focused['quotationHistory']['chart_item']['hash']);
        $this->assertSame([], $focused['quotationHistory']['notices']);

        $missing = $this->payload($pr, ['material_name' => 'SS400', 'shape' => 'Flat']);
        $this->assertSame(HashidCodec::encode($pr->items[0]->id), $missing['quotationHistory']['chart_item']['hash']);
        $this->assertSame([__('purchasing.quotation_history.notice_material_not_in_pr')], $missing['quotationHistory']['notices']);
    }

    public function test_n12_legacy_historical_keeps_inter_supplier_remembered_pr(): void
    {
        $prA = $this->prWithQuotes();
        $this->page('purchasing.comparison.inter-supplier', ['pr_id' => $prA->getRouteKey()]);

        $html = $this->page('purchasing.comparison.historical', []);

        $this->assertSame($prA->getRouteKey(), $this->query($this->tabHref($html, 'inter-supplier'))['pr_id']);
        $this->assertArrayNotHasKey('pr_id', $this->query($this->tabHref($html, 'historical')));
        $this->assertSame($prA->getRouteKey(), $this->query(session('purchasing.index_urls.purchasing.comparison.inter-supplier'))['pr_id']);
    }

    private function prWithQuotes()
    {
        $pr = $this->prAt('2026-10-01', [self::skd(), self::round('S45C', 60)]);
        $this->quote($pr, $this->sa, '2026-10-15', 'USD', 'USD', [3.80, 2.10]);
        $this->quote($pr, $this->sb, '2026-10-15', 'USD', 'USD', [3.70, 2.00], Quotation::STATUS_ACCEPTED);

        return $pr;
    }

    private function page(string $route, array $query): string
    {
        return $this->actingAs($this->purchasingUser)->get(route($route, $query))->assertOk()->getContent();
    }

    private function payload($pr, array $query): array
    {
        return $this->actingAs($this->purchasingUser)
            ->getJson(route('purchasing.comparison.historical', ['pr_id' => $pr->getRouteKey(), 'view' => 'json', ...$query]))
            ->assertOk()
            ->json();
    }

    private function tabHref(string $html, string $tab): string
    {
        $this->assertSame(1, preg_match('/<a\s[^>]*href="([^"]+)"[^>]*data-tab-name="'.$tab.'"/s', $html, $match), "Tab {$tab} not found.");

        return html_entity_decode($match[1]);
    }

    private function query(?string $url): array
    {
        parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $query);

        return $query;
    }
}
