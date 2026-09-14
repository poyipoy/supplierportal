<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add V2 fields to local_invoices
        Schema::table('local_invoices', function (Blueprint $table) {
            $table->enum('po_source', ['INTERNAL', 'MANUAL'])->default('INTERNAL')->after('invoice_date');
            $table->string('internal_po_reference', 100)->nullable()->after('po_number');
            $table->string('manual_po_number', 100)->nullable()->after('internal_po_reference');
            $table->string('internal_gr_reference', 100)->nullable()->after('manual_po_number');
            $table->string('manual_gr_reference', 100)->nullable()->after('internal_gr_reference');

            $table->decimal('po_value_snapshot', 20, 2)->nullable()->after('manual_gr_reference');
            $table->decimal('po_invoiced_snapshot', 20, 2)->nullable()->after('po_value_snapshot');
            $table->decimal('po_remaining_snapshot', 20, 2)->nullable()->after('po_invoiced_snapshot');
            $table->boolean('has_po_discrepancy')->default(false)->after('po_remaining_snapshot');

            $table->string('ppn_scheme', 20)->nullable()->after('tax_amount');
            $table->decimal('submitted_ppn_amount', 20, 2)->nullable()->after('ppn_scheme');

            $table->date('scheduled_physical_delivery_date')->nullable()->after('submitted_ppn_amount');
            $table->unsignedTinyInteger('missed_delivery_count')->default(0)->after('scheduled_physical_delivery_date');
            $table->timestamp('rescheduled_at')->nullable()->after('missed_delivery_count');
            $table->foreignId('rescheduled_by')->nullable()->after('rescheduled_at')->constrained('users')->nullOnDelete();

            $table->timestamp('cashier_received_at')->nullable()->after('physical_verified_at');
            $table->foreignId('cashier_received_by')->nullable()->after('cashier_received_at')->constrained('users')->nullOnDelete();

            $table->timestamp('ready_to_pay_at')->nullable()->after('approved_at');
            $table->timestamp('paid_at')->nullable()->after('completed_at');
            $table->timestamp('expired_at')->nullable()->after('paid_at');
        });

        // Make payment_term_days_snapshot nullable at submission (populated upon cashier receipt)
        DB::statement("ALTER TABLE local_invoices MODIFY payment_term_days_snapshot SMALLINT UNSIGNED NULL");

        // 2. Add fields to local_invoice_revisions
        Schema::table('local_invoice_revisions', function (Blueprint $table) {
            $table->enum('po_source', ['INTERNAL', 'MANUAL'])->default('INTERNAL')->after('invoice_date');
            $table->string('internal_po_reference', 100)->nullable()->after('po_number');
            $table->string('manual_po_number', 100)->nullable()->after('internal_po_reference');
            $table->string('internal_gr_reference', 100)->nullable()->after('manual_po_number');
            $table->string('manual_gr_reference', 100)->nullable()->after('internal_gr_reference');
            $table->string('ppn_scheme', 20)->nullable()->after('tax_amount');
            $table->decimal('submitted_ppn_amount', 20, 2)->nullable()->after('ppn_scheme');
            $table->boolean('has_po_discrepancy')->default(false)->after('submitted_ppn_amount');
        });

        // 3. Migrate legacy status data before altering enum
        DB::table('local_invoices')->where('status', 'UNDER_REVIEW')->update(['status' => 'UNDER_VERIFICATION']);
        DB::table('local_invoices')->whereIn('status', ['APPROVED', 'PAYMENT_SCHEDULED'])->update(['status' => 'READY_TO_PAY']);
        DB::table('local_invoices')->where('status', 'COMPLETED')->update(['status' => 'PAID']);

        // 4. Alter status enum on local_invoices (supporting both V2 and legacy statuses for full backward-compatibility and zero data loss)
        DB::statement("ALTER TABLE local_invoices MODIFY status ENUM('WAITING_PHYSICAL_DOCUMENT', 'UNDER_VERIFICATION', 'UNDER_REVIEW', 'NEED_REVISION', 'READY_TO_PAY', 'APPROVED', 'PAYMENT_SCHEDULED', 'PAID', 'COMPLETED', 'EXPIRED', 'REJECTED') NOT NULL DEFAULT 'WAITING_PHYSICAL_DOCUMENT'");

        // 5. Expand document_type enum on local_invoice_documents to include 'delivery_note' (Surat Jalan)
        DB::statement("ALTER TABLE local_invoice_documents MODIFY document_type ENUM('invoice', 'tax_invoice', 'delivery_note', 'supporting') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE local_invoice_documents MODIFY document_type ENUM('invoice', 'tax_invoice', 'supporting') NOT NULL");
        DB::statement("ALTER TABLE local_invoices MODIFY status ENUM('WAITING_PHYSICAL_DOCUMENT', 'UNDER_REVIEW', 'NEED_REVISION', 'REJECTED', 'APPROVED', 'PAYMENT_SCHEDULED', 'COMPLETED') NOT NULL DEFAULT 'WAITING_PHYSICAL_DOCUMENT'");

        Schema::table('local_invoice_revisions', function (Blueprint $table) {
            $table->dropColumn([
                'po_source',
                'internal_po_reference',
                'manual_po_number',
                'internal_gr_reference',
                'manual_gr_reference',
                'ppn_scheme',
                'submitted_ppn_amount',
                'has_po_discrepancy',
            ]);
        });

        Schema::table('local_invoices', function (Blueprint $table) {
            $table->dropForeign(['rescheduled_by']);
            $table->dropForeign(['cashier_received_by']);
            $table->dropColumn([
                'po_source',
                'internal_po_reference',
                'manual_po_number',
                'internal_gr_reference',
                'manual_gr_reference',
                'po_value_snapshot',
                'po_invoiced_snapshot',
                'po_remaining_snapshot',
                'has_po_discrepancy',
                'ppn_scheme',
                'submitted_ppn_amount',
                'scheduled_physical_delivery_date',
                'missed_delivery_count',
                'rescheduled_at',
                'rescheduled_by',
                'cashier_received_at',
                'cashier_received_by',
                'ready_to_pay_at',
                'paid_at',
                'expired_at',
            ]);
        });
    }
};
