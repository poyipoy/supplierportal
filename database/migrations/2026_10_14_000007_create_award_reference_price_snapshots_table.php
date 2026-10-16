<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reference price captured at PO finalization (decision 31, I-19). Insert-only:
 * exactly one row per award, matched or not_available. Rows are written by
 * Mission 06B inside PO generation and never backfilled for earlier POs, whose
 * absence of a row means "not captured". Foreign keys restrict so a snapshot
 * can never silently disappear.
 */
return new class extends Migration
{
    private const TABLE = 'award_reference_price_snapshots';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pr_item_award_id')->unique()->constrained('pr_item_awards')->restrictOnDelete();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            // Explicit name: the generated one exceeds MySQL's 64-character limit.
            $table->foreignId('material_reference_price_id')->nullable()
                ->constrained('material_reference_prices', 'id', 'award_ref_price_snapshots_reference_price_fk')->restrictOnDelete();
            $table->string('status', 20);
            $table->string('reason_code', 40)->nullable();
            $table->date('evaluation_date');
            $table->string('dimension_field', 20)->nullable();
            $table->decimal('dimension_value', 10, 4)->nullable();
            $table->string('reference_currency', 3)->nullable();
            $table->decimal('reference_price_per_kg', 15, 4)->nullable();
            $table->string('po_currency', 3);
            $table->foreignId('reference_rate_id')->nullable()->constrained('exchange_rates')->restrictOnDelete();
            $table->foreignId('po_rate_id')->nullable()->constrained('exchange_rates')->restrictOnDelete();
            $table->decimal('converted_reference_price_per_kg', 15, 4)->nullable();
            $table->decimal('offer_price_per_kg', 15, 4)->nullable();
            $table->decimal('diff_amount_per_kg', 15, 4)->nullable();
            $table->decimal('diff_percent', 9, 4)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            // A match names its reference row; a miss names its reason (Q6A).
            DB::statement(<<<'SQL'
                ALTER TABLE award_reference_price_snapshots
                ADD CONSTRAINT award_reference_price_snapshots_status_coherent
                CHECK (
                    (CAST(status AS BINARY) = 'matched' AND material_reference_price_id IS NOT NULL)
                    OR (CAST(status AS BINARY) = 'not_available' AND reason_code IS NOT NULL)
                )
                SQL);
        }
    }

    public function down(): void
    {
        $snapshots = DB::table(self::TABLE)->count();

        if ($snapshots > 0) {
            throw new RuntimeException("Cannot roll back award reference price snapshots: {$snapshots} immutable snapshot row(s) exist.");
        }

        Schema::dropIfExists(self::TABLE);
    }
};
