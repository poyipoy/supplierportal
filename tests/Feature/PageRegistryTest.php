<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\QuickAccessService;
use App\Support\PageRegistry;
use App\Support\PurchasingNavigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Support\ReloadsPortalRoutes;
use Tests\TestCase;

class PageRegistryTest extends TestCase
{
    use RefreshDatabase;
    use ReloadsPortalRoutes;

    public function test_registry_has_stable_unique_v4_ids_and_only_existing_get_pages_without_required_parameters(): void
    {
        $pages = config('page_registry');
        $this->assertIsArray($pages);
        $this->assertGreaterThan(50, count($pages));
        $this->assertSame(count($pages), count(array_unique(array_values($pages))));
        foreach ($pages as $uuid => $name) {
            $this->assertMatchesRegularExpression('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $uuid);
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $this->assertContains('GET', $route->methods());
            $this->assertDoesNotMatchRegularExpression('/\{[^}?]+\}/', $route->uri(), $name);
        }

        $source = file_get_contents(resource_path('views/partials/sidebar.blade.php'));
        preg_match_all('/PageRegistry::url\(\x27([^\x27]+)\x27/', $source, $matches);
        $this->assertNotEmpty($matches[1]);
        foreach ($matches[1] as $name) {
            $this->assertContains($name, $pages);
        }
        $check = function (array $items) use (&$check, $pages): void {
            foreach ($items as $item) {
                if (isset($item['route'])) {
                    if (Route::has($item['route'])) {
                        $this->assertContains($item['route'], $pages);
                    }
                } else {
                    $check($item);
                }
            }
        };
        $check(config('quick_access.audiences'));
    }

    public function test_flag_off_preserves_direct_urls_and_purchasing_filter_memory(): void
    {
        $this->assertFalse(Route::has('portal.page'));
        $this->assertSame(route('finance.invoices.index'), PageRegistry::url('finance.invoices.index'));
        $stored = route('purchasing.purchase-orders.index', ['status' => 'active']);
        PurchasingNavigation::rememberListUrl('purchasing.purchase-orders.index', $stored);
        $this->assertSame($stored, PageRegistry::url('purchasing.purchase-orders.index', PurchasingNavigation::listUrl('purchasing.purchase-orders.index')));
    }

    public function test_menu_uuid_redirects_to_real_routes_with_or_without_prefix(): void
    {
        $user = User::factory()->create(['role' => 'purchasing']);
        $uuid = array_search('purchasing.purchase-orders.index', config('page_registry'), true);
        foreach ([false, true] as $prefix) {
            $this->reloadPortalRoutes(['portal_urls.page_registry.enabled' => true, 'portal_urls.prefix.enabled' => $prefix]);
            $url = PageRegistry::url('purchasing.purchase-orders.index');
            $this->assertSame(($prefix ? '/v2/ADASI_PRD' : '').'/'.$uuid, parse_url($url, PHP_URL_PATH));
            $this->actingAs($user)->get($url)->assertStatus(302)->assertRedirect(route('purchasing.purchase-orders.index'));
            $this->assertSame(route('profile.customization'), PageRegistry::url('profile.customization'));
        }
    }

    public function test_registry_does_not_bypass_guest_role_or_supplier_scope_checks(): void
    {
        $this->reloadPortalRoutes(['portal_urls.page_registry.enabled' => true, 'portal_urls.prefix.enabled' => true]);
        $target = PageRegistry::url('finance.invoices.index');
        $this->get($target)->assertRedirect(route('login'));
        $user = User::factory()->create(['role' => 'qc']);
        $destination = $this->actingAs($user)->get($target)->assertStatus(302)->headers->get('Location');
        $this->get($destination)->assertForbidden();

        $supplier = User::factory()->create();
        $this->actingAs($supplier)->get(PageRegistry::url('local-supplier.invoices.index'))->assertStatus(302);
        $this->get(route('local-supplier.invoices.index'))->assertForbidden();
        $this->get(route('portal.page', '12345678-1234-4123-8123-123456789abc'))->assertNotFound();
        $this->get('/v2/ADASI_PRD/invalid-uuid')->assertNotFound();
    }

    public function test_rendered_sidebar_and_quick_access_use_uuid_links_with_the_flag_on(): void
    {
        $this->reloadPortalRoutes(['portal_urls.page_registry.enabled' => true, 'portal_urls.prefix.enabled' => true]);
        $user = User::factory()->create(['role' => 'purchasing']);
        $this->actingAs($user)->get(route('profile.edit'))->assertOk()
            ->assertSee('href="'.PageRegistry::url('purchasing.purchase-orders.index').'"', false);
        $items = app(QuickAccessService::class)->availableFor($user);
        $this->assertSame(PageRegistry::url('purchasing.purchase-orders.index'), $items['purchasing.orders']['url']);
    }
}
