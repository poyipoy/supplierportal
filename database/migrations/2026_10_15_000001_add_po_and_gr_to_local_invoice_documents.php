<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE local_invoice_documents MODIFY document_type ENUM('invoice', 'tax_invoice', 'delivery_note', 'supporting', 'purchase_order', 'goods_receipt') NOT NULL");
    }

    public function down(): void
    {
        if (DB::table('local_invoice_documents')->whereIn('document_type', ['purchase_order', 'goods_receipt'])->exists()) {
            throw new RuntimeException('Cannot remove PO and GR document types while documents exist.');
        }

        DB::statement("ALTER TABLE local_invoice_documents MODIFY document_type ENUM('invoice', 'tax_invoice', 'delivery_note', 'supporting') NOT NULL");
    }
};
