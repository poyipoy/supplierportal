<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE users ALTER COLUMN account_status SET DEFAULT 'PENDING'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE users ALTER COLUMN account_status SET DEFAULT 'ACTIVE'");
    }
};
