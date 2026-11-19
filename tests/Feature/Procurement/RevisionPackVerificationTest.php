<?php

namespace Tests\Feature\Procurement;

use App\Enums\PoAcknowledgementStatus;
use App\Enums\PoSalesContractStatus;
use App\Exceptions\BusinessRuleArgumentException;
use App\Exports\PrFormExport;
use App\Exports\PrImportTemplateExport;
use App\Exports\QuotationFormExport;
use App\Exports\QuotationImportTemplateExport;
use App\Exports\ShipmentsExport;
use App\Models\ExchangeRate;
use App\Models\ExportJob;
use App\Models\PoItemProgressUpdate;
use App\Models\PrItem;
use App\Models\PurchaseOrder;
use App\Models\ReferencePriceRule;
use App\Models\Shipment;
use App\Models\ShipmentDocument;
use App\Models\Supplier;
use App\Models\SupplierPreshipmentProfile;
use App\Models\User;
use App\Services\PrItemAwardService;
use App\Services\Procurement\OutstandingMaterialQueryService;
use App\Services\PurchaseOrderGenerationService;
use App\Services\ShipmentService;
use App\Support\BusinessTime;
use App\Support\Export\ExportDefinitions;
use App\Support\Procurement\LogisticsTerms;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\Support\AttachesShipmentMtc;
use Tests\TestCase;

class RevisionPackVerificationTest extends TestCase
{
    use AttachesShipmentMtc;
    use ProcurementFixtures;
    use RefreshDatabase;

    private User $supplierB;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        $this->setUpProcurementActors();
        $this->supplier->update(['name' => 'Alpha Steel Ltd']);
        Supplier::create([
            'user_id' => $this->supplier->id,
            'company_name' => 'Alpha Steel Co',
        ]);

