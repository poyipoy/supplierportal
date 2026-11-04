<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('annual_exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('revision');
            $table->enum('currency', ['USD', 'JPY', 'CNY']);
            $table->decimal('rate_to_idr', 15, 4);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['year', 'revision', 'currency'], 'annual_rates_year_revision_currency_unique');
        });
        DB::statement('ALTER TABLE annual_exchange_rates ADD CONSTRAINT annual_rates_positive CHECK (rate_to_idr > 0 AND year BETWEEN 1000 AND 9999 AND revision > 0)');
    }

    public function down(): void
    {
        if (DB::table('annual_exchange_rates')->exists()) {
            throw new RuntimeException('Annual exchange-rate history must be preserved before rollback.');
        }
        Schema::dropIfExists('annual_exchange_rates');
    }
};
