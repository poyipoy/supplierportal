<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_delta_rows', function (Blueprint $table): void {
            $table->string('reconciliation_status', 24)
                ->default('pending')
                ->after('last_synced_by');
            $table->foreignId('matched_local_po_id')
                ->nullable()
                ->after('reconciliation_status')
                ->constrained('local_purchase_orders')
                ->nullOnDelete();
            $table->timestamp('applied_at')
                ->nullable()
                ->after('matched_local_po_id');
            $table->foreignId('applied_by')
                ->nullable()
                ->after('applied_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('ignored_at')
                ->nullable()
                ->after('applied_by');
            $table->foreignId('ignored_by')
                ->nullable()
                ->after('ignored_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->string('apply_note', 255)
                ->nullable()
                ->after('ignored_by');

            $table->index(
                ['supplier_id', 'reconciliation_status', 'doc_no'],
                'erp_delta_rows_supplier_recon_doc_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('erp_delta_rows', function (Blueprint $table): void {
            $table->dropIndex('erp_delta_rows_supplier_recon_doc_idx');
            $table->dropConstrainedForeignId('ignored_by');
            $table->dropConstrainedForeignId('applied_by');
            $table->dropConstrainedForeignId('matched_local_po_id');
            $table->dropColumn([
                'reconciliation_status',
                'applied_at',
                'ignored_at',
                'apply_note',
            ]);
        });
    }
};
