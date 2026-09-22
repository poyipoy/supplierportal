<?php

namespace Tests\Feature\LocalInvoice;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LocalInvoiceMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_supplier_backfill_and_schema_constraints(): void
    {
        $this->assertSame('adasi_portal_test', DB::connection()->getDatabaseName());
        $migration = require database_path('migrations/2026_09_08_000001_add_supplier_business_scopes.php');
        $migration->down();
        $supplier = User::create(['name' => 'Legacy supplier', 'email' => 'legacy@example.test', 'password' => 'password', 'role' => 'supplier', 'is_active' => true]);
        $admin = User::create(['name' => 'Legacy admin', 'email' => 'admin@example.test', 'password' => 'password', 'role' => 'admin', 'is_active' => true]);
        $migration->up();
        (require database_path('migrations/2026_09_11_000001_update_user_roles_accounting_to_finance_and_add_ga.php'))->up();
        $this->assertDatabaseHas('supplier_scopes', ['supplier_id' => $supplier->id, 'scope' => 'import']);
        $this->assertDatabaseMissing('supplier_scopes', ['supplier_id' => $admin->id]);
        $this->assertSame(0, DB::table('local_invoices')->count());
        $this->assertTrue(Schema::hasColumn('suppliers', 'payment_term_days'));
        $this->assertFalse(Schema::hasColumn('local_invoices', 'purchase_order_id'));
        $indexes = collect(Schema::getIndexes('local_invoices'));
        $this->assertTrue($indexes->contains(fn ($index) => $index['unique'] && $index['columns'] === ['supplier_id', 'invoice_number']));
        $this->assertTrue($indexes->contains(fn ($index) => $index['unique'] && $index['columns'] === ['submission_number']));
        $this->actingAs($supplier)->get(route('supplier.dashboard'))->assertOk();
        $this->get(route('local-supplier.dashboard'))->assertForbidden();
    }
}
