<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Purchasing-selected ordered quantity, values frozen at PO finalization, and
 * the specification-difference acceptance record (decisions 01-04, 07; Q2).
 *
 * Every column is nullable: NULL means "legacy / not selected", which keeps
 * the existing offer-based ordered values for every PO created before the
 * Mission 04 write path (Gate 1 section 11.2). Nothing is backfilled.
 */
return new class extends Migration
{
    private const TABLE = 'pr_item_awards';

    private const COLUMNS = [
        'ordered_qty',
        'ordered_weight_per_unit',
        'ordered_total_weight_kg',
        'ordered_price_per_kg',
        'ordered_amount',
        'spec_accepted_by',
        'spec_accepted_at',
        'spec_acceptance_snapshot',
        'spec_acceptance_hash',
    ];

    public function up(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->unsignedInteger('ordered_qty')->nullable()->after('awarded_at');
            $table->decimal('ordered_weight_per_unit', 12, 4)->nullable()->after('ordered_qty');
            $table->decimal('ordered_total_weight_kg', 14, 4)->nullable()->after('ordered_weight_per_unit');
            $table->decimal('ordered_price_per_kg', 15, 4)->nullable()->after('ordered_total_weight_kg');
            $table->decimal('ordered_amount', 15, 4)->nullable()->after('ordered_price_per_kg');
            $table->foreignId('spec_accepted_by')->nullable()->after('ordered_amount')->constrained('users')->nullOnDelete();
            $table->timestamp('spec_accepted_at')->nullable()->after('spec_accepted_by');
            $table->json('spec_acceptance_snapshot')->nullable()->after('spec_accepted_at');
            $table->char('spec_acceptance_hash', 64)->nullable()->after('spec_acceptance_snapshot');
        });

        if ($this->supportsCheckConstraints()) {
            DB::statement('ALTER TABLE pr_item_awards ADD CONSTRAINT pr_item_awards_ordered_qty_positive CHECK (ordered_qty IS NULL OR ordered_qty >= 1)');
            DB::statement(<<<'SQL'
                ALTER TABLE pr_item_awards
                ADD CONSTRAINT pr_item_awards_ordered_values_non_negative
                CHECK (
                    (ordered_weight_per_unit IS NULL OR ordered_weight_per_unit >= 0)
                    AND (ordered_total_weight_kg IS NULL OR ordered_total_weight_kg >= 0)
                    AND (ordered_price_per_kg IS NULL OR ordered_price_per_kg >= 0)
                    AND (ordered_amount IS NULL OR ordered_amount >= 0)
                )
                SQL);
        }
    }

    public function down(): void
    {
        // Frozen values belong to finalized POs (I-17); a selection belongs to
        // Purchasing. Neither may be discarded by a schema rollback.
        $withData = DB::table(self::TABLE)
            ->where(function ($query): void {
                foreach (self::COLUMNS as $column) {
                    $query->orWhereNotNull($column);
                }
            })
            ->count();

        if ($withData > 0) {
            throw new RuntimeException("Cannot roll back award ordered/acceptance fields: {$withData} award(s) carry an ordered quantity, frozen value or specification acceptance.");
        }

        $this->dropCheck('pr_item_awards_ordered_values_non_negative');
        $this->dropCheck('pr_item_awards_ordered_qty_positive');

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropConstrainedForeignId('spec_accepted_by');
            $table->dropColumn(array_values(array_diff(self::COLUMNS, ['spec_accepted_by'])));
        });
    }

    private function supportsCheckConstraints(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }

    private function dropCheck(string $name): void
    {
        if (! $this->supportsCheckConstraints()) {
            return;
        }

        $exists = DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', self::TABLE)
            ->where('CONSTRAINT_NAME', $name)
            ->exists();

        if ($exists) {
            DB::statement('ALTER TABLE '.self::TABLE." DROP CHECK {$name}");
        }
    }
};
