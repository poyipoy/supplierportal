<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

class FrontendAssetLoadingTest extends TestCase
{
    use RefreshDatabase;

    public function test_auth_splash_bootstrap_precedes_assets_and_keeps_native_readiness_independent(): void
    {
        foreach (['auth', 'app', 'guest'] as $layout) {
            $source = file_get_contents(resource_path("views/layouts/{$layout}.blade.php"));
            $bootstrap = strpos($source, "@include('partials.auth-splash-bootstrap'");
            $this->assertNotFalse($bootstrap);
            $this->assertLessThan(strpos($source, '<link'), $bootstrap);
            $i18n = strpos($source, "@include('partials.i18n-bootstrap')");
            $this->assertGreaterThan($bootstrap, $i18n);
            $this->assertLessThan(strpos($source, '<link'), $i18n, 'Classic i18n script must not wait for external stylesheets before the body can parse.');
            $this->assertStringContainsString('<x-ui.auth-splash', $source);
            $this->assertStringContainsString('data-auth-splash-layout-ready', $source);
        }

        $auth = file_get_contents(resource_path('views/layouts/auth.blade.php'));
        $this->assertFalse(strpos($auth, '<script src='), 'Auth layout must not block rendering with classic external script tags.');
        $this->assertLessThan(strpos($auth, "@yield('scripts')"), strpos($auth, 'data-readiness="native-login"'));
        $app = file_get_contents(resource_path('views/layouts/app.blade.php'));
        $this->assertGreaterThan(strrpos($app, "@stack('scripts')"), strpos($app, 'data-auth-splash-layout-ready'));
    }

    public function test_auth_splash_is_inlined_once_without_a_vite_import_or_entry(): void
    {
        $bootstrap = file_get_contents(resource_path('views/partials/auth-splash-bootstrap.blade.php'));
        $this->assertStringContainsString("resource_path('js/auth-splash.js')", $bootstrap);
        $this->assertStringContainsString("resource_path('css/auth-splash.css')", $bootstrap);
        $this->assertStringContainsString('ui.auth_splash_bootstrap_available', $bootstrap);
        $this->assertStringContainsString('isset($exception)', $bootstrap);
        $this->assertStringNotContainsString("import './auth-splash", file_get_contents(resource_path('js/app.js')));
        $this->assertStringNotContainsString('auth-splash', file_get_contents(base_path('vite.config.js')));
        $this->assertStringContainsString('markCoreReady()', file_get_contents(resource_path('js/app.js')));
    }

    public function test_login_controls_have_a_working_native_password_fallback(): void
    {
        $this->get(route('login'))->assertOk()
            ->assertSee('type="password"', false)
            ->assertSee(':type="visible ? \'text\' : \'password\'"', false)
            ->assertSee(':disabled="false"', false)
            ->assertSee('aria-label="'.__('auth.login.show_password').'"', false);
    }

    public function test_splash_acknowledges_only_rendered_markup_and_keeps_marker_details_server_side(): void
    {
        config(['ui.auth_splash.enabled' => true]);
        request()->attributes->set('ui.auth_splash', [
            'reason' => 'post-login',
            'id' => 'private-marker-id',
            'target' => '/private/intended-page',
        ]);
        request()->attributes->set('ui.auth_splash_bootstrap_available', false);

        $this->assertStringNotContainsString('data-auth-splash', Blade::render('<x-ui.auth-splash />'));
        $this->assertFalse(request()->attributes->get('ui.auth_splash_rendered', false));

        request()->attributes->set('ui.auth_splash_bootstrap_available', true);
        $html = Blade::render('<x-ui.auth-splash />');
        $this->assertTrue(request()->attributes->get('ui.auth_splash_rendered'));
        $this->assertStringContainsString('<picture class="auth-splash__picture">', $html);
        $this->assertStringContainsString('media="(prefers-reduced-motion: reduce)"', $html);
        $this->assertStringContainsString('data-auth-splash-media', $html);
        $this->assertStringContainsString('logo-adasi.webp', $html);
        $this->assertStringContainsString('logo-adasi.gif', $html);
        $this->assertStringContainsString('logo-adasi-wordmark.png', $html);
        $this->assertStringContainsString('decoding="async"', $html);
        $this->assertStringContainsString('fetchpriority="low"', $html);
        $this->assertStringNotContainsString('fetchpriority="high"', $html);
        $this->assertStringContainsString('width="384"', $html);
        $this->assertStringContainsString('height="153"', $html);
        $this->assertStringContainsString('alt=""', $html);
        $this->assertStringNotContainsString('class="auth-splash__letter"', $html);
        $this->assertStringContainsString('hidden aria-hidden="true"', $html);
        $this->assertStringContainsString('role="status" aria-live="polite" aria-atomic="true"', $html);
        $this->assertStringNotContainsString('private-marker-id', $html);
        $this->assertStringNotContainsString('/private/intended-page', $html);

        request()->attributes->remove('ui.auth_splash_rendered');
        $this->assertStringNotContainsString('data-auth-splash', Blade::render('<x-ui.auth-splash :error="true" />'));
        $this->assertFalse(request()->attributes->get('ui.auth_splash_rendered', false));
    }

