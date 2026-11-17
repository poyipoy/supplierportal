<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\PurchasingNavigation;
use App\Support\ServerTabsResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkspaceShellSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_feature_flag_off_keeps_legacy_layout_and_deny_headers(): void
    {
        config()->set('ui.workspace_shell.enabled', false);

        $user = User::factory()->create(['role' => 'purchasing']);

        $response = $this->actingAs($user)->get(route('purchasing.shipments.index'));

        $response->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY');
        $this->assertStringContainsString("frame-ancestors 'none'", (string) $response->headers->get('Content-Security-Policy'));
        $response->assertDontSee('data-workspace-shell', false);

        $embedAttempt = $this->actingAs($user)->get(route('purchasing.shipments.index', ['_embed' => 1]));

        $embedAttempt->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY');
        $this->assertStringContainsString("frame-ancestors 'none'", (string) $embedAttempt->headers->get('Content-Security-Policy'));
        $embedAttempt->assertDontSee('data-workspace-embed="true"', false);
    }

    public function test_flag_on_renders_workspace_shell_top_level_and_sameorigin_embed_inside_frame(): void
    {
        config()->set('ui.workspace_shell.enabled', true);

        $user = User::factory()->create(['role' => 'purchasing']);

        $shellResponse = $this->actingAs($user)->get(route('purchasing.shipments.index', ['search' => 'SHP']));

        $shellResponse->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertSee('data-workspace-shell', false)
            ->assertSee('role="tablist"', false)
            ->assertSee('data-workspace-tab="shortcuts"', false)
            ->assertSee('workspace-panel-shortcuts', false)
            ->assertSee('data-workspace-tab-close="initial"', false)
            ->assertSee('_embed=1', false);

        $shellConfig = $this->extractWorkspaceConfig($shellResponse->getContent());
        $this->assertSame(12, $shellConfig['maxTabs']);
        $this->assertSame(6, $shellConfig['maxLiveFrames']);
        $this->assertStringContainsString("frame-ancestors 'none'", (string) $shellResponse->headers->get('Content-Security-Policy'));

        $embedResponse = $this->actingAs($user)->get(route('purchasing.shipments.index', ['search' => 'SHP', '_embed' => 1]));

        $embedResponse->assertOk()
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertSee('data-workspace-embed="true"', false)
            ->assertDontSee('id="sidebar"', false)
            ->assertDontSee('class="top-navbar"', false)
            ->assertDontSee('id="chatDrawer"', false)
            ->assertDontSee('id="adasi-smart-panel"', false)
            ->assertDontSee('data-auth-splash', false);
        $this->assertStringContainsString("frame-ancestors 'self'", (string) $embedResponse->headers->get('Content-Security-Policy'));
    }

    public function test_security_sensitive_routes_never_relax_framing_headers_even_when_shell_is_enabled(): void
    {
        config()->set('ui.workspace_shell.enabled', true);

        $this->get(route('login'))
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY');

        $admin = User::factory()->create(['role' => 'admin']);
        $supplier = User::factory()->create(['role' => 'supplier']);
        $supplier->supplierScopes()->firstOrCreate(['scope' => 'local']);

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('profile.two-factor.start'));

        foreach ([
            [$admin, route('profile.security')],
            [$admin, route('profile.two-factor.setup')],
            [$admin, route('password.confirm')],
            [$supplier, route('supplier-context.index')],
        ] as [$actor, $url]) {
            $response = $this->actingAs($actor)->get($url);
            $response->assertOk()
                ->assertHeader('X-Frame-Options', 'DENY')
                ->assertDontSee('data-workspace-shell', false);
            $this->assertStringContainsString("frame-ancestors 'none'", (string) $response->headers->get('Content-Security-Policy'));
        }

        $confirmedUserCreate = $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.users.create', ['_embed' => 1]));

        $confirmedUserCreate->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertDontSee('data-workspace-embed="true"', false);
        $this->assertStringContainsString("frame-ancestors 'none'", (string) $confirmedUserCreate->headers->get('Content-Security-Policy'));
    }

    public function test_embed_routes_still_enforce_authentication_and_role_authorization(): void
    {
        config()->set('ui.workspace_shell.enabled', true);

        $this->get(route('purchasing.shipments.index', ['_embed' => 1]))
            ->assertRedirect(route('login'));

        $supplier = User::factory()->create(['role' => 'supplier']);

        $this->actingAs($supplier)
            ->get(route('purchasing.shipments.index', ['_embed' => 1]))
            ->assertForbidden();
    }

    public function test_purchasing_navigation_and_server_tabs_strip_embed_parameter(): void
    {
        config()->set('ui.workspace_shell.enabled', true);

        $purchasing = User::factory()->create(['role' => 'purchasing']);

        $this->actingAs($purchasing)
            ->get(route('purchasing.shipments.index', ['_embed' => 1, 'search' => 'SHP']))
            ->assertOk();

        $remembered = PurchasingNavigation::listUrl('purchasing.shipments.index');
        $this->assertIsString($remembered);
        $this->assertStringNotContainsString('_embed', $remembered);
        $this->assertStringContainsString('search=SHP', $remembered);

        $ajaxRequest = Request::create('/v2/ADSI_PROD/purchasing/shipments?_embed=1&tab=history', 'GET');
        $json = ServerTabsResponse::make('<div>ok</div>', 'history', $ajaxRequest)->getData(true);
        $this->assertStringNotContainsString('_embed', $json['url']);
        $this->assertStringContainsString('tab=history', $json['url']);
    }

    public function test_auth_splash_renders_once_in_outer_shell_and_is_omitted_inside_embed_frame(): void
    {
        config()->set('ui.workspace_shell.enabled', true);
        config()->set('ui.auth_splash.enabled', true);

        $purchasing = User::factory()->create(['role' => 'purchasing']);
        $targetPath = route('purchasing.dashboard', absolute: false);
        $marker = [
            'id' => 'ws-splash-marker',
            'reason' => 'post-login',
            'issued_at' => now()->timestamp,
            'target' => $targetPath,
        ];

        $shellResponse = $this->actingAs($purchasing)
            ->withSession(['ui.auth_splash' => $marker])
            ->get(route('purchasing.dashboard'));

        $shellResponse->assertOk()
            ->assertSee('data-auth-splash', false)
            ->assertSee('data-workspace-shell', false);

        $embedResponse = $this->actingAs($purchasing)
            ->withSession(['ui.auth_splash' => $marker])
            ->get(route('purchasing.dashboard', ['_embed' => 1]));

        $embedResponse->assertOk()
            ->assertDontSee('data-auth-splash', false)
            ->assertSee('data-workspace-embed="true"', false);
    }

    public function test_embedded_form_post_redirect_preserves_embed_param_for_eligible_target(): void
    {
        config()->set('ui.workspace_shell.enabled', true);

        $purchasing = User::factory()->create(['role' => 'purchasing']);

        // Updating profile triggers a standard 302 redirect back to profile.edit;
        // when submitted from an embedded iframe (Referer has _embed=1), redirect preserves _embed=1
        // so the iframe does not wrap a nested workspace shell on redirect.
        $response = $this->actingAs($purchasing)
            ->from(route('profile.edit', ['_embed' => 1]))
            ->patch(route('profile.update'), [
                'name' => $purchasing->name,
                'email' => $purchasing->email,
            ]);

        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString('_embed=1', $location);
    }

    public function test_shell_wrapping_reflashes_session_messages_so_initial_iframe_renders_them(): void
    {
        config()->set('ui.workspace_shell.enabled', true);

        $purchasing = User::factory()->create(['role' => 'purchasing']);

        // 1. Landing top-level after a redirect that flashed a status message
        $shellResponse = $this->actingAs($purchasing)
            ->withSession(['_flash' => ['new' => ['status'], 'old' => []], 'status' => 'profile-updated'])
            ->get(route('profile.edit'));

        $shellResponse->assertOk()
            ->assertSee('data-workspace-shell', false)
            ->assertSessionHas('status', 'profile-updated');

        // 2. Immediate follow-up iframe request still receives and consumes the flash message
        $embedResponse = $this->actingAs($purchasing)
            ->get(route('profile.edit', ['_embed' => 1]));

        $embedResponse->assertOk()
            ->assertSee('data-adasi-flash', false)
            ->assertSee(__('auth.feedback.profile_updated'), false);
    }

    public function test_workspace_shell_v2_config_exposes_shortcuts_and_excludes_security_routes_from_index_paths(): void
    {
        config()->set('ui.workspace_shell.enabled', true);

        $purchasing = User::factory()->create(['role' => 'purchasing']);

        $response = $this->actingAs($purchasing)->get(route('purchasing.dashboard'));
        $response->assertOk();

        $config = $this->extractWorkspaceConfig($response->getContent());

        $this->assertSame('shortcuts', $config['shortcutsTab']['key']);
        $this->assertTrue($config['shortcutsTab']['pinned']);
        $this->assertFalse($config['shortcutsTab']['closable']);
        $this->assertFalse($config['initialTab']['pinned']);
        $this->assertTrue($config['initialTab']['closable']);
        $this->assertSame(__('navigation.dashboard'), $config['initialTab']['label']);
        $this->assertSame(12, $config['maxTabs']);
        $this->assertSame(6, $config['maxLiveFrames']);
        $this->assertIsArray($config['indexPaths']);
        $this->assertArrayHasKey('menuPathTitles', $config);
        $this->assertSame(
            __('navigation.requisition'),
            $config['menuPathTitles'][rtrim(route('purchasing.requisitions.index', [], false), '/')] ?? null,
        );
        $this->assertContains(rtrim(route('purchasing.requisitions.index', [], false), '/'), $config['indexPaths']);

        // Direct top-level load on a sidebar route also uses exact menu title cleanly
        $prShellResponse = $this->actingAs($purchasing)->get(route('purchasing.requisitions.index'));
        $prShellResponse->assertOk();
        $prConfig = $this->extractWorkspaceConfig($prShellResponse->getContent());
        $this->assertSame(__('navigation.requisition'), $prConfig['initialTab']['label']);

        // Security, create, and admin edit paths must never be misclassified as index views.
        foreach ($config['indexPaths'] as $indexPath) {
            $this->assertStringNotContainsString('/profile/security', $indexPath);
            $this->assertStringNotContainsString('/supplier-context', $indexPath);
            $this->assertFalse(str_ends_with($indexPath, '/create'), "Index path should exclude /create routes: {$indexPath}");
        }
    }

    public function test_top_level_wrap_prerender_cache_is_reused_once_by_embedded_iframe_with_fresh_nonce(): void
    {
        config()->set('ui.workspace_shell.enabled', true);

        $purchasing = User::factory()->create(['role' => 'purchasing']);

        $shellResponse = $this->actingAs($purchasing)->get(route('purchasing.shipments.index'));
        $shellResponse->assertOk()->assertSee('data-workspace-shell', false);
        preg_match("/'nonce-([^']+)'/", (string) $shellResponse->headers->get('Content-Security-Policy'), $shellNonceMatch);
        $shellNonce = $shellNonceMatch[1] ?? '';
        $this->assertNotSame('', $shellNonce);

        $sessionCookie = collect($shellResponse->headers->getCookies())
            ->first(fn ($cookie) => $cookie->getName() === config('session.cookie'))
            ?->getValue();
        $this->assertNotEmpty($sessionCookie);

        $this->app->forgetScopedInstances();
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $cachedEmbedResponse = $this->actingAs($purchasing)
                ->withUnencryptedCookie(config('session.cookie'), (string) $sessionCookie)
                ->get(route('purchasing.shipments.index', ['_embed' => 1]));
            $cachedQueries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $cachedEmbedResponse->assertOk()->assertSee('data-workspace-embed="true"', false);
        preg_match("/'nonce-([^']+)'/", (string) $cachedEmbedResponse->headers->get('Content-Security-Policy'), $cachedNonceMatch);
        $cachedNonce = $cachedNonceMatch[1] ?? '';
        $this->assertNotSame('', $cachedNonce);
        $this->assertNotSame($shellNonce, $cachedNonce);
        $this->assertStringContainsString('nonce="'.$cachedNonce.'"', $cachedEmbedResponse->getContent());
        $this->assertStringNotContainsString('nonce="'.$shellNonce.'"', $cachedEmbedResponse->getContent());

        $this->app->forgetScopedInstances();
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $freshEmbedResponse = $this->actingAs($purchasing)
                ->withUnencryptedCookie(config('session.cookie'), (string) $sessionCookie)
                ->get(route('purchasing.shipments.index', ['_embed' => 1]));
            $freshQueries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $freshEmbedResponse->assertOk()->assertSee('data-workspace-embed="true"', false);
        $this->assertLessThan(count($freshQueries), count($cachedQueries), 'Single-use prerender cache hit should skip controller and view queries.');
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
