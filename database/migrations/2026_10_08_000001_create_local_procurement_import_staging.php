<?php

use App\Models\LocalProcurementImport;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('local_procurement_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('kind', 10);
            $table->string('locale', 2);
            $table->string('status', 30)->index();
            $table->string('token_hash', 64)->unique();
            $table->string('checksum', 64);
            $table->string('original_filename');
            $table->unsignedInteger('attempt')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('source_rows')->default(0);
            $table->json('summary')->nullable();
            $table->json('warnings')->nullable();
            $table->text('failure')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->string('owner_job_key', 36)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('cleaned_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });
        Schema::create('local_procurement_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->constrained('local_procurement_imports')->cascadeOnDelete();
            $table->unsignedInteger('attempt');
            $table->unsignedInteger('source_row');
            $table->string('po_key', 100)->collation('utf8mb4_bin');
            $table->string('gr_key', 100)->nullable()->collation('utf8mb4_bin');
            $table->json('values');
            $table->json('errors')->nullable();
            $table->unique(['import_id', 'attempt', 'source_row'], 'local_import_source_unique');
            $table->index(['import_id', 'attempt', 'po_key', 'source_row'], 'local_import_po_rows');
            $table->index(['import_id', 'attempt', 'gr_key', 'source_row'], 'local_import_gr_rows');
        });
        Schema::create('local_procurement_import_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->constrained('local_procurement_imports')->cascadeOnDelete();
            $table->unsignedInteger('attempt');
            $table->string('kind', 5);
            $table->string('document_key', 100)->collation('utf8mb4_bin');
            $table->unsignedInteger('source_row');
            $table->unsignedInteger('source_count')->default(1);
            $table->json('values');
            $table->json('errors')->nullable();
            $table->unique(['import_id', 'attempt', 'kind', 'document_key'], 'local_import_document_unique');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('local_procurement_imports') && DB::table('local_procurement_imports')->whereIn('status', ['QUEUED', 'READING', 'VALIDATING', 'READY', 'IMPORTING'])->exists()) {
            throw new RuntimeException('Drain or cancel active imports before removing staging.');
        }
        if (Schema::hasTable('attachments') && DB::table('attachments')->where('attachable_type', LocalProcurementImport::class)->exists()) {
            throw new RuntimeException('Clean retained private import attachments before removing staging.');
        }
        Schema::dropIfExists('local_procurement_import_records');
        Schema::dropIfExists('local_procurement_import_rows');
        Schema::dropIfExists('local_procurement_imports');
    }
};