    public function test_auth_splash_gif_and_static_wordmark_assets_exist_under_public_images(): void
    {
        $this->assertFileExists(public_path('assets/images/logo-adasi.webp'));
        $this->assertFileExists(public_path('assets/images/logo-adasi.gif'));
        $this->assertFileExists(public_path('assets/images/logo-adasi-wordmark.png'));
        $this->assertFileExists(public_path('assets/images/adasi-login-bg.avif'));
        $this->assertFileExists(public_path('assets/images/adasi-login-bg.webp'));
        $this->assertFileExists(public_path('assets/images/logo-adasi-sm.webp'));
        $this->assertFileExists(public_path('assets/images/logo-adasi-sm.png'));
        $this->assertFileDoesNotExist(base_path('logo-adasi.gif'));

        config(['ui.auth_splash.enabled' => true]);
        request()->attributes->set('ui.auth_splash', ['reason' => 'login-page']);

        $bootstrap = Blade::render("@include('partials.auth-splash-bootstrap', ['authSplashLayout' => 'auth', 'authSplashReadiness' => 'native-login'])");
        $this->assertStringContainsString('rel="preload" as="image"', $bootstrap);
        $this->assertStringContainsString('logo-adasi.webp', $bootstrap);
        $this->assertStringNotContainsString('fetchpriority="high"', $bootstrap);
        $this->assertStringContainsString('logo-adasi-wordmark.png', $bootstrap);
        $this->assertStringContainsString('data-media-fallback-ms="500"', $bootstrap);
    }

    public function test_missing_or_unreadable_standalone_source_omits_bootstrap_and_component(): void
    {
        config(['ui.auth_splash.enabled' => true]);
        request()->attributes->set('ui.auth_splash', ['reason' => 'login-page']);
        File::partialMock()->shouldReceive('isReadable')
            ->with(resource_path('js/auth-splash.js'))->once()->andReturn(false);

        $html = Blade::render("@include('partials.auth-splash-bootstrap', ['authSplashLayout' => 'auth', 'authSplashReadiness' => 'native-login']) <x-ui.auth-splash />");

        $this->assertStringNotContainsString('data-auth-splash', $html);
        $this->assertFalse(request()->attributes->get('ui.auth_splash_bootstrap_available'));
        $this->assertFalse(request()->attributes->get('ui.auth_splash_rendered', false));
    }

