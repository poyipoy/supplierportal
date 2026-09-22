<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class UserRoleMigrationRollbackTest extends TestCase
{
    use RefreshDatabase;

    private function getMigration()
    {
        return require database_path('migrations/2026_09_11_000001_update_user_roles_accounting_to_finance_and_add_ga.php');
    }

    public function test_clean_rollback_reverts_enum_when_no_finance_or_ga_users_exist(): void
    {
        $migration = $this->getMigration();

        // Ensure no finance or GA users exist
        DB::table('users')->whereIn('role', ['finance', 'ga'])->delete();

        try {
            $migration->down();

            // Schema verification: enum contains exactly the old roles
            $columnType = strtolower(DB::selectOne(
                "SELECT COLUMN_TYPE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'role'"
            )->COLUMN_TYPE);
            $this->assertSame("enum('admin','purchasing','supplier','qc','accounting')", $columnType);
            $this->assertStringNotContainsString('finance', $columnType);
            $this->assertStringNotContainsString('ga', $columnType);

            // Verification: can insert accounting user under rolled-back enum
            $userId = DB::table('users')->insertGetId([
                'name' => 'Accounting User Clean',
                'email' => 'accounting.clean@adasi.co.id',
                'password' => 'secret',
                'role' => 'accounting',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->assertSame('accounting', DB::table('users')->where('id', $userId)->value('role'));

            // Verification: finance user can no longer be stored under rolled-back enum
            $financeRejected = false;
            try {
                DB::table('users')->insert([
                    'name' => 'Finance User Rejected',
                    'email' => 'finance.rejected@adasi.co.id',
                    'password' => 'secret',
                    'role' => 'finance',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (\Throwable $e) {
                $financeRejected = true;
            }
            $this->assertTrue($financeRejected, 'Expected inserting finance user after rollback to fail.');
        } finally {
            $migration->up();
        }
    }

    public function test_users_migrated_from_accounting_to_finance_are_reverted_unambiguously_on_rollback(): void
    {
        $migration = $this->getMigration();

        // Clear existing finance users
        DB::table('users')->where('role', 'finance')->delete();
        DB::table('auth_audit_logs')->where('event', 'role_migrated_accounting_to_finance')->delete();

        // Create user with accounting role
        $userId = DB::table('users')->insertGetId([
            'name' => 'Accounting Migrated User',
            'email' => 'accounting.migrated@adasi.co.id',
            'password' => 'secret',
            'role' => 'accounting',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Run up(): migrates to finance and records in audit log
        $migration->up();
        $this->assertSame('finance', DB::table('users')->where('id', $userId)->value('role'));
        $this->assertDatabaseHas('auth_audit_logs', [
            'user_id' => $userId,
            'event' => 'role_migrated_accounting_to_finance',
        ]);

        try {
            // Run down(): unambiguous migration reversal
            $migration->down();

            // Schema verification: enum contains exactly the old roles
            $columnType = strtolower(DB::selectOne(
                "SELECT COLUMN_TYPE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'role'"
            )->COLUMN_TYPE);
            $this->assertSame("enum('admin','purchasing','supplier','qc','accounting')", $columnType);
            $this->assertStringNotContainsString('finance', $columnType);
            $this->assertStringNotContainsString('ga', $columnType);

            $this->assertSame('accounting', DB::table('users')->where('id', $userId)->value('role'));
            $this->assertDatabaseMissing('auth_audit_logs', [
                'user_id' => $userId,
                'event' => 'role_migrated_accounting_to_finance',
            ]);
        } finally {
            $migration->up();
        }
    }

    public function test_presence_of_finance_users_created_after_migration_refuses_rollback(): void
    {
        $migration = $this->getMigration();

        // Clear audit logs to simulate a native finance user created post-migration
        DB::table('auth_audit_logs')->where('event', 'role_migrated_accounting_to_finance')->delete();

        $userId = DB::table('users')->insertGetId([
            'name' => 'Native Finance User',
            'email' => 'finance.native@adasi.co.id',
            'password' => 'secret',
            'role' => 'finance',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $thrown = false;
        try {
            $migration->down();
        } catch (RuntimeException $e) {
            $thrown = true;
            $this->assertStringContainsString('Cannot rollback: Finance users exist that were created after migration.', $e->getMessage());
        }

        $this->assertTrue($thrown, 'Expected down() to refuse rollback when native finance users exist.');

        // User role is preserved without silent data loss
        $this->assertSame('finance', DB::table('users')->where('id', $userId)->value('role'));

        // Enum remains unchanged
        $columnType = strtolower(DB::selectOne(
            "SELECT COLUMN_TYPE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'role'"
        )->COLUMN_TYPE);
        $this->assertStringContainsString('finance', $columnType);
    }

    public function test_presence_of_ga_users_refuses_rollback(): void
    {
        $migration = $this->getMigration();

        $userId = DB::table('users')->insertGetId([
            'name' => 'GA Officer',
            'email' => 'ga.officer@adasi.co.id',
            'password' => 'secret',
            'role' => 'ga',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $thrown = false;
        try {
            $migration->down();
        } catch (RuntimeException $e) {
            $thrown = true;
            $this->assertStringContainsString('Cannot rollback: Users with role "ga" exist.', $e->getMessage());
        }

        $this->assertTrue($thrown, 'Expected down() to refuse rollback when GA users exist.');

        // GA user is preserved
        $this->assertSame('ga', DB::table('users')->where('id', $userId)->value('role'));

        // Enum remains unchanged
        $columnType = strtolower(DB::selectOne(
            "SELECT COLUMN_TYPE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'role'"
        )->COLUMN_TYPE);
        $this->assertStringContainsString('ga', $columnType);
    }

    public function test_unsafe_rollback_conditions_preserve_database_state_and_forward_migration_operates_normally(): void
    {
        $migration = $this->getMigration();

        $gaUserId = DB::table('users')->insertGetId([
            'name' => 'GA Safety Test',
            'email' => 'ga.safety@adasi.co.id',
            'password' => 'secret',
            'role' => 'ga',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $financeUserId = DB::table('users')->insertGetId([
            'name' => 'Finance Safety Test',
            'email' => 'finance.safety@adasi.co.id',
            'password' => 'secret',
            'role' => 'finance',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $migration->down();
            $this->fail('Expected rollback to throw RuntimeException');
        } catch (RuntimeException $e) {
            // Refusal was triggered safely
            $this->assertTrue(true);
        }

        // Roles remain intact
        $this->assertSame('ga', DB::table('users')->where('id', $gaUserId)->value('role'));
        $this->assertSame('finance', DB::table('users')->where('id', $financeUserId)->value('role'));

        // Forward migration remains healthy and idempotent
        $migration->up();
        $this->assertSame('ga', DB::table('users')->where('id', $gaUserId)->value('role'));
        $this->assertSame('finance', DB::table('users')->where('id', $financeUserId)->value('role'));
    }

    public function test_schema_verification_asserts_exact_pre_migration_enum_without_finance_or_ga(): void
    {
        $migration = $this->getMigration();

        // Clear finance and ga users to allow clean rollback
        DB::table('users')->whereIn('role', ['finance', 'ga'])->delete();

        try {
            $migration->down();

            $columnType = strtolower(DB::selectOne(
                "SELECT COLUMN_TYPE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'role'"
            )->COLUMN_TYPE);

            // Assert exact enum contents
            $this->assertSame("enum('admin','purchasing','supplier','qc','accounting')", $columnType);
            $this->assertStringNotContainsString('finance', $columnType);
            $this->assertStringNotContainsString('ga', $columnType);
        } finally {
            $migration->up();
        }
    }
}
