<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE export_jobs MODIFY COLUMN status ENUM('queued','processing','completed','failed','cancelled') NOT NULL DEFAULT 'queued'");
    }

    public function down(): void
    {
        DB::table('export_jobs')
            ->where('status', 'cancelled')
            ->update(['status' => 'failed']);

        DB::statement("ALTER TABLE export_jobs MODIFY COLUMN status ENUM('queued','processing','completed','failed') NOT NULL DEFAULT 'queued'");
    }
};