    public function test_static_cdn_scripts_and_styles_have_exact_versions_and_integrity(): void
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views')));
        $assets = [];
        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            preg_match_all('~<(?:script|link)\b[^>]*(?:src|href)="(https://(?:cdn\.jsdelivr\.net|cdn\.datatables\.net|code\.jquery\.com)/[^"<>]+)"[^>]*>~i', file_get_contents($file->getPathname()), $matches, PREG_SET_ORDER);
            foreach ($matches as [$tag, $url]) {
                $this->assertMatchesRegularExpression('~(?:@\d+\.\d+\.\d+/|/\d+\.\d+\.\d+/|jquery-\d+\.\d+\.\d+\.min\.js)~', $url);
                $this->assertMatchesRegularExpression('/integrity="sha384-[A-Za-z0-9+\/]{64}"/', $tag);
                $this->assertStringContainsString('crossorigin="anonymous"', $tag);
                $assets[$url] = true;
            }
        }
        $this->assertCount(11, $assets);
        $this->assertSame([], preg_grep('~/@fontsource/~', array_keys($assets)), 'web fonts are bundled by Vite, not loaded from a CDN');
        $this->assertArrayHasKey('https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js', $assets);
    }

    public function test_datatables_assets_are_only_rendered_for_opted_in_pages(): void
    {
        $purchasing = User::factory()->create([
            'role' => 'purchasing',
            'is_active' => true,
        ]);

        $this->actingAs($purchasing)
            ->get(route('purchasing.dashboard'))
            ->assertOk()
            ->assertDontSee('cdn.datatables.net/3.1.5', false)
            ->assertSee('code.jquery.com/jquery-4.1.0.min.js', false);

        $this->actingAs($purchasing)
            ->get(route('purchasing.requisitions.index'))
            ->assertOk()
            ->assertSee('cdn.datatables.net/3.1.5/css/dataTables.bootstrap5.min.css', false)
            ->assertSee('cdn.datatables.net/3.1.5/js/dataTables.min.js', false)
            ->assertSee('cdn.datatables.net/3.1.5/js/dataTables.bootstrap5.min.js', false);
    }

    public function test_every_blade_datatable_initializer_declares_the_asset_contract(): void
    {
        $viewRoot = resource_path('views');
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($viewRoot));
        $initializers = [];

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            if (! preg_match('/\.(?:DataTable|dataTable)\s*\(/', $contents)) {
                continue;
            }

            $relativePath = str_replace('\\', '/', substr($file->getPathname(), strlen($viewRoot) + 1));
            $contractPath = match ($relativePath) {
                'admin/material-hs-code/_script.blade.php' => resource_path('views/admin/material-hs-code/index.blade.php'),
                'purchasing/comparison/_scripts.blade.php' => resource_path('views/purchasing/comparison/vs-best.blade.php'),
                default => $file->getPathname(),
            };

            $this->assertStringContainsString(
                "@section('uses-datatables', true)",
                file_get_contents($contractPath),
                "DataTables assets were not enabled for {$relativePath}.",
            );

            $initializers[] = $relativePath;
        }

        $this->assertCount(15, $initializers);
    }

    public function test_every_view_that_loads_the_app_bundle_includes_the_loader_logo_partial(): void
    {
        $viewRoot = resource_path('views');
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($viewRoot));
        $bundleViews = [];

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            if (! preg_match('/@vite\([^)]*resources\/js\/(?:app|auth)\.js/', $contents)) {
                continue;
            }

            $relativePath = str_replace('\\', '/', substr($file->getPathname(), strlen($viewRoot) + 1));
            $bundleViews[] = $relativePath;

            $this->assertStringContainsString(
                "@include('partials.loader-logo')",
                $contents,
                "{$relativePath} loads app.js but does not include the loader logo partial.",
            );
        }

        foreach (['layouts/app.blade.php', 'layouts/auth.blade.php', 'layouts/guest.blade.php'] as $layout) {
            $this->assertContains($layout, $bundleViews);
        }
    }

    public function test_loader_logo_variable_is_defined_only_by_the_shared_partial(): void
    {
        $viewRoot = resource_path('views');
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($viewRoot));
        $definitions = [];

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            if (preg_match('/--adasi-loader-logo\s*:/', file_get_contents($file->getPathname()))) {
                $definitions[] = str_replace('\\', '/', substr($file->getPathname(), strlen($viewRoot) + 1));
            }
        }

        $this->assertSame(['partials/loader-logo.blade.php'], $definitions);
    }

    public function test_loader_logo_variable_renders_on_guest_and_app_layouts(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('--adasi-loader-logo: url(', false);

        $purchasing = User::factory()->create([
            'role' => 'purchasing',
            'is_active' => true,
        ]);

        $this->actingAs($purchasing)
            ->get(route('purchasing.dashboard'))
            ->assertOk()
            ->assertSee('--adasi-loader-logo: url(', false);
    }

    public function test_navigation_fade_and_loader_thresholds_render_across_layouts(): void
    {
        config([
            'ui.navigation.page_fade_ms' => 5000,
            'ui.navigation.slow_loader_threshold_ms' => 3200,
        ]);

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('--ui-page-fade-duration: 5000ms;', false)
            ->assertSee('pageFadeMs: 5000', false)
            ->assertSee('slowLoaderThresholdMs: 3200', false);

        $purchasing = User::factory()->create([
            'role' => 'purchasing',
            'is_active' => true,
        ]);

        $this->actingAs($purchasing)
            ->get(route('purchasing.dashboard'))
            ->assertOk()
            ->assertSee('--ui-page-fade-duration: 5000ms;', false)
            ->assertSee('pageFadeMs: 5000', false)
            ->assertSee('slowLoaderThresholdMs: 3200', false);
    }

    public function test_unused_axios_runtime_and_dependency_are_removed(): void
    {
        $this->assertStringNotContainsString('axios', file_get_contents(base_path('package.json')));
        $this->assertStringNotContainsString('node_modules/axios', file_get_contents(base_path('package-lock.json')));
        $this->assertStringNotContainsString("import './bootstrap'", file_get_contents(resource_path('js/app.js')));
        $this->assertStringNotContainsString('window.axios', file_get_contents(resource_path('js/bootstrap.js')));
    }

    public function test_auth_login_page_eliminates_render_blocking_assets_and_optimizes_lcp_and_logos(): void
    {
        config(['ui.auth_splash.enabled' => true, 'ui.auth_splash.login_page_enabled' => false]);

        $response = $this->get(route('login'))->assertOk();
        $html = $response->getContent();

        $this->assertStringNotContainsString('bootstrap.min.css', $html);
        $this->assertStringNotContainsString('bootstrap.bundle.min.js', $html);
        $this->assertDoesNotMatchRegularExpression('/<link\b[^>]*rel=["\']stylesheet["\'][^>]*sweetalert2/i', $html);
        $this->assertDoesNotMatchRegularExpression('/<link\b[^>]*rel=["\']stylesheet["\'][^>]*adasi-alert\.css/i', $html);
        $this->assertDoesNotMatchRegularExpression('/<script\b[^>]*\bsrc=["\'][^"\']*(?:adasi-i18n|adasi-alert|sweetalert2)\.js/i', $html);
        $this->assertStringContainsString('data-adasi-i18n-runtime', $html);
        $this->assertMatchesRegularExpression('/<script\b[^>]*nonce="[^"]+"[^>]*data-adasi-i18n-runtime/i', $html);
        $this->assertStringContainsString('id="adasi-auth-alert-assets"', $html);
        $this->assertStringNotContainsString('data-auth-splash', $html);
        $this->assertStringNotContainsString('data-splash-webp', $html);
        $this->assertStringNotContainsString('data-splash-gif', $html);
        $this->assertStringNotContainsString('data-splash-wordmark', $html);
        $this->assertStringContainsString('class="auth-card"', $html);
        $this->assertStringNotContainsString('auth-card ui-page-fade', $html);

        $this->assertStringNotContainsString('media="(max-width: 1023.98px)"', $html);
        $this->assertMatchesRegularExpression('/<source\b[^>]*media="\(min-width: 1024px\)"[^>]*adasi-login-bg\.avif[^>]*type="image\/avif"/i', $html);
        $this->assertMatchesRegularExpression('/<source\b[^>]*media="\(min-width: 1024px\)"[^>]*adasi-login-bg\.webp[^>]*type="image\/webp"/i', $html);
        $this->assertMatchesRegularExpression('/<source\b[^>]*media="\(min-width: 1024px\)"[^>]*adasi-login-bg\.jpg[^>]*type="image\/jpeg"/i', $html);
        $this->assertMatchesRegularExpression('/<img\b[^>]*src="data:image\/gif;base64,[^"]+"[^>]*fetchpriority="high"/i', $html);
        $this->assertStringContainsString('logo-adasi-sm.webp', $html);
        $this->assertStringContainsString('width="34" height="32"', $html);
        $this->assertStringContainsString('width="39" height="36"', $html);

        config(['ui.auth_splash.login_page_enabled' => true]);
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('class="auth-card ui-page-fade"', false);
    }

    public function test_i18n_bootstrap_falls_back_synchronously_without_throwing_when_script_is_unreadable(): void
    {
        $path = public_path('assets/js/adasi-i18n.js');

        File::partialMock()->shouldReceive('isReadable')
            ->with($path)
            ->once()
            ->andReturn(false);

        $html = view('partials.i18n-bootstrap')->render();

        $this->assertStringContainsString('id="adasi-i18n"', $html);
        $this->assertStringNotContainsString('data-adasi-i18n-runtime', $html);
        $this->assertMatchesRegularExpression('/<script\s+src="[^"]*assets\/js\/adasi-i18n\.js(?:\?v=\d+)?"><\/script>/i', $html);
        $this->assertDoesNotMatchRegularExpression('/<script\b[^>]*adasi-i18n\.js[^>]*\bdefer\b/i', $html);

        $source = file_get_contents(resource_path('views/partials/i18n-bootstrap.blade.php'));
        $this->assertStringContainsString('is_file($adasiI18nPath) ? @filemtime($adasiI18nPath) : null', $source);
    }
}
