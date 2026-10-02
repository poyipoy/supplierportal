<?php

namespace Tests\Feature;

use App\Models\LocalGoodsReceipt;
use App\Models\LocalInvoice;
use App\Models\LocalInvoicePayment;
use App\Models\LocalInvoicePaymentTransfer;
use App\Models\LocalInvoiceReceipt;
use App\Models\LocalInvoiceStatusHistory;
use App\Models\LocalInvoiceVoucher;
use App\Models\LocalPurchaseOrder;
use App\Models\PaymentBatch;
use App\Models\PaymentGroup;
use App\Models\PaymentItem;
use App\Models\Supplier;
use App\Models\SupplierOverpaymentRefund;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegionalInvoiceFinancialDisplayTest extends TestCase
{
    use RefreshDatabase;

    private User $supplier;

    private User $finance;

    private User $purchasing;

    private LocalPurchaseOrder $po;

    private LocalInvoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-30T12:00:00Z'));

        $this->supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $this->supplier->supplierScopes()->create(['scope' => 'local']);
        Supplier::create([
            'user_id' => $this->supplier->id,
            'company_name' => 'PT Vendor Regional Financial',
            'vendor_category' => 'Barang',
            'is_pkp' => true,
            'payment_term_days' => 30,
        ]);

        $this->finance = User::factory()->create(['role' => 'finance', 'is_active' => true]);
        $this->purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);

        $this->po = LocalPurchaseOrder::create([
            'supplier_id' => $this->supplier->id,
            'po_number' => 'PO-FIN-001',
            'po_date' => '2026-09-15',
            'currency' => 'IDR',
            'total_amount' => '5000000.00',
            'status' => 'OPEN',
        ]);

        $this->invoice = LocalInvoice::create([
            'supplier_id' => $this->supplier->id,
            'submission_number' => 'SUB-FIN-001',
            'invoice_number' => 'INV-FIN-001',
            'invoice_date' => '2026-09-20',
            'invoice_amount' => '1250000.00',
            'tax_amount' => '137500.00',
            'ppn_scheme' => '11%',
            'status' => LocalInvoice::STATUS_READY_TO_PAY,
            'po_source' => 'INTERNAL',
            'po_number' => $this->po->po_number,
            'local_purchase_order_id' => $this->po->id,
            'due_date' => '2026-10-20',
            'scheduled_payment_date' => '2026-10-25',
            'cashier_received_at' => '2026-09-21 09:30:00',
            'completed_at' => '2026-09-25 15:45:00',
            'submitted_at' => '2026-09-20 08:15:00',
            'payment_term_days_snapshot' => 30,
        ]);

        LocalInvoiceStatusHistory::create([
            'local_invoice_id' => $this->invoice->id,
            'from_status' => LocalInvoice::STATUS_UNDER_VERIFICATION,
            'to_status' => $this->invoice->status,
            'actor_id' => $this->finance->id,
            'event' => 'approved',
            'notes' => 'Financial test approval',
            'created_at' => '2026-09-22 14:00:00',
        ]);
    }

    public function test_invoice_financial_presentation_transcodes_separators_for_international_and_indonesian(): void
    {
        // 1. Indonesian preference: dot grouping, comma decimal
        $this->preference($this->finance, 'indonesian');
        $htmlIndo = $this->actingAs($this->finance)
            ->get(route('finance.invoices.show', $this->invoice))
            ->assertOk()
            ->getContent();

        // 1,250,000.00 with 0 decimals formatted as 1.250.000
        $this->assertStringContainsString('Rp 1.250.000', $htmlIndo);
        $this->assertStringContainsString('Rp 137.500', $htmlIndo);
        $this->assertStringContainsString('Rp 1.387.500', $htmlIndo);

        // 2. International preference: comma grouping, dot decimal
        $this->preference($this->finance, 'international');
        $htmlIntl = $this->actingAs($this->finance)
            ->get(route('finance.invoices.show', $this->invoice))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Rp 1,250,000', $htmlIntl);
        $this->assertStringContainsString('Rp 137,500', $htmlIntl);
        $this->assertStringContainsString('Rp 1,387,500', $htmlIntl);

        // 3. System preference: preserves legacy format
        $this->preference($this->finance, 'system');
        $htmlSys = $this->actingAs($this->finance)
            ->get(route('finance.invoices.show', $this->invoice))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Rp 1.250.000', $htmlSys);
    }

    public function test_authoritative_financial_values_and_calculations_freeze(): void
    {
        $rawBefore = [
            'invoice_amount' => $this->invoice->getRawOriginal('invoice_amount'),
            'tax_amount' => $this->invoice->getRawOriginal('tax_amount'),
            'po_total' => $this->po->getRawOriginal('total_amount'),
        ];

        // Toggle preferences across several combinations
        foreach (['system', 'indonesian', 'international'] as $num) {
            foreach (['system', 'Asia/Jakarta'] as $tz) {
                $this->preference($this->finance, $num, 'dmy', $tz);

                $this->actingAs($this->finance)->get(route('finance.invoices.show', $this->invoice));

                $freshInvoice = $this->invoice->fresh();
                $freshPo = $this->po->fresh();

                $this->assertSame($rawBefore['invoice_amount'], $freshInvoice->getRawOriginal('invoice_amount'));
                $this->assertSame($rawBefore['tax_amount'], $freshInvoice->getRawOriginal('tax_amount'));
                $this->assertSame($rawBefore['po_total'], $freshPo->getRawOriginal('total_amount'));
            }
        }
    }

    public function test_invoice_event_timestamps_honor_regional_preferences(): void
    {
        // 1. With Jakarta + 24h + DMY
        $this->preference($this->finance, 'system', 'dmy', 'Asia/Jakarta', '24h');
        $html = $this->actingAs($this->finance)
            ->get(route('finance.invoices.show', $this->invoice))
            ->assertOk()
            ->getContent();

        // Status history created_at was 2026-09-22 14:00:00 UTC -> in Jakarta (UTC+7) = 21:00 WIB
        // With date_format='dmy', the date pattern transforms to 'd/m/Y' and time to 'H:i' -> '22/09/2026 21:00 WIB'
        $this->assertStringContainsString('22/09/2026 21:00 WIB', $html);

        // 2. With System preference: no timezone shift and no zone label
        $this->preference($this->finance, 'system', 'system', 'system', 'system');
        $htmlSys = $this->actingAs($this->finance)
            ->get(route('finance.invoices.show', $this->invoice))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('22 Sep 14:00', $htmlSys);
        $this->assertStringNotContainsString('22 Sep 14:00 WIB', $htmlSys);
        $this->assertStringNotContainsString('22 Sep 21:00', $htmlSys);
    }

    public function test_machine_protection_whatsapp_and_td_reg_02_preserved(): void
    {
        $batch = PaymentBatch::create([
            'batch_number' => 'DRP-FIN-001',
            'batch_type' => 'SUPPLIER',
            'status' => 'FINALIZED',
            'created_by' => $this->finance->id,
        ]);
        $group = PaymentGroup::create([
            'payment_batch_id' => $batch->id,
            'payee_type' => 'supplier',
            'payee_id' => $this->supplier->id,
            'payee_name' => 'PT Vendor Regional Financial',
            'bank_name' => 'BCA',
            'account_number' => '1234567890',
            'account_holder_name' => 'PT Vendor Regional Financial',
            'subtotal_amount' => '1387500.00',
            'net_payment_amount' => '1387500.00',
        ]);
        $item = PaymentItem::create([
            'payment_group_id' => $group->id,
            'payable_type' => LocalInvoice::class,
            'payable_id' => $this->invoice->id,
            'amount' => '1387500.00',
        ]);
        $voucher = LocalInvoiceVoucher::create([
            'local_invoice_id' => $this->invoice->id,
            'payment_batch_id' => $batch->id,
            'payment_group_id' => $group->id,
            'payment_item_id' => $item->id,
            'voucher_number' => 'VB-FIN-001',
            'voucher_date' => '2026-09-25',
            'payment_method' => 'BANK',
            'supplier_name_snapshot' => 'PT Vendor Regional Financial',
            'bank_name_snapshot' => 'BCA',
            'bank_account_snapshot' => '1234567890',
            'bank_account_holder_snapshot' => 'PT Vendor Regional Financial',
            'invoice_number_snapshot' => $this->invoice->invoice_number,
            'po_number_snapshot' => $this->po->po_number,
            'gr_references_snapshot' => 'GR-FIN-001',
            'dpp_snapshot' => '1250000.00',
            'ppn_snapshot' => '137500.00',
            'pph_snapshot' => '0.00',
            'net_payable_snapshot' => '1387500.00',
            'amount' => '1387500.00',
            'terbilang_snapshot' => 'Satu juta tiga ratus',
            'finalized_by' => $this->finance->id,
            'finalized_at' => '2026-09-25 15:00:00',
        ]);
        $payment = LocalInvoicePayment::create([
            'local_invoice_id' => $this->invoice->id,
            'local_invoice_voucher_id' => $voucher->id,
            'payment_item_id' => $item->id,
            'expected_amount' => '1387500.00',
            'actual_paid_total' => '1437500.00',
            'status' => 'CORRECTION_REQUIRED',
            'created_by' => $this->finance->id,
            'updated_by' => $this->finance->id,
        ]);

        // Add overpayment refund to trigger TD-REG-02 check in shared detail view
        $refund = SupplierOverpaymentRefund::create([
            'local_invoice_id' => $this->invoice->id,
            'local_invoice_payment_id' => $payment->id,
            'supplier_id' => $this->supplier->id,
            'overpayment_amount' => 50000.00,
            'refund_amount' => 50000.00,
            'refund_date' => '2026-09-26',
            'settled_at' => Carbon::parse('2026-09-26 11:00:00'),
            'status' => SupplierOverpaymentRefund::STATUS_SETTLED,
            'refund_reference' => 'REF-001',
            'created_by' => $this->finance->id,
        ]);

        $this->preference($this->supplier, 'international', 'iso', 'Asia/Jakarta');

        $html = $this->actingAs($this->supplier)
            ->get(route('local-supplier.invoices.show', $this->invoice))
            ->assertOk()
            ->getContent();

        // TD-REG-02: refund_date uses format('d M Y')
        $this->assertStringContainsString('26 Sep 2026', $html);

        // Machine protection: Nilai Asli remains exact IDR value
        $this->assertStringContainsString('IDR 1250000.00', $html);
    }

    private function preference(User $user, string $number = 'system', string $date = 'system', string $timezone = 'system', string $time = '24h'): void
    {
        $user->preference()->updateOrCreate([], [
            ...config('user_preferences.defaults'),
            'timezone' => $timezone,
            'date_format' => $date,
            'time_format' => $time,
            'number_format' => $number,
        ]);
        app()->forgetScopedInstances();
    }
}
