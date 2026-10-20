<?php

namespace Tests\Unit;

use App\Services\Auth\TwoFactorService;
use App\Support\PortalPath;
use App\Support\PurchasingNavigation;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class PortalPathTest extends TestCase
{
    public function test_disabled_prefix_preserves_root_paths(): void
    {
        config(['portal_urls.prefix.enabled' => false]);

        $this->assertFalse(PortalPath::enabled());
        $this->assertSame('', PortalPath::prefix());
        $this->assertSame('/purchasing/dashboard', PortalPath::path('purchasing/dashboard'));
        $this->assertSame('/purchasing/', PortalPath::path('/purchasing/'));
    }

    public function test_enabled_prefix_and_known_legacy_paths_use_segment_boundaries(): void
    {
        config([
            'portal_urls.prefix.enabled' => true,
            'portal_urls.prefix.value' => 'erp/ADASI_TST',
            'portal_urls.prefix.legacy_values' => ['v2/ADASI_PRD'],
        ]);

        $this->assertSame('erp/ADASI_TST', PortalPath::prefix());
        $this->assertSame('/erp/ADASI_TST/purchasing/', PortalPath::path('purchasing/'));
        $this->assertSame('/purchasing/dashboard', PortalPath::strip('/erp/ADASI_TST/purchasing/dashboard'));
        $this->assertSame('/profile', PortalPath::strip('/v2/ADASI_PRD/profile'));
        $this->assertSame('/', PortalPath::strip('/erp/ADASI_TST'));
        $this->assertSame('/erp/ADASI_TST_other/profile', PortalPath::strip('/erp/ADASI_TST_other/profile'));
        $this->assertSame('/profile', PortalPath::strip('/profile'));

        config(['portal_urls.prefix.enabled' => false]);
        $this->assertSame('/profile', PortalPath::strip('/erp/ADASI_TST/profile'));
    }

    #[DataProvider('invalidPrefixes')]
    public function test_invalid_or_reserved_prefix_is_rejected(string $prefix): void
    {
        config(['portal_urls.prefix.enabled' => true, 'portal_urls.prefix.value' => $prefix]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PORTAL_PATH_PREFIX');
        PortalPath::assertValid();
    }

    public static function invalidPrefixes(): array
    {
        return array_map(fn (string $value): array => [$value], [
            '', '/v2/ADASI_PRD', 'v2/ADASI_PRD/', 'v2//ADASI_PRD', 'one/two/three/four/five',
            str_repeat('a', 65), 'v2/../ADASI', 'v2/hello world', 'v2/%2f', "v2/ADASI\n",
            'https://portal.test', 'login/ADASI', 'LOGIN/ADASI', 'logout', 'two-factor-challenge',
            'forgot-password', 'confirm-password', 'password', 'oauth', 'sso', 'verify-receipt',
            'supplier', 'locale', 'up', 'storage', 'build', 'assets', 'vendor', 'broadcasting',
        ]);
    }

    public function test_a_public_directory_is_reserved_and_valid_prefix_limits_are_supported(): void
    {
        $directory = public_path('portal-prefix-test-directory');
        mkdir($directory);
        try {
            config(['portal_urls.prefix.enabled' => true, 'portal_urls.prefix.value' => 'PORTAL-PREFIX-TEST-DIRECTORY/erp']);
            try {
                PortalPath::assertValid();
                $this->fail('A public directory must never be used as a portal prefix.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('conflicts', $exception->getMessage());
            }
        } finally {
            rmdir($directory);
        }

        foreach (['v2/ADASI_PRD', 'one/two/three/four', str_repeat('a', 64)] as $prefix) {
            config(['portal_urls.prefix.value' => $prefix]);
            $this->assertSame($prefix, PortalPath::prefix());
        }
    }

    public function test_prefixed_polling_and_numeric_purchasing_urls_keep_existing_guards(): void
    {
        config(['portal_urls.prefix.enabled' => true, 'portal_urls.prefix.value' => 'erp/ADASI_TST']);
        $request = Request::create('http://localhost/login');

        foreach (['notifications/unread-count', 'exports/hash/download', 'attachments/hash', 'conversations/hash'] as $path) {
            $this->assertSame('/dashboard', TwoFactorService::safeIntendedUrl($request, PortalPath::path($path)));
        }
        $this->assertSame('/erp/ADASI_TST/profile?tab=layout', TwoFactorService::safeIntendedUrl($request, '/erp/ADASI_TST/profile?tab=layout'));
        $this->assertFalse(PurchasingNavigation::isSafeUrl('/erp/ADASI_TST/purchasing/purchase-orders/123'));
        $this->assertTrue(PurchasingNavigation::isSafeUrl('/erp/ADASI_TST/purchasing/purchase-orders/aHashKey'));
    }
}
