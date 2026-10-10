<?php

namespace Tests\Feature\SupplierAudit;

use App\Models\User;
use App\Support\BusinessTime;

class SupplierAuditServerTabsTest extends SupplierAuditTestCase
{
    private const FRAGMENT_HEADERS = [
        'X-Adasi-Server-Tabs' => '1',
        'X-Requested-With' => 'XMLHttpRequest',
        'Accept' => 'application/json',
    ];

    public function test_full_page_exposes_the_server_tabs_contract(): void
    {
        $this->assign($this->supplier());

        $this->actingAs($this->purchasing)->get(route('purchasing.supplier-audits.index'))
            ->assertOk()
            ->assertSee('id="supplierAuditQueueContainer"', false)
            ->assertSee('data-server-tabs-container', false)
            ->assertSee('data-server-tabs-nav', false)
            ->assertSee('data-server-tabs-content', false)
            ->assertSee('data-server-tabs-form', false)
            ->assertSee('data-tab-name="review"', false)
            ->assertSee('data-tab-name="late"', false)
            ->assertSee('data-server-tab', false);
    }

    public function test_fragment_request_returns_the_queue_table_and_a_fresh_nav(): void
    {
        $late = $this->assign($this->supplier(), BusinessTime::today()->subDays(3)->toDateString());
        $onTime = $this->assign($this->supplier(), BusinessTime::today()->addDays(5)->toDateString());
        $url = route('purchasing.supplier-audits.index', ['queue' => 'late']);

        $response = $this->actingAs($this->purchasing)->withHeaders(self::FRAGMENT_HEADERS)->get($url);

        $response->assertOk()->assertJsonStructure(['html', 'nav', 'tab', 'url']);
        $this->assertSame('late', $response->json('tab'));
        $this->assertSame($url, $response->json('url'));
        $this->assertStringContainsString(route('purchasing.supplier-audits.show', $late), $response->json('html'));
        $this->assertStringNotContainsString(route('purchasing.supplier-audits.show', $onTime), $response->json('html'));
        $this->assertStringContainsString('data-server-tabs-form', $response->json('html'));
        $this->assertStringNotContainsString('<html', $response->json('html'));
        $this->assertMatchesRegularExpression('/aria-current="page"\s+data-queue-tab="late"/', $response->json('nav'));
        $this->assertStringContainsString('data-queue-count="waiting">2<', $response->json('nav'));
        $this->assertStringContainsString('data-queue-count="late">1<', $response->json('nav'));
    }

    public function test_nav_links_carry_the_active_filters_so_they_stay_correct_after_an_ajax_filter(): void
    {
        $supplier = $this->supplier();
        $this->assign($supplier);

        $response = $this->actingAs($this->purchasing)->withHeaders(self::FRAGMENT_HEADERS)
            ->get(route('purchasing.supplier-audits.index', ['queue' => 'all', 'supplier' => $supplier->hash]));

        $response->assertOk();
        $this->assertSame(5, substr_count($response->json('nav'), 'supplier='.$supplier->hash), 'every tab link keeps the supplier filter');
        $this->assertStringContainsString('supplier='.$supplier->hash, $response->json('url'));
    }

    public function test_a_bare_xhr_without_the_header_still_gets_the_full_page(): void
    {
        $this->actingAs($this->purchasing)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->get(route('purchasing.supplier-audits.index'))
            ->assertOk()
            ->assertViewIs('purchasing.supplier-audits.index');
    }

    public function test_fragment_requests_are_authorized_like_the_page(): void
    {
        $finance = User::factory()->create(['role' => 'finance']);
        $supplier = $this->supplier();

        $this->actingAs($finance)->withHeaders(self::FRAGMENT_HEADERS)
            ->get(route('purchasing.supplier-audits.index'))->assertForbidden();
        $this->actingAs($supplier)->withHeaders(self::FRAGMENT_HEADERS)
            ->get(route('purchasing.supplier-audits.index'))->assertForbidden();

        auth()->logout();
        $this->withHeaders(self::FRAGMENT_HEADERS)
            ->get(route('purchasing.supplier-audits.index'))->assertUnauthorized();
    }

    public function test_an_unknown_queue_is_rejected_for_fragments_too(): void
    {
        $this->actingAs($this->purchasing)->withHeaders(self::FRAGMENT_HEADERS)
            ->get(route('purchasing.supplier-audits.index', ['queue' => 'nonsense']))
            ->assertUnprocessable();
    }
}
