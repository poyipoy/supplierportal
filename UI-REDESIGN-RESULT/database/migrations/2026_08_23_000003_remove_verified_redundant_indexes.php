<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remove secondary indexes already covered by an equivalent index or by
     * the left-most prefix of a retained unique index.
     */
    public function up(): void
    {
        $this->dropCoveredIndex(
            'quotations',
            'quotations_submitted_at_index',
            'quot_submitted_at_index',
        );
        $this->dropCoveredIndex(
            'purchase_requisitions',
            'pr_number_index',
            'purchase_requirements_pr_number_unique',
        );
        $this->dropCoveredIndex(
            'purchase_orders',
            'purchase_orders_po_number_index',
            'purchase_orders_po_number_unique',
        );
        $this->dropCoveredIndex(
            'po_quotations',
            'po_quotations_po_id_index',
            'po_quotations_po_id_quotation_id_unique',
        );
    }

    /**
     * Restore the canonical pre-migration index definitions. On a deployment
     * where cleanup had already been applied manually, rollback intentionally
     * restores the repository's canonical pre-migration schema.
     */
    public function down(): void
    {
        $this->createIndexIfMissing(
            'quotations',
            'quotations_submitted_at_index',
            ['submitted_at'],
        );
        $this->createIndexIfMissing(
            'purchase_requisitions',
            'pr_number_index',
            ['pr_number'],
        );
        $this->createIndexIfMissing(
            'purchase_orders',
            'purchase_orders_po_number_index',
            ['po_number'],
        );
        $this->createIndexIfMissing(
            'po_quotations',
            'po_quotations_po_id_index',
            ['po_id'],
        );
    }

    private function dropCoveredIndex(
        string $table,
        string $redundantIndex,
        string $retainedIndex,
    ): void {
        $indexes = collect(Schema::getIndexes($table))->keyBy('name');
        $redundant = $indexes->get($redundantIndex);

        // Permit deployment where this cleanup was already applied manually.
        if ($redundant === null) {
            return;
        }

        $retained = $indexes->get($retainedIndex);
        $redundantColumns = array_values($redundant['columns'] ?? []);
        $retainedColumns = array_values($retained['columns'] ?? []);

        if ($retained === null
            || $redundantColumns === []
            || array_slice($retainedColumns, 0, count($redundantColumns)) !== $redundantColumns) {
            throw new RuntimeException(
                "Cannot drop {$table}.{$redundantIndex}: retained index {$retainedIndex} does not cover it."
            );
        }

        Schema::table($table, function (Blueprint $table) use ($redundantIndex) {
            $table->dropIndex($redundantIndex);
        });
    }

    /**
     * @param  list<string>  $columns
     */
    private function createIndexIfMissing(string $table, string $indexName, array $columns): void
    {
        $exists = collect(Schema::getIndexes($table))
            ->contains(fn (array $index): bool => $index['name'] === $indexName);

        if ($exists) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($columns, $indexName) {
            $table->index($columns, $indexName);
        });
    }
};
