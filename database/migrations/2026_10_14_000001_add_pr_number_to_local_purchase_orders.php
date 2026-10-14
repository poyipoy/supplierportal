<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('local_purchase_orders', function (Blueprint $table): void {
            $table->string('pr_number', 100)->nullable()->index();
        });
    }

    public function down(): void
    {
        if (DB::table('local_purchase_orders')->whereNotNull('pr_number')->exists()) {
            throw new RuntimeException('Cannot roll back: local purchase orders contain PR references.');
        }
        Schema::table('local_purchase_orders', function (Blueprint $table): void {
            $table->dropIndex(['pr_number']);
            $table->dropColumn('pr_number');
        });
    }
};
