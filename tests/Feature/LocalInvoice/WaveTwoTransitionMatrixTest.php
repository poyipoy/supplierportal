<?php

namespace Tests\Feature\LocalInvoice;

use App\Enums\GaClaimStatus;
use App\Enums\LocalGoodsReceiptStatus;
use App\Enums\LocalInvoicePaymentStatus;
use App\Enums\LocalPurchaseOrderStatus;
use App\Enums\PaymentBatchStatus;
use App\Enums\PaymentGroupStatus;
use App\Events\GaClaimStatusTransitioned;
use App\Events\LocalGoodsReceiptStatusTransitioned;
use App\Events\LocalInvoicePaymentStatusTransitioned;
use App\Events\LocalPurchaseOrderStatusTransitioned;
use App\Events\PaymentBatchStatusTransitioned;
use App\Events\PaymentGroupStatusTransitioned;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\Employee;
use App\Models\GaClaim;
use App\Models\LocalGoodsReceipt;
use App\Models\LocalInvoice;
use App\Models\LocalInvoicePayment;
use App\Models\LocalInvoiceVoucher;
use App\Models\LocalPurchaseOrder;
use App\Models\PaymentBatch;
use App\Models\PaymentGroup;
use App\Models\PaymentItem;
use App\Models\Supplier;
use App\Models\SupplierScope;
use App\Models\User;
use App\Services\Ga\GaClaimStateEngine;
use App\Services\LocalInvoice\LocalGoodsReceiptStateEngine;
use App\Services\LocalInvoice\LocalPurchaseOrderStateEngine;
use App\Services\Payment\LocalInvoicePaymentStateEngine;
use App\Services\Payment\PaymentBatchStateEngine;
use App\Services\Payment\PaymentGroupStateEngine;
use App\Support\StatusHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WaveTwoTransitionMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected User $supplier;

    protected User $actor;

    protected LocalPurchaseOrder $po;

    protected Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supplier = User::factory()->create([
            'role' => 'supplier',
            'is_active' => true,
            'account_status' => User::ACCOUNT_STATUS_ACTIVE,
        ]);

        Supplier::create([
            'user_id' => $this->supplier->id,
            'company_name' => 'PT Wave Two Matrix Supplier',
            'country' => 'Indonesia',
            'city' => 'Bekasi',
            'npwp' => '01.234.567.8-888.000',
            'is_pkp' => true,
            'vendor_category' => 'Barang',
            'payment_term_days' => 30,
        ]);

        SupplierScope::create([
            'supplier_id' => $this->supplier->id,
            'scope' => 'local',
        ]);

        $this->actor = User::factory()->create([
            'role' => 'finance',
            'is_active' => true,
        ]);

        $this->po = LocalPurchaseOrder::create([
            'supplier_id' => $this->supplier->id,
            'po_number' => 'PO-W2-MAT-001',
            'po_date' => '2026-09-01',
            'total_amount' => '10000000.00',
            'currency' => 'IDR',
            'status' => LocalPurchaseOrder::STATUS_OPEN,
            'source' => LocalPurchaseOrder::SOURCE_MANUAL,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ]);

        $this->employee = Employee::create([
            'name' => 'Matrix Employee',
            'department' => 'GA',
            'bank_name' => 'BCA',
            'account_number' => '9998887776',
            'account_holder_name' => 'Matrix Employee',
            'is_active' => true,
        ]);
    }

    /**
     * @return array<string, array{0: LocalGoodsReceiptStatus, 1: LocalGoodsReceiptStatus, 2: bool}>
     */
    public static function provideGoodsReceiptMatrix(): array
    {
        $matrix = [];
        foreach (LocalGoodsReceiptStatus::cases() as $source) {
            foreach (LocalGoodsReceiptStatus::cases() as $target) {
                $key = sprintf('GR %s -> %s', $source->value, $target->value);
                $matrix[$key] = [$source, $target, $source->canTransitionTo($target)];
            }
        }

        return $matrix;
    }

    #[DataProvider('provideGoodsReceiptMatrix')]
    public function test_local_goods_receipt_transition_matrix_cell(
        LocalGoodsReceiptStatus $source,
        LocalGoodsReceiptStatus $target,
        bool $shouldSucceed
    ): void {
        Event::fake([LocalGoodsReceiptStatusTransitioned::class]);

        $gr = LocalGoodsReceipt::create([
            'local_purchase_order_id' => $this->po->id,
            'gr_number' => 'GR-MAT-'.uniqid(),
            'gr_date' => '2026-09-02',
            'qty' => '5.000',
            'uom' => 'pcs',
            'status' => $source->value,
            'source' => LocalPurchaseOrder::SOURCE_MANUAL,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ]);

        $engine = app(LocalGoodsReceiptStateEngine::class);

        if ($shouldSucceed) {
            $updated = $engine->transition(
                model: $gr,
                target: $target,
                actor: $this->actor,
                reason: "Transition {$source->value} -> {$target->value}",
                metadata: [
                    'audit_action' => 'gr_matrix_transition',
                ]
            );

            $this->assertSame($target->value, $updated->status);
            $this->assertSame($target, $updated->statusEnum());
            Event::assertDispatched(LocalGoodsReceiptStatusTransitioned::class, function (LocalGoodsReceiptStatusTransitioned $e) use ($gr, $source, $target): bool {
                return $e->goodsReceipt->id === $gr->id
                    && $e->fromStatus === $source
                    && $e->toStatus === $target;
            });
        } else {
            try {
                $engine->transition($gr, $target, $this->actor);
                $this->fail("Expected InvalidStateTransitionException for {$source->value} -> {$target->value}");
            } catch (InvalidStateTransitionException $e) {
                $this->assertInstanceOf(BusinessRuleException::class, $e);
                $this->assertSame($source->value, $e->fromStatus);
                $this->assertSame($target->value, $e->toStatus);
            }

            $this->assertSame($source->value, $gr->fresh()->status);
            Event::assertNotDispatched(LocalGoodsReceiptStatusTransitioned::class);
        }
    }

    /**
     * @return array<string, array{0: PaymentBatchStatus, 1: PaymentBatchStatus, 2: bool}>
     */
    public static function providePaymentBatchMatrix(): array
    {
        $matrix = [];
        foreach (PaymentBatchStatus::cases() as $source) {
            foreach (PaymentBatchStatus::cases() as $target) {
                $key = sprintf('Batch %s -> %s', $source->value, $target->value);
                $matrix[$key] = [$source, $target, $source->canTransitionTo($target)];
            }
        }

        return $matrix;
    }

    #[DataProvider('providePaymentBatchMatrix')]
    public function test_payment_batch_transition_matrix_cell(
        PaymentBatchStatus $source,
        PaymentBatchStatus $target,
        bool $shouldSucceed
    ): void {
        Event::fake([PaymentBatchStatusTransitioned::class]);

        $batch = PaymentBatch::create([
            'batch_number' => 'DRP-MAT-'.uniqid(),
            'batch_type' => PaymentBatch::TYPE_SUPPLIER,
            'status' => $source->value,
            'created_by' => $this->actor->id,
        ]);

        $engine = app(PaymentBatchStateEngine::class);

        if ($shouldSucceed) {
            $updated = $engine->transition(
                model: $batch,
                target: $target,
                actor: $this->actor,
                reason: "Transition {$source->value} -> {$target->value}"
            );

            $this->assertSame($target->value, $updated->status);
            $this->assertSame($target, $updated->statusEnum());
            Event::assertDispatched(PaymentBatchStatusTransitioned::class, function (PaymentBatchStatusTransitioned $e) use ($batch, $source, $target): bool {
                return $e->batch->id === $batch->id
                    && $e->fromStatus === $source
                    && $e->toStatus === $target;
            });
        } else {
            try {
                $engine->transition($batch, $target, $this->actor);
                $this->fail("Expected InvalidStateTransitionException for {$source->value} -> {$target->value}");
            } catch (InvalidStateTransitionException $e) {
                $this->assertInstanceOf(BusinessRuleException::class, $e);
                $this->assertSame($source->value, $e->fromStatus);
                $this->assertSame($target->value, $e->toStatus);
            }

            $this->assertSame($source->value, $batch->fresh()->status);
            Event::assertNotDispatched(PaymentBatchStatusTransitioned::class);
        }
    }

    /**
     * @return array<string, array{0: GaClaimStatus, 1: GaClaimStatus, 2: bool}>
     */
    public static function provideGaClaimMatrix(): array
    {
        $matrix = [];
        foreach (GaClaimStatus::cases() as $source) {
            foreach (GaClaimStatus::cases() as $target) {
                $key = sprintf('GA %s -> %s', $source->value, $target->value);
                $matrix[$key] = [$source, $target, $source->canTransitionTo($target)];
            }
        }

        return $matrix;
    }

    #[DataProvider('provideGaClaimMatrix')]
    public function test_ga_claim_transition_matrix_cell(
        GaClaimStatus $source,
        GaClaimStatus $target,
        bool $shouldSucceed
    ): void {
        Event::fake([GaClaimStatusTransitioned::class]);

        $claim = GaClaim::create([
            'claim_number' => 'CLM-MAT-'.uniqid(),
            'employee_id' => $this->employee->id,
            'claim_type' => GaClaim::TYPE_BUSINESS_TRAVEL,
            'claim_date' => '2026-09-10',
            'amount' => '250000.00',
            'status' => $source->value,
            'revision_number' => 1,
            'submitted_by' => $this->actor->id,
            'submitted_at' => now(),
        ]);

        $engine = app(GaClaimStateEngine::class);

        if ($shouldSucceed) {
            $updated = $engine->transition(
                model: $claim,
                target: $target,
                actor: $this->actor,
                reason: "Transition {$source->value} -> {$target->value}",
                metadata: ['event' => 'matrix_transition']
            );

            $this->assertSame($target->value, $updated->status);
            $this->assertSame($target, $updated->statusEnum());
            $this->assertDatabaseHas('ga_claim_status_histories', [
                'ga_claim_id' => $claim->id,
                'from_status' => $source->value,
                'to_status' => $target->value,
                'actor_id' => $this->actor->id,
                'event' => 'matrix_transition',
            ]);
            Event::assertDispatched(GaClaimStatusTransitioned::class);
        } else {
            try {
                $engine->transition($claim, $target, $this->actor);
                $this->fail("Expected InvalidStateTransitionException for {$source->value} -> {$target->value}");
            } catch (InvalidStateTransitionException $e) {
                $this->assertInstanceOf(BusinessRuleException::class, $e);
                $this->assertSame($source->value, $e->fromStatus);
                $this->assertSame($target->value, $e->toStatus);
            }

            $this->assertSame($source->value, $claim->fresh()->status);
            $this->assertDatabaseMissing('ga_claim_status_histories', [
                'ga_claim_id' => $claim->id,
                'to_status' => $target->value,
            ]);
            Event::assertNotDispatched(GaClaimStatusTransitioned::class);
        }
    }

    public function test_wave_two_legacy_synonyms_and_ui_metadata(): void
    {
        // LocalGoodsReceiptStatus synonyms
        $this->assertSame(LocalGoodsReceiptStatus::AVAILABLE, LocalGoodsReceiptStatus::tryFromWithLegacy('RELEASED'));
        $this->assertSame(LocalGoodsReceiptStatus::INVOICED, LocalGoodsReceiptStatus::tryFromWithLegacy('CONSUMED'));
        $this->assertSame('success', StatusHelper::localFinanceTone(LocalGoodsReceiptStatus::AVAILABLE));
        $this->assertNotEmpty(StatusHelper::localFinanceLabel(LocalGoodsReceiptStatus::AVAILABLE));

        // PaymentBatchStatus synonyms
        $this->assertSame(PaymentBatchStatus::FINALIZED, PaymentBatchStatus::tryFromWithLegacy('CONFIRMED'));
        $this->assertSame(PaymentBatchStatus::PAID, PaymentBatchStatus::tryFromWithLegacy('COMPLETED'));
        $this->assertSame('info', StatusHelper::paymentBatchTone(PaymentBatchStatus::FINALIZED));
        $this->assertNotEmpty(StatusHelper::localFinanceLabel(PaymentBatchStatus::FINALIZED));

        // GaClaimStatus synonyms
        $this->assertSame(GaClaimStatus::UNDER_VERIFICATION, GaClaimStatus::tryFromWithLegacy('UNDER_REVIEW'));
        $this->assertSame(GaClaimStatus::READY_TO_PAY, GaClaimStatus::tryFromWithLegacy('APPROVED'));
        $this->assertSame(GaClaimStatus::PAID, GaClaimStatus::tryFromWithLegacy('COMPLETED'));
        $this->assertSame(GaClaimStatus::CANCELLED, GaClaimStatus::tryFromWithLegacy('REJECTED'));
        $this->assertSame('success', StatusHelper::gaClaimTone(GaClaimStatus::READY_TO_PAY));
        $this->assertNotEmpty(StatusHelper::gaClaimLabel(GaClaimStatus::READY_TO_PAY));

        // Wave 2B synonyms & UI metadata
        $this->assertSame(LocalPurchaseOrderStatus::OPEN, LocalPurchaseOrderStatus::tryFromWithLegacy('APPROVED'));
        $this->assertSame('success', StatusHelper::localFinanceTone(LocalPurchaseOrderStatus::OPEN));
        $this->assertNotEmpty(StatusHelper::localFinanceLabel(LocalPurchaseOrderStatus::OPEN));

        $this->assertSame(PaymentGroupStatus::UNPAID, PaymentGroupStatus::tryFromWithLegacy('PENDING'));
        $this->assertSame(PaymentGroupStatus::PAID, PaymentGroupStatus::tryFromWithLegacy('COMPLETED'));
        $this->assertSame('warning', StatusHelper::localFinanceTone(PaymentGroupStatus::UNPAID));
        $this->assertNotEmpty(StatusHelper::localFinanceLabel(PaymentGroupStatus::UNPAID));

        $this->assertSame(LocalInvoicePaymentStatus::FINALIZED, LocalInvoicePaymentStatus::tryFromWithLegacy('SETTLED'));
        $this->assertSame(LocalInvoicePaymentStatus::FINALIZED, LocalInvoicePaymentStatus::tryFromWithLegacy('PAID'));
        $this->assertSame('warning', StatusHelper::localFinanceTone(LocalInvoicePaymentStatus::CORRECTION_REQUIRED));
        $this->assertNotEmpty(StatusHelper::localFinanceLabel(LocalInvoicePaymentStatus::CORRECTION_REQUIRED));
    }

    /**
     * @return array<string, array{0: LocalPurchaseOrderStatus, 1: LocalPurchaseOrderStatus, 2: bool}>
     */
    public static function provideLocalPurchaseOrderMatrix(): array
    {
        $matrix = [];
        foreach (LocalPurchaseOrderStatus::cases() as $source) {
            foreach (LocalPurchaseOrderStatus::cases() as $target) {
                $key = sprintf('LPO %s -> %s', $source->value, $target->value);
                $matrix[$key] = [$source, $target, $source->canTransitionTo($target)];
            }
        }

        return $matrix;
    }

    #[DataProvider('provideLocalPurchaseOrderMatrix')]
    public function test_local_purchase_order_transition_matrix_cell(
        LocalPurchaseOrderStatus $source,
        LocalPurchaseOrderStatus $target,
        bool $shouldSucceed
    ): void {
        Event::fake([LocalPurchaseOrderStatusTransitioned::class]);

        $po = LocalPurchaseOrder::create([
            'supplier_id' => $this->supplier->id,
            'po_number' => 'PO-W2B-'.uniqid(),
            'po_date' => '2026-09-01',
            'total_amount' => '5000000.00',
            'currency' => 'IDR',
            'status' => $source->value,
            'source' => LocalPurchaseOrder::SOURCE_MANUAL,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ]);

        $engine = app(LocalPurchaseOrderStateEngine::class);

        if ($shouldSucceed) {
            $updated = $engine->transition($po, $target, $this->actor, "Transition {$source->value} -> {$target->value}");
            $this->assertSame($target->value, $updated->status);
            $this->assertSame($target, $updated->statusEnum());
            Event::assertDispatched(LocalPurchaseOrderStatusTransitioned::class);
        } else {
            try {
                $engine->transition($po, $target, $this->actor);
                $this->fail("Expected InvalidStateTransitionException for {$source->value} -> {$target->value}");
            } catch (InvalidStateTransitionException $e) {
                $this->assertInstanceOf(BusinessRuleException::class, $e);
                $this->assertSame($source->value, $e->fromStatus);
                $this->assertSame($target->value, $e->toStatus);
            }
            $this->assertSame($source->value, $po->fresh()->status);
            Event::assertNotDispatched(LocalPurchaseOrderStatusTransitioned::class);
        }
    }

    /**
     * @return array<string, array{0: PaymentGroupStatus, 1: PaymentGroupStatus, 2: bool}>
     */
    public static function providePaymentGroupMatrix(): array
    {
        $matrix = [];
        foreach (PaymentGroupStatus::cases() as $source) {
            foreach (PaymentGroupStatus::cases() as $target) {
                $key = sprintf('Group %s -> %s', $source->value, $target->value);
                $matrix[$key] = [$source, $target, $source->canTransitionTo($target)];
            }
        }

        return $matrix;
    }

    #[DataProvider('providePaymentGroupMatrix')]
    public function test_payment_group_transition_matrix_cell(
        PaymentGroupStatus $source,
        PaymentGroupStatus $target,
        bool $shouldSucceed
    ): void {
        Event::fake([PaymentGroupStatusTransitioned::class]);

        $batch = PaymentBatch::create([
            'batch_number' => 'DRP-GRP-'.uniqid(),
            'batch_type' => PaymentBatch::TYPE_SUPPLIER,
            'status' => PaymentBatch::STATUS_DRAFT,
            'created_by' => $this->actor->id,
        ]);

        $group = $batch->groups()->create([
            'payee_type' => 'supplier',
            'payee_id' => $this->supplier->id,
            'payee_name' => 'PT Wave Two Matrix Supplier',
            'bank_name' => 'BCA',
            'account_number' => '1234567890',
            'account_holder_name' => 'PT Wave Two Matrix Supplier',
            'subtotal_amount' => '1000000.00',
            'bank_fee' => '0.00',
            'net_payment_amount' => '1000000.00',
            'status' => $source->value,
        ]);

        $engine = app(PaymentGroupStateEngine::class);

        if ($shouldSucceed) {
            $updated = $engine->transition($group, $target, $this->actor, "Transition {$source->value} -> {$target->value}");
            $this->assertSame($target->value, $updated->status);
            $this->assertSame($target, $updated->statusEnum());
            Event::assertDispatched(PaymentGroupStatusTransitioned::class);
        } else {
            try {
                $engine->transition($group, $target, $this->actor);
                $this->fail("Expected InvalidStateTransitionException for {$source->value} -> {$target->value}");
            } catch (InvalidStateTransitionException $e) {
                $this->assertInstanceOf(BusinessRuleException::class, $e);
                $this->assertSame($source->value, $e->fromStatus);
                $this->assertSame($target->value, $e->toStatus);
            }
            $this->assertSame($source->value, $group->fresh()->status);
            Event::assertNotDispatched(PaymentGroupStatusTransitioned::class);
        }
    }

    /**
     * @return array<string, array{0: LocalInvoicePaymentStatus, 1: LocalInvoicePaymentStatus, 2: bool}>
     */
    public static function provideLocalInvoicePaymentMatrix(): array
    {
        $matrix = [];
        foreach (LocalInvoicePaymentStatus::cases() as $source) {
            foreach (LocalInvoicePaymentStatus::cases() as $target) {
                $key = sprintf('Payment %s -> %s', $source->value, $target->value);
                $matrix[$key] = [$source, $target, $source->canTransitionTo($target)];
            }
        }

        return $matrix;
    }

    #[DataProvider('provideLocalInvoicePaymentMatrix')]
    public function test_local_invoice_payment_transition_matrix_cell(
        LocalInvoicePaymentStatus $source,
        LocalInvoicePaymentStatus $target,
        bool $shouldSucceed
    ): void {
        Event::fake([LocalInvoicePaymentStatusTransitioned::class]);

        $invoice = LocalInvoice::create([
            'submission_number' => 'SUB-PAY-'.uniqid(),
            'supplier_id' => $this->supplier->id,
            'local_purchase_order_id' => $this->po->id,
            'invoice_number' => 'INV-PAY-'.uniqid(),
            'po_number' => $this->po->po_number,
            'invoice_date' => '2026-09-05',
            'due_date' => '2026-10-05',
            'invoice_amount' => '1000000.00',
            'tax_amount' => '110000.00',
            'currency' => 'IDR',
            'status' => LocalInvoice::STATUS_READY_TO_PAY,
            'submitted_at' => now(),
        ]);

        $batch = PaymentBatch::create([
            'batch_number' => 'DRP-PAY-'.uniqid(),
            'batch_type' => PaymentBatch::TYPE_SUPPLIER,
            'status' => PaymentBatch::STATUS_FINALIZED,
            'created_by' => $this->actor->id,
        ]);

        $group = $batch->groups()->create([
            'payee_type' => 'supplier',
            'payee_id' => $this->supplier->id,
            'payee_name' => 'PT Wave Two Matrix Supplier',
            'bank_name' => 'BCA',
            'account_number' => '1234567890',
            'account_holder_name' => 'PT Wave Two Matrix Supplier',
            'subtotal_amount' => '1110000.00',
            'bank_fee' => '0.00',
            'net_payment_amount' => '1110000.00',
            'status' => PaymentGroup::STATUS_UNPAID,
        ]);

        $item = $group->items()->create([
            'payable_type' => LocalInvoice::class,
            'payable_id' => $invoice->id,
            'amount' => '1110000.00',
            'status' => PaymentItem::STATUS_ACTIVE,
        ]);

        $voucher = LocalInvoiceVoucher::create([
            'voucher_number' => 'VB-MAT-'.uniqid(),
            'local_invoice_id' => $invoice->id,
            'payment_batch_id' => $batch->id,
            'payment_group_id' => $group->id,
            'payment_item_id' => $item->id,
            'voucher_date' => '2026-09-10',
            'payment_method' => LocalInvoiceVoucher::METHOD_BANK,
            'status' => LocalInvoiceVoucher::STATUS_FINAL,
            'supplier_name_snapshot' => 'PT Wave Two Matrix Supplier',
            'bank_name_snapshot' => 'BCA',
            'bank_account_snapshot' => '1234567890',
            'bank_account_holder_snapshot' => 'PT Wave Two Matrix Supplier',
            'npwp_snapshot' => '01.234.567.8-888.000',
            'invoice_number_snapshot' => $invoice->invoice_number,
            'po_number_snapshot' => $invoice->po_number,
            'gr_references_snapshot' => 'GR-MAT-001',
            'dpp_snapshot' => '1000000.00',
            'ppn_snapshot' => '110000.00',
            'pph_snapshot' => '0.00',
            'net_payable_snapshot' => '1110000.00',
            'amount' => '1110000.00',
            'terbilang_snapshot' => 'Satu Juta Seratus Sepuluh Ribu Rupiah',
            'finalized_by' => $this->actor->id,
            'finalized_at' => now(),
        ]);

        $payment = LocalInvoicePayment::create([
            'local_invoice_id' => $invoice->id,
            'local_invoice_voucher_id' => $voucher->id,
            'payment_item_id' => $item->id,
            'expected_amount' => '1110000.00',
            'actual_paid_total' => '0.00',
            'status' => $source->value,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ]);

        $engine = app(LocalInvoicePaymentStateEngine::class);

        if ($shouldSucceed) {
            $updated = $engine->transition($payment, $target, $this->actor, "Transition {$source->value} -> {$target->value}");
            $this->assertSame($target->value, $updated->status);
            $this->assertSame($target, $updated->statusEnum());
            Event::assertDispatched(LocalInvoicePaymentStatusTransitioned::class);
        } else {
            try {
                $engine->transition($payment, $target, $this->actor);
                $this->fail("Expected InvalidStateTransitionException for {$source->value} -> {$target->value}");
            } catch (InvalidStateTransitionException $e) {
                $this->assertInstanceOf(BusinessRuleException::class, $e);
                $this->assertSame($source->value, $e->fromStatus);
                $this->assertSame($target->value, $e->toStatus);
            }
            $this->assertSame($source->value, $payment->fresh()->status);
            Event::assertNotDispatched(LocalInvoicePaymentStatusTransitioned::class);
        }
    }
}
