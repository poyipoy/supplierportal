<?php

namespace Tests\Feature;

use App\Models\PaymentBatch;
use App\Models\PaymentGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class PaymentGroupCancelledMigrationRollbackTest extends TestCase
{
    use RefreshDatabase;

    private function getMigration()
    {
        return require database_path('migrations/2026_09_14_000003_add_cancelled_status_to_payment_groups.php');
    }

    public function test_rollback_succeeds_when_no_cancelled_payment_groups_exist(): void
    {
        $migration = $this->getMigration();

        $user = User::factory()->create(['role' => 'finance']);
        $batch = PaymentBatch::create([
            'batch_number' => 'BATCH-TEST-001',
            'batch_type' => 'SUPPLIER',
            'status' => 'DRAFT',
            'created_by' => $user->id,
        ]);

        $group = PaymentGroup::create([
            'payment_batch_id' => $batch->id,
            'payee_type' => 'supplier',
            'payee_id' => $user->id,
            'payee_name' => 'Supplier Test',
            'bank_name' => 'BCA',
            'account_number' => '1234567890',
            'account_holder_name' => 'Supplier Test',
            'subtotal_amount' => 1000000.00,
            'bank_fee' => 0.00,
            'net_payment_amount' => 1000000.00,
            'status' => PaymentGroup::STATUS_UNPAID,
        ]);

        try {
            // Rollback should succeed cleanly
            $migration->down();

            // Verify UNPAID row remains intact
            $this->assertSame(PaymentGroup::STATUS_UNPAID, $group->fresh()->status);
        } finally {
            // Restore forward migration
            $migration->up();
        }
    }

    public function test_rollback_refuses_when_cancelled_rows_exist_and_prevents_corruption(): void
    {
        $migration = $this->getMigration();

        $user = User::factory()->create(['role' => 'finance']);
        $batch = PaymentBatch::create([
            'batch_number' => 'BATCH-TEST-002',
            'batch_type' => 'SUPPLIER',
            'status' => 'DRAFT',
            'created_by' => $user->id,
        ]);

        $group = PaymentGroup::create([
            'payment_batch_id' => $batch->id,
            'payee_type' => 'supplier',
            'payee_id' => $user->id,
            'payee_name' => 'Supplier Test',
            'bank_name' => 'BCA',
            'account_number' => '1234567890',
            'account_holder_name' => 'Supplier Test',
            'subtotal_amount' => 1000000.00,
            'bank_fee' => 0.00,
            'net_payment_amount' => 1000000.00,
            'status' => PaymentGroup::STATUS_CANCELLED,
        ]);

        $thrown = false;
        try {
            $migration->down();
        } catch (RuntimeException $e) {
            $thrown = true;
            $this->assertStringContainsString('Cannot rollback: Payment groups with CANCELLED status exist.', $e->getMessage());
        }

        $this->assertTrue($thrown, 'Expected down() to throw RuntimeException when CANCELLED rows exist.');

        // Data was not corrupted
        $this->assertSame(PaymentGroup::STATUS_CANCELLED, $group->fresh()->status);

        // Normal forward migration still works
        $migration->up();
        $this->assertSame(PaymentGroup::STATUS_CANCELLED, $group->fresh()->status);
    }
}
