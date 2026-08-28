<?php

namespace Tests\Feature;

use App\Models\Period;
use App\Models\PrItem;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class OfferFieldsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function getMigration()
    {
        return require database_path('migrations/2026_08_28_000002_add_offer_fields_to_quotation_items_table.php');
    }

    public function test_rollback_succeeds_when_no_null_prices_exist(): void
    {
        $migration = $this->getMigration();

        // Create a standard quotation item with valid price
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $period = Period::create([
            'name' => 'Migration Test Period',
            'month' => 8,
            'year' => 2026,
            'status' => 'open',
            'created_by' => $admin->id,
        ]);
        $pr = PurchaseRequisition::create([
            'period_id' => $period->id,
            'created_by' => $purchasing->id,
            'pr_number' => 'REQ/08/2026/M01',
            'status' => 'bidding',
        ]);
        $prItem = PrItem::create([
            'pr_id' => $pr->id,
            'hs_code' => '7209.16.00',
            'material_name' => 'Migration Test Item',
            'quantity' => 1,
            'shape' => 'Flat',
            'thickness' => 2,
            'width' => 1000,
            'length' => 2000,
            'weight_needed' => 100,
        ]);
        $quotation = Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $supplier->id,
            'currency' => 'USD',
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
        $quotation->items()->create([
            'pr_item_id' => $prItem->id,
            'is_available' => true,
            'price_per_kg' => 2.5,
            'amount' => 250,
        ]);

        $this->assertTrue(Schema::hasColumn('quotation_items', 'is_available'));
        $this->assertTrue(Schema::hasColumn('quotation_items', 'offered_weight_source'));

        // Execute rollback
        $migration->down();

        $this->assertFalse(Schema::hasColumn('quotation_items', 'is_available'));
        $this->assertFalse(Schema::hasColumn('quotation_items', 'offered_weight_source'));
        $this->assertFalse(Schema::hasColumn('quotation_items', 'offered_weight_per_unit'));
        $this->assertFalse(Schema::hasColumn('quotation_items', 'available_length_min'));
        $this->assertFalse(Schema::hasColumn('quotation_items', 'available_length_max'));

        // Re-run up()
        $migration->up();

        $this->assertTrue(Schema::hasColumn('quotation_items', 'is_available'));
        $this->assertTrue(Schema::hasColumn('quotation_items', 'offered_weight_source'));
    }

    public function test_rollback_is_refused_safely_when_null_prices_exist(): void
    {
        $migration = $this->getMigration();

        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $period = Period::create([
            'name' => 'Migration Test Period 2',
            'month' => 8,
            'year' => 2026,
            'status' => 'open',
            'created_by' => $admin->id,
        ]);
        $pr = PurchaseRequisition::create([
            'period_id' => $period->id,
            'created_by' => $purchasing->id,
            'pr_number' => 'REQ/08/2026/M02',
            'status' => 'bidding',
        ]);
        $prItem = PrItem::create([
            'pr_id' => $pr->id,
            'hs_code' => '7209.16.00',
            'material_name' => 'Migration Test Item 2',
            'quantity' => 1,
            'shape' => 'Flat',
            'thickness' => 2,
            'width' => 1000,
            'length' => 2000,
            'weight_needed' => 100,
        ]);
        $quotation = Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $supplier->id,
            'currency' => 'USD',
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
        $quotation->items()->create([
            'pr_item_id' => $prItem->id,
            'is_available' => false,
            'price_per_kg' => null,
            'amount' => 0,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot restore quotation_items.price_per_kg to NOT NULL while unavailable rows have null prices.');

        $migration->down();
    }

    public function test_forward_migration_after_refused_rollback_and_manual_resolution(): void
    {
        $migration = $this->getMigration();

        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $period = Period::create([
            'name' => 'Migration Test Period 3',
            'month' => 8,
            'year' => 2026,
            'status' => 'open',
            'created_by' => $admin->id,
        ]);
        $pr = PurchaseRequisition::create([
            'period_id' => $period->id,
            'created_by' => $purchasing->id,
            'pr_number' => 'REQ/08/2026/M03',
            'status' => 'bidding',
        ]);
        $prItem = PrItem::create([
            'pr_id' => $pr->id,
            'hs_code' => '7209.16.00',
            'material_name' => 'Migration Test Item 3',
            'quantity' => 1,
            'shape' => 'Flat',
            'thickness' => 2,
            'width' => 1000,
            'length' => 2000,
            'weight_needed' => 100,
        ]);
        $quotation = Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $supplier->id,
            'currency' => 'USD',
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
        $item = $quotation->items()->create([
            'pr_item_id' => $prItem->id,
            'is_available' => false,
            'price_per_kg' => null,
            'amount' => 0,
        ]);

        // Attempt rollback which throws
        try {
            $migration->down();
            $this->fail('Expected down() to throw RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Cannot restore quotation_items.price_per_kg', $e->getMessage());
        }

        // Columns must still exist since rollback refused
        $this->assertTrue(Schema::hasColumn('quotation_items', 'is_available'));

        // Operator resolves data as per runbook: backfills NULL prices
        DB::table('quotation_items')->whereNull('price_per_kg')->update(['price_per_kg' => 0]);

        // Rollback now succeeds
        $migration->down();
        $this->assertFalse(Schema::hasColumn('quotation_items', 'is_available'));

        // Forward migration succeeds
        $migration->up();
        $this->assertTrue(Schema::hasColumn('quotation_items', 'is_available'));
    }
}
