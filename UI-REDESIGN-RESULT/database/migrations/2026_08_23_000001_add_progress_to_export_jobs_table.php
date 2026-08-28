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
            $table->string('progress_stage', 32)->default('queued')->after('status');
            $table->unsignedTinyInteger('progress')->nullable()->after('progress_stage');
        });

        DB::table('export_jobs')->where('status', 'processing')->update([
            'progress_stage' => 'generating',
        ]);
        DB::table('export_jobs')->where('status', 'completed')->update([
            'progress_stage' => 'completed',
            'progress' => 100,
        ]);
        DB::table('export_jobs')->where('status', 'failed')->update([
            'progress_stage' => 'failed',
        ]);
    }

    public function down(): void
    {
        Schema::table('export_jobs', function (Blueprint $table) {
            $table->dropColumn(['progress_stage', 'progress']);
        });
    }
};
