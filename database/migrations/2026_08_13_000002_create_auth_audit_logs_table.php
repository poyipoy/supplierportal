<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('auth_audit_logs')) {
            return;
        }

        Schema::create('auth_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email_attempted')->nullable();
            $table->string('event', 80);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['event', 'created_at']);
            $table->index(['email_attempted', 'created_at']);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('auth_audit_logs')) {
            Schema::drop('auth_audit_logs');
        }
    }
};
