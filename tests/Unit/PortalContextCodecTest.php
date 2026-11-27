<?php

namespace Tests\Unit;

use App\Models\User;
use App\Support\PortalContextCodec;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\ReloadsPortalRoutes;
use Tests\TestCase;

class PortalContextCodecTest extends TestCase
{
    use RefreshDatabase;
    use ReloadsPortalRoutes;

    /**
     * @return array<string, mixed>
     */
    private function loadFixture(): array
    {
        $raw = file_get_contents(base_path('tests/fixtures/portal-context-url-cases.json'));
        $this->assertIsString($raw);

        return json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_encode_and_decode_match_shared_cross_language_fixture_byte_for_byte(): void
    {
        $fixture = $this->loadFixture();

        foreach ($fixture['validCases'] as $case) {
            $encoded = PortalContextCodec::encodeScv2Payload(
                $case['description'],
                $case['action'],
                $case['expectedArgument']
            );
            $this->assertSame($case['expectedFavoriteContext'], $encoded, 'Case '.$case['id'].' encode mismatch');

            $decoded = PortalContextCodec::decodeScv2Payload($case['expectedFavoriteContext']);
            $this->assertSame($case['action'], $decoded['action']);
            $this->assertSame($case['expectedArgument'], $decoded['argument']);
            $this->assertNull($decoded['path']);

            $sessionEntry = [[
                'action' => $case['action'],
                'route' => $case['route'],
                'logicalId' => $case['logicalId'],
                'pathTemplate' => $case['pathTemplate'],
                'params' => $case['params'],
                'queryAllowlist' => $case['queryAllowlist'],
                'hashedKeys' => $case['hashedKeys'],
                'description' => $case['description'],
            ]];

            $projected = PortalContextCodec::project(
                $case['internalUrl'],
                sessionIndex: $sessionEntry,
                options: [
                    'appUuid' => $fixture['appUuid'],
                    'prefix' => $case['prefix'],
                ]
            );
            $this->assertSame($case['expectedContextUrl'], $projected, 'Case '.$case['id'].' project mismatch');

            $useLongHashids = in_array($case['id'], ['po_show', 'po_idx_utf8', 'lsp_inv_noprefix'], true);
            $this->reloadPortalRoutes([
                'portal_urls.prefix.enabled' => $case['prefix'] !== '',
                'portal_urls.prefix.value' => $case['prefix'] !== '' ? $case['prefix'] : 'v2/ADASI_PRD',
                'portal_urls.long_hashids.enabled' => $useLongHashids,
                'portal_urls.context_urls.enabled' => true,
                'portal_urls.context_urls.app_uuid' => $fixture['appUuid'],
            ]);

            $actor = match ($case['logicalId']) {
                'lid://adasi.portal.supplier' => User::factory()->create(['role' => 'supplier']),
                'lid://adasi.portal.local_supplier' => tap(
                    User::factory()->create(['role' => 'supplier']),
                    fn (User $u) => $u->supplierScopes()->firstOrCreate(['scope' => 'local'])
                )->forgetSupplierScopes(),
                default => User::factory()->create(['role' => 'purchasing']),
            };

            $resolved = PortalContextCodec::resolve(Request::create($case['expectedContextUrl'], 'GET'), $actor);
            $this->assertSame($case['expectedResolvedInternalUrl'], $resolved['internalUrl'], 'Case '.$case['id'].' resolve mismatch');
        }
    }

    public function test_static_create_route_precedence_wins_over_parameterized_show_route(): void
    {
        $this->reloadPortalRoutes([
            'portal_urls.prefix.enabled' => true,
            'portal_urls.prefix.value' => 'v2/ADASI_PRD',
            'portal_urls.context_urls.enabled' => true,
        ]);

        $purchasing = User::factory()->create(['role' => 'purchasing']);
        $createUrl = route('purchasing.requisitions.create');
        $projected = PortalContextCodec::project($createUrl, $purchasing);

        $this->assertNotNull($projected);
        $resolved = PortalContextCodec::resolve(Request::create($projected, 'GET'), $purchasing);
        $this->assertSame('purchasing.requisitions.create', $resolved['routeName']);
        $this->assertSame('purreqs100m000', $resolved['action']);
    }

    public function test_invalid_fixture_vectors_and_size_boundaries_are_rejected(): void
    {
        $fixture = $this->loadFixture();
        $this->reloadPortalRoutes([
            'portal_urls.prefix.enabled' => true,
            'portal_urls.prefix.value' => 'v2/ADASI_PRD',
            'portal_urls.context_urls.enabled' => true,
            'portal_urls.context_urls.app_uuid' => $fixture['appUuid'],
        ]);

        $admin = User::factory()->create(['role' => 'admin']);
        $purchasing = User::factory()->create(['role' => 'purchasing']);
        $supplier = User::factory()->create(['role' => 'supplier']);

        foreach ($fixture['invalidPayloads'] as $vector) {
            $encoded = base64_encode('scv2'.json_encode($vector['payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $contextUrl = 'https://portal.adasi.test/v2/ADASI_PRD/'.$fixture['appUuid']
                .'?favoriteContext='.rawurlencode($encoded)
                .'&LogicalId='.rawurlencode($vector['logicalId']);

            $actor = match ($vector['logicalId']) {
                'lid://adasi.portal.admin' => $admin,
                'lid://adasi.portal.supplier' => $supplier,
                default => $purchasing,
            };

            try {
                PortalContextCodec::resolve(Request::create($contextUrl, 'GET'), $actor);
                $this->fail('Expected rejection for vector: '.$vector['id']);
            } catch (HttpException $e) {
                $this->assertContains($e->getStatusCode(), [400, 404], 'Unexpected status for '.$vector['id']);
            }
        }

        // Exact raw > 2048 bytes rejection, wrong base64 alphabet, and non-scv2 prefix (SEC-06)
        $oversizedRaw = str_repeat('A', 2052);
        $this->expectException(HttpException::class);
        PortalContextCodec::decodeScv2Payload($oversizedRaw);
    }

    public function test_urlsafe_base64_and_scv1_prefix_and_decoded_limit_are_rejected(): void
    {
        $urlSafe = strtr(PortalContextCodec::encodeScv2Payload('?>?>?>>>>', 'purdash000m000', ''), '+/', '-_');
        $this->assertMatchesRegularExpression('/[-_]/', $urlSafe);

        foreach ([
            $urlSafe,
            base64_encode('scv1'.json_encode(['description' => 'x', 'application' => 'erp', 'type' => 'session', 'action' => 'purdash000m000', 'path' => null, 'argument' => ''])),
            'not-valid-base64!!!',
            base64_encode('scv2'.json_encode(['description' => str_repeat('x', 1480), 'application' => 'erp', 'type' => 'session', 'action' => 'purdash000m000', 'path' => null, 'argument' => ''])),
        ] as $raw) {
            try {
                PortalContextCodec::decodeScv2Payload($raw);
                $this->fail('Expected decode rejection.');
            } catch (HttpException $e) {
                $this->assertSame(400, $e->getStatusCode());
            }
        }
    }

    public function test_legacy_8_char_hashid_is_accepted_and_canonicalized_when_long_hashids_enabled(): void
    {
        $this->reloadPortalRoutes([
            'portal_urls.prefix.enabled' => true,
            'portal_urls.prefix.value' => 'v2/ADASI_PRD',
            'portal_urls.long_hashids.enabled' => true,
            'portal_urls.context_urls.enabled' => true,
        ]);

        $purchasing = User::factory()->create(['role' => 'purchasing']);
        $legacyPayload = PortalContextCodec::encodeScv2Payload('Purchase Order Details', 'purords410m000', 'id=8jMEB3zb&tab=items');
        $contextUrl = 'https://portal.adasi.test/v2/ADASI_PRD/'.config('portal_urls.context_urls.app_uuid')
            .'?favoriteContext='.rawurlencode($legacyPayload)
            .'&LogicalId='.rawurlencode('lid://adasi.portal.purchasing');

        $resolved = PortalContextCodec::resolve(Request::create($contextUrl, 'GET'), $purchasing);
        $this->assertSame('/v2/ADASI_PRD/purchasing/purchase-orders/gwpZa2E78jMEB3zbrvNnYXP5?tab=items', $resolved['internalPath']);
    }

    public function test_memoized_session_index_invalidates_cleanly_on_locale_and_prefix_changes(): void
    {
        $this->reloadPortalRoutes([
            'portal_urls.prefix.enabled' => true,
            'portal_urls.prefix.value' => 'v2/ADASI_PRD',
            'portal_urls.context_urls.enabled' => true,
        ]);

        $purchasing = User::factory()->create(['role' => 'purchasing']);

        app()->setLocale('en');
        $enUrl = PortalContextCodec::project(route('purchasing.purchase-orders.index'), $purchasing);
        $this->assertNotNull($enUrl);
        parse_str((string) parse_url($enUrl, PHP_URL_QUERY), $enQuery);
        $enPayload = PortalContextCodec::decodeScv2Payload((string) ($enQuery['favoriteContext'] ?? ''));

        app()->setLocale('id');
        $idUrl = PortalContextCodec::project(route('purchasing.purchase-orders.index'), $purchasing);
        $this->assertNotNull($idUrl);
        parse_str((string) parse_url($idUrl, PHP_URL_QUERY), $idQuery);
        $idPayload = PortalContextCodec::decodeScv2Payload((string) ($idQuery['favoriteContext'] ?? ''));

        $this->assertSame(__('navigation.purchase_order', [], 'en'), $enPayload['description']);
        $this->assertSame(__('navigation.purchase_order', [], 'id'), $idPayload['description']);

        $this->reloadPortalRoutes([
            'portal_urls.prefix.enabled' => true,
            'portal_urls.prefix.value' => 'erp/ADASI_ALT',
            'portal_urls.context_urls.enabled' => true,
        ]);

        $altUrl = PortalContextCodec::project(route('purchasing.purchase-orders.index'), $purchasing);
        $this->assertNotNull($altUrl);
        $this->assertStringContainsString('/erp/ADASI_ALT/', $altUrl);
    }
}
