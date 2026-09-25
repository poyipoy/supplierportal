<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Ensure status enum on local_invoices safely accommodates all states before conversion
        // (Supports MySQL strict mode upgrade from populated legacy databases)
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE local_invoices MODIFY status ENUM(
                'WAITING_PHYSICAL_DOCUMENT',
                'UNDER_VERIFICATION',
                'UNDER_REVIEW',
                'NEED_REVISION',
                'READY_TO_PAY',
                'APPROVED',
                'PAYMENT_SCHEDULED',
                'PAID',
                'COMPLETED',
                'EXPIRED',
                'REJECTED'
            ) NOT NULL DEFAULT 'WAITING_PHYSICAL_DOCUMENT'");

            DB::statement('ALTER TABLE local_invoices MODIFY payment_term_days_snapshot SMALLINT UNSIGNED NULL');
        }

        // Convert any legacy status rows to canonical V2 statuses
        DB::table('local_invoices')->where('status', 'UNDER_REVIEW')->update(['status' => 'UNDER_VERIFICATION']);
        DB::table('local_invoices')->whereIn('status', ['APPROVED', 'PAYMENT_SCHEDULED'])->update(['status' => 'READY_TO_PAY']);
        DB::table('local_invoices')->where('status', 'COMPLETED')->update(['status' => 'PAID']);

        // 2. Authoritative Local PO & Goods Receipt Staging Domain (P0-04)
        if (! Schema::hasTable('local_purchase_orders')) {
            Schema::create('local_purchase_orders', function (Blueprint $table) {
                $table->id();
                $table->foreignId('supplier_id')->constrained('users')->restrictOnDelete();
                $table->string('po_number', 100)->unique();
                $table->date('po_date');
                $table->decimal('total_amount', 20, 2);
                $table->string('currency', 10)->default('IDR');
                $table->text('description')->nullable();
                $table->string('status', 50)->default('APPROVED');
                $table->timestamps();

                $table->index(['supplier_id', 'status']);
            });
        }

        if (! Schema::hasTable('local_goods_receipts')) {
            Schema::create('local_goods_receipts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('local_purchase_order_id')->constrained('local_purchase_orders')->cascadeOnDelete();
                $table->string('gr_number', 100)->unique();
                $table->date('gr_date');
                $table->decimal('received_amount', 20, 2);
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['local_purchase_order_id', 'gr_date']);
            });
        }

        // 3. Payment Items Active Reservation Index (P0-03)
        $indexes = collect(Schema::getIndexes('payment_items'));
        if (! $indexes->contains(fn ($index) => $index['name'] === 'idx_payment_items_reservation')) {
            Schema::table('payment_items', function (Blueprint $table) {
                $table->index(['payable_type', 'payable_id', 'status'], 'idx_payment_items_reservation');
            });
        }
    }

    public function down(): void
    {
        Schema::table('payment_items', function (Blueprint $table) {
            $table->dropIndex('idx_payment_items_reservation');
        });

        Schema::dropIfExists('local_goods_receipts');
        Schema::dropIfExists('local_purchase_orders');
    }
};
