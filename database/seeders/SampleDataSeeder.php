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
        $supplier1User = User::where('email', 'supplier1@test.com')->first();
        $supplier2User = User::where('email', 'supplier2@test.com')->first();
        $rateUsd = ExchangeRate::where('currency', 'USD')->first();
        $rateJpy = ExchangeRate::where('currency', 'JPY')->first();

        if (!$purchasing || !$supplier1User || !$supplier2User || !$rateUsd) {
            echo "Base users or exchange rates missing. Please run DatabaseSeeder first.\n";
            return;
        }

        DB::transaction(function () use ($purchasing, $supplier1User, $supplier2User, $rateUsd, $rateJpy) {
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
                'currency' => 'USD',
                'exchange_rate_id' => $rateUsd->id,
                'status' => 'accepted',
                'submitted_at' => now(),
            ]);

            QuotationItem::create([
                'quotation_id' => $quotation1->id,
                'pr_item_id' => $item1->id,
                'price_per_kg' => 0.85,
                'amount' => 0.85 * 5000,
            ]);

            QuotationItem::create([
                'quotation_id' => $quotation1->id,
                'pr_item_id' => $item2->id,
                'price_per_kg' => 0.95,
                'amount' => 0.95 * 3000,
            ]);

            // 5. Create Quotation from Supplier 2 (Rejected)
            $quotation2 = Quotation::create([
                'pr_id' => $pr->id,
                'supplier_id' => $supplier2User->id,
                'currency' => 'JPY',
                'exchange_rate_id' => $rateJpy->id,
                'status' => 'rejected',
                'submitted_at' => now()->subDay(),
            ]);

            QuotationItem::create([
                'quotation_id' => $quotation2->id,
                'pr_item_id' => $item1->id,
                'price_per_kg' => 135,
                'amount' => 135 * 5000,
            ]);

            QuotationItem::create([
                'quotation_id' => $quotation2->id,
                'pr_item_id' => $item2->id,
                'price_per_kg' => 150,
                'amount' => 150 * 3000,
            ]);

            // 6. Create Purchase Order for Quotation 1
            $po = PurchaseOrder::create([
                'quotation_id' => $quotation1->id,
                'po_number' => PurchaseOrder::generatePoNumber(),
                'status' => 'active',
                'created_by' => $purchasing->id,
                'estimated_arrival' => now()->addDays(14),
                'notes' => 'Tolong pastikan packing rapi agar tidak berkarat saat pengiriman via laut.',
            ]);

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
                'currency' => 'USD',
                'exchange_rate_id' => $rateUsd->id,
                'status' => 'submitted',
                'submitted_at' => now(),
            ]);

            QuotationItem::create([
                'quotation_id' => $quotation3->id,
                'pr_item_id' => $item3->id,
                'price_per_kg' => 1.05,
                'amount' => 1.05 * 10000,
            ]);

            echo "Sample data generated successfully!\n";
        });
    }
}
