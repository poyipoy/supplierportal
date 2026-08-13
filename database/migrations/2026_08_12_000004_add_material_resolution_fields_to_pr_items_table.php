<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pr_items', function (Blueprint $table) {
            $table->foreignId('material_master_id')->nullable()->after('pr_id')
                ->constrained('material_masters')->restrictOnDelete();
            $table->foreignId('hs_code_rule_id')->nullable()->after('hs_code')
                ->constrained('hs_code_rules')->restrictOnDelete();
            $table->string('hs_code_source', 20)->default('legacy')->after('hs_code_rule_id');
            $table->string('hs_code_resolution_status', 30)->default('legacy')->after('hs_code_source');
            $table->foreignId('hs_code_manual_selected_by')->nullable()->after('hs_code_resolution_status')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('hs_code_manual_selected_at')->nullable()->after('hs_code_manual_selected_by');
            $table->string('weight_calculation_status', 20)->default('legacy')->after('weight_needed');
            $table->string('weight_formula_key', 40)->nullable()->after('weight_calculation_status');
            $table->decimal('weight_factor', 10, 6)->nullable()->after('weight_formula_key');
            $table->timestamp('weight_calculated_at')->nullable()->after('weight_factor');

            $table->index(['material_master_id', 'hs_code_resolution_status'], 'pr_items_material_resolution_index');
        });
    }

    public function down(): void
    {
        Schema::table('pr_items', function (Blueprint $table) {
            $table->dropForeign(['hs_code_manual_selected_by']);
            $table->dropForeign(['hs_code_rule_id']);
            $table->dropForeign(['material_master_id']);
            $table->dropIndex('pr_items_material_resolution_index');
            $table->dropColumn([
                'material_master_id',
                'hs_code_rule_id',
                'hs_code_source',
                'hs_code_resolution_status',
                'hs_code_manual_selected_by',
                'hs_code_manual_selected_at',
                'weight_calculation_status',
                'weight_formula_key',
                'weight_factor',
                'weight_calculated_at',
            ]);
        });
    }
};
