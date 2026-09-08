<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shipment_items')) {
            throw new RuntimeException('Table shipment_items does not exist.');
        }

        if (DB::table('shipment_items')->count() > 0) {
            throw new RuntimeException('Cannot migrate shipment_items to qty and actual weight: table is not empty.');
        }

        if (! Schema::hasColumn('shipment_items', 'shipped_quantity')) {
            throw new RuntimeException('Column shipped_quantity does not exist in shipment_items.');
        }

        if (Schema::hasColumn('shipment_items', 'shipped_qty') || Schema::hasColumn('shipment_items', 'actual_weight_kg')) {
            throw new RuntimeException('Target columns already exist in shipment_items.');
        }

        if (DB::getDriverName() === 'mysql') {
            $hasCheck = DB::table('information_schema.CHECK_CONSTRAINTS')
                ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
                ->where('CONSTRAINT_NAME', 'shipment_items_shipped_quantity_positive')
                ->exists();

            if ($hasCheck) {
                DB::statement(<<<'SQL'
                    ALTER TABLE shipment_items
                    DROP CHECK shipment_items_shipped_quantity_positive
                    SQL);
            }
        }

        Schema::table('shipment_items', function (Blueprint $table): void {
            $table->dropColumn('shipped_quantity');
            $table->unsignedInteger('shipped_qty')->after('pr_item_award_id');
            $table->decimal('actual_weight_kg', 12, 4)->after('shipped_qty');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement(<<<'SQL'
                ALTER TABLE shipment_items
                ADD CONSTRAINT shipment_items_shipped_qty_positive
                CHECK (shipped_qty > 0)
                SQL);

            DB::statement(<<<'SQL'
                ALTER TABLE shipment_items
                ADD CONSTRAINT shipment_items_actual_weight_kg_positive
                CHECK (actual_weight_kg > 0)
                SQL);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('shipment_items') && DB::table('shipment_items')->count() > 0) {
            throw new RuntimeException('Cannot roll back shipment item quantity schema while data exists.');
        }

        if (DB::getDriverName() === 'mysql') {
            $hasQtyCheck = DB::table('information_schema.CHECK_CONSTRAINTS')
                ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
                ->where('CONSTRAINT_NAME', 'shipment_items_shipped_qty_positive')
                ->exists();

            if ($hasQtyCheck) {
                DB::statement(<<<'SQL'
                    ALTER TABLE shipment_items
                    DROP CHECK shipment_items_shipped_qty_positive
                    SQL);
            }

            $hasWeightCheck = DB::table('information_schema.CHECK_CONSTRAINTS')
                ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
                ->where('CONSTRAINT_NAME', 'shipment_items_actual_weight_kg_positive')
                ->exists();

            if ($hasWeightCheck) {
                DB::statement(<<<'SQL'
                    ALTER TABLE shipment_items
                    DROP CHECK shipment_items_actual_weight_kg_positive
                    SQL);
            }
        }

        Schema::table('shipment_items', function (Blueprint $table): void {
            $table->dropColumn(['shipped_qty', 'actual_weight_kg']);
            $table->decimal('shipped_quantity', 12, 4)->after('pr_item_award_id');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement(<<<'SQL'
                ALTER TABLE shipment_items
                ADD CONSTRAINT shipment_items_shipped_quantity_positive
                CHECK (shipped_quantity > 0)
                SQL);
        }
    }
};
