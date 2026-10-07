<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('export_jobs', function (Blueprint $table): void {
            $table->string('format', 8)->default('xlsx');
            $table->json('export_options')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('export_jobs')->where('format', '!=', 'xlsx')->orWhereNotNull('export_options')->exists()) {
            throw new RuntimeException('Cannot remove advanced export metadata while CSV/options records exist.');
        }
        Schema::table('export_jobs', function (Blueprint $table): void {
            $table->dropColumn(['format', 'export_options']);
        });
    }
};
