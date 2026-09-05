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
        Schema::create('pr_item_awards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pr_id')->constrained('purchase_requisitions')->cascadeOnDelete();
            $table->foreignId('pr_item_id')->unique()->constrained('pr_items')->cascadeOnDelete();
            $table->foreignId('quotation_id')->constrained('quotations')->cascadeOnDelete();
            $table->foreignId('quotation_item_id')->constrained('quotation_items')->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained('users');
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            $table->foreignId('awarded_by')->constrained('users');
            $table->timestamp('awarded_at');
            $table->timestamps();

            $table->index(['pr_id', 'supplier_id']);
            $table->index(['quotation_id', 'supplier_id']);
            $table->index('purchase_order_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pr_item_awards');
    }
};
