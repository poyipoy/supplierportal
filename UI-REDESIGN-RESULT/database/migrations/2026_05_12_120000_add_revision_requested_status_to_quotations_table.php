<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE quotations MODIFY COLUMN status ENUM('draft','submitted','revision_requested','accepted','rejected') DEFAULT 'draft'");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::table('quotations')
            ->where('status', 'revision_requested')
            ->update(['status' => 'draft']);

        DB::statement("ALTER TABLE quotations MODIFY COLUMN status ENUM('draft','submitted','accepted','rejected') DEFAULT 'draft'");
    }
};
