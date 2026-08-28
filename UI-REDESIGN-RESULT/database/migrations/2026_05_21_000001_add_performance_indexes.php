<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tambahkan index pada kolom-kolom yang sering digunakan
     * untuk filter, pencarian, dan sorting di DataTables server-side.
     */
    public function up(): void
    {
        // purchase_requirements: filter status, pencarian pr_number, sorting tanggal
        Schema::table('purchase_requirements', function (Blueprint $table) {
            $table->index('status', 'pr_status_index');
            $table->index('pr_number', 'pr_number_index');
            $table->index('created_by', 'pr_created_by_index');
            $table->index('created_at', 'pr_created_at_index');
        });

        // pr_items: pencarian material & HS code
        Schema::table('pr_items', function (Blueprint $table) {
            $table->index('material_name', 'pri_material_name_index');
            $table->index('hs_code', 'pri_hs_code_index');
        });

        // quotations: filter per supplier & status
        Schema::table('quotations', function (Blueprint $table) {
            $table->index('status', 'quot_status_index');
            $table->index('submitted_at', 'quot_submitted_at_index');
            $table->index(['supplier_id', 'status'], 'quot_supplier_status_index');
        });

        // purchase_orders: filter daftar PO
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->index('status', 'po_status_index');
            $table->index('created_by', 'po_created_by_index');
            $table->index('estimated_arrival', 'po_estimated_arrival_index');
            $table->index('created_at', 'po_created_at_index');
        });

        // qc_inspections: filter riwayat QC
        Schema::table('qc_inspections', function (Blueprint $table) {
            $table->index('status', 'qci_status_index');
            $table->index('inspected_at', 'qci_inspected_at_index');
        });

        // material_claims: filter claim per supplier
        Schema::table('material_claims', function (Blueprint $table) {
            $table->index('status', 'mc_status_index');
            $table->index(['supplier_id', 'status'], 'mc_supplier_status_index');
        });

        // exchange_rates: pencarian kurs terbaru
        Schema::table('exchange_rates', function (Blueprint $table) {
            $table->index(['currency', 'valid_from'], 'er_currency_valid_from_index');
        });

        // periods: filter periode
        Schema::table('periods', function (Blueprint $table) {
            $table->index('status', 'period_status_index');
            $table->index(['year', 'month'], 'period_year_month_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_requirements', function (Blueprint $table) {
            $table->dropIndex('pr_status_index');
            $table->dropIndex('pr_number_index');
            $table->dropIndex('pr_created_by_index');
            $table->dropIndex('pr_created_at_index');
        });

        Schema::table('pr_items', function (Blueprint $table) {
            $table->dropIndex('pri_material_name_index');
            $table->dropIndex('pri_hs_code_index');
        });

        Schema::table('quotations', function (Blueprint $table) {
            $table->dropIndex('quot_status_index');
            $table->dropIndex('quot_submitted_at_index');
            $table->dropIndex('quot_supplier_status_index');
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropIndex('po_status_index');
            $table->dropIndex('po_created_by_index');
            $table->dropIndex('po_estimated_arrival_index');
            $table->dropIndex('po_created_at_index');
        });

        Schema::table('qc_inspections', function (Blueprint $table) {
            $table->dropIndex('qci_status_index');
            $table->dropIndex('qci_inspected_at_index');
        });

        Schema::table('material_claims', function (Blueprint $table) {
            $table->dropIndex('mc_status_index');
            $table->dropIndex('mc_supplier_status_index');
        });

        Schema::table('exchange_rates', function (Blueprint $table) {
            $table->dropIndex('er_currency_valid_from_index');
        });

        Schema::table('periods', function (Blueprint $table) {
            $table->dropIndex('period_status_index');
            $table->dropIndex('period_year_month_index');
        });
    }
};
