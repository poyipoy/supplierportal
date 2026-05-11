<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qc_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inspection_id')->constrained('qc_inspections')->cascadeOnDelete();
            $table->foreignId('pr_item_id')->constrained('pr_items')->cascadeOnDelete();
            $table->decimal('actual_thickness', 10, 4)->nullable();
            $table->decimal('actual_d_inner', 10, 4)->nullable();
            $table->decimal('actual_d_outer', 10, 4)->nullable();
            $table->decimal('actual_width', 10, 4)->nullable();
            $table->decimal('actual_length', 10, 4)->nullable();
            $table->decimal('actual_weight', 12, 4)->nullable();
            $table->enum('status', ['ok', 'ng'])->default('ok');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qc_items');
    }
};
