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
        Schema::table('local_invoices', function (Blueprint $table) {
            $table->text('internal_gr_reference')->nullable()->change();
            $table->text('manual_gr_reference')->nullable()->change();
        });

        Schema::table('local_invoice_revisions', function (Blueprint $table) {
            $table->text('internal_gr_reference')->nullable()->change();
            $table->text('manual_gr_reference')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('local_invoices', function (Blueprint $table) {
            $table->string('internal_gr_reference', 100)->nullable()->change();
            $table->string('manual_gr_reference', 100)->nullable()->change();
        });

        Schema::table('local_invoice_revisions', function (Blueprint $table) {
            $table->string('internal_gr_reference', 100)->nullable()->change();
            $table->string('manual_gr_reference', 100)->nullable()->change();
        });
    }
};
