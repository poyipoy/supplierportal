<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_presets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('export_key', 64);
            $table->string('name', 80);
            $table->json('columns');
            $table->json('filters')->nullable();
            $table->string('format', 8)->default('xlsx');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->unique(['user_id', 'export_key', 'name']);
            $table->index(['user_id', 'export_key', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_presets');
    }
};
