<?php

namespace Tests\Feature;

use App\Http\Middleware\DecodeHashids;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;
use Vinkla\Hashids\Facades\Hashids;

class LongHashidTransitionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'auth'])->match(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], '/_url-test/scalars/{po_id}/{supplier_id}',
            static fn (Request $request, int $po_id, int $supplier_id) => ['po' => $po_id, 'supplier' => $supplier_id])
            ->name('url-test.scalars');
        // member intentionally is not in HASHED_PARAM_KEYS; implicit models must also canonicalize.
        Route::middleware(['web', 'auth'])->match(['GET', 'POST'], '/_url-test/member/{member}',
            static fn (User $member) => ['id' => $member->id])->name('url-test.member');
        Route::getRoutes()->refreshNameLookups();
        config(['portal_urls.long_hashids.enabled' => true]);
    }

    public function test_manual_get_and_head_redirect_both_ways_and_preserve_the_exact_query(): void
    {
        $user = User::factory()->create(['role' => 'purchasing']);
        $short = Hashids::connection('main')->encode($user->id);
        $long = Hashids::connection('long')->encode($user->id);
        $this->assertSame(24, strlen($user->hash));
        $query = '?id=plain&q=a%20b&x=1&x=2';

        foreach ([true, false] as $enabled) {
            config(['portal_urls.long_hashids.enabled' => $enabled]);
            [$old, $canonical] = $enabled ? [$short, $long] : [$long, $short];
            foreach (['GET', 'HEAD'] as $method) {
                $this->actingAs($user)->call($method, '/_url-test/scalars/'.$old.'/'.$old.$query)
                    ->assertStatus(301)->assertHeader('Location', '/_url-test/scalars/'.$canonical.'/'.$canonical.$query);
            }
            $this->actingAs($user)->get('/_url-test/scalars/'.$canonical.'/'.$canonical)
                ->assertOk()->assertJsonPath('po', $user->id)->assertJsonPath('supplier', $user->id);
        }
    }

    public function test_non_get_methods_accept_the_other_format_without_redirecting(): void
    {
        $user = User::factory()->create(['role' => 'purchasing']);
        foreach ([true, false] as $enabled) {
            config(['portal_urls.long_hashids.enabled' => $enabled]);
            $hash = Hashids::connection($enabled ? 'main' : 'long')->encode($user->id);
            foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
                $this->actingAs($user)->call($method, '/_url-test/scalars/'.$hash.'/'.$hash)
                    ->assertOk()->assertHeaderMissing('Location')->assertJsonPath('po', $user->id);
            }
            $this->actingAs($user)->post('/_url-test/member/'.$hash)->assertOk()->assertJsonPath('id', $user->id);
        }
    }

    public function test_implicit_bindings_outside_the_allowlist_redirect_to_the_active_format(): void
    {
        $user = User::factory()->create(['role' => 'purchasing']);
        foreach ([true, false] as $enabled) {
            config(['portal_urls.long_hashids.enabled' => $enabled]);
            $old = Hashids::connection($enabled ? 'main' : 'long')->encode($user->id);
            $this->actingAs($user)->get('/_url-test/member/'.$old.'?tab=record')
                ->assertStatus(301)->assertHeader('Location', '/_url-test/member/'.$user->hash.'?tab=record');
        }
    }

    public function test_raw_integers_and_invalid_hashes_are_rejected_in_both_modes(): void
    {
        $user = User::factory()->create(['role' => 'purchasing']);
        foreach ([false, true] as $enabled) {
            config(['portal_urls.long_hashids.enabled' => $enabled]);
            foreach ([(string) $user->id, 'invalid-hash', Hashids::connection('main')->encode(1, 2)] as $invalid) {
                $this->actingAs($user)->get('/_url-test/member/'.$invalid)->assertNotFound();
                $this->actingAs($user)->get('/_url-test/scalars/'.$invalid.'/'.$user->hash)->assertNotFound();
            }
        }
    }

    public function test_supplier_ownership_still_rejects_the_other_suppliers_canonical_url(): void
    {
        $supplier = User::factory()->create(['role' => 'supplier']);
        $other = User::factory()->create(['role' => 'supplier']);
        $po = PurchaseOrder::create([
            'po_number' => 'PO/URL/001', 'supplier_id' => $other->id, 'currency' => 'IDR',
            'status' => 'active', 'created_by' => $other->id,
        ]);

        foreach ([false, true] as $enabled) {
            config(['portal_urls.long_hashids.enabled' => $enabled]);
            $response = $this->actingAs($supplier)->get(route('supplier.purchase-orders.show', $po));
            $this->assertContains($response->status(), [403, 404]);
        }

        $reflection = new \ReflectionClass(DecodeHashids::class);
        $this->assertNotContains('pageUuid', $reflection->getConstant('HASHED_PARAM_KEYS'));
    }
}
