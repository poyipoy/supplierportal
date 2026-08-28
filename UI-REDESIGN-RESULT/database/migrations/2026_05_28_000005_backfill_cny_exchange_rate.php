<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $adminId = DB::table('users')->where('role', 'admin')->orderBy('id')->value('id');

        if (! $adminId || DB::table('exchange_rates')->where('currency', 'CNY')->exists()) {
            return;
        }

        DB::table('exchange_rates')->insert([
            'currency' => 'CNY',
            'rate_to_idr' => 2250,
            'valid_from' => now()->toDateString(),
            'created_by' => $adminId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('exchange_rates')
            ->where('currency', 'CNY')
            ->where('rate_to_idr', 2250)
            ->delete();
    }
};
