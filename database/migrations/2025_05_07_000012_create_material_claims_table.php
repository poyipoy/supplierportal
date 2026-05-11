<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inspection_id')->constrained('qc_inspections')->cascadeOnDelete();
            $table->foreignId('po_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('submitted_by')->constrained('users');
            $table->foreignId('supplier_id')->constrained('users');
            $table->enum('status', ['draft', 'submitted', 'in_review', 'accepted', 'rejected'])->default('draft');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_claims');
    }
};
