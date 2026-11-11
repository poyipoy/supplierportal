<?php

namespace Tests\Feature\VendorMaster;

use App\Models\ExchangeRate;
use App\Models\LocalInvoice;
use App\Models\LocalPurchaseOrder;
use App\Models\Period;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\QcInspection;
use App\Models\Quotation;
use App\Models\SupplierAudit;
use App\Models\SupplierAuditAnswer;
use App\Models\SupplierAuditCriterion;
use App\Models\SupplierAuditSection;
use App\Models\SupplierAuditTemplate;
use App\Models\SupplierScope;
use App\Models\User;
use App\Services\VendorMaster\SupplierScorecardService;
use App\Support\BusinessTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupplierScorecardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $purchasing;

    private User $qc;

    private User $finance;

    private User $importSupplier;

    private User $localSupplier;

    private ExchangeRate $rate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'account_status' => 'ACTIVE']);
        $this->purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true, 'account_status' => 'ACTIVE']);
        $this->qc = User::factory()->create(['role' => 'qc', 'is_active' => true, 'account_status' => 'ACTIVE']);
        $this->finance = User::factory()->create(['role' => 'finance', 'is_active' => true, 'account_status' => 'ACTIVE']);

        $this->importSupplier = User::factory()->create(['role' => 'supplier', 'is_active' => true, 'account_status' => 'ACTIVE']);
        SupplierScope::query()->updateOrCreate(['supplier_id' => $this->importSupplier->id, 'scope' => 'import']);

        $this->localSupplier = User::factory()->create(['role' => 'supplier', 'is_active' => true, 'account_status' => 'ACTIVE']);
        SupplierScope::query()->where('supplier_id', $this->localSupplier->id)->delete();
        SupplierScope::query()->create(['supplier_id' => $this->localSupplier->id, 'scope' => 'local']);

        $this->rate = ExchangeRate::create([
            'currency' => 'USD',
            'rate_to_idr' => 16000,
            'valid_from' => BusinessTime::today()->toDateString(),
            'created_by' => $this->admin->id,
        ]);
    }

    public function test_calculates_import_supplier_scorecard_metrics_and_grade(): void
    {
        // PO 1: On-time arrival + QC OK
        $po1 = PurchaseOrder::create([
            'supplier_id' => $this->importSupplier->id,
            'currency' => 'USD',
            'exchange_rate_id' => $this->rate->id,
            'po_number' => 'PO/SC/001',
            'status' => 'completed',
            'created_by' => $this->purchasing->id,
            'estimated_arrival' => BusinessTime::today()->subDays(10)->toDateString(),
            'actual_arrival' => BusinessTime::today()->subDays(12)->toDateString(),
        ]);

        QcInspection::create([
            'po_id' => $po1->id,
            'inspected_by' => $this->qc->id,
            'status' => 'ok',
            'inspected_at' => BusinessTime::now()->subDays(9),
        ]);

        // PO 2: Late arrival + QC OK
        $po2 = PurchaseOrder::create([
            'supplier_id' => $this->importSupplier->id,
            'currency' => 'USD',
            'exchange_rate_id' => $this->rate->id,
            'po_number' => 'PO/SC/002',
            'status' => 'completed',
            'created_by' => $this->purchasing->id,
            'estimated_arrival' => BusinessTime::today()->subDays(15)->toDateString(),
            'actual_arrival' => BusinessTime::today()->subDays(5)->toDateString(),
        ]);

        QcInspection::create([
            'po_id' => $po2->id,
            'inspected_by' => $this->qc->id,
            'status' => 'ok',
            'inspected_at' => BusinessTime::now()->subDays(4),
        ]);

        // PR Invitation & Quotation response (1/1 = 100%)
        $period = Period::create([
            'name' => 'Scorecard Period',
            'month' => 7,
            'year' => 2026,
            'status' => 'open',
            'created_by' => $this->admin->id,
        ]);
        $pr = PurchaseRequisition::create([
            'period_id' => $period->id,
            'created_by' => $this->purchasing->id,
            'pr_number' => 'REQ/SC/001',
            'status' => 'completed',
        ]);
        DB::table('purchase_requisition_suppliers')->insert([
            'pr_id' => $pr->id,
            'supplier_id' => $this->importSupplier->id,
            'invited_at' => BusinessTime::now()->subDays(20),
        ]);
        Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $this->importSupplier->id,
            'exchange_rate_id' => $this->rate->id,
            'currency' => 'USD',
            'status' => 'accepted',
            'submitted_at' => BusinessTime::now()->subDays(18),
        ]);

        $scorecard = app(SupplierScorecardService::class)->forSupplier($this->importSupplier->fresh());

        $this->assertSame('import', $scorecard->scope);
        $this->assertSame('50.00', $scorecard->metrics['otd']['percentage']);
        $this->assertSame('100.00', $scorecard->metrics['quality']['percentage']);
        // 1 PR quoted + 0/2 PO acknowledged = 1/3 = 33.33%
        $this->assertSame('33.33', $scorecard->metrics['responsiveness']['percentage']);
        // Average of (50.00 + 100.00 + 33.33) / 3 = 61.11 -> Grade C
        $this->assertSame('61.11', $scorecard->compositeScore);
        $this->assertSame('C', $scorecard->grade);
    }

    public function test_calculates_local_supplier_scorecard_with_wednesday_punctuality_ftr_and_audit(): void
    {
        $localPo = LocalPurchaseOrder::create([
            'po_number' => 'LPO/SC/001',
            'supplier_id' => $this->localSupplier->id,
            'total_amount' => '25000000.00',
            'currency' => 'IDR',
            'status' => LocalPurchaseOrder::STATUS_OPEN,
            'po_date' => BusinessTime::today()->subDays(20)->toDateString(),
            'source' => LocalPurchaseOrder::SOURCE_MANUAL,
            'created_by' => $this->finance->id,
        ]);

        // Invoice 1: On-time physical delivery + First-time-right (revision 1, READY_TO_PAY)
        LocalInvoice::create([
            'submission_number' => 'SUB/SC/001',
            'supplier_id' => $this->localSupplier->id,
            'local_purchase_order_id' => $localPo->id,
            'po_number' => $localPo->po_number,
            'submitted_at' => BusinessTime::now(),
            'payment_term_days_snapshot' => 30,
            'invoice_number' => 'INV-SC-001',
            'invoice_date' => BusinessTime::today()->subDays(14)->toDateString(),
            'transaction_type' => 'BARANG',
            'invoice_amount' => '10000000.00',
            'tax_amount' => '1100000.00',
            'scheduled_physical_delivery_date' => BusinessTime::today()->subDays(10)->toDateString(),
            'cashier_received_at' => BusinessTime::today()->subDays(10)->setTime(11, 0),
            'status' => LocalInvoice::STATUS_READY_TO_PAY,
            'revision_number' => 1,
        ]);

        // Invoice 2: On-time physical delivery + Revision 2 (not first-time-right)
        LocalInvoice::create([
            'submission_number' => 'SUB/SC/002',
            'supplier_id' => $this->localSupplier->id,
            'local_purchase_order_id' => $localPo->id,
            'po_number' => $localPo->po_number,
            'submitted_at' => BusinessTime::now(),
            'payment_term_days_snapshot' => 30,
            'invoice_number' => 'INV-SC-002',
            'invoice_date' => BusinessTime::today()->subDays(12)->toDateString(),
            'transaction_type' => 'BARANG',
            'invoice_amount' => '8000000.00',
            'tax_amount' => '880000.00',
            'scheduled_physical_delivery_date' => BusinessTime::today()->subDays(7)->toDateString(),
            'cashier_received_at' => BusinessTime::today()->subDays(7)->setTime(14, 0),
            'status' => LocalInvoice::STATUS_READY_TO_PAY,
            'revision_number' => 2,
        ]);

        // Published Supplier Audit (2 criteria: 5 + 5 = 10/10 = 100%)
        $template = SupplierAuditTemplate::create([
            'code' => 'TPL-SC-01',
            'title' => 'Audit Template SC',
            'version' => 1,
            'is_active' => true,
        ]);
        $section = SupplierAuditSection::create([
            'supplier_audit_template_id' => $template->id,
            'sheet_number' => 1,
            'code' => 'SEC-1',
            'title' => 'Quality System',
            'level' => 1,
            'sort_order' => 1,
        ]);
        $criterion1 = SupplierAuditCriterion::create([
            'supplier_audit_section_id' => $section->id,
            'number' => 1,
            'text' => 'ISO Certificate valid',
            'sort_order' => 1,
        ]);
        $criterion2 = SupplierAuditCriterion::create([
            'supplier_audit_section_id' => $section->id,
            'number' => 2,
            'text' => 'Calibration records complete',
            'sort_order' => 2,
        ]);

        $audit = SupplierAudit::create([
            'supplier_id' => $this->localSupplier->id,
            'supplier_audit_template_id' => $template->id,
            'period_label' => '2026',
            'due_date' => BusinessTime::today()->addDays(10)->toDateString(),
            'status' => SupplierAudit::STATUS_RESULT_PUBLISHED,
            'assigned_by' => $this->purchasing->id,
            'assigned_at' => BusinessTime::now()->subDays(30),
        ]);

        SupplierAuditAnswer::create([
            'supplier_audit_id' => $audit->id,
            'supplier_audit_criterion_id' => $criterion1->id,
            'section_code_snapshot' => 'SEC-1',
            'section_title_snapshot' => 'Quality System',
            'sheet_number_snapshot' => 1,
            'criterion_number_snapshot' => 1,
            'sort_order_snapshot' => 1,
            'criterion_text_snapshot' => 'ISO Certificate valid',
            'answer' => SupplierAuditAnswer::ANSWER_YES,
            'score' => 5,
        ]);
        SupplierAuditAnswer::create([
            'supplier_audit_id' => $audit->id,
            'supplier_audit_criterion_id' => $criterion2->id,
            'section_code_snapshot' => 'SEC-1',
            'section_title_snapshot' => 'Quality System',
            'sheet_number_snapshot' => 1,
            'criterion_number_snapshot' => 2,
            'sort_order_snapshot' => 2,
            'criterion_text_snapshot' => 'Calibration records complete',
            'answer' => SupplierAuditAnswer::ANSWER_YES,
            'score' => 5,
        ]);

        $scorecard = app(SupplierScorecardService::class)->forSupplier($this->localSupplier->fresh());

        $this->assertSame('local', $scorecard->scope);
        $this->assertSame('100.00', $scorecard->metrics['wednesday_punctuality']['percentage']);
        $this->assertSame('50.00', $scorecard->metrics['invoice_ftr']['percentage']);
        $this->assertSame('100.00', $scorecard->metrics['audit_score']['percentage']);
        // Average of (100.00 + 50.00 + 100.00) / 3 = 83.33 -> Grade B
        $this->assertSame('83.33', $scorecard->compositeScore);
        $this->assertSame('B', $scorecard->grade);
    }
}
