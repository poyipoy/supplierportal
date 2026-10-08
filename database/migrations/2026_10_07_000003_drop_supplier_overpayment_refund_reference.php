<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_overpayment_refunds', function (Blueprint $table) {
            $table->dropColumn('refund_reference');
        });
    }

    public function down(): void
    {
        // Historical reference values were permanently removed by the approved revision.
        Schema::table('supplier_overpayment_refunds', function (Blueprint $table) {
            $table->string('refund_reference', 100)->nullable();
        });
    }
};
