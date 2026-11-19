<?php

namespace Tests\Feature\Procurement;

use App\Enums\PoSalesContractStatus;
use App\Exports\ShipmentsExport;
use App\Models\PurchaseOrder;
use App\Models\Shipment;
use App\Models\ShipmentPreshipmentInspection;
use App\Models\SupplierGoodsLocation;
use App\Models\SupplierPreshipmentProfile;
use App\Models\User;
use App\Services\PrItemAwardService;
use App\Services\Procurement\PreshipmentInspectionService;
use App\Services\PurchaseOrderGenerationService;
use App\Services\ShipmentService;
use App\Support\Export\ExportOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PreshipmentInspectionTest extends TestCase
{
    use ProcurementFixtures;
    use RefreshDatabase;

    private User $supplierB;

    private User $admin;

    private User $qc;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        $this->setUpProcurementActors();

        $this->supplierB = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->qc = User::factory()->create(['role' => 'qc', 'is_active' => true]);

        config([
            'procurement.require_preshipment_for_shipment' => true,
            'procurement.require_commercial_invoice_for_shipment' => false,
            'procurement.shipment_mtc_enabled' => false,
            'procurement.require_sales_contract_before_shipment' => false,
            'procurement.require_ready_to_ship_for_shipment' => false,
            'procurement.require_po_acknowledgement_before_shipment' => false,
        ]);
    }

    private function createPoFor(User $supplier): PurchaseOrder
    {
        $pr = $this->createRequisition([['quantity' => 10, 'weight_needed' => 3]]);
        $quotation = $this->createQuotation($pr, [['price_per_kg' => 2.5]], supplier: $supplier);
        $award = app(PrItemAwardService::class)->awardItem($pr->items[0], $quotation->items[0], $this->purchasing);

        return app(PurchaseOrderGenerationService::class)
            ->generateFromAwards([$award->id], $this->purchasing)
            ->sole();
    }

    private function validPsiPayload(array $overrides = []): array
    {
        return array_merge([
            'exporter_name' => 'Shanghai Trading Co., Ltd.',
            'exporter_contact' => 'Ms. Lin Wei',
            'exporter_address' => '100 Century Ave, Pudong',
            'exporter_city' => 'Shanghai',
            'exporter_country' => 'CN',
            'exporter_zip_code' => '200120',
            'exporter_telephone' => '+86 (21) 5555-0100',
            'exporter_mobile' => '+86 138-0013-8000',
            'exporter_fax' => '+86-21-5555-0199',
            'exporter_email' => 'export@shanghai-trade.example',
            'exporter_port_of_loading' => 'Shanghai Port',
            'same_as_exporter' => '0',
            'supplier_name' => 'Wuxi Precision Steel Mill',
            'supplier_contact' => 'Mr. Zhou Qiang',
            'supplier_address' => '88 High-Tech Road, Xinwu',
            'supplier_city' => 'Wuxi',
            'supplier_country' => 'CN',
            'supplier_telephone' => '+86-510-8888-1234',
            'supplier_mobile' => '+86 139 5100 0001',
            'supplier_fax' => '+86-510-8888-9999',
            'supplier_email' => 'factory@wuxi-mill.example',
            'save_exporter_default' => '0',
            'location_action' => 'none',
            'set_location_default' => '0',
        ], $overrides);
    }

    private function baseShipmentPayload(PurchaseOrder $po, array $overrides = []): array
    {
        $quotationItem = $po->quotations->first()->items->first();

        return array_merge([
            'action' => 'draft',
            'shipment_date' => now()->toDateString(),
            'estimated_arrival_date' => now()->addDays(14)->toDateString(),
            'invoice_number' => 'INV-PSI-'.uniqid(),
            'items' => [[
                'purchase_order_id' => $po->id,
                'quotation_item_id' => $quotationItem->id,
                'shipped_qty' => 3,
                'actual_weight_kg' => 9.0,
            ]],
        ], $overrides);
    }

    public function test_cross_supplier_and_non_supplier_isolation_is_enforced_on_backend(): void
    {
        $poA = $this->createPoFor($this->supplier);
        $poB = $this->createPoFor($this->supplierB);

        $locationB = SupplierGoodsLocation::create([
            'supplier_id' => $this->supplierB->id,
            'name' => 'Supplier B Secret Warehouse',
            'address' => '99 Secret Rd',
            'city' => 'Busan',
            'country' => 'KR',
            'is_default' => true,
        ]);
        SupplierPreshipmentProfile::create([
            'supplier_id' => $this->supplierB->id,
            'exporter_name' => 'Supplier B Secret Exporter',
            'exporter_port_of_loading' => 'Busan Secret Port',
        ]);

        // Supplier A's create page must never expose Supplier B's locations, exporter, or ports.
        $createHtml = $this->actingAs($this->supplier)
            ->get(route('supplier.shipments.create'))
            ->assertOk()
            ->getContent();
        $this->assertStringNotContainsString('Supplier B Secret Warehouse', $createHtml);
        $this->assertStringNotContainsString('Supplier B Secret Exporter', $createHtml);
        $this->assertStringNotContainsString('Busan Secret Port', $createHtml);

        // Supplier A cannot pass Supplier B's goods_location hash when creating a shipment.
        $this->actingAs($this->supplier)
            ->post(route('supplier.shipments.store'), $this->baseShipmentPayload($poA, [
                'preshipment' => $this->validPsiPayload([
                    'goods_location' => $locationB->getRouteKey(),
                    'location_action' => 'update',
                ]),
            ]))
            ->assertSessionHasErrors('preshipment.goods_location');

        // Create a real shipment for Supplier A.
        $draftA = app(ShipmentService::class)->createDraft($this->supplier, $this->baseShipmentPayload($poA, [
            'preshipment' => $this->validPsiPayload(),
        ]));

        // Supplier B, Purchasing, Admin, and QC cannot PUT preshipment on Supplier A's shipment.
        foreach ([$this->supplierB, $this->purchasing, $this->admin, $this->qc] as $actor) {
            $this->actingAs($actor)
                ->put(route('supplier.shipments.preshipment.update', $draftA), [
                    'preshipment' => $this->validPsiPayload(['exporter_name' => 'Hijacked']),
                ])
                ->assertForbidden();
        }

        $this->assertSame('Shanghai Trading Co., Ltd.', $draftA->fresh()->preshipmentInspection->exporter_name);

        // Ensure Supplier B's own PO still works cleanly.
        $this->assertNotNull($poB->id);
    }

    public function test_snapshot_immutability_and_explicit_master_opt_in_only(): void
    {
        $po = $this->createPoFor($this->supplier);

        // First shipment with explicit opt-in saves master Exporter + new default Goods Location.
        $this->actingAs($this->supplier)
            ->post(route('supplier.shipments.store'), $this->baseShipmentPayload($po, [
                'action' => 'submit',
                'invoice_number' => 'INV-SNAP-001',
                'preshipment' => $this->validPsiPayload([
                    'save_exporter_default' => '1',
                    'location_action' => 'create',
                    'set_location_default' => '1',
                ]),
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $shipment1 = Shipment::where('invoice_number', 'INV-SNAP-001')->firstOrFail();
        $this->assertSame(Shipment::STATUS_SUBMITTED, $shipment1->status);
        $this->assertDatabaseHas('supplier_preshipment_profiles', [
            'supplier_id' => $this->supplier->id,
            'exporter_name' => 'Shanghai Trading Co., Ltd.',
        ]);
        $location = SupplierGoodsLocation::forSupplier($this->supplier->id)->firstOrFail();
        $this->assertTrue($location->is_default);
        $this->assertSame($location->id, $shipment1->preshipmentInspection->goods_location_id);

        // Second shipment edits PSI WITHOUT opt-in -> snapshot reflects edits, master remains UNCHANGED!
        $this->actingAs($this->supplier)
            ->post(route('supplier.shipments.store'), $this->baseShipmentPayload($po, [
                'action' => 'draft',
                'invoice_number' => 'INV-SNAP-002',
                'preshipment' => $this->validPsiPayload([
                    'exporter_name' => 'One-Off Exporter Ltd.',
                    'supplier_name' => 'One-Off Warehouse',
                    'save_exporter_default' => '0',
                    'location_action' => 'none',
                ]),
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $shipment2 = Shipment::where('invoice_number', 'INV-SNAP-002')->firstOrFail();
        $this->assertSame('One-Off Exporter Ltd.', $shipment2->preshipmentInspection->exporter_name);
        $this->assertSame('One-Off Warehouse', $shipment2->preshipmentInspection->supplier_name);
        $this->assertSame(
            'Shanghai Trading Co., Ltd.',
            SupplierPreshipmentProfile::where('supplier_id', $this->supplier->id)->firstOrFail()->exporter_name
        );
        $this->assertSame('Wuxi Precision Steel Mill', $location->fresh()->name);

        // Updating and soft-deleting the master location MUST NOT alter shipment 1's snapshot.
        app(PreshipmentInspectionService::class)->updateLocation($location, $this->supplier, [
            'name' => 'Renamed Master Mill',
            'address' => '999 New Road',
            'city' => 'Suzhou',
            'country' => 'CN',
        ]);
        app(PreshipmentInspectionService::class)->deleteLocation($location->fresh(), $this->supplier);
        $this->assertSoftDeleted('supplier_goods_locations', ['id' => $location->id]);

        $snapshot1 = $shipment1->fresh()->preshipmentInspection;
        $this->assertSame('Wuxi Precision Steel Mill', $snapshot1->supplier_name);
        $this->assertSame('Wuxi', $snapshot1->supplier_city);
        $this->assertSame($location->id, $snapshot1->goods_location_id);
    }

    public function test_same_as_exporter_copies_on_server_and_ignores_tampered_block_two(): void
    {
        $po = $this->createPoFor($this->supplier);

        // Client tampers with supplier_* fields while same_as_exporter=1; server must overwrite them from exporter_*.
        $this->actingAs($this->supplier)
            ->post(route('supplier.shipments.store'), $this->baseShipmentPayload($po, [
                'action' => 'submit',
                'invoice_number' => 'INV-SAME-001',
                'preshipment' => $this->validPsiPayload([
                    'same_as_exporter' => '1',
                    'supplier_name' => 'TAMPERED FACTORY',
                    'supplier_address' => 'TAMPERED ADDR',
                    'supplier_city' => 'TAMPERED CITY',
                    'supplier_country' => 'ZZ', // invalid if not overwritten
                    'supplier_email' => 'not-an-email', // invalid if not overwritten
                ]),
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $snapshot = Shipment::where('invoice_number', 'INV-SAME-001')->firstOrFail()->preshipmentInspection;
        $this->assertTrue($snapshot->supplier_same_as_exporter);
        $this->assertNull($snapshot->goods_location_id);
        $this->assertSame('Shanghai Trading Co., Ltd.', $snapshot->supplier_name);
        $this->assertSame('Ms. Lin Wei', $snapshot->supplier_contact);
        $this->assertSame('100 Century Ave, Pudong', $snapshot->supplier_address);
        $this->assertSame('Shanghai', $snapshot->supplier_city);
        $this->assertSame('CN', $snapshot->supplier_country);
        $this->assertSame('export@shanghai-trade.example', $snapshot->supplier_email);
    }

    public function test_validation_rules_for_draft_vs_submit_phone_email_country_and_cjk_utf8(): void
    {
        $po = $this->createPoFor($this->supplier);

        // Draft allows partial PSI (required fields relaxed).
        $this->actingAs($this->supplier)
            ->post(route('supplier.shipments.store'), $this->baseShipmentPayload($po, [
                'action' => 'draft',
                'invoice_number' => 'INV-PARTIAL-DRAFT',
                'preshipment' => [
                    'exporter_name' => 'Partial Exporter Only',
                ],
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $partialDraft = Shipment::where('invoice_number', 'INV-PARTIAL-DRAFT')->firstOrFail();
        $this->assertSame(Shipment::STATUS_DRAFT, $partialDraft->status);

        // Quick-submitting an incomplete draft fails with PSI incomplete error.
        $this->actingAs($this->supplier)
            ->post(route('supplier.shipments.submit', $partialDraft))
            ->assertRedirect()
            ->assertSessionHas('error', __('shipments.preshipment.required_to_submit'));
        $this->assertSame(Shipment::STATUS_DRAFT, $partialDraft->fresh()->status);

        // Invalid email, phone, and non-ISO country fail validation even on draft.
        $this->actingAs($this->supplier)
            ->post(route('supplier.shipments.store'), $this->baseShipmentPayload($po, [
                'action' => 'submit',
                'preshipment' => $this->validPsiPayload([
                    'exporter_email' => 'bad-email',
                    'exporter_telephone' => 'abc',
                    'exporter_country' => 'Japan',
                    'exporter_port_of_loading' => '',
                ]),
            ]))
            ->assertSessionHasErrors([
                'preshipment.exporter_email',
                'preshipment.exporter_telephone',
                'preshipment.exporter_country',
                'preshipment.exporter_port_of_loading',
            ]);

        // CJK + non-Latin UTF-8 characters persist and render intact.
        $this->actingAs($this->supplier)
            ->post(route('supplier.shipments.store'), $this->baseShipmentPayload($po, [
                'action' => 'submit',
                'invoice_number' => 'INV-CJK-001',
                'preshipment' => $this->validPsiPayload([
                    'exporter_name' => '上海鋼鉄株式会社 / 한국철강',
                    'exporter_address' => '上海市浦東新区世紀大道100号',
                    'exporter_city' => '上海市',
                    'supplier_name' => '無錫精密工場',
                    'supplier_address' => '江蘇省無錫市新呉区88号',
                    'supplier_city' => '無錫市',
                ]),
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $cjkShipment = Shipment::where('invoice_number', 'INV-CJK-001')->firstOrFail();
        $this->assertSame('上海鋼鉄株式会社 / 한국철강', $cjkShipment->preshipmentInspection->exporter_name);
        $this->assertSame('江蘇省無錫市新呉区88号', $cjkShipment->preshipmentInspection->supplier_address);

        $this->actingAs($this->purchasing)
            ->get(route('purchasing.shipments.show', $cjkShipment))
            ->assertOk()
            ->assertSee('上海鋼鉄株式会社 / 한국철강')
            ->assertSee('江蘇省無錫市新呉区88号');
    }

    public function test_sales_contract_gate_blocks_psi_creation_and_update(): void
    {
        config(['procurement.require_sales_contract_before_shipment' => true]);

        $po = $this->createPoFor($this->supplier);
        $po->forceFill([
            'sales_contract_status' => PoSalesContractStatus::PENDING_SUPPLIER->value,
        ])->save();

        // Attempting to create draft with PSI when sales contract is unsigned must fail and NOT persist master profile.
        $this->actingAs($this->supplier)
            ->post(route('supplier.shipments.store'), $this->baseShipmentPayload($po, [
                'action' => 'draft',
                'invoice_number' => 'INV-SC-BLOCKED',
                'preshipment' => $this->validPsiPayload([
                    'save_exporter_default' => '1',
                    'location_action' => 'create',
                ]),
            ]))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('shipments', ['invoice_number' => 'INV-SC-BLOCKED']);
        $this->assertDatabaseMissing('supplier_preshipment_profiles', ['supplier_id' => $this->supplier->id]);
        $this->assertDatabaseMissing('supplier_goods_locations', ['supplier_id' => $this->supplier->id]);

        // Create a draft while gate is temporarily off, then re-enable gate -> PUT preshipment must be refused.
        config(['procurement.require_sales_contract_before_shipment' => false]);
        $draft = app(ShipmentService::class)->createDraft($this->supplier, $this->baseShipmentPayload($po));

        config(['procurement.require_sales_contract_before_shipment' => true]);
        $this->actingAs($this->supplier)
            ->put(route('supplier.shipments.preshipment.update', $draft), [
                'preshipment' => $this->validPsiPayload(['save_exporter_default' => '1']),
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNull($draft->fresh()->preshipmentInspection);
        $this->assertDatabaseMissing('supplier_preshipment_profiles', ['supplier_id' => $this->supplier->id]);
    }

    public function test_status_matrix_post_submit_revision_tracking_and_legacy_submitted_shipment(): void
    {
        $po = $this->createPoFor($this->supplier);

        // Create and submit a shipment with PSI.
        $draft = app(ShipmentService::class)->createDraft($this->supplier, $this->baseShipmentPayload($po, [
            'preshipment' => $this->validPsiPayload(),
        ]));
        $submitted = app(ShipmentService::class)->submitShipment($draft);
        $this->assertSame(0, (int) $submitted->preshipmentInspection->revision_count);

        // No-op edit while submitted: revision_count stays 0 and no revision notification sent.
        $this->actingAs($this->supplier)
            ->put(route('supplier.shipments.preshipment.update', $submitted), [
                'preshipment' => $this->validPsiPayload(),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $snapshot = $submitted->fresh()->preshipmentInspection;
        $this->assertSame(0, (int) $snapshot->revision_count);
        $this->assertNull($snapshot->last_revised_at);
        $this->assertFalse(
            $this->purchasing->notifications()->get()->contains(fn ($n) => ($n->data['event'] ?? '') === 'shipment.preshipment_revised')
        );

        // Real edit while submitted: increments revision_count, sets last_revised_at, notifies Purchasing, ignores master opt-in.
        $this->actingAs($this->supplier)
            ->put(route('supplier.shipments.preshipment.update', $submitted), [
                'preshipment' => $this->validPsiPayload([
                    'exporter_port_of_loading' => 'Ningbo Port',
                    'save_exporter_default' => '1',
                    'location_action' => 'create',
                ]),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $snapshot->refresh();
        $this->assertSame('Ningbo Port', $snapshot->exporter_port_of_loading);
        $this->assertSame(1, (int) $snapshot->revision_count);
        $this->assertNotNull($snapshot->last_revised_at);
        $this->assertSame($this->supplier->id, $snapshot->updated_by);
        $this->assertDatabaseMissing('supplier_preshipment_profiles', ['supplier_id' => $this->supplier->id]);
        $this->assertDatabaseMissing('supplier_goods_locations', ['supplier_id' => $this->supplier->id]);
        $this->assertTrue(
            $this->purchasing->notifications()->get()->contains(fn ($n) => ($n->data['event'] ?? '') === 'shipment.preshipment_revised')
        );

        // Both Supplier and Purchasing views render Revised (Rev. 1); Purchasing has NO edit button.
        $this->actingAs($this->supplier)
            ->get(route('supplier.shipments.show', $submitted))
            ->assertOk()
            ->assertSee('Revised (Rev. 1)')
            ->assertSee(__('shipments.preshipment.edit_psi'));

        $this->actingAs($this->purchasing)
            ->get(route('purchasing.shipments.show', $submitted))
            ->assertOk()
            ->assertSee('Revised (Rev. 1)')
            ->assertDontSee(__('shipments.preshipment.edit_psi'));

        // Arrived shipment locks PSI (refused under row lock in ShipmentService).
        $arrived = app(ShipmentService::class)->confirmArrival($submitted, $this->purchasing);
        $this->actingAs($this->supplier)
            ->put(route('supplier.shipments.preshipment.update', $arrived), [
                'preshipment' => $this->validPsiPayload(['exporter_port_of_loading' => 'Late Change']),
            ])
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->assertSame('Ningbo Port', $arrived->fresh()->preshipmentInspection->exporter_port_of_loading);

        // Cancelled shipment locks PSI (refused under row lock in ShipmentService).
        $cancelled = app(ShipmentService::class)->cancelShipment(
            app(ShipmentService::class)->createDraft($this->supplier, $this->baseShipmentPayload($po)),
            $this->supplier
        );
        $this->actingAs($this->supplier)
            ->put(route('supplier.shipments.preshipment.update', $cancelled), [
                'preshipment' => $this->validPsiPayload(),
            ])
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->assertNull($cancelled->fresh()->preshipmentInspection);

        // Legacy submitted shipment with NULL PSI: first fill sets revision_count = 0 (NOT a revision).
        config(['procurement.require_preshipment_for_shipment' => false]);
        $legacyDraft = app(ShipmentService::class)->createDraft($this->supplier, $this->baseShipmentPayload($po));
        $legacySubmitted = app(ShipmentService::class)->submitShipment($legacyDraft);
        config(['procurement.require_preshipment_for_shipment' => true]);
        $this->assertNull($legacySubmitted->preshipmentInspection);

        $this->purchasing->notifications()->delete();
        $this->actingAs($this->supplier)
            ->put(route('supplier.shipments.preshipment.update', $legacySubmitted), [
                'preshipment' => $this->validPsiPayload(),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $legacySnapshot = $legacySubmitted->fresh()->preshipmentInspection;
        $this->assertNotNull($legacySnapshot);
        $this->assertSame(0, (int) $legacySnapshot->revision_count);
        $this->assertNull($legacySnapshot->last_revised_at);
        $this->assertCount(0, $this->purchasing->notifications()->get());
    }

    public function test_async_submit_json_responses_for_store_and_update(): void
    {
        $po = $this->createPoFor($this->supplier);

        // Validation error returns 422 JSON with field errors.
        $this->actingAs($this->supplier)
            ->postJson(route('supplier.shipments.store'), $this->baseShipmentPayload($po, [
                'action' => 'submit',
                'preshipment' => ['exporter_name' => 'Incomplete'],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['preshipment.exporter_port_of_loading']);

        // Valid draft returns 200 JSON with redirect URL.
        $storeResponse = $this->actingAs($this->supplier)
            ->postJson(route('supplier.shipments.store'), $this->baseShipmentPayload($po, [
                'action' => 'draft',
                'invoice_number' => 'INV-ASYNC-001',
                'preshipment' => $this->validPsiPayload(),
            ]))
            ->assertOk()
            ->assertJsonStructure(['redirect']);

        $shipment = Shipment::where('invoice_number', 'INV-ASYNC-001')->firstOrFail();
        $this->assertStringContainsString($shipment->getRouteKey(), (string) $storeResponse->json('redirect'));

        // Valid update returns 200 JSON with redirect URL.
        $this->actingAs($this->supplier)
            ->putJson(route('supplier.shipments.update', $shipment), $this->baseShipmentPayload($po, [
                'action' => 'draft',
                'invoice_number' => 'INV-ASYNC-001',
                'preshipment' => $this->validPsiPayload(['exporter_port_of_loading' => 'Qingdao Port']),
            ]))
            ->assertOk()
            ->assertJsonStructure(['redirect']);

        $this->assertSame('Qingdao Port', $shipment->fresh()->preshipmentInspection->exporter_port_of_loading);
    }

    public function test_legacy_null_psi_draft_prefills_from_master_on_edit_form(): void
    {
        $po = $this->createPoFor($this->supplier);
        SupplierPreshipmentProfile::create([
            'supplier_id' => $this->supplier->id,
            'exporter_name' => 'Master Exporter Prefill',
            'exporter_city' => 'Tokyo',
            'exporter_country' => 'JP',
            'exporter_port_of_loading' => 'Tokyo Port',
        ]);
        SupplierGoodsLocation::create([
            'supplier_id' => $this->supplier->id,
            'name' => 'Master Factory Prefill',
            'address' => '1 Factory Way',
            'city' => 'Osaka',
            'country' => 'JP',
            'is_default' => true,
        ]);

        // Legacy draft without PSI row.
        $draft = app(ShipmentService::class)->createDraft($this->supplier, $this->baseShipmentPayload($po));
        $this->assertNull($draft->preshipmentInspection);

        $editHtml = $this->actingAs($this->supplier)
            ->get(route('supplier.shipments.edit', $draft))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Master Exporter Prefill', $editHtml);
        $this->assertStringContainsString('Master Factory Prefill', $editHtml);
    }

    public function test_failed_store_submit_rolls_back_draft_items_psi_and_master_profiles_atomically(): void
    {
        $po = $this->createPoFor($this->supplier);
        $quotationItem = $po->quotations->first()->items->first();

        // Request exceeds ordered/remaining qty (99 > 10) while opting into master saves.
        $payload = $this->baseShipmentPayload($po, [
            'action' => 'submit',
            'invoice_number' => 'INV-ATOMIC-ROLLBACK',
            'items' => [[
                'purchase_order_id' => $po->id,
                'quotation_item_id' => $quotationItem->id,
                'shipped_qty' => 99,
                'actual_weight_kg' => 297.0,
            ]],
            'preshipment' => $this->validPsiPayload([
                'save_exporter_default' => '1',
                'location_action' => 'create',
                'set_location_default' => '1',
            ]),
        ]);

        $this->actingAs($this->supplier)
            ->postJson(route('supplier.shipments.store'), $payload)
            ->assertUnprocessable();

        $this->assertDatabaseCount('shipments', 0);
        $this->assertDatabaseCount('shipment_items', 0);
        $this->assertDatabaseCount('shipment_preshipment_inspections', 0);
        $this->assertDatabaseMissing('supplier_preshipment_profiles', ['supplier_id' => $this->supplier->id]);
        $this->assertDatabaseMissing('supplier_goods_locations', ['supplier_id' => $this->supplier->id]);
    }

    public function test_draft_edit_psi_allows_empty_clear_and_rejects_partial_master_opt_in(): void
    {
        $po = $this->createPoFor($this->supplier);
        $draft = app(ShipmentService::class)->createDraft($this->supplier, $this->baseShipmentPayload($po, [
            'preshipment' => $this->validPsiPayload(),
        ]));
        $this->assertNotNull($draft->preshipmentInspection);

        // Clearing all PSI fields on a Draft succeeds without throwing required_to_submit.
        $this->actingAs($this->supplier)
            ->putJson(route('supplier.shipments.preshipment.update', $draft), [
                'preshipment' => [
                    'exporter_name' => '',
                    'exporter_port_of_loading' => '',
                    'exporter_address' => '',
                    'exporter_city' => '',
                    'exporter_country' => '',
                    'supplier_name' => '',
                    'supplier_address' => '',
                    'supplier_city' => '',
                    'supplier_country' => '',
                ],
            ])
            ->assertOk()
            ->assertJsonStructure(['redirect']);

        $this->assertNull($draft->fresh()->preshipmentInspection);

        // Partial Block 2 input + location_action=create fails explicitly instead of silently skipping master save.
        $this->actingAs($this->supplier)
            ->putJson(route('supplier.shipments.preshipment.update', $draft), [
                'preshipment' => [
                    'supplier_name' => 'Incomplete Plant',
                    'location_action' => 'create',
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'preshipment.supplier_address',
                'preshipment.supplier_city',
                'preshipment.supplier_country',
            ]);
    }

    public function test_custom_edit_with_location_action_none_detaches_goods_location_id_on_snapshot(): void
    {
        $po = $this->createPoFor($this->supplier);
        $location = SupplierGoodsLocation::create([
            'supplier_id' => $this->supplier->id,
            'name' => 'Wuxi Original Plant',
            'address' => '100 Old Road',
            'city' => 'Wuxi',
            'country' => 'CN',
            'is_default' => true,
        ]);

        $this->actingAs($this->supplier)
            ->post(route('supplier.shipments.store'), $this->baseShipmentPayload($po, [
                'action' => 'submit',
                'invoice_number' => 'INV-CUSTOM-LOC-01',
                'preshipment' => $this->validPsiPayload([
                    'goods_location' => $location->getRouteKey(),
                    'location_action' => 'none',
                    'supplier_name' => 'Wuxi Original Plant',
                    'supplier_address' => '999 Temporary Warehouse Ln',
                    'supplier_city' => 'Suzhou',
                ]),
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $shipment = Shipment::where('invoice_number', 'INV-CUSTOM-LOC-01')->firstOrFail();
        $snapshot = ShipmentPreshipmentInspection::where('shipment_id', $shipment->id)->firstOrFail();
        $this->assertNull($snapshot->goods_location_id);
        $this->assertSame('Wuxi Original Plant', $snapshot->supplier_name);
        $this->assertSame('Suzhou', $snapshot->supplier_city);
        $this->assertSame('100 Old Road', $location->fresh()->address);
    }

    public function test_legacy_country_normalizes_on_prefill_and_shows_notice_when_unmapped(): void
    {
        $po = $this->createPoFor($this->supplier);
        $draft = app(ShipmentService::class)->createDraft($this->supplier, $this->baseShipmentPayload($po));

        // Simulate legacy free-text countries saved before ISO standardization.
        ShipmentPreshipmentInspection::create([
            'shipment_id' => $draft->id,
            'exporter_name' => 'Legacy Exporter',
            'exporter_address' => '1 Port Rd',
            'exporter_city' => 'Tokyo',
            'exporter_country' => 'Japan',
            'exporter_port_of_loading' => 'Tokyo Port',
            'supplier_name' => 'Unknown Mill',
            'supplier_address' => '99 Mystery Blvd',
            'supplier_city' => 'Unknown',
            'supplier_country' => 'Atlantis',
        ]);

        $prefill = app(PreshipmentInspectionService::class)->prefillFor($this->supplier, $draft->fresh('preshipmentInspection'));
        $this->assertSame('JP', $prefill['values']['exporter_country']);
        $this->assertNull($prefill['values']['supplier_country']);
        $this->assertSame(['supplier_country' => 'Atlantis'], $prefill['legacy_unmapped_countries']);

        $showHtml = $this->actingAs($this->supplier)
            ->get(route('supplier.shipments.show', $draft))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Atlantis', $showHtml);
    }

    public function test_advanced_export_shipment_definition_includes_optional_psi_columns(): void
    {
        $po = $this->createPoFor($this->supplier);
        $this->actingAs($this->supplier)
            ->post(route('supplier.shipments.store'), $this->baseShipmentPayload($po, [
                'action' => 'submit',
                'invoice_number' => 'INV-EXPORT-PSI-01',
                'preshipment' => $this->validPsiPayload([
                    'exporter_name' => 'Export Corp Tokyo',
                    'exporter_country' => 'JP',
                    'exporter_port_of_loading' => 'Yokohama',
                    'supplier_name' => 'Osaka Works',
                    'supplier_city' => 'Osaka',
                    'supplier_country' => 'JP',
                ]),
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $export = new ShipmentsExport;
        $export->applyOptions(new ExportOptions(
            ['shipment_number', 'psi_exporter_name', 'psi_port_of_loading', 'psi_location_name', 'psi_same_as_exporter'],
            'xlsx',
            'purchasing'
        ));
        $export->setExportLocale('en');

        $loads = array_keys($export->query()->getEagerLoads());
        $this->assertContains('preshipmentInspection', $loads);
        $this->assertSame([
            'Shipment Number',
            'PSI Exporter Name',
            'PSI Port of Loading',
            'PSI Location of Goods',
            'PSI Same as Exporter',
        ], $export->headings());

        $row = $export->collection()->first();
        $this->assertSame('Export Corp Tokyo', $row[1]);
        $this->assertSame('Yokohama', $row[2]);
        $this->assertSame('Osaka Works', $row[3]);
        $this->assertSame('No', $row[4]);
    }

    public function test_shipment_create_renders_in_dialog_manage_locations_without_new_tab_link(): void
    {
        $this->createPoFor($this->supplier);

        $response = $this->actingAs($this->supplier)
            ->get(route('supplier.shipments.create'))
            ->assertOk()
            ->assertSee(__('shipments.preshipment.exporter_short_title'))
            ->assertSee(__('shipments.preshipment.exporter_role_subtitle'))
            ->assertSee(__('shipments.preshipment.back_to_psi'))
            ->assertSee(__('shipments.preshipment.manage_locations_locked_hint'));

        $html = $response->getContent();
        $this->assertDoesNotMatchRegularExpression('/<a\b[^>]*goods-locations/i', $html);
        $this->assertStringContainsString('openManageLocations', $html);
    }
}
