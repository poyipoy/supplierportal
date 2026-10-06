<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->index(['pr_id', 'status'], 'quotations_pr_id_status_index');
        });

        Schema::table('quotation_items', function (Blueprint $table) {
            $table->index(['pr_item_id', 'quotation_id'], 'quotation_items_pr_item_quotation_index');
        });

        Schema::table('purchase_requisitions', function (Blueprint $table) {
            $table->index(['created_at', 'status'], 'pr_created_at_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_requisitions', function (Blueprint $table) {
            $table->dropIndex('pr_created_at_status_index');
        });

        Schema::table('quotation_items', function (Blueprint $table) {
            $table->dropIndex('quotation_items_pr_item_quotation_index');
        });

        Schema::table('quotations', function (Blueprint $table) {
            $table->dropIndex('quotations_pr_id_status_index');
        });
    }
};
