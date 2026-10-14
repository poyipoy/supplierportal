<?php

namespace Tests\Unit;

use App\Support\PurchasingNavigation;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PurchasingNavigationUrlTest extends TestCase
{
    #[DataProvider('unsafeUrls')]
    public function test_unsafe_or_encoded_redirect_targets_are_rejected(string $url): void
    {
        $this->assertFalse(PurchasingNavigation::isSafeUrl($url));
    }

    public static function unsafeUrls(): array
    {
        return array_map(fn ($url) => [$url], [
            '/\\outside.test', '/%5coutside.test', '//outside.test', '/%2foutside.test',
            '/purchasing/dashboard%09', '/purchasing/dashboard%0d%0aLocation:evil',
            "/purchasing/dashboard\t", '/purchasing/dashboard%00',
            'https://outside.test/purchasing/dashboard', 'https://name:secret@localhost/purchasing/dashboard',
        ]);
    }

    public function test_valid_relative_and_same_origin_list_urls_keep_filters(): void
    {
        $this->assertTrue(PurchasingNavigation::isSafeUrl('/purchasing/requisitions?search=tool%20steel'));
        $this->assertTrue(PurchasingNavigation::isSafeUrl(url('/purchasing/requisitions?status=draft')));
    }
}
