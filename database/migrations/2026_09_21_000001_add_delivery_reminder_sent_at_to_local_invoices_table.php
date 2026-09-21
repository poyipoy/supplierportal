<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('local_invoices', function (Blueprint $table) {
            $table->timestamp('delivery_reminder_sent_at')->nullable()->after('scheduled_physical_delivery_date');
        });
    }

    public function down(): void
    {
        Schema::table('local_invoices', function (Blueprint $table) {
            $table->dropColumn('delivery_reminder_sent_at');
        });
    }
};
