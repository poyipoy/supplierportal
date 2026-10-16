<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit of Material Master / HS Code Rule changes (Q3, I-16):
 * created, updated, status_changed, deleted and delete_refused. entity_id is a
 * plain copy so a hard-deleted master keeps its history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('master_data_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('entity_type', 40);
            $table->unsignedBigInteger('entity_id');
            $table->string('action', 30);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_role', 20)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['entity_type', 'entity_id', 'created_at']);
        });
    }

    public function down(): void
    {
        $rows = DB::table('master_data_audit_logs')->count();

        if ($rows > 0) {
            throw new RuntimeException("Cannot roll back master data audit history: {$rows} audit row(s) exist.");
        }

        Schema::dropIfExists('master_data_audit_logs');
    }
};
