<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Unified Payment Batches (DRP)
        Schema::create('payment_batches', function (Blueprint $table) {
            $table->id();
            $table->string('batch_number', 100)->unique();
            $table->enum('batch_type', ['SUPPLIER', 'GA']);
            $table->enum('status', ['DRAFT', 'FINALIZED', 'PARTIALLY_PAID', 'PAID', 'CANCELLED'])->default('DRAFT');
            $table->decimal('total_subtotal', 20, 2)->default(0);
            $table->decimal('total_bank_fee', 20, 2)->default(0);
            $table->decimal('total_net_amount', 20, 2)->default(0);

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['batch_type', 'status']);
        });

        // 2. Payment Groups (grouped by Payee + Bank Account)
        Schema::create('payment_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_batch_id')->constrained('payment_batches')->cascadeOnDelete();
            $table->string('payee_type', 50); // supplier or employee
            $table->unsignedBigInteger('payee_id');
            $table->string('payee_name', 150);

            // Bank Account Snapshot
            $table->string('bank_name', 100);
            $table->string('account_number', 50);
            $table->string('account_holder_name', 150);

            $table->decimal('subtotal_amount', 20, 2)->default(0);
            $table->decimal('bank_fee', 20, 2)->default(0);
            $table->decimal('net_payment_amount', 20, 2)->default(0);
            $table->text('fee_override_reason')->nullable();

            // Payment Confirmation
            $table->enum('status', ['UNPAID', 'PAID'])->default('UNPAID');
            $table->string('transfer_reference', 100)->nullable();
            $table->date('transfer_date')->nullable();
            $table->text('payment_notes')->nullable();

            // Voucher Details
            $table->string('voucher_number', 100)->nullable();
            $table->date('voucher_date')->nullable();

            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['payment_batch_id', 'status']);
            $table->index(['payee_type', 'payee_id']);
        });

        // 3. Payment Items (linking individual invoices / claims to payment groups)
        Schema::create('payment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_group_id')->constrained('payment_groups')->cascadeOnDelete();
            $table->string('payable_type', 50); // LocalInvoice or GaClaim
            $table->unsignedBigInteger('payable_id');
            $table->decimal('amount', 20, 2);

            $table->enum('status', ['ACTIVE', 'REMOVED'])->default('ACTIVE');
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('removed_at')->nullable();
            $table->text('removal_reason')->nullable();
            $table->timestamps();

            $table->index(['payable_type', 'payable_id', 'status']);
            $table->index(['payment_group_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_items');
        Schema::dropIfExists('payment_groups');
        Schema::dropIfExists('payment_batches');
    }
};
