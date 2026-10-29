<?php

namespace Tests\Feature\Auth;

use App\Models\AuthAuditLog;
use App\Models\User;
use App\Services\Auth\Sso\SsoTransactionStore;
use App\Services\Auth\TwoFactorService;
use Defuse\Crypto\Crypto;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Passport\Bridge\AccessTokenRepository;
use Laravel\Passport\Bridge\RefreshTokenRepository;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use League\OAuth2\Server\AuthorizationServer;
use Mockery\MockInterface;
use phpseclib4\Crypt\RSA;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Tests\TestCase;

class SsoAuthorizationFlowTest extends TestCase
{
    use RefreshDatabase;

    private static ?array $keys = null;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$keys === null) {
            // Generate only in memory; the bundled OpenSSL config writes .rnd to cwd.
            RSA::forceEngine('PHP');
            try {
                $privateKey = RSA::createKey(2048);
            } finally {
                RSA::forceEngine();
            }
            self::$keys = [
                'private' => $privateKey->toString('PKCS8'),
                'public' => $privateKey->getPublicKey()->toString('PKCS8'),
            ];
        }

        config([
            'passport.private_key' => self::$keys['private'],
            'passport.public_key' => self::$keys['public'],
            'sso.enabled' => true,
            'sso.state_prefix' => 'ADASI',
            'sso.transaction_ttl_seconds' => 600,
            'sso.max_pending_transactions' => 5,
            'sso.scope' => 'openid',
            'ui.auth_splash.enabled' => false,
        ]);

        $this->client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
            'ADASI Portal', [route('sso.callback')], confidential: false,
        );
        config(['sso.client_id' => (string) $this->client->getKey()]);
    }

    public function test_guest_completes_the_full_authorization_login_callback_and_dashboard_flow(): void
    {
        Http::preventStrayRequests();
        $user = User::factory()->create(['role' => 'admin']);
        $this->get('/dashboard')->assertRedirect(route('login'));
        $transaction = $this->start();

        $query = $this->query($transaction['authorize']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('openid', $query['scope']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertMatchesRegularExpression('/\AADASI~[A-Za-z0-9_-]{64}\z/', $query['state']);
        $this->assertSame($this->base64Url(hash('sha256', $transaction['verifier'], true)), $query['code_challenge']);

        $this->get($transaction['authorize'])->assertOk()->assertViewIs('auth.login')
            ->assertSee('action="'.route('login.store').'"', false)
            ->assertSessionHas('url.intended', $transaction['authorize']);
        $this->assertGuest();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect($transaction['authorize']);
        $callback = $this->issueCallback($transaction);
        $this->get($callback)->assertRedirect('/dashboard')
            ->assertSessionHas('sso.verified_at')
            ->assertSessionHas('sso.client_id', (string) $this->client->getKey());
        $this->get('/dashboard')->assertRedirect(route('admin.dashboard'));
        $this->get(route('admin.dashboard'))->assertOk();

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('auth_known_devices', ['user_id' => $user->id]);
        $this->assertDatabaseHas('auth_audit_logs', ['user_id' => $user->id, 'event' => 'sso_authorization_completed']);
        $this->assertGreaterThan(0, DB::table('oauth_access_tokens')->count());
        $this->assertGreaterThan(0, DB::table('oauth_refresh_tokens')->count());
        $this->assertNoActiveTokens();
        Http::assertNothingSent();
    }

    public function test_login_page_and_sso_authorization_is_accessible_via_localhost_8000(): void
    {
        Http::preventStrayRequests();
        $user = User::factory()->create(['role' => 'admin']);

        // 1. Visit /login on localhost:8000
        $response = $this->get('http://localhost:8000/login')->assertRedirect();
        $authorizeUrl = Request::create($response->headers->get('Location'))->fullUrl();
        $this->assertStringStartsWith('http://localhost:8000/oauth/authorize', $authorizeUrl);

        $query = $this->query($authorizeUrl);
        $this->assertSame('http://localhost:8000/sso/callback', $query['redirect_uri']);

        // 2. Guest visits the authorize URL on localhost:8000 -> renders login page with 200 OK
        $this->get($authorizeUrl)->assertOk()->assertViewIs('auth.login');

        // 3. User authenticates on localhost:8000
        $this->post('http://localhost:8000/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect($authorizeUrl);

        // 4. Authenticated user visits authorize URL -> issues auth code and redirects to callback on localhost:8000
        $authResponse = $this->get($authorizeUrl)->assertRedirect();
        $callbackUrl = $authResponse->headers->get('Location');
        $this->assertStringStartsWith('http://localhost:8000/sso/callback', $callbackUrl);

        // 5. Callback completes SSO exchange and redirects to dashboard
        $this->get($callbackUrl)->assertRedirect('/dashboard')
            ->assertSessionHas('sso.verified_at');
    }

    public function test_a_deep_link_and_its_query_are_preserved_through_login(): void
    {
        $intended = '/profile/customization?tab=layout';
        $user = User::factory()->create(['role' => 'admin']);
        $this->get($intended)->assertRedirect(route('login'));
        $transaction = $this->start();
        $this->get($transaction['authorize'])->assertOk();
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->get($this->issueCallback($transaction))->assertRedirect($intended);
        $this->get($intended)->assertOk();
        $this->assertNoActiveTokens();
    }

    public function test_failed_credentials_return_to_the_same_authorization_form_with_errors(): void
    {
        $user = User::factory()->create();
        $transaction = $this->start();
        $this->get($transaction['authorize'])->assertOk();
        $this->from($transaction['authorize'])->post('/login', [
            'email' => $user->email, 'password' => 'incorrect',
        ])->assertRedirect($transaction['authorize'])->assertSessionHasErrors('email');

        $this->get($transaction['authorize'])->assertOk()->assertViewIs('auth.login')
            ->assertSee($user->email)->assertSessionMissing('sso.verified_at');
        $this->assertGuest();
        $this->assertSame(0, DB::table('oauth_auth_codes')->count());
    }

    public function test_two_factor_authentication_completes_before_authorization_code_issuance(): void
    {
        [$user, $secret] = $this->mfaUser();
        $transaction = $this->start('/profile');
        $this->get($transaction['authorize'])->assertOk();
        $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => '1'])
            ->assertRedirect(route('two-factor.challenge'))
            ->assertSessionHas(TwoFactorService::PENDING_LOGIN_KEY);
        $this->assertGuest();
        $this->assertSame(0, DB::table('oauth_auth_codes')->count());

        $this->post('/two-factor-challenge', [
            'code' => app(TwoFactorService::class)->authenticator()->getCurrentOtp($secret),
        ])->assertRedirect($transaction['authorize']);

        $this->get($this->issueCallback($transaction))->assertRedirect('/profile')
            ->assertSessionHas('sso.verified_at')
            ->assertSessionMissing(TwoFactorService::PENDING_LOGIN_KEY);
        $this->assertAuthenticatedAs($user);
        $this->assertNoActiveTokens();
    }

    public function test_remember_cookie_requires_mfa_then_safely_restarts_the_lost_transaction(): void
    {
        [$user, $secret] = $this->mfaUser();
        $transaction = $this->start('/profile');
        $guard = Auth::guard('web');
        $recaller = $user->id.'|'.$user->remember_token.'|'.$guard->hashPasswordForCookie($user->getAuthPassword());

        $this->withCookie($guard->getRecallerName(), $recaller)->get($transaction['authorize'])
            ->assertRedirect(route('two-factor.challenge'))
            ->assertSessionHas(TwoFactorService::PENDING_LOGIN_KEY)
            ->assertSessionMissing('sso.transactions');
        $this->assertGuest();
        $this->post('/two-factor-challenge', [
            'code' => app(TwoFactorService::class)->authenticator()->getCurrentOtp($secret),
        ])->assertRedirect($transaction['authorize']);
        $this->assertAuthenticatedAs($user);
        Auth::forgetGuards();

        $this->get($this->issueCallback($transaction))->assertRedirect(route('login'))
            ->assertSessionMissing('sso.verified_at');
        $this->completeRestart();
        $this->assertNoActiveTokens();
    }

    #[DataProvider('invalidAuthorizationRequests')]
    public function test_invalid_authorization_requests_return_400_without_a_location_header(array $overrides, array $remove = []): void
    {
        $transaction = $this->start();
        $query = array_replace($this->query($transaction['authorize']), $overrides);
        foreach ($remove as $key) {
            unset($query[$key]);
        }

        $response = $this->get($this->authorizeUrl($query));

        $response->assertStatus(400)->assertViewIs('auth.sso-error')
            ->assertHeaderMissing('Location')->assertDontSee('name="password"', false);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertDatabaseHas('auth_audit_logs', ['event' => 'sso_invalid_authorization_request']);
        $this->assertSame(0, DB::table('oauth_auth_codes')->count());
    }

    public static function invalidAuthorizationRequests(): array
    {
        return [
            'unknown client' => [['client_id' => 'e1ab02ef-057e-4975-a663-6c728416ac33']],
            'unregistered redirect' => [['redirect_uri' => 'https://evil.example/callback']],
            'missing PKCE' => [[], ['code_challenge', 'code_challenge_method']],
            'plain PKCE' => [['code_challenge_method' => 'plain']],
            'implicit response' => [['response_type' => 'token']],
            'unknown scope' => [['scope' => 'unregistered-scope']],
            'array client' => [['client_id' => ['invalid']]],
            'missing response type' => [[], ['response_type']],
        ];
    }

    public function test_registered_redirect_must_match_exactly(): void
    {
        $query = $this->query($this->start()['authorize']);
        $query['redirect_uri'] .= '/';

        $this->get($this->authorizeUrl($query))->assertStatus(400)->assertHeaderMissing('Location');
    }

    public function test_only_the_configured_first_party_client_skips_consent(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $other = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
            'Another application', [route('sso.callback')], confidential: false,
        );
        $query = $this->query($this->start()['authorize']);
        $query['client_id'] = (string) $other->getKey();

        $response = $this->actingAs($user)->get($this->authorizeUrl($query));

        $response->assertOk()->assertViewIs('auth.sso-authorize')
            ->assertSessionHas('authToken')->assertSessionMissing('sso.verified_at');
        $this->assertSame(0, DB::table('oauth_auth_codes')->count());
    }

    #[DataProvider('malformedCallbackStates')]
    public function test_forged_or_malformed_callback_state_clears_verification_and_restarts(mixed $state): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->withSession(['sso.verified_at' => now()->timestamp, 'sso.client_id' => $this->client->getKey()]);

        $this->get($this->callbackUrl(['state' => $state, 'code' => 'invalid-code']))
            ->assertRedirect(route('login'))->assertSessionMissing('sso.verified_at');

        $this->assertDatabaseHas('auth_audit_logs', ['event' => 'sso_state_rejected']);
        $this->assertNoActiveTokens();
    }

    public static function malformedCallbackStates(): array
    {
        return [
            'forged' => ['ADASI~'.str_repeat('A', 64)],
            'missing' => [null],
            'array' => [['invalid']],
            'unbounded' => [str_repeat('x', 10000)],
        ];
    }

    public function test_callback_state_cannot_be_reused(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $transaction = $this->start();
        $this->actingAs($user);
        $callback = $this->issueCallback($transaction);
        $this->get($callback)->assertSessionHas('sso.verified_at');
        $issued = DB::table('oauth_access_tokens')->count();

        $this->get($callback)->assertRedirect(route('login'))->assertSessionMissing('sso.verified_at');

        $this->assertSame($issued, DB::table('oauth_access_tokens')->count());
        $this->assertNoActiveTokens();
    }

    public function test_callback_state_expires_at_its_ttl_deadline(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $transaction = $this->start();
        $this->actingAs($user);
        $callback = $this->issueCallback($transaction);
        $this->travel(600)->seconds();

        $this->get($callback)->assertRedirect(route('login'))->assertSessionMissing('sso.verified_at');

        $this->assertDatabaseHas('auth_audit_logs', ['event' => 'sso_state_rejected']);
        $this->assertNoActiveTokens();
    }

    public function test_a_reused_code_is_rejected_even_with_a_new_valid_state(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $transaction = $this->start();
        $this->actingAs($user);
        $callback = $this->issueCallback($transaction);
        $this->get($callback)->assertSessionHas('sso.verified_at');
        $originalCode = $this->query($callback)['code'];
        $next = $this->newTransaction();

        $this->get($this->callbackUrl(['state' => $next['state'], 'code' => $originalCode]))
            ->assertRedirect(route('login'))->assertSessionMissing('sso.verified_at');

        $this->assertNoActiveTokens();
    }

    public function test_a_wrong_verifier_cannot_exchange_an_authorization_code(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $transaction = $this->start();
        $this->actingAs($user);
        $callback = $this->issueCallback($transaction);
        $pending = session('sso.transactions');
        $pending[$transaction['state']]['verifier'] = $this->base64Url(random_bytes(64));
        $this->withSession(['sso.transactions' => $pending]);

        $this->get($callback)->assertRedirect(route('login'))->assertSessionMissing('sso.verified_at');

        $this->assertNoActiveTokens();
    }

    public function test_an_expired_code_is_rejected_while_its_session_transaction_is_valid(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $transaction = $this->start();
        $this->actingAs($user);
        $query = $this->query($this->issueCallback($transaction));
        $key = Passport::tokenEncryptionKey(app('encrypter'));
        $payload = json_decode(Crypto::decryptWithPassword($query['code'], $key), true, flags: JSON_THROW_ON_ERROR);
        // League uses PHP time(), independently of Laravel's travel clock.
        $payload['expire_time'] = time() - 1;
        $query['code'] = Crypto::encryptWithPassword(json_encode($payload, JSON_THROW_ON_ERROR), $key);

        $this->get($this->callbackUrl($query))->assertRedirect(route('login'))->assertSessionMissing('sso.verified_at');

        $this->assertNoActiveTokens();
    }

    public function test_access_denied_consumes_state_and_restarts_without_verification(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $transaction = $this->start();
        $this->actingAs($user);

        $this->get($this->callbackUrl(['state' => $transaction['state'], 'error' => 'access_denied']))
            ->assertRedirect(route('login'))->assertSessionMissing('sso.verified_at');

        $this->assertArrayNotHasKey($transaction['state'], session('sso.transactions', []));
        $this->assertNoActiveTokens();
    }

    public function test_a_code_issued_to_another_subject_logs_out_and_leaves_no_active_tokens(): void
    {
        $subject = User::factory()->create(['role' => 'admin']);
        $other = User::factory()->create(['role' => 'admin']);
        $transaction = $this->start();
        $this->actingAs($subject);
        $callback = $this->issueCallback($transaction);
        $this->actingAs($other);

        $this->get($callback)->assertRedirect(route('login'))->assertSessionMissing('sso.verified_at');

        $this->assertGuest();
        $this->assertDatabaseHas('auth_audit_logs', ['event' => 'sso_subject_mismatch']);
        $this->assertNoActiveTokens();
    }

    #[DataProvider('invalidJwtResponses')]
    public function test_invalid_jwt_responses_never_verify_a_session_or_leave_active_tokens(string $change): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $transaction = $this->start();
        $this->actingAs($user);
        $callback = $this->issueCallback($transaction);
        $server = app(AuthorizationServer::class);
        $realResponseIssued = false;
        $modifiedResponseReturned = false;
        $this->mock(AuthorizationServer::class, function (MockInterface $mock) use ($server, $change, &$realResponseIssued, &$modifiedResponseReturned): void {
            $mock->shouldReceive('respondToAccessTokenRequest')->once()
                ->andReturnUsing(function (ServerRequestInterface $request, ResponseInterface $response) use ($server, $change, &$realResponseIssued, &$modifiedResponseReturned): ResponseInterface {
                    $issued = $server->respondToAccessTokenRequest($request, $response);
                    $payload = json_decode((string) $issued->getBody(), true, flags: JSON_THROW_ON_ERROR);
                    $originalToken = $payload['access_token'];
                    $realResponseIssued = is_string($originalToken)
                        && DB::table('oauth_access_tokens')->where('revoked', false)->exists();
                    if ($change === 'audience') {
                        $claims = (array) JWT::decode($payload['access_token'], new Key(self::$keys['public'], 'RS256'));
                        $claims['aud'] = ['another-client'];
                        $payload['access_token'] = JWT::encode($claims, self::$keys['private'], 'RS256');
                    } else {
                        $parts = explode('.', $payload['access_token']);
                        $parts[2][0] = $parts[2][0] === 'A' ? 'B' : 'A';
                        $payload['access_token'] = implode('.', $parts);
                    }

                    $modified = $issued->withBody(Utils::streamFor(json_encode($payload, JSON_THROW_ON_ERROR)));
                    $modifiedResponseReturned = ! hash_equals($originalToken, $payload['access_token']);

                    return $modified;
                });
        });

        $this->get($callback)->assertRedirect(route('login'))->assertSessionMissing('sso.verified_at');

        // Assert outside the callback: its catch(Throwable) must not swallow a fixture error.
        $this->assertTrue($realResponseIssued, 'The real server must issue a persisted token before JWT mutation.');
        $this->assertTrue($modifiedResponseReturned, 'The modified JWT response must reach the callback.');
        $this->assertNoActiveTokens();
        if ($change === 'audience') {
            $this->assertGuest();
            $this->assertGreaterThan(0, DB::table('oauth_access_tokens')->count());
            $this->assertGreaterThan(0, DB::table('oauth_refresh_tokens')->count());
            $this->assertDatabaseHas('auth_audit_logs', ['event' => 'sso_subject_mismatch']);
        } else {
            $this->assertSame(0, DB::table('oauth_access_tokens')->count());
            $this->assertSame(0, DB::table('oauth_refresh_tokens')->count());
            $this->assertDatabaseHas('auth_audit_logs', ['event' => 'sso_state_rejected']);
        }
        $this->assertDatabaseMissing('auth_audit_logs', ['event' => 'sso_authorization_completed']);
    }

    public static function invalidJwtResponses(): array
    {
        return [['audience'], ['signature']];
    }

    #[DataProvider('failedRevocations')]
    public function test_revocation_failure_rolls_back_token_issuance_and_never_verifies_the_session(string $repository, string $method): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $transaction = $this->start();
        $this->actingAs($user);
        $callback = $this->issueCallback($transaction);
        // The already-resolved server retains its real issuance repositories.
        // Only the callback's explicit revocation collaborator is made to fail.
        $this->mock($repository, function (MockInterface $mock) use ($method): void {
            $mock->shouldReceive($method)->once()->andThrow(new RuntimeException('Synthetic revocation failure.'));
        });

        $this->get($callback)->assertRedirect(route('login'))->assertSessionMissing('sso.verified_at');

        $this->assertSame(0, DB::table('oauth_access_tokens')->count());
        $this->assertSame(0, DB::table('oauth_refresh_tokens')->count());
        $this->assertDatabaseMissing('auth_audit_logs', ['event' => 'sso_authorization_completed']);
        $this->assertDatabaseHas('auth_audit_logs', ['event' => 'sso_state_rejected']);
    }

    public static function failedRevocations(): array
    {
        return [
            'access token' => [AccessTokenRepository::class, 'revokeAccessToken'],
            'refresh token' => [RefreshTokenRepository::class, 'revokeRefreshToken'],
        ];
    }

    public function test_clean_authorization_login_uses_native_login_splash_readiness(): void
    {
        config(['ui.auth_splash.enabled' => true]);
        $transaction = $this->start();

        $this->get($transaction['authorize'])->assertOk()->assertViewIs('auth.login')
            ->assertSee('data-splash-reason="login-page"', false)
            ->assertSee('data-readiness="native-login"', false)
            ->assertSee('<template data-auth-splash-layout-ready="auth" data-readiness="native-login">', false)
            ->assertSessionMissing('ui.auth_splash');
    }

    public function test_invalid_authorization_errors_never_render_splash_markup(): void
    {
        config(['ui.auth_splash.enabled' => true]);
        $transaction = $this->start();
        $query = $this->query($transaction['authorize']);
        $query['redirect_uri'] = 'https://evil.example/callback';

        $this->get($this->authorizeUrl($query))->assertStatus(400)->assertViewIs('auth.sso-error')
            ->assertHeaderMissing('Location')->assertDontSee('data-auth-splash', false)
            ->assertSessionMissing('ui.auth_splash');
    }

    public function test_client_installation_reuses_the_existing_matching_public_client_idempotently(): void
    {
        config(['sso.client_id' => '']);
        $count = Passport::client()->newQuery()->count();
        $expectedId = (string) $this->client->getKey();

        foreach (range(1, 2) as $attempt) {
            $exitCode = Artisan::call('sso:install-client');
            $output = Artisan::output();
            $this->assertSame(0, $exitCode, $output);
            $this->assertStringContainsString('SSO_CLIENT_ID='.$expectedId, $output);
            $this->assertSame($count, Passport::client()->newQuery()->count());
        }
    }

    public function test_existing_authenticated_session_gets_a_transaction_without_a_guest_redirect_loop(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($user)->get('/profile/customization?tab=layout');
        $response->assertRedirect();
        $this->assertSame('/oauth/authorize', parse_url($response->headers->get('Location'), PHP_URL_PATH));
        $transaction = $this->transactionFromUrl($response->headers->get('Location'));

        $this->get($this->issueCallback($transaction))->assertRedirect('/profile/customization?tab=layout')
            ->assertSessionHas('sso.verified_at');
        $this->get('/profile/customization?tab=layout')->assertOk();
    }

    #[DataProvider('backgroundRequests')]
    public function test_unverified_session_background_requests_receive_401_without_a_redirect(string $uri, bool $json): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user);
        $response = $json ? $this->getJson($uri) : $this->get($uri);

        $response->assertUnauthorized()->assertHeaderMissing('Location')->assertSessionMissing('sso.transactions');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertAuthenticatedAs($user);
    }

    public static function backgroundRequests(): array
    {
        return [['/profile', true], ['/notifications/unread-count', false], ['/conversations/unread-count', false]];
    }

    public function test_forced_password_change_is_enforced_and_the_original_destination_survives(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'must_change_password' => true]);
        $transaction = $this->start('/profile/customization?tab=layout');
        $this->get($transaction['authorize'])->assertOk();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect($transaction['authorize']);
        $this->get($transaction['authorize'])->assertRedirect(route('profile.security'));
        $this->get(route('profile.security'))->assertOk();
        $this->assertNull(session('sso.verified_at'));
        $this->from(route('profile.security'))->put('/password', [
            'current_password' => 'password',
            'password' => 'Updated!Password246',
            'password_confirmation' => 'Updated!Password246',
        ])->assertSessionHasNoErrors()->assertRedirect(route('profile.security'));
        $this->assertFalse($user->fresh()->must_change_password);

        $response = $this->get('/dashboard')->assertRedirect();
        $transaction = $this->transactionFromUrl($response->headers->get('Location'));
        $this->get($this->issueCallback($transaction))->assertRedirect('/profile/customization?tab=layout')
            ->assertSessionHas('sso.verified_at');
        $this->assertNoActiveTokens();
    }

    public function test_public_registration_remains_accessible_without_sso_verification(): void
    {
        $this->get(route('supplier.register'))->assertOk();
        $this->get(route('supplier.registration.access-form'))->assertOk();
    }

    public function test_flag_off_preserves_the_existing_login_form_and_session_access(): void
    {
        config(['sso.enabled' => false]);
        $this->get('/login')->assertOk()->assertViewIs('auth.login')
            ->assertSee('action="'.route('login.store').'"', false)
            ->assertSessionMissing('sso.transactions');
        $user = User::factory()->create(['role' => 'admin']);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/dashboard');
        $this->get('/profile')->assertOk()->assertSessionMissing('sso.verified_at');
        $this->assertAuthenticatedAs($user);
    }

    #[DataProvider('oauthEndpoints')]
    public function test_flag_off_disables_every_oauth_and_callback_endpoint(string $method, string $uri): void
    {
        config(['sso.enabled' => false]);
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)->call($method, $uri)->assertNotFound()->assertSessionMissing('sso.verified_at');
        $this->assertSame(0, DB::table('oauth_auth_codes')->count());
        $this->assertNoActiveTokens();
    }

    public static function oauthEndpoints(): array
    {
        return [
            'authorization form' => ['GET', '/oauth/authorize'],
            'authorization approval' => ['POST', '/oauth/authorize'],
            'authorization denial' => ['DELETE', '/oauth/authorize'],
            'token exchange' => ['POST', '/oauth/token'],
            'callback' => ['GET', '/sso/callback'],
        ];
    }

    public function test_the_public_token_endpoint_does_not_require_a_web_session_or_echo_secrets(): void
    {
        $sentinel = 'never-echo-this-authorization-code';
        $response = $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => (string) $this->client->getKey(),
            'redirect_uri' => route('sso.callback'),
            'code' => $sentinel,
            'code_verifier' => $this->base64Url(random_bytes(64)),
        ]);

        $response->assertStatus(400)->assertHeaderMissing('Location');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $response->assertHeader('Pragma', 'no-cache')->assertCookieMissing(config('session.cookie'));
        $this->assertTrue(! str_contains($response->getContent(), $sentinel), 'OAuth errors must not echo submitted credentials.');
        $this->assertNoActiveTokens();
    }

    public function test_token_endpoint_configuration_failures_are_generic_private_and_do_not_log_secrets(): void
    {
        Log::spy();
        $this->mock(AuthorizationServer::class, function (MockInterface $mock): void {
            $mock->shouldReceive('respondToAccessTokenRequest')->once()
                ->andThrow(new RuntimeException('secret-code-that-must-never-be-rendered'));
        });

        $response = $this->postJson('/oauth/token', ['grant_type' => 'authorization_code']);

        $response->assertStatus(503)->assertExactJson(['error' => 'server_error'])
            ->assertHeader('Pragma', 'no-cache')->assertHeaderMissing('Location');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertNoActiveTokens();
        Log::shouldNotHaveReceived('error');
    }

    public function test_success_and_failure_audits_and_application_logs_omit_oauth_secrets(): void
    {
        Log::spy();
        $user = User::factory()->create(['role' => 'admin']);
        $transaction = $this->start();
        $this->actingAs($user);
        $callback = $this->issueCallback($transaction);
        $code = $this->query($callback)['code'];
        $this->get($callback)->assertSessionHas('sso.verified_at');
        $this->get($callback)->assertSessionMissing('sso.verified_at');
        $serialized = AuthAuditLog::query()->get()->toJson();
        $secrets = [$transaction['state'], $transaction['verifier'], $code];

        foreach ($secrets as $secret) {
            $this->assertTrue(! str_contains($serialized, $secret), 'Audit rows must omit OAuth credentials.');
        }
        $audit = AuthAuditLog::query()->where('event', 'sso_authorization_completed')->firstOrFail();
        $this->assertSame((string) $this->client->getKey(), $audit->metadata['client_id']);
        foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency', 'log'] as $level) {
            Log::shouldNotHaveReceived($level, function (...$arguments) use ($secrets): bool {
                $encoded = json_encode($arguments);
                foreach ($secrets as $secret) {
                    if (is_string($encoded) && str_contains($encoded, $secret)) {
                        return true;
                    }
                }

                return false;
            });
        }
        $this->assertNoActiveTokens();
    }

    private function start(?string $intended = null): array
    {
        if ($intended !== null) {
            $this->withSession(['url.intended' => $intended]);
        }
        $response = $this->get('/login')->assertRedirect();

        return $this->transactionFromUrl($response->headers->get('Location'));
    }

    private function newTransaction(): array
    {
        $request = Request::create('http://localhost/login');
        $request->setLaravelSession(app('session')->driver());

        return $this->transactionFromUrl(app(SsoTransactionStore::class)->begin($request));
    }

    private function transactionFromUrl(string $url): array
    {
        // Symfony normalizes query ordering when a request becomes fullUrl().
        // Keep exact redirect/session assertions while using that same canonical URL.
        $url = Request::create($url)->fullUrl();
        $this->assertSame('/oauth/authorize', parse_url($url, PHP_URL_PATH));
        $query = $this->query($url);
        $pending = session('sso.transactions', []);
        $this->assertTrue(isset($pending[$query['state']]), 'Authorization state must have server-side context.');

        return ['authorize' => $url, 'state' => $query['state'], 'verifier' => $pending[$query['state']]['verifier']];
    }

    private function issueCallback(array $transaction): string
    {
        $response = $this->get($transaction['authorize'])->assertRedirect();
        $callback = $response->headers->get('Location');
        $this->assertSame('/sso/callback', parse_url($callback, PHP_URL_PATH));
        $query = $this->query($callback);
        $this->assertTrue(isset($query['code']) && is_string($query['code']), 'Authorization must return a code.');
        $this->assertTrue(isset($query['state']) && hash_equals($transaction['state'], $query['state']), 'Authorization state must match.');

        return $callback;
    }

    private function completeRestart(): void
    {
        for ($redirects = 0, $url = route('login'); $redirects < 6; $redirects++) {
            $response = $this->get($url);
            if (session()->has('sso.verified_at')) {
                $this->assertTrue($response->isRedirection() || $response->isOk());

                return;
            }
            $response->assertRedirect();
            $url = $response->headers->get('Location');
        }

        $this->fail('Authenticated SSO restart must terminate without a redirect loop.');
    }

    private function mfaUser(): array
    {
        $secret = app(TwoFactorService::class)->authenticator()->generateSecretKey(32);
        $user = User::factory()->create(['role' => 'admin']);
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => [],
            'two_factor_confirmed_at' => now(),
            'two_factor_last_used_step' => null,
        ])->saveQuietly();

        return [$user, $secret];
    }

    private function query(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }

    private function authorizeUrl(array $query): string
    {
        return route('passport.authorizations.authorize').'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function callbackUrl(array $query): string
    {
        return route('sso.callback').'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function assertNoActiveTokens(): void
    {
        $this->assertSame(0, DB::table('oauth_access_tokens')->where('revoked', false)->count());
        $this->assertSame(0, DB::table('oauth_refresh_tokens')->where('revoked', false)->count());
    }
}
