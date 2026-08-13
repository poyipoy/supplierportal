<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_master_id')->constrained('material_masters')->restrictOnDelete();
            $table->string('alias', 100);
            $table->string('normalized_alias', 100)->unique();
            $table->string('source_note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_aliases');
    }
};
