<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Master Reference Price (Master Harga, decisions 25-27, 33). Evaluation-only:
 * no transaction reads it. Versions are append-only (supersede/void, never
 * edit or delete). Overlap prevention is a service rule under a
 * material_masters row lock (Mission 06B); the CHECKs below cover what a
 * single row can violate.
 */
return new class extends Migration
{
    private const TABLE = 'material_reference_prices';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->foreignId('material_master_id')->constrained('material_masters')->restrictOnDelete();
            $table->string('shape', 20);
            $table->string('dimension_field', 20);
            $table->decimal('dimension_min', 10, 4);
            $table->decimal('dimension_max', 10, 4);
            $table->string('currency', 3);
            $table->decimal('price_per_kg', 15, 4);
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->string('status', 10)->default('active');
            $table->foreignId('supersedes_id')->nullable()->constrained(self::TABLE)->restrictOnDelete();
            $table->unsignedInteger('version_no')->default(1);
            $table->text('notes')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['material_master_id', 'shape', 'currency', 'status', 'valid_from'], 'material_reference_prices_lookup_index');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            // Text codes compare as binary so the default *_ci collation cannot
            // admit non-canonical case ('flat', 'usd').
            $checks = [
                'material_reference_prices_shape_allowed' => "CAST(shape AS BINARY) IN ('Flat', 'Round', 'Hollow')",
                // Decision 18: Flat is keyed by thickness, Round/Hollow by outer diameter.
                'material_reference_prices_dimension_matches_shape' => "(CAST(shape AS BINARY) = 'Flat' AND CAST(dimension_field AS BINARY) = 'thickness') OR (CAST(shape AS BINARY) IN ('Round', 'Hollow') AND CAST(dimension_field AS BINARY) = 'd_outer')",
                'material_reference_prices_dimension_range_valid' => 'dimension_min > 0 AND dimension_max >= dimension_min',
                'material_reference_prices_price_positive' => 'price_per_kg > 0',
                'material_reference_prices_validity_range_valid' => 'valid_until IS NULL OR valid_until >= valid_from',
                'material_reference_prices_currency_allowed' => "CAST(currency AS BINARY) IN ('USD', 'JPY', 'IDR', 'CNY')",
                'material_reference_prices_status_allowed' => "CAST(status AS BINARY) IN ('active', 'void')",
            ];

            foreach ($checks as $name => $expression) {
                DB::statement('ALTER TABLE '.self::TABLE." ADD CONSTRAINT {$name} CHECK ({$expression})");
            }
        }
    }

    public function down(): void
    {
        $prices = DB::table(self::TABLE)->count();

        if ($prices > 0) {
            throw new RuntimeException("Cannot roll back Master Reference Price: {$prices} reference price row(s) exist.");
        }

        Schema::dropIfExists(self::TABLE);
    }
};
