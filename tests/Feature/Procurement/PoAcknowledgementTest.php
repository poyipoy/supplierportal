<?php

namespace Tests\Feature\Procurement;

use App\Enums\PoAcknowledgementStatus;
use App\Exceptions\BusinessRuleArgumentException;
use App\Models\ExchangeRate;
use App\Models\LocalPurchaseOrder;
use App\Models\PurchaseOrder;
use App\Models\SupplierScope;
use App\Models\User;
use App\Services\ShipmentService;
use App\Support\BusinessTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PoAcknowledgementTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasing;

    private User $finance;

    private User $importSupplierA;

    private User $importSupplierB;

    private User $localSupplierA;

    private User $localSupplierB;

    private ExchangeRate $rate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasing = User::factory()->create([
            'role' => 'purchasing',
            'is_active' => true,
            'account_status' => 'ACTIVE',
        ]);

        $this->finance = User::factory()->create([
            'role' => 'finance',
            'is_active' => true,
            'account_status' => 'ACTIVE',
        ]);

        $this->importSupplierA = User::factory()->create([
            'role' => 'supplier',
            'is_active' => true,
            'account_status' => 'ACTIVE',
        ]);
        SupplierScope::query()->updateOrCreate([
            'supplier_id' => $this->importSupplierA->id,
            'scope' => 'import',
        ]);

        $this->importSupplierB = User::factory()->create([
            'role' => 'supplier',
            'is_active' => true,
            'account_status' => 'ACTIVE',
        ]);
        SupplierScope::query()->updateOrCreate([
            'supplier_id' => $this->importSupplierB->id,
            'scope' => 'import',
        ]);

        $this->localSupplierA = User::factory()->create([
            'role' => 'supplier',
            'is_active' => true,
            'account_status' => 'ACTIVE',
        ]);
        SupplierScope::query()->where('supplier_id', $this->localSupplierA->id)->delete();
        SupplierScope::query()->create([
            'supplier_id' => $this->localSupplierA->id,
            'scope' => 'local',
        ]);

        $this->localSupplierB = User::factory()->create([
            'role' => 'supplier',
            'is_active' => true,
            'account_status' => 'ACTIVE',
        ]);
        SupplierScope::query()->where('supplier_id', $this->localSupplierB->id)->delete();
        SupplierScope::query()->create([
            'supplier_id' => $this->localSupplierB->id,
            'scope' => 'local',
        ]);

        $this->rate = ExchangeRate::create([
            'currency' => 'USD',
            'rate_to_idr' => 16000,
            'valid_from' => BusinessTime::today()->toDateString(),
            'created_by' => $this->purchasing->id,
        ]);
    }

    private function createImportPo(User $supplier, string $poNumber = 'PO/IMP/001'): PurchaseOrder
    {
        return PurchaseOrder::create([
            'supplier_id' => $supplier->id,
            'currency' => 'USD',
            'exchange_rate_id' => $this->rate->id,
            'po_number' => $poNumber,
            'status' => 'active',
            'created_by' => $this->purchasing->id,
            'estimated_arrival' => BusinessTime::today()->addDays(14)->toDateString(),
        ]);
    }

    private function createLocalPo(User $supplier, string $poNumber = 'LPO/LOC/001'): LocalPurchaseOrder
    {
        return LocalPurchaseOrder::create([
            'po_number' => $poNumber,
            'supplier_id' => $supplier->id,
            'total_amount' => '15000000.00',
            'currency' => 'IDR',
            'status' => LocalPurchaseOrder::STATUS_OPEN,
            'po_date' => BusinessTime::today()->toDateString(),
            'source' => LocalPurchaseOrder::SOURCE_MANUAL,
            'created_by' => $this->purchasing->id,
        ]);
    }

    public function test_import_supplier_can_acknowledge_own_active_po(): void
    {
        $po = $this->createImportPo($this->importSupplierA);

        $response = $this->actingAs($this->importSupplierA)
            ->post(route('supplier.purchase-orders.acknowledge', $po), [
                'mode' => 'confirm',
                'acknowledgement_notes' => 'Order confirmed as scheduled.',
            ]);

        $response->assertRedirect(route('supplier.purchase-orders.show', $po));

        $po->refresh();
        $this->assertSame(PoAcknowledgementStatus::ACKNOWLEDGED, $po->acknowledgementStatusEnum());
        $this->assertNotNull($po->acknowledged_at);
        $this->assertSame($this->importSupplierA->id, (int) $po->acknowledged_by);
        $this->assertSame($po->estimated_arrival?->format('Y-m-d'), $po->supplier_promised_date?->format('Y-m-d'));
        $this->assertSame('Order confirmed as scheduled.', $po->acknowledgement_notes);
    }

    public function test_import_supplier_can_propose_promise_date_and_purchasing_can_accept_or_reject(): void
    {
        $po = $this->createImportPo($this->importSupplierA);
        $proposedDate = BusinessTime::today()->addDays(25)->toDateString();

        // Propose change without notes should fail validation/business rule
        $badResponse = $this->actingAs($this->importSupplierA)
            ->from(route('supplier.purchase-orders.show', $po))
            ->post(route('supplier.purchase-orders.acknowledge', $po), [
                'mode' => 'propose_change',
                'supplier_promised_date' => $proposedDate,
                'acknowledgement_notes' => '',
            ]);
        $badResponse->assertRedirect(route('supplier.purchase-orders.show', $po));
        $this->assertSame(PoAcknowledgementStatus::PENDING, $po->fresh()->acknowledgementStatusEnum());

        // Propose change with valid date and notes
        $response = $this->actingAs($this->importSupplierA)
            ->post(route('supplier.purchase-orders.acknowledge', $po), [
                'mode' => 'propose_change',
                'supplier_promised_date' => $proposedDate,
                'acknowledgement_notes' => 'Mill production slot shifted by 11 days.',
            ]);
        $response->assertRedirect(route('supplier.purchase-orders.show', $po));

        $po->refresh();
        $this->assertSame(PoAcknowledgementStatus::CHANGE_REQUESTED, $po->acknowledgementStatusEnum());
        $this->assertSame($proposedDate, $po->supplier_promised_date?->format('Y-m-d'));

        // Shipment creation is blocked when feature flag is enabled and PO is not acknowledged
        config(['procurement.require_po_acknowledgement_before_shipment' => true]);
        $this->expectException(BusinessRuleArgumentException::class);
        app(ShipmentService::class)->createDraft($this->importSupplierA, [
            'shipment_date' => BusinessTime::today()->toDateString(),
            'items' => [
                [
                    'purchase_order_id' => $po->id,
                    'quotation_item_id' => 1,
                    'shipped_qty' => 1,
                    'actual_weight_kg' => '100.00',
                ],
            ],
        ]);
    }

    public function test_purchasing_accepts_and_rejects_import_po_promise_date_proposal(): void
    {
        $po = $this->createImportPo($this->importSupplierA);
        $proposedDate = BusinessTime::today()->addDays(30)->toDateString();

        $this->actingAs($this->importSupplierA)
            ->post(route('supplier.purchase-orders.acknowledge', $po), [
                'mode' => 'propose_change',
                'supplier_promised_date' => $proposedDate,
                'acknowledgement_notes' => 'Vessel schedule adjustment.',
            ]);

        // Reject first -> reverts to PENDING
        $this->actingAs($this->purchasing)
            ->post(route('purchasing.purchase-orders.respond-promise-change', $po), [
                'decision' => 'reject',
                'response_notes' => 'Please expedite earlier.',
            ])
            ->assertRedirect(route('purchasing.purchase-orders.show', $po));

        $po->refresh();
        $this->assertSame(PoAcknowledgementStatus::PENDING, $po->acknowledgementStatusEnum());
        $this->assertNull($po->supplier_promised_date);

        // Supplier proposes again
        $secondProposedDate = BusinessTime::today()->addDays(20)->toDateString();
        $this->actingAs($this->importSupplierA)
            ->post(route('supplier.purchase-orders.acknowledge', $po), [
                'mode' => 'propose_change',
                'supplier_promised_date' => $secondProposedDate,
                'acknowledgement_notes' => 'Best expedited vessel ETA.',
            ]);

        // Purchasing accepts -> updates estimated_arrival and transitions to ACCEPTED_CHANGE
        $this->actingAs($this->purchasing)
            ->post(route('purchasing.purchase-orders.respond-promise-change', $po), [
                'decision' => 'accept',
                'response_notes' => 'Approved revised ETA.',
            ])
            ->assertRedirect(route('purchasing.purchase-orders.show', $po));

        $po->refresh();
        $this->assertSame(PoAcknowledgementStatus::ACCEPTED_CHANGE, $po->acknowledgementStatusEnum());
        $this->assertSame($secondProposedDate, $po->estimated_arrival?->format('Y-m-d'));
    }

    public function test_local_supplier_po_does_not_require_or_expose_acknowledgement(): void
    {
        $importPoA = $this->createImportPo($this->importSupplierA, 'PO/IMP/ISO-1');
        $localPoA = $this->createLocalPo($this->localSupplierA, 'LPO/LOC/ISO-1');

        // Import Supplier B cannot acknowledge Import Supplier A's PO
        $this->actingAs($this->importSupplierB)
            ->post(route('supplier.purchase-orders.acknowledge', $importPoA), [
                'mode' => 'confirm',
            ])
            ->assertNotFound();

        // Local PO does not allow or require acknowledgement
        $this->assertFalse($localPoA->canAcknowledge());
        $this->assertFalse(Gate::forUser($this->localSupplierA)->allows('acknowledge', $localPoA));
        $this->assertFalse(Gate::forUser($this->finance)->allows('respondPromiseChange', $localPoA));
        $this->assertFalse(Route::has('local-supplier.purchase-orders.acknowledge'));
        $this->assertFalse(Route::has('finance.local-procurement.respond-promise-change'));
        $this->assertFalse(Route::has('purchasing.local-procurement.respond-promise-change'));

        // Local Supplier PO detail page never displays PO Acknowledgement card or submit button
        $supplierHtml = $this->actingAs($this->localSupplierA)
            ->get(route('local-supplier.purchase-orders.show', $localPoA))
            ->assertOk()
            ->getContent();
        $this->assertStringNotContainsString('sec-local-po-acknowledgement', $supplierHtml);
        $this->assertStringNotContainsString(__('purchasing.po_ack.card_title'), $supplierHtml);
        $this->assertStringNotContainsString(__('purchasing.po_ack.actions.submit'), $supplierHtml);

        // Finance & Purchasing Local PO detail pages never display Local PO Acknowledgement card
        foreach ([$this->finance, $this->purchasing] as $staff) {
            $routeName = $staff->role === 'finance' ? 'finance.local-procurement.show' : 'purchasing.local-procurement.show';
            $staffHtml = $this->actingAs($staff)
                ->get(route($routeName, $localPoA))
                ->assertOk()
                ->getContent();
            $this->assertStringNotContainsString('sec-local-po-acknowledgement', $staffHtml);
            $this->assertStringNotContainsString(__('purchasing.po_ack.card_title'), $staffHtml);
        }

        // Local Supplier B cannot view Local Supplier A's PO
        $this->actingAs($this->localSupplierB)
            ->get(route('local-supplier.purchase-orders.show', $localPoA))
            ->assertForbidden();
    }

    public function test_confirm_mode_ignores_stale_promised_date_and_hides_form_after_submit(): void
    {
        $importPo = $this->createImportPo($this->importSupplierA, 'PO/IMP/CONFIRM-1');
        $staleDifferentDate = BusinessTime::today()->addDays(30)->toDateString();

        // Confirm PO page initially renders the ack form with conditional date disabling
        $initialHtml = $this->actingAs($this->importSupplierA)
            ->get(route('supplier.purchase-orders.show', $importPo))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString(route('supplier.purchase-orders.acknowledge', $importPo), $initialHtml);
        $this->assertStringContainsString('el.disabled = !isPropose', $initialHtml);
        $this->assertStringContainsString('aria-required', $initialHtml);

        // Even if the browser form submits a leftover hidden supplier_promised_date != estimated_arrival
        // while mode=confirm and notes is empty, confirm must succeed and use estimated_arrival
        $this->actingAs($this->importSupplierA)
            ->post(route('supplier.purchase-orders.acknowledge', $importPo), [
                'mode' => 'confirm',
                'supplier_promised_date' => $staleDifferentDate,
                'acknowledgement_notes' => '',
            ])
            ->assertRedirect(route('supplier.purchase-orders.show', $importPo))
            ->assertSessionHas('success');

        $importPo->refresh();
        $this->assertSame(PoAcknowledgementStatus::ACKNOWLEDGED, $importPo->acknowledgementStatusEnum());
        $this->assertSame($importPo->estimated_arrival?->format('Y-m-d'), $importPo->supplier_promised_date?->format('Y-m-d'));
        $this->assertFalse($importPo->canAcknowledge());

        // Submit form and button must no longer appear after successful acknowledgement
        $afterConfirmHtml = $this->actingAs($this->importSupplierA)
            ->get(route('supplier.purchase-orders.show', $importPo))
            ->assertOk()
            ->getContent();
        $this->assertStringNotContainsString(route('supplier.purchase-orders.acknowledge', $importPo), $afterConfirmHtml);

        // Pending proposal (CHANGE_REQUESTED) must also hide submit form until reviewed (Import + Local)
        $proposalImportPo = $this->createImportPo($this->importSupplierA, 'PO/IMP/PROP-1');
        $this->actingAs($this->importSupplierA)
            ->post(route('supplier.purchase-orders.acknowledge', $proposalImportPo), [
                'mode' => 'propose_change',
                'supplier_promised_date' => BusinessTime::today()->addDays(21)->toDateString(),
                'acknowledgement_notes' => 'Waiting port slot.',
            ])
            ->assertRedirect(route('supplier.purchase-orders.show', $proposalImportPo))
            ->assertSessionHas('success');

        $proposalImportPo->refresh();
        $this->assertSame(PoAcknowledgementStatus::CHANGE_REQUESTED, $proposalImportPo->acknowledgementStatusEnum());
        $this->assertFalse($proposalImportPo->canAcknowledge());

        $afterProposalHtml = $this->actingAs($this->importSupplierA)
            ->get(route('supplier.purchase-orders.show', $proposalImportPo))
            ->assertOk()
            ->getContent();
        $this->assertStringNotContainsString(route('supplier.purchase-orders.acknowledge', $proposalImportPo), $afterProposalHtml);
        $this->assertStringContainsString(__('purchasing.po_ack.awaiting_review_notice'), $afterProposalHtml);

        // Duplicate submission while awaiting review must be refused
        $this->actingAs($this->importSupplierA)
            ->from(route('supplier.purchase-orders.show', $proposalImportPo))
            ->post(route('supplier.purchase-orders.acknowledge', $proposalImportPo), [
                'mode' => 'confirm',
            ])
            ->assertRedirect(route('supplier.purchase-orders.show', $proposalImportPo))
            ->assertSessionHas('error');
    }
}
