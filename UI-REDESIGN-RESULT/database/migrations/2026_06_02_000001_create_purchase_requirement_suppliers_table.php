<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('purchase_requirement_suppliers')) {
            return;
        }

        Schema::create('purchase_requirement_suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pr_id')->constrained('purchase_requirements')->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('invited_at')->nullable();
            $table->timestamps();

            $table->unique(['pr_id', 'supplier_id'], 'pr_supplier_unique');
            $table->index('supplier_id', 'pr_supplier_supplier_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_requirement_suppliers');
    }
};
