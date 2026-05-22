<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tambahkan Soft Deletes pada tabel-tabel yang menyimpan dokumen
     * legal/penting agar data tidak terhapus secara permanen.
     */
    public function up(): void
    {
        $tables = [
            'purchase_requirements',
            'quotations',
            'purchase_orders',
            'qc_inspections',
            'material_claims',
            'announcements',
        ];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->softDeletes();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tables = [
            'purchase_requirements',
            'quotations',
            'purchase_orders',
            'qc_inspections',
            'material_claims',
            'announcements',
        ];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropSoftDeletes();
            });
        }
    }
};
