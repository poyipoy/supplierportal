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
        if (Schema::hasTable('local_goods_receipts')) {
            Schema::table('local_goods_receipts', function (Blueprint $table) {
                $table->decimal('received_amount', 20, 2)->nullable()->change();
            });
        }

        if (Schema::hasTable('local_invoice_goods_receipts')) {
            Schema::table('local_invoice_goods_receipts', function (Blueprint $table) {
                $table->decimal('gr_amount_snapshot', 20, 2)->nullable()->change();

                if (! Schema::hasColumn('local_invoice_goods_receipts', 'gr_qty_snapshot')) {
                    $table->decimal('gr_qty_snapshot', 15, 4)->nullable()->after('gr_amount_snapshot');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('local_invoice_goods_receipts')) {
            Schema::table('local_invoice_goods_receipts', function (Blueprint $table) {
                if (Schema::hasColumn('local_invoice_goods_receipts', 'gr_qty_snapshot')) {
                    $table->dropColumn('gr_qty_snapshot');
                }

                $table->decimal('gr_amount_snapshot', 20, 2)->nullable(false)->change();
            });
        }

        if (Schema::hasTable('local_goods_receipts')) {
            Schema::table('local_goods_receipts', function (Blueprint $table) {
                $table->decimal('received_amount', 20, 2)->nullable(false)->change();
            });
        }
    }
};
