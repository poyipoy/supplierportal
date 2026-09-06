<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds the 'all_unavailable' state to quotations.status enum.
     */
    public function up(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE quotations MODIFY COLUMN status ENUM('draft','submitted','revision_requested','accepted','rejected','all_unavailable') DEFAULT 'draft'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $hasAllUnavailable = DB::table('quotations')
                ->where('status', 'all_unavailable')
                ->exists();

            if ($hasAllUnavailable) {
                throw new \RuntimeException('Cannot rollback migration: quotations with status all_unavailable exist in the database.');
            }

            DB::statement("ALTER TABLE quotations MODIFY COLUMN status ENUM('draft','submitted','revision_requested','accepted','rejected') DEFAULT 'draft'");
        }
    }
};
