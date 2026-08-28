<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Rename main table
        Schema::rename('purchase_requirements', 'purchase_requisitions');

        // 2. Rename pivot table
        Schema::rename('purchase_requirement_suppliers', 'purchase_requisition_suppliers');

        // 3. Update polymorphic conversable_type in conversations
        DB::table('conversations')
            ->where('conversable_type', 'App\\Models\\PurchaseRequirement')
            ->update(['conversable_type' => 'App\\Models\\PurchaseRequisition']);
    }

    public function down(): void
    {
        // 1. Revert polymorphic
        DB::table('conversations')
            ->where('conversable_type', 'App\\Models\\PurchaseRequisition')
            ->update(['conversable_type' => 'App\\Models\\PurchaseRequirement']);

        // 2. Revert pivot table
        Schema::rename('purchase_requisition_suppliers', 'purchase_requirement_suppliers');

        // 3. Revert main table
        Schema::rename('purchase_requisitions', 'purchase_requirements');
    }
};
