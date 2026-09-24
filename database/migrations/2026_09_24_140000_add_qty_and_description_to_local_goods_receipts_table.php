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
        Schema::table('local_goods_receipts', function (Blueprint $table) {
            $table->decimal('qty', 12, 4)->default(0)->after('received_amount');
            $table->text('description')->nullable()->after('qty');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('local_goods_receipts', function (Blueprint $table) {
            $table->dropColumn(['qty', 'description']);
        });
    }
};
