<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_preferences', function (Blueprint $table): void {
            // Work-location country and Supplier Import display zone; null import_timezone = follow the country.
            $table->char('country_code', 2)->nullable()->after('timezone');
            $table->string('import_timezone', 64)->nullable()->after('country_code');
        });
    }

    public function down(): void
    {
        Schema::table('user_preferences', function (Blueprint $table): void {
            $table->dropColumn(['country_code', 'import_timezone']);
        });
    }
};
