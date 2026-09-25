<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('local_goods_receipts') && Schema::hasColumn('local_goods_receipts', 'received_amount')) {
            Schema::table('local_goods_receipts', function (Blueprint $table) {
                $table->dropColumn('received_amount');
            });
        }

        if (Schema::hasTable('local_invoice_goods_receipts') && Schema::hasColumn('local_invoice_goods_receipts', 'gr_amount_snapshot')) {
            Schema::table('local_invoice_goods_receipts', function (Blueprint $table) {
                $table->dropColumn('gr_amount_snapshot');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('local_goods_receipts') && ! Schema::hasColumn('local_goods_receipts', 'received_amount')) {
            Schema::table('local_goods_receipts', function (Blueprint $table) {
                $table->decimal('received_amount', 20, 2)->nullable()->after('gr_date');
            });
        }

        if (Schema::hasTable('local_invoice_goods_receipts') && ! Schema::hasColumn('local_invoice_goods_receipts', 'gr_amount_snapshot')) {
            Schema::table('local_invoice_goods_receipts', function (Blueprint $table) {
                $table->decimal('gr_amount_snapshot', 20, 2)->nullable()->after('gr_number_snapshot');
            });
        }
    }
};
