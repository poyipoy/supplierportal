<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pr_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pr_id')->constrained('purchase_requirements')->cascadeOnDelete();
            $table->string('hs_code')->nullable();
            $table->string('material_name');
            $table->string('shape')->nullable();
            $table->decimal('thickness', 10, 4)->nullable();
            $table->decimal('d_inner', 10, 4)->nullable();
            $table->decimal('d_outer', 10, 4)->nullable();
            $table->decimal('width', 10, 4)->nullable();
            $table->decimal('length', 10, 4)->nullable();
            $table->decimal('weight_needed', 12, 4)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pr_items');
    }
};
