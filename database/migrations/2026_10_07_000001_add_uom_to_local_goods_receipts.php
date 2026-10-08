<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('local_goods_receipts', function (Blueprint $table) {
            $table->string('uom', 3)->nullable()->after('qty');
        });
        Schema::table('local_invoice_goods_receipts', function (Blueprint $table) {
            $table->string('gr_uom_snapshot', 3)->nullable()->after('gr_qty_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('local_invoice_goods_receipts', fn (Blueprint $table) => $table->dropColumn('gr_uom_snapshot'));
        Schema::table('local_goods_receipts', fn (Blueprint $table) => $table->dropColumn('uom'));
    }
};
