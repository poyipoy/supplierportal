<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('local_invoices') && ! Schema::hasColumn('local_invoices', 'tax_invoice_number')) {
            Schema::table('local_invoices', function (Blueprint $table) {
                $table->string('tax_invoice_number', 30)->nullable()->after('tax_amount');
                $table->index(['supplier_id', 'tax_invoice_number']);
            });
        }

        if (Schema::hasTable('local_invoice_revisions') && ! Schema::hasColumn('local_invoice_revisions', 'tax_invoice_number')) {
            Schema::table('local_invoice_revisions', function (Blueprint $table) {
                $table->string('tax_invoice_number', 30)->nullable()->after('tax_amount');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('local_invoices') && Schema::hasColumn('local_invoices', 'tax_invoice_number')) {
            Schema::table('local_invoices', function (Blueprint $table) {
                $table->dropIndex(['supplier_id', 'tax_invoice_number']);
                $table->dropColumn('tax_invoice_number');
            });
        }

        if (Schema::hasTable('local_invoice_revisions') && Schema::hasColumn('local_invoice_revisions', 'tax_invoice_number')) {
            Schema::table('local_invoice_revisions', function (Blueprint $table) {
                $table->dropColumn('tax_invoice_number');
            });
        }
    }
};
