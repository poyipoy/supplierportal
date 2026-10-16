<?php

namespace Tests\Feature\Procurement;

use App\Models\MaterialMaster;
use App\Models\PrItemAward;
use App\Models\PurchaseOrder;
use App\Services\PrItemAwardService;
use App\Services\PurchaseOrderGenerationService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Mission 01 migrations are reversible on an empty schema and refuse to roll
 * back once any row carries data they would drop.
 *
 * MySQL DDL commits implicitly, so the down/up cycle runs only on empty
 * tables, and every guard is asserted to fire before any DDL executes.
 */
class ProcurementMigrationRollbackTest extends TestCase
{
    use ProcurementFixtures;
    use RefreshDatabase;

    private const MIGRATIONS = [
        '2026_10_14_000002_add_logistics_terms_to_purchase_requisitions',
        '2026_10_14_000003_add_logistics_terms_to_purchase_orders',
        '2026_10_14_000004_add_ordered_and_acceptance_fields_to_pr_item_awards',
        '2026_10_14_000005_create_pr_item_award_events_table',
        '2026_10_14_000006_create_material_reference_prices_table',
        '2026_10_14_000007_create_award_reference_price_snapshots_table',
    ];

    private const CHECK_CONSTRAINTS = [
        'purchase_requisitions_port_of_discharge_allowed',
        'purchase_requisitions_incoterm_allowed',
        'purchase_orders_port_of_discharge_allowed',
        'purchase_orders_incoterm_allowed',
        'pr_item_awards_ordered_qty_positive',
        'pr_item_awards_ordered_values_non_negative',
        'material_reference_prices_shape_allowed',
        'material_reference_prices_dimension_matches_shape',
        'material_reference_prices_dimension_range_valid',
        'material_reference_prices_price_positive',
        'material_reference_prices_validity_range_valid',
        'material_reference_prices_currency_allowed',
        'material_reference_prices_status_allowed',
        'award_reference_price_snapshots_status_coherent',
    ];

    public function test_migrations_roll_back_in_reverse_and_restore_forward_on_empty_tables(): void
    {
        $this->assertEqualsCanonicalizing(self::CHECK_CONSTRAINTS, $this->missionCheckConstraints());
        $migrations = array_map(fn (string $name): Migration => $this->migration($name), self::MIGRATIONS);

        try {
            foreach (array_reverse($migrations) as $migration) {
                $migration->down();
            }

            $this->assertFalse(Schema::hasTable('award_reference_price_snapshots'));
            $this->assertFalse(Schema::hasTable('material_reference_prices'));
            $this->assertFalse(Schema::hasTable('pr_item_award_events'));
            $this->assertFalse(Schema::hasColumn('pr_item_awards', 'ordered_qty'));
            $this->assertFalse(Schema::hasColumn('pr_item_awards', 'spec_accepted_by'));
            $this->assertFalse(Schema::hasColumn('purchase_orders', 'port_of_discharge'));
            $this->assertFalse(Schema::hasColumn('purchase_requisitions', 'incoterm'));
            $this->assertSame([], $this->missionCheckConstraints());
        } finally {
            foreach ($migrations as $migration) {
                $migration->up();
            }
        }

        $this->assertTrue(Schema::hasTable('award_reference_price_snapshots'));
        $this->assertTrue(Schema::hasColumns('pr_item_awards', ['ordered_qty', 'ordered_amount', 'spec_acceptance_hash']));
        $this->assertTrue(Schema::hasColumns('purchase_requisitions', ['port_of_discharge', 'incoterm']));
        $this->assertEqualsCanonicalizing(self::CHECK_CONSTRAINTS, $this->missionCheckConstraints());
    }

    public function test_requisition_logistics_rollback_refuses_when_logistics_exist(): void
    {
        $this->setUpProcurementActors();
        $pr = $this->createRequisition();
        DB::table('purchase_requisitions')->where('id', $pr->id)->update(['port_of_discharge' => 'TANJUNG_PRIOK']);

        $this->assertRollbackRefused(self::MIGRATIONS[0]);
        $this->assertTrue(Schema::hasColumn('purchase_requisitions', 'port_of_discharge'));
    }

