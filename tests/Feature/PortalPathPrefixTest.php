<?php

namespace Tests\Feature;

use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\Auth\Sso\SsoTransactionStore;
use App\Support\PortalPath;
use App\Support\PurchasingNavigation;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\BootProviders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Laravel\Passport\ClientRepository;
use phpseclib4\Crypt\RSA;
use RuntimeException;
use Tests\Support\ReloadsPortalRoutes;
use Tests\TestCase;

class PortalPathPrefixTest extends TestCase
{
    use RefreshDatabase;
    use ReloadsPortalRoutes;

    public function test_all_operational_routes_gain_the_prefix_and_root_routes_keep_their_contracts(): void
    {
        $before = [];
        foreach (Route::getRoutes() as $route) {
            $before[$route->getName() ?? $route->uri()] = [$route->uri(), $route->methods(), $route->gatherMiddleware()];
        }
        $this->reloadPortalRoutes(['portal_urls.prefix.enabled' => true]);
        $rootPaths = ['/', 'login', 'two-factor-challenge', 'logout', 'confirm-password', 'password', 'up', 'oauth/token'];
        $rootPrefixes = ['oauth/', 'sso/', 'verify-receipt/', 'locale', 'broadcasting/'];
        foreach (Route::getRoutes() as $route) {
            if ($route->isFallback) {
                continue;
            }
            $key = $route->getName() ?? $route->uri();
            if (! isset($before[$key])) {
                continue;
            }
            [$uri, $methods, $middleware] = $before[$key];
            $staysRoot = in_array($uri, $rootPaths, true);
            foreach ($rootPrefixes as $prefix) {
                $staysRoot = $staysRoot || str_starts_with($uri, $prefix);
            }
            $this->assertSame($staysRoot ? $uri : 'v2/ADASI_PRD/'.$uri, $route->uri(), $key);
            $this->assertSame($methods, $route->methods(), $key);
            $this->assertSame($middleware, $route->gatherMiddleware(), $key);
        }
        $this->assertSame('/sso/callback', route('sso.callback', absolute: false));
        $this->assertSame('/v2/ADASI_PRD/profile/two-factor/setup', route('profile.two-factor.setup', absolute: false));
    }

