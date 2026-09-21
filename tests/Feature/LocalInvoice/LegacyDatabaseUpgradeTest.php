<?php

namespace Tests\Feature\LocalInvoice;

use App\Models\LocalInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LegacyDatabaseUpgradeTest extends TestCase
{
    use DatabaseTruncation;

    protected function beforeTruncatingDatabase(): void
    {
        $this->assertSame('adasi_portal_test', DB::connection()->getDatabaseName());
    }

    protected function tearDown(): void
    {
        $this->truncateTablesForAllConnections();
        parent::tearDown();
    }

    public function test_corrective_migration_upgrades_populated_legacy_statuses_safely(): void
    {
        $supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);

        // Insert legacy rows with legacy statuses directly
        $invReview = DB::table('local_invoices')->insertGetId([
            'supplier_id' => $supplier->id,
            'submission_number' => 'SUB/TEST/001',
            'invoice_number' => 'INV-LEGACY-001',
            'invoice_date' => '2026-08-01',
            'po_number' => 'PO-LEGACY-001',
            'invoice_amount' => 1000000.00,
            'tax_amount' => 110000.00,
            'status' => 'UNDER_REVIEW',
            'revision_number' => 1,
            'submitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $invApproved = DB::table('local_invoices')->insertGetId([
            'supplier_id' => $supplier->id,
            'submission_number' => 'SUB/TEST/002',
            'invoice_number' => 'INV-LEGACY-002',
            'invoice_date' => '2026-08-02',
            'po_number' => 'PO-LEGACY-002',
            'invoice_amount' => 2000000.00,
            'tax_amount' => 220000.00,
            'status' => 'APPROVED',
            'revision_number' => 1,
            'submitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $invScheduled = DB::table('local_invoices')->insertGetId([
            'supplier_id' => $supplier->id,
            'submission_number' => 'SUB/TEST/003',
            'invoice_number' => 'INV-LEGACY-003',
            'invoice_date' => '2026-08-03',
            'po_number' => 'PO-LEGACY-003',
            'invoice_amount' => 3000000.00,
            'tax_amount' => 330000.00,
            'status' => 'PAYMENT_SCHEDULED',
            'revision_number' => 1,
            'submitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $invCompleted = DB::table('local_invoices')->insertGetId([
            'supplier_id' => $supplier->id,
            'submission_number' => 'SUB/TEST/004',
            'invoice_number' => 'INV-LEGACY-004',
            'invoice_date' => '2026-08-04',
            'po_number' => 'PO-LEGACY-004',
            'invoice_amount' => 4000000.00,
            'tax_amount' => 440000.00,
            'status' => 'COMPLETED',
            'revision_number' => 1,
            'submitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Run the corrective forward migration
        $migration = require database_path('migrations/2026_09_14_000001_harden_local_invoice_and_payment_invariants.php');
        $migration->up();

        // Verify status conversion:
        // UNDER_REVIEW -> UNDER_VERIFICATION
        $this->assertSame(
            LocalInvoice::STATUS_UNDER_VERIFICATION,
            DB::table('local_invoices')->where('id', $invReview)->value('status')
        );

        // APPROVED -> READY_TO_PAY
        $this->assertSame(
            LocalInvoice::STATUS_READY_TO_PAY,
            DB::table('local_invoices')->where('id', $invApproved)->value('status')
        );

        // PAYMENT_SCHEDULED -> READY_TO_PAY
        $this->assertSame(
            LocalInvoice::STATUS_READY_TO_PAY,
            DB::table('local_invoices')->where('id', $invScheduled)->value('status')
        );

        // COMPLETED -> PAID
        $this->assertSame(
            LocalInvoice::STATUS_PAID,
            DB::table('local_invoices')->where('id', $invCompleted)->value('status')
        );

        // Verify staging tables exist
        $this->assertTrue(Schema::hasTable('local_purchase_orders'));
        $this->assertTrue(Schema::hasTable('local_goods_receipts'));
    }
}
