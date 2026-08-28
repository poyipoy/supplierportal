<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hs_code_rules', function (Blueprint $table) {
            $table->id();
            $table->string('rule_key', 150)->unique();
            $table->string('hs_code', 10)->index();
            $table->string('material_category', 50)->index();
            $table->string('shape', 20)->index();
            $table->json('conditions');
            $table->unsignedSmallInteger('priority')->default(100)->index();
            $table->string('status', 20)->default('active')->index();
            $table->json('source_refs');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(
                ['material_category', 'shape', 'status', 'priority'],
                'hs_rules_resolution_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hs_code_rules');
    }
};
