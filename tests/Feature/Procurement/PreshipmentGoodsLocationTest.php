<?php

namespace Tests\Feature\Procurement;

use App\Exceptions\BusinessRuleArgumentException;
use App\Models\SupplierGoodsLocation;
use App\Models\User;
use App\Services\Procurement\PreshipmentInspectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PreshipmentGoodsLocationTest extends TestCase
{
    use ProcurementFixtures;
    use RefreshDatabase;

    private User $supplierB;

    private User $qc;

    private User $localSupplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurementActors();

        $this->supplierB = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $this->qc = User::factory()->create(['role' => 'qc', 'is_active' => true]);
        $this->localSupplier = User::factory()->create([
            'role' => 'supplier',
            'is_active' => true,
        ]);
        $this->localSupplier->supplierScopes()->delete();
        $this->localSupplier->supplierScopes()->create(['scope' => 'local']);
        $this->localSupplier->forgetSupplierScopes();
    }

    private function validLocationPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Wuxi Plant #1',
            'contact' => 'Mr. Chen',
            'address' => '88 Industrial Ave, Xinwu District',
            'city' => 'Wuxi',
            'country' => 'CN',
            'telephone' => '+86-510-8888-1234',
            'mobile' => '+86 138 0000 1111',
            'fax' => '+86-510-8888-9999',
            'email' => 'plant1@wuxi.example',
            'is_default' => '0',
        ], $overrides);
    }

    public function test_supplier_crud_auto_default_first_location_and_deleting_default_does_not_auto_promote(): void
    {
        // Index page loads with empty state initially.
        $this->actingAs($this->supplier)
            ->get(route('supplier.goods-locations.index'))
            ->assertOk()
            ->assertSee(__('shipments.preshipment.locations_empty_title'));

        // First location automatically becomes default even if is_default=0.
        $this->actingAs($this->supplier)
            ->post(route('supplier.goods-locations.store'), $this->validLocationPayload([
                'name' => 'First Plant',
                'is_default' => '0',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $first = SupplierGoodsLocation::forSupplier($this->supplier->id)->where('name', 'First Plant')->firstOrFail();
        $this->assertTrue($first->is_default);

        // Second location with is_default=0 stays non-default.
        $this->actingAs($this->supplier)
            ->post(route('supplier.goods-locations.store'), $this->validLocationPayload([
                'name' => 'Second Plant',
                'city' => 'Suzhou',
                'is_default' => '0',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $second = SupplierGoodsLocation::forSupplier($this->supplier->id)->where('name', 'Second Plant')->firstOrFail();
        $this->assertFalse($second->is_default);

        // Update second location.
        $this->actingAs($this->supplier)
            ->put(route('supplier.goods-locations.update', $second), $this->validLocationPayload([
                'name' => 'Second Plant Updated',
                'city' => 'Nanjing',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $second->refresh();
        $this->assertSame('Second Plant Updated', $second->name);
        $this->assertSame('Nanjing', $second->city);

        // Set second location as default -> clears default on first.
        $this->actingAs($this->supplier)
            ->post(route('supplier.goods-locations.default', $second))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue($second->fresh()->is_default);

        // Deleting the default location soft-deletes it and does NOT auto-promote the first location.
        $this->actingAs($this->supplier)
            ->delete(route('supplier.goods-locations.destroy', $second))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSoftDeleted('supplier_goods_locations', ['id' => $second->id]);
        $this->assertFalse($first->fresh()->is_default);
        $this->assertSame(0, SupplierGoodsLocation::forSupplier($this->supplier->id)->where('is_default', true)->count());
    }

    public function test_cross_supplier_idor_role_guards_and_raw_integer_hashid_rejection(): void
    {
        $locA = SupplierGoodsLocation::create([
            'supplier_id' => $this->supplier->id,
            'name' => 'Supplier A Mill',
            'address' => '10 Steel Way',
            'city' => 'Osaka',
            'country' => 'JP',
            'is_default' => true,
        ]);

        // Supplier B cannot see Supplier A's location on index, nor update/default/delete it.
        $this->actingAs($this->supplierB)
            ->get(route('supplier.goods-locations.index'))
            ->assertOk()
            ->assertDontSee('Supplier A Mill');

        $this->actingAs($this->supplierB)
            ->put(route('supplier.goods-locations.update', $locA), $this->validLocationPayload(['name' => 'Stolen']))
            ->assertForbidden();

        $this->actingAs($this->supplierB)
            ->post(route('supplier.goods-locations.default', $locA))
            ->assertForbidden();

        $this->actingAs($this->supplierB)
            ->delete(route('supplier.goods-locations.destroy', $locA))
            ->assertForbidden();

        // Purchasing, QC, and Local-only Supplier cannot access the routes.
        foreach ([$this->purchasing, $this->qc, $this->localSupplier] as $actor) {
            $this->actingAs($actor)
                ->get(route('supplier.goods-locations.index'))
                ->assertForbidden();
        }

        // Raw numeric ID in URL is rejected with 404 by DecodeHashids.
        $this->actingAs($this->supplier)
            ->put('/supplier/goods-locations/'.$locA->id, $this->validLocationPayload())
            ->assertNotFound();

        $this->actingAs($this->supplier)
            ->delete('/supplier/goods-locations/'.$locA->id)
            ->assertNotFound();
    }

    public function test_max_twenty_active_locations_limit_is_enforced_and_soft_deleted_do_not_count(): void
    {
        $service = app(PreshipmentInspectionService::class);

        $created = [];
        for ($i = 1; $i <= SupplierGoodsLocation::MAX_ACTIVE_PER_SUPPLIER; $i++) {
            $created[] = $service->createLocation($this->supplier, $this->validLocationPayload([
                'name' => "Plant #{$i}",
            ]));
        }

        $this->assertSame(20, SupplierGoodsLocation::forSupplier($this->supplier->id)->count());

        // 21st active location is rejected via HTTP and service.
        $this->actingAs($this->supplier)
            ->post(route('supplier.goods-locations.store'), $this->validLocationPayload(['name' => 'Plant #21']))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->expectException(BusinessRuleArgumentException::class);
        try {
            $service->createLocation($this->supplier, $this->validLocationPayload(['name' => 'Plant #21']));
        } finally {
            // Soft-deleting one frees a slot for a new active location.
            $service->deleteLocation($created[0], $this->supplier);
            $replacement = $service->createLocation($this->supplier, $this->validLocationPayload(['name' => 'Plant #21 After Delete']));
            $this->assertSame('Plant #21 After Delete', $replacement->name);
            $this->assertSame(20, SupplierGoodsLocation::forSupplier($this->supplier->id)->count());
        }
    }

    public function test_options_endpoint_search_empty_state_copy_and_raw_default_id_rejection(): void
    {
        $locA = SupplierGoodsLocation::create([
            'supplier_id' => $this->supplier->id,
            'name' => 'Supplier A Busan Hub',
            'address' => '1 Harbor Blvd',
            'city' => 'Busan',
            'country' => 'KR',
            'is_default' => true,
        ]);
        SupplierGoodsLocation::create([
            'supplier_id' => $this->supplierB->id,
            'name' => 'Supplier B Secret Mill',
            'address' => '9 Private Rd',
            'city' => 'Osaka',
            'country' => 'JP',
            'is_default' => true,
        ]);

        // Options JSON returns only authenticated supplier locations and limit metadata.
        $this->actingAs($this->supplier)
            ->getJson(route('supplier.goods-locations.options'))
            ->assertOk()
            ->assertJsonPath('active_count', 1)
            ->assertJsonPath('max_locations', 20)
            ->assertJsonCount(1, 'locations')
            ->assertJsonPath('locations.0.name', 'Supplier A Busan Hub')
            ->assertDontSee('Supplier B Secret Mill');

        foreach ([$this->purchasing, $this->qc, $this->localSupplier] as $actor) {
            $this->actingAs($actor)
                ->getJson(route('supplier.goods-locations.options'))
                ->assertForbidden();
        }

        // Filtered search empty state displays search-empty copy + Reset (never initial empty prompt), and Save Location CTA.
        $this->actingAs($this->supplier)
            ->get(route('supplier.goods-locations.index', ['search' => 'NoSuchCityXYZ']))
            ->assertOk()
            ->assertSee(__('shipments.preshipment.locations_search_empty_title'))
            ->assertSee(__('shipments.copy.reset'))
            ->assertSee(__('shipments.preshipment.locations_save'))
            ->assertDontSee(__('shipments.preshipment.save_psi'))
            ->assertDontSee(__('shipments.preshipment.locations_empty_title'));

        // Raw numeric ID in default route is rejected with 404 by DecodeHashids.
        $this->actingAs($this->supplier)
            ->post('/supplier/goods-locations/'.$locA->id.'/default')
            ->assertNotFound();
    }

    public function test_json_crud_endpoints_sync_locations_enforce_limit_and_block_cross_supplier_idor(): void
    {
        // Create first location via JSON -> 201 + created_hash + route URLs + auto-default
        $createRes = $this->actingAs($this->supplier)
            ->postJson(route('supplier.goods-locations.store'), $this->validLocationPayload([
                'name' => 'JSON Wuxi Plant',
                'is_default' => '0',
            ]))
            ->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('active_count', 1)
            ->assertJsonPath('max_locations', SupplierGoodsLocation::MAX_ACTIVE_PER_SUPPLIER)
            ->assertJsonPath('locations.0.name', 'JSON Wuxi Plant')
            ->assertJsonPath('locations.0.is_default', true);

        $createdHash = (string) $createRes->json('created_hash');
        $this->assertNotSame('', $createdHash);
        $this->assertNotSame('', (string) $createRes->json('locations.0.update_url'));
        $this->assertNotSame('', (string) $createRes->json('locations.0.default_url'));
        $this->assertNotSame('', (string) $createRes->json('locations.0.destroy_url'));

        // Validation error via JSON returns 422 field errors
        $this->actingAs($this->supplier)
            ->postJson(route('supplier.goods-locations.store'), $this->validLocationPayload([
                'name' => '',
                'country' => 'ZZ',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'country']);

        $secondRes = $this->actingAs($this->supplier)
            ->postJson(route('supplier.goods-locations.store'), $this->validLocationPayload([
                'name' => 'JSON Ningbo Plant',
                'city' => 'Ningbo',
                'is_default' => '0',
            ]))
            ->assertCreated()
            ->assertJsonPath('active_count', 2);

        $secondHash = (string) $secondRes->json('created_hash');
        $firstLoc = SupplierGoodsLocation::forSupplier($this->supplier->id)->where('name', 'JSON Wuxi Plant')->firstOrFail();
        $secondLoc = SupplierGoodsLocation::forSupplier($this->supplier->id)->where('name', 'JSON Ningbo Plant')->firstOrFail();

        // Update via JSON -> 200
        $this->actingAs($this->supplier)
            ->putJson(route('supplier.goods-locations.update', $secondLoc), $this->validLocationPayload([
                'name' => 'JSON Ningbo Revised',
                'city' => 'Ningbo',
            ]))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('updated_hash', $secondHash);

        // Set default via JSON -> 200
        $this->actingAs($this->supplier)
            ->postJson(route('supplier.goods-locations.default', $secondLoc))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('default_hash', $secondHash);

        $this->assertTrue($secondLoc->fresh()->is_default);
        $this->assertFalse($firstLoc->fresh()->is_default);

        // Cross-supplier IDOR attempts via JSON are forbidden (403); raw numeric ID is 404
        $this->actingAs($this->supplierB)
            ->putJson(route('supplier.goods-locations.update', $secondLoc), $this->validLocationPayload(['name' => 'Hijack']))
            ->assertForbidden();
        $this->actingAs($this->supplierB)
            ->postJson(route('supplier.goods-locations.default', $secondLoc))
            ->assertForbidden();
        $this->actingAs($this->supplierB)
            ->deleteJson(route('supplier.goods-locations.destroy', $secondLoc))
            ->assertForbidden();
        $this->actingAs($this->supplier)
            ->deleteJson('/supplier/goods-locations/'.$secondLoc->id)
            ->assertNotFound();

        // Delete via JSON -> 200 and leaves remaining count = 1
        $this->actingAs($this->supplier)
            ->deleteJson(route('supplier.goods-locations.destroy', $secondLoc))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('deleted_hash', $secondHash)
            ->assertJsonPath('active_count', 1);

        // Reaching max 20 active locations rejects next JSON POST with 422
        $service = app(PreshipmentInspectionService::class);
        for ($i = 2; $i <= SupplierGoodsLocation::MAX_ACTIVE_PER_SUPPLIER; $i++) {
            $service->createLocation($this->supplier, $this->validLocationPayload(['name' => "Limit Plant #{$i}"]));
        }

        $this->actingAs($this->supplier)
            ->postJson(route('supplier.goods-locations.store'), $this->validLocationPayload(['name' => 'Plant #21']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['limit']);
    }
}
