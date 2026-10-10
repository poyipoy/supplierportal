<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('local_po_document_capacity', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
        });
        DB::table('local_po_document_capacity')->insert(['id' => 1]);
        Schema::create('local_po_document_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('users')->restrictOnDelete();
            $table->string('request_key', 36);
            $table->string('locale', 2);
            $table->string('status', 20)->index();
            $table->boolean('asynchronous')->default(false);
            $table->json('budget');
            $table->string('original_path');
            $table->string('original_filename');
            $table->string('sha256', 64);
            $table->unsignedBigInteger('upload_bytes');
            $table->foreignId('original_file_inspection_id')->constrained('file_inspections')->restrictOnDelete();
            $table->unsignedInteger('pdf_count')->default(0);
            $table->unsignedInteger('processed_count')->default(0);
            $table->unsignedBigInteger('actual_bytes')->default(0);
            $table->unsignedBigInteger('reserved_bytes');
            $table->unsignedInteger('attempt')->default(0);
            $table->unsignedInteger('retry_count')->default(0);
            $table->unsignedBigInteger('processing_milliseconds')->default(0);
            $table->string('dispatch_token', 36);
            $table->string('run_token', 36)->nullable();
            $table->timestamp('dispatch_requested_at');
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('cleaned_at')->nullable();
            $table->string('failure_code', 40)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'request_key'], 'po_document_request_unique');
            $table->index(['status', 'lease_expires_at'], 'po_document_recovery_index');
        });
        Schema::create('local_po_document_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('local_po_document_batches')->restrictOnDelete();
            $table->unsignedInteger('entry_index');
            $table->string('filename');
            $table->foreignId('local_purchase_order_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('declared_bytes');
            $table->unsignedBigInteger('actual_bytes')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->string('staging_path')->nullable();
            $table->string('final_path')->nullable();
            $table->foreignId('file_inspection_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('attachment_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['batch_id', 'entry_index'], 'po_document_entry_unique');
            $table->unique(['batch_id', 'local_purchase_order_id'], 'po_document_target_unique');
            $table->unique('attachment_id');
        });
    }

    public function down(): void
    {
        if (DB::table('local_po_document_batches')->exists()) {
            throw new RuntimeException('Retain PO document batch tracking while any historical or published batch exists.');
        }
        Schema::dropIfExists('local_po_document_entries');
        Schema::dropIfExists('local_po_document_batches');
        Schema::dropIfExists('local_po_document_capacity');
    }
};
