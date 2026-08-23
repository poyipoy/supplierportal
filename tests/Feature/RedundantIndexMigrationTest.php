<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RedundantIndexMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_cleanup_migration_is_reversible_and_retains_covering_indexes(): void
    {
        $migration = require database_path(
            'migrations/2026_08_23_000003_remove_verified_redundant_indexes.php'
        );

        $this->assertCleanupState();

        $migration->down();

        $this->assertIndex('quotations', 'quotations_submitted_at_index', ['submitted_at']);
        $this->assertIndex('purchase_requisitions', 'pr_number_index', ['pr_number']);
        $this->assertIndex('purchase_orders', 'purchase_orders_po_number_index', ['po_number']);
        $this->assertIndex('po_quotations', 'po_quotations_po_id_index', ['po_id']);

        $migration->up();

        $this->assertCleanupState();
    }

    private function assertCleanupState(): void
    {
        $this->assertIndex('quotations', 'quot_submitted_at_index', ['submitted_at']);
        $this->assertIndex(
            'purchase_requisitions',
            'purchase_requirements_pr_number_unique',
            ['pr_number'],
            true,
        );
        $this->assertIndex(
            'purchase_orders',
            'purchase_orders_po_number_unique',
            ['po_number'],
            true,
        );
        $this->assertIndex(
            'po_quotations',
            'po_quotations_po_id_quotation_id_unique',
            ['po_id', 'quotation_id'],
            true,
        );

        $this->assertIndexMissing('quotations', 'quotations_submitted_at_index');
        $this->assertIndexMissing('purchase_requisitions', 'pr_number_index');
        $this->assertIndexMissing('purchase_orders', 'purchase_orders_po_number_index');
        $this->assertIndexMissing('po_quotations', 'po_quotations_po_id_index');
    }

    /**
     * @param  list<string>  $columns
     */
    private function assertIndex(
        string $table,
        string $indexName,
        array $columns,
        ?bool $unique = null,
    ): void {
        $index = collect(Schema::getIndexes($table))->firstWhere('name', $indexName);

        $this->assertNotNull($index, "Expected index {$table}.{$indexName} to exist.");
        $this->assertSame($columns, array_values($index['columns']));

        if ($unique !== null) {
            $this->assertSame($unique, $index['unique']);
        }
    }

    private function assertIndexMissing(string $table, string $indexName): void
    {
        $this->assertFalse(
            collect(Schema::getIndexes($table))->contains(
                fn (array $index): bool => $index['name'] === $indexName
            ),
            "Expected index {$table}.{$indexName} to be absent."
        );
    }
}
