<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_preferences', function (Blueprint $table): void {
            $table->string('timezone', 64)->default('system');
            $table->string('date_format', 16)->default('system');
            $table->string('time_format', 8)->default('system');
            $table->string('number_format', 20)->default('system');
        });
    }

    public function down(): void
    {
        Schema::table('user_preferences', function (Blueprint $table): void {
            $table->dropColumn(['timezone', 'date_format', 'time_format', 'number_format']);
        });
    }
};
