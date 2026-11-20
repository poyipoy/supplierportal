<?php

namespace Tests\Feature\Architecture;

use App\Models\PurchaseOrder;
use App\Models\SupplierScope;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Support\HashidCodec;
use App\Support\PageRegistry;
use App\Support\PortalContextCodec;
use App\Support\WorkspaceShell;
use Hashids\Hashids;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Support\ReloadsPortalRoutes;
use Tests\TestCase;

class InforContextUrlSecurityTest extends TestCase
{
    use RefreshDatabase;
    use ReloadsPortalRoutes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reloadPortalRoutes([
            'portal_urls.context_urls.enabled' => true,
            'portal_urls.long_hashids.enabled' => true,
            'portal_urls.long_hashids.length' => 24,
            'portal_urls.prefix.enabled' => true,
            'portal_urls.prefix.value' => 'v2/ADASI_PRD',
            'ui.workspace_shell.enabled' => true,
        ]);
    }

    public function test_sec01_workspace_config_exports_context_sessions_only_for_actor_role_and_scope(): void
    {
        $purchasing = User::factory()->create(['role' => 'purchasing']);
        $supplier = User::factory()->create(['role' => 'supplier']);

        $purchasingConfig = $this->extractWorkspaceConfig(
            $this->actingAs($purchasing)->get(route('purchasing.dashboard'))->assertOk()->getContent()
        );
        $this->assertTrue($purchasingConfig['contextLinks']['enabled']);
        $purRoutes = array_column($purchasingConfig['contextLinks']['sessionMap'], 'route');
        $this->assertContains('purchasing.purchase-orders.index', $purRoutes);
        $this->assertNotContains('admin.users.index', $purRoutes);
        $this->assertNotContains('finance.invoices.index', $purRoutes);
        $this->assertNotContains('supplier.purchase-orders.index', $purRoutes);

        $supplierConfig = $this->extractWorkspaceConfig(
            $this->actingAs($supplier)->get(route('supplier.dashboard'))->assertOk()->getContent()
        );
        $supRoutes = array_column($supplierConfig['contextLinks']['sessionMap'], 'route');
        $this->assertContains('supplier.purchase-orders.index', $supRoutes);
        $this->assertNotContains('purchasing.purchase-orders.index', $supRoutes);
        $this->assertNotContains('local-supplier.invoices.index', $supRoutes);
    }

    public function test_sec02_cross_role_and_cross_scope_context_urls_return_404_without_location_header(): void
    {
        $qc = User::factory()->create(['role' => 'qc']);
        $importSupplier = User::factory()->create(['role' => 'supplier']);

        $financeUrl = PageRegistry::contextUrl('finance.invoices.index');
        $this->assertNotNull($financeUrl);

        $this->actingAs($qc)
            ->get($financeUrl)
            ->assertNotFound()
            ->assertHeaderMissing('Location');

        $localUrl = PageRegistry::contextUrl('local-supplier.invoices.index');
        $this->assertNotNull($localUrl);

        $this->actingAs($importSupplier)
            ->get($localUrl)
            ->assertNotFound()
            ->assertHeaderMissing('Location');
    }

    public function test_sec03_path_injection_and_disallowed_or_non_shell_actions_are_blocked(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $appUrlBase = '/v2/ADASI_PRD/'.config('portal_urls.context_urls.app_uuid');

        foreach (['//evil.test', '/admin/users', 'https://attacker.example'] as $evilPath) {
            $raw = base64_encode('scv2'.json_encode([
                'description' => 'Evil',
                'application' => 'erp',
                'type' => 'session',
                'action' => 'admdash000m000',
                'path' => $evilPath,
                'argument' => '',
            ], JSON_UNESCAPED_SLASHES));

            $this->actingAs($admin)
                ->get($appUrlBase.'?favoriteContext='.rawurlencode($raw).'&LogicalId='.rawurlencode('lid://adasi.portal.admin'))
                ->assertStatus(400);
        }

        foreach (['logout', 'login', 'passport.authorizations.authorize', 'nonexistent00m000'] as $badAction) {
            $raw = PortalContextCodec::encodeScv2Payload('Bad', $badAction, '');
            $this->actingAs($admin)
                ->get($appUrlBase.'?favoriteContext='.rawurlencode($raw).'&LogicalId='.rawurlencode('lid://adasi.portal.admin'))
                ->assertNotFound();
        }
    }

    public function test_sec04_raw_int_ids_cross_tenant_idor_and_dual_scope_logical_id_bypass_are_blocked(): void
    {
        $purchasing = User::factory()->create(['role' => 'purchasing']);
        $supplierA = User::factory()->create(['role' => 'supplier']);
        $supplierB = User::factory()->create(['role' => 'supplier']);

        $poB = PurchaseOrder::create([
            'po_number' => 'PO-IDOR-001',
            'supplier_id' => $supplierB->id,
            'currency' => 'USD',
            'status' => 'active',
            'created_by' => $purchasing->id,
        ]);

        // Raw numeric ID -> 404 immediately
        $appUrlBase = '/v2/ADASI_PRD/'.config('portal_urls.context_urls.app_uuid');
        $rawIntPayload = PortalContextCodec::encodeScv2Payload('PO', 'supords410m000', 'id='.$poB->id);
        $this->actingAs($supplierA)
            ->get($appUrlBase.'?favoriteContext='.rawurlencode($rawIntPayload).'&LogicalId='.rawurlencode('lid://adasi.portal.supplier'))
            ->assertNotFound();

        // Cross-tenant hashed PO ID -> shell points iframe to _embed URL, and iframe enforces Model Policy (403)
        $otherSupplierContextUrl = PageRegistry::contextUrl('supplier.purchase-orders.show', ['id' => $poB->getRouteKey()]);
        $this->assertNotNull($otherSupplierContextUrl);
        $this->actingAs($supplierA)
            ->get(route('supplier.purchase-orders.show', ['id' => $poB->getRouteKey(), '_embed' => 1]))
            ->assertForbidden();

        // Tampered LogicalId must never mutate supplier_context
        $tamperedLogicalUrl = $appUrlBase
            .'?favoriteContext='.rawurlencode(PortalContextCodec::encodeScv2Payload('PO List', 'supords000m000', ''))
            .'&LogicalId='.rawurlencode('lid://adasi.portal.local_supplier');

        $this->actingAs($supplierA)
            ->withSession(['supplier_context' => 'import'])
            ->get($tamperedLogicalUrl)
            ->assertStatus(400);
        $this->assertSame('import', session('supplier_context'));

        // Dual-scope supplier opening an authorized local-supplier context URL switches scope strictly from route middleware
        $dualSupplier = User::factory()->create(['role' => 'supplier']);
        SupplierScope::firstOrCreate(['supplier_id' => $dualSupplier->id, 'scope' => 'local']);
        $dualSupplier->forgetSupplierScopes();

        $localContextUrl = PageRegistry::contextUrl('local-supplier.invoices.index');
        $this->actingAs($dualSupplier)
            ->withSession(['supplier_context' => 'import'])
            ->get($localContextUrl)
            ->assertOk();
        $this->assertSame('local', session('supplier_context'));
    }

    public function test_sec05_untrusted_description_never_renders_or_spoofs_shell_title(): void
    {
        $purchasing = User::factory()->create(['role' => 'purchasing']);
        $appUrlBase = '/v2/ADASI_PRD/'.config('portal_urls.context_urls.app_uuid');

        $spoofed = PortalContextCodec::encodeScv2Payload('<img src=x onerror=alert(1)> System Admin', 'purords000m000', 'status=active');
        $response = $this->actingAs($purchasing)
            ->get($appUrlBase.'?favoriteContext='.rawurlencode($spoofed).'&LogicalId='.rawurlencode('lid://adasi.portal.purchasing'));

        $response->assertOk()
            ->assertDontSee('<img src=x onerror=alert(1)>', false)
            ->assertDontSee('System Admin', false);

        $config = $this->extractWorkspaceConfig($response->getContent());
        $this->assertSame(__('navigation.purchase_order'), $config['initialSession']['label']);
    }

    public function test_sec06_dos_limits_and_prerender_cache_key_alignment(): void
    {
        $purchasing = User::factory()->create(['role' => 'purchasing']);
        $appUrlBase = '/v2/ADASI_PRD/'.config('portal_urls.context_urls.app_uuid');

        // Raw > 2048 bytes -> 400
        $this->actingAs($purchasing)
            ->get($appUrlBase.'?favoriteContext='.str_repeat('A', 2052).'&LogicalId='.rawurlencode('lid://adasi.portal.purchasing'))
            ->assertStatus(400);

        // JSON depth > 4 -> 400
        $deepRaw = base64_encode('scv2'.json_encode([
            'description' => 'Deep',
            'application' => 'erp',
            'type' => 'session',
            'action' => 'purdash000m000',
            'path' => null,
            'argument' => ['a' => ['b' => ['c' => ['d' => 1]]]],
        ]));
        $this->actingAs($purchasing)
            ->get($appUrlBase.'?favoriteContext='.rawurlencode($deepRaw).'&LogicalId='.rawurlencode('lid://adasi.portal.purchasing'))
            ->assertStatus(400);

        // Prerender cache key strips favoriteContext and LogicalId junk so cache poisoning fails
        $this->actingAs($purchasing)->get(route('purchasing.dashboard'));
        $reqClean = Request::create(route('purchasing.shipments.index', ['status' => 'submitted']), 'GET');
        $reqJunk = Request::create(route('purchasing.shipments.index', ['status' => 'submitted', 'favoriteContext' => 'junk', 'LogicalId' => 'lid://x']), 'GET');
        $reqClean->setUserResolver(fn () => $purchasing);
        $reqJunk->setUserResolver(fn () => $purchasing);
        $this->assertSame(WorkspaceShell::prerenderCacheKey($reqClean), WorkspaceShell::prerenderCacheKey($reqJunk));

        // Direct portal.page context hit does not poison or mismatch subsequent _embed iframe fetch
        $contextUrl = PageRegistry::contextUrl('purchasing.shipments.index', query: ['status' => 'submitted']);
        $this->actingAs($purchasing)->get($contextUrl)->assertOk()->assertSee('data-workspace-shell="true"', false);
        $this->actingAs($purchasing)
            ->get(route('purchasing.shipments.index', ['status' => 'submitted', '_embed' => 1]))
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertSee('data-workspace-embed="true"', false);
    }

    public function test_sec07_and_sec08_framing_denial_and_strict_argument_allowlist(): void
    {
        $purchasing = User::factory()->create(['role' => 'purchasing']);
        $contextUrl = PageRegistry::contextUrl('purchasing.purchase-orders.index', query: ['status' => 'active']);
        $this->assertNotNull($contextUrl);

        // Top-level framing headers must stay DENY + frame-ancestors 'none' + strict Referrer-Policy
        $top = $this->actingAs($purchasing)->get($contextUrl);
        $top->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertStringContainsString("frame-ancestors 'none'", (string) $top->headers->get('Content-Security-Policy'));

        // _embed=1 and Sec-Fetch-Dest: iframe on portal.page are strictly forbidden (403)
        $this->actingAs($purchasing)->get($contextUrl.'&_embed=1')->assertForbidden();
        $this->actingAs($purchasing)->get($contextUrl, ['Sec-Fetch-Dest' => 'iframe'])->assertForbidden();

        // SEC-08 disallowed query keys
        $appUrlBase = '/v2/ADASI_PRD/'.config('portal_urls.context_urls.app_uuid');
        foreach (['email=user@example.com', 'npwp=123456', '_embed=1', 'return_url=%2Fadmin', 'status=active&status=draft'] as $badArg) {
            $badContext = PortalContextCodec::encodeScv2Payload('PO', 'purords000m000', $badArg);
            $this->actingAs($purchasing)
                ->get($appUrlBase.'?favoriteContext='.rawurlencode($badContext).'&LogicalId='.rawurlencode('lid://adasi.portal.purchasing'))
                ->assertStatus(400);
        }
    }

    public function test_multi_role_roundtrip_legacy_8char_hash_and_mfa_intended_url_and_rollback(): void
    {
        $actors = [
            'purchasing' => [User::factory()->create(['role' => 'purchasing']), 'purchasing.purchase-orders.index'],
            'finance' => [User::factory()->create(['role' => 'finance']), 'finance.invoices.index'],
            'accounting' => [User::factory()->create(['role' => 'accounting']), 'accounting.invoices.index'],
            'ga' => [User::factory()->create(['role' => 'ga']), 'ga.claims.index'],
            'qc' => [User::factory()->create(['role' => 'qc']), 'qc.inspections.index'],
            'admin' => [User::factory()->create(['role' => 'admin']), 'admin.users.index'],
            'supplier_import' => [User::factory()->create(['role' => 'supplier']), 'supplier.purchase-orders.index'],
        ];

        $localSupplier = User::factory()->create(['role' => 'supplier']);
        $localSupplier->supplierScopes()->delete();
        $localSupplier->supplierScopes()->firstOrCreate(['scope' => 'local']);
        $localSupplier->forgetSupplierScopes();
        $actors['supplier_local'] = [$localSupplier, 'local-supplier.invoices.index'];

        foreach ($actors as $label => [$user, $routeName]) {
            $url = PageRegistry::contextUrl($routeName);
            $this->assertNotNull($url, "Missing contextUrl for {$label}");
            $resp = $this->actingAs($user)->get($url);
            $resp->assertOk()->assertHeader('X-Frame-Options', 'DENY');
        }

        // Legacy 8-char hashid upgrades cleanly to 24-char embed URL when long_hashids is active
        $supplier = $actors['supplier_import'][0];
        $purchasing = $actors['purchasing'][0];
        $po = PurchaseOrder::create([
            'po_number' => 'PO-LEGACY-8CHAR',
            'supplier_id' => $supplier->id,
            'currency' => 'USD',
            'status' => 'active',
            'created_by' => $purchasing->id,
        ]);
        $legacy8 = (new Hashids((string) config('hashids.connections.main.salt'), 8))->encode($po->id);
        $long24 = HashidCodec::encode($po->id);
        $this->assertSame(8, strlen($legacy8));
        $this->assertSame(24, strlen($long24));

        $appUrlBase = '/v2/ADASI_PRD/'.config('portal_urls.context_urls.app_uuid');
        $legacyPayload = PortalContextCodec::encodeScv2Payload('PO', 'supords410m000', 'id='.$legacy8);
        $legacyResp = $this->actingAs($supplier)
            ->get($appUrlBase.'?favoriteContext='.rawurlencode($legacyPayload).'&LogicalId='.rawurlencode('lid://adasi.portal.supplier'));
        $legacyResp->assertOk()->assertSee($long24, false);

        // Instant rollback when PORTAL_CONTEXT_URLS_ENABLED=false
        $rollbackTarget = PageRegistry::contextUrl('purchasing.purchase-orders.index');
        $this->assertNotNull($rollbackTarget);

        $this->reloadPortalRoutes([
            'portal_urls.context_urls.enabled' => false,
            'portal_urls.page_registry.enabled' => true,
            'portal_urls.prefix.enabled' => true,
        ]);
        $this->assertNull(PageRegistry::contextUrl('purchasing.purchase-orders.index'));
        $this->actingAs($purchasing)->get($rollbackTarget)->assertNotFound();
        $legacyUuid = array_search('purchasing.purchase-orders.index', config('page_registry'), true);
        $this->actingAs($purchasing)->get('/v2/ADASI_PRD/'.$legacyUuid)->assertRedirect(route('purchasing.purchase-orders.index'));
    }

    public function test_guest_login_and_2fa_preserve_infor_context_url_and_enforce_post_login_access(): void
    {
        $twoFactor = app(TwoFactorService::class);
        $secret = $twoFactor->authenticator()->generateSecretKey(32);
        $purchasing = User::factory()->create(['role' => 'purchasing']);
        $purchasing->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => [],
            'two_factor_confirmed_at' => now(),
            'two_factor_last_used_step' => null,
        ])->saveQuietly();

        $targetContextUrl = PageRegistry::contextUrl('purchasing.shipments.index', query: ['search' => 'SHP-MFA', 'status' => 'submitted']);
        $this->assertNotNull($targetContextUrl);

        $expectedPath = (string) parse_url($targetContextUrl, PHP_URL_PATH).'?'.(string) parse_url($targetContextUrl, PHP_URL_QUERY);
        $this->assertSame(
            $expectedPath,
            TwoFactorService::safeIntendedUrl(Request::create(route('two-factor.challenge')), $targetContextUrl)
        );

        $this->get($targetContextUrl)->assertRedirect(route('login'));

        $this->post(route('login.store'), [
            'email' => $purchasing->email,
            'password' => 'password',
        ])->assertRedirect(route('two-factor.challenge'));

        $code = $twoFactor->authenticator()->getCurrentOtp($secret);
        $mfaComplete = $this->post('/two-factor-challenge', ['code' => $code]);
        $mfaComplete->assertRedirect();

        $redirectLocation = (string) $mfaComplete->headers->get('Location');
        $this->assertSame((string) parse_url($targetContextUrl, PHP_URL_PATH), (string) parse_url($redirectLocation, PHP_URL_PATH));
        parse_str((string) parse_url($targetContextUrl, PHP_URL_QUERY), $expectedQuery);
        parse_str((string) parse_url($redirectLocation, PHP_URL_QUERY), $actualQuery);
        $this->assertSame($expectedQuery['favoriteContext'] ?? null, $actualQuery['favoriteContext'] ?? null);
        $this->assertSame($expectedQuery['LogicalId'] ?? null, $actualQuery['LogicalId'] ?? null);

        $this->get($redirectLocation)
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertSee('data-workspace-shell="true"', false)
            ->assertSee('search=SHP-MFA', false);
    }

    public function test_login_without_intended_redirects_to_shortcuts_app_uuid_and_renders_shortcuts_home(): void
    {
        $appUuid = (string) config('portal_urls.context_urls.app_uuid');
        $expectedShortcutsPath = '/v2/ADASI_PRD/'.$appUuid;

        $purchasing = User::factory()->create(['role' => 'purchasing']);

        // Form login without url.intended goes directly to bare appUuid shortcuts URL
        $formLogin = $this->post(route('login.store'), [
            'email' => $purchasing->email,
            'password' => 'password',
        ]);
        $formLogin->assertRedirect($expectedShortcutsPath);

        // Hitting bare appUuid renders Shortcuts home directly (200 OK, no initial iframe)
        $home = $this->get($expectedShortcutsPath);
        $home->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertSee('data-workspace-shell="true"', false)
            ->assertSee('id="workspace-session-shortcuts"', false)
            ->assertSee('aria-selected="true"', false)
            ->assertDontSee('data-workspace-panel="initial"', false);
        $this->assertStringContainsString("frame-ancestors 'none'", (string) $home->headers->get('Content-Security-Policy'));

        $config = $this->extractWorkspaceConfig($home->getContent());
        $this->assertSame('shortcuts', $config['initialMode']);
        $this->assertNull($config['initialSession']);
        $this->assertSame(url($expectedShortcutsPath), $config['shortcutsUrl']);
        $this->assertTrue($config['contextLinks']['enabled']);
        $this->assertSame(url($expectedShortcutsPath), $config['contextLinks']['shortcutsUrl']);

        // /dashboard route also directs to bare appUuid when flags are enabled
        $this->get(route('dashboard'))->assertRedirect(url($expectedShortcutsPath));

        // Security gates still apply on bare appUuid Shortcuts home
        $this->get($expectedShortcutsPath.'?_embed=1')->assertForbidden();
        $this->get($expectedShortcutsPath, ['Sec-Fetch-Dest' => 'iframe'])->assertForbidden();
        $this->get($expectedShortcutsPath.'?email=leak@example.com')->assertStatus(400);
        $this->get($expectedShortcutsPath.'?LogicalId='.rawurlencode('lid://adasi.portal.purchasing'))->assertStatus(400);
        $this->get($expectedShortcutsPath.'?favoriteContext=')->assertStatus(400);

        // Dual-scope supplier still goes to supplier-context selector first, then shortcuts appUuid
        $this->post('/logout');
        $dual = User::factory()->create(['role' => 'supplier']);
        SupplierScope::firstOrCreate(['supplier_id' => $dual->id, 'scope' => 'local']);
        $dual->forgetSupplierScopes();

        $this->post(route('login.store'), [
            'email' => $dual->email,
            'password' => 'password',
        ])->assertRedirect(route('supplier-context.index', absolute: false));

        $this->post(route('supplier-context.store'), ['context' => 'local'])
            ->assertRedirect($expectedShortcutsPath);
        $this->get($expectedShortcutsPath)->assertOk();

        // Fresh interactive X-Auth-Splash-Client login returns redirect_url pointing to bare appUuid
        $this->post('/logout');
        $splashLogin = $this->withHeaders(['X-Auth-Splash-Client' => '1'])
            ->postJson(route('login.store'), [
                'email' => $purchasing->email,
                'password' => 'password',
            ]);
        $splashLogin->assertOk()->assertJson([
            'ok' => true,
            'two_factor' => false,
            'redirect_url' => $expectedShortcutsPath,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractWorkspaceConfig(string $html): array
    {
        $this->assertMatchesRegularExpression('/window\.AdasiWorkspaceShellConfig\s*=\s*JSON\.parse\(\'(.*?)\'\);/s', $html);
        preg_match('/window\.AdasiWorkspaceShellConfig\s*=\s*JSON\.parse\(\'(.*?)\'\);/s', $html, $matches);
        $decodedLiteral = json_decode('"'.str_replace('"', '\\"', $matches[1]).'"', true, flags: JSON_THROW_ON_ERROR);

        return json_decode($decodedLiteral, true, flags: JSON_THROW_ON_ERROR);
    }
}
