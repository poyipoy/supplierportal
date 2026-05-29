<?php

namespace Database\Seeders;

use App\Models\ExchangeRate;
use App\Models\Period;
use App\Models\PrItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequirement;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SampleDataSeeder extends Seeder
{
    public function run(): void
    {
        $purchasing = User::where('role', 'purchasing')->first();
        $supplier1User = User::with('supplier')->where('email', 'supplier1@test.com')->first();
        $supplier2User = User::with('supplier')->where('email', 'supplier2@test.com')->first();
        $rateUsd = ExchangeRate::where('currency', 'USD')->first();

        if (!$purchasing || !$supplier1User || !$supplier2User || !$rateUsd) {
            echo "Base users or exchange rates missing. Please run DatabaseSeeder first.\n";
            return;
        }

        DB::transaction(function () use ($purchasing, $supplier1User, $supplier2User, $rateUsd) {
            $currency1 = $supplier1User->supplier->currency ?? ExchangeRate::CURRENCY_USD;
            $currency2 = $supplier2User->supplier->currency ?? ExchangeRate::CURRENCY_USD;
            $rate1 = ExchangeRate::latestRate($currency1) ?? $rateUsd;
            $rate2 = ExchangeRate::latestRate($currency2) ?? $rateUsd;
            $convertFromUsd = function (float $usdPrice, ExchangeRate $targetRate) use ($rateUsd): float {
                return round(($usdPrice * (float) $rateUsd->rate_to_idr) / (float) $targetRate->rate_to_idr, 4);
            };

            // 1. Create a Period
            $period = Period::create([
                'name' => 'Kebutuhan Produksi Mei 2026',
                'month' => 5,
                'year' => 2026,
                'status' => 'open',
                'created_by' => $purchasing->id,
            ]);

            // 2. Create a PR
            $pr = PurchaseRequirement::create([
                'period_id' => $period->id,
                'created_by' => $purchasing->id,
                'pr_number' => PurchaseRequirement::generatePrNumber(),
                'status' => 'completed', // we will simulate a completed PR
                'notes' => 'Tolong segera diproses untuk line produksi 1',
            ]);

            // 3. Create PR Items
            $item1 = PrItem::create([
                'pr_id' => $pr->id,
                'hs_code' => '7208.39.90',
                'material_name' => 'Hot Rolled Steel Sheet',
                'shape' => 'Coil',
                'thickness' => 2.0,
                'width' => 1219,
                'weight_needed' => 5000,
            ]);

            $item2 = PrItem::create([
                'pr_id' => $pr->id,
                'hs_code' => '7209.16.00',
                'material_name' => 'Cold Rolled Steel Sheet',
                'shape' => 'Sheet',
                'thickness' => 1.0,
                'width' => 1000,
                'length' => 2000,
                'weight_needed' => 3000,
            ]);

            // 4. Create Quotation from Supplier 1 (Accepted)
            $quotation1 = Quotation::create([
                'pr_id' => $pr->id,
                'supplier_id' => $supplier1User->id,
                'currency' => $currency1,
                'exchange_rate_id' => $rate1->id,
                'status' => 'accepted',
                'submitted_at' => now(),
            ]);

            $item1Supplier1Price = $convertFromUsd(0.85, $rate1);
            QuotationItem::create([
                'quotation_id' => $quotation1->id,
                'pr_item_id' => $item1->id,
                'price_per_kg' => $item1Supplier1Price,
                'amount' => $item1Supplier1Price * 5000,
            ]);

            $item2Supplier1Price = $convertFromUsd(0.95, $rate1);
            QuotationItem::create([
                'quotation_id' => $quotation1->id,
                'pr_item_id' => $item2->id,
                'price_per_kg' => $item2Supplier1Price,
                'amount' => $item2Supplier1Price * 3000,
            ]);

            // 5. Create Quotation from Supplier 2 (Rejected)
            $quotation2 = Quotation::create([
                'pr_id' => $pr->id,
                'supplier_id' => $supplier2User->id,
                'currency' => $currency2,
                'exchange_rate_id' => $rate2->id,
                'status' => 'rejected',
                'submitted_at' => now()->subDay(),
            ]);

            $item1Supplier2Price = $convertFromUsd(0.9, $rate2);
            QuotationItem::create([
                'quotation_id' => $quotation2->id,
                'pr_item_id' => $item1->id,
                'price_per_kg' => $item1Supplier2Price,
                'amount' => $item1Supplier2Price * 5000,
            ]);

            $item2Supplier2Price = $convertFromUsd(1.0, $rate2);
            QuotationItem::create([
                'quotation_id' => $quotation2->id,
                'pr_item_id' => $item2->id,
                'price_per_kg' => $item2Supplier2Price,
                'amount' => $item2Supplier2Price * 3000,
            ]);

            // 6. Create Purchase Order for Quotation 1
            $po = PurchaseOrder::create([
                'supplier_id' => $quotation1->supplier_id,
                'currency' => $quotation1->currency,
                'exchange_rate_id' => $quotation1->exchange_rate_id,
                'po_number' => PurchaseOrder::generatePoNumber(),
                'status' => 'active',
                'created_by' => $purchasing->id,
                'estimated_arrival' => now()->addDays(14),
                'notes' => 'Tolong pastikan packing rapi agar tidak berkarat saat pengiriman via laut.',
            ]);

            $po->quotations()->syncWithoutDetaching([$quotation1->id]);

            // 7. Create PO Documents
            \App\Models\PoDocument::create(['po_id' => $po->id, 'doc_type' => 'invoice', 'status' => 'received']);
            \App\Models\PoDocument::create(['po_id' => $po->id, 'doc_type' => 'bl', 'status' => 'issued']);
            \App\Models\PoDocument::create(['po_id' => $po->id, 'doc_type' => 'packing_list', 'status' => 'pending']);
            \App\Models\PoDocument::create(['po_id' => $po->id, 'doc_type' => 'form_e', 'status' => 'processing']);

            // 8. Create a pending PR (bidding state)
            $pr2 = PurchaseRequirement::create([
                'period_id' => $period->id,
                'created_by' => $purchasing->id,
                'pr_number' => PurchaseRequirement::generatePrNumber(),
                'status' => 'bidding',
            ]);

            $item3 = PrItem::create([
                'pr_id' => $pr2->id,
                'hs_code' => '7209.18.99',
                'material_name' => 'Galvanized Iron Sheet',
                'shape' => 'Coil',
                'thickness' => 1.5,
                'weight_needed' => 10000,
            ]);

            $quotation3 = Quotation::create([
                'pr_id' => $pr2->id,
                'supplier_id' => $supplier2User->id,
                'currency' => $currency2,
                'exchange_rate_id' => $rate2->id,
                'status' => 'submitted',
                'submitted_at' => now(),
            ]);

            $item3Supplier2Price = $convertFromUsd(1.05, $rate2);
            QuotationItem::create([
                'quotation_id' => $quotation3->id,
                'pr_item_id' => $item3->id,
                'price_per_kg' => $item3Supplier2Price,
                'amount' => $item3Supplier2Price * 10000,
            ]);

            echo "Sample data generated successfully!\n";
        });
    }
}
