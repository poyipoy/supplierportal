<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Ensures quotation_items.price_per_kg is nullable across all environments
     * (especially staging/production databases where previous migrations may have already run
     * before the column modify statement was added).
     */
    public function up(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE quotation_items MODIFY price_per_kg DECIMAL(15,4) NULL DEFAULT NULL');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $nullPrices = DB::table('quotation_items')->whereNull('price_per_kg')->count();
        if ($nullPrices > 0) {
            return;
        }

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE quotation_items MODIFY price_per_kg DECIMAL(15,4) NOT NULL DEFAULT 0');
        }
    }
};
