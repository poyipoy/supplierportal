<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sla_escalation_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('rule_key', 64);
            $table->string('subject_type', 191);
            $table->unsignedBigInteger('subject_id');
            $table->string('stage', 32);
            $table->foreignId('recipient_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('recipient_role', 32)->nullable();
            $table->timestamp('dispatched_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['rule_key', 'subject_type', 'subject_id', 'stage'], 'sla_escalation_logs_unique');
            $table->index(['subject_type', 'subject_id'], 'sla_escalation_logs_subject_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sla_escalation_logs');
    }
};
