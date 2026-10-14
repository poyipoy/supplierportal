<?php

namespace Tests\Feature\LocalInvoice;

use App\Models\LocalFinanceAuditLog;
use App\Models\LocalGoodsReceipt;
use App\Models\LocalInvoice;
use App\Models\LocalInvoiceGoodsReceipt;
use App\Models\LocalPurchaseOrder;
use App\Models\SupplierScope;
use App\Models\User;
use App\Services\LocalInvoice\LocalFinanceAuditService;
use App\Services\LocalInvoice\LocalProcurementMasterService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LocalProcurementRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    private User $supplier;

    private LocalPurchaseOrder $po;

    private LocalGoodsReceipt $gr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->finance = User::factory()->create(['role' => 'finance']);
        $this->supplier = User::factory()->create(['role' => 'supplier']);
        SupplierScope::create(['supplier_id' => $this->supplier->id, 'scope' => 'local']);
        $this->po = LocalPurchaseOrder::create([
            'po_number' => 'PO-RECOVERY-001', 'supplier_id' => $this->supplier->id,
            'po_date' => '2026-10-13', 'total_amount' => '1000000.00', 'currency' => 'IDR',
            'status' => 'CANCELLED', 'description' => 'Original PO', 'source' => 'IMPORT',
            'created_by' => $this->finance->id,
        ]);
        $this->gr = $this->po->goodsReceipts()->create([
            'gr_number' => 'GR-RECOVERY-001', 'gr_date' => '2026-10-13',
            'qty' => '12.5000', 'uom' => 'pcs', 'description' => 'Original GR',
            'notes' => 'Original note', 'status' => 'CANCELLED', 'source' => 'IMPORT',
            'created_by' => $this->finance->id,
        ]);
    }

    public static function operators(): array
    {
        return [['finance', 'finance'], ['admin', 'finance'], ['purchasing', 'purchasing']];
    }

    #[DataProvider('operators')]
    public function test_operator_restores_only_status_and_preserves_children_and_business_identity(string $role, string $prefix): void
    {
        $actor = User::factory()->create(['role' => $role]);
        $this->po->refresh();
        $this->gr->refresh();
        $poBefore = $this->po->getAttributes();
        $grBefore = $this->gr->getAttributes();

        $this->actingAs($actor)->postJson(route($prefix.'.local-procurement.restore', $this->po), $this->payload() + [
            'po_number' => 'FORGED', 'supplier_id' => $actor->id, 'total_amount' => '1.00',
        ])->assertRedirect();

        $this->assertSame('OPEN', $this->po->fresh()->status);
        $this->assertSame($this->businessAttributes($poBefore), $this->businessAttributes($this->po->fresh()->getAttributes()));
        $this->assertSame($grBefore, $this->gr->fresh()->getAttributes());
        $audit = LocalFinanceAuditLog::where('action', 'po_restored')->sole();
        $this->assertSame($actor->id, $audit->actor_id);
        $this->assertSame('CANCELLED', $audit->before_values['status']);
        $this->assertSame('OPEN', $audit->after_values['status']);
        $this->assertSame('Wrong portal cancellation', $audit->metadata['reason']);
        $this->assertTrue($audit->metadata['business_document_valid']);

        $this->actingAs($actor)->postJson(route($prefix.'.local-procurement.goods-receipts.restore', $this->gr), $this->payload() + [
            'gr_number' => 'FORGED', 'local_purchase_order_id' => 999999, 'qty' => '1',
        ])->assertRedirect();
        $this->assertSame('AVAILABLE', $this->gr->fresh()->status);
        $this->assertSame($this->businessAttributes($grBefore), $this->businessAttributes($this->gr->fresh()->getAttributes()));
        $audit = LocalFinanceAuditLog::where('action', 'gr_restored')->sole();
        $this->assertSame($actor->id, $audit->actor_id);
        $this->assertSame('CANCELLED', $audit->before_values['status']);
        $this->assertSame('AVAILABLE', $audit->after_values['status']);
    }

    public static function invalidRecovery(): array
    {
        return [
            'empty reason' => [['reason' => '   ', 'business_document_valid' => '1'], 'reason'],
            'long reason' => [['reason' => str_repeat('a', 1001), 'business_document_valid' => '1'], 'reason'],
            'array reason' => [['reason' => ['reason'], 'business_document_valid' => '1'], 'reason'],
            'missing confirmation' => [['reason' => 'Correction'], 'business_document_valid'],
            'false confirmation' => [['reason' => 'Correction', 'business_document_valid' => '0'], 'business_document_valid'],
        ];
    }

    #[DataProvider('invalidRecovery')]
    public function test_recovery_requires_reason_and_valid_business_document_confirmation(array $payload, string $field): void
    {
        $this->actingAs($this->finance)->postJson(route('finance.local-procurement.restore', $this->po), $payload)
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->po->update(['status' => 'OPEN']);
        $this->postJson(route('finance.local-procurement.goods-receipts.restore', $this->gr), $payload)
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame('CANCELLED', $this->gr->fresh()->status);
        $this->assertDatabaseCount('local_finance_audit_logs', 0);
    }

    public function test_service_cannot_bypass_recovery_reason_validation(): void
    {
        $this->expectException(ValidationException::class);
        app(LocalProcurementMasterService::class)->restorePurchaseOrder($this->finance, $this->po, ['reason' => ' ']);
    }

    public function test_gr_can_only_be_restored_after_its_po_is_open(): void
    {
        foreach (['CANCELLED', 'CLOSED'] as $status) {
            $this->po->update(['status' => $status]);
            $this->actingAs($this->finance)->postJson(route('finance.local-procurement.goods-receipts.restore', $this->gr), $this->payload())
                ->assertUnprocessable()->assertJsonValidationErrors('status');
        }
        $this->assertSame('CANCELLED', $this->gr->fresh()->status);
        $this->assertDatabaseCount('local_finance_audit_logs', 0);
    }

    public function test_already_restored_po_and_gr_are_rejected_without_duplicate_audits(): void
    {
        $this->actingAs($this->finance)->postJson(route('finance.local-procurement.restore', $this->po), $this->payload())->assertRedirect();
        $this->postJson(route('finance.local-procurement.restore', $this->po), $this->payload())->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson(route('finance.local-procurement.goods-receipts.restore', $this->gr), $this->payload())->assertRedirect();
        $this->postJson(route('finance.local-procurement.goods-receipts.restore', $this->gr), $this->payload())->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertDatabaseCount('local_finance_audit_logs', 2);
    }

    public static function unavailableSuppliers(): array
    {
        return [['inactive'], ['pending'], ['no_local_scope']];
    }

    #[DataProvider('unavailableSuppliers')]
    public function test_po_and_gr_recovery_reject_supplier_without_current_local_eligibility(string $condition): void
    {
        match ($condition) {
            'inactive' => $this->supplier->update(['is_active' => false]),
            'pending' => $this->supplier->update(['account_status' => User::ACCOUNT_STATUS_PENDING]),
            'no_local_scope' => $this->supplier->supplierScopes()->where('scope', 'local')->delete(),
        };
        $this->actingAs($this->finance)->postJson(route('finance.local-procurement.restore', $this->po), $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors('supplier_id');
        $this->po->update(['status' => 'OPEN']);
        $this->postJson(route('finance.local-procurement.goods-receipts.restore', $this->gr), $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors('supplier_id');
        $this->assertSame('CANCELLED', $this->gr->fresh()->status);
        $this->assertDatabaseCount('local_finance_audit_logs', 0);
    }

    public static function activeLinkStates(): array
    {
        return [['RESERVED'], ['CONSUMED']];
    }

    #[DataProvider('activeLinkStates')]
    public function test_recovery_rejects_active_link_even_if_gr_status_is_inconsistent(string $state): void
    {
        $invoice = $this->invoice('CANCELLED');
        $this->link($invoice, $state);
        $this->actingAs($this->finance)->postJson(route('finance.local-procurement.restore', $this->po), $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->po->update(['status' => 'OPEN']);
        $this->postJson(route('finance.local-procurement.goods-receipts.restore', $this->gr), $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertDatabaseCount('local_finance_audit_logs', 0);
    }

    public function test_recovery_rejects_current_invoice_owner_even_without_active_link(): void
    {
        $invoice = $this->invoice('CANCELLED');
        $this->gr->update(['current_invoice_id' => $invoice->id]);
        $this->actingAs($this->finance)->postJson(route('finance.local-procurement.restore', $this->po), $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->po->update(['status' => 'OPEN']);
        $this->postJson(route('finance.local-procurement.goods-receipts.restore', $this->gr), $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_po_recovery_rejects_active_invoice_dependency_without_gr_link(): void
    {
        $this->invoice('WAITING_PHYSICAL_DOCUMENT');
        $this->actingAs($this->finance)->postJson(route('finance.local-procurement.restore', $this->po), $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame('CANCELLED', $this->po->fresh()->status);
    }

    public static function allocatedGrStatuses(): array
    {
        return [['RESERVED'], ['INVOICED']];
    }

    #[DataProvider('allocatedGrStatuses')]
    public function test_po_recovery_rejects_allocated_child_even_without_invoice_owner(string $status): void
    {
        $this->gr->update(['status' => $status]);
        $this->actingAs($this->finance)->postJson(route('finance.local-procurement.restore', $this->po), $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame('CANCELLED', $this->po->fresh()->status);
        $this->assertSame($status, $this->gr->fresh()->status);
    }

    public function test_recovery_html_validation_uses_the_correct_modal_error_bag(): void
    {
        $this->actingAs($this->finance)->from(route('finance.local-procurement.show', $this->po))
            ->post(route('finance.local-procurement.restore', $this->po), [])
            ->assertSessionHasErrors(['reason', 'business_document_valid'], null, 'restorePo');
        $this->get(route('finance.local-procurement.show', $this->po))->assertOk()
            ->assertSee('data-recovery-reopen="1"', false)->assertSee('restorePoModalReasonError');
        $this->po->update(['status' => 'OPEN']);
        $this->post(route('finance.local-procurement.goods-receipts.restore', $this->gr), [])
            ->assertSessionHasErrors(['reason', 'business_document_valid'], null, 'restoreGr');
        $this->get(route('finance.local-procurement.show', $this->po))->assertOk()
            ->assertSee('data-recovery-reopen="1"', false)->assertSee('restorePoGrModalReasonError');
    }

    public function test_released_history_is_preserved_and_does_not_prevent_recovery(): void
    {
        $invoice = $this->invoice('CANCELLED');
        $link = $this->link($invoice, 'RELEASED');
        $link->refresh();
        $before = $link->getAttributes();
        $this->actingAs($this->finance)->postJson(route('finance.local-procurement.restore', $this->po), $this->payload())->assertRedirect();
        $this->postJson(route('finance.local-procurement.goods-receipts.restore', $this->gr), $this->payload())->assertRedirect();
        $this->assertSame($before, $link->fresh()->getAttributes());
        $this->assertSame('CANCELLED', $invoice->fresh()->status);
    }

    public static function deniedRoles(): array
    {
        return [['supplier'], ['ga'], ['qc'], ['accounting']];
    }

    #[DataProvider('deniedRoles')]
    public function test_roles_without_permission_cannot_recover_po_or_gr(string $role): void
    {
        $actor = User::factory()->create(['role' => $role]);
        $this->actingAs($actor)->postJson(route('finance.local-procurement.restore', $this->po), $this->payload())->assertForbidden();
        $this->postJson(route('purchasing.local-procurement.goods-receipts.restore', $this->gr), $this->payload())->assertForbidden();
        $this->assertSame('CANCELLED', $this->po->fresh()->status);
        $this->assertSame('CANCELLED', $this->gr->fresh()->status);
    }

    public function test_service_rechecks_actor_after_permissions_change(): void
    {
        $stale = $this->finance->fresh();
        $this->finance->update(['role' => 'supplier']);
        $this->expectException(AuthorizationException::class);
        app(LocalProcurementMasterService::class)->restorePurchaseOrder($stale, $this->po, $this->payload());
    }

    public function test_audit_failure_rolls_back_recovery(): void
    {
        $this->mock(LocalFinanceAuditService::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        try {
            app(LocalProcurementMasterService::class)->restorePurchaseOrder($this->finance, $this->po, $this->payload());
            $this->fail('Expected audit failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        $this->assertSame('CANCELLED', $this->po->fresh()->status);
        $this->assertSame('CANCELLED', $this->gr->fresh()->status);
        $this->assertDatabaseCount('local_finance_audit_logs', 0);
    }

    private function payload(): array
    {
        return ['reason' => '  Wrong portal cancellation  ', 'business_document_valid' => '1'];
    }

    private function businessAttributes(array $attributes): array
    {
        return array_diff_key($attributes, array_flip(['status', 'updated_by', 'updated_at']));
    }

    private function invoice(string $status): LocalInvoice
    {
        return LocalInvoice::create([
            'submission_number' => 'SUB-RECOVERY', 'supplier_id' => $this->supplier->id,
            'invoice_number' => 'INV-RECOVERY', 'invoice_date' => '2026-10-13',
            'local_purchase_order_id' => $this->po->id, 'po_number' => $this->po->po_number,
            'currency' => 'IDR', 'invoice_amount' => '100.00', 'tax_amount' => '0.00',
            'payment_term_days_snapshot' => 30, 'revision_number' => 1,
            'status' => $status, 'submitted_at' => now(),
        ]);
    }

    private function link(LocalInvoice $invoice, string $state): LocalInvoiceGoodsReceipt
    {
        return LocalInvoiceGoodsReceipt::create([
            'local_invoice_id' => $invoice->id, 'local_purchase_order_id' => $this->po->id,
            'local_goods_receipt_id' => $this->gr->id, 'state' => $state,
            'gr_number_snapshot' => $this->gr->gr_number, 'gr_qty_snapshot' => $this->gr->qty,
            'gr_uom_snapshot' => $this->gr->uom, 'reserved_at' => now(),
            'released_at' => $state === 'RELEASED' ? now() : null,
        ]);
    }
}
