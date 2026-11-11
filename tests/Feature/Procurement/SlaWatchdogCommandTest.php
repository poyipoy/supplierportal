<?php

namespace Tests\Feature\Procurement;

use App\Enums\PoAcknowledgementStatus;
use App\Models\ExchangeRate;
use App\Models\LocalInvoice;
use App\Models\LocalPurchaseOrder;
use App\Models\Period;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\SlaEscalationLog;
use App\Models\SupplierScope;
use App\Models\User;
use App\Support\BusinessTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SlaWatchdogCommandTest extends TestCase
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

    public function test_sla_watchdog_dispatches_reminders_and_escalations_idempotently(): void
    {
        // 1. Overdue Import PO acknowledgement (60h old -> ESCALATION_1)
        $overduePo = PurchaseOrder::create([
            'supplier_id' => $this->importSupplier->id,
            'currency' => 'USD',
            'exchange_rate_id' => $this->rate->id,
            'po_number' => 'PO/SLA/001',
            'status' => 'active',
            'acknowledgement_status' => PoAcknowledgementStatus::PENDING,
            'created_by' => $this->purchasing->id,
            'estimated_arrival' => BusinessTime::today()->addDays(14)->toDateString(),
        ]);
        DB::table('purchase_orders')
            ->where('id', $overduePo->id)
            ->update(['created_at' => BusinessTime::now()->subHours(60)]);

        // 2. Unquoted PR bidding (6 days old -> REMINDER_1)
        $period = Period::create([
            'name' => 'SLA Test Period',
            'month' => 6,
            'year' => 2026,
            'status' => 'open',
            'created_by' => $this->admin->id,
        ]);
        $pr = PurchaseRequisition::create([
            'period_id' => $period->id,
            'created_by' => $this->purchasing->id,
            'pr_number' => 'REQ/SLA/001',
            'status' => 'bidding',
        ]);
        DB::table('purchase_requisitions')
            ->where('id', $pr->id)
            ->update(['created_at' => BusinessTime::now()->subDays(6)]);
        DB::table('purchase_requisition_suppliers')->insert([
            'pr_id' => $pr->id,
            'supplier_id' => $this->importSupplier->id,
            'invited_at' => BusinessTime::now()->subDays(6),
        ]);

        // 3. QC waiting inspection (60h old -> REMINDER_1)
        $qcPo = PurchaseOrder::create([
            'supplier_id' => $this->importSupplier->id,
            'currency' => 'USD',
            'exchange_rate_id' => $this->rate->id,
            'po_number' => 'PO/SLA/QC-01',
            'status' => 'waiting_qc',
            'acknowledgement_status' => PoAcknowledgementStatus::ACKNOWLEDGED,
            'created_by' => $this->purchasing->id,
            'estimated_arrival' => BusinessTime::today()->toDateString(),
        ]);
        DB::table('purchase_orders')
            ->where('id', $qcPo->id)
            ->update(['updated_at' => BusinessTime::now()->subHours(60)]);

        // 4. Local Invoice waiting verification (5 days since cashier_received_at -> REMINDER_1)
        $localPo = LocalPurchaseOrder::create([
            'po_number' => 'LPO/SLA/001',
            'supplier_id' => $this->localSupplier->id,
            'total_amount' => '10000000.00',
            'currency' => 'IDR',
            'status' => LocalPurchaseOrder::STATUS_OPEN,
            'po_date' => BusinessTime::today()->subDays(10)->toDateString(),
            'source' => LocalPurchaseOrder::SOURCE_MANUAL,
            'created_by' => $this->finance->id,
        ]);
        DB::table('local_purchase_orders')
            ->where('id', $localPo->id)
            ->update(['created_at' => BusinessTime::now()->subHours(120)]);
        $invoice = LocalInvoice::create([
            'submission_number' => 'SUB/SLA/001',
            'supplier_id' => $this->localSupplier->id,
            'local_purchase_order_id' => $localPo->id,
            'po_number' => $localPo->po_number,
            'submitted_at' => BusinessTime::now(),
            'payment_term_days_snapshot' => 30,
            'invoice_number' => 'INV-SLA-001',
            'invoice_date' => BusinessTime::today()->subDays(7)->toDateString(),
            'transaction_type' => 'BARANG',
            'invoice_amount' => '5000000.00',
            'tax_amount' => '550000.00',
            'status' => LocalInvoice::STATUS_UNDER_VERIFICATION,
            'cashier_received_at' => BusinessTime::now()->subDays(4),
            'revision_number' => 1,
        ]);

        // First run should dispatch all SLA reminders/escalations
        $this->artisan('sla:watchdog')
            ->assertExitCode(0);

        $firstRunCount = SlaEscalationLog::query()->count();
        $this->assertGreaterThanOrEqual(4, $firstRunCount);
        $this->assertTrue(SlaEscalationLog::query()->where('rule_key', 'import_po_unacknowledged')->where('stage', SlaEscalationLog::STAGE_REMINDER)->exists());
        $this->assertTrue(SlaEscalationLog::query()->where('rule_key', 'pr_bidding_stale')->where('stage', SlaEscalationLog::STAGE_REMINDER)->exists());
        $this->assertTrue(SlaEscalationLog::query()->where('rule_key', 'qc_waiting_inspection')->where('stage', SlaEscalationLog::STAGE_REMINDER)->exists());
        $this->assertTrue(SlaEscalationLog::query()->where('rule_key', 'local_invoice_verification_pending')->where('stage', SlaEscalationLog::STAGE_REMINDER)->exists());
        $this->assertFalse(SlaEscalationLog::query()->where('rule_key', 'local_po_unacknowledged')->exists());

        // Second run must be 100% idempotent (no duplicate logs created)
        $this->artisan('sla:watchdog')
            ->assertExitCode(0);

        $this->assertSame($firstRunCount, SlaEscalationLog::query()->count());
    }
}
