<?php

namespace Tests\Feature;

use App\Models\PurchaseOrder;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Services\NotificationUrlResolver;
use App\Support\PortalContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Tests\Support\ReloadsPortalRoutes;
use Tests\TestCase;
use Vinkla\Hashids\Facades\Hashids;

class NotificationLegacyUrlTest extends TestCase
{
    use RefreshDatabase;
    use ReloadsPortalRoutes;

    public function test_legacy_record_and_query_hashes_resolve_in_all_flag_combinations_without_rewriting_data(): void
    {
        $purchasing = User::factory()->create(['role' => 'purchasing']);
        $supplier = User::factory()->create();
        $po = PurchaseOrder::create([
            'po_number' => 'PO/LEGACY/001', 'supplier_id' => $supplier->id, 'currency' => 'IDR',
            'status' => 'active', 'created_by' => $purchasing->id,
        ]);
        $hash = Hashids::connection('main')->encode($po->id);
        $filter = Hashids::connection('main')->encode($supplier->id);
        foreach ([false, true] as $prefix) {
            foreach ([false, true] as $long) {
                $this->reloadPortalRoutes(['portal_urls.prefix.enabled' => $prefix,
                    'portal_urls.prefix.value' => 'erp/ADASI_TST', 'portal_urls.long_hashids.enabled' => $long]);
                foreach (['', '/v2/ADASI_PRD', '/erp/ADASI_TST'] as $storedPrefix) {
                    $url = $storedPrefix.'/purchasing/purchase-orders/'.$hash.'?supplier_id='.$filter.'#documents';
                    $notification = $this->notification($purchasing, $url);
                    $expected = route('purchasing.purchase-orders.show', $po, absolute: false)
                        .'?supplier_id='.$supplier->hash.'#documents';
                    $this->assertSame($expected, app(NotificationUrlResolver::class)->resolve($notification, $purchasing));
                    $this->assertSame($url, $notification->fresh()->data['url']);
                }
            }
        }
    }

    public function test_legacy_links_still_enforce_supplier_ownership_role_and_domain(): void
    {
        $this->reloadPortalRoutes(['portal_urls.prefix.enabled' => true, 'portal_urls.long_hashids.enabled' => true]);
        $supplier = User::factory()->create();
        $other = User::factory()->create();
        $po = PurchaseOrder::create([
            'po_number' => 'PO/LEGACY/002', 'supplier_id' => $other->id, 'currency' => 'IDR',
            'status' => 'active', 'created_by' => $other->id,
        ]);
        $url = '/supplier/purchase-orders/'.Hashids::connection('main')->encode($po->id);
        $this->assertSame(PortalContext::dashboard($supplier), app(NotificationUrlResolver::class)->resolve($this->notification($supplier, $url), $supplier));
        $own = $this->notification($other, $url);
        $this->assertSame(route('supplier.purchase-orders.show', $po, absolute: false), app(NotificationUrlResolver::class)->resolve($own, $other));

        $finance = User::factory()->create(['role' => 'finance']);
        $this->assertSame(PortalContext::dashboard($finance), app(NotificationUrlResolver::class)->resolve($this->notification($finance, $url), $finance));
    }

    public function test_a_prefix_equal_to_an_operational_segment_preserves_legacy_notifications(): void
    {
        $this->reloadPortalRoutes(['portal_urls.prefix.enabled' => true, 'portal_urls.prefix.value' => 'purchasing']);
        $user = User::factory()->create(['role' => 'purchasing']);
        $notification = $this->notification($user, '/purchasing/shipments?status=draft');
        $this->assertSame('/purchasing/purchasing/shipments?status=draft', app(NotificationUrlResolver::class)->resolve($notification, $user));
    }

    private function notification(User $user, string $url): DatabaseNotification
    {
        return $user->notifications()->create([
            'id' => (string) Str::uuid(), 'type' => SystemNotification::class,
            'data' => ['url' => $url, 'title' => 'Legacy URL', 'message' => '', 'domain' => 'import'],
        ]);
    }
}
