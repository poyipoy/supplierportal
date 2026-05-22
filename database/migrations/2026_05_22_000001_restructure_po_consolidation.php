<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Create pivot table po_quotations
        Schema::create('po_quotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('po_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('quotation_id')->constrained('quotations')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['po_id', 'quotation_id']);
        });

        // 2. Add direct supplier_id, currency, exchange_rate_id to purchase_orders
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->foreignId('supplier_id')->nullable()->after('id')->constrained('users');
            $table->enum('currency', ['USD', 'JPY'])->default('USD')->after('supplier_id');
            $table->foreignId('exchange_rate_id')->nullable()->after('currency')->constrained('exchange_rates')->nullOnDelete();
        });

        // 3. Migrate existing data: move quotation_id → po_quotations pivot + fill supplier_id/currency
        $pos = DB::table('purchase_orders')->whereNotNull('quotation_id')->get();
        foreach ($pos as $po) {
            $quotation = DB::table('quotations')->where('id', $po->quotation_id)->first();
            if ($quotation) {
                // Insert into pivot
                DB::table('po_quotations')->insert([
                    'po_id' => $po->id,
                    'quotation_id' => $quotation->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Fill direct columns
                DB::table('purchase_orders')->where('id', $po->id)->update([
                    'supplier_id' => $quotation->supplier_id,
                    'currency' => $quotation->currency,
                    'exchange_rate_id' => $quotation->exchange_rate_id ?? null,
                ]);
            }
        }

        // 4. Drop quotation_id FK and column
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropForeign(['quotation_id']);
            $table->dropColumn('quotation_id');
        });

        // 5. Make supplier_id NOT NULL now that data is migrated
        // (MySQL requires dropping nullable in separate step)
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->foreignId('supplier_id')->nullable(false)->change();
        });

        // 6. Add index for performance
        Schema::table('po_quotations', function (Blueprint $table) {
            $table->index('po_id');
            $table->index('quotation_id');
        });
    }

    public function down(): void
    {
        // Re-add quotation_id
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->foreignId('quotation_id')->nullable()->after('id')->constrained('quotations')->cascadeOnDelete();
        });

        // Migrate pivot data back
        $pivots = DB::table('po_quotations')->get();
        foreach ($pivots as $pivot) {
            DB::table('purchase_orders')->where('id', $pivot->po_id)->update([
                'quotation_id' => $pivot->quotation_id,
            ]);
        }

        // Drop new columns
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropForeign(['supplier_id']);
            $table->dropColumn(['supplier_id', 'currency', 'exchange_rate_id']);
        });

        Schema::dropIfExists('po_quotations');
    }
};
