<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('label');
            $table->string('export_class');
            $table->json('export_args');
            $table->string('file_name');
            $table->string('file_path')->nullable();
            $table->string('disk')->default('private');
            $table->enum('status', ['queued', 'processing', 'completed', 'failed'])->default('queued');
            $table->text('error_message')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_jobs');
    }
};
