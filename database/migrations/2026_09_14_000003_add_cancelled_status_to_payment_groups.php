<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE payment_groups MODIFY status ENUM('UNPAID', 'PAID', 'CANCELLED') NOT NULL DEFAULT 'UNPAID'");
        }
    }

    public function down(): void
    {
        if (DB::table('payment_groups')->where('status', 'CANCELLED')->exists()) {
            throw new RuntimeException('Cannot rollback: Payment groups with CANCELLED status exist. Reassign or resolve CANCELLED payment groups before rolling back.');
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE payment_groups MODIFY status ENUM('UNPAID', 'PAID') NOT NULL DEFAULT 'UNPAID'");
        }
    }
};
