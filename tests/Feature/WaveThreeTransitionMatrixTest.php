<?php

namespace Tests\Feature;

use App\Enums\MaterialClaimStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseRequisitionStatus;
use App\Enums\QcInspectionStatus;
use App\Enums\QuotationStatus;
use App\Enums\ShipmentStatus;
use App\Events\MaterialClaimStatusTransitioned;
use App\Events\PurchaseOrderStatusTransitioned;
use App\Events\PurchaseRequisitionStatusTransitioned;
use App\Events\QcInspectionStatusTransitioned;
use App\Events\QuotationStatusTransitioned;
use App\Events\ShipmentStatusTransitioned;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\MaterialClaim;
use App\Models\Period;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\QcInspection;
use App\Models\Quotation;
use App\Models\Shipment;
use App\Models\Supplier;
use App\Models\SupplierScope;
use App\Models\User;
use App\Services\Procurement\MaterialClaimStateEngine;
use App\Services\Procurement\PurchaseOrderStateEngine;
use App\Services\Procurement\PurchaseRequisitionStateEngine;
use App\Services\Procurement\QcInspectionStateEngine;
use App\Services\Procurement\QuotationStateEngine;
use App\Services\Procurement\ShipmentStateEngine;
use App\Support\StatusHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WaveThreeTransitionMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected User $supplier;

    protected User $actor;

    protected Period $period;

    protected PurchaseRequisition $pr;

    protected PurchaseOrder $po;

    protected QcInspection $inspection;

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
            'company_name' => 'PT Wave Three Import Supplier',
            'country' => 'Japan',
            'city' => 'Tokyo',
        ]);

        SupplierScope::firstOrCreate([
            'supplier_id' => $this->supplier->id,
            'scope' => 'import',
        ]);

        $this->actor = User::factory()->create([
            'role' => 'purchasing',
            'is_active' => true,
        ]);

        $this->period = Period::create([
            'name' => 'Sept 2026',
            'month' => 9,
            'year' => 2026,
            'status' => 'open',
            'created_by' => $this->actor->id,
        ]);

        $this->pr = PurchaseRequisition::create([
            'period_id' => $this->period->id,
            'created_by' => $this->actor->id,
            'pr_number' => 'REQ/09/2026/001',
            'status' => PurchaseRequisition::STATUS_SUBMITTED,
        ]);

        $this->po = PurchaseOrder::create([
            'supplier_id' => $this->supplier->id,
            'currency' => 'USD',
            'po_number' => 'PO-W3-BASE-001',
            'status' => 'active',
            'created_by' => $this->actor->id,
            'estimated_arrival' => '2026-09-20',
        ]);

        $this->inspection = QcInspection::create([
            'po_id' => $this->po->id,
            'inspected_by' => $this->actor->id,
            'status' => QcInspection::STATUS_NG,
            'inspected_at' => now(),
        ]);
    }

    /**
     * @return array<string, array{0: PurchaseOrderStatus, 1: PurchaseOrderStatus, 2: bool}>
     */
    public static function providePurchaseOrderMatrix(): array
    {
        $matrix = [];
        foreach (PurchaseOrderStatus::cases() as $source) {
            foreach (PurchaseOrderStatus::cases() as $target) {
                $key = sprintf('PO %s -> %s', $source->value, $target->value);
                $matrix[$key] = [$source, $target, $source->canTransitionTo($target)];
            }
        }

        return $matrix;
    }

    #[DataProvider('providePurchaseOrderMatrix')]
    public function test_purchase_order_transition_matrix_cell(
        PurchaseOrderStatus $source,
        PurchaseOrderStatus $target,
        bool $shouldSucceed
    ): void {
        Event::fake([PurchaseOrderStatusTransitioned::class]);

        $po = PurchaseOrder::create([
            'supplier_id' => $this->supplier->id,
            'currency' => 'USD',
            'po_number' => 'PO-MAT-'.uniqid(),
            'status' => $source->value,
            'created_by' => $this->actor->id,
        ]);

        $engine = app(PurchaseOrderStateEngine::class);

        if ($shouldSucceed) {
            $updated = $engine->transition($po, $target, $this->actor, "Transition {$source->value} -> {$target->value}");
            $this->assertSame($target->value, $updated->status);
            $this->assertSame($target, $updated->statusEnum());
            Event::assertDispatched(PurchaseOrderStatusTransitioned::class);
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
            Event::assertNotDispatched(PurchaseOrderStatusTransitioned::class);
        }
    }

    /**
     * @return array<string, array{0: ShipmentStatus, 1: ShipmentStatus, 2: bool}>
     */
    public static function provideShipmentMatrix(): array
    {
        $matrix = [];
        foreach (ShipmentStatus::cases() as $source) {
            foreach (ShipmentStatus::cases() as $target) {
                $key = sprintf('Shipment %s -> %s', $source->value, $target->value);
                $matrix[$key] = [$source, $target, $source->canTransitionTo($target)];
            }
        }

        return $matrix;
    }

    #[DataProvider('provideShipmentMatrix')]
    public function test_shipment_transition_matrix_cell(
        ShipmentStatus $source,
        ShipmentStatus $target,
        bool $shouldSucceed
    ): void {
        Event::fake([ShipmentStatusTransitioned::class]);

        $shipment = Shipment::create([
            'shipment_number' => 'SHP-MAT-'.uniqid(),
            'supplier_id' => $this->supplier->id,
            'status' => $source->value,
            'shipment_date' => '2026-09-10',
            'estimated_arrival_date' => '2026-09-20',
            'created_by' => $this->supplier->id,
        ]);

        $engine = app(ShipmentStateEngine::class);

        if ($shouldSucceed) {
            $updated = $engine->transition($shipment, $target, $this->actor, "Transition {$source->value} -> {$target->value}");
            $this->assertSame($target->value, $updated->status);
            $this->assertSame($target, $updated->statusEnum());
            Event::assertDispatched(ShipmentStatusTransitioned::class);
        } else {
            try {
                $engine->transition($shipment, $target, $this->actor);
                $this->fail("Expected InvalidStateTransitionException for {$source->value} -> {$target->value}");
            } catch (InvalidStateTransitionException $e) {
                $this->assertInstanceOf(BusinessRuleException::class, $e);
                $this->assertSame($source->value, $e->fromStatus);
                $this->assertSame($target->value, $e->toStatus);
            }
            $this->assertSame($source->value, $shipment->fresh()->status);
            Event::assertNotDispatched(ShipmentStatusTransitioned::class);
        }
    }

    /**
     * @return array<string, array{0: QcInspectionStatus, 1: QcInspectionStatus, 2: bool}>
     */
    public static function provideQcInspectionMatrix(): array
    {
        $matrix = [];
        foreach (QcInspectionStatus::cases() as $source) {
            foreach (QcInspectionStatus::cases() as $target) {
                $key = sprintf('QC %s -> %s', $source->value, $target->value);
                $matrix[$key] = [$source, $target, $source->canTransitionTo($target)];
            }
        }

        return $matrix;
    }

    #[DataProvider('provideQcInspectionMatrix')]
    public function test_qc_inspection_transition_matrix_cell(
        QcInspectionStatus $source,
        QcInspectionStatus $target,
        bool $shouldSucceed
    ): void {
        Event::fake([QcInspectionStatusTransitioned::class]);

        $qc = QcInspection::create([
            'po_id' => $this->po->id,
            'inspected_by' => $this->actor->id,
            'status' => $source->value,
            'inspected_at' => now(),
        ]);

        $engine = app(QcInspectionStateEngine::class);

        if ($shouldSucceed) {
            $updated = $engine->transition($qc, $target, $this->actor, "Transition {$source->value} -> {$target->value}");
            $this->assertSame($target->value, $updated->status);
            $this->assertSame($target, $updated->statusEnum());
            Event::assertDispatched(QcInspectionStatusTransitioned::class);
        } else {
            try {
                $engine->transition($qc, $target, $this->actor);
                $this->fail("Expected InvalidStateTransitionException for {$source->value} -> {$target->value}");
            } catch (InvalidStateTransitionException $e) {
                $this->assertInstanceOf(BusinessRuleException::class, $e);
                $this->assertSame($source->value, $e->fromStatus);
                $this->assertSame($target->value, $e->toStatus);
            }
            $this->assertSame($source->value, $qc->fresh()->status);
            Event::assertNotDispatched(QcInspectionStatusTransitioned::class);
        }
    }

    /**
     * @return array<string, array{0: MaterialClaimStatus, 1: MaterialClaimStatus, 2: bool}>
     */
    public static function provideMaterialClaimMatrix(): array
    {
        $matrix = [];
        foreach (MaterialClaimStatus::cases() as $source) {
            foreach (MaterialClaimStatus::cases() as $target) {
                $key = sprintf('Claim %s -> %s', $source->value, $target->value);
                $matrix[$key] = [$source, $target, $source->canTransitionTo($target)];
            }
        }

        return $matrix;
    }

    #[DataProvider('provideMaterialClaimMatrix')]
    public function test_material_claim_transition_matrix_cell(
        MaterialClaimStatus $source,
        MaterialClaimStatus $target,
        bool $shouldSucceed
    ): void {
        Event::fake([MaterialClaimStatusTransitioned::class]);

        $claim = MaterialClaim::create([
            'inspection_id' => $this->inspection->id,
            'po_id' => $this->po->id,
            'submitted_by' => $this->actor->id,
            'supplier_id' => $this->supplier->id,
            'status' => $source->value,
            'description' => 'Matrix defect',
            'resolution_expected' => 'Replacement',
            'deadline' => '2026-09-25',
        ]);

        $engine = app(MaterialClaimStateEngine::class);

        if ($shouldSucceed) {
            $updated = $engine->transition($claim, $target, $this->actor, "Transition {$source->value} -> {$target->value}");
            $this->assertSame($target->value, $updated->status);
            $this->assertSame($target, $updated->statusEnum());
            Event::assertDispatched(MaterialClaimStatusTransitioned::class);
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
            Event::assertNotDispatched(MaterialClaimStatusTransitioned::class);
        }
    }

    /**
     * @return array<string, array{0: PurchaseRequisitionStatus, 1: PurchaseRequisitionStatus, 2: bool}>
     */
    public static function providePurchaseRequisitionMatrix(): array
    {
        $matrix = [];
        foreach (PurchaseRequisitionStatus::cases() as $source) {
            foreach (PurchaseRequisitionStatus::cases() as $target) {
                $key = sprintf('PR %s -> %s', $source->value, $target->value);
                $matrix[$key] = [$source, $target, $source->canTransitionTo($target)];
            }
        }

        return $matrix;
    }

    #[DataProvider('providePurchaseRequisitionMatrix')]
    public function test_purchase_requisition_transition_matrix_cell(
        PurchaseRequisitionStatus $source,
        PurchaseRequisitionStatus $target,
        bool $shouldSucceed
    ): void {
        Event::fake([PurchaseRequisitionStatusTransitioned::class]);

        $pr = PurchaseRequisition::create([
            'period_id' => $this->period->id,
            'created_by' => $this->actor->id,
            'pr_number' => 'REQ-MAT-'.uniqid(),
            'status' => $source->value,
        ]);

        $engine = app(PurchaseRequisitionStateEngine::class);

        if ($shouldSucceed) {
            $updated = $engine->transition($pr, $target, $this->actor, "Transition {$source->value} -> {$target->value}");
            $this->assertSame($target->value, $updated->status);
            $this->assertSame($target, $updated->statusEnum());
            Event::assertDispatched(PurchaseRequisitionStatusTransitioned::class);
        } else {
            try {
                $engine->transition($pr, $target, $this->actor);
                $this->fail("Expected InvalidStateTransitionException for {$source->value} -> {$target->value}");
            } catch (InvalidStateTransitionException $e) {
                $this->assertInstanceOf(BusinessRuleException::class, $e);
                $this->assertSame($source->value, $e->fromStatus);
                $this->assertSame($target->value, $e->toStatus);
            }
            $this->assertSame($source->value, $pr->fresh()->status);
            Event::assertNotDispatched(PurchaseRequisitionStatusTransitioned::class);
        }
    }

    /**
     * @return array<string, array{0: QuotationStatus, 1: QuotationStatus, 2: bool}>
     */
    public static function provideQuotationMatrix(): array
    {
        $matrix = [];
        foreach (QuotationStatus::cases() as $source) {
            foreach (QuotationStatus::cases() as $target) {
                $key = sprintf('Quotation %s -> %s', $source->value, $target->value);
                $matrix[$key] = [$source, $target, $source->canTransitionTo($target)];
            }
        }

        return $matrix;
    }

    #[DataProvider('provideQuotationMatrix')]
    public function test_quotation_transition_matrix_cell(
        QuotationStatus $source,
        QuotationStatus $target,
        bool $shouldSucceed
    ): void {
        Event::fake([QuotationStatusTransitioned::class]);

        $quotation = Quotation::create([
            'pr_id' => $this->pr->id,
            'supplier_id' => $this->supplier->id,
            'currency' => 'USD',
            'status' => $source->value,
        ]);

        $engine = app(QuotationStateEngine::class);

        if ($shouldSucceed) {
            $updated = $engine->transition($quotation, $target, $this->actor, "Transition {$source->value} -> {$target->value}");
            $this->assertSame($target->value, $updated->status);
            $this->assertSame($target, $updated->statusEnum());
            Event::assertDispatched(QuotationStatusTransitioned::class);
        } else {
            try {
                $engine->transition($quotation, $target, $this->actor);
                $this->fail("Expected InvalidStateTransitionException for {$source->value} -> {$target->value}");
            } catch (InvalidStateTransitionException $e) {
                $this->assertInstanceOf(BusinessRuleException::class, $e);
                $this->assertSame($source->value, $e->fromStatus);
                $this->assertSame($target->value, $e->toStatus);
            }
            $this->assertSame($source->value, $quotation->fresh()->status);
            Event::assertNotDispatched(QuotationStatusTransitioned::class);
        }
    }

    public function test_wave_three_legacy_synonyms_and_compound_reconciliation(): void
    {
        $this->assertSame(PurchaseOrderStatus::ACTIVE, PurchaseOrderStatus::tryFromWithLegacy('open'));
        $this->assertSame(PurchaseOrderStatus::COMPLETED, PurchaseOrderStatus::tryFromWithLegacy('closed'));
        $this->assertSame(ShipmentStatus::SUBMITTED, ShipmentStatus::tryFromWithLegacy('in_transit'));
        $this->assertSame(ShipmentStatus::ARRIVED, ShipmentStatus::tryFromWithLegacy('delivered'));
        $this->assertSame(QcInspectionStatus::OK, QcInspectionStatus::tryFromWithLegacy('passed'));
        $this->assertSame(QcInspectionStatus::NG, QcInspectionStatus::tryFromWithLegacy('failed'));
        $this->assertSame(MaterialClaimStatus::PENDING, MaterialClaimStatus::tryFromWithLegacy('open'));
        $this->assertSame(MaterialClaimStatus::RESOLVED, MaterialClaimStatus::tryFromWithLegacy('completed'));
        $this->assertSame(PurchaseRequisitionStatus::SUBMITTED, PurchaseRequisitionStatus::tryFromWithLegacy('open'));
        $this->assertSame(PurchaseRequisitionStatus::COMPLETED, PurchaseRequisitionStatus::tryFromWithLegacy('closed'));
        $this->assertSame(QuotationStatus::ACCEPTED, QuotationStatus::tryFromWithLegacy('approved'));
        $this->assertSame(QuotationStatus::REVISION_REQUESTED, QuotationStatus::tryFromWithLegacy('needs_revision'));

        // StatusHelper enum integration
        $this->assertNotEmpty(StatusHelper::poLabel(PurchaseOrderStatus::ACTIVE));
        $this->assertNotEmpty(StatusHelper::shipmentLabel(ShipmentStatus::SUBMITTED));
        $this->assertSame('OK', StatusHelper::qcLabel(QcInspectionStatus::OK));
        $this->assertNotEmpty(StatusHelper::claimLabel(MaterialClaimStatus::PENDING));
        $this->assertNotEmpty(StatusHelper::prLabel(PurchaseRequisitionStatus::BIDDING));
        $this->assertNotEmpty(StatusHelper::quotationLabel(QuotationStatus::ACCEPTED));

        // Compound state machine: reconcileOperationalStatus() dispatches PurchaseOrderStatusTransitioned
        Event::fake([PurchaseOrderStatusTransitioned::class]);
        $result = $this->po->reconcileOperationalStatus();
        $this->assertSame('claim_needed', $result);
        $this->assertSame(PurchaseOrderStatus::CLAIM_NEEDED, $this->po->fresh()->statusEnum());
        Event::assertDispatched(PurchaseOrderStatusTransitioned::class);
    }
}
