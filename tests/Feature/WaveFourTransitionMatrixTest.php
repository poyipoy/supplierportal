<?php

namespace Tests\Feature;

use App\Enums\CustomsDocumentStatus;
use App\Enums\SupplierAuditStatus;
use App\Enums\SupplierBankAccountStatus;
use App\Enums\SupplierChangeRequestStatus;
use App\Enums\SupplierRegistrationAttemptStatus;
use App\Events\SupplierAuditStatusTransitioned;
use App\Events\SupplierBankAccountStatusTransitioned;
use App\Events\SupplierChangeRequestStatusTransitioned;
use App\Events\SupplierRegistrationAttemptStatusTransitioned;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\PoDocument;
use App\Models\PurchaseOrder;
use App\Models\Shipment;
use App\Models\ShipmentDocument;
use App\Models\Supplier;
use App\Models\SupplierAudit;
use App\Models\SupplierAuditTemplate;
use App\Models\SupplierBankAccount;
use App\Models\SupplierChangeRequest;
use App\Models\SupplierRegistrationAttempt;
use App\Models\SupplierScope;
use App\Models\User;
use App\Services\SupplierAudit\SupplierAuditStateEngine;
use App\Services\VendorMaster\SupplierBankAccountStateEngine;
use App\Services\VendorMaster\SupplierChangeRequestStateEngine;
use App\Services\VendorMaster\SupplierRegistrationAttemptStateEngine;
use App\Support\StatusHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WaveFourTransitionMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected User $supplier;

    protected User $actor;

    protected SupplierAuditTemplate $template;

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
            'company_name' => 'PT Wave Four Supplier',
        ]);

        SupplierScope::firstOrCreate([
            'supplier_id' => $this->supplier->id,
            'scope' => 'local',
        ]);

        $this->actor = User::factory()->create([
            'role' => 'purchasing',
            'is_active' => true,
        ]);

        $this->template = SupplierAuditTemplate::create([
            'code' => 'TPL-W4-01',
            'title' => 'Wave 4 Audit Template',
            'version' => 1,
            'is_active' => true,
        ]);
    }

    /**
     * @return array<string, array{0: SupplierAuditStatus, 1: SupplierAuditStatus, 2: bool}>
     */
    public static function provideSupplierAuditMatrix(): array
    {
        $matrix = [];
        foreach (SupplierAuditStatus::cases() as $source) {
            foreach (SupplierAuditStatus::cases() as $target) {
                $key = sprintf('Audit %s -> %s', $source->value, $target->value);
                $matrix[$key] = [$source, $target, $source->canTransitionTo($target)];
            }
        }

        return $matrix;
    }

    #[DataProvider('provideSupplierAuditMatrix')]
    public function test_supplier_audit_transition_matrix_cell(
        SupplierAuditStatus $source,
        SupplierAuditStatus $target,
        bool $shouldSucceed
    ): void {
        Event::fake([SupplierAuditStatusTransitioned::class]);

        $audit = SupplierAudit::create([
            'supplier_id' => $this->supplier->id,
            'supplier_audit_template_id' => $this->template->id,
            'period_label' => '2026-Q3-'.uniqid(),
            'status' => $source->value,
            'assigned_by' => $this->actor->id,
            'assigned_at' => now(),
        ]);

        $engine = app(SupplierAuditStateEngine::class);

        if ($shouldSucceed) {
            $updated = $engine->transition($audit, $target, $this->actor, "Transition {$source->value} -> {$target->value}");
            $this->assertSame($target->value, $updated->status);
            $this->assertSame($target, $updated->statusEnum());
            $this->assertDatabaseHas('supplier_audit_status_histories', [
                'supplier_audit_id' => $audit->id,
                'from_status' => $source->value,
                'to_status' => $target->value,
            ]);
            Event::assertDispatched(SupplierAuditStatusTransitioned::class);
        } else {
            try {
                $engine->transition($audit, $target, $this->actor);
                $this->fail("Expected InvalidStateTransitionException for {$source->value} -> {$target->value}");
            } catch (InvalidStateTransitionException $e) {
                $this->assertInstanceOf(BusinessRuleException::class, $e);
                $this->assertSame($source->value, $e->fromStatus);
                $this->assertSame($target->value, $e->toStatus);
            }
            $this->assertSame($source->value, $audit->fresh()->status);
            Event::assertNotDispatched(SupplierAuditStatusTransitioned::class);
        }
    }

    /**
     * @return array<string, array{0: SupplierRegistrationAttemptStatus, 1: SupplierRegistrationAttemptStatus, 2: bool}>
     */
    public static function provideRegistrationAttemptMatrix(): array
    {
        $matrix = [];
        foreach (SupplierRegistrationAttemptStatus::cases() as $source) {
            foreach (SupplierRegistrationAttemptStatus::cases() as $target) {
                $key = sprintf('RegAttempt %s -> %s', $source->value, $target->value);
                $matrix[$key] = [$source, $target, $source->canTransitionTo($target)];
            }
        }

        return $matrix;
    }

    #[DataProvider('provideRegistrationAttemptMatrix')]
    public function test_supplier_registration_attempt_transition_matrix_cell(
        SupplierRegistrationAttemptStatus $source,
        SupplierRegistrationAttemptStatus $target,
        bool $shouldSucceed
    ): void {
        Event::fake([SupplierRegistrationAttemptStatusTransitioned::class]);

        $attempt = SupplierRegistrationAttempt::create([
            'user_id' => $this->supplier->id,
            'attempt_number' => random_int(1, 99999),
            'status' => $source->value,
            'submission_snapshot' => ['company' => ['company_name' => 'PT Wave Four Supplier']],
            'submission_checksum' => hash('sha256', uniqid()),
            'submitted_at' => now(),
        ]);

        $engine = app(SupplierRegistrationAttemptStateEngine::class);

        if ($shouldSucceed) {
            $updated = $engine->transition($attempt, $target, $this->actor, "Transition {$source->value} -> {$target->value}");
            $this->assertSame($target->value, $updated->status);
            $this->assertSame($target, $updated->statusEnum());
            Event::assertDispatched(SupplierRegistrationAttemptStatusTransitioned::class);
        } else {
            try {
                $engine->transition($attempt, $target, $this->actor);
                $this->fail("Expected InvalidStateTransitionException for {$source->value} -> {$target->value}");
            } catch (InvalidStateTransitionException $e) {
                $this->assertInstanceOf(BusinessRuleException::class, $e);
                $this->assertSame($source->value, $e->fromStatus);
                $this->assertSame($target->value, $e->toStatus);
            }
            $this->assertSame($source->value, $attempt->fresh()->status);
            Event::assertNotDispatched(SupplierRegistrationAttemptStatusTransitioned::class);
        }
    }

    /**
     * @return array<string, array{0: SupplierChangeRequestStatus, 1: SupplierChangeRequestStatus, 2: bool}>
     */
    public static function provideChangeRequestMatrix(): array
    {
        $matrix = [];
        foreach (SupplierChangeRequestStatus::cases() as $source) {
            foreach (SupplierChangeRequestStatus::cases() as $target) {
                $key = sprintf('ChangeReq %s -> %s', $source->value, $target->value);
                $matrix[$key] = [$source, $target, $source->canTransitionTo($target)];
            }
        }

        return $matrix;
    }

    #[DataProvider('provideChangeRequestMatrix')]
    public function test_supplier_change_request_transition_matrix_cell(
        SupplierChangeRequestStatus $source,
        SupplierChangeRequestStatus $target,
        bool $shouldSucceed
    ): void {
        Event::fake([SupplierChangeRequestStatusTransitioned::class]);

        $changeRequest = SupplierChangeRequest::create([
            'supplier_id' => $this->supplier->id,
            'change_type' => SupplierChangeRequest::TYPE_PROFILE,
            'current_data_snapshot' => ['company_name' => 'Old'],
            'proposed_data' => ['company_name' => 'New'],
            'status' => $source->value,
            'requested_by' => $this->supplier->id,
            'requested_at' => now(),
        ]);

        $engine = app(SupplierChangeRequestStateEngine::class);

        if ($shouldSucceed) {
            $updated = $engine->transition($changeRequest, $target, $this->actor, "Transition {$source->value} -> {$target->value}");
            $this->assertSame($target->value, $updated->status);
            $this->assertSame($target, $updated->statusEnum());
            Event::assertDispatched(SupplierChangeRequestStatusTransitioned::class);
        } else {
            try {
                $engine->transition($changeRequest, $target, $this->actor);
                $this->fail("Expected InvalidStateTransitionException for {$source->value} -> {$target->value}");
            } catch (InvalidStateTransitionException $e) {
                $this->assertInstanceOf(BusinessRuleException::class, $e);
                $this->assertSame($source->value, $e->fromStatus);
                $this->assertSame($target->value, $e->toStatus);
            }
            $this->assertSame($source->value, $changeRequest->fresh()->status);
            Event::assertNotDispatched(SupplierChangeRequestStatusTransitioned::class);
        }
    }

    /**
     * @return array<string, array{0: SupplierBankAccountStatus, 1: SupplierBankAccountStatus, 2: bool}>
     */
    public static function provideBankAccountMatrix(): array
    {
        $matrix = [];
        foreach (SupplierBankAccountStatus::cases() as $source) {
            foreach (SupplierBankAccountStatus::cases() as $target) {
                $key = sprintf('BankAcct %s -> %s', $source->value, $target->value);
                $matrix[$key] = [$source, $target, $source->canTransitionTo($target)];
            }
        }

        return $matrix;
    }

    #[DataProvider('provideBankAccountMatrix')]
    public function test_supplier_bank_account_transition_matrix_cell(
        SupplierBankAccountStatus $source,
        SupplierBankAccountStatus $target,
        bool $shouldSucceed
    ): void {
        Event::fake([SupplierBankAccountStatusTransitioned::class]);

        $bankAccount = SupplierBankAccount::create([
            'supplier_id' => $this->supplier->id,
            'bank_name' => 'BCA',
            'account_number' => '1234567890',
            'account_holder_name' => 'PT Wave Four Supplier',
            'status' => $source->value,
        ]);

        $engine = app(SupplierBankAccountStateEngine::class);

        if ($shouldSucceed) {
            $updated = $engine->transition($bankAccount, $target, $this->actor, "Transition {$source->value} -> {$target->value}");
            $this->assertSame($target->value, $updated->status);
            $this->assertSame($target, $updated->statusEnum());
            Event::assertDispatched(SupplierBankAccountStatusTransitioned::class);
        } else {
            try {
                $engine->transition($bankAccount, $target, $this->actor);
                $this->fail("Expected InvalidStateTransitionException for {$source->value} -> {$target->value}");
            } catch (InvalidStateTransitionException $e) {
                $this->assertInstanceOf(BusinessRuleException::class, $e);
                $this->assertSame($source->value, $e->fromStatus);
                $this->assertSame($target->value, $e->toStatus);
            }
            $this->assertSame($source->value, $bankAccount->fresh()->status);
            Event::assertNotDispatched(SupplierBankAccountStatusTransitioned::class);
        }
    }

    public function test_wave_four_legacy_synonyms_and_customs_documents(): void
    {
        $this->assertSame(SupplierAuditStatus::ASSIGNED, SupplierAuditStatus::tryFromWithLegacy('PUBLISHED'));
        $this->assertSame(SupplierAuditStatus::DRAFT, SupplierAuditStatus::tryFromWithLegacy('IN_PROGRESS'));
        $this->assertSame(SupplierAuditStatus::SUBMITTED, SupplierAuditStatus::tryFromWithLegacy('UNDER_REVIEW'));
        $this->assertSame(SupplierAuditStatus::RESULT_PUBLISHED, SupplierAuditStatus::tryFromWithLegacy('COMPLETED'));

        $this->assertSame(SupplierRegistrationAttemptStatus::PENDING, SupplierRegistrationAttemptStatus::tryFromWithLegacy('SUBMITTED'));
        $this->assertSame(SupplierRegistrationAttemptStatus::REVISION, SupplierRegistrationAttemptStatus::tryFromWithLegacy('NEEDS_REVISION'));
        $this->assertSame(SupplierRegistrationAttemptStatus::APPROVED, SupplierRegistrationAttemptStatus::tryFromWithLegacy('ACTIVE'));

        $this->assertSame(SupplierChangeRequestStatus::PENDING, SupplierChangeRequestStatus::tryFromWithLegacy('OPEN'));
        $this->assertSame(SupplierChangeRequestStatus::CANCELLED, SupplierChangeRequestStatus::tryFromWithLegacy('SUPERSEDED'));

        $this->assertSame(SupplierBankAccountStatus::VERIFIED, SupplierBankAccountStatus::tryFromWithLegacy('ACTIVE'));
        $this->assertSame(SupplierBankAccountStatus::INACTIVE, SupplierBankAccountStatus::tryFromWithLegacy('DEACTIVATED'));

        $this->assertSame(CustomsDocumentStatus::RECEIVED, CustomsDocumentStatus::tryFromWithLegacy('uploaded'));
        $this->assertSame(CustomsDocumentStatus::DONE, CustomsDocumentStatus::tryFromWithLegacy('completed'));
        $this->assertSame(3, CustomsDocumentStatus::VERIFIED->rank());

        // Verify ShipmentDocument and PoDocument cast + statusEnum()
        $po = PurchaseOrder::create([
            'supplier_id' => $this->supplier->id,
            'currency' => 'USD',
            'po_number' => 'PO-W4-DOC-001',
            'status' => 'active',
            'created_by' => $this->actor->id,
        ]);

        $poDoc = PoDocument::create([
            'po_id' => $po->id,
            'doc_type' => 'invoice',
            'status' => 'uploaded', // legacy synonym -> 'received'
        ]);
        $this->assertSame('received', $poDoc->status);
        $this->assertSame(CustomsDocumentStatus::RECEIVED, $poDoc->statusEnum());

        $shipment = Shipment::create([
            'shipment_number' => 'SHP-W4-DOC-001',
            'supplier_id' => $this->supplier->id,
            'status' => 'submitted',
            'shipment_date' => '2026-09-10',
            'estimated_arrival_date' => '2026-09-20',
            'created_by' => $this->supplier->id,
        ]);

        $sDoc = ShipmentDocument::create([
            'shipment_id' => $shipment->id,
            'doc_type' => 'bl',
            'status' => 'verified',
        ]);
        $this->assertSame('verified', $sDoc->status);
        $this->assertSame(CustomsDocumentStatus::VERIFIED, $sDoc->statusEnum());

        // Verify StatusHelper integration
        $this->assertSame('info', StatusHelper::supplierAuditTone(SupplierAuditStatus::SUBMITTED));
        $this->assertSame('success', StatusHelper::registrationTone(SupplierRegistrationAttemptStatus::APPROVED));
        $this->assertSame('warning', StatusHelper::contactRequestTone(SupplierChangeRequestStatus::PENDING));
        $this->assertSame('success', StatusHelper::localFinanceTone(SupplierBankAccountStatus::VERIFIED));
        $this->assertSame('success', StatusHelper::shipmentDocTone(CustomsDocumentStatus::DONE));
    }
}
