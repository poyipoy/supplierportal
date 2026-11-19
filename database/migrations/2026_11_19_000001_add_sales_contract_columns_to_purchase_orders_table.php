<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->string('sales_contract_status', 32)
                ->nullable()
                ->after('acknowledgement_notes');
            $table->unsignedBigInteger('supplier_sales_contract_attachment_id')
                ->nullable()
                ->after('sales_contract_status');
            $table->timestamp('supplier_sales_contract_uploaded_at')
                ->nullable()
                ->after('supplier_sales_contract_attachment_id');
            $table->unsignedBigInteger('purchasing_sales_contract_attachment_id')
                ->nullable()
                ->after('supplier_sales_contract_uploaded_at');
            $table->timestamp('purchasing_sales_contract_uploaded_at')
                ->nullable()
                ->after('purchasing_sales_contract_attachment_id');
            $table->unsignedBigInteger('purchasing_sales_contract_uploaded_by')
                ->nullable()
                ->after('purchasing_sales_contract_uploaded_at');
            $table->text('sales_contract_notes')
                ->nullable()
                ->after('purchasing_sales_contract_uploaded_by');

            $table->index(['sales_contract_status', 'status'], 'idx_po_sales_contract_status');
            $table->foreign('supplier_sales_contract_attachment_id', 'fk_po_supplier_sc_attachment')
                ->references('id')->on('attachments')->nullOnDelete();
            $table->foreign('purchasing_sales_contract_attachment_id', 'fk_po_purchasing_sc_attachment')
                ->references('id')->on('attachments')->nullOnDelete();
            $table->foreign('purchasing_sales_contract_uploaded_by', 'fk_po_purchasing_sc_user')
                ->references('id')->on('users')->nullOnDelete();
        });

        // Backfill existing POs that were already acknowledged or advanced in fulfillment
        DB::table('purchase_orders')
            ->where(function ($query) {
                $query->whereIn('acknowledgement_status', ['ACKNOWLEDGED', 'ACCEPTED_CHANGE'])
                    ->orWhereIn('status', ['waiting_qc', 'claim_needed', 'completed', 'cancelled'])
                    ->orWhereExists(function ($sub) {
                        $sub->selectRaw('1')
                            ->from('shipment_items')
                            ->whereColumn('shipment_items.purchase_order_id', 'purchase_orders.id');
                    });
            })
            ->update(['sales_contract_status' => 'purchasing_signed']);
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropForeign('fk_po_supplier_sc_attachment');
            $table->dropForeign('fk_po_purchasing_sc_attachment');
            $table->dropForeign('fk_po_purchasing_sc_user');
            $table->dropIndex('idx_po_sales_contract_status');
            $table->dropColumn([
                'sales_contract_status',
                'supplier_sales_contract_attachment_id',
                'supplier_sales_contract_uploaded_at',
                'purchasing_sales_contract_attachment_id',
                'purchasing_sales_contract_uploaded_at',
                'purchasing_sales_contract_uploaded_by',
                'sales_contract_notes',
            ]);
        });
    }
};
