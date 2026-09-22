<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Alter the role enum to support finance and ga while retaining accounting for migration compatibility
        DB::statement("ALTER TABLE users MODIFY role ENUM('admin','purchasing','supplier','qc','accounting','finance','ga') NOT NULL DEFAULT 'supplier'");

        // 2. Migrate any existing accounting users to finance and track them for safe rollback
        $migratedUserIds = DB::table('users')->where('role', 'accounting')->pluck('id')->all();
        if (! empty($migratedUserIds)) {
            DB::table('users')->whereIn('id', $migratedUserIds)->update(['role' => 'finance']);

            if (Schema::hasTable('auth_audit_logs')) {
                foreach ($migratedUserIds as $userId) {
                    DB::table('auth_audit_logs')->insert([
                        'user_id' => $userId,
                        'event' => 'role_migrated_accounting_to_finance',
                        'metadata' => json_encode(['from' => 'accounting', 'to' => 'finance', 'migration' => '2026_09_11_000001']),
                        'created_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // 1. Refuse rollback if GA users exist (GA role is not in the previous enum)
        if (DB::table('users')->where('role', 'ga')->exists()) {
            throw new RuntimeException('Cannot rollback: Users with role "ga" exist. Reassign or remove GA users before rolling back.');
        }

        // 2. Safely resolve finance users
        $currentFinanceUserIds = DB::table('users')->where('role', 'finance')->pluck('id')->all();

        if (! empty($currentFinanceUserIds)) {
            $migratedUserIds = [];
            if (Schema::hasTable('auth_audit_logs')) {
                $migratedUserIds = DB::table('auth_audit_logs')
                    ->where('event', 'role_migrated_accounting_to_finance')
                    ->pluck('user_id')
                    ->all();
            }

            $unmigratedFinanceUserIds = array_diff($currentFinanceUserIds, $migratedUserIds);

            // If finance users exist that were created after migration, refuse rollback to prevent destroying their role
            if (! empty($unmigratedFinanceUserIds)) {
                throw new RuntimeException('Cannot rollback: Finance users exist that were created after migration. Reassign or remove finance users before rolling back.');
            }

            // Unambiguous: All current finance users were originally accounting users. Safely revert them.
            if (! empty($migratedUserIds)) {
                DB::table('users')->whereIn('id', $migratedUserIds)->update(['role' => 'accounting']);
                if (Schema::hasTable('auth_audit_logs')) {
                    DB::table('auth_audit_logs')->where('event', 'role_migrated_accounting_to_finance')->delete();
                }
            }
        }

        // 3. Restore previous enum without 'ga' and without 'finance'
        DB::statement("ALTER TABLE users MODIFY role ENUM('admin','purchasing','supplier','qc','accounting') NOT NULL DEFAULT 'supplier'");
    }
};
