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
        $this->assertLessThan(strpos($auth, '<script src='), strpos($auth, 'data-readiness="native-login"'));
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
        $this->assertSame(5, substr_count($html, 'class="auth-splash__letter"'));
        $this->assertStringContainsString('hidden aria-hidden="true"', $html);
        $this->assertStringContainsString('role="status" aria-live="polite" aria-atomic="true"', $html);
        $this->assertStringNotContainsString('private-marker-id', $html);
        $this->assertStringNotContainsString('/private/intended-page', $html);

        request()->attributes->remove('ui.auth_splash_rendered');
        $this->assertStringNotContainsString('data-auth-splash', Blade::render('<x-ui.auth-splash :error="true" />'));
        $this->assertFalse(request()->attributes->get('ui.auth_splash_rendered', false));
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
        $this->assertCount(13, $assets);
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
            ->assertDontSee('cdn.datatables.net/1.13.6', false)
            ->assertSee('code.jquery.com/jquery-3.7.1.min.js', false);

        $this->actingAs($purchasing)
            ->get(route('purchasing.requisitions.index'))
            ->assertOk()
            ->assertSee('cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css', false)
            ->assertSee('cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js', false)
            ->assertSee('cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js', false);
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
            if (! preg_match('/@vite\([^)]*resources\/js\/app\.js/', $contents)) {
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

    public function test_unused_axios_runtime_and_dependency_are_removed(): void
    {
        $this->assertStringNotContainsString('axios', file_get_contents(base_path('package.json')));
        $this->assertStringNotContainsString('node_modules/axios', file_get_contents(base_path('package-lock.json')));
        $this->assertStringNotContainsString("import './bootstrap'", file_get_contents(resource_path('js/app.js')));
        $this->assertStringNotContainsString('window.axios', file_get_contents(resource_path('js/bootstrap.js')));
    }
}
