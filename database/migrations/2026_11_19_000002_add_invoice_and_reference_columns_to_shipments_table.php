<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->string('invoice_number', 100)
                ->nullable()
                ->after('shipment_number');
            $table->string('supplier_reference', 120)
                ->nullable()
                ->after('invoice_number');

            $table->index('invoice_number', 'idx_shipments_invoice_number');
            $table->index(['supplier_id', 'supplier_reference'], 'idx_shipments_supplier_ref');
        });

        // Backfill invoice_number from existing invoice shipment_documents where available
        $invoiceDocs = DB::table('shipment_documents')
            ->where('doc_type', 'invoice')
            ->whereNotNull('document_number')
            ->where('document_number', '<>', '')
            ->get(['shipment_id', 'document_number']);

        foreach ($invoiceDocs as $doc) {
            DB::table('shipments')
                ->where('id', $doc->shipment_id)
                ->whereNull('invoice_number')
                ->update(['invoice_number' => $doc->document_number]);
        }
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropIndex('idx_shipments_invoice_number');
            $table->dropIndex('idx_shipments_supplier_ref');
            $table->dropColumn(['invoice_number', 'supplier_reference']);
        });
    }
};
