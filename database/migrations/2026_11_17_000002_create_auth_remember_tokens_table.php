<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('auth_remember_tokens')) {
            return;
        }

        Schema::create('auth_remember_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->string('session_id')->nullable()->index();
            $table->char('device_hash', 64)->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'session_id']);
            $table->index(['user_id', 'device_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_remember_tokens');
    }
};
