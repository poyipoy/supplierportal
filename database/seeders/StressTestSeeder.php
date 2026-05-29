<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class StressTestSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Mulai generate data ribuan baris untuk stress test...');
        
        $admin = DB::table('users')->where('role', 'admin')->first();
        $purchasing = DB::table('users')->where('role', 'purchasing')->first();
        $supplier = DB::table('users')->where('role', 'supplier')->first();
        $qc = DB::table('users')->where('role', 'qc')->first();

        if (!$admin || !$purchasing || !$supplier || !$qc) {
            $this->command->error('User roles tidak lengkap. Pastikan seeder awal (SampleDataSeeder/ProductionDummySeeder) sudah dijalankan.');
            return;
        }

        $periodId = DB::table('periods')->insertGetId([
            'name' => 'Periode Stress Test',
            'month' => 5,
            'year' => 2026,
            'status' => 'open',
            'created_by' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $supplierCurrency = DB::table('suppliers')->where('user_id', $supplier->id)->value('currency') ?: 'USD';
        $rateId = DB::table('exchange_rates')
            ->where('currency', $supplierCurrency)
            ->orderByDesc('valid_from')
            ->value('id');

        if (!$rateId) {
            $rateId = DB::table('exchange_rates')->insertGetId([
                'currency' => $supplierCurrency,
                'rate_to_idr' => match ($supplierCurrency) {
                    'IDR' => 1,
                    'JPY' => 110,
                    'CNY' => 2250,
                    default => 16000,
                },
                'valid_from' => now(),
                'created_by' => $admin->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $rateToIdr = (float) DB::table('exchange_rates')->where('id', $rateId)->value('rate_to_idr');
        $currencyPriceMultiplier = $rateToIdr > 0 ? 16000 / $rateToIdr : 1;

        $totalPrs = 2000; // 2000 PR = 4000 PR Items = 2000 Quotations = 4000 Quotation Items = 2000 POs
        $chunkSize = 500;
        $now = now();
        
        $this->command->info("Generating $totalPrs Purchase Requirements & Items...");

        $prIdStart = DB::table('purchase_requirements')->max('id') ?? 0;
        $prItemIdStart = DB::table('pr_items')->max('id') ?? 0;

        $prChunks = [];
        $prItemChunks = [];

        for ($i = 1; $i <= $totalPrs; $i++) {
            $currentPrId = $prIdStart + $i;
            
            $prChunks[] = [
                'id' => $currentPrId,
                'period_id' => $periodId,
                'created_by' => $purchasing->id,
                'pr_number' => 'REQ/STRESS/' . str_pad($i, 5, '0', STR_PAD_LEFT),
                'notes' => 'Data untuk testing paginasi dan performa ' . $i,
                'status' => 'completed',
                'created_at' => $now,
                'updated_at' => $now,
            ];

            // 2 items per PR
            for ($j = 1; $j <= 2; $j++) {
                $prItemChunks[] = [
                    'id' => ++$prItemIdStart,
                    'pr_id' => $currentPrId,
                    'hs_code' => '7209.16.00',
                    'material_name' => 'Stress Test Material ' . $j,
                    'shape' => 'Flat',
                    'thickness' => rand(1, 10),
                    'd_inner' => null,
                    'd_outer' => null,
                    'width' => 1200,
                    'length' => 2400,
                    'weight_needed' => rand(1000, 5000),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($i % $chunkSize === 0) {
                DB::table('purchase_requirements')->insert($prChunks);
                DB::table('pr_items')->insert($prItemChunks);
                $prChunks = [];
                $prItemChunks = [];
            }
        }

        $this->command->info('Generating Quotations & Purchase Orders...');
        
        $quotationChunks = [];
        $quotationItemChunks = [];
        $poChunks = [];
        $poQuotationChunks = [];
        
        $quotationIdStart = DB::table('quotations')->max('id') ?? 0;
        $quotationItemIdStart = DB::table('quotation_items')->max('id') ?? 0;
        $poIdStart = DB::table('purchase_orders')->max('id') ?? 0;

        $prItems = DB::table('pr_items')->where('material_name', 'like', 'Stress Test Material%')->get()->groupBy('pr_id');
        
        $qCount = 0;
        foreach ($prItems as $prId => $items) {
            $qCount++;
            $currentQuotationId = ++$quotationIdStart;
            
            $quotationChunks[] = [
                'id' => $currentQuotationId,
                'pr_id' => $prId,
                'supplier_id' => $supplier->id,
                'currency' => $supplierCurrency,
                'exchange_rate_id' => $rateId,
                'status' => 'accepted',
                'submitted_at' => $now,
                'estimated_delivery' => $now->copy()->addDays(30),
                'payment_terms' => 'Net 30',
                'validity_period' => $now->copy()->addDays(14),
                'general_notes' => 'Stress test quotation',
                'created_at' => $now,
                'updated_at' => $now,
            ];

            foreach ($items as $item) {
                $price = round((rand(10, 50) / 10) * $currencyPriceMultiplier, 4);
                $quotationItemChunks[] = [
                    'id' => ++$quotationItemIdStart,
                    'quotation_id' => $currentQuotationId,
                    'pr_item_id' => $item->id,
                    'price_per_kg' => $price,
                    'amount' => $price * $item->weight_needed,
                    'notes' => 'Stress test item',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            $currentPoId = ++$poIdStart;

            $poChunks[] = [
                'id' => $currentPoId,
                'supplier_id' => $supplier->id,
                'currency' => $supplierCurrency,
                'exchange_rate_id' => $rateId,
                'po_number' => 'PO/STRESS/' . str_pad($qCount, 5, '0', STR_PAD_LEFT),
                'status' => 'completed',
                'estimated_arrival' => $now->copy()->addDays(30),
                'actual_arrival' => $now->copy()->addDays(35),
                'created_by' => $purchasing->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $poQuotationChunks[] = [
                'po_id' => $currentPoId,
                'quotation_id' => $currentQuotationId,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if ($qCount % $chunkSize === 0) {
                DB::table('quotations')->insert($quotationChunks);
                DB::table('quotation_items')->insert($quotationItemChunks);
                DB::table('purchase_orders')->insert($poChunks);
                DB::table('po_quotations')->insert($poQuotationChunks);
                $quotationChunks = [];
                $quotationItemChunks = [];
                $poChunks = [];
                $poQuotationChunks = [];
            }
        }

        if (!empty($quotationChunks)) {
            DB::table('quotations')->insert($quotationChunks);
            DB::table('quotation_items')->insert($quotationItemChunks);
            DB::table('purchase_orders')->insert($poChunks);
            DB::table('po_quotations')->insert($poQuotationChunks);
        }

        $this->command->info('Data stress test berhasil di-generate! (Ribuan baris data ditambahkan)');
    }
}
