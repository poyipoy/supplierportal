<?php

namespace Tests\Feature\LocalInvoice;

use App\Models\LocalGoodsReceipt;
use App\Models\LocalPurchaseOrder;
use App\Models\SupplierScope;
use App\Models\User;
use App\Services\RegionalDisplayFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class LocalImportUiFixtureTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_views_and_feeds_render_for_both_locales_and_audiences(): void
    {
        config(['broadcasting.default' => 'null']);
        $supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        SupplierScope::create(['supplier_id' => $supplier->id, 'scope' => 'local']);
        $po = LocalPurchaseOrder::create(['supplier_id' => $supplier->id, 'po_number' => 'UI-PO', 'po_date' => '2026-10-01',
            'total_amount' => '1000000.00', 'currency' => 'IDR', 'status' => 'OPEN']);
        foreach (range(1, 55) as $number) {
            LocalGoodsReceipt::create(['local_purchase_order_id' => $po->id, 'gr_number' => 'UI-GR-'.str_pad($number, 3, '0', STR_PAD_LEFT),
                'gr_date' => '2026-10-01', 'qty' => '1.125', 'uom' => 'pcs', 'description' => 'Long material description for browser rendering '.$number, 'status' => 'AVAILABLE']);
        }
        foreach (['en', 'id'] as $locale) {
            foreach (['finance', 'purchasing', 'supplier'] as $role) {
                $actor = $role === 'supplier' ? $supplier : User::factory()->create(['role' => $role, 'is_active' => true]);
                $actor->preference()->updateOrCreate([], [...config('user_preferences.defaults'), 'locale' => $locale, 'theme' => 'light']);
                $actor->unsetRelation('preference');
                $this->app->forgetInstance(RegionalDisplayFormatter::class);
                $this->actingAs($actor);
                $prefix = $role === 'supplier' ? 'local-supplier.purchase-orders' : $role.'.local-procurement';
                $detail = $this->get(route($prefix.'.show', $po))->assertOk()->assertSee('poGoodsReceiptsTable');
                $feed = $this->getJson(route($prefix.'.goods-receipts.data', $po).'?length=100')->assertOk();
                if (getenv('LOCAL_IMPORT_UI_FIXTURES') !== '1') {
                    continue;
                }
                $root = storage_path('framework/testing/import-ui');
                File::ensureDirectoryExists($root);
                $strip = fn ($html) => str_replace([url('/'), config('app.url')], '', $html);
                File::put($root.'/'.$locale.'-'.$role.'-detail.html', $strip($detail->getContent()));
                File::put($root.'/'.$locale.'-'.$role.'-feed.json', $feed->getContent());
                if ($role === 'finance') {
                    $index = $this->get(route($prefix.'.index'))->assertOk()->assertSee('localGrImportModal');
                    File::put($root.'/'.$locale.'-imports.html', $strip($index->getContent()));
                }
            }
        }
    }
}
