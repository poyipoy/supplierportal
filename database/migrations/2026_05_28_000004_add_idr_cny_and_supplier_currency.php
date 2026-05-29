<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('suppliers', 'currency')) {
            Schema::table('suppliers', function (Blueprint $table) {
                $table->enum('currency', ['USD', 'JPY', 'IDR', 'CNY'])->default('USD')->after('category');
            });
        }

        DB::statement("ALTER TABLE quotations MODIFY currency ENUM('USD','JPY','IDR','CNY') NOT NULL DEFAULT 'USD'");
        DB::statement("ALTER TABLE exchange_rates MODIFY currency ENUM('USD','JPY','IDR','CNY') NOT NULL");
        DB::statement("ALTER TABLE purchase_orders MODIFY currency ENUM('USD','JPY','IDR','CNY') NOT NULL DEFAULT 'USD'");

        $adminId = DB::table('users')->where('role', 'admin')->orderBy('id')->value('id');

        if ($adminId) {
            foreach (['IDR' => 1, 'CNY' => 2250] as $currency => $rate) {
                if (DB::table('exchange_rates')->where('currency', $currency)->exists()) {
                    continue;
                }

                DB::table('exchange_rates')->insert([
                    'currency' => $currency,
                    'rate_to_idr' => $rate,
                    'valid_from' => now()->toDateString(),
                    'created_by' => $adminId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('suppliers', 'currency')) {
            DB::table('suppliers')->whereIn('currency', ['IDR', 'CNY'])->update(['currency' => 'USD']);
        }

        DB::table('quotations')->whereIn('currency', ['IDR', 'CNY'])->update(['currency' => 'USD']);
        DB::table('purchase_orders')->whereIn('currency', ['IDR', 'CNY'])->update(['currency' => 'USD']);

        DB::table('exchange_rates')->whereIn('currency', ['IDR', 'CNY'])->delete();

        DB::statement("ALTER TABLE quotations MODIFY currency ENUM('USD','JPY') NOT NULL DEFAULT 'USD'");
        DB::statement("ALTER TABLE exchange_rates MODIFY currency ENUM('USD','JPY') NOT NULL");
        DB::statement("ALTER TABLE purchase_orders MODIFY currency ENUM('USD','JPY') NOT NULL DEFAULT 'USD'");

        // Fresh schema now defines suppliers.currency, so rollback only normalizes values.
    }
};
