<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Migrate any existing accounting users to finance before altering the enum
        DB::table('users')->where('role', 'accounting')->update(['role' => 'finance']);

        // 2. Alter the role enum to support finance and ga while retaining accounting for migration compatibility
        DB::statement("ALTER TABLE users MODIFY role ENUM('admin','purchasing','supplier','qc','accounting','finance','ga') NOT NULL DEFAULT 'supplier'");
    }

    public function down(): void
    {
        // Allow accounting back in the enum if rolling back
        DB::statement("ALTER TABLE users MODIFY role ENUM('admin','purchasing','supplier','qc','accounting','finance','ga') NOT NULL DEFAULT 'supplier'");
    }
};
