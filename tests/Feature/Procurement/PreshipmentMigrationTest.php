<?php

namespace Tests\Feature\Procurement;

use App\Models\SupplierPreshipmentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class PreshipmentMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertSame('adasi_portal_test', config('database.connections.mysql.database'));
        $this->assertSame('adasi_portal_test', DB::selectOne('SELECT DATABASE() AS db')->db);
    }

    public function test_extension_migration_supports_clean_rollback_idempotent_backfill_and_data_guard(): void
    {
        $this->assertSame('adasi_portal_test', DB::connection()->getDatabaseName());

        $migration = require database_path('migrations/2026_11_19_000005_extend_preshipment_inspection.php');

        try {
            // 1. Clean down() when tables are empty drops new table and columns cleanly.
            $migration->down();
            $this->assertFalse(Schema::hasTable('supplier_goods_locations'));
            $this->assertFalse(Schema::hasColumn('shipment_preshipment_inspections', 'goods_location_id'));
            $this->assertFalse(Schema::hasColumn('shipment_preshipment_inspections', 'supplier_same_as_exporter'));
            $this->assertFalse(Schema::hasColumn('shipment_preshipment_inspections', 'revision_count'));
            $this->assertFalse(Schema::hasColumn('shipment_preshipment_inspections', 'last_revised_at'));
            $this->assertFalse(Schema::hasColumn('shipment_preshipment_inspections', 'updated_by'));

            // 2. Seed a legacy supplier_preshipment_profiles row with supplier_* columns before running up().
            $supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
            SupplierPreshipmentProfile::create([
                'supplier_id' => $supplier->id,
                'exporter_name' => 'Legacy Trading Corp',
                'exporter_city' => 'Shanghai',
                'exporter_country' => 'CN',
                'exporter_port_of_loading' => 'Shanghai Port',
                'supplier_name' => 'Legacy Mill Wuxi',
                'supplier_contact' => 'Mr. Li',
                'supplier_address' => '55 Factory Blvd',
                'supplier_city' => 'Wuxi',
                'supplier_country' => 'CN',
                'supplier_telephone' => '+86-510-1234-5678',
                'supplier_email' => 'mill@legacy.example',
            ]);

            // 3. Running up() creates schema and backfills legacy supplier_* into supplier_goods_locations as default.
            $migration->up();
            $this->assertTrue(Schema::hasTable('supplier_goods_locations'));
            $this->assertTrue(Schema::hasColumn('shipment_preshipment_inspections', 'goods_location_id'));
            $this->assertTrue(Schema::hasColumn('shipment_preshipment_inspections', 'supplier_same_as_exporter'));
            $this->assertTrue(Schema::hasColumn('shipment_preshipment_inspections', 'revision_count'));

            $this->assertDatabaseHas('supplier_goods_locations', [
                'supplier_id' => $supplier->id,
                'name' => 'Legacy Mill Wuxi',
                'city' => 'Wuxi',
                'country' => 'CN',
                'is_default' => 1,
            ]);

            // 4. Re-running up() is idempotent and does not duplicate backfilled locations.
            $migration->up();
            $this->assertSame(1, DB::table('supplier_goods_locations')->where('supplier_id', $supplier->id)->count());

            // 5. down() refuses destructive rollback while rows exist in supplier_goods_locations.
            try {
                $migration->down();
                $this->fail('Expected RuntimeException when rolling back non-empty supplier_goods_locations.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('Cannot roll back', $exception->getMessage());
            }
        } finally {
            // Restore clean schema for subsequent tests.
            DB::table('shipment_preshipment_inspections')->delete();
            if (Schema::hasTable('supplier_goods_locations')) {
                DB::table('supplier_goods_locations')->delete();
            }
            $migration->up();
        }
    }
}
