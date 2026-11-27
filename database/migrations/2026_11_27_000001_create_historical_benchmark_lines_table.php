<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reference_price_imports', function (Blueprint $table): void {
            $table->string('import_type', 30)->default('rules')->after('id');
            $table->index(['import_type', 'uploaded_by', 'status'], 'ref_price_imports_type_user_status_idx');
        });

        Schema::create('historical_benchmark_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_id')->nullable()->constrained('reference_price_imports')->nullOnDelete();
            $table->string('po_number', 100);
            $table->string('po_number_normalized', 100);
            $table->unsignedInteger('line_no')->default(1);
            $table->date('po_date');
            $table->timestamp('po_datetime_utc');
            $table->unsignedSmallInteger('po_year');
            $table->string('supplier_name', 255);
            $table->foreignId('supplier_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('material_master_id')->constrained('material_masters')->restrictOnDelete();
            $table->string('material_code_snapshot', 100);
            $table->string('shape', 20);
            $table->decimal('key_dimension', 10, 4);
            $table->unsignedInteger('rounded_dimension');
            $table->decimal('ordered_weight_kg', 18, 4);
            $table->string('currency', 3);
            $table->decimal('price_per_kg', 15, 4);
            $table->string('pr_number', 100)->nullable();
            $table->string('notes', 1000)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['po_number_normalized', 'material_master_id', 'shape', 'key_dimension', 'line_no'],
                'hist_bench_natural_unique'
            );
            $table->index(
                ['material_master_id', 'shape', 'rounded_dimension', 'po_datetime_utc'],
                'hist_bench_lookup_idx'
            );
            $table->index(['po_datetime_utc', 'id'], 'hist_bench_po_dt_idx');
            $table->index(['po_year', 'currency'], 'hist_bench_year_curr_idx');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                ALTER TABLE historical_benchmark_lines
                ADD CONSTRAINT hist_bench_positive_metrics_check
                CHECK (
                    key_dimension > 0
                    AND rounded_dimension >= 1
                    AND ordered_weight_kg > 0
                    AND price_per_kg > 0
                    AND line_no >= 1
                )
                SQL);

            DB::statement(<<<'SQL'
                ALTER TABLE historical_benchmark_lines
                ADD CONSTRAINT hist_bench_shape_check
                CHECK (shape IN ('Flat', 'Round', 'Hollow'))
                SQL);

            DB::statement(<<<'SQL'
                ALTER TABLE historical_benchmark_lines
                ADD CONSTRAINT hist_bench_currency_check
                CHECK (currency IN ('IDR', 'USD', 'JPY', 'CNY'))
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('historical_benchmark_lines');

        Schema::table('reference_price_imports', function (Blueprint $table): void {
            $table->dropIndex('ref_price_imports_type_user_status_idx');
            $table->dropColumn('import_type');
        });
    }
};

