<?php

namespace Tests\Feature\Security;

use App\Enums\PurchaseOrderStatus;
use App\Models\ErpDeltaRow;
use App\Models\ExchangeRate;
use App\Models\LocalFinanceAuditLog;
use App\Models\LocalGoodsReceipt;
use App\Models\LocalInvoice;
use App\Models\LocalPurchaseOrder;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierScope;
use App\Models\User;
use App\Support\BusinessTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ErpDeltaReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private User $supplierA;

    private User $supplierB;

    private User $finance;

    private User $purchasing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->finance = User::factory()->create([
            'role' => 'finance',
            'is_active' => true,
            'account_status' => User::ACCOUNT_STATUS_ACTIVE,
        ]);

        $this->purchasing = User::factory()->create([
            'role' => 'purchasing',
            'is_active' => true,
            'account_status' => User::ACCOUNT_STATUS_ACTIVE,
        ]);

        $this->supplierA = $this->createEligibleLocalSupplier('VND-RECON-A');
        $this->supplierB = $this->createEligibleLocalSupplier('VND-RECON-B');
    }

    public function test_apply_aggregates_multiline_items_with_bcmath_and_records_audit_logs(): void
    {
        $po = $this->createLocalPo($this->supplierA, 'LPO-RECON-001', '100.00');

        $this->actingAs($this->supplierA)
            ->postJson(route('local-supplier.erp-delta.sync'), [
                'rows' => [
                    ['doc_no' => 'LPO-RECON-001', 'item_code' => 'MAT-A', 'qty' => '1.5000', 'price' => '100.00', 'status' => 'OPEN'],
                    ['doc_no' => 'LPO-RECON-001', 'item_code' => 'MAT-B', 'qty' => '2.0000', 'price' => '75.50', 'status' => 'OPEN'],
                    ['doc_no' => 'LPO-RECON-001', 'item_code' => 'MAT-C', 'qty' => '3.0000', 'price' => '10.10', 'status' => 'OPEN'],
                ],
            ])
            ->assertOk();

        $projectionResponse = $this->actingAs($this->finance)
            ->getJson(route('finance.local-procurement.erp-delta.reconciliation', ['supplier_id' => $this->supplierA->id]))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');

        $doc = collect($projectionResponse->json('documents'))->firstWhere('doc_no', 'LPO-RECON-001');
        $this->assertNotNull($doc);
        $this->assertSame(3, $doc['line_count']);
        $this->assertSame('331.30', $doc['erp_total_amount']);
        $this->assertSame('amount_diff', $doc['diff_kind']);
        $this->assertTrue($doc['can_apply']);

        $this->actingAs($this->finance)
            ->postJson(route('finance.local-procurement.erp-delta.apply-document'), [
                'supplier_id' => $this->supplierA->id,
                'doc_no' => 'LPO-RECON-001',
                'expected_digest' => $doc['composite_digest'],
                'note' => 'Verified against Infor LN export',
            ])
            ->assertOk()
            ->assertJsonPath('total_amount', '331.30')
            ->assertJsonPath('applied_count', 3);

        $this->assertSame('331.30', (string) $po->fresh()->total_amount);

        $this->assertDatabaseHas('local_finance_audit_logs', [
            'auditable_type' => LocalPurchaseOrder::class,
            'auditable_id' => $po->id,
            'action' => 'po_updated',
            'actor_id' => $this->finance->id,
        ]);

        $this->assertDatabaseHas('local_finance_audit_logs', [
            'auditable_type' => LocalPurchaseOrder::class,
            'auditable_id' => $po->id,
            'action' => 'erp_delta_applied',
            'actor_id' => $this->finance->id,
        ]);

        $appliedRows = ErpDeltaRow::query()
            ->where('supplier_id', $this->supplierA->id)
            ->where('doc_no', 'LPO-RECON-001')
            ->get();

        $this->assertCount(3, $appliedRows);
        foreach ($appliedRows as $row) {
            $this->assertSame(ErpDeltaRow::RECONCILIATION_APPLIED, $row->reconciliation_status);
            $this->assertSame($po->id, $row->matched_local_po_id);
            $this->assertSame($this->finance->id, $row->applied_by);
            $this->assertNotNull($row->applied_at);
        }
    }

    public function test_apply_rejects_reducing_po_below_active_invoiced_ceiling_with_422(): void
    {
        $po = $this->createLocalPo($this->supplierA, 'LPO-CEILING-01', '600.00');

        LocalInvoice::create([
            'supplier_id' => $this->supplierA->id,
            'local_purchase_order_id' => $po->id,
            'submission_number' => 'SUB-CEILING-01',
            'invoice_number' => 'INV-CEILING-01',
            'invoice_date' => BusinessTime::today()->toDateString(),
            'po_number' => $po->po_number,
            'po_source' => 'INTERNAL',
            'currency' => 'IDR',
            'invoice_amount' => '500.00',
            'tax_amount' => '55.00',
            'payment_term_days_snapshot' => 30,
            'status' => LocalInvoice::STATUS_UNDER_VERIFICATION,
            'revision_number' => 1,
            'submitted_at' => now(),
        ]);

        $this->actingAs($this->supplierA)
            ->postJson(route('local-supplier.erp-delta.sync'), [
                'rows' => [[
                    'doc_no' => 'LPO-CEILING-01',
                    'item_code' => 'MAT-1',
                    'qty' => '1.0000',
                    'price' => '400.00',
                    'status' => 'OPEN',
                ]],
            ])
            ->assertOk();

        $proj = $this->actingAs($this->finance)
            ->getJson(route('finance.local-procurement.erp-delta.reconciliation', ['supplier_id' => $this->supplierA->id]))
            ->assertOk();

        $doc = collect($proj->json('documents'))->firstWhere('doc_no', 'LPO-CEILING-01');
        $this->assertSame('blocked', $doc['diff_kind']);
        $this->assertSame('ceiling_low', $doc['block_reason']);
        $this->assertFalse($doc['can_apply']);

        $this->actingAs($this->finance)
            ->postJson(route('finance.local-procurement.erp-delta.apply-document'), [
                'supplier_id' => $this->supplierA->id,
                'doc_no' => 'LPO-CEILING-01',
                'expected_digest' => $doc['composite_digest'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['total_amount']);

        $this->assertSame('600.00', (string) $po->fresh()->total_amount);
    }

    public function test_apply_rejects_cancelling_po_with_reserved_gr_and_ignores_gr_confirmed_status(): void
    {
        $poWithGr = $this->createLocalPo($this->supplierA, 'LPO-GR-RSV-01', '600.00');
        LocalGoodsReceipt::create([
            'local_purchase_order_id' => $poWithGr->id,
            'gr_number' => 'GR-LOCKED-01',
            'gr_date' => BusinessTime::today()->toDateString(),
            'qty' => '10.0000',
            'uom' => 'pcs',
            'status' => LocalGoodsReceipt::STATUS_RESERVED,
            'source' => LocalPurchaseOrder::SOURCE_MANUAL,
            'created_by' => $this->purchasing->id,
        ]);

        $poOpenForGr = $this->createLocalPo($this->supplierA, 'LPO-GR-CONF-02', '600.00');

        $this->actingAs($this->supplierA)
            ->postJson(route('local-supplier.erp-delta.sync'), [
                'rows' => [
                    ['doc_no' => 'LPO-GR-RSV-01', 'item_code' => 'HDR', 'qty' => '1.0000', 'price' => '600.00', 'status' => 'CANCELLED'],
                    ['doc_no' => 'LPO-GR-CONF-02', 'item_code' => 'GR-1|ITEM-9', 'qty' => '5.0000', 'price' => '0.00', 'status' => 'CONFIRMED'],
                ],
            ])
            ->assertOk();

        $proj = $this->actingAs($this->purchasing)
            ->getJson(route('purchasing.local-procurement.erp-delta.reconciliation', ['supplier_id' => $this->supplierA->id]))
            ->assertOk();

        $docs = collect($proj->json('documents'))->keyBy('doc_no');

        $rsvDoc = $docs->get('LPO-GR-RSV-01');
        $this->assertSame('blocked', $rsvDoc['diff_kind']);
        $this->assertSame('reserved_cancel', $rsvDoc['block_reason']);
        $this->assertFalse($rsvDoc['can_apply']);

        $grDoc = $docs->get('LPO-GR-CONF-02');
        $this->assertNull($grDoc['mapped_po_status']);
        $this->assertSame('matched', $grDoc['diff_kind']);
        $this->assertFalse($grDoc['can_apply']);

        $this->actingAs($this->purchasing)
            ->postJson(route('purchasing.local-procurement.erp-delta.apply-document'), [
                'supplier_id' => $this->supplierA->id,
                'doc_no' => 'LPO-GR-RSV-01',
                'expected_digest' => $rsvDoc['composite_digest'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $this->assertSame(LocalPurchaseOrder::STATUS_OPEN, $poWithGr->fresh()->status);
        $this->assertSame(LocalPurchaseOrder::STATUS_OPEN, $poOpenForGr->fresh()->status);
        $this->assertSame('600.00', (string) $poOpenForGr->fresh()->total_amount);
    }

    public function test_apply_rejects_stale_composite_digest_with_409_conflict(): void
    {
        $po = $this->createLocalPo($this->supplierA, 'LPO-TOCTOU-01', '100.00');

        $this->actingAs($this->supplierA)
            ->postJson(route('local-supplier.erp-delta.sync'), [
                'rows' => [[
                    'doc_no' => 'LPO-TOCTOU-01',
                    'item_code' => 'MAT-1',
                    'qty' => '1.0000',
                    'price' => '200.00',
                    'status' => 'OPEN',
                ]],
            ])
            ->assertOk();

        $initial = $this->actingAs($this->finance)
            ->getJson(route('finance.local-procurement.erp-delta.reconciliation', ['supplier_id' => $this->supplierA->id]))
            ->assertOk();
        $staleDigest = collect($initial->json('documents'))->firstWhere('doc_no', 'LPO-TOCTOU-01')['composite_digest'];

        // Concurrent sync updates price to 250.00 before Finance clicks Apply.
        $this->actingAs($this->supplierA)
            ->postJson(route('local-supplier.erp-delta.sync'), [
                'rows' => [[
                    'doc_no' => 'LPO-TOCTOU-01',
                    'item_code' => 'MAT-1',
                    'qty' => '1.0000',
                    'price' => '250.00',
                    'status' => 'OPEN',
                ]],
            ])
            ->assertOk();

        $this->actingAs($this->finance)
            ->postJson(route('finance.local-procurement.erp-delta.apply-document'), [
                'supplier_id' => $this->supplierA->id,
                'doc_no' => 'LPO-TOCTOU-01',
                'expected_digest' => $staleDigest,
            ])
            ->assertStatus(409);

        $this->assertSame('100.00', (string) $po->fresh()->total_amount);
        $this->assertSame(0, LocalFinanceAuditLog::query()->where('auditable_id', $po->id)->count());
    }

    public function test_supplier_cannot_apply_or_ignore_and_resync_resets_only_changed_rows(): void
    {
        $po = $this->createLocalPo($this->supplierA, 'LPO-ROLE-01', '100.00');

        $rate = ExchangeRate::create([
            'currency' => 'IDR',
            'rate_to_idr' => 1,
            'valid_from' => '2026-11-13',
            'created_by' => $this->purchasing->id,
        ]);

        PurchaseOrder::create([
            'supplier_id' => $this->supplierA->id,
            'currency' => 'IDR',
            'exchange_rate_id' => $rate->id,
            'po_number' => 'IMP-PO-900',
            'status' => PurchaseOrderStatus::ACTIVE->value,
            'created_by' => $this->purchasing->id,
        ]);

        $this->actingAs($this->supplierA)
            ->postJson(route('local-supplier.erp-delta.sync'), [
                'rows' => [
                    ['doc_no' => 'LPO-ROLE-01', 'item_code' => 'MAT-1', 'qty' => '1.0000', 'price' => '150.00', 'status' => 'OPEN'],
                    ['doc_no' => 'IMP-PO-900', 'item_code' => 'MAT-IMP', 'qty' => '2.0000', 'price' => '50.00', 'status' => 'ACTIVE'],
                ],
            ])
            ->assertOk();

        // Supplier can read own reconciliation projection as read-only (can_apply=false), not Supplier B's.
        $supplierProj = $this->actingAs($this->supplierA)
            ->getJson(route('local-supplier.erp-delta.reconciliation'))
            ->assertOk()
            ->assertJsonPath('can_apply', false);

        $docs = collect($supplierProj->json('documents'))->keyBy('doc_no');
        $digestLocal = $docs->get('LPO-ROLE-01')['composite_digest'];
        $importDoc = $docs->get('IMP-PO-900');
        $this->assertSame('import_po', $importDoc['portal_domain']);
        $this->assertSame('import_diff', $importDoc['diff_kind']);
        $this->assertFalse($importDoc['can_apply']);

        $this->actingAs($this->supplierA)
            ->getJson(route('local-supplier.erp-delta.reconciliation', ['supplier_id' => $this->supplierB->id]))
            ->assertForbidden();

        // Supplier is forbidden from calling apply or ignore on Finance/Purchasing routes.
        $this->actingAs($this->supplierA)
            ->postJson(route('finance.local-procurement.erp-delta.apply-document'), [
                'supplier_id' => $this->supplierA->id,
                'doc_no' => 'LPO-ROLE-01',
                'expected_digest' => $digestLocal,
            ])
            ->assertForbidden();

        $this->actingAs($this->supplierA)
            ->postJson(route('finance.local-procurement.erp-delta.ignore-document'), [
                'supplier_id' => $this->supplierA->id,
                'doc_no' => 'LPO-ROLE-01',
                'expected_digest' => $digestLocal,
            ])
            ->assertForbidden();

        // Import PO cannot be auto-applied even by Finance.
        $this->actingAs($this->finance)
            ->postJson(route('finance.local-procurement.erp-delta.apply-document'), [
                'supplier_id' => $this->supplierA->id,
                'doc_no' => 'IMP-PO-900',
                'expected_digest' => $importDoc['composite_digest'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['doc_no']);

        // Finance applies LPO-ROLE-01, then unchanged resend keeps 'applied', changed resend resets to 'pending'.
        $this->actingAs($this->finance)
            ->postJson(route('finance.local-procurement.erp-delta.apply-document'), [
                'supplier_id' => $this->supplierA->id,
                'doc_no' => 'LPO-ROLE-01',
                'expected_digest' => $digestLocal,
            ])
            ->assertOk();

        $this->actingAs($this->supplierA)
            ->postJson(route('local-supplier.erp-delta.sync'), [
                'rows' => [
                    ['doc_no' => 'LPO-ROLE-01', 'item_code' => 'MAT-1', 'qty' => '1.0000', 'price' => '150.00', 'status' => 'OPEN'],
                ],
            ])
            ->assertOk();

        $this->assertSame(
            ErpDeltaRow::RECONCILIATION_APPLIED,
            ErpDeltaRow::query()->where('doc_no', 'LPO-ROLE-01')->value('reconciliation_status'),
        );

        $this->actingAs($this->supplierA)
            ->postJson(route('local-supplier.erp-delta.sync'), [
                'rows' => [
                    ['doc_no' => 'LPO-ROLE-01', 'item_code' => 'MAT-1', 'qty' => '1.0000', 'price' => '175.00', 'status' => 'OPEN'],
                ],
            ])
            ->assertOk();

        $resetRow = ErpDeltaRow::query()->where('doc_no', 'LPO-ROLE-01')->firstOrFail();
        $this->assertSame(ErpDeltaRow::RECONCILIATION_PENDING, $resetRow->reconciliation_status);
        $this->assertNull($resetRow->applied_at);
        $this->assertNull($resetRow->applied_by);
        $this->assertNull($resetRow->matched_local_po_id);

        // Finance can ignore a pending document cleanly.
        $reloaded = $this->actingAs($this->finance)
            ->getJson(route('finance.local-procurement.erp-delta.reconciliation', ['supplier_id' => $this->supplierA->id]))
            ->assertOk();
        $newDigest = collect($reloaded->json('documents'))->firstWhere('doc_no', 'LPO-ROLE-01')['composite_digest'];

        $this->actingAs($this->finance)
            ->postJson(route('finance.local-procurement.erp-delta.ignore-document'), [
                'supplier_id' => $this->supplierA->id,
                'doc_no' => 'LPO-ROLE-01',
                'expected_digest' => $newDigest,
                'note' => 'Deferred pending addendum',
            ])
            ->assertOk();

        $ignoredRow = ErpDeltaRow::query()->where('doc_no', 'LPO-ROLE-01')->firstOrFail();
        $this->assertSame(ErpDeltaRow::RECONCILIATION_IGNORED, $ignoredRow->reconciliation_status);
        $this->assertNotNull($ignoredRow->ignored_at);
        $this->assertSame('150.00', (string) $po->fresh()->total_amount);
    }

    private function createEligibleLocalSupplier(string $vendorCode): User
    {
        $user = User::factory()->create([
            'role' => 'supplier',
            'is_active' => true,
            'account_status' => User::ACCOUNT_STATUS_ACTIVE,
        ]);
        SupplierScope::query()->where('supplier_id', $user->id)->delete();
        SupplierScope::query()->create([
            'supplier_id' => $user->id,
            'scope' => 'local',
        ]);
        Supplier::create([
            'user_id' => $user->id,
            'company_name' => 'PT Test '.$vendorCode,
            'vendor_code' => $vendorCode,
            'is_pkp' => true,
            'payment_term_days' => 30,
        ]);

        return $user;
    }

    private function createLocalPo(User $supplier, string $poNumber, string $totalAmount): LocalPurchaseOrder
    {
        return LocalPurchaseOrder::create([
            'supplier_id' => $supplier->id,
            'po_number' => $poNumber,
            'po_date' => BusinessTime::today()->toDateString(),
            'total_amount' => $totalAmount,
            'currency' => 'IDR',
            'status' => LocalPurchaseOrder::STATUS_OPEN,
            'source' => LocalPurchaseOrder::SOURCE_MANUAL,
            'created_by' => $this->finance->id,
        ]);
    }
}
