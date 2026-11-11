<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('local_goods_receipts', function (Blueprint $table) {
            $table->decimal('unit_price', 18, 2)->nullable()->after('uom');
            $table->decimal('receipt_amount', 18, 2)->nullable()->after('unit_price');
            $table->string('po_line_reference', 64)->nullable()->after('receipt_amount');
        });

        Schema::table('local_invoice_goods_receipts', function (Blueprint $table) {
            $table->decimal('gr_amount_snapshot', 18, 2)->nullable()->after('gr_uom_snapshot');
        });

        Schema::table('local_invoices', function (Blueprint $table) {
            $table->decimal('expected_gr_dpp_amount', 18, 2)->nullable()->after('po_remaining_snapshot');
            $table->decimal('gr_dpp_variance_amount', 18, 2)->nullable()->after('expected_gr_dpp_amount');
            $table->string('three_way_match_status', 32)->default('PO_CEILING_ONLY')->after('gr_dpp_variance_amount');
        });

        Schema::table('local_invoice_verifications', function (Blueprint $table) {
            $table->string('three_way_match_status', 32)->nullable()->after('gr_notes');
            $table->decimal('three_way_variance_amount', 18, 2)->nullable()->after('three_way_match_status');
        });
    }

    public function down(): void
    {
        Schema::table('local_goods_receipts', function (Blueprint $table) {
            $table->dropColumn(['unit_price', 'receipt_amount', 'po_line_reference']);
        });

        Schema::table('local_invoice_goods_receipts', function (Blueprint $table) {
            $table->dropColumn(['gr_amount_snapshot']);
        });

        Schema::table('local_invoices', function (Blueprint $table) {
            $table->dropColumn([
                'expected_gr_dpp_amount',
                'gr_dpp_variance_amount',
                'three_way_match_status',
            ]);
        });

        Schema::table('local_invoice_verifications', function (Blueprint $table) {
            $table->dropColumn([
                'three_way_match_status',
                'three_way_variance_amount',
            ]);
        });
    }
};
