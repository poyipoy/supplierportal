<?php

namespace Tests\Feature\Architecture;

use App\Models\Period;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\SupplierScope;
use App\Models\User;
use App\Support\PageRegistry;
use App\Support\PortalPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Support\ReloadsPortalRoutes;
use Tests\TestCase;

class InforPortalUrlParityTest extends TestCase
{
    use RefreshDatabase;
    use ReloadsPortalRoutes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reloadPortalRoutes([
            'portal_urls.long_hashids.enabled' => true,
            'portal_urls.prefix.enabled' => true,
            'portal_urls.page_registry.enabled' => true,
        ]);
    }

    public function test_all_three_infor_url_flags_operate_together_with_24_char_hashes_and_portal_prefix(): void
    {
        $purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true, 'account_status' => 'ACTIVE']);
        $supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true, 'account_status' => 'ACTIVE']);
        SupplierScope::firstOrCreate(['supplier_id' => $supplier->id, 'scope' => 'import']);

        $period = Period::create([
            'name' => 'Infor Parity Period',
            'month' => 11,
            'year' => 2026,
            'status' => 'open',
            'created_by' => $purchasing->id,
        ]);

        $pr = PurchaseRequisition::create([
            'period_id' => $period->id,
            'created_by' => $purchasing->id,
            'pr_number' => 'PR/INFOR/001',
            'status' => 'bidding',
        ]);

        $po = PurchaseOrder::create([
            'supplier_id' => $supplier->id,
            'currency' => 'USD',
            'po_number' => 'PO/INFOR/001',
            'status' => 'active',
            'created_by' => $purchasing->id,
        ]);

        $longHash = $po->getRouteKey();
        $this->assertSame(24, strlen($pr->getRouteKey()));
        $this->assertSame(24, strlen($longHash));
        $this->assertTrue(PortalPath::enabled());
        $this->assertSame('v2/ADASI_PRD', PortalPath::prefix());

        $prShowUrl = route('purchasing.requisitions.show', $pr, false);
        $this->assertStringStartsWith('/v2/ADASI_PRD/purchasing/requisitions/', $prShowUrl);

        $this->actingAs($purchasing)
            ->get($prShowUrl)
            ->assertOk();

        // Prefixed Smart Panel JSON endpoint with 24-char hash works cleanly
        $this->actingAs($purchasing)
            ->getJson(route('context-panel.show', ['entityType' => 'import-po', 'hash' => $longHash], false))
            ->assertOk()
            ->assertJsonPath('title', 'PO/INFOR/001');

        // Legacy 8-char hashid on legacy unprefixed path redirects 301 to /v2/ADASI_PRD/...,
        // then DecodeHashids canonicalizes 8-char hash -> 24-char hash (301) and serves 200 OK.
        config(['portal_urls.long_hashids.enabled' => false]);
        $legacyHash = $po->getRouteKey();
        $this->assertSame(8, strlen($legacyHash));

        config(['portal_urls.long_hashids.enabled' => true]);

        $this->actingAs($purchasing)
            ->get("/purchasing/purchase-orders/{$legacyHash}?tab=items")
            ->assertStatus(301)
            ->assertHeader('Location', "/v2/ADASI_PRD/purchasing/purchase-orders/{$legacyHash}?tab=items");

        $this->actingAs($purchasing)
            ->get("/v2/ADASI_PRD/purchasing/purchase-orders/{$legacyHash}?tab=items")
            ->assertStatus(301)
            ->assertHeader('Location', "/v2/ADASI_PRD/purchasing/purchase-orders/{$longHash}?tab=items");

        $this->actingAs($purchasing)
            ->get("/v2/ADASI_PRD/purchasing/purchase-orders/{$longHash}?tab=items")
            ->assertOk();
    }

    public function test_every_page_registry_entry_maps_to_a_registered_named_route_and_resolves_via_portal_code(): void
    {
        $codes = config('page_registry', []);
        $this->assertNotEmpty($codes);

        foreach ($codes as $code => $routeName) {
            $this->assertMatchesRegularExpression('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $code);
            $this->assertTrue(
                Route::has($routeName),
                "Page registry code [{$code}] points to unregistered route [{$routeName}]."
            );
        }

        $purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true, 'account_status' => 'ACTIVE']);
        $poIndexCode = array_search('purchasing.purchase-orders.index', $codes, true);
        $this->assertIsString($poIndexCode);

        $registryUrl = PageRegistry::url('purchasing.purchase-orders.index');
        $this->assertSame("/v2/ADASI_PRD/{$poIndexCode}", parse_url($registryUrl, PHP_URL_PATH));

        // Legacy unprefixed UUID bookmark redirects 301 to /v2/ADASI_PRD/{uuid}
        $this->actingAs($purchasing)
            ->get("/{$poIndexCode}")
            ->assertStatus(301)
            ->assertHeader('Location', "/v2/ADASI_PRD/{$poIndexCode}");

        // Prefixed UUID resolves to the active route via 302
        $this->actingAs($purchasing)
            ->get("/v2/ADASI_PRD/{$poIndexCode}")
            ->assertStatus(302)
            ->assertRedirect(route('purchasing.purchase-orders.index'));
    }
}
