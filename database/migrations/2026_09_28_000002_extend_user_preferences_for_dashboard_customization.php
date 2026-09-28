<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_preferences', function (Blueprint $table): void {
            $table->string('accent', 20)->default(config('user_preferences.defaults.accent', 'brand'));
            $table->json('dashboard_preferences')->default(DB::raw('(JSON_OBJECT())'));
            $table->unsignedBigInteger('sidebar_revision')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('user_preferences', function (Blueprint $table): void {
            $table->dropColumn(['accent', 'dashboard_preferences', 'sidebar_revision']);
        });
    }
};
