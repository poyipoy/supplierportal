<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_known_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('device_hash', 64);
            $table->string('last_ip_address', 45)->nullable();
            $table->string('last_user_agent', 512)->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');

            $table->unique(['user_id', 'device_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_known_devices');
    }
};
