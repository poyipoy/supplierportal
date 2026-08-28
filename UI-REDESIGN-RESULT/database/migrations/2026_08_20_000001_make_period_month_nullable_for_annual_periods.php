<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('periods', 'month')) {
            return;
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE periods MODIFY COLUMN month TINYINT UNSIGNED NULL');

            return;
        }

        Schema::table('periods', function (Blueprint $table): void {
            $table->unsignedTinyInteger('month')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('periods', 'month') || DB::table('periods')->whereNull('month')->exists()) {
            return;
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE periods MODIFY COLUMN month TINYINT UNSIGNED NOT NULL');

            return;
        }

        Schema::table('periods', function (Blueprint $table): void {
            $table->unsignedTinyInteger('month')->nullable(false)->change();
        });
    }
};
