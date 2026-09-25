<?php

namespace Tests\Feature;

use App\Exports\ShipmentsExport;
use App\Models\User;
use App\Services\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShipmentUiAndExportTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasing;

    private User $supplierA;

    private User $supplierB;

    private ShipmentService $shipmentService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $this->supplierA = User::factory()->create(['role' => 'supplier', 'is_active' => true, 'name' => 'Supplier Alpha']);
        $this->supplierB = User::factory()->create(['role' => 'supplier', 'is_active' => true, 'name' => 'Supplier Beta']);
        $this->shipmentService = app(ShipmentService::class);
    }

    public function test_purchasing_shipments_index_ajax_returns_datatables_json(): void
    {
        $shipment = $this->shipmentService->createDraft($this->supplierA, [
            'notes' => 'Test Consignment',
            'shipment_date' => now()->toDateString(),
            'estimated_arrival_date' => now()->addDays(10)->toDateString(),
        ]);

        $response = $this->actingAs($this->purchasing)
            ->getJson(route('purchasing.shipments.index'), [
                'X-Requested-With' => 'XMLHttpRequest',
            ]);

        $response->assertOk()
            ->assertJsonStructure([
                'draw',
                'recordsTotal',
                'recordsFiltered',
                'data' => [
                    '*' => [
                        'shipment_number_display',
                        'supplier_name',
                        'consolidated_pos',
                        'items_count',
                        'total_weight',
                        'shipment_date',
                        'estimated_arrival',
                        'actual_arrival',
                        'status_badge',
                        'action',
                    ],
                ],
            ]);

        $this->assertStringContainsString($shipment->shipment_number, $response->json('data.0.shipment_number_display'));
        $this->assertStringContainsString('Supplier Alpha', $response->json('data.0.supplier_name'));
    }

    public function test_supplier_shipments_index_ajax_enforces_supplier_isolation(): void
    {
        $shipmentA = $this->shipmentService->createDraft($this->supplierA, [
            'notes' => 'Supplier A shipment',
        ]);
        $shipmentB = $this->shipmentService->createDraft($this->supplierB, [
            'notes' => 'Supplier B shipment',
        ]);

        $response = $this->actingAs($this->supplierA)
            ->getJson(route('supplier.shipments.index'), [
                'X-Requested-With' => 'XMLHttpRequest',
            ]);

        $response->assertOk();
        $data = $response->json('data');

        $this->assertCount(1, $data);
        $this->assertStringContainsString($shipmentA->shipment_number, $data[0]['shipment_number_display']);
        $this->assertStringNotContainsString($shipmentB->shipment_number, json_encode($data));
    }

    public function test_supplier_shipments_index_draft_action_buttons_render_submit_and_more_dropdown(): void
    {
        $draft = $this->shipmentService->createDraft($this->supplierA, [
            'notes' => 'Draft consignment action test',
        ]);

        $response = $this->actingAs($this->supplierA)
            ->getJson(route('supplier.shipments.index'), [
                'X-Requested-With' => 'XMLHttpRequest',
            ]);

        $response->assertOk();
        $actionHtml = $response->json('data.0.action');

        $this->assertStringContainsString('btn-submit-draft', $actionHtml);
        $this->assertStringContainsString('Submit', $actionHtml);
        $this->assertStringContainsString('More', $actionHtml);
        $this->assertStringContainsString('View details', $actionHtml);
        $this->assertStringContainsString('Edit draft', $actionHtml);
        $this->assertStringContainsString('Cancel shipment', $actionHtml);

        // Verify SSR view also renders the actions
        $ssrResponse = $this->actingAs($this->supplierA)->get(route('supplier.shipments.index'));
        $ssrResponse->assertOk()
            ->assertSee('Submit')
            ->assertSee('Edit draft')
            ->assertSee('Cancel shipment');
    }

    public function test_purchasing_can_dispatch_shipments_excel_export(): void
    {
        $response = $this->actingAs($this->purchasing)
            ->getJson(route('purchasing.export.shipments', [
                'status' => 'draft',
            ]), [
                'X-Requested-With' => 'XMLHttpRequest',
            ]);

        $response->assertStatus(202)
            ->assertJsonStructure([
                'message',
                'export_job_id',
                'exports_url',
                'status_url',
                'cancel_url',
            ]);

        $this->assertDatabaseHas('export_jobs', [
            'export_class' => ShipmentsExport::class,
            'user_id' => $this->purchasing->id,
        ]);
    }

    public function test_shipments_export_produces_mapped_rows(): void
    {
        $shipment = $this->shipmentService->createDraft($this->supplierA, [
            'notes' => 'Export test consignment notes',
            'shipment_date' => now()->toDateString(),
            'estimated_arrival_date' => now()->addDays(7)->toDateString(),
        ]);

        $export = new ShipmentsExport(
            supplierId: $this->supplierA->id,
            status: 'draft',
            search: 'Export test consignment'
        );

        $collection = $export->collection();
        $this->assertCount(1, $collection);

        $row = $collection->first();
        $this->assertSame($shipment->shipment_number, $row[0]);
        $this->assertSame('Supplier Alpha', $row[1]);
        $this->assertSame('Draft', $row[9]);
        $this->assertSame('Export test consignment notes', $row[10]);
    }

    public function test_purchasing_shipments_index_filters_by_shipment_date_range(): void
    {
        $shpEarly = $this->shipmentService->createDraft($this->supplierA, [
            'notes' => 'Early Shipment',
            'shipment_date' => '2026-05-10',
            'estimated_arrival_date' => '2026-05-20',
        ]);
        $shpLate = $this->shipmentService->createDraft($this->supplierA, [
            'notes' => 'Late Shipment',
            'shipment_date' => '2026-08-15',
            'estimated_arrival_date' => '2026-08-25',
        ]);

        $response = $this->actingAs($this->purchasing)
            ->getJson(route('purchasing.shipments.index', [
                'date_from' => '2026-08-01',
                'date_to' => '2026-08-31',
            ]), [
                'X-Requested-With' => 'XMLHttpRequest',
            ]);

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertStringContainsString($shpLate->shipment_number, $data[0]['shipment_number_display']);
        $this->assertStringNotContainsString($shpEarly->shipment_number, json_encode($data));
    }

    public function test_supplier_shipments_index_filters_by_shipment_date_range(): void
    {
        $shpEarly = $this->shipmentService->createDraft($this->supplierA, [
            'notes' => 'Supplier Early Shipment',
            'shipment_date' => '2026-05-10',
            'estimated_arrival_date' => '2026-05-20',
        ]);
        $shpLate = $this->shipmentService->createDraft($this->supplierA, [
            'notes' => 'Supplier Late Shipment',
            'shipment_date' => '2026-08-15',
            'estimated_arrival_date' => '2026-08-25',
        ]);

        $response = $this->actingAs($this->supplierA)
            ->getJson(route('supplier.shipments.index', [
                'date_from' => '2026-08-01',
                'date_to' => '2026-08-31',
            ]), [
                'X-Requested-With' => 'XMLHttpRequest',
            ]);

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertStringContainsString($shpLate->shipment_number, $data[0]['shipment_number_display']);
        $this->assertStringNotContainsString($shpEarly->shipment_number, json_encode($data));
    }

    public function test_custom_calendar_components_are_rendered_in_shipment_views(): void
    {
        $createBlade = file_get_contents(resource_path('views/supplier/shipments/create.blade.php'));
        $this->assertStringContainsString('x-ui.date-picker', $createBlade);
        $this->assertStringContainsString('id="shipmentDate"', $createBlade);
        $this->assertStringContainsString('id="estimatedArrivalDate"', $createBlade);

        $shipment = $this->shipmentService->createDraft($this->supplierA, [
            'notes' => 'Test Show Consignment',
        ]);
        $shipment->update(['status' => 'submitted']);
        $showResponse = $this->actingAs($this->purchasing)->get(route('purchasing.shipments.show', $shipment));
        $showResponse->assertOk()
            ->assertSee('data-adasi-date-picker', false)
            ->assertSee('id="actual_arrival_date"', false);

        $purchasingIndexResponse = $this->actingAs($this->purchasing)->get(route('purchasing.shipments.index'));
        $purchasingIndexResponse->assertOk()
            ->assertSee('id="shipmentDateRange"', false)
            ->assertSee('data-adasi-date-range', false);

        $supplierIndexResponse = $this->actingAs($this->supplierA)->get(route('supplier.shipments.index'));
        $supplierIndexResponse->assertOk()
            ->assertSee('id="supplierShipmentDateRange"', false)
            ->assertSee('data-adasi-date-range', false);
    }

    public function test_supplier_shipment_submission_triggers_confirmation_alert_guard(): void
    {
        $createBlade = file_get_contents(resource_path('views/supplier/shipments/create.blade.php'));
        $this->assertStringContainsString('id="btnSubmitShipment"', $createBlade);
        $this->assertStringContainsString('window.AdasiAlert.confirm', $createBlade);
        $this->assertStringContainsString('Submit Shipment Delivery?', $createBlade);
        $this->assertStringContainsString("actionInput.value = 'submit'", $createBlade);

        $showBlade = file_get_contents(resource_path('views/supplier/shipments/show.blade.php'));
        $this->assertStringContainsString('id="btnConfirmSubmit"', $showBlade);
        $this->assertStringContainsString('window.AdasiAlert.confirm', $showBlade);
        $this->assertStringContainsString('Submit Shipment Delivery?', $showBlade);

        $indexBlade = file_get_contents(resource_path('views/supplier/shipments/index.blade.php'));
        $this->assertStringContainsString('btn-submit-draft', $indexBlade);
        $this->assertStringContainsString('AdasiAlert.confirm', $indexBlade);
        $this->assertStringContainsString('Submit Shipment Delivery?', $indexBlade);
    }
}