        $this->supplierB = User::factory()->create([
            'role' => 'supplier',
            'name' => 'Beta Metals Ltd',
            'is_active' => true,
        ]);
        Supplier::create([
            'user_id' => $this->supplierB->id,
            'company_name' => 'Beta Metals Co',
        ]);
    }

    private function fakePdf(string $name = 'document.pdf'): UploadedFile
    {
        $body = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n";
        $xrefOffset = strlen($body);
        $content = $body."xref\n0 2\n0000000000 65535 f \n0000000009 00000 n \ntrailer\n<< /Size 2 /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF\n";

        return UploadedFile::fake()->createWithContent($name, $content);
    }

    private function generatePoForSupplier(User $supplier, array $offers = [], array $overrides = []): PurchaseOrder
    {
        $pr = $this->createRequisition([
            ['quantity' => 10, 'weight_needed' => 2],
        ]);
        if (! empty($overrides['material_name']) || ! empty($overrides['shape'])) {
            $pr->items[0]->forceFill([
                'material_name' => $overrides['material_name'] ?? $pr->items[0]->material_name,
                'shape' => $overrides['shape'] ?? $pr->items[0]->shape,
                'thickness' => $overrides['thickness'] ?? $pr->items[0]->thickness,
                'd_outer' => $overrides['d_outer'] ?? $pr->items[0]->d_outer,
            ])->save();
        }
        if (! empty($overrides['created_at'])) {
            $pr->forceFill(['created_at' => $overrides['created_at']])->save();
        }

        $quotation = $this->createQuotation($pr, $offers ?: [['price_per_kg' => 50000]], supplier: $supplier);
        if (! empty($overrides['idr'])) {
            $quotation->forceFill(['currency' => 'IDR', 'exchange_rate_id' => null])->save();
        }

        $awards = app(PrItemAwardService::class)->awardBatch(
            $pr,
            [$pr->items[0]->id => $quotation->items[0]->id],
            $this->purchasing
        );

        /** @var PurchaseOrder $po */
        $po = app(PurchaseOrderGenerationService::class)
            ->generateFromAwards($awards->pluck('id')->all(), $this->purchasing, [
                'estimated_arrival' => now()->addDays(21)->toDateString(),
                'port_of_discharge' => $overrides['port_of_discharge'] ?? array_key_first(LogisticsTerms::portOptions()),
            ])
            ->firstOrFail();

        if (! empty($overrides['status'])) {
            $po->forceFill(['status' => $overrides['status']])->save();
        }
        if (! empty($overrides['created_at'])) {
            $po->forceFill(['created_at' => $overrides['created_at']])->save();
        }

        return $po->fresh(['awards.quotationItem', 'awards.prItem', 'quotations']);
    }

    public function test_a1_excel_exports_apply_distinct_fills_to_mandatory_vs_optional_headers(): void
    {
        $pr = $this->createRequisition([['quantity' => 5, 'weight_needed' => 2]]);
        $prItem = $pr->items->first();

        $exports = [
            'pr_template' => [
                'export' => new PrImportTemplateExport,
                'required' => 'A1',
                'conditional' => 'C1',
                'optional' => 'I1',
            ],
            'pr_form' => [
                'export' => new PrFormExport([['material_name' => 'SCM440', 'shape' => 'Round', 'd_outer' => 20, 'length' => 1000, 'quantity' => 5]]),
                'required' => 'A1',
                'conditional' => 'C1',
                'optional' => 'I1',
            ],
            'quotation_template' => [
                'export' => new QuotationImportTemplateExport($pr->id),
                'required' => 'A1',
                'conditional' => 'D1',
                'optional' => 'L1',
            ],
            'quotation_form' => [
                'export' => new QuotationFormExport($pr->fresh('items'), [['pr_item_id' => $prItem->id, 'is_available' => true, 'available_qty' => 5]]),
                'required' => 'K1',
                'conditional' => 'J1',
                'optional' => 'L1',
            ],
        ];

        foreach ($exports as $name => $spec) {
            $sheet = (new Spreadsheet)->getActiveSheet();
            $spec['export']->styles($sheet);

            $this->assertSame(
                PrImportTemplateExport::REQUIRED_HEADER_FILL,
                $sheet->getStyle($spec['required'])->getFill()->getStartColor()->getRGB(),
                "{$name} required cell {$spec['required']}"
            );
            $this->assertSame(
                PrImportTemplateExport::CONDITIONAL_HEADER_FILL,
                $sheet->getStyle($spec['conditional'])->getFill()->getStartColor()->getRGB(),
                "{$name} conditional cell {$spec['conditional']}"
            );
            $this->assertSame(
                PrImportTemplateExport::OPTIONAL_HEADER_FILL,
                $sheet->getStyle($spec['optional'])->getFill()->getStartColor()->getRGB(),
                "{$name} optional cell {$spec['optional']}"
            );
        }
    }

    public function test_b1_to_b5_inter_supplier_best_total_follows_lowest_price_per_kg_and_benchmark_period_filters(): void
    {
        $idrRate = ExchangeRate::create([
            'currency' => 'IDR',
            'rate_to_idr' => 1,
            'valid_from' => now()->subYears(2)->toDateString(),
            'created_by' => $this->purchasing->id,
        ]);

        // Historical POs across two years/months for B5
        $this->generatePoForSupplier($this->supplier, [['price_per_kg' => 12345]], [
            'material_name' => 'BENCH_STEEL_B5',
            'shape' => PrItem::SHAPE_FLAT,
            'thickness' => 10,
            'idr' => true,
            'status' => 'completed',
            'created_at' => BusinessTime::parseDate('2025-03-10')->setTime(10, 0),
        ]);
        $this->generatePoForSupplier($this->supplierB, [['price_per_kg' => 67890]], [
            'material_name' => 'BENCH_STEEL_B5',
            'shape' => PrItem::SHAPE_FLAT,
            'thickness' => 10,
            'idr' => true,
            'status' => 'completed',
            'created_at' => BusinessTime::parseDate('2026-10-10')->setTime(10, 0),
        ]);

        // PR with 2 items: total weights 100 kg and 1 kg
        $pr = $this->createRequisition([
            ['quantity' => 10, 'weight_needed' => 10],
            ['quantity' => 1, 'weight_needed' => 1],
        ]);
        $pr->items[0]->forceFill(['material_name' => 'BENCH_STEEL_B5', 'shape' => PrItem::SHAPE_FLAT, 'thickness' => 10])->save();
        $pr->items[1]->forceFill(['material_name' => 'OTHER_STEEL_B5', 'shape' => PrItem::SHAPE_FLAT, 'thickness' => 10])->save();

        // Alpha: 100/kg + 400/kg => Total Price/kg = 500 IDR/kg, Estimated Total = 10,400 IDR
        $qA = $this->createQuotation($pr, [
            ['price_per_kg' => 100, 'available_qty' => 10, 'offered_weight_per_unit' => 10],
            ['price_per_kg' => 400, 'available_qty' => 1, 'offered_weight_per_unit' => 1],
        ], supplier: $this->supplier);
        $qA->forceFill(['currency' => 'IDR', 'exchange_rate_id' => $idrRate->id])->save();

        // Beta: 50/kg + 500/kg => Total Price/kg = 550 IDR/kg, Estimated Total = 5,500 IDR (cheaper overall, costlier per kg!)
        $qB = $this->createQuotation($pr, [
            ['price_per_kg' => 50, 'available_qty' => 10, 'offered_weight_per_unit' => 10],
            ['price_per_kg' => 500, 'available_qty' => 1, 'offered_weight_per_unit' => 1],
        ], supplier: $this->supplierB);
        $qB->forceFill(['currency' => 'IDR', 'exchange_rate_id' => $idrRate->id])->save();

        $response = $this->actingAs($this->purchasing)
            ->get(route('purchasing.comparison.inter-supplier', [
                'pr_id' => $pr->getRouteKey(),
                'benchmark_year' => 2025,
                'benchmark_month' => 3,
            ]))
            ->assertOk();

        $chartData = $response->viewData('chartData');
        $datasets = collect($chartData['datasets'])->keyBy('quotation_id');

        $this->assertSame(500.0, (float) $datasets[$qA->id]['total_price_per_kg_idr']);
        $this->assertTrue($datasets[$qA->id]['is_best_total']);
        $this->assertSame(550.0, (float) $datasets[$qB->id]['total_price_per_kg_idr']);
        $this->assertFalse($datasets[$qB->id]['is_best_total']);

        $html2025 = $response->getContent();
        $comparison2025 = $response->viewData('comparison');
        $this->assertSame('12345.0000', $comparison2025['matrix'][0]['indicators']['history']['weighted_average']);
        $this->assertStringContainsString('12,345', $html2025);
        $this->assertStringNotContainsString('67,890', $html2025);

        $response2026 = $this->actingAs($this->purchasing)
            ->get(route('purchasing.comparison.inter-supplier', [
                'pr_id' => $pr->getRouteKey(),
                'benchmark_year' => 2026,
                'benchmark_month' => 10,
            ]))
            ->assertOk();
        $this->assertSame('67890.0000', $response2026->viewData('comparison')['matrix'][0]['indicators']['history']['weighted_average']);

        // B5: Month-only filter (without year) filters across all years instead of being silently ignored
        $responseMonthOnly = $this->actingAs($this->purchasing)
            ->get(route('purchasing.comparison.inter-supplier', [
                'pr_id' => $pr->getRouteKey(),
                'benchmark_month' => 3,
            ]))
            ->assertOk();
        $this->assertSame('12345.0000', $responseMonthOnly->viewData('comparison')['matrix'][0]['indicators']['history']['weighted_average']);

        // B5: Out-of-range benchmark_year is normalized safely without throwing a database error
        $this->actingAs($this->purchasing)
            ->get(route('purchasing.comparison.inter-supplier', [
                'pr_id' => $pr->getRouteKey(),
                'benchmark_year' => 99999,
            ]))
            ->assertOk();

        // Commercial Overview Best Total badge stays next to Alpha Steel Co row
        $alphaPos = strpos($html2025, 'Alpha Steel Co');
        $bestBadgePos = strpos($html2025, __('purchasing.copy.best_total'));
        $betaPos = strpos($html2025, 'Beta Metals Co');
        $this->assertNotFalse($alphaPos);
        $this->assertNotFalse($bestBadgePos);
        $this->assertNotFalse($betaPos);
        $this->assertTrue($alphaPos < $bestBadgePos && $bestBadgePos < $betaPos);
    }

    public function test_c1_and_c2_reference_prices_historical_tab_shows_completed_pos_with_dimension_and_range_classification(): void
    {
        ReferencePriceRule::create([
            'category_key' => 'small_tier',
            'category_name' => 'Small Tier',
            'dimension_min' => 1,
            'dimension_max' => 10,
            'sort_order' => 1,
            'status' => ReferencePriceRule::STATUS_ACTIVE,
            'created_by' => $this->purchasing->id,
        ]);
        ReferencePriceRule::create([
            'category_key' => 'medium_tier',
            'category_name' => 'Medium Tier',
            'dimension_min' => 11,
            'dimension_max' => 50,
            'sort_order' => 2,
            'status' => ReferencePriceRule::STATUS_ACTIVE,
            'created_by' => $this->purchasing->id,
        ]);

        $completedFlatPo = $this->generatePoForSupplier($this->supplier, [['price_per_kg' => 45000]], [
            'material_name' => 'HIST_FLAT_P20',
            'shape' => PrItem::SHAPE_FLAT,
            'thickness' => 2.5,
            'idr' => true,
            'status' => 'completed',
        ]);
        $completedRoundPo = $this->generatePoForSupplier($this->supplier, [['price_per_kg' => 55000]], [
            'material_name' => 'HIST_ROUND_SCM',
            'shape' => PrItem::SHAPE_ROUND,
            'd_outer' => 25,
            'idr' => true,
            'status' => 'completed',
        ]);
        $activePo = $this->generatePoForSupplier($this->supplier, [['price_per_kg' => 65000]], [
            'material_name' => 'ACTIVE_ONLY_MATERIAL',
            'shape' => PrItem::SHAPE_FLAT,
            'thickness' => 5,
            'idr' => true,
            'status' => 'active',
        ]);

        // Default po_status is 'completed', both tab=benchmark and legacy tab=historical resolve here
        foreach (['benchmark', 'historical'] as $tab) {
            $html = $this->actingAs($this->purchasing)
                ->get(route('purchasing.master-data.reference-prices.index', ['tab' => $tab]))
                ->assertOk()
                ->getContent();

            $this->assertStringContainsString($completedFlatPo->po_number, $html);
            $this->assertStringContainsString('HIST_FLAT_P20', $html);
            $this->assertStringContainsString('2.5 mm', $html);
            $this->assertStringContainsString('Small Tier', $html);

            $this->assertStringContainsString($completedRoundPo->po_number, $html);
            $this->assertStringContainsString('HIST_ROUND_SCM', $html);
            $this->assertStringContainsString('Ø 25 mm', $html);
            $this->assertStringContainsString('Medium Tier', $html);

            $this->assertStringNotContainsString($activePo->po_number, $html);
        }

        // po_status=all includes active PO as well
        $allHtml = $this->actingAs($this->purchasing)
            ->get(route('purchasing.master-data.reference-prices.index', ['tab' => 'benchmark', 'po_status' => 'all']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($activePo->po_number, $allHtml);
        $this->assertStringContainsString('ACTIVE_ONLY_MATERIAL', $allHtml);

        // C2 CRIT-01 regression: Server-Tabs AJAX fragment request on tab=benchmark renders without missing $regionalFormatter
        $ajaxJson = $this->actingAs($this->purchasing)
            ->withHeaders(['X-Adasi-Server-Tabs' => '1'])
            ->getJson(route('purchasing.master-data.reference-prices.index', ['tab' => 'benchmark']))
            ->assertOk()
            ->json();

        $this->assertSame('benchmark', $ajaxJson['tab'] ?? null);
        $this->assertStringContainsString($completedFlatPo->po_number, (string) ($ajaxJson['html'] ?? ''));
        $this->assertStringContainsString('Small Tier', (string) ($ajaxJson['html'] ?? ''));
    }

    public function test_d2_po_generation_requires_and_persists_port_of_discharge_confirmation(): void
    {
        $pr = $this->createRequisition([['quantity' => 10, 'weight_needed' => 2]]);
        $quotation = $this->createQuotation($pr);
        $ports = array_keys(LogisticsTerms::portOptions());
        $chosenPort = $ports[1] ?? $ports[0];

        // HIGH-01 regression: omitting both confirm_port_of_discharge and any client marker cannot bypass validation
        $this->actingAs($this->purchasing)
            ->post(route('purchasing.comparison.save-awards'), [
                'pr_id' => $pr->getRouteKey(),
                'action' => 'generate_pos',
                'awards' => [$pr->items[0]->id => $quotation->items[0]->id],
            ])
            ->assertSessionHasErrors(['port_of_discharge', 'confirm_port_of_discharge']);

        $this->actingAs($this->purchasing)
            ->post(route('purchasing.comparison.save-awards'), [
                'pr_id' => $pr->getRouteKey(),
                'action' => 'generate_pos',
                'awards' => [$pr->items[0]->id => $quotation->items[0]->id],
                'port_of_discharge' => $chosenPort,
            ])
            ->assertSessionHasErrors('confirm_port_of_discharge');

        $this->assertDatabaseCount('purchase_orders', 0);

        $this->actingAs($this->purchasing)
            ->post(route('purchasing.comparison.save-awards'), [
                'pr_id' => $pr->getRouteKey(),
                'action' => 'generate_pos',
                'awards' => [$pr->items[0]->id => $quotation->items[0]->id],
                'port_of_discharge' => $chosenPort,
                'confirm_port_of_discharge' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('purchase_orders', [
            'port_of_discharge' => $chosenPort,
        ]);

        // HIGH-01 regression on consolidate-awards.store: omitting port confirmation is rejected
        $pr2 = $this->createRequisition([['quantity' => 3, 'weight_needed' => 2]]);
        $quotation2 = $this->createQuotation($pr2, [['price_per_kg' => 62000]], supplier: $this->supplier);
        $award2 = app(PrItemAwardService::class)
            ->awardBatch($pr2, [$pr2->items[0]->id => $quotation2->items[0]->id], $this->purchasing)
            ->firstOrFail();

        $this->actingAs($this->purchasing)
            ->post(route('purchasing.purchase-orders.consolidate-awards.store'), [
                'award_ids' => [$award2->id],
                'estimated_arrival' => now()->addDays(14)->toDateString(),
            ])
            ->assertSessionHasErrors(['port_of_discharge', 'confirm_port_of_discharge']);

        $this->actingAs($this->purchasing)
            ->post(route('purchasing.purchase-orders.consolidate-awards.store'), [
                'award_ids' => [$award2->id],
                'estimated_arrival' => now()->addDays(14)->toDateString(),
                'port_of_discharge' => $chosenPort,
                'confirm_port_of_discharge' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    public function test_d3_po_document_endpoints_return_403_and_po_show_is_read_only_when_flag_enabled(): void
    {
        config([
            'procurement.po_document_status_read_only' => true,
            'procurement.require_sales_contract_before_shipment' => false,
            'procurement.require_ready_to_ship_for_shipment' => false,
            'procurement.require_commercial_invoice_for_shipment' => false,
            'procurement.shipment_mtc_enabled' => false,
        ]);

        $po = $this->generatePoForSupplier($this->supplier);
        $award = $po->awards->first();
        $shipment = app(ShipmentService::class)->createDraft($this->supplier);
        app(ShipmentService::class)->syncDraftItems($shipment, [[
            'purchase_order_id' => $po->id,
            'quotation_item_id' => $award->quotation_item_id,
            'shipped_qty' => 1,
            'actual_weight_kg' => 2.0,
        ]], $this->supplier);
        $shipment = app(ShipmentService::class)->submitShipment($shipment, ['user_id' => $this->supplier->id]);

        $this->actingAs($this->purchasing)
            ->get(route('purchasing.purchase-orders.show', $po))
            ->assertOk()
            ->assertSee('id="poDocsReadOnlyBanner"', false);

        // MED-01 regression: even an empty/invalid payload returns 403 Forbidden (not 422 validation error)
        $this->actingAs($this->purchasing)
            ->putJson(route('purchasing.purchase-orders.documents.shipments.update', ['id' => $po, 'doc_type' => 'invoice']), [])
            ->assertForbidden();

        $this->actingAs($this->purchasing)
            ->put(route('purchasing.purchase-orders.documents.shipments.update', ['id' => $po, 'doc_type' => 'invoice']), [
                'target_status' => ShipmentDocument::STATUS_VERIFIED,
                'rows' => [['shipment' => $shipment->getRouteKey(), 'expected_status' => 'pending']],
            ])
            ->assertForbidden();

        $this->actingAs($this->purchasing)
            ->put(route('purchasing.purchase-orders.documents.shipments.batch-update', ['id' => $po]), [
                'target_status' => ShipmentDocument::STATUS_VERIFIED,
                'rows' => [['shipment' => $shipment->getRouteKey(), 'expected_status' => 'pending']],
            ])
            ->assertForbidden();

        $this->actingAs($this->purchasing)
            ->put(route('purchasing.po-documents.update', ['id' => 999999]), [
                'status' => ShipmentDocument::STATUS_VERIFIED,
            ])
            ->assertForbidden();
    }

    public function test_d4_and_e1_sales_contract_four_stage_handshake_and_shipment_gate(): void
    {
        config([
            'procurement.require_sales_contract_before_shipment' => true,
            'procurement.require_ready_to_ship_for_shipment' => false,
            'procurement.require_commercial_invoice_for_shipment' => false,
            'procurement.shipment_mtc_enabled' => false,
        ]);

        $po = $this->generatePoForSupplier($this->supplier);
        $award = $po->awards->first();
        $this->assertSame(PoSalesContractStatus::PENDING_SUPPLIER->value, $po->sales_contract_status);

        // Supplier cannot acknowledge before signed sales contract
        $this->actingAs($this->supplier)
            ->post(route('supplier.purchase-orders.acknowledge', $po), ['mode' => 'confirm'])
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->assertSame(PoAcknowledgementStatus::PENDING, $po->fresh()->acknowledgement_status);

        // Shipment sync / submit is blocked by handshake gate
        $draft = app(ShipmentService::class)->createDraft($this->supplier);
        try {
            app(ShipmentService::class)->syncDraftItems($draft, [[
                'purchase_order_id' => $po->id,
                'quotation_item_id' => $award->quotation_item_id,
                'shipped_qty' => 2,
                'actual_weight_kg' => 4.0,
            ]], $this->supplier);
            $this->fail('Expected sales contract gate to reject draft item sync.');
        } catch (BusinessRuleArgumentException $e) {
            $this->assertStringContainsString($po->po_number, $e->getMessage());
        }

        // Cross-supplier cannot upload sales contract (scoped lookup returns 404)
        $this->actingAs($this->supplierB)
            ->post(route('supplier.purchase-orders.sales-contract.store', $po), [
                'sales_contract_file' => $this->fakePdf('idor.pdf'),
            ])
            ->assertNotFound();

        // Step 1: Supplier uploads draft sales contract
        $this->actingAs($this->supplier)
            ->post(route('supplier.purchase-orders.sales-contract.store', $po), [
                'sales_contract_file' => $this->fakePdf('supplier-contract-v1.pdf'),
                'sales_contract_notes' => 'Draft contract v1',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $po->refresh();
        $this->assertSame(PoSalesContractStatus::SUPPLIER_UPLOADED->value, $po->sales_contract_status);
        $this->assertNotNull($po->supplier_sales_contract_attachment_id);
        $this->assertTrue(
            $this->purchasing->notifications()->get()->contains(fn ($n) => ($n->data['event'] ?? '') === 'po.sales_contract_uploaded')
        );

        // Step 2: Purchasing requests revision
        $this->actingAs($this->purchasing)
            ->post(route('purchasing.purchase-orders.sales-contract.revision', $po), [
                'sales_contract_notes' => 'Please revise delivery clause.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $po->refresh();
        $this->assertSame(PoSalesContractStatus::REVISION_REQUESTED->value, $po->sales_contract_status);
        $this->assertTrue(
            $this->supplier->notifications()->get()->contains(fn ($n) => ($n->data['event'] ?? '') === 'po.sales_contract_revision_requested')
        );

        // Step 3: Supplier re-uploads revised contract
        $this->actingAs($this->supplier)
            ->post(route('supplier.purchase-orders.sales-contract.store', $po), [
                'sales_contract_file' => $this->fakePdf('supplier-contract-v2.pdf'),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(PoSalesContractStatus::SUPPLIER_UPLOADED->value, $po->fresh()->sales_contract_status);

        // Step 4: Purchasing uploads countersigned contract
        $this->actingAs($this->purchasing)
            ->post(route('purchasing.purchase-orders.sales-contract.sign', $po), [
                'signed_sales_contract_file' => $this->fakePdf('countersigned.pdf'),
                'sales_contract_notes' => 'Signed by Purchasing.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $po->refresh();
        $this->assertSame(PoSalesContractStatus::PURCHASING_SIGNED->value, $po->sales_contract_status);
        $this->assertNotNull($po->purchasing_sales_contract_attachment_id);
        $this->assertTrue(
            $this->supplier->notifications()->get()->contains(fn ($n) => ($n->data['event'] ?? '') === 'po.sales_contract_signed')
        );

        // Signed contract is locked against supplier replacement and purchasing revision revert
        $signedAttachmentId = $po->supplier_sales_contract_attachment_id;
        $this->actingAs($this->supplier)
            ->post(route('supplier.purchase-orders.sales-contract.store', $po), [
                'sales_contract_file' => $this->fakePdf('locked.pdf'),
            ])
            ->assertRedirect();
        $this->assertSame($signedAttachmentId, $po->fresh()->supplier_sales_contract_attachment_id);

        $this->actingAs($this->purchasing)
            ->post(route('purchasing.purchase-orders.sales-contract.revision', $po), [
                'sales_contract_notes' => 'Cannot revert signed contract',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->assertSame(PoSalesContractStatus::PURCHASING_SIGNED->value, $po->fresh()->sales_contract_status);

        // Zero-Trust Attachment IDOR check: Supplier B gets 403 on Sales Contract files; Supplier A & Purchasing get 200
        foreach ([$po->supplierSalesContractAttachment, $po->purchasingSalesContractAttachment] as $scAttachment) {
            $this->assertNotNull($scAttachment);
            $this->actingAs($this->supplierB)
                ->get(route('attachments.show', $scAttachment))
                ->assertForbidden();
            $this->actingAs($this->supplier)
                ->get(route('attachments.show', $scAttachment))
                ->assertOk();
            $this->actingAs($this->purchasing)
                ->get(route('attachments.show', $scAttachment))
                ->assertOk();
        }

        // Now Supplier can acknowledge PO and submit shipment
        $this->actingAs($this->supplier)
            ->post(route('supplier.purchase-orders.acknowledge', $po), ['mode' => 'confirm'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(PoAcknowledgementStatus::ACKNOWLEDGED, $po->fresh()->acknowledgement_status);

        app(ShipmentService::class)->syncDraftItems($draft, [[
            'purchase_order_id' => $po->id,
            'quotation_item_id' => $award->quotation_item_id,
            'shipped_qty' => 2,
            'actual_weight_kg' => 4.0,
        ]], $this->supplier);
        $submitted = app(ShipmentService::class)->submitShipment($draft, ['user_id' => $this->supplier->id]);
        $this->assertSame(Shipment::STATUS_SUBMITTED, $submitted->status);
    }

    public function test_e3_supplier_progress_rejects_awaiting_and_order_confirmed_and_accepts_three_allowed_stages(): void
    {
        $po = $this->generatePoForSupplier($this->supplier);
        $award = $po->awards->first();

        $this->assertSame(PoItemProgressUpdate::STATUS_ORDER_CONFIRMED, $award->latestProgressUpdate?->status);

        foreach ([PoItemProgressUpdate::STATUS_AWAITING_CONFIRMATION, PoItemProgressUpdate::STATUS_ORDER_CONFIRMED] as $rejectedStage) {
            $this->actingAs($this->supplier)
                ->post(route('supplier.purchase-orders.item-progress.update', ['po_id' => $po, 'award_id' => $award]), [
                    'status' => $rejectedStage,
                    'note' => 'Should fail',
                ])
                ->assertSessionHasErrors('status');
        }

        foreach (PoItemProgressUpdate::SUPPLIER_UPDATABLE_STATUSES as $allowedStage) {
            $this->actingAs($this->supplier)
                ->post(route('supplier.purchase-orders.item-progress.update', ['po_id' => $po, 'award_id' => $award]), [
                    'status' => $allowedStage,
                    'note' => "Progress {$allowedStage}",
                ])
                ->assertRedirect()
                ->assertSessionHasNoErrors();
        }
    }

    public function test_e5_e6_e9_e10_shipment_invoice_number_commercial_invoice_gate_preshipment_reuse_and_document_revision(): void
    {
        config([
            'procurement.require_commercial_invoice_for_shipment' => true,
            'procurement.shipment_mtc_enabled' => true,
            'procurement.require_preshipment_for_shipment' => true,
            'procurement.require_sales_contract_before_shipment' => false,
            'procurement.require_ready_to_ship_for_shipment' => false,
        ]);

        $po = $this->generatePoForSupplier($this->supplier);
        $award = $po->awards->first();

        // HIGH-02 regression: omitting invoice_number entirely (without any client marker) is rejected
        $this->actingAs($this->supplier)
            ->post(route('supplier.shipments.store'), [
                'action' => 'draft',
                'shipment_date' => now()->toDateString(),
                'estimated_arrival_date' => now()->addDays(10)->toDateString(),
                'items' => [[
                    'purchase_order_id' => $po->id,
                    'quotation_item_id' => $award->quotation_item_id,
                    'shipped_qty' => 2,
                    'actual_weight_kg' => 4.0,
                ]],
            ])
            ->assertSessionHasErrors('invoice_number');

        // Submitting without Commercial Invoice file (and without MTC!) is blocked by CommercialInvoiceRequiredException, while saving draft & E9 profile
        $preshipmentInput = [
            'exporter_name' => 'Xinkai Export Co.',
            'exporter_contact' => 'Mr. Ken',
            'exporter_address' => '12 Harbor Rd',
            'exporter_city' => 'Yokohama',
            'exporter_country' => 'JP',
            'exporter_zip_code' => '231-0001',
            'exporter_telephone' => '+81-45-111-2222',
            'exporter_mobile' => '+81-90-111-2222',
            'exporter_fax' => '+81-45-111-3333',
            'exporter_email' => 'export@xinkai.example',
            'exporter_port_of_loading' => 'Yokohama Port',
            'supplier_name' => 'Xinkai Factory',
            'supplier_contact' => 'Ms. Yuki',
            'supplier_address' => '88 Industrial Ave',
            'supplier_country' => 'JP',
            'supplier_city' => 'Kawasaki',
            'supplier_telephone' => '+81-44-222-3333',
            'supplier_mobile' => '+81-90-222-3333',
            'supplier_fax' => '+81-44-222-4444',
            'supplier_email' => 'factory@xinkai.example',
            'save_exporter_default' => '1',
            'location_action' => 'create',
            'set_location_default' => '1',
        ];

        $this->actingAs($this->supplier)
            ->post(route('supplier.shipments.store'), [
                'action' => 'submit',
                'shipment_date' => now()->toDateString(),
                'estimated_arrival_date' => now()->addDays(10)->toDateString(),
                'invoice_number' => 'INV-E6-001',
                'supplier_reference' => 'SUP-REF-E11',
                'preshipment' => $preshipmentInput,
                'items' => [[
                    'purchase_order_id' => $po->id,
                    'quotation_item_id' => $award->quotation_item_id,
                    'shipped_qty' => 2,
                    'actual_weight_kg' => 4.0,
                ]],
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        /** @var Shipment $shipment */
        $shipment = Shipment::query()->where('invoice_number', 'INV-E6-001')->firstOrFail();
        $this->assertSame(Shipment::STATUS_DRAFT, $shipment->status);

        // HIGH-02 regression: PUT update without invoice_number is rejected and does not wipe existing invoice_number
        $this->actingAs($this->supplier)
            ->put(route('supplier.shipments.update', $shipment), [
                'shipment_date' => now()->toDateString(),
                'estimated_arrival_date' => now()->addDays(10)->toDateString(),
                'items' => [[
                    'purchase_order_id' => $po->id,
                    'quotation_item_id' => $award->quotation_item_id,
                    'shipped_qty' => 2,
                    'actual_weight_kg' => 4.0,
                ]],
            ])
            ->assertSessionHasErrors('invoice_number');
        $this->assertSame('INV-E6-001', $shipment->fresh()->invoice_number);
        $this->assertSame('SUP-REF-E11', $shipment->supplier_reference);
        $this->assertDatabaseHas('shipment_preshipment_inspections', [
            'shipment_id' => $shipment->id,
            'exporter_name' => 'Xinkai Export Co.',
            'exporter_port_of_loading' => 'Yokohama Port',
            'supplier_name' => 'Xinkai Factory',
        ]);
        $this->assertDatabaseHas('supplier_preshipment_profiles', [
            'supplier_id' => $this->supplier->id,
            'exporter_name' => 'Xinkai Export Co.',
            'exporter_port_of_loading' => 'Yokohama Port',
        ]);

        // Second shipment create page auto-fills Pre-Shipment values (E9)
        $createHtml = $this->actingAs($this->supplier)
            ->get(route('supplier.shipments.create'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('Xinkai Export Co.', $createHtml);
        $this->assertStringContainsString('Yokohama Port', $createHtml);
        $this->assertStringContainsString('Xinkai Factory', $createHtml);

        // Batch upload Commercial Invoice (initial upload), then re-upload -> E10 revision tracking + notification
        $this->actingAs($this->supplier)
            ->postJson(route('supplier.shipments.documents.batch-upload', $shipment), [
                'documents' => [[
                    'doc_type' => ShipmentDocument::DOC_TYPE_INVOICE,
                    'file' => $this->fakePdf('invoice-v1.pdf'),
                    'document_number' => 'INV-E6-001',
                ]],
            ])
            ->assertOk();

        $invoiceDoc = $shipment->documents()->where('doc_type', ShipmentDocument::DOC_TYPE_INVOICE)->firstOrFail();
        $this->assertSame(0, (int) $invoiceDoc->revision_count);

        $this->actingAs($this->supplier)
            ->postJson(route('supplier.shipments.documents.batch-upload', $shipment), [
                'documents' => [[
                    'doc_type' => ShipmentDocument::DOC_TYPE_INVOICE,
                    'file' => $this->fakePdf('invoice-v2.pdf'),
                    'document_number' => 'INV-E6-001-R1',
                ]],
            ])
            ->assertOk();

        $invoiceDoc->refresh();
        $this->assertSame(1, (int) $invoiceDoc->revision_count);
        $this->assertNotNull($invoiceDoc->last_revised_at);
        $this->assertSame(2, $invoiceDoc->attachments()->count());
        $this->assertSame('INV-E6-001-R1', $shipment->fresh()->invoice_number);
        $this->assertTrue(
            $this->purchasing->notifications()->get()->contains(fn ($n) => ($n->data['event'] ?? '') === 'shipment.document_revised')
        );

        // Zero-Trust Attachment IDOR check on ShipmentDocument revision history (Rev. 0 and Rev. 1)
        foreach ($invoiceDoc->attachments as $versionAttachment) {
            $this->actingAs($this->supplierB)
                ->get(route('attachments.show', $versionAttachment))
                ->assertForbidden();
            $this->actingAs($this->supplier)
                ->get(route('attachments.show', $versionAttachment))
                ->assertOk();
            $this->actingAs($this->purchasing)
                ->get(route('attachments.show', $versionAttachment))
                ->assertOk();
        }

        // Ensure draft items are present, then submit since Commercial Invoice & MTC are attached
        if ($shipment->items()->count() === 0) {
            app(ShipmentService::class)->syncDraftItems($shipment, [[
                'purchase_order_id' => $po->id,
                'quotation_item_id' => $award->quotation_item_id,
                'shipped_qty' => 2,
                'actual_weight_kg' => 4.0,
            ]], $this->supplier);
        }

        $this->actingAs($this->supplier)
            ->post(route('supplier.shipments.submit', $shipment))
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('error');
        $shipment->refresh();
        $this->assertSame(Shipment::STATUS_SUBMITTED, $shipment->status);

        // Purchasing shipment show renders Pre-Shipment block and Revision marker
        $purchasingShowHtml = $this->actingAs($this->purchasing)
            ->get(route('purchasing.shipments.show', $shipment))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('Xinkai Export Co.', $purchasingShowHtml);
        $this->assertStringContainsString('Rev. 1', $purchasingShowHtml);
    }

    public function test_d1_e2_d5_e4_e7_e8_e11_outstanding_materials_shipment_dashboard_export_and_supplier_po_columns(): void
    {
        config([
            'procurement.require_commercial_invoice_for_shipment' => false,
            'procurement.shipment_mtc_enabled' => false,
            'procurement.require_sales_contract_before_shipment' => false,
            'procurement.require_ready_to_ship_for_shipment' => false,
        ]);

        // PO1 for Supplier A (qty 10): 4 submitted (in transit), 3 arrived (waiting_qc), 3 remaining (active)
        $po1 = $this->generatePoForSupplier($this->supplier, [], [
            'material_name' => 'MAT_SUP_A_SHIPPED',
            'shape' => PrItem::SHAPE_FLAT,
            'thickness' => 75,
        ]);
        $award1 = $po1->awards->first();
        SupplierPreshipmentProfile::create([
            'supplier_id' => $this->supplier->id,
            'exporter_port_of_loading' => 'Yokohama',
        ]);

        $shipmentService = app(ShipmentService::class);
        $inTransitShipment = $shipmentService->createDraft($this->supplier, ['supplier_reference' => 'REF-A-001']);
        $shipmentService->syncDraftItems($inTransitShipment, [[
            'purchase_order_id' => $po1->id,
            'quotation_item_id' => $award1->quotation_item_id,
            'shipped_qty' => 4,
            'actual_weight_kg' => 8.0,
        ]], $this->supplier);
        $inTransitShipment = $shipmentService->submitShipment($inTransitShipment, ['user_id' => $this->supplier->id]);

        // PO2 for Supplier A (no shipment yet)
        $po2 = $this->generatePoForSupplier($this->supplier, [], ['material_name' => 'MAT_SUP_A_UNSHIPPED']);

        // E7: While PO1 is still 'active' with a submitted shipment, Supplier PO Index distinguishes Active - Shipped vs Active - Not Yet Shipped
        $activePoJson = $this->actingAs($this->supplier)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->getJson(route('supplier.purchase-orders.index', ['draw' => 1, 'start' => 0, 'length' => 25]))
            ->assertOk()
            ->json('data');
        $activePoRows = collect($activePoJson);
        $activePo1Row = $activePoRows->first(fn (array $row) => str_contains((string) ($row['po_number_display'] ?? ''), $po1->po_number));
        $activePo2Row = $activePoRows->first(fn (array $row) => str_contains((string) ($row['po_number_display'] ?? ''), $po2->po_number));
        $this->assertNotNull($activePo1Row);
        $this->assertNotNull($activePo2Row);
        $this->assertStringContainsString('Active - Shipped', strip_tags($activePo1Row['status_badge']));
        $this->assertStringContainsString('Active - Not Yet Shipped', strip_tags($activePo2Row['status_badge']));

        $arrivedShipment = $shipmentService->createDraft($this->supplier, ['supplier_reference' => 'REF-A-002']);
        $shipmentService->syncDraftItems($arrivedShipment, [[
            'purchase_order_id' => $po1->id,
            'quotation_item_id' => $award1->quotation_item_id,
            'shipped_qty' => 3,
            'actual_weight_kg' => 6.0,
        ]], $this->supplier);
        $arrivedShipment = $shipmentService->submitShipment($arrivedShipment, ['user_id' => $this->supplier->id]);
        $shipmentService->confirmArrival($arrivedShipment, $this->purchasing);

        // PO3 + Shipment for Supplier B (must NEVER leak to Supplier A!)
        $po3 = $this->generatePoForSupplier($this->supplierB, [], ['material_name' => 'MAT_SUP_B_SECRET']);
        $award3 = $po3->awards->first();
        $supBShipment = $shipmentService->createDraft($this->supplierB, ['supplier_reference' => 'REF-B-SECRET']);
        $shipmentService->syncDraftItems($supBShipment, [[
            'purchase_order_id' => $po3->id,
            'quotation_item_id' => $award3->quotation_item_id,
            'shipped_qty' => 5,
            'actual_weight_kg' => 10.0,
        ]], $this->supplierB);

        // D1: Purchasing Outstanding Materials shows all suppliers + Yokohama port of loading + 11 columns (including Invoice Reference)
        $purchOutstandingHtml = $this->actingAs($this->purchasing)
            ->get(route('purchasing.outstanding-materials.index'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString($po1->po_number, $purchOutstandingHtml);
        $this->assertStringContainsString($po3->po_number, $purchOutstandingHtml);
        $this->assertStringContainsString('Yokohama', $purchOutstandingHtml);
        $this->assertSame(11, substr_count(
            explode('</thead>', explode('<thead', $purchOutstandingHtml)[1] ?? '')[0] ?? '',
            '<th'
        ));

        // E2: Supplier Outstanding Materials strictly isolates to auth()->id() even with forged supplier_id query
        $supOutstandingHtml = $this->actingAs($this->supplier)
            ->get(route('supplier.outstanding-materials.index', ['supplier_id' => $this->supplierB->getRouteKey()]))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString($po1->po_number, $supOutstandingHtml);
        $this->assertStringContainsString($po2->po_number, $supOutstandingHtml);
        $this->assertStringNotContainsString($po3->po_number, $supOutstandingHtml);
        $this->assertStringNotContainsString('MAT_SUP_B_SECRET', $supOutstandingHtml);

        // D5: Purchasing Shipment Index JSON includes missing_documents
        $purchShipJson = $this->actingAs($this->purchasing)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->getJson(route('purchasing.shipments.index', ['draw' => 1, 'start' => 0, 'length' => 25]))
            ->assertOk()
            ->json('data');
        $this->assertArrayHasKey('missing_documents', $purchShipJson[0]);

        // E11: Supplier Shipment Index JSON includes supplier_reference_display & missing_documents and isolates Supplier B
        $supShipJson = $this->actingAs($this->supplier)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->getJson(route('supplier.shipments.index', ['draw' => 1, 'start' => 0, 'length' => 25]))
            ->assertOk()
            ->json('data');
        $this->assertCount(2, $supShipJson);
        $refs = collect($supShipJson)->pluck('supplier_reference_display')->all();
        $this->assertContains('REF-A-001', $refs);
        $this->assertNotContains('REF-B-SECRET', $refs);
        $this->assertArrayHasKey('missing_documents', $supShipJson[0]);

        // E4: Supplier Shipment Export responds 202 Accepted (queued) and serialized job strictly scopes to supplier_id
        $exportDefinition = ExportDefinitions::forClass(ShipmentsExport::class, 'supplier');
        $this->actingAs($this->supplier)
            ->postJson(route('supplier.export.shipments'), [
                'options' => [
                    'columns' => ExportDefinitions::defaultKeys($exportDefinition),
                    'format' => 'xlsx',
                ],
            ])
            ->assertAccepted();
        /** @var ExportJob $job */
        $job = ExportJob::query()->where('user_id', $this->supplier->id)->latest('id')->firstOrFail();
        $this->assertSame(ShipmentsExport::class, $job->export_class);
        $exportInstance = new ShipmentsExport(...$job->export_args);
        $exportedIds = $exportInstance->query()->pluck('shipments.id')->all();
        $this->assertEqualsCanonicalizing([$inTransitShipment->id, $arrivedShipment->id], $exportedIds);
        $this->assertNotContains($supBShipment->id, $exportedIds);

        // E8: Supplier PO Index JSON shows Active & Waiting QC outstanding breakdown
        $supPoJson = $this->actingAs($this->supplier)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->getJson(route('supplier.purchase-orders.index', ['draw' => 1, 'start' => 0, 'length' => 25]))
            ->assertOk()
            ->json('data');

        $poRows = collect($supPoJson);
        $po1Row = $poRows->first(fn (array $row) => str_contains((string) ($row['po_number_display'] ?? ''), $po1->po_number));
        $po2Row = $poRows->first(fn (array $row) => str_contains((string) ($row['po_number_display'] ?? ''), $po2->po_number));
        $this->assertNotNull($po1Row);
        $this->assertNotNull($po2Row);
        $this->assertStringContainsString('Active - Not Yet Shipped', strip_tags($po2Row['status_badge']));
        $this->assertStringContainsString('Active: 7 pcs', strip_tags($po1Row['outstanding_breakdown']));
        $this->assertStringContainsString('Waiting QC: 3 pcs', strip_tags($po1Row['outstanding_breakdown']));

        // HIGH-05 regression: split-item PO must NOT bleed Item 1's arrived shipment / port onto unshipped Item 2
        $splitPr = $this->createRequisition([
            ['quantity' => 5, 'weight_needed' => 2],
            ['quantity' => 5, 'weight_needed' => 3],
        ]);
        $splitPr->items[0]->forceFill(['material_name' => 'MAT_SPLIT_SHIPPED'])->save();
        $splitPr->items[1]->forceFill(['material_name' => 'MAT_SPLIT_UNSHIPPED'])->save();
        $splitQuotation = $this->createQuotation($splitPr, [
            ['price_per_kg' => 51000],
            ['price_per_kg' => 52000],
        ], supplier: $this->supplier);
        $splitAwards = app(PrItemAwardService::class)->awardBatch(
            $splitPr,
            [
                $splitPr->items[0]->id => $splitQuotation->items[0]->id,
                $splitPr->items[1]->id => $splitQuotation->items[1]->id,
            ],
            $this->purchasing
        );
        /** @var PurchaseOrder $splitPo */
        $splitPo = app(PurchaseOrderGenerationService::class)
            ->generateFromAwards($splitAwards->pluck('id')->all(), $this->purchasing, [
                'estimated_arrival' => now()->addDays(25)->toDateString(),
                'port_of_discharge' => array_key_first(LogisticsTerms::portOptions()),
            ])
            ->firstOrFail();
        $splitShipment = $shipmentService->createDraft($this->supplier, [
            'preshipment' => ['exporter_port_of_loading' => 'Osaka'],
        ]);
        $shipmentService->syncDraftItems($splitShipment, [[
            'purchase_order_id' => $splitPo->id,
            'quotation_item_id' => $splitQuotation->items[0]->id,
            'shipped_qty' => 3,
            'actual_weight_kg' => 6.0,
        ]], $this->supplier);
        $splitShipment = $shipmentService->submitShipment($splitShipment, ['user_id' => $this->supplier->id]);
        $shipmentService->confirmArrival($splitShipment, $this->purchasing);

        // Update supplier default profile port AFTER splitShipment was saved so shipped vs unshipped rows must diverge.
        SupplierPreshipmentProfile::query()
            ->where('supplier_id', $this->supplier->id)
            ->update(['exporter_port_of_loading' => 'Nagoya']);

        // HIGH-05 regression: query count of OutstandingMaterialQueryService::paginate() stays constant O(1) (no N+1 per PO)
        DB::enableQueryLog();
        DB::flushQueryLog();
        $paginated = app(OutstandingMaterialQueryService::class)->paginate(null, []);
        $queriesExecuted = count(DB::getQueryLog());
        DB::disableQueryLog();

        $allItems = collect($paginated->items());
        $splitShippedRow = $allItems->firstWhere('material_name', 'MAT_SPLIT_SHIPPED');
        $splitUnshippedRow = $allItems->firstWhere('material_name', 'MAT_SPLIT_UNSHIPPED');
        $this->assertNotNull($splitShippedRow);
        $this->assertNotNull($splitUnshippedRow);
        $this->assertSame('Osaka', $splitShippedRow['port_of_loading']);
        $this->assertNotNull($splitShippedRow['actual_arrival']);
        $this->assertSame('Nagoya', $splitUnshippedRow['port_of_loading']);
        $this->assertNull($splitUnshippedRow['actual_arrival']);

        $this->assertGreaterThanOrEqual(5, $paginated->total());
        $this->assertLessThanOrEqual(18, $queriesExecuted, "Expected O(1) eager-loaded queries across 4+ POs, got {$queriesExecuted}");
    }
}