    public function test_path_redirects_are_bidirectional_get_and_head_only_and_keep_the_exact_query(): void
    {
        foreach ([true, false] as $enabled) {
            $this->reloadPortalRoutes(['portal_urls.prefix.enabled' => $enabled]);
            [$old, $new] = $enabled ? ['/profile', '/v2/ADASI_PRD/profile'] : ['/v2/ADASI_PRD/profile', '/profile'];
            foreach (['GET', 'HEAD'] as $method) {
                $this->call($method, $old.'?q=a%20b&x=1&x=2')->assertStatus(301)
                    ->assertHeader('Location', $new.'?q=a%20b&x=1&x=2');
            }
            foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
                $response = $this->call($method, $old);
                $this->assertContains($response->status(), [404, 405]);
                $response->assertHeaderMissing('Location');
            }
        }
    }

    public function test_forgot_password_register_and_registration_tracking_follow_the_prefix_flag(): void
    {
        $pages = [
            'password.request' => 'forgot-password',
            'supplier.register' => 'supplier/register',
            'supplier.registration.access-form' => 'supplier/registration/access',
        ];

        $this->reloadPortalRoutes(['portal_urls.prefix.enabled' => true]);
        foreach ($pages as $name => $path) {
            $this->assertSame('/v2/ADASI_PRD/'.$path, route($name, absolute: false), $name);
            $this->get('/v2/ADASI_PRD/'.$path)->assertOk();
            $this->get('/'.$path.'?ref=1')->assertStatus(301)
                ->assertHeader('Location', '/v2/ADASI_PRD/'.$path.'?ref=1');
        }
        $this->assertSame('/v2/ADASI_PRD/supplier/registration/status', route('supplier.registration.status', absolute: false));
        $this->assertSame('/v2/ADASI_PRD/supplier/register', route('supplier.register.store', absolute: false));
        $this->post('/supplier/register')->assertHeaderMissing('Location');
        $this->get('/v2/ADASI_PRD/supplier/registration/status')->assertRedirect(route('supplier.registration.access-form'));

        $this->reloadPortalRoutes(['portal_urls.prefix.enabled' => false]);
        foreach ($pages as $name => $path) {
            $this->assertSame('/'.$path, route($name, absolute: false), $name);
            $this->get('/'.$path)->assertOk();
        }
    }

    public function test_unknown_paths_prefix_boundaries_and_untrusted_hosts_never_create_external_redirects(): void
    {
        $this->reloadPortalRoutes(['portal_urls.prefix.enabled' => true]);
        foreach (['/unknown-url-page', '/v2/ADASI_PRD/unknown-url-page', '/v2/ADASI_PRDextra/profile'] as $uri) {
            $this->get($uri)->assertNotFound()->assertHeaderMissing('Location');
        }
        $this->withServerVariables(['HTTP_HOST' => 'attacker.test'])->get('/profile')
            ->assertStatus(301)->assertHeader('Location', '/v2/ADASI_PRD/profile');
        $this->reloadPortalRoutes(['portal_urls.prefix.enabled' => false]);
        $this->get('/v2/ADASI_PRD//attacker.test/profile')->assertNotFound()->assertHeaderMissing('Location');
    }

    public function test_custom_prefix_and_previous_prefix_can_both_reach_the_current_route(): void
    {
        $this->reloadPortalRoutes(['portal_urls.prefix.enabled' => true, 'portal_urls.prefix.value' => 'erp/ADASI_TST']);
        $this->get('/v2/ADASI_PRD/profile?tab=layout')->assertStatus(301)
            ->assertHeader('Location', '/erp/ADASI_TST/profile?tab=layout');
        $this->get('/profile')->assertStatus(301)->assertHeader('Location', '/erp/ADASI_TST/profile');
        $this->get('/erp/ADASI_TST/profile')->assertRedirect(route('login'));

        $this->reloadPortalRoutes(['portal_urls.prefix.value' => 'purchasing']);
        $this->get('/purchasing/shipments')->assertStatus(301)->assertHeader('Location', '/purchasing/purchasing/shipments');
        // This path is also the current shared dashboard route; a registered route takes priority.
        $this->get('/purchasing/dashboard')->assertRedirect(route('login'));
    }

    public function test_fallback_never_starts_a_session_security_detour_before_resolving_the_path(): void
    {
        $user = User::factory()->create(['role' => 'purchasing']);
        foreach ([false, true] as $prefix) {
            $this->reloadPortalRoutes(['portal_urls.prefix.enabled' => $prefix]);
            foreach ([false, true] as $passwordChange) {
                config(['sso.enabled' => ! $passwordChange, 'sso.client_id' => 'test-client']);
                $user->forceFill(['must_change_password' => $passwordChange])->saveQuietly();
                $this->actingAs($user)->get('/unknown-url-page')->assertNotFound()->assertHeaderMissing('Location');
                $old = $prefix ? '/profile' : '/v2/ADASI_PRD/profile';
                $target = $prefix ? '/v2/ADASI_PRD/profile' : '/profile';
                $this->get($old)->assertStatus(301)->assertHeader('Location', $target);
            }
        }
    }

    public function test_fallback_preserves_404_405_and_options_for_existing_and_unknown_paths(): void
    {
        foreach ([false, true] as $prefix) {
            $this->reloadPortalRoutes(['portal_urls.prefix.enabled' => $prefix]);
            foreach (['/oauth/token', '/logout', '/password'] as $uri) {
                $this->get($uri)->assertStatus(405)->assertHeader('Allow')->assertHeaderMissing('Location');
                $this->call('HEAD', $uri)->assertStatus(405)->assertHeader('Allow');
            }
            foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
                $this->call($method, '/unknown-url-page')->assertNotFound()->assertHeaderMissing('Location');
            }
            $this->call('OPTIONS', '/oauth/token')->assertOk()->assertHeader('Allow', 'POST');
        }
    }

    public function test_prefixed_polling_does_not_redirect_guests_or_unverified_sso_sessions(): void
    {
        $this->reloadPortalRoutes(['portal_urls.prefix.enabled' => true]);
        $this->get(route('notifications.unread-count'))->assertUnauthorized()->assertHeaderMissing('Location');
        config(['sso.enabled' => true, 'sso.client_id' => 'test-client']);
        $this->actingAs(User::factory()->create(['role' => 'purchasing']))
            ->get(route('notifications.unread-count'))->assertUnauthorized()->assertHeaderMissing('Location')
            ->assertSessionMissing('sso.transactions');
    }

    public function test_signed_receipt_urls_stay_valid_at_root(): void
    {
        $this->reloadPortalRoutes(['portal_urls.prefix.enabled' => true]);
        foreach (['supplier', 'ga'] as $type) {
            $url = URL::signedRoute('receipts.verify-'.$type, ['receipt' => 123, 'revision' => 2]);
            $this->assertSame('/verify-receipt/'.$type.'/123', parse_url($url, PHP_URL_PATH));
            $this->assertTrue(Request::create($url)->hasValidSignature());
            $this->get($url)->assertNotFound()->assertHeaderMissing('Location');
        }
    }

    public function test_combined_prefix_and_long_hashes_keep_supplier_isolation_and_raw_integer_rejection(): void
    {
        $this->reloadPortalRoutes(['portal_urls.prefix.enabled' => true, 'portal_urls.long_hashids.enabled' => true]);
        $supplier = User::factory()->create();
        $other = User::factory()->create();
        $po = PurchaseOrder::create([
            'po_number' => 'PO/PREFIX/001', 'supplier_id' => $other->id, 'currency' => 'IDR',
            'status' => 'active', 'created_by' => $other->id,
        ]);
        $this->actingAs($supplier)->get(route('supplier.purchase-orders.show', $po->id))->assertNotFound();
        $response = $this->actingAs($supplier)->get(route('supplier.purchase-orders.show', $po));
        $this->assertContains($response->status(), [403, 404]);
    }

    public function test_server_tabs_and_purchasing_list_memory_keep_the_prefixed_url(): void
    {
        $this->reloadPortalRoutes(['portal_urls.prefix.enabled' => true]);
        $user = User::factory()->create(['role' => 'purchasing']);
        $url = route('purchasing.supplier-audits.index', ['queue' => 'late']);
        $this->actingAs($user)->withHeaders(['X-Adasi-Server-Tabs' => '1', 'X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])
            ->get($url)->assertOk()->assertJsonPath('url', $url)->assertJsonPath('tab', 'late');
        $this->assertSame($url, PurchasingNavigation::listUrl('purchasing.supplier-audits.index'));
    }

    public function test_sso_login_and_callback_preserve_the_prefixed_intended_page(): void
    {
        $this->reloadPortalRoutes(['portal_urls.prefix.enabled' => true]);
        RSA::forceEngine('PHP');
        try {
            $key = RSA::createKey(2048);
        } finally {
            RSA::forceEngine();
        }
        config(['passport.private_key' => $key->toString('PKCS8'), 'passport.public_key' => $key->getPublicKey()->toString('PKCS8'), 'sso.enabled' => true]);
        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('URL test', [route('sso.callback')], confidential: false);
        config(['sso.client_id' => (string) $client->getKey()]);
        $user = User::factory()->create(['role' => 'purchasing']);
        $intended = PortalPath::path('profile/customization').'?tab=layout';
        $this->get($intended)->assertRedirect(route('login'));
        $authorization = $this->get('/login')->assertRedirect()->headers->get('Location');
        $this->assertSame('/oauth/authorize', parse_url($authorization, PHP_URL_PATH));
        $this->get($authorization)->assertOk();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $callback = $this->get($authorization)->assertRedirect()->headers->get('Location');
        $this->assertSame('/sso/callback', parse_url($callback, PHP_URL_PATH));
        $this->get($callback)->assertRedirect($intended)->assertSessionHas('sso.verified_at');
        $this->get($intended)->assertOk();
        $this->assertDatabaseMissing('oauth_access_tokens', ['revoked' => false]);
        $this->get(PortalPath::path('supplier/register'))->assertOk();
        $this->post('/locale/id')->assertRedirect();

        $request = Request::create('http://localhost/login');
        $this->assertSame(PortalPath::path('dashboard'), app(SsoTransactionStore::class)->safeIntendedUrl($request, PortalPath::path('oauth/authorize')));
    }

    public function test_invalid_prefix_rejects_application_boot(): void
    {
        $app = require base_path('bootstrap/app.php');
        $app->beforeBootstrapping(BootProviders::class, function ($app): void {
            $app['config']->set('portal_urls.prefix.enabled', true);
            $app['config']->set('portal_urls.prefix.value', 'assets/ADASI');
        });
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PORTAL_PATH_PREFIX');
        $app->make(Kernel::class)->bootstrap();
    }
}
