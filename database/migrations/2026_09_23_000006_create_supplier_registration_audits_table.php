<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_registration_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')->nullable()->constrained('supplier_registration_attempts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_role', 50)->nullable();
            $table->string('event', 100);
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at'], 'reg_audits_user_created_idx');
            $table->index(['attempt_id', 'created_at'], 'reg_audits_attempt_created_idx');
            $table->index('event', 'reg_audits_event_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_registration_audits');
    }
};