    public function test_purchase_order_logistics_rollback_refuses_when_a_snapshot_exists(): void
    {
        $this->setUpProcurementActors();
        $po = $this->generatePurchaseOrder();
        DB::table('purchase_orders')->where('id', $po->id)->update(['incoterm' => 'CIF']);

        $this->assertRollbackRefused(self::MIGRATIONS[1]);
        $this->assertTrue(Schema::hasColumn('purchase_orders', 'incoterm'));
    }

    public function test_award_rollback_refuses_when_a_selection_or_frozen_value_exists(): void
    {
        $this->setUpProcurementActors();
        $award = $this->createAward();
        DB::table('pr_item_awards')->where('id', $award->id)->update(['spec_accepted_at' => now()]);

        $this->assertRollbackRefused(self::MIGRATIONS[2]);
        $this->assertTrue(Schema::hasColumn('pr_item_awards', 'spec_accepted_at'));
    }

    public function test_event_rollback_refuses_when_audit_rows_exist(): void
    {
        $this->setUpProcurementActors();
        $award = $this->createAward();
        DB::table('pr_item_award_events')->insert([
            'pr_item_award_id' => $award->id, 'pr_id' => $award->pr_id, 'pr_item_id' => $award->pr_item_id,
            'event' => 'selection_cleared', 'created_at' => now(),
        ]);

        $this->assertRollbackRefused(self::MIGRATIONS[3]);
        $this->assertTrue(Schema::hasTable('pr_item_award_events'));
    }

    public function test_reference_price_rollback_refuses_when_prices_exist(): void
    {
        $this->setUpProcurementActors();
        $material = MaterialMaster::create(['material_code' => 'S45C', 'normalized_code' => 'S45C-'.uniqid()]);
        DB::table('material_reference_prices')->insert([
            'material_master_id' => $material->id, 'shape' => 'Round', 'dimension_field' => 'd_outer',
            'dimension_min' => '10', 'dimension_max' => '20', 'currency' => 'USD', 'price_per_kg' => '3.5',
            'valid_from' => '2026-10-01', 'version_no' => 1, 'created_by' => $this->purchasing->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertRollbackRefused(self::MIGRATIONS[4]);
        $this->assertTrue(Schema::hasTable('material_reference_prices'));
    }

    public function test_snapshot_rollback_refuses_when_snapshots_exist(): void
    {
        $this->setUpProcurementActors();
        // Since Mission 06B, PO generation itself writes the snapshot row.
        $po = $this->generatePurchaseOrder();
        $this->assertSame(1, DB::table('award_reference_price_snapshots')->where('purchase_order_id', $po->id)->count());

        $this->assertRollbackRefused(self::MIGRATIONS[5]);
        $this->assertTrue(Schema::hasTable('award_reference_price_snapshots'));
    }

    private function assertRollbackRefused(string $name): void
    {
        try {
            $this->migration($name)->down();
            $this->fail("{$name} must refuse to roll back while data exists.");
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Cannot roll back', $exception->getMessage());
        }
    }

    private function migration(string $name): Migration
    {
        return require database_path("migrations/{$name}.php");
    }

    /**
     * @return list<string>
     */
    private function missionCheckConstraints(): array
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('CONSTRAINT_TYPE', 'CHECK')
            ->whereIn('CONSTRAINT_NAME', self::CHECK_CONSTRAINTS)
            ->pluck('CONSTRAINT_NAME')
            ->all();
    }

    private function createAward(): PrItemAward
    {
        $pr = $this->createRequisition();
        $quotation = $this->createQuotation($pr);

        return app(PrItemAwardService::class)->awardItem($pr->items[0], $quotation->items[0], $this->purchasing);
    }

    private function generatePurchaseOrder(): PurchaseOrder
    {
        return app(PurchaseOrderGenerationService::class)->generateFromAwards([$this->createAward()->id], $this->purchasing)->sole();
    }
}
