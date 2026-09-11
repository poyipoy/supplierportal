<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('local_invoice_sequences', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedBigInteger('last_number')->default(0);
        });
        Schema::create('local_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('submission_number')->unique();
            $table->foreignId('supplier_id')->constrained('users')->restrictOnDelete();
            $table->string('invoice_number', 100);
            $table->date('invoice_date');
            $table->string('po_number', 100)->index();
            $table->char('currency', 3)->default('IDR');
            $table->decimal('invoice_amount', 20, 2);
            $table->decimal('tax_amount', 20, 2);
            $table->unsignedSmallInteger('payment_term_days_snapshot');
            $table->unsignedInteger('revision_number')->default(1);
            $table->enum('status', ['WAITING_PHYSICAL_DOCUMENT', 'UNDER_REVIEW', 'NEED_REVISION', 'REJECTED', 'APPROVED', 'PAYMENT_SCHEDULED', 'COMPLETED']);
            $table->timestamp('submitted_at');
            $table->timestamp('physical_verified_at')->nullable();
            $table->timestamp('review_started_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->date('due_date')->nullable()->index();
            $table->timestamp('payment_scheduled_at')->nullable();
            $table->date('scheduled_payment_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['supplier_id', 'invoice_number']);
            $table->index(['supplier_id', 'status']);
            $table->index(['status', 'submitted_at']);
        });
        Schema::create('local_invoice_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('local_invoice_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('revision_number');
            $table->string('invoice_number', 100);
            $table->date('invoice_date');
            $table->string('po_number', 100);
            $table->decimal('invoice_amount', 20, 2);
            $table->decimal('tax_amount', 20, 2);
            $table->text('reason')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('resubmitted_at')->nullable();
            $table->timestamps();
            $table->unique(['local_invoice_id', 'revision_number'], 'local_revision_unique');
            $table->unique(['id', 'local_invoice_id'], 'local_revision_parent_unique');
        });
        Schema::create('local_invoice_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('local_invoice_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('local_invoice_revision_id');
            $table->foreign(['local_invoice_revision_id', 'local_invoice_id'], 'local_document_revision_fk')->references(['id', 'local_invoice_id'])->on('local_invoice_revisions')->restrictOnDelete();
            $table->enum('document_type', ['invoice', 'tax_invoice', 'supporting']);
            $table->string('file_path')->unique();
            $table->string('original_filename');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['local_invoice_revision_id', 'document_type'], 'local_document_type_unique');
        });
        Schema::create('local_invoice_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('local_invoice_id')->unique()->constrained()->restrictOnDelete();
            $table->string('receipt_number')->unique();
            $table->timestamp('issued_at');
            $table->timestamps();
        });
        Schema::create('local_invoice_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('local_invoice_id')->constrained()->restrictOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('event');
            $table->text('notes')->nullable();
            $table->timestamp('created_at');
        });
        Schema::create('local_invoice_physical_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('local_invoice_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('revision_number');
            $table->enum('status', ['matched', 'invalidated']);
            $table->foreignId('verified_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at');
            $table->text('notes')->nullable();
            $table->timestamp('created_at');
            $table->unique(['local_invoice_id', 'revision_number', 'status'], 'local_physical_revision_unique');
        });
    }

    public function down(): void
    {
        foreach (['local_invoice_physical_verifications', 'local_invoice_status_histories', 'local_invoice_receipts', 'local_invoice_documents', 'local_invoice_revisions', 'local_invoices', 'local_invoice_sequences'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
