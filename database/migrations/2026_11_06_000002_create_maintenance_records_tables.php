<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier Portal maintenance windows. A record is Global or Module maintenance;
 * module rows keep their own deactivated_at so one module can be released while
 * the rest of the record still blocks. Records are soft-deleted only, so rollback
 * refuses once any history exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_records', function (Blueprint $table): void {
            $table->id();
            $table->enum('type', ['global', 'module']);
            $table->enum('status', ['draft', 'scheduled', 'active', 'completed'])->default('draft');
            $table->string('title', 150);
            $table->text('description');
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('estimated_end_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'starts_at'], 'maintenance_records_status_start_idx');
        });

        DB::statement('ALTER TABLE maintenance_records ADD CONSTRAINT maintenance_records_window_chk CHECK (starts_at IS NULL OR estimated_end_at IS NULL OR estimated_end_at > starts_at)');
        DB::statement("ALTER TABLE maintenance_records ADD CONSTRAINT maintenance_records_schedule_chk CHECK (status = 'draft' OR (starts_at IS NOT NULL AND estimated_end_at IS NOT NULL))");
        DB::statement("ALTER TABLE maintenance_records ADD CONSTRAINT maintenance_records_completed_chk CHECK ((status = 'completed' AND completed_at IS NOT NULL) OR (status <> 'completed' AND completed_at IS NULL))");

        Schema::create('maintenance_record_modules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('maintenance_record_id')->constrained('maintenance_records')->restrictOnDelete();
            $table->string('module_key', 64);
            $table->dateTime('deactivated_at')->nullable();
            $table->timestamps();

            $table->unique(['maintenance_record_id', 'module_key'], 'maintenance_record_modules_record_key_unique');
            $table->index(['module_key', 'deactivated_at'], 'maintenance_record_modules_key_idx');
        });
    }

    public function down(): void
    {
        $records = DB::table('maintenance_records')->count();

        if ($records > 0) {
            throw new RuntimeException("Cannot roll back maintenance history: {$records} maintenance record(s) exist.");
        }

        Schema::dropIfExists('maintenance_record_modules');
        Schema::dropIfExists('maintenance_records');
    }
};
