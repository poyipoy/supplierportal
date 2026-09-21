<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('local_invoice_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('local_invoice_id')->constrained('local_invoices')->cascadeOnDelete();
            $table->unsignedInteger('revision_number')->default(1);

            // Section A: Document & Reference Checks
            $table->enum('invoice_check', ['OK', 'NOT_OK'])->default('OK');
            $table->text('invoice_notes')->nullable();

            $table->enum('tax_invoice_check', ['OK', 'NOT_OK', 'NOT_APPLICABLE'])->default('OK');
            $table->text('tax_invoice_notes')->nullable();

            $table->enum('po_check', ['OK', 'NOT_OK'])->default('OK');
            $table->text('po_notes')->nullable();

            $table->enum('delivery_note_check', ['OK', 'NOT_OK', 'NOT_APPLICABLE'])->default('OK');
            $table->text('delivery_note_notes')->nullable();

            $table->enum('gr_check', ['OK', 'NOT_OK'])->default('OK');
            $table->text('gr_notes')->nullable();

            // Section B: Tax Verification
            $table->enum('ppn_status', ['SESUAI', 'TIDAK_SESUAI'])->default('SESUAI');
            $table->decimal('submitted_ppn', 20, 2)->default(0);
            $table->decimal('verified_ppn', 20, 2)->default(0);

            $table->boolean('pph_23_applicable')->default(false);
            $table->decimal('pph_23_base', 20, 2)->nullable();
            $table->decimal('pph_23_rate', 5, 2)->nullable();
            $table->decimal('pph_23_amount', 20, 2)->default(0);

            $table->boolean('pph_4_2_applicable')->default(false);
            $table->decimal('pph_4_2_amount', 20, 2)->default(0);

            $table->boolean('pph_21_applicable')->default(false);
            $table->decimal('pph_21_amount', 20, 2)->default(0);

            $table->text('tax_notes')->nullable();

            // Status & Verification Locking
            $table->boolean('is_section_a_passed')->default(false);
            $table->boolean('is_section_b_passed')->default(false);
            $table->boolean('is_locked')->default(false);

            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['local_invoice_id', 'revision_number'], 'liv_invoice_rev_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('local_invoice_verifications');
    }
};
