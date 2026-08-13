<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_masters', function (Blueprint $table) {
            $table->id();
            $table->string('material_code', 100);
            $table->string('normalized_code', 100)->unique();
            $table->string('raw_category', 100)->nullable();
            $table->string('hs_category', 50)->nullable()->index();
            $table->string('density_profile', 20)->default('steel');
            $table->string('manufacturer_scope', 20)->default('unknown');
            $table->boolean('is_active')->default(true)->index();
            $table->string('source_file')->nullable();
            $table->string('source_sheet')->nullable();
            $table->unsignedInteger('source_row')->nullable();
            $table->string('hs_source_ref')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_masters');
    }
};
