<?php

namespace Tests\Feature;

use App\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustedProxiesConfigCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        TrustProxies::flushState();
        Route::middleware('web')->get('/_test/trusted-proxy-probe', function (Request $request) {
            return response()->json([
                'ip' => $request->ip(),
                'secure' => $request->isSecure(),
                'scheme' => $request->getScheme(),
            ]);
        });
    }

    protected function tearDown(): void
    {
        TrustProxies::flushState();
        parent::tearDown();
    }

    public function test_bootstrap_app_has_no_direct_env_calls_so_config_cache_preserves_trusted_proxies(): void
    {
        $bootstrap = (string) file_get_contents(base_path('bootstrap/app.php'));

        $this->assertDoesNotMatchRegularExpression('/\benv\s*\(/', $bootstrap);
        $this->assertArrayHasKey('trusted_proxies', config('auth_security'));
    }

    public function test_configured_trusted_proxies_honor_forwarded_headers_without_env_lookup(): void
    {
        config(['auth_security.trusted_proxies' => '10.0.0.5, 172.16.0.0/12']);

        $this->withServerVariables([
            'REMOTE_ADDR' => '10.0.0.5',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.42',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ])->getJson('/_test/trusted-proxy-probe')
            ->assertOk()
            ->assertExactJson([
                'ip' => '203.0.113.42',
                'secure' => true,
                'scheme' => 'https',
            ]);

        $this->withServerVariables([
            'REMOTE_ADDR' => '172.20.1.9',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.77',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ])->getJson('/_test/trusted-proxy-probe')
            ->assertOk()
            ->assertExactJson([
                'ip' => '198.51.100.77',
                'secure' => true,
                'scheme' => 'https',
            ]);
    }

    public function test_untrusted_client_and_wildcard_config_never_trust_spoofed_forwarded_headers(): void
    {
        config(['auth_security.trusted_proxies' => '10.0.0.5']);

        $this->withServerVariables([
            'REMOTE_ADDR' => '198.51.100.9',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.42',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ])->getJson('/_test/trusted-proxy-probe')
            ->assertOk()
            ->assertExactJson([
                'ip' => '198.51.100.9',
                'secure' => false,
                'scheme' => 'http',
            ]);

        $this->assertSame([], TrustProxies::parseConfiguredProxies('*, **, REMOTE_ADDR, 10.0.0.1/33, 2001:db8::/129, invalid'));
        $this->assertSame(['10.0.0.5', '172.16.0.0/12', '2001:db8::/32'], TrustProxies::parseConfiguredProxies('10.0.0.5, *, 172.16.0.0/12, 2001:db8::/32, 10.0.0.5'));

        config(['auth_security.trusted_proxies' => '*']);

        $this->withServerVariables([
            'REMOTE_ADDR' => '198.51.100.9',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.42',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ])->getJson('/_test/trusted-proxy-probe')
            ->assertOk()
            ->assertExactJson([
                'ip' => '198.51.100.9',
                'secure' => false,
                'scheme' => 'http',
            ]);
    }
}
