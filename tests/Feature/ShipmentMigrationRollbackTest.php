<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ShipmentMigrationRollbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_shipment_migrations_roll_back_with_active_shp_sequence_and_restore_forward(): void
    {
        DB::table('document_sequences')->updateOrInsert(
            ['type' => 'SHP', 'year' => 2026, 'month' => 9],
            ['last_number' => 7, 'created_at' => now(), 'updated_at' => now()]
        );

        $shipmentMigration = require database_path('migrations/2026_09_04_000002_create_shipments_tables.php');
        $hardeningMigration = require database_path('migrations/2026_09_04_000003_harden_shipment_integrity_constraints.php');

        $hardeningMigration->down();
        try {
            $shipmentMigration->down();

            $this->assertFalse(Schema::hasTable('shipments'));
            $this->assertFalse(Schema::hasTable('shipment_items'));
            $this->assertFalse(Schema::hasTable('shipment_documents'));
            $this->assertSame(0, DB::table('document_sequences')->where('type', 'SHP')->count());
        } finally {
            if (! Schema::hasTable('shipments')) {
                $shipmentMigration->up();
            }
            $hardeningMigration->up();
        }

        $this->assertTrue(Schema::hasTable('shipments'));
        $this->assertTrue(Schema::hasColumn('qc_inspections', 'shipment_id'));
        $this->assertTrue(Schema::hasColumn('qc_items', 'shipment_item_id'));
    }
}
