<?php

namespace Tests\Feature;

use App\Exports\PurchaseOrderDetailExport;
use App\Exports\PurchaseOrdersExport;
use App\Exports\QuotationDetailExport;
use App\Exports\ShipmentsExport;
use App\Models\ExchangeRate;
use App\Models\LocalPurchaseOrder;
use App\Models\MaterialClaim;
use App\Models\Period;
use App\Models\PrItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\QcInspection;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\RegionalDisplayFormatter;
use App\Services\ShipmentService;
use App\Support\Money;
use App\Support\NumberFormat;
use App\Support\StatusHelper;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RegionalScopeProtectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_fixed_po_pdf_and_export_rows_ignore_personal_display_preferences(): void
    {
        $this->travelTo(Carbon::parse('2026-10-28 03:00:00', 'UTC'));
        $po = $this->purchaseOrder();
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user);
        app()->setLocale('en');
        $beforePdf = view('pdf.po-pdf', ['po' => $po, 'quotationRates' => [1 => $po->quotations->first()->exchange_rate]])->render();
        $this->assertStringContainsString('29 Sep 2026', $beforePdf);
        $beforeRow = (new PurchaseOrdersExport)->map($po);
        $this->assertSame(1250000.5, $beforeRow[5]);
        $this->assertSame('2026-09-28', $beforeRow[7]);
        $this->assertStringContainsString('1,250,000.50', $beforePdf);
        $user->preference()->create([...config('user_preferences.defaults'), 'timezone' => 'Asia/Jakarta', 'date_format' => 'iso', 'time_format' => '12h', 'number_format' => 'international']);
        $this->assertSame($beforePdf, view('pdf.po-pdf', ['po' => $po, 'quotationRates' => [1 => $po->quotations->first()->exchange_rate]])->render());
        $this->assertSame($beforeRow, (new PurchaseOrdersExport)->map($po));

        app()->setLocale('id');
        $indonesianPdf = view('pdf.po-pdf', ['po' => $po, 'quotationRates' => [1 => $po->quotations->first()->exchange_rate]])->render();
        $this->assertSame($beforePdf, $indonesianPdf, 'The fixed PO form stays English for both user locales.');
        app()->setLocale('en');
    }

    public function test_voucher_print_uses_authoritative_snapshot_format_with_every_personal_preset(): void
    {
        app()->setLocale('en');
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
        $this->assertStringContainsString('Phone: 021-39506699', $before);
        $this->assertStringContainsString('Website: www.astra-daido.co.id', $before);
        $this->assertStringContainsString('Rp 1.362.500,55', $before);
        app()->setLocale('id');
        $indonesian = view('finance.vouchers.print', compact('voucher'))->render();
        $this->assertStringContainsString('Telepon: 021-39506699', $indonesian);
        $this->assertStringContainsString('Situs web: www.astra-daido.co.id', $indonesian);
        app()->setLocale('en');
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

    public function test_detail_exports_and_commercial_values_ignore_regional_display_settings(): void
    {
        $this->travelTo(Carbon::parse('2026-09-29T08:00:00Z'));
        $purchasing = User::factory()->create(['role' => 'purchasing']);
        $supplier = User::factory()->create(['role' => 'supplier']);
        $period = Period::create(['name' => 'Regional Detail Scope', 'month' => 9, 'year' => 2026, 'status' => 'open', 'created_by' => $purchasing->id]);
        $pr = PurchaseRequisition::create(['period_id' => $period->id, 'created_by' => $purchasing->id, 'pr_number' => 'REQ-DETAIL-SCOPE', 'status' => 'completed']);
        $prItem = $pr->items()->create(['material_name' => 'Scope Plate', 'hs_code' => '7209.16.00', 'shape' => 'Flat', 'quantity' => 4, 'weight_needed' => '125.25', 'thickness' => 2.5, 'width' => 1000, 'length' => 2000]);
        $rate = ExchangeRate::create(['currency' => 'USD', 'rate_to_idr' => '16000.1234', 'valid_from' => '2026-09-28', 'created_by' => $purchasing->id]);
        $quotation = Quotation::create(['pr_id' => $pr->id, 'supplier_id' => $supplier->id, 'currency' => 'USD', 'exchange_rate_id' => $rate->id, 'status' => 'accepted', 'submitted_at' => '2026-09-28 23:35:00', 'estimated_delivery' => '2026-09-30', 'validity_period' => '2026-10-05']);
        $item = $quotation->items()->create(['pr_item_id' => $prItem->id, 'price_per_kg' => '12.3456', 'amount' => '2500.5000', 'is_available' => true, 'available_qty' => 4, 'offered_weight_per_unit' => '125.25']);
        $po = PurchaseOrder::create(['po_number' => 'PO-DETAIL-SCOPE', 'supplier_id' => $supplier->id, 'currency' => 'USD', 'exchange_rate_id' => $rate->id, 'status' => 'active', 'created_by' => $purchasing->id, 'estimated_arrival' => '2026-09-30']);
        $po->quotations()->attach($quotation->id);
        $shipment = app(ShipmentService::class)->createDraft($supplier, [
            'shipment_date' => '2026-09-28', 'estimated_arrival_date' => '2026-09-30',
            'items' => [['purchase_order_id' => $po->id, 'quotation_item_id' => $item->id, 'shipped_qty' => 2, 'actual_weight_kg' => '1234.5678']],
        ]);
        $inspection = QcInspection::create(['po_id' => $po->id, 'inspected_by' => $purchasing->id, 'status' => 'ng', 'inspected_at' => '2026-09-28 23:35:00']);
        $claim = MaterialClaim::create(['inspection_id' => $inspection->id, 'po_id' => $po->id, 'submitted_by' => $purchasing->id, 'supplier_id' => $supplier->id, 'status' => 'pending', 'description' => 'Scope protection', 'resolution_expected' => 'Replacement', 'deadline' => '2026-10-02']);
        $claim->forceFill(['created_at' => '2026-09-28 23:35:00', 'updated_at' => '2026-09-28 23:35:00'])->save();
        $this->actingAs($purchasing);
        $snapshot = fn () => [
            'po_rows' => (new PurchaseOrderDetailExport($po->id))->collection()->all(),
            'quotation_rows' => (new QuotationDetailExport($quotation->id))->collection()->all(),
            'shipment_row' => (new ShipmentsExport)->map($shipment->fresh(['items', 'supplier'])),
            'item' => $item->fresh()->getAttributes(),
            'po' => $po->fresh()->getAttributes(),
            'quotation' => $quotation->fresh()->getAttributes(),
            'shipment' => $shipment->fresh()->getAttributes(),
            'commercial' => [$item->fresh()->requested_amount, $item->fresh()->offer_amount, $item->fresh()->resolved_amount],
            'expiry' => $quotation->fresh()->isExpired(),
            'overdue' => $po->fresh()->is_overdue,
            'claim' => $claim->fresh()->getAttributes(),
            'deadline_meta' => StatusHelper::claimDeadlineMeta($claim->deadline, $claim->status),
        ];
        $before = $snapshot();
        $this->assertSame(2, $before['shipment_row'][4]);
        $this->assertSame('1234.57', $before['shipment_row'][5]);
        $this->assertSame('2026-09-28', $before['shipment_row'][6]);
        $this->assertSame(2500.5, $before['commercial'][2]);
        $preference = $purchasing->preference()->create(config('user_preferences.defaults'));
        foreach (['international', 'indonesian'] as $number) {
            $preference->update(['timezone' => 'Asia/Jakarta', 'date_format' => 'dmy', 'time_format' => '12h', 'number_format' => $number]);
            app()->forgetScopedInstances();
            $this->assertSame($before, $snapshot());
        }
    }

    public function test_material_claim_notification_deadline_text_does_not_follow_regional_preferences(): void
    {
        $this->travelTo(Carbon::parse('2026-09-29T12:00:00Z'));
        $purchasing = User::factory()->create(['role' => 'purchasing']);
        $supplier = User::factory()->create(['role' => 'supplier']);
        $po = PurchaseOrder::create(['supplier_id' => $supplier->id, 'currency' => 'USD', 'po_number' => 'PO-CLAIM-NOTIFICATION', 'status' => 'claim_needed', 'created_by' => $purchasing->id, 'estimated_arrival' => '2026-10-02']);
        $messages = [];
        $this->mock(NotificationService::class)
            ->shouldReceive('send')->twice()
            ->andReturnUsing(function (...$args) use (&$messages, $supplier): void {
                $this->assertSame($supplier->id, $args[0]->id);
                $this->assertSame('claim.created', $args[1]);
                $messages[] = [
                    'message' => $args[4],
                    'replace' => $args[8] ?? [],
                    'localized_replace' => $args[9] ?? [],
                ];
            });
        $this->actingAs($purchasing);
        foreach (['system', 'iso'] as $date) {
            if ($date !== 'system') {
                foreach ([$purchasing, $supplier] as $user) {
                    $user->preference()->create([...config('user_preferences.defaults'), 'timezone' => 'Asia/Jakarta', 'date_format' => $date, 'time_format' => '12h', 'number_format' => 'indonesian']);
                }
                app()->forgetScopedInstances();
            }
            $inspection = QcInspection::create(['po_id' => $po->id, 'inspected_by' => $purchasing->id, 'status' => 'ng', 'inspected_at' => '2026-09-28 23:35:00']);
            $this->post(route('purchasing.claims.store'), [
                'inspection_id' => $inspection->id, 'description' => 'Scope notification',
                'resolution_expected' => 'Replacement', 'deadline' => '2026-10-02',
            ])->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success');
        }
        $this->assertCount(2, $messages);
        $this->assertSame($messages[0], $messages[1]);
        $this->assertSame([
            'message' => 'claims.notify.created_body',
            'replace' => ['po' => 'PO-CLAIM-NOTIFICATION'],
            'localized_replace' => ['deadline' => '@date:2026-10-02'],
        ], $messages[0]);
        $this->assertSame(['2026-10-02', '2026-10-02'], DB::table('material_claims')->orderBy('id')->pluck('deadline')->all());
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
