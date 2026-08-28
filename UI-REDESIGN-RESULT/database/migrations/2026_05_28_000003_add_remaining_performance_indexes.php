<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tambah index untuk kolom yang sering dipakai di query pencarian/filter
     * tetapi belum ter-index oleh migration sebelumnya.
     */
    public function up(): void
    {
        // po_number sering dipakai untuk pencarian dan filter
        if (! $this->hasIndex('purchase_orders', 'purchase_orders_po_number_index')) {
            Schema::table('purchase_orders', function (Blueprint $table) {
                $table->index('po_number');
            });
        }

        // Composite index untuk comparison queries yang join quotation_items + pr_items
        if (! $this->hasIndex('quotation_items', 'quotation_items_pr_item_id_quotation_id_index')) {
            Schema::table('quotation_items', function (Blueprint $table) {
                $table->index(['pr_item_id', 'quotation_id']);
            });
        }

        // submitted_at sering dipakai di comparison queries
        if (! $this->hasIndex('quotations', 'quotations_submitted_at_index')) {
            Schema::table('quotations', function (Blueprint $table) {
                $table->index('submitted_at');
            });
        }
    }

    /**
     * Cek apakah index sudah ada (hindari error duplicate index).
     */
    private function hasIndex(string $table, string $indexName): bool
    {
        $indexes = Schema::getIndexes($table);

        foreach ($indexes as $index) {
            if ($index['name'] === $indexName) {
                return true;
            }
        }

        return false;
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropIndex(['po_number']);
        });

        Schema::table('quotation_items', function (Blueprint $table) {
            $table->dropIndex(['pr_item_id', 'quotation_id']);
        });

        Schema::table('quotations', function (Blueprint $table) {
            $table->dropIndex(['submitted_at']);
        });
    }
};
