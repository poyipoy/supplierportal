<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\Period;
use App\Models\PrItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
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
        $qtyMigration = require database_path('migrations/2026_09_08_000001_change_shipment_items_to_qty_and_actual_weight.php');

        // Rollback from the newest migration backwards
        $qtyMigration->down();
        $this->assertTrue(Schema::hasColumn('shipment_items', 'shipped_quantity'));
        $this->assertFalse(Schema::hasColumn('shipment_items', 'shipped_qty'));
        $this->assertFalse(Schema::hasColumn('shipment_items', 'actual_weight_kg'));

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
            $qtyMigration->up();
        }

        $this->assertTrue(Schema::hasTable('shipments'));
        $this->assertTrue(Schema::hasColumn('qc_inspections', 'shipment_id'));
        $this->assertTrue(Schema::hasColumn('qc_items', 'shipment_item_id'));
        $this->assertTrue(Schema::hasColumn('shipment_items', 'shipped_qty'));
        $this->assertTrue(Schema::hasColumn('shipment_items', 'actual_weight_kg'));
        $this->assertFalse(Schema::hasColumn('shipment_items', 'shipped_quantity'));
    }

    public function test_qty_migration_fails_closed_when_shipment_items_is_populated_on_up(): void
    {
        $qtyMigration = require database_path('migrations/2026_09_08_000001_change_shipment_items_to_qty_and_actual_weight.php');

        // Revert to legacy schema
        $qtyMigration->down();

        // Create a shipment and shipment_item in the legacy schema
        $supplier = User::factory()->create(['role' => 'supplier']);
        $purchasing = User::factory()->create(['role' => 'purchasing']);
        $period = Period::create([
            'name' => 'Test Period', 'year' => 2026, 'month' => 9, 'status' => 'open', 'created_by' => $purchasing->id,
        ]);
        $pr = PurchaseRequisition::create([
            'period_id' => $period->id, 'created_by' => $purchasing->id, 'pr_number' => 'REQ/09/2026/001', 'status' => 'bidding',
        ]);
        $prItem = PrItem::create([
            'pr_id' => $pr->id, 'material_name' => 'Steel', 'shape' => 'Flat', 'quantity' => 1, 'weight_needed' => 10,
        ]);
        $rate = ExchangeRate::create(['currency' => 'USD', 'rate_to_idr' => 16000, 'valid_from' => now()->subDay(), 'created_by' => $purchasing->id]);
        $q = Quotation::create(['pr_id' => $pr->id, 'supplier_id' => $supplier->id, 'currency' => 'USD', 'exchange_rate_id' => $rate->id, 'status' => 'submitted']);
        $qItem = QuotationItem::create(['quotation_id' => $q->id, 'pr_item_id' => $prItem->id, 'price_per_kg' => 2.5, 'amount' => 25]);
        $po = PurchaseOrder::create(['supplier_id' => $supplier->id, 'currency' => 'USD', 'exchange_rate_id' => $rate->id, 'po_number' => 'PO/09/2026/001', 'status' => 'active', 'created_by' => $purchasing->id]);
        $shipment = Shipment::create(['supplier_id' => $supplier->id, 'shipment_number' => 'SHP/09/2026/001', 'status' => 'draft', 'created_by' => $supplier->id]);

        DB::table('shipment_items')->insert([
            'shipment_id' => $shipment->id,
            'purchase_order_id' => $po->id,
            'quotation_item_id' => $qItem->id,
            'shipped_quantity' => '10.0000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Cannot migrate shipment_items to qty and actual weight: table is not empty.');
            $qtyMigration->up();
        } finally {
            DB::table('shipment_items')->delete();
            if (! Schema::hasColumn('shipment_items', 'shipped_qty')) {
                $qtyMigration->up();
            }
        }
    }

    public function test_qty_migration_fails_closed_when_shipment_items_is_populated_on_down(): void
    {
        $qtyMigration = require database_path('migrations/2026_09_08_000001_change_shipment_items_to_qty_and_actual_weight.php');

        $supplier = User::factory()->create(['role' => 'supplier']);
        $purchasing = User::factory()->create(['role' => 'purchasing']);
        $period = Period::create([
            'name' => 'Test Period', 'year' => 2026, 'month' => 9, 'status' => 'open', 'created_by' => $purchasing->id,
        ]);
        $pr = PurchaseRequisition::create([
            'period_id' => $period->id, 'created_by' => $purchasing->id, 'pr_number' => 'REQ/09/2026/002', 'status' => 'bidding',
        ]);
        $prItem = PrItem::create([
            'pr_id' => $pr->id, 'material_name' => 'Steel', 'shape' => 'Flat', 'quantity' => 1, 'weight_needed' => 10,
        ]);
        $rate = ExchangeRate::create(['currency' => 'USD', 'rate_to_idr' => 16000, 'valid_from' => now()->subDay(), 'created_by' => $purchasing->id]);
        $q = Quotation::create(['pr_id' => $pr->id, 'supplier_id' => $supplier->id, 'currency' => 'USD', 'exchange_rate_id' => $rate->id, 'status' => 'submitted']);
        $qItem = QuotationItem::create(['quotation_id' => $q->id, 'pr_item_id' => $prItem->id, 'price_per_kg' => 2.5, 'amount' => 25]);
        $po = PurchaseOrder::create(['supplier_id' => $supplier->id, 'currency' => 'USD', 'exchange_rate_id' => $rate->id, 'po_number' => 'PO/09/2026/002', 'status' => 'active', 'created_by' => $purchasing->id]);
        $shipment = Shipment::create(['supplier_id' => $supplier->id, 'shipment_number' => 'SHP/09/2026/002', 'status' => 'draft', 'created_by' => $supplier->id]);

        DB::table('shipment_items')->insert([
            'shipment_id' => $shipment->id,
            'purchase_order_id' => $po->id,
            'quotation_item_id' => $qItem->id,
            'shipped_qty' => 10,
            'actual_weight_kg' => 10.0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Cannot roll back shipment item quantity schema while data exists.');
            $qtyMigration->down();
        } finally {
            DB::table('shipment_items')->delete();
        }
    }
}
