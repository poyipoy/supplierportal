<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\ShareAuthSplashState;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Tests\TestCase;

class AuthSplashTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ui.auth_splash.enabled' => true]);
    }

    public function test_clean_login_and_refresh_render_request_only_splash(): void
    {
        foreach (range(1, 2) as $visit) {
            $this->get('/login')->assertOk()->assertSee('data-splash-reason="login-page"', false)
                ->assertSessionMissing('ui.auth_splash');
        }
    }

    public function test_flag_defaults_off_and_disabled_requests_discard_stale_markers(): void
    {
        $defaults = require base_path('config/ui.php');
        $this->assertFalse($defaults['auth_splash']['enabled']);
        $this->assertSame(2000, $defaults['auth_splash']['min_visible_ms']);
        config(['ui.auth_splash.enabled' => false]);
        $this->withSession(['ui.auth_splash' => $this->marker('/login', 'post-logout')])
            ->get('/login')->assertOk()->assertDontSee('data-auth-splash', false)
            ->assertSessionMissing('ui.auth_splash');
        config(['ui.auth_splash.enabled' => true]);
        $this->get('/login')->assertSee('data-splash-reason="login-page"', false);
    }

    public function test_minimum_visibility_is_shared_with_the_independent_bootstrap(): void
    {
        $this->get('/login')->assertOk()->assertSee('data-min-visible-ms="2000"', false);
        foreach ([0, 500, 10000, 'invalid'] as $minimum) {
            config(['ui.auth_splash.min_visible_ms' => $minimum]);
            $this->get('/login')->assertOk()->assertSee('data-min-visible-ms="'.$minimum.'"', false);
        }
    }

    #[DataProvider('loginFeedback')]
    public function test_login_feedback_is_never_covered(string $key, mixed $value): void
    {
        $this->withSession([$key => $value])->get('/login')->assertOk()
            ->assertDontSee('data-auth-splash', false);
    }

    public static function loginFeedback(): array
    {
        return [
            'status' => ['status', 'Session ended'],
            'warning' => ['warning', 'Please retry'],
            'error' => ['error', 'Unable to sign in'],
            'errors' => ['errors', (new ViewErrorBag)->put('default', new MessageBag(['email' => 'Invalid email']))],
        ];
    }

    public function test_successful_login_binds_marker_to_actual_redirect_and_consumes_rendered_destination(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/dashboard')->assertSessionHas('ui.auth_splash', fn (array $marker) => $marker['reason'] === 'post-login' && $marker['target'] === '/dashboard' && is_string($marker['id'])
            );
        $id = session('ui.auth_splash.id');
        $this->get('/dashboard')->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas('ui.auth_splash.target', '/admin/dashboard');
        $page = $this->get('/admin/dashboard')->assertOk()
            ->assertSee('data-splash-reason="post-login"', false)->assertSessionMissing('ui.auth_splash');
        $this->assertStringNotContainsString($id, $page->getContent());
        $this->get('/admin/dashboard')->assertOk()->assertDontSee('data-auth-splash', false);
    }

    #[DataProvider('intendedDestinations')]
    public function test_intended_redirect_security_is_preserved(string $intended, string $expected): void
    {
        $user = User::factory()->create();
        $this->withSession(['url.intended' => $intended])->post('/login', [
            'email' => $user->email, 'password' => 'password',
        ])->assertRedirect($expected)->assertSessionHas('ui.auth_splash.target', $expected);
    }

    public static function intendedDestinations(): array
    {
        return [
            'safe page' => ['/profile', '/profile'],
            'external' => ['https://evil.example/phishing', '/dashboard'],
            'polling' => ['http://localhost/notifications/unread-count', '/dashboard'],
        ];
    }

    public function test_mandatory_password_redirect_retains_marker_despite_warning(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertRedirect(route('profile.security'))
            ->assertSessionHas('ui.auth_splash.target', '/profile/security');
        $this->get('/profile/security')->assertOk()->assertSee('data-splash-reason="post-login"', false)
            ->assertSessionMissing('ui.auth_splash');
    }

    #[DataProvider('roleDestinations')]
    public function test_every_role_and_supplier_context_consumes_on_its_actual_destination(string $role, array $scopes, string $destination): void
    {
        $user = User::factory()->create(['role' => $role]);
        if ($role === 'supplier') {
            $user->supplierScopes()->delete();
            foreach ($scopes as $scope) {
                $user->supplierScopes()->create(['scope' => $scope]);
            }
        }
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertRedirect($destination)->assertSessionHas('ui.auth_splash.target', $destination);
        $this->get($destination)->assertOk()->assertSee('data-splash-reason="post-login"', false)
            ->assertSessionMissing('ui.auth_splash');
        $this->get($destination)->assertOk()->assertDontSee('data-auth-splash', false);
    }

    public static function roleDestinations(): array
    {
        return [
            ['admin', [], '/admin/dashboard'],
            ['purchasing', [], '/purchasing/dashboard'],
            ['qc', [], '/qc/dashboard'],
            ['finance', [], '/finance/dashboard'],
            ['accounting', [], '/accounting/dashboard'],
            ['ga', [], '/ga/dashboard'],
            ['supplier', ['import'], '/supplier/dashboard'],
            ['supplier', ['local'], '/local-supplier/dashboard'],
            ['supplier', ['import', 'local'], '/supplier-context'],
        ];
    }

    public function test_failed_credentials_and_json_login_do_not_leave_success_markers(): void
    {
        $user = User::factory()->create();
        $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertSessionHasErrors('email')->assertSessionMissing('ui.auth_splash');
        $this->get('/login')->assertDontSee('data-auth-splash', false);
        $this->postJson('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/dashboard')->assertSessionMissing('ui.auth_splash');
    }

    #[DataProvider('mfaMethods')]
    public function test_mfa_only_issues_marker_after_success(string $method): void
    {
        $secret = app(TwoFactorService::class)->authenticator()->generateSecretKey(32);
        $recovery = 'ABCD-EFGH-JKLM';
        $user = User::factory()->create();
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => [hash_hmac('sha256', str_replace('-', '', $recovery), hash('sha256', (string) config('app.key')))],
            'two_factor_confirmed_at' => now(),
        ])->saveQuietly();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('two-factor.challenge'))->assertSessionMissing('ui.auth_splash');
        $this->get('/two-factor-challenge')->assertOk()->assertDontSee('data-auth-splash', false);
        $this->post('/two-factor-challenge', ['code' => 'INVALID'])
            ->assertSessionHasErrors('code')->assertSessionMissing('ui.auth_splash');
        $code = $method === 'recovery' ? $recovery : app(TwoFactorService::class)->authenticator()->getCurrentOtp($secret);
        $this->post('/two-factor-challenge', ['code' => $code])->assertRedirect('/dashboard')
            ->assertSessionHas('ui.auth_splash.reason', 'post-login')->assertSessionHas('ui.auth_splash.target', '/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public static function mfaMethods(): array
    {
        return [['totp'], ['recovery']];
    }

    public function test_logout_uses_new_session_and_splash_is_consumed_once(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->withSession(['old_private_state' => 'remove-me']);
        $oldId = session()->getId();
        $this->post('/logout')->assertRedirect(route('login'))->assertSessionMissing('old_private_state')
            ->assertSessionHas('ui.auth_splash.reason', 'post-logout')->assertSessionHas('ui.auth_splash.target', '/login');
        $this->assertGuest();
        $this->assertNotSame($oldId, session()->getId());
        $this->get('/login')->assertSee('data-splash-reason="post-logout"', false)->assertSessionMissing('ui.auth_splash');
        $this->get('/login')->assertSee('data-splash-reason="login-page"', false);
    }

    public function test_json_logout_does_not_leave_marker_for_later_navigation(): void
    {
        $this->actingAs(User::factory()->create())->postJson('/logout')
            ->assertRedirect(route('login'))->assertSessionMissing('ui.auth_splash');
        $this->assertGuest();
    }

    public function test_session_security_invalidation_does_not_resurrect_marker_or_cover_feedback(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->withSession([
            'ui.auth_splash' => $this->marker('/dashboard'),
            'auth_session_version' => $user->auth_session_version + 1,
        ])->get('/dashboard')->assertRedirect(route('login'))->assertSessionMissing('ui.auth_splash');
        $this->assertGuest();
        $this->get('/login')->assertOk()->assertDontSee('data-auth-splash', false);
    }

    public function test_ttl_accepts_29_seconds_and_rejects_exactly_30_seconds(): void
    {
        $this->freezeTime();
        foreach ([29 => true, 30 => false] as $age => $eligible) {
            $request = $this->request('/target');
            $request->session()->put('ui.auth_splash', array_replace($this->marker('/target'), ['issued_at' => now()->timestamp - $age]));
            (new ShareAuthSplashState)->handle($request, function (Request $request) use ($eligible) {
                $this->assertSame($eligible, $request->attributes->has('ui.auth_splash'));

                return response('HTML', 200, ['Content-Type' => 'text/html']);
            });
            $this->assertFalse($request->session()->has('ui.auth_splash'));
        }
    }

    public function test_pending_mfa_or_missing_authentication_disqualifies_post_login_marker(): void
    {
        foreach ([true, false] as $pendingMfa) {
            $request = $this->request('/target');
            $request->session()->put('ui.auth_splash', $this->marker('/target'));
            if ($pendingMfa) {
                $request->session()->put(TwoFactorService::PENDING_LOGIN_KEY, ['user_id' => 1]);
            } else {
                $request->setUserResolver(fn () => null);
            }
            (new ShareAuthSplashState)->handle($request, function (Request $request) {
                $this->assertNull($request->attributes->get('ui.auth_splash'));

                return response('HTML', 200, ['Content-Type' => 'text/html']);
            });
            $this->assertFalse($request->session()->has('ui.auth_splash'));
        }
    }

    #[DataProvider('backgroundRequests')]
    public function test_background_requests_do_not_offer_or_consume_markers(string $method, array $headers): void
    {
        $request = $this->request('/target', $method, $headers);
        $marker = $this->marker('/target');
        $request->session()->put('ui.auth_splash', $marker);
        (new ShareAuthSplashState)->handle($request, function (Request $request) {
            $this->assertNull($request->attributes->get('ui.auth_splash'));

            return response('HTML', 200, ['Content-Type' => 'text/html']);
        });
        $this->assertSame($marker, $request->session()->get('ui.auth_splash'));
    }

    public static function backgroundRequests(): array
    {
        return [
            'head' => ['HEAD', []],
            'xhr' => ['GET', ['X-Requested-With' => 'XMLHttpRequest']],
            'json' => ['GET', ['Accept' => 'application/json']],
            'tabs' => ['GET', ['X-Adasi-Server-Tabs' => '1']],
            'fetch' => ['GET', ['Sec-Fetch-Dest' => 'empty', 'Sec-Fetch-Mode' => 'cors']],
            'image' => ['GET', ['Sec-Fetch-Dest' => 'image']],
        ];
    }

    #[DataProvider('invalidMarkers')]
    public function test_invalid_or_expired_markers_are_discarded(mixed $change): void
    {
        $request = $this->request('/target');
        $marker = is_array($change) ? array_replace($this->marker('/target'), $change) : $change;
        $request->session()->put('ui.auth_splash', $marker);
        (new ShareAuthSplashState)->handle($request, function (Request $request) {
            $this->assertNull($request->attributes->get('ui.auth_splash'));

            return response('HTML', 200, ['Content-Type' => 'text/html']);
        });
        $this->assertFalse($request->session()->has('ui.auth_splash'));
    }

    public static function invalidMarkers(): array
    {
        return [
            'string' => ['invalid'],
            'empty id' => [['id' => '']],
            'wrong reason' => [['reason' => 'other']],
            'missing target' => [['target' => null]],
            'wrong timestamp' => [['issued_at' => 'invalid']],
            'future' => [['issued_at' => time() + 3600]],
            'expired boundary' => [['issued_at' => time() - 30]],
        ];
    }

    public function test_matching_redirect_updates_target_without_extending_ttl(): void
    {
        $request = $this->request('/target?b=2&a=1');
        $marker = $this->marker('/target?b=2&a=1');
        $request->session()->put('ui.auth_splash', $marker);
        (new ShareAuthSplashState)->handle($request, fn () => new RedirectResponse('/final?q=%2F#fragment'));
        $this->assertSame('/final?q=%2F', $request->session()->get('ui.auth_splash.target'));
        $this->assertSame($marker['issued_at'], $request->session()->get('ui.auth_splash.issued_at'));
    }

    #[DataProvider('terminalResponses')]
    public function test_terminal_unrendered_responses_discard_marker(string $type): void
    {
        $request = $this->request('/target');
        $request->session()->put('ui.auth_splash', $this->marker('/target'));
        (new ShareAuthSplashState)->handle($request, function () use ($type) {
            return match ($type) {
                'external' => redirect('https://evil.example/target'),
                'json' => response()->json(['ok' => true]),
                'download' => response('bytes', 200, ['Content-Type' => 'text/html', 'Content-Disposition' => 'attachment; filename="x.html"']),
                'error' => response('error', 403, ['Content-Type' => 'text/html']),
                default => response('unsupported layout', 200, ['Content-Type' => 'text/html']),
            };
        });
        $this->assertFalse($request->session()->has('ui.auth_splash'));
    }

    public static function terminalResponses(): array
    {
        return [['external'], ['json'], ['download'], ['error'], ['unsupported']];
    }

    public function test_unrelated_target_clears_marker_without_rendering(): void
    {
        $request = $this->request('/other');
        $request->session()->put('ui.auth_splash', $this->marker('/target'));
        (new ShareAuthSplashState)->handle($request, function (Request $request) {
            $this->assertNull($request->attributes->get('ui.auth_splash'));

            return response('HTML', 200, ['Content-Type' => 'text/html']);
        });
        $this->assertFalse($request->session()->has('ui.auth_splash'));
    }

    public function test_only_render_acknowledgement_consumes_marker_and_exposes_generic_reason(): void
    {
        $request = $this->request('/target');
        $request->session()->put('ui.auth_splash', $this->marker('/target'));
        (new ShareAuthSplashState)->handle($request, function (Request $request) {
            $this->assertSame(['reason' => 'post-login'], $request->attributes->get('ui.auth_splash'));
            $request->attributes->set('ui.auth_splash_rendered', true);

            return response('component HTML', 200, ['Content-Type' => 'text/html']);
        });
        $this->assertFalse($request->session()->has('ui.auth_splash'));
    }

    public function test_downstream_replacement_or_invalidation_is_not_overwritten(): void
    {
        foreach ([true, false] as $replace) {
            $request = $this->request('/target');
            $request->session()->put('ui.auth_splash', $this->marker('/target'));
            $replacement = array_replace($this->marker('/new'), ['id' => 'replacement-marker']);
            (new ShareAuthSplashState)->handle($request, function (Request $request) use ($replace, $replacement) {
                $replace ? $request->session()->put('ui.auth_splash', $replacement) : $request->session()->invalidate();

                return redirect('/final');
            });
            $this->assertSame($replace ? $replacement : null, $request->session()->get('ui.auth_splash'));
        }
    }

    public function test_exception_cleans_matching_marker_and_rethrows(): void
    {
        $request = $this->request('/target');
        $request->session()->put('ui.auth_splash', $this->marker('/target'));
        try {
            (new ShareAuthSplashState)->handle($request, fn () => throw new RuntimeException('fixture'));
            $this->fail('Exception must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('fixture', $exception->getMessage());
            $this->assertFalse($request->session()->has('ui.auth_splash'));
        }
    }

    public function test_exception_after_authentication_does_not_leave_an_unbound_new_marker(): void
    {
        $request = $this->request('/login', 'POST');
        $request->route()->setAction(array_replace($request->route()->getAction(), ['as' => 'login.store']));
        try {
            (new ShareAuthSplashState)->handle($request, function (Request $request) {
                $request->session()->put('ui.auth_splash', array_replace($this->marker('/target'), ['target' => null]));
                throw new RuntimeException('after authentication');
            });
            $this->fail('Exception must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('after authentication', $exception->getMessage());
            $this->assertFalse($request->session()->has('ui.auth_splash'));
        }
    }

    #[DataProvider('queryCountDestinations')]
    public function test_splash_adds_no_database_queries_on_login_or_authenticated_document(string $destination): void
    {
        if ($destination !== '/login') {
            $this->actingAs(User::factory()->create(['role' => 'admin']));
        }
        config(['ui.auth_splash.enabled' => false]);
        $this->get($destination)->assertOk();

        $queries = [];
        foreach ([false, true, false, true] as $enabled) {
            config(['ui.auth_splash.enabled' => $enabled]);
            if ($destination !== '/login') {
                $this->withSession(['ui.auth_splash' => $this->marker($destination)]);
            }
            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                $response = $this->get($destination)->assertOk();
                $queries[] = array_column(DB::getQueryLog(), 'query');
            } finally {
                DB::disableQueryLog();
            }
            $enabled ? $response->assertSee('data-auth-splash data-splash-reason=', false)
                : $response->assertDontSee('data-auth-splash', false);
        }
        $this->assertSame($queries[0], $queries[1]);
        $this->assertSame($queries[2], $queries[3]);
    }

    public static function queryCountDestinations(): array
    {
        return [['/login'], ['/profile/security']];
    }

    private function marker(string $target, string $reason = 'post-login'): array
    {
        return ['id' => 'opaque-marker-id', 'reason' => $reason, 'issued_at' => now()->timestamp, 'target' => $target];
    }

    private function request(string $uri, string $method = 'GET', array $headers = []): Request
    {
        $request = Request::create('http://localhost'.$uri, $method);
        foreach ($headers as $key => $value) {
            $request->headers->set($key, $value);
        }
        $request->setLaravelSession(new Store('auth-splash-test', new ArraySessionHandler(120)));
        $request->setUserResolver(fn () => new User(['role' => 'admin']));
        $route = new Route([$method], ltrim($uri, '/'), fn () => null);
        $route->name('fixture');
        $request->setRouteResolver(fn () => $route);

        return $request;
    }
}
