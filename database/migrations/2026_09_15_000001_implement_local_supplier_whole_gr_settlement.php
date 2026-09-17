<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('local_purchase_orders', function (Blueprint $table) {
            $table->string('source', 20)->default('MANUAL')->after('status');
            $table->foreignId('created_by')->nullable()->after('source')->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->index('po_date', 'local_po_date_idx');
        });

        DB::table('local_purchase_orders')->where('status', 'APPROVED')->update(['status' => 'OPEN']);

        Schema::table('local_invoices', function (Blueprint $table) {
            $table->foreignId('local_purchase_order_id')->nullable()->after('supplier_id')
                ->constrained('local_purchase_orders')->restrictOnDelete();
        });

        Schema::table('local_goods_receipts', function (Blueprint $table) {
            $table->string('status', 20)->default('AVAILABLE')->after('notes');
            $table->foreignId('current_invoice_id')->nullable()->after('status')
                ->constrained('local_invoices')->restrictOnDelete();
            $table->string('source', 20)->default('MANUAL')->after('current_invoice_id');
            $table->foreignId('created_by')->nullable()->after('source')->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->index(['local_purchase_order_id', 'status'], 'local_gr_po_status_idx');
        });

        Schema::create('local_invoice_goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('local_invoice_id')->constrained('local_invoices')->restrictOnDelete();
            $table->foreignId('local_purchase_order_id')->constrained('local_purchase_orders')->restrictOnDelete();
            $table->foreignId('local_goods_receipt_id')->constrained('local_goods_receipts')->restrictOnDelete();
            $table->string('state', 20);
            $table->string('gr_number_snapshot', 100);
            $table->decimal('gr_amount_snapshot', 20, 2);
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->index(['local_invoice_id', 'state'], 'local_invoice_gr_invoice_state_idx');
            $table->index(['local_goods_receipt_id', 'state'], 'local_invoice_gr_receipt_state_idx');
        });

        Schema::create('payment_voucher_sequences', function (Blueprint $table) {
            $table->char('period', 4)->primary();
            $table->unsignedBigInteger('last_number')->default(0);
        });

        Schema::create('local_invoice_vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('local_invoice_id')->unique()->constrained('local_invoices')->restrictOnDelete();
            $table->foreignId('payment_batch_id')->constrained('payment_batches')->restrictOnDelete();
            $table->foreignId('payment_group_id')->constrained('payment_groups')->restrictOnDelete();
            $table->foreignId('payment_item_id')->unique()->constrained('payment_items')->restrictOnDelete();
            $table->string('voucher_number', 100)->unique();
            $table->date('voucher_date');
            $table->string('payment_method', 10);
            $table->string('status', 20)->default('FINAL');
            $table->string('supplier_name_snapshot', 150);
            $table->string('bank_name_snapshot', 100);
            $table->string('bank_account_snapshot', 50);
            $table->string('bank_account_holder_snapshot', 150);
            $table->string('npwp_snapshot', 50)->nullable();
            $table->string('invoice_number_snapshot', 100);
            $table->string('po_number_snapshot', 100);
            $table->text('gr_references_snapshot');
            $table->decimal('dpp_snapshot', 20, 2);
            $table->decimal('ppn_snapshot', 20, 2);
            $table->decimal('pph_snapshot', 20, 2)->default(0);
            $table->decimal('net_payable_snapshot', 20, 2);
            $table->decimal('amount', 20, 2);
            $table->text('terbilang_snapshot');
            $table->text('remarks_snapshot')->nullable();
            $table->foreignId('finalized_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('finalized_at');
            $table->timestamps();
            $table->index(['payment_batch_id', 'payment_group_id'], 'local_voucher_batch_group_idx');
        });

        Schema::create('local_invoice_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('local_invoice_id')->unique()->constrained('local_invoices')->restrictOnDelete();
            $table->foreignId('local_invoice_voucher_id')->unique()->constrained('local_invoice_vouchers')->restrictOnDelete();
            $table->foreignId('payment_item_id')->unique()->constrained('payment_items')->restrictOnDelete();
            $table->decimal('expected_amount', 20, 2);
            $table->decimal('actual_paid_total', 20, 2)->default(0);
            $table->string('status', 30)->default('OPEN');
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('local_invoice_payment_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('local_invoice_payment_id')->constrained('local_invoice_payments')->restrictOnDelete();
            $table->unsignedInteger('sequence_no');
            $table->string('transfer_type', 20);
            $table->unsignedBigInteger('primary_guard')->nullable()->unique();
            $table->decimal('amount', 20, 2);
            $table->string('transfer_reference', 100);
            $table->date('transfer_date');
            $table->text('correction_reason')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('entered_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['local_invoice_payment_id', 'sequence_no'], 'local_payment_transfer_sequence_unique');
            $table->index('transfer_reference', 'local_payment_transfer_reference_idx');
        });

        Schema::create('supplier_overpayment_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('local_invoice_payment_id')->unique()->constrained('local_invoice_payments')->restrictOnDelete();
            $table->foreignId('local_invoice_id')->constrained('local_invoices')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('users')->restrictOnDelete();
            $table->decimal('overpayment_amount', 20, 2);
            $table->string('status', 20)->default('OPEN');
            $table->decimal('refund_amount', 20, 2)->nullable();
            $table->string('refund_reference', 100)->nullable();
            $table->date('refund_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('settled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
            $table->index(['supplier_id', 'status'], 'supplier_overpayment_supplier_status_idx');
        });

        Schema::create('local_finance_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('auditable_type');
            $table->unsignedBigInteger('auditable_id');
            $table->string('action', 50);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->json('before_values')->nullable();
            $table->json('after_values')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');
            $table->index(['auditable_type', 'auditable_id'], 'local_finance_auditable_idx');
            $table->index(['action', 'created_at'], 'local_finance_action_date_idx');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE local_invoices MODIFY status ENUM(
                'WAITING_PHYSICAL_DOCUMENT','UNDER_VERIFICATION','UNDER_REVIEW','NEED_REVISION',
                'READY_TO_PAY','APPROVED','PAYMENT_SCHEDULED','PAID','COMPLETED',
                'EXPIRED','REJECTED','CANCELLED'
            ) NOT NULL DEFAULT 'WAITING_PHYSICAL_DOCUMENT'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            // CANCELLED is introduced by this migration. Normalize any
            // remaining rows before narrowing the legacy enum so rollback does
            // not truncate data or fail under MySQL strict mode.
            DB::table('local_invoices')->where('status', 'CANCELLED')->update(['status' => 'REJECTED']);
            DB::statement("ALTER TABLE local_invoices MODIFY status ENUM(
                'WAITING_PHYSICAL_DOCUMENT','UNDER_VERIFICATION','UNDER_REVIEW','NEED_REVISION',
                'READY_TO_PAY','APPROVED','PAYMENT_SCHEDULED','PAID','COMPLETED',
                'EXPIRED','REJECTED'
            ) NOT NULL DEFAULT 'WAITING_PHYSICAL_DOCUMENT'");
        }

        Schema::dropIfExists('local_finance_audit_logs');
        Schema::dropIfExists('supplier_overpayment_refunds');
        Schema::dropIfExists('local_invoice_payment_transfers');
        Schema::dropIfExists('local_invoice_payments');
        Schema::dropIfExists('local_invoice_vouchers');
        Schema::dropIfExists('payment_voucher_sequences');
        Schema::dropIfExists('local_invoice_goods_receipts');

        Schema::table('local_goods_receipts', function (Blueprint $table) {
            $table->dropForeign(['current_invoice_id']);
            $table->dropForeign(['created_by']);
            $table->dropForeign(['updated_by']);
            $table->dropIndex('local_gr_po_status_idx');
            $table->dropColumn(['status', 'current_invoice_id', 'source', 'created_by', 'updated_by']);
        });
        Schema::table('local_invoices', function (Blueprint $table) {
            $table->dropForeign(['local_purchase_order_id']);
            $table->dropColumn('local_purchase_order_id');
        });
        Schema::table('local_purchase_orders', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropForeign(['updated_by']);
            $table->dropIndex('local_po_date_idx');
            $table->dropColumn(['source', 'created_by', 'updated_by']);
        });
    }
};
