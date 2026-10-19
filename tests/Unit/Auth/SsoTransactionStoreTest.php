<?php

namespace Tests\Unit\Auth;

use App\Services\Auth\Sso\SsoTransactionStore;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SsoTransactionStoreTest extends TestCase
{
    private SsoTransactionStore $transactions;

    protected function setUp(): void
    {
        parent::setUp();

        // Only URL generation is under test here; no OAuth server or database is needed.
        Route::get('/oauth/authorize', fn () => '')->name('passport.authorizations.authorize');
        Route::get('/sso/callback', fn () => '')->name('sso.callback');
        Route::getRoutes()->refreshNameLookups();

        config([
            'sso.enabled' => true,
            'sso.client_id' => '8e7ac9e1-b552-47f7-8a46-df8a0c48b692',
            'sso.state_prefix' => 'ADASI',
            'sso.transaction_ttl_seconds' => 600,
            'sso.max_pending_transactions' => 5,
            'sso.scope' => 'openid',
        ]);

        $this->transactions = app(SsoTransactionStore::class);
        $this->freezeTime();
    }

    public function test_begin_creates_an_authorization_request_with_s256_and_server_side_context(): void
    {
        $request = $this->request();
        $url = $this->transactions->begin($request, '/profile/customization?tab=layout');
        $query = $this->authorizationQuery($url);
        $transaction = $request->session()->get('sso.transactions')[$query['state']];

        $this->assertSame('/oauth/authorize', parse_url($url, PHP_URL_PATH));
        $this->assertSame(config('sso.client_id'), $query['client_id']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame(route('sso.callback'), $query['redirect_uri']);
        $this->assertSame('openid', $query['scope']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertMatchesRegularExpression('/\AADASI~[A-Za-z0-9_-]{64}\z/', $query['state']);
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{86}\z/', $transaction['verifier']);
        $this->assertSame($this->base64Url(hash('sha256', $transaction['verifier'], true)), $query['code_challenge']);
        $this->assertSame('/profile/customization?tab=layout', $transaction['intended']);
        $this->assertSame(now()->timestamp, $transaction['created_at']);
        $this->assertSame(config('sso.client_id'), $transaction['client_id']);
        $this->assertSame(route('sso.callback'), $transaction['redirect_uri']);
        $this->assertArrayNotHasKey('intended', $query);
        $this->assertArrayNotHasKey('code_verifier', $query);
        $this->assertStringNotContainsString($transaction['verifier'], $url);
    }

    public function test_each_transaction_gets_a_distinct_state_and_verifier(): void
    {
        $request = $this->request();
        $first = $this->authorizationQuery($this->transactions->begin($request));
        $second = $this->authorizationQuery($this->transactions->begin($request));
        $pending = $request->session()->get('sso.transactions');

        $this->assertNotSame($first['state'], $second['state']);
        $this->assertNotSame($pending[$first['state']]['verifier'], $pending[$second['state']]['verifier']);
        $this->assertNotSame($first['code_challenge'], $second['code_challenge']);
        $this->assertCount(2, $pending);
    }

    public function test_state_uses_the_configured_prefix(): void
    {
        config(['sso.state_prefix' => 'ADASI_PRD']);
        $request = $this->request();
        $query = $this->authorizationQuery($this->transactions->begin($request));

        $this->assertMatchesRegularExpression('/\AADASI_PRD~[A-Za-z0-9_-]{64}\z/', $query['state']);
        $this->assertNotNull($this->transactions->pull($request, $query['state']));
    }

    public function test_pull_consumes_only_the_matching_transaction_once(): void
    {
        $request = $this->request();
        $first = $this->authorizationQuery($this->transactions->begin($request, '/profile'))['state'];
        $second = $this->authorizationQuery($this->transactions->begin($request, '/profile/customization'))['state'];

        $transaction = $this->transactions->pull($request, $first);

        $this->assertSame('/profile', $transaction['intended']);
        $this->assertNull($this->transactions->pull($request, $first));
        $this->assertArrayNotHasKey($first, $request->session()->get('sso.transactions', []));
        $this->assertSame('/profile/customization', $this->transactions->pull($request, $second)['intended']);
    }

    #[DataProvider('invalidStates')]
    public function test_invalid_states_do_not_consume_a_legitimate_transaction(mixed $state): void
    {
        $request = $this->request();
        $validState = $this->authorizationQuery($this->transactions->begin($request))['state'];

        $this->assertNull($this->transactions->pull($request, $state));
        $this->assertNotNull($this->transactions->pull($request, $validState));
    }

    public static function invalidStates(): array
    {
        return [
            'missing' => [null],
            'empty' => [''],
            'array' => [['ADASI~forged']],
            'integer' => [42],
            'boolean' => [false],
            'unknown state' => ['ADASI~'.str_repeat('A', 64)],
            'wrong prefix' => ['OTHER~'.str_repeat('B', 64)],
            'unbounded' => [str_repeat('x', 10000)],
        ];
    }

    public function test_a_changed_state_is_rejected_without_consuming_the_original(): void
    {
        $request = $this->request();
        $state = $this->authorizationQuery($this->transactions->begin($request))['state'];
        $forged = substr($state, 0, -1).(str_ends_with($state, 'A') ? 'B' : 'A');

        $this->assertNull($this->transactions->pull($request, $forged));
        $this->assertNotNull($this->transactions->pull($request, $state));
    }

    public function test_transaction_is_valid_until_the_ttl_deadline(): void
    {
        $request = $this->request();
        $state = $this->authorizationQuery($this->transactions->begin($request))['state'];

        $this->travel(599)->seconds();

        $this->assertNotNull($this->transactions->pull($request, $state));
    }

    public function test_transaction_expires_at_the_ttl_deadline_and_is_consumed(): void
    {
        $request = $this->request();
        $state = $this->authorizationQuery($this->transactions->begin($request))['state'];

        $this->travel(600)->seconds();

        $this->assertNull($this->transactions->pull($request, $state));
        $this->assertArrayNotHasKey($state, $request->session()->get('sso.transactions', []));
    }

    public function test_begin_discards_expired_transactions_before_storing_a_new_one(): void
    {
        $request = $this->request();
        $expired = $this->authorizationQuery($this->transactions->begin($request))['state'];
        $this->travel(600)->seconds();
        $current = $this->authorizationQuery($this->transactions->begin($request))['state'];

        $this->assertCount(1, $request->session()->get('sso.transactions'));
        $this->assertNull($this->transactions->pull($request, $expired));
        $this->assertNotNull($this->transactions->pull($request, $current));
    }

    public function test_pending_limit_evicts_the_oldest_transactions(): void
    {
        $request = $this->request();
        $states = [];

        for ($index = 0; $index < 7; $index++) {
            $states[] = $this->authorizationQuery($this->transactions->begin($request))['state'];
            $this->travel(1)->seconds();
        }

        $this->assertCount(5, $request->session()->get('sso.transactions'));
        $this->assertNull($this->transactions->pull($request, $states[0]));
        $this->assertNull($this->transactions->pull($request, $states[1]));

        foreach (array_slice($states, 2) as $state) {
            $this->assertNotNull($this->transactions->pull($request, $state));
        }
    }

    #[DataProvider('invalidCreatedAtValues')]
    public function test_future_or_malformed_timestamps_fail_closed_and_are_consumed(mixed $createdAt): void
    {
        $request = $this->request();
        $state = $this->authorizationQuery($this->transactions->begin($request))['state'];
        $pending = $request->session()->get('sso.transactions');
        $pending[$state]['created_at'] = $createdAt === 'future' ? now()->addSecond()->timestamp : $createdAt;
        $request->session()->put('sso.transactions', $pending);

        $this->assertNull($this->transactions->pull($request, $state));
        $this->assertArrayNotHasKey($state, $request->session()->get('sso.transactions', []));
    }

    public static function invalidCreatedAtValues(): array
    {
        return [
            'future' => ['future'],
            'null' => [null],
            'numeric string' => ['1234567890'],
            'array' => [[]],
            'zero' => [0],
            'negative' => [-1],
            'boolean' => [true],
        ];
    }

    #[DataProvider('unsafeIntendedUrls')]
    public function test_unsafe_final_destinations_fall_back_on_begin_and_direct_filter(mixed $intended): void
    {
        $request = $this->request();
        $fallback = route('dashboard', absolute: false);

        $this->assertSame($fallback, $this->transactions->safeIntendedUrl($request, $intended));

        if (is_string($intended) || $intended === null) {
            $state = $this->authorizationQuery($this->transactions->begin($request, $intended))['state'];
            $this->assertSame($fallback, $this->transactions->pull($request, $state)['intended']);
        }
    }

    public static function unsafeIntendedUrls(): array
    {
        return [
            'missing' => [null],
            'empty' => [''],
            'array' => [['/profile']],
            'integer' => [42],
            'external host' => ['https://evil.example/profile'],
            'scheme relative' => ['//evil.example/profile'],
            'userinfo host confusion' => ['http://localhost@evil.example/profile'],
            'userinfo on same host' => ['http://evil.example@localhost/profile'],
            'different scheme' => ['https://localhost/profile'],
            'different port' => ['http://localhost:8080/profile'],
            'javascript scheme' => ['javascript:alert(1)'],
            'backslash authority' => ['/\\evil.example/profile'],
            'encoded backslash' => ['/%5cevil.example/profile'],
            'encoded authority' => ['/%2f%2fevil.example/profile'],
            'newline' => ["/profile\nLocation: https://evil.example"],
            'encoded newline' => ['/profile%0d%0aLocation:evil'],
            'oauth authorize' => ['/oauth/authorize?state=opaque'],
            'sso callback' => ['/sso/callback?code=opaque&state=opaque'],
            'login' => ['/login?intended=/profile'],
            'logout' => ['/logout'],
            'mfa challenge' => ['/two-factor-challenge'],
            'password confirmation' => ['/confirm-password'],
            'encoded login' => ['/%6cogin'],
            'dot segment callback' => ['/profile/../sso/callback'],
            'notification polling' => ['/notifications/unread-count'],
            'conversation polling' => ['/conversations/opaque/messages/latest'],
            'export polling' => ['/exports/opaque/status'],
            'attachment' => ['/attachments/opaque'],
            'api' => ['/api/users'],
        ];
    }

    #[DataProvider('safeIntendedUrls')]
    public function test_valid_page_destinations_preserve_the_path_and_query(string $intended, string $expected): void
    {
        $request = $this->request();
        $state = $this->authorizationQuery($this->transactions->begin($request, $intended))['state'];

        $this->assertSame($expected, $this->transactions->safeIntendedUrl($request, $intended));
        $this->assertSame($expected, $this->transactions->pull($request, $state)['intended']);
    }

    public static function safeIntendedUrls(): array
    {
        return [
            'relative page' => ['/profile', '/profile'],
            'relative query' => ['/profile/customization?tab=layout&filter=a%2Fb', '/profile/customization?tab=layout&filter=a%2Fb'],
            'same origin absolute' => ['http://localhost/profile/customization?tab=layout', '/profile/customization?tab=layout'],
        ];
    }

    private function request(): Request
    {
        $request = Request::create('http://localhost/login');
        $request->setLaravelSession(new Store('sso-transactions-test', new ArraySessionHandler(120)));

        return $request;
    }

    private function authorizationQuery(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertArrayHasKey('state', $query);

        return $query;
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
