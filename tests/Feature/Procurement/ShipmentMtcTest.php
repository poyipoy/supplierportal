<?php

namespace Tests\Feature\Procurement;

use App\Models\Attachment;
use App\Models\FileInspection;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\Shipment;
use App\Models\User;
use App\Services\PrItemAwardService;
use App\Services\PurchaseOrderGenerationService;
use App\Services\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\Support\NativeFileFixtures;
use Tests\TestCase;

/**
 * Mission 05 (Q6B, decisions 21/22): shipment-level MTC with the atomic
 * retirement of new quotation MTC uploads, behind
 * procurement.shipment_mtc_enabled (AC-11..14, AC-34).
 */
class ShipmentMtcTest extends TestCase
{
    use ProcurementFixtures;
    use RefreshDatabase;

    private PurchaseOrder $po;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        $this->setUpProcurementActors();
        config(['procurement.shipment_mtc_enabled' => true]);
        $this->po = $this->purchaseOrder();
    }

    public function test_draft_saves_without_mtc_but_every_submit_path_is_blocked(): void
    {
        // Service path.
        $draft = $this->draft();
        $this->assertRefused(fn () => app(ShipmentService::class)->submitShipment($draft), __('shipments.mtc.required_to_submit'));
        $this->assertSame(Shipment::STATUS_DRAFT, $draft->fresh()->status);

        // Quick submit route.
        $this->actingAs($this->supplier)
            ->post(route('supplier.shipments.submit', $draft->getRouteKey()))
            ->assertSessionHas('error', __('shipments.mtc.required_to_submit'));
        $this->assertSame(Shipment::STATUS_DRAFT, $draft->fresh()->status);

        // Create-and-submit form path: the draft is kept, submission refused.
        $this->actingAs($this->supplier)
            ->post(route('supplier.shipments.store'), [...$this->formPayload(), 'action' => 'submit'])
            ->assertSessionHas('error', __('shipments.mtc.required_to_submit'));
        $this->assertSame(0, Shipment::where('status', Shipment::STATUS_SUBMITTED)->count());
    }

    public function test_two_validated_mtc_files_allow_submission_and_owner_and_purchasing_can_download(): void
    {
        $draft = $this->draft();
        $this->actingAs($this->supplier)
            ->post(route('supplier.shipments.mtc.store', $draft->getRouteKey()), [
                'mtc_files' => [NativeFileFixtures::upload('mtc-heat-1.pdf'), NativeFileFixtures::upload('mtc-heat-2.pdf')],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $files = $draft->fresh()->mtcAttachments;
        $this->assertCount(2, $files);
        foreach ($files as $file) {
            $this->assertSame(FileInspection::STATUS_VALIDATED, FileInspection::find($file->file_inspection_id)?->status);
            $this->assertSame($this->supplier->id, $file->uploaded_by);
        }

        $submitted = app(ShipmentService::class)->submitShipment($draft);
        $this->assertSame(Shipment::STATUS_SUBMITTED, $submitted->status);

        $this->actingAs($this->supplier)->get(route('attachments.show', $files[0]))->assertOk();
        $this->actingAs($this->purchasing)->get(route('attachments.show', $files[0]))->assertOk();
        $this->actingAs($this->otherSupplier())->get(route('attachments.show', $files[0]))->assertForbidden();
    }

    public function test_state_matrix_draft_submitted_arrived_cancelled(): void
    {
        $draft = $this->draftWithMtc();
        $first = $draft->mtcAttachments()->first();

        // Draft: delete allowed.
        $extra = $this->upload($draft);
        $this->actingAs($this->supplier)
            ->delete(route('supplier.shipments.mtc.destroy', [$draft->getRouteKey(), $extra]))
            ->assertSessionHasNoErrors();
        $this->assertModelMissing($extra);

        // Submitted: append allowed, delete refused, file kept.
        $submitted = app(ShipmentService::class)->submitShipment($draft);
        $this->upload($submitted);
        $this->actingAs($this->supplier)
            ->delete(route('supplier.shipments.mtc.destroy', [$submitted->getRouteKey(), $first]))
            ->assertSessionHasErrors('mtc');
        $this->assertModelExists($first);
        $this->assertCount(2, $submitted->fresh()->mtcAttachments);

        // Arrived: upload and delete refused (fail-closed, T-6); download works.
        $arrived = app(ShipmentService::class)->confirmArrival($submitted, $this->purchasing);
        $this->actingAs($this->supplier)
            ->post(route('supplier.shipments.mtc.store', $arrived->getRouteKey()), ['mtc_files' => [NativeFileFixtures::upload('late.pdf')]])
            ->assertSessionHasErrors('mtc');
        $this->actingAs($this->supplier)
            ->delete(route('supplier.shipments.mtc.destroy', [$arrived->getRouteKey(), $first]))
            ->assertSessionHasErrors('mtc');
        $this->assertCount(2, $arrived->fresh()->mtcAttachments);
        $this->actingAs($this->supplier)->get(route('attachments.show', $first))->assertOk();

        // Cancelled: refused as well.
        $cancelled = app(ShipmentService::class)->cancelShipment($this->draftWithMtc(), $this->supplier);
        $this->actingAs($this->supplier)
            ->post(route('supplier.shipments.mtc.store', $cancelled->getRouteKey()), ['mtc_files' => [NativeFileFixtures::upload('x.pdf')]])
            ->assertSessionHasErrors('mtc');
    }

    public function test_removing_the_last_mtc_then_submitting_never_yields_a_submitted_shipment_without_mtc(): void
    {
        $draft = $this->draftWithMtc();
        $only = $draft->mtcAttachments()->sole();
        app(ShipmentService::class)->removeMtc($draft, $only, $this->supplier);

        $this->assertRefused(fn () => app(ShipmentService::class)->submitShipment($draft), __('shipments.mtc.required_to_submit'));
        $this->assertSame(Shipment::STATUS_DRAFT, $draft->fresh()->status);
    }

    public function test_an_unvalidated_attachment_does_not_count(): void
    {
        $draft = $this->draftWithMtc();
        FileInspection::whereKey($draft->mtcAttachments()->sole()->file_inspection_id)->update(['status' => 'ERROR']);

        $this->assertRefused(fn () => app(ShipmentService::class)->submitShipment($draft), __('shipments.mtc.required_to_submit'));
    }

    public function test_other_suppliers_and_purchasing_cannot_change_mtc_and_ids_are_scoped_to_the_shipment(): void
    {
        $draft = $this->draftWithMtc();
        $file = $draft->mtcAttachments()->sole();
        $other = $this->otherSupplier();

        $this->actingAs($other)
            ->post(route('supplier.shipments.mtc.store', $draft->getRouteKey()), ['mtc_files' => [NativeFileFixtures::upload('x.pdf')]])
            ->assertForbidden();
        $this->actingAs($other)
            ->delete(route('supplier.shipments.mtc.destroy', [$draft->getRouteKey(), $file]))
            ->assertForbidden();
        $this->actingAs($this->purchasing)
            ->post(route('supplier.shipments.mtc.store', $draft->getRouteKey()), ['mtc_files' => [NativeFileFixtures::upload('x.pdf')]])
            ->assertForbidden();

        // An attachment of another shipment cannot be removed through this one.
        $second = $this->draftWithMtc();
        $this->actingAs($this->supplier)
            ->delete(route('supplier.shipments.mtc.destroy', [$draft->getRouteKey(), $second->mtcAttachments()->sole()]))
            ->assertNotFound();
        $this->assertCount(1, $second->fresh()->mtcAttachments);
    }

    public function test_quotation_mtc_uploads_are_retired_and_legacy_files_survive_a_save(): void
    {
        $pr = $this->createRequisition();
        $pr->invitedSuppliers()->syncWithoutDetaching([$this->supplier->id]);
        $quotation = $this->createQuotation($pr, [], Quotation::STATUS_DRAFT);
        $legacy = $quotation->items[0]->attachments()->create([
            'file_path' => 'attachments/legacy/mtc.pdf', 'file_name' => 'legacy-mtc.pdf', 'file_type' => 'application/pdf', 'uploaded_by' => $this->supplier->id,
        ]);
        Storage::disk('private')->put('attachments/legacy/mtc.pdf', NativeFileFixtures::pdf());
        // A real historical row predates the native-file cutover (HISTORICAL_UNVERIFIED).
        DB::table('file_security_cutovers')->updateOrInsert(['document_table' => 'attachments'], ['legacy_max_id' => $legacy->id, 'created_at' => now()]);

        $payload = [
            'action' => 'draft',
            'currency' => 'USD',
            'payment_terms' => 'TT 30 Days',
            'items' => [[
                'pr_item_id' => $pr->items[0]->id,
                'price_per_kg' => 2.5,
                'mtc_file' => NativeFileFixtures::upload('new-quotation-mtc.pdf'),
            ]],
        ];
        $this->actingAs($this->supplier)
            ->post(route('supplier.quotations.store', $pr), $payload)
            ->assertSessionHasErrors('items.0.mtc_file');

        unset($payload['items'][0]['mtc_file']);
        $this->actingAs($this->supplier)
            ->post(route('supplier.quotations.store', $pr), [...$payload, 'items' => [[...$payload['items'][0], 'keep_existing_attachment' => 0]]])
            ->assertSessionHasNoErrors();

        $kept = Attachment::find($legacy->id);
        $this->assertNotNull($kept, 'A quotation save never deletes a historical MTC file.');
        $this->assertSame($quotation->fresh()->items->sole()->id, (int) $kept->attachable_id);
        $this->actingAs($this->purchasing)->get(route('attachments.show', $kept))->assertOk();

        $this->actingAs($this->supplier)
            ->get(route('supplier.quotations.create', $pr))
            ->assertOk()
            ->assertDontSee('[mtc_file]', false);
    }

    public function test_switch_off_restores_the_previous_behaviour(): void
    {
        config(['procurement.shipment_mtc_enabled' => false]);

        $submitted = app(ShipmentService::class)->submitShipment($this->draft());
        $this->assertSame(Shipment::STATUS_SUBMITTED, $submitted->status);

        $pr = $this->createRequisition();
        $pr->invitedSuppliers()->syncWithoutDetaching([$this->supplier->id]);
        $this->actingAs($this->supplier)
            ->get(route('supplier.quotations.create', $pr))
            ->assertOk()
            ->assertSee('[mtc_file]', false);
    }

    public function test_pages_show_mtc_with_status_appropriate_controls(): void
    {
        $draft = $this->draftWithMtc();
        $name = $draft->mtcAttachments()->sole()->file_name;

        $this->actingAs($this->supplier)->get(route('supplier.shipments.show', $draft->getRouteKey()))
            ->assertOk()->assertSee($name)
            ->assertSee(route('supplier.shipments.mtc.store', $draft->getRouteKey()), false)
            ->assertSee(route('supplier.shipments.mtc.destroy', [$draft->getRouteKey(), $draft->mtcAttachments()->sole()]), false);

        $submitted = app(ShipmentService::class)->submitShipment($draft);
        $this->actingAs($this->supplier)->get(route('supplier.shipments.show', $submitted->getRouteKey()))
            ->assertOk()->assertSee(route('supplier.shipments.mtc.store', $submitted->getRouteKey()), false)
            ->assertDontSee('/mtc/', false)
            ->assertSee(__('shipments.mtc.submitted_note'));

        $this->actingAs($this->purchasing)->get(route('purchasing.shipments.show', $submitted->getRouteKey()))
            ->assertOk()->assertSee($name)->assertDontSee('mtc_files', false);
        $this->actingAs($this->purchasing)->get(route('purchasing.purchase-orders.show', $this->po))
            ->assertOk()->assertSee($name)->assertSee($submitted->shipment_number);
    }

    public function test_customs_documents_are_unchanged(): void
    {
        $draft = $this->draftWithMtc();

        $this->assertSame(['bl', 'form_e', 'invoice', 'packing_list'], $draft->documents()->pluck('doc_type')->sort()->values()->all());
        $this->assertTrue($draft->mtcAttachments->every(fn ($file) => $file->attachable_type === Shipment::class));
    }

    private function purchaseOrder(): PurchaseOrder
    {
        $pr = $this->createRequisition([['quantity' => 10, 'weight_needed' => 3]]);
        $quotation = $this->createQuotation($pr);
        $award = app(PrItemAwardService::class)->awardItem($pr->items[0], $quotation->items[0], $this->purchasing);

        return app(PurchaseOrderGenerationService::class)->generateFromAwards([$award->id], $this->purchasing)->sole();
    }

    private function items(int $qty = 2): array
    {
        return [[
            'purchase_order_id' => $this->po->id,
            'quotation_item_id' => $this->po->quotations->first()->items->first()->id,
            'shipped_qty' => $qty,
            'actual_weight_kg' => $qty * 3,
        ]];
    }

    private function draft(): Shipment
    {
        return app(ShipmentService::class)->createDraft($this->supplier, ['items' => $this->items()]);
    }

    private function draftWithMtc(): Shipment
    {
        $draft = $this->draft();
        $this->upload($draft);

        return $draft->fresh();
    }

    private function upload(Shipment $shipment): Attachment
    {
        return app(ShipmentService::class)->uploadMtc($shipment, [NativeFileFixtures::upload('mtc-'.uniqid().'.pdf')], $this->supplier)[0];
    }

    private function formPayload(): array
    {
        return [
            'shipment_date' => now()->toDateString(),
            'estimated_arrival_date' => now()->addDays(10)->toDateString(),
            'items' => $this->items(),
        ];
    }

    private function otherSupplier(): User
    {
        return User::factory()->create(['role' => 'supplier', 'is_active' => true]);
    }

    private function assertRefused(callable $action, string $message): void
    {
        try {
            $action();
            $this->fail('The action must be refused.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }
}
