<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotation_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('quotation_items', 'is_available')) {
                $table->boolean('is_available')->default(true)->after('amount');
            }
            if (! Schema::hasColumn('quotation_items', 'available_length_min')) {
                $table->decimal('available_length_min', 10, 4)->nullable()->after('available_length');
            }
            if (! Schema::hasColumn('quotation_items', 'available_length_max')) {
                $table->decimal('available_length_max', 10, 4)->nullable()->after('available_length_min');
            }
            if (! Schema::hasColumn('quotation_items', 'offered_weight_per_unit')) {
                $table->decimal('offered_weight_per_unit', 12, 4)->nullable()->after('available_length_max');
            }
            if (! Schema::hasColumn('quotation_items', 'offered_weight_source')) {
                $table->string('offered_weight_source', 20)->nullable()->after('offered_weight_per_unit');
            }
        });

        // An unavailable offer must be able to clear its price.  Keep this
        // change explicit and MySQL-compatible because price_per_kg predates
        // the availability state and is currently NOT NULL.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE quotation_items MODIFY price_per_kg DECIMAL(15,4) NULL DEFAULT NULL');
        }
    }

    public function down(): void
    {
        $nullPrices = DB::table('quotation_items')->whereNull('price_per_kg')->count();
        if ($nullPrices > 0) {
            throw new RuntimeException(
                'Cannot restore quotation_items.price_per_kg to NOT NULL while unavailable rows have null prices. '
                .'Resolve those rows or deploy the forward schema again before rolling back.'
            );
        }

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE quotation_items MODIFY price_per_kg DECIMAL(15,4) NOT NULL DEFAULT 0');
        }

        $columns = collect([
            'offered_weight_source',
            'offered_weight_per_unit',
            'available_length_max',
            'available_length_min',
            'is_available',
        ])->filter(fn (string $column) => Schema::hasColumn('quotation_items', $column))->all();

        if ($columns !== []) {
            Schema::table('quotation_items', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }
};
