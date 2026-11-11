<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_vouchers', function (Blueprint $table): void {
            $table->id();
            $table->string('voucher_number', 100)->unique();
            $table->date('voucher_date');
            $table->string('source_department', 20);
            $table->string('status', 20)->default('FINAL');
            $table->string('payment_method', 10);
            $table->string('payee_type', 20);

            $table->foreignId('payee_supplier_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('payee_employee_id')->nullable()->constrained('employees')->nullOnDelete();

            $table->string('payee_name_snapshot', 150);
            $table->string('payee_department_snapshot', 100)->nullable();
            $table->string('bank_name_snapshot', 100)->nullable();
            $table->string('bank_account_snapshot', 50)->nullable();
            $table->string('bank_account_holder_snapshot', 150)->nullable();
            $table->string('npwp_snapshot', 50)->nullable();

            $table->string('reference_number', 150)->nullable();
            $table->text('description');
            $table->text('notes')->nullable();

            $table->decimal('dpp_amount', 20, 2);
            $table->decimal('ppn_amount', 20, 2)->default(0);
            $table->decimal('pph_amount', 20, 2)->default(0);
            $table->decimal('bank_fee', 20, 2)->default(0);
            $table->decimal('net_amount', 20, 2);
            $table->text('terbilang_snapshot');

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('issued_at');

            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();

            $table->timestamps();

            $table->index(['status', 'voucher_date'], 'payment_vouchers_status_date_idx');
            $table->index(['source_department', 'status'], 'payment_vouchers_dept_status_idx');
            $table->index(['payee_type', 'voucher_date'], 'payment_vouchers_payee_date_idx');
            $table->index('reference_number', 'payment_vouchers_ref_idx');
        });

        Schema::create('payment_voucher_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_voucher_id')->constrained('payment_vouchers')->cascadeOnDelete();
            $table->string('document_type', 50)->default('supporting');
            $table->string('file_path', 255)->unique();
            $table->string('original_filename', 255);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->foreignId('file_inspection_id')->nullable()->constrained('file_inspections')->nullOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index('payment_voucher_id', 'payment_voucher_docs_voucher_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_voucher_documents');
        Schema::dropIfExists('payment_vouchers');
    }
};
