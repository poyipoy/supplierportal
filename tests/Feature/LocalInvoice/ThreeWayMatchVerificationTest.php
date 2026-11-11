<?php

namespace Tests\Feature\LocalInvoice;

use App\Exceptions\BusinessRuleArgumentException;
use App\Models\LocalGoodsReceipt;
use App\Models\LocalInvoice;
use App\Models\LocalInvoiceVerification;
use App\Models\LocalPurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierScope;
use App\Models\User;
use App\Services\LocalInvoice\InvoiceVerificationService;
use App\Services\LocalInvoice\ThreeWayMatchCalculator;
use App\Support\BusinessTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThreeWayMatchVerificationTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    private User $localSupplier;

    private LocalPurchaseOrder $localPo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->finance = User::factory()->create([
            'role' => 'finance',
            'is_active' => true,
            'account_status' => 'ACTIVE',
        ]);

        $this->localSupplier = User::factory()->create([
            'role' => 'supplier',
            'is_active' => true,
            'account_status' => 'ACTIVE',
        ]);
        SupplierScope::query()->where('supplier_id', $this->localSupplier->id)->delete();
        SupplierScope::query()->create([
            'supplier_id' => $this->localSupplier->id,
            'scope' => 'local',
        ]);

        Supplier::create([
            'user_id' => $this->localSupplier->id,
            'company_name' => 'PT Baja Lokal Presisi',
            'vendor_code' => 'VND-3WM-01',
            'is_pkp' => true,
            'payment_term_days' => 30,
        ]);

        $this->localPo = LocalPurchaseOrder::create([
            'po_number' => 'LPO/3WM/001',
            'supplier_id' => $this->localSupplier->id,
            'total_amount' => '50000000.00',
            'currency' => 'IDR',
            'status' => LocalPurchaseOrder::STATUS_OPEN,
            'po_date' => BusinessTime::today()->toDateString(),
            'source' => LocalPurchaseOrder::SOURCE_MANUAL,
            'created_by' => $this->finance->id,
        ]);
    }

    public function test_calculator_returns_po_ceiling_only_when_grs_have_no_pricing(): void
    {
        $gr1 = LocalGoodsReceipt::create([
            'local_purchase_order_id' => $this->localPo->id,
            'gr_number' => 'GR-LEGACY-01',
            'gr_date' => BusinessTime::today()->toDateString(),
            'qty' => '100.00',
            'uom' => 'KG',
            'status' => LocalGoodsReceipt::STATUS_AVAILABLE,
            'source' => 'IMPORT',
            'created_by' => $this->finance->id,
        ]);

        $calc = app(ThreeWayMatchCalculator::class);
        $summary = $calc->evaluate($this->localPo, collect([$gr1]), '10000000.00');

        $this->assertSame(ThreeWayMatchCalculator::STATUS_PO_CEILING_ONLY, $summary['three_way_match_status']);
        $this->assertNull($summary['expected_gr_dpp_amount']);
        $this->assertNull($summary['gr_dpp_variance_amount']);
        $this->assertFalse($summary['has_line_values']);
    }

    public function test_calculator_detects_exact_match_within_tolerance_and_variance(): void
    {
        $gr1 = LocalGoodsReceipt::create([
            'local_purchase_order_id' => $this->localPo->id,
            'gr_number' => 'GR-VAL-01',
            'gr_date' => BusinessTime::today()->toDateString(),
            'qty' => '10.00',
            'uom' => 'PCS',
            'unit_price' => '250000.00',
            'receipt_amount' => '2500000.00',
            'po_line_reference' => 'LINE-10',
            'status' => LocalGoodsReceipt::STATUS_AVAILABLE,
            'source' => 'MANUAL',
            'created_by' => $this->finance->id,
        ]);

        $gr2 = LocalGoodsReceipt::create([
            'local_purchase_order_id' => $this->localPo->id,
            'gr_number' => 'GR-VAL-02',
            'gr_date' => BusinessTime::today()->toDateString(),
            'qty' => '5.00',
            'uom' => 'PCS',
            'unit_price' => '500000.00',
            // receipt_amount omitted -> should derive 5.00 * 500000.00 = 2500000.00 via BCMath
            'status' => LocalGoodsReceipt::STATUS_AVAILABLE,
            'source' => 'MANUAL',
            'created_by' => $this->finance->id,
        ]);

        $calc = app(ThreeWayMatchCalculator::class);
        $grs = collect([$gr1, $gr2]);

        // Expected GR DPP = 2,500,000 + 2,500,000 = 5,000,000.00
        $exact = $calc->evaluate($this->localPo, $grs, '5000000.00');
        $this->assertSame(ThreeWayMatchCalculator::STATUS_EXACT_MATCH, $exact['three_way_match_status']);
        $this->assertSame('5000000.00', $exact['expected_gr_dpp_amount']);
        $this->assertSame('0.00', $exact['gr_dpp_variance_amount']);

        // Within Rp 100 rounding tolerance (+75.00)
        $tolerance = $calc->evaluate($this->localPo, $grs, '5000075.00');
        $this->assertSame(ThreeWayMatchCalculator::STATUS_WITHIN_TOLERANCE, $tolerance['three_way_match_status']);
        $this->assertSame('75.00', $tolerance['gr_dpp_variance_amount']);

        // Variance exceeding Rp 100 (+150,000.00)
        $variance = $calc->evaluate($this->localPo, $grs, '5150000.00');
        $this->assertSame(ThreeWayMatchCalculator::STATUS_VARIANCE_DETECTED, $variance['three_way_match_status']);
        $this->assertSame('150000.00', $variance['gr_dpp_variance_amount']);
    }

    public function test_finance_section_a_requires_gr_notes_when_variance_detected_and_gr_check_ok(): void
    {
        $gr = LocalGoodsReceipt::create([
            'local_purchase_order_id' => $this->localPo->id,
            'gr_number' => 'GR-VAR-01',
            'gr_date' => BusinessTime::today()->toDateString(),
            'qty' => '10.00',
            'uom' => 'PCS',
            'unit_price' => '1000000.00',
            'receipt_amount' => '10000000.00',
            'status' => LocalGoodsReceipt::STATUS_RESERVED,
            'source' => 'MANUAL',
            'created_by' => $this->finance->id,
        ]);

        $invoice = LocalInvoice::create([
            'submission_number' => 'SUB/3WM/001',
            'supplier_id' => $this->localSupplier->id,
            'local_purchase_order_id' => $this->localPo->id,
            'po_number' => $this->localPo->po_number,
            'submitted_at' => BusinessTime::now(),
            'payment_term_days_snapshot' => 30,
            'invoice_number' => 'INV-3WM-VAR-01',
            'invoice_date' => BusinessTime::today()->toDateString(),
            'transaction_type' => 'BARANG',
            'invoice_amount' => '10500000.00',
            'tax_amount' => '1155000.00',
            'expected_gr_dpp_amount' => '10000000.00',
            'gr_dpp_variance_amount' => '500000.00',
            'three_way_match_status' => ThreeWayMatchCalculator::STATUS_VARIANCE_DETECTED,
            'status' => LocalInvoice::STATUS_UNDER_VERIFICATION,
            'revision_number' => 1,
        ]);

        $invoice->goodsReceiptHistories()->create([
            'local_purchase_order_id' => $this->localPo->id,
            'local_goods_receipt_id' => $gr->id,
            'gr_number_snapshot' => $gr->gr_number,
            'gr_qty_snapshot' => $gr->qty,
            'gr_uom_snapshot' => $gr->uom,
            'gr_amount_snapshot' => $gr->receipt_amount,
            'state' => 'RESERVED',
        ]);

        LocalInvoiceVerification::create([
            'local_invoice_id' => $invoice->id,
            'revision_number' => 1,
        ]);

        $service = app(InvoiceVerificationService::class);

        // Should fail when gr_check is OK on a VARIANCE_DETECTED invoice without gr_notes
        try {
            $service->verifySectionA($invoice, [
                'po_check' => LocalInvoiceVerification::CHECK_OK,
                'gr_check' => LocalInvoiceVerification::CHECK_OK,
                'gr_notes' => '',
                'delivery_note_check' => LocalInvoiceVerification::CHECK_OK,
                'invoice_doc_check' => LocalInvoiceVerification::CHECK_OK,
                'tax_invoice_check' => LocalInvoiceVerification::CHECK_OK,
            ], $this->finance);
            $this->fail('Expected BusinessRuleArgumentException when gr_notes is empty on VARIANCE_DETECTED invoice.');
        } catch (BusinessRuleArgumentException $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        // Providing gr_notes succeeds and records three_way_match_status + variance on verification
        $verification = $service->verifySectionA($invoice, [
            'po_check' => LocalInvoiceVerification::CHECK_OK,
            'gr_check' => LocalInvoiceVerification::CHECK_OK,
            'gr_notes' => 'Approved Rp 500.000 freight surcharge per PO addendum.',
            'delivery_note_check' => LocalInvoiceVerification::CHECK_OK,
            'invoice_doc_check' => LocalInvoiceVerification::CHECK_OK,
            'tax_invoice_check' => LocalInvoiceVerification::CHECK_OK,
        ], $this->finance);

        $this->assertTrue($verification->is_section_a_passed);
        $this->assertSame(ThreeWayMatchCalculator::STATUS_VARIANCE_DETECTED, $verification->three_way_match_status);
        $this->assertSame('500000.00', (string) $verification->three_way_variance_amount);
    }
}
