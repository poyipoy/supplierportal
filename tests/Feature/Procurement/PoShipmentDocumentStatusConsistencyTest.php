<?php

namespace Tests\Feature\Procurement;

use App\Models\ExchangeRate;
use App\Models\Period;
use App\Models\PoDocument;
use App\Models\PrItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Shipment;
use App\Models\ShipmentDocument;
use App\Models\User;
use App\Services\PrItemAwardService;
use App\Services\PurchaseOrderGenerationService;
use App\Services\ShipmentService;
use App\Support\Procurement\CustomsDocumentationProgressCalculator;
use App\Support\StatusHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AttachesShipmentMtc;
use Tests\Support\NativeFileFixtures;
use Tests\TestCase;

/**
 * One rule for the PO-level import document status (least advanced active
 * shipment document), whichever menu changed it: every read surface for
 * Purchasing and Supplier must show the same value.
 */
class PoShipmentDocumentStatusConsistencyTest extends TestCase
{
    use AttachesShipmentMtc;
    use RefreshDatabase;

    private User $purchasing;

    private User $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');

        $this->purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $this->supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);

        ExchangeRate::create([
            'currency' => 'USD',
            'rate_to_idr' => 16000,
            'valid_from' => now()->subDay(),
            'created_by' => $this->purchasing->id,
        ]);
    }

    public function test_every_write_path_keeps_purchasing_and_supplier_views_in_sync(): void
    {
        [$po, $qItem] = $this->createAwardedPo();
        $s1 = $this->submittedShipment($po, $qItem);
        $s2 = $this->submittedShipment($po, $qItem);
        $this->assertDocumentStatusEverywhere($po, 'invoice', 'pending');

        // Supplier uploads on one shipment: the other is still pending.
        $this->supplierUploads($s1, 'invoice');
        $this->assertDocumentStatusEverywhere($po, 'invoice', 'pending');

        $this->supplierUploads($s2, 'invoice');
        $this->assertDocumentStatusEverywhere($po, 'invoice', 'received');

        // Purchasing verifies s1 on the shipment page: s2 is still only received.
        $this->purchasingUpdatesOnShipmentPage($s1, 'invoice', 'verified')->assertSessionHas('success');
        $this->assertDocumentStatusEverywhere($po, 'invoice', 'received');

        // Purchasing verifies s2 from the PO dialog.
        $this->purchasingUpdatesFromPoDialog($po, $s2, 'invoice', 'verified')->assertOk();
        $this->assertDocumentStatusEverywhere($po, 'invoice', 'verified');

        // Downgrade with a reason on the shipment page moves the PO down too.
        $this->purchasingUpdatesOnShipmentPage($s1, 'invoice', 'received', 'Invoice total differs from PO.')
            ->assertSessionHas('success');
        $this->assertDocumentStatusEverywhere($po, 'invoice', 'received');

        // Cancelling s1 leaves only the verified s2 active.
        $this->actingAs($this->supplier)
            ->post(route('supplier.shipments.cancel', ['id' => $s1]))
            ->assertSessionHas('success');
        $this->assertDocumentStatusEverywhere($po, 'invoice', 'verified');
    }

    public function test_submitting_another_shipment_lowers_the_po_status_until_its_documents_arrive(): void
    {
        [$po, $qItem] = $this->createAwardedPo();
        $s1 = $this->submittedShipment($po, $qItem);
        $this->supplierUploads($s1, 'packing_list');
        $this->purchasingUpdatesOnShipmentPage($s1, 'packing_list', 'verified')->assertSessionHas('success');
        $this->assertDocumentStatusEverywhere($po, 'packing_list', 'verified');

        $this->submittedShipment($po, $qItem);

        $this->assertDocumentStatusEverywhere($po, 'packing_list', 'pending');
    }

    public function test_supplier_upload_moves_every_doc_type_to_received_and_replacement_keeps_status(): void
    {
        [$po, $qItem] = $this->createAwardedPo();
        $shipment = $this->submittedShipment($po, $qItem);

        // All four doc types share pending -> received -> verified.
        foreach (['invoice', 'packing_list', 'bl', 'form_e'] as $docType) {
            $this->supplierUploads($shipment, $docType);
            $this->assertSame('received', $shipment->documents()->where('doc_type', $docType)->value('status'));
            $this->assertDocumentStatusEverywhere($po, $docType, 'received');
        }

        // BL and Form-E can now be verified like the invoice.
        $this->purchasingUpdatesOnShipmentPage($shipment, 'form_e', 'verified')->assertSessionHas('success');
        $this->assertDocumentStatusEverywhere($po, 'form_e', 'verified');

        // A replacement file does not move a reviewed document.
        $this->supplierUploads($shipment, 'bl');
        $this->assertSame('received', $shipment->documents()->where('doc_type', 'bl')->value('status'));
        $this->assertSame(2, $shipment->documents()->where('doc_type', 'bl')->firstOrFail()->attachments()->count());
    }

    public function test_form_e_upload_appears_on_the_purchasing_po_page_even_without_a_po_documents_row(): void
    {
        [$po, $qItem] = $this->createAwardedPo();
        $shipment = $this->submittedShipment($po, $qItem);
        // Historical POs were created without a form_e row.
        PoDocument::where('po_id', $po->id)->where('doc_type', 'form_e')->delete();

        // Before any upload the card still renders, as pending.
        $this->actingAs($this->purchasing)
            ->get(route('purchasing.purchase-orders.show', $po))
            ->assertOk()
            ->assertSee('id="doc-badge-type-form_e" data-status="pending"', false);

        $this->supplierUploads($shipment, 'form_e');
        $attachment = $shipment->documents()->where('doc_type', 'form_e')->firstOrFail()->latestAttachment;

        // The upload recreates the row with the derived status ...
        $this->assertSame('received', PoDocument::where('po_id', $po->id)->where('doc_type', 'form_e')->value('status'));

        // ... and Purchasing sees the Form-E card, its status and the supplier's file.
        $this->assertDocumentStatusEverywhere($po, 'form_e', 'received');
        $this->actingAs($this->purchasing)
            ->get(route('purchasing.purchase-orders.show', $po))
            ->assertOk()
            ->assertSee(route('attachments.show', $attachment), false);
    }

    public function test_upload_on_a_draft_shipment_does_not_change_the_po_status(): void
    {
        [$po, $qItem] = $this->createAwardedPo();
        $draft = app(ShipmentService::class)->createDraft($this->supplier, [
            'items' => [[
                'purchase_order_id' => $po->id,
                'quotation_item_id' => $qItem->id,
                'shipped_qty' => 5,
                'actual_weight_kg' => 5.0,
            ]],
        ]);

        $this->supplierUploads($draft, 'invoice');

        $this->assertSame('received', $draft->documents()->where('doc_type', 'invoice')->value('status'));
        $this->assertDocumentStatusEverywhere($po, 'invoice', 'pending');
    }

    public function test_shipment_page_form_applies_the_same_rules_as_the_po_dialog(): void
    {
        [$po, $qItem] = $this->createAwardedPo();
        $shipment = $this->submittedShipment($po, $qItem);
        $invoice = $shipment->documents()->where('doc_type', 'invoice')->firstOrFail();

        // Verifying needs an uploaded file.
        $this->purchasingUpdatesOnShipmentPage($shipment, 'invoice', 'verified')->assertSessionHas('error');
        $this->assertSame('pending', $invoice->fresh()->status);

        // A status from another doc type's workflow is not accepted.
        $this->actingAs($this->purchasing)
            ->put(route('purchasing.shipments.documents.status', ['id' => $shipment, 'document_id' => $invoice->id]), [
                'status' => 'issued',
            ])
            ->assertSessionHasErrors('status');

        // A stale expected status is refused.
        $this->actingAs($this->purchasing)
            ->put(route('purchasing.shipments.documents.status', ['id' => $shipment, 'document_id' => $invoice->id]), [
                'status' => 'received',
                'expected_status' => 'verified',
            ])
            ->assertSessionHas('error');
        $this->assertSame('pending', $invoice->fresh()->status);

        // BL offers the same pending/received/verified list as the invoice.
        $blDoc = $shipment->documents()->where('doc_type', 'bl')->firstOrFail();
        $page = $this->actingAs($this->purchasing)->get(route('purchasing.shipments.show', $shipment))->assertOk();
        $this->assertSame(['pending', 'received', 'verified'], $this->statusOptions($page->getContent(), $blDoc->id));

        // A historical BL status outside the list is shown, never silently replaced by 'pending'.
        $blDoc->update(['status' => ShipmentDocument::STATUS_DONE]);
        $page = $this->actingAs($this->purchasing)->get(route('purchasing.shipments.show', $shipment))->assertOk();
        $this->assertSame(['', 'pending', 'received', 'verified'], $this->statusOptions($page->getContent(), $blDoc->id));
        $this->assertMatchesRegularExpression('/<option value="" disabled selected>\s*'.preg_quote(StatusHelper::shipmentDocLabel('done'), '/').'\s*<\/option>/', $page->getContent());

        // Suppliers cannot use the Purchasing route.
        $this->actingAs($this->supplier)
            ->put(route('purchasing.shipments.documents.status', ['id' => $shipment, 'document_id' => $invoice->id]), [
                'status' => 'received',
            ])
            ->assertForbidden();
    }

    public function test_purchasing_status_change_notifies_the_shipment_supplier(): void
    {
        [$po, $qItem] = $this->createAwardedPo();
        $shipment = $this->submittedShipment($po, $qItem);
        $this->supplierUploads($shipment, 'invoice');

        $this->purchasingUpdatesOnShipmentPage($shipment, 'invoice', 'verified')->assertSessionHas('success');

        $notification = $this->supplier->notifications()->get()
            ->first(fn ($n) => ($n->data['event'] ?? null) === 'shipment.document_status_updated');

        $this->assertNotNull($notification, 'Supplier must be notified when Purchasing changes a shipment document status.');
        $this->assertSame(route('supplier.shipments.show', $shipment, false), $notification->data['url']);
        $this->assertSame('verified', $notification->data['status']);
        $this->assertSame('import', $notification->data['domain']);
    }

    public function test_resync_command_reports_in_dry_run_and_repairs_with_execute(): void
    {
        [$po, $qItem] = $this->createAwardedPo();
        $this->submittedShipment($po, $qItem);

        // Simulate a row left behind by the old forward-only sync.
        PoDocument::where('po_id', $po->id)->where('doc_type', 'invoice')->update(['status' => 'verified']);

        $this->artisan('procurement:resync-document-status')->assertSuccessful();
        $this->assertSame('verified', PoDocument::where('po_id', $po->id)->where('doc_type', 'invoice')->value('status'));

        $this->artisan('procurement:reconcile', ['--json' => true])
            ->expectsOutputToContain('"po_document_status_drift_count": 1')
            ->assertSuccessful();

        $this->artisan('procurement:resync-document-status', ['--execute' => true])->assertSuccessful();
        $this->assertSame('pending', PoDocument::where('po_id', $po->id)->where('doc_type', 'invoice')->value('status'));

        $this->artisan('procurement:reconcile', ['--json' => true])
            ->expectsOutputToContain('"po_document_status_drift_count": 0')
            ->assertSuccessful();
    }

    private function assertDocumentStatusEverywhere(PurchaseOrder $po, string $docType, string $expected): void
    {
        $po = PurchaseOrder::query()->findOrFail($po->id);
        $poDocument = PoDocument::where('po_id', $po->id)->where('doc_type', $docType)->firstOrFail();

        $this->assertSame($expected, $poDocument->status, "po_documents.{$docType}");
        $this->assertSame($expected, $po->customsDocumentationSummary()[$docType]['status'], "summary.{$docType}");
        $this->assertSame(
            $expected,
            app(CustomsDocumentationProgressCalculator::class)->calculate($po->fresh())['documents'][$docType]['derived_status'],
            "calculator.{$docType}",
        );

        $purchasingPage = $this->actingAs($this->purchasing)
            ->get(route('purchasing.purchase-orders.show', $po))
            ->assertOk()
            ->assertSee('id="doc-badge-'.$poDocument->id.'" data-status="'.$expected.'"', false);
        $this->assertSame($expected, $purchasingPage->viewData('customsSummary')[$docType]['status'], "purchasing page.{$docType}");

        $supplierPage = $this->actingAs($this->supplier)
            ->get(route('supplier.purchase-orders.show', $po))
            ->assertOk();
        $this->assertSame($expected, $supplierPage->viewData('customsSummary')[$docType]['status'], "supplier page.{$docType}");
    }

    /**
     * Option values of the status select in a shipment document's update form.
     *
     * @return array<int, string>
     */
    private function statusOptions(string $html, int $documentId): array
    {
        $this->assertSame(1, preg_match(
            '/documents\/'.$documentId.'\/status".*?<select name="status"[^>]*>(.*?)<\/select>/s',
            $html,
            $match,
        ));
        preg_match_all('/<option value="([^"]*)"/', $match[1], $values);

        return $values[1];
    }

    private function supplierUploads(Shipment $shipment, string $docType): void
    {
        $document = $shipment->documents()->where('doc_type', $docType)->firstOrFail();

        $this->actingAs($this->supplier)
            ->post(route('supplier.shipments.documents.upload', ['id' => $shipment, 'document_id' => $document->id]), [
                'file' => NativeFileFixtures::upload($docType.'-'.uniqid().'.pdf', 300),
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('error');
    }

    private function purchasingUpdatesOnShipmentPage(Shipment $shipment, string $docType, string $target, ?string $reason = null)
    {
        $document = $shipment->documents()->where('doc_type', $docType)->firstOrFail();

        return $this->actingAs($this->purchasing)
            ->from(route('purchasing.shipments.show', $shipment))
            ->put(route('purchasing.shipments.documents.status', ['id' => $shipment, 'document_id' => $document->id]), array_filter([
                'status' => $target,
                'expected_status' => $document->status,
                'expected_attachment' => $document->latestAttachment?->hash,
                'reason' => $reason,
            ], fn ($value) => $value !== null));
    }

    private function purchasingUpdatesFromPoDialog(PurchaseOrder $po, Shipment $shipment, string $docType, string $target, ?string $reason = null)
    {
        $document = $shipment->documents()->where('doc_type', $docType)->firstOrFail();

        return $this->actingAs($this->purchasing)
            ->putJson(route('purchasing.purchase-orders.documents.shipments.update', ['id' => $po, 'doc_type' => $docType]), [
                'target_status' => $target,
                'reason' => $reason,
                'rows' => [[
                    'shipment' => $shipment->hash,
                    'expected_status' => $document->status,
                    'expected_attachment' => $document->latestAttachment?->hash,
                ]],
            ]);
    }

    private function submittedShipment(PurchaseOrder $po, QuotationItem $qItem): Shipment
    {
        $draft = app(ShipmentService::class)->createDraft($this->supplier);

        return $this->submitWithMtc($draft, [
            'shipment_date' => now()->toDateString(),
            'items' => [[
                'purchase_order_id' => $po->id,
                'quotation_item_id' => $qItem->id,
                'shipped_qty' => 5,
                'actual_weight_kg' => 5.0,
            ]],
        ]);
    }

    /**
     * @return array{0: PurchaseOrder, 1: QuotationItem}
     */
    private function createAwardedPo(): array
    {
        $period = Period::firstOrCreate(
            ['name' => 'Document Consistency Period', 'year' => 2026],
            ['status' => 'open', 'created_by' => $this->purchasing->id],
        );

        $pr = PurchaseRequisition::create([
            'period_id' => $period->id,
            'pr_number' => 'REQ/DC/'.uniqid(),
            'status' => 'bidding',
            'created_by' => $this->purchasing->id,
        ]);

        $prItem = PrItem::create([
            'pr_id' => $pr->id,
            'material_name' => 'SKD11 Tool Steel Bar',
            'shape' => 'round',
            'quantity' => 20,
            'weight_needed' => 20.0,
            'd_outer' => 120.0,
            'length' => 1000.0,
        ]);

        $quotation = Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $this->supplier->id,
            'currency' => 'USD',
            'status' => Quotation::STATUS_SUBMITTED,
            'validity_period' => now()->addDays(30),
            'submitted_at' => now(),
        ]);

        $qItem = QuotationItem::create([
            'quotation_id' => $quotation->id,
            'pr_item_id' => $prItem->id,
            'price_per_kg' => 15.0,
            'amount' => 300.0,
            'available_qty' => 20,
            'is_available' => true,
        ]);

        app(PrItemAwardService::class)->saveAwards($pr, [$prItem->id => $qItem->id], $this->purchasing);
        $po = app(PurchaseOrderGenerationService::class)
            ->generatePurchaseOrdersForAwards([$prItem->fresh()->award], $this->purchasing)[0];

        return [$po, $qItem];
    }
}
