<?php

namespace Tests\Feature;

use App\Exports\PurchaseOrdersExport;
use App\Models\ExchangeRate;
use App\Models\LocalPurchaseOrder;
use App\Models\Period;
use App\Models\PrItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\User;
use App\Services\RegionalDisplayFormatter;
use App\Support\Money;
use App\Support\NumberFormat;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RegionalScopeProtectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_fixed_po_pdf_and_export_rows_ignore_personal_display_preferences(): void
    {
        $po = $this->purchaseOrder();
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user);
        $beforePdf = view('pdf.po-pdf', ['po' => $po, 'quotationRates' => [1 => $po->quotations->first()->exchange_rate]])->render();
        $beforeRow = (new PurchaseOrdersExport)->map($po);
        $this->assertSame(1250000.5, $beforeRow[5]);
        $this->assertSame('2026-09-28', $beforeRow[7]);
        $this->assertStringContainsString('1.250.000,50', $beforePdf);
        $user->preference()->create([...config('user_preferences.defaults'), 'timezone' => 'Asia/Jakarta', 'date_format' => 'iso', 'time_format' => '12h', 'number_format' => 'international']);
        $this->assertSame($beforePdf, view('pdf.po-pdf', ['po' => $po, 'quotationRates' => [1 => $po->quotations->first()->exchange_rate]])->render());
        $this->assertSame($beforeRow, (new PurchaseOrdersExport)->map($po));
    }

    public function test_voucher_print_uses_authoritative_snapshot_format_with_every_personal_preset(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user);
        $voucher = (object) [
            'voucher_number' => 'VB/09/2026/001', 'batch' => (object) ['batch_number' => 'DRP/09/2026/001'],
            'voucher_date' => Carbon::parse('2026-09-28'), 'payment_method' => 'BANK',
            'supplier_name_snapshot' => 'Fixed Supplier', 'npwp_snapshot' => '012345',
            'bank_name_snapshot' => 'BCA', 'bank_account_snapshot' => '1234', 'bank_account_holder_snapshot' => 'Fixed Supplier',
            'invoice_number_snapshot' => 'INV-001', 'po_number_snapshot' => 'PO-001', 'gr_references_snapshot' => 'GR-001',
            'dpp_snapshot' => '1250000.50', 'ppn_snapshot' => '137500.06', 'pph_snapshot' => '25000.01', 'amount' => '1362500.55',
            'terbilang_snapshot' => 'Authoritative words', 'remarks_snapshot' => null,
            'finalized_at' => Carbon::parse('2026-09-28T23:35:00Z'),
        ];
        $before = view('finance.vouchers.print', compact('voucher'))->render();
        $this->assertStringContainsString('Rp 1.362.500,55', $before);
        $preference = $user->preference()->create([...config('user_preferences.defaults'), 'timezone' => 'Asia/Jakarta', 'date_format' => 'dmy', 'time_format' => '12h', 'number_format' => 'international']);
        $this->assertSame($before, view('finance.vouchers.print', compact('voucher'))->render());
        $preference->update(['date_format' => 'iso', 'number_format' => 'indonesian']);
        $this->assertSame($before, view('finance.vouchers.print', compact('voucher'))->render());
    }

    public function test_display_never_changes_stored_values_query_sorting_or_deadline_and_money_semantics(): void
    {
        $creator = User::factory()->create(['role' => 'purchasing']);
        $supplier = User::factory()->create(['role' => 'supplier']);
        $first = PurchaseOrder::create(['supplier_id' => $supplier->id, 'currency' => 'USD', 'po_number' => 'PO-FIRST', 'status' => 'active', 'created_by' => $creator->id, 'estimated_arrival' => '2026-09-28']);
        $first->forceFill(['created_at' => '2026-09-28 23:35:00'])->save();
        $second = PurchaseOrder::create(['supplier_id' => $supplier->id, 'currency' => 'USD', 'po_number' => 'PO-SECOND', 'status' => 'active', 'created_by' => $creator->id, 'estimated_arrival' => '2026-09-29']);
        $second->forceFill(['created_at' => '2026-09-29 00:00:00'])->save();
        $localPo = LocalPurchaseOrder::create(['supplier_id' => $supplier->id, 'po_number' => 'LOCAL-FIXED', 'total_amount' => '1250000.50', 'currency' => 'IDR', 'status' => 'OPEN', 'po_date' => '2026-09-28', 'source' => 'MANUAL', 'created_by' => $creator->id]);
        $storedAmount = DB::table('local_purchase_orders')->where('id', $localPo->id)->value('total_amount');
        $before = DB::table('purchase_orders')->whereIn('id', [$first->id, $second->id])->orderBy('created_at')->get()->toArray();
        $deadlineResult = $first->is_overdue;
        $calculation = Money::multiply('1250000.50', '0.11');
        $this->assertSame('137500.06', $calculation);
        $formatter = new RegionalDisplayFormatter(['timezone' => 'Asia/Jakarta', 'date_format' => 'dmy', 'time_format' => '12h', 'number_format' => 'indonesian']);
        $formatter->timestamp($first->created_at);
        $formatter->date($first->estimated_arrival);
        $formatter->number(NumberFormat::maxDecimals($calculation), 'decimal');
        $formatter->number($localPo->total_amount, 'decimal');
        $this->assertSame('1250000.50', $storedAmount);
        $this->assertSame($storedAmount, DB::table('local_purchase_orders')->where('id', $localPo->id)->value('total_amount'));
        $this->assertEquals($before, DB::table('purchase_orders')->whereIn('id', [$first->id, $second->id])->orderBy('created_at')->get()->toArray());
        $this->assertSame($deadlineResult, $first->is_overdue);
        $this->assertSame($calculation, Money::multiply('1250000.50', '0.11'));
        $this->assertSame('2026-09-28', $first->estimated_arrival->format('Y-m-d'));
        $this->assertSame('2026-09-28 23:35:00', $first->created_at->format('Y-m-d H:i:s'));
    }

    private function purchaseOrder(): PurchaseOrder
    {
        $supplier = new User(['name' => 'Fixed Supplier']);
        $creator = new User(['name' => 'Fixed Purchasing']);
        $period = new Period(['name' => 'September 2026', 'month' => 9, 'year' => 2026]);
        $pr = (new PurchaseRequisition(['pr_number' => 'REQ/09/2026/001']))->setRelation('period', $period);
        $pr->id = 1;
        $prItem = new PrItem(['material_name' => 'Fixed Material', 'shape' => 'Flat', 'quantity' => 2, 'weight_needed' => 100, 'hs_code' => '7209.16.00']);
        $rate = new ExchangeRate(['currency' => 'USD', 'rate_to_idr' => 16000]);
        $item = new QuotationItem(['quotation_id' => 1, 'amount' => 1250000.5, 'price_per_kg' => 6250.0025]);
        $item->id = 1;
        $item->setRelation('prItem', $prItem);
        $quotation = new Quotation(['currency' => 'USD']);
        $quotation->id = 1;
        $quotation->setRelation('items', collect([$item]))->setRelation('purchaseRequisition', $pr)->setRelation('exchange_rate', $rate);
        $po = new PurchaseOrder(['po_number' => 'PO/09/2026/001', 'currency' => 'USD', 'status' => 'completed', 'estimated_arrival' => '2026-09-28', 'notes' => 'Fixed remark']);
        $po->created_at = Carbon::parse('2026-09-28T23:35:00Z');
        $po->setRelation('supplier', $supplier)->setRelation('creator', $creator)->setRelation('quotations', collect([$quotation]))->setRelation('awards', collect());

        return $po;
    }
}
