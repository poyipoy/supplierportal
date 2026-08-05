<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotation_items', function (Blueprint $table) {
            if (! Schema::hasColumn('quotation_items', 'available_qty')) {
                $table->unsignedInteger('available_qty')->nullable()->after('amount');
            }
            if (! Schema::hasColumn('quotation_items', 'available_thickness')) {
                $table->decimal('available_thickness', 10, 4)->nullable()->after('available_qty');
            }
            if (! Schema::hasColumn('quotation_items', 'available_d_inner')) {
                $table->decimal('available_d_inner', 10, 4)->nullable()->after('available_thickness');
            }
            if (! Schema::hasColumn('quotation_items', 'available_d_outer')) {
                $table->decimal('available_d_outer', 10, 4)->nullable()->after('available_d_inner');
            }
            if (! Schema::hasColumn('quotation_items', 'available_width')) {
                $table->decimal('available_width', 10, 4)->nullable()->after('available_d_outer');
            }
            if (! Schema::hasColumn('quotation_items', 'available_length')) {
                $table->decimal('available_length', 10, 4)->nullable()->after('available_width');
            }
        });
    }

    public function down(): void
    {
        $columns = collect([
            'available_qty',
            'available_thickness',
            'available_d_inner',
            'available_d_outer',
            'available_width',
            'available_length',
        ])->filter(fn (string $column) => Schema::hasColumn('quotation_items', $column))->all();

        if ($columns !== []) {
            Schema::table('quotation_items', function (Blueprint $table) use ($columns) {
                $table->dropColumn($columns);
            });
        }
    }
};
