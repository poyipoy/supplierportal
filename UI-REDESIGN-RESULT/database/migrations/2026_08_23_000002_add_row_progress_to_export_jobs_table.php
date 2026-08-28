<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('export_jobs', function (Blueprint $table) {
            $table->unsignedBigInteger('total_rows')->default(0)->after('progress');
            $table->unsignedBigInteger('processed_rows')->default(0)->after('total_rows');
            $table->json('processed_chunks')->nullable()->after('processed_rows');
        });

        DB::table('export_jobs')->where('status', 'completed')->update([
            'processed_chunks' => json_encode([]),
        ]);
    }

    public function down(): void
    {
        Schema::table('export_jobs', function (Blueprint $table) {
            $table->dropColumn(['total_rows', 'processed_rows', 'processed_chunks']);
        });
    }
};
