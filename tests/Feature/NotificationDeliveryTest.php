<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\MaterialClaim;
use App\Models\MaterialMaster;
use App\Models\Period;
use App\Models\PoDocument;
use App\Models\PrItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\QcInspection;
use App\Models\Quotation;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\NotificationCategory;
use Database\Seeders\MaterialHsCodeMasterSeeder;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class NotificationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_delivery_targets_active_users_and_is_idempotent_per_event_key(): void
    {
        $active = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $inactive = User::factory()->create(['role' => 'admin', 'is_active' => false]);
        $service = app(NotificationService::class);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $service->send(
                [$active, $inactive],
                'pr.submitted',
                'pr.submitted:99',
                'New PR',
                'PR submitted',
                route('admin.dashboard', absolute: false),
                'bell',
                ['category' => NotificationCategory::QUOTATION, 'pr_id' => 99, 'pr_number' => 'REQ/08/2026/099'],
            );
        }

        $this->assertCount(1, $active->notifications);
        $this->assertCount(0, $inactive->notifications);
        $this->assertSame('pr.submitted', $active->notifications->first()->data['event']);
        $this->assertSame('pr.submitted:99', $active->notifications->first()->data['event_key']);
    }

    public function test_notification_registered_inside_rolled_back_transaction_is_not_delivered(): void
    {
        $recipient = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        try {
            DB::transaction(function () use ($recipient): void {
                app(NotificationService::class)->send(
                    $recipient,
                    'test.rollback',
                    'test.rollback:1',
                    'Rollback',
                    'Must not persist',
                );

                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
            // Expected rollback.
        }

        $this->assertSame(0, $recipient->notifications()->count());
    }

    public function test_database_notification_persists_when_broadcast_is_queued(): void
    {
        Queue::fake();
        $recipient = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        app(NotificationService::class)->send(
            $recipient,
            'test.queued',
            'test.queued:1',
            'Queued',
            'Database first',
        );

        $this->assertSame(1, $recipient->notifications()->count());
        Queue::assertPushed(BroadcastEvent::class);
    }

    public function test_pr_submission_succeeds_when_synchronous_broadcaster_throws(): void
    {
        $this->seed(MaterialHsCodeMasterSeeder::class);
        $material = MaterialMaster::where('material_code', 'S45C')->firstOrFail();
        $this->installFailingBroadcaster();
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $period = Period::create([
            'name' => 'Notification Failure Period',
            'month' => 8,
            'year' => 2026,
            'status' => 'open',
            'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($purchasing)->post(route('purchasing.requisitions.store'), [
            'period_id' => $period->id,
            'action' => 'submitted',
            'notes' => 'Core transaction remains successful',
            'items' => [[
                'material_master_id' => $material->id,
                'hs_code' => '7209.16.00',
                'material_name' => 'Notification Test Material',
                'quantity' => 1,
                'shape' => 'Flat',
                'thickness' => 5,
                'width' => 700,
                'length' => 2000,
                'weight_needed' => 100,
            ]],
        ]);

        $response->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('purchase_requisitions', ['notes' => 'Core transaction remains successful', 'status' => 'submitted']);
        $this->assertSame(1, $admin->notifications()->count());
    }

    public function test_draft_update_submission_notifies_active_admin_with_structured_payload(): void
    {
        $this->seed(MaterialHsCodeMasterSeeder::class);
        $material = MaterialMaster::where('material_code', 'S45C')->firstOrFail();
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        User::factory()->create(['role' => 'admin', 'is_active' => false]);
        $purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $period = $this->period($admin);
        $pr = PurchaseRequisition::create([
            'period_id' => $period->id,
            'created_by' => $purchasing->id,
            'status' => 'draft',
        ]);
        $item = $pr->items()->create([
            'material_master_id' => $material->id,
            'hs_code' => '7209.16.00',
            'material_name' => 'Draft Material',
            'quantity' => 1,
            'shape' => 'Flat',
            'thickness' => 5,
            'width' => 700,
            'length' => 2000,
            'weight_needed' => 100,
        ]);

        $this->actingAs($purchasing)->put(route('purchasing.requisitions.update', $pr), [
            'period_id' => $period->id,
            'action' => 'submitted',
            'items' => [[
                'id' => $item->id,
                'material_master_id' => $material->id,
                'hs_code' => $item->hs_code,
                'material_name' => $item->material_name,
                'quantity' => $item->quantity,
                'shape' => $item->shape,
                'thickness' => $item->thickness,
                'width' => $item->width,
                'length' => $item->length,
                'weight_needed' => $item->weight_needed,
            ]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $notification = $admin->notifications()->sole();
        $this->assertSame('pr.submitted', $notification->data['event']);
        $this->assertSame($pr->id, $notification->data['pr_id']);
        $this->assertSame(route('admin.requisitions.show', $pr, absolute: false), $notification->data['url']);
    }

    public function test_direct_quotation_review_actions_notify_supplier(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $period = $this->period($admin);
        $pr = PurchaseRequisition::create([
            'period_id' => $period->id,
            'created_by' => $purchasing->id,
            'pr_number' => 'REQ/08/2026/010',
            'status' => 'bidding',
        ]);
        $item = PrItem::create([
            'pr_id' => $pr->id,
            'hs_code' => '7209.16.00',
            'material_name' => 'Notification Quotation Material',
            'quantity' => 1,
            'shape' => 'Flat',
            'thickness' => 2,
            'width' => 1000,
            'length' => 2000,
            'weight_needed' => 100,
        ]);

        $quotations = collect(['accepted', 'rejected', 'revision_requested'])->map(function () use ($pr, $supplier, $item) {
            $quotation = Quotation::create([
                'pr_id' => $pr->id,
                'supplier_id' => $supplier->id,
                'currency' => 'USD',
                'status' => 'submitted',
                'submitted_at' => now(),
            ]);
            $quotation->items()->create([
                'pr_item_id' => $item->id,
                'is_available' => true,
                'price_per_kg' => 2.5,
                'amount' => 250,
            ]);

            return $quotation;
        });

        $this->actingAs($purchasing)->post(route('purchasing.quotations.accept', $quotations[0]))
            ->assertRedirect();
        $this->actingAs($purchasing)->post(route('purchasing.quotations.reject', $quotations[1]), ['reviewer_notes' => 'Not selected'])
            ->assertRedirect();
        $this->actingAs($purchasing)->post(route('purchasing.quotations.request-revision', $quotations[2]), ['revision_note' => 'Please revise'])
            ->assertRedirect();

        $notifications = $supplier->notifications()->get();
        $this->assertCount(3, $notifications);
        $this->assertEqualsCanonicalizing(
            ['quotation.accepted', 'quotation.rejected', 'quotation.revision_requested'],
            $notifications->pluck('data.event')->all(),
        );
        $this->assertTrue($notifications->every(fn ($notification) => $notification->data['category'] === NotificationCategory::QUOTATION));
    }

    public function test_po_arrival_and_document_events_have_entity_payloads_and_same_status_is_silent(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $qc = User::factory()->create(['role' => 'qc', 'is_active' => true]);
        $period = $this->period($admin);
        $pr = PurchaseRequisition::create([
            'period_id' => $period->id,
            'created_by' => $purchasing->id,
            'pr_number' => 'REQ/08/2026/020',
            'status' => 'bidding',
        ]);
        $item = PrItem::create([
            'pr_id' => $pr->id,
            'hs_code' => '7209.16.00',
            'material_name' => 'PO Notification Material',
            'quantity' => 1,
            'shape' => 'Flat',
            'thickness' => 2,
            'width' => 1000,
            'length' => 2000,
            'weight_needed' => 100,
        ]);
        $quotation = Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $supplier->id,
            'currency' => 'USD',
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
        $quotation->items()->create([
            'pr_item_id' => $item->id,
            'is_available' => true,
            'price_per_kg' => 2.5,
            'amount' => 250,
        ]);

        $this->actingAs($purchasing)->post(route('purchasing.purchase-orders.store'), [
            'quotation_ids' => [$quotation->id],
            'estimated_arrival' => now()->addMonth()->toDateString(),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $po = PurchaseOrder::firstOrFail();
        $poNotification = $supplier->notifications()->sole();
        $this->assertSame('po.issued', $poNotification->data['event']);
        $this->assertSame($po->id, $poNotification->data['po_id']);

        $this->actingAs($purchasing)->post(route('purchasing.purchase-orders.confirm-arrival', $po))
            ->assertRedirect();
        $this->assertSame('po.material_arrived', $qc->notifications()->sole()->data['event']);

        $document = PoDocument::where('po_id', $po->id)->firstOrFail();
        $this->actingAs($purchasing)->putJson(route('purchasing.po-documents.update', $document), ['status' => 'pending'])
            ->assertOk();
        $this->assertSame(0, $purchasing->notifications()->count());

        $this->actingAs($purchasing)->putJson(route('purchasing.po-documents.update', $document), ['status' => 'received'])
            ->assertOk();
        $documentNotification = $purchasing->notifications()->sole();
        $this->assertSame('document.status_updated', $documentNotification->data['event']);
        $this->assertSame($document->id, $documentNotification->data['document_id']);
        $this->assertSame(NotificationCategory::DOCUMENT, $documentNotification->data['category']);
    }

    public function test_qc_and_claim_lifecycle_events_reach_the_correct_roles(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $qc = User::factory()->create(['role' => 'qc', 'is_active' => true]);
        $period = $this->period($admin);
        $pr = PurchaseRequisition::create([
            'period_id' => $period->id,
            'created_by' => $purchasing->id,
            'pr_number' => 'REQ/08/2026/030',
            'status' => 'completed',
        ]);
        $item = PrItem::create([
            'pr_id' => $pr->id,
            'hs_code' => '7209.16.00',
            'material_name' => 'QC Material',
            'quantity' => 1,
            'shape' => 'Flat',
            'thickness' => 2,
            'width' => 1000,
            'length' => 2000,
            'weight_needed' => 100,
        ]);
        $quotation = Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $supplier->id,
            'currency' => 'USD',
            'status' => 'accepted',
            'submitted_at' => now(),
        ]);
        $quotation->items()->create(['pr_item_id' => $item->id, 'price_per_kg' => 2, 'amount' => 200]);
        $po = PurchaseOrder::create([
            'supplier_id' => $supplier->id,
            'currency' => 'USD',
            'po_number' => 'PO/08/2026/030',
            'status' => 'waiting_qc',
            'created_by' => $purchasing->id,
        ]);
        $po->quotations()->attach($quotation->id);

        $this->actingAs($qc)->post(route('qc.inspections.store', $po), [
            'items' => [[
                'pr_item_id' => $item->id,
                'status' => 'ok',
                'actual_thickness' => 2,
                'actual_width' => 1000,
                'actual_length' => 2000,
                'actual_weight' => 100,
            ]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $qcNotification = $purchasing->notifications()->latest()->firstOrFail();
        $this->assertSame('qc.inspection_ok', $qcNotification->data['event']);
        $this->assertArrayHasKey('inspection_id', $qcNotification->data);

        $claimPo = PurchaseOrder::create([
            'supplier_id' => $supplier->id,
            'currency' => 'USD',
            'po_number' => 'PO/08/2026/031',
            'status' => 'claim_needed',
            'created_by' => $purchasing->id,
        ]);
        $inspection = QcInspection::create([
            'po_id' => $claimPo->id,
            'inspected_by' => $qc->id,
            'status' => 'ng',
            'inspected_at' => now(),
        ]);

        $this->actingAs($purchasing)->post(route('purchasing.claims.store'), [
            'inspection_id' => $inspection->id,
            'description' => 'Material damaged',
            'resolution_expected' => 'Replacement',
            'deadline' => now()->addWeek()->toDateString(),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $claimCreated = $supplier->notifications()->latest()->firstOrFail();
        $this->assertSame('claim.created', $claimCreated->data['event']);
        $claimId = $claimCreated->data['claim_id'];
        $claim = MaterialClaim::findOrFail($claimId);

        $this->actingAs($supplier)->post(route('supplier.claims.respond', $claim), [
            'supplier_response' => 'Replacement approved',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue($purchasing->notifications()->get()->contains(
            fn ($notification) => $notification->data['event'] === 'claim.responded',
        ));

        $this->actingAs($purchasing)->post(route('purchasing.claims.resolve', $claim))
            ->assertRedirect();
        $this->assertTrue($supplier->notifications()->get()->contains(
            fn ($notification) => $notification->data['event'] === 'claim.resolved',
        ));
    }

    public function test_chat_message_notifies_only_the_active_conversation_partner(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $outsider = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $period = $this->period($admin);
        $pr = PurchaseRequisition::create([
            'period_id' => $period->id,
            'created_by' => $purchasing->id,
            'pr_number' => 'REQ/08/2026/040',
            'status' => 'bidding',
        ]);
        $conversation = Conversation::create([
            'conversable_type' => PurchaseRequisition::class,
            'conversable_id' => $pr->id,
            'purchasing_user_id' => $purchasing->id,
            'supplier_user_id' => $supplier->id,
            'status' => Conversation::STATUS_OPEN,
        ]);

        $this->actingAs($purchasing)->postJson(route('conversations.messages.store', $conversation), [
            'body' => 'Please confirm the quotation.',
        ])->assertOk()->assertJsonPath('success', true);

        $notification = $supplier->notifications()->sole();
        $this->assertSame('conversation.message_created', $notification->data['event']);
        $this->assertSame($conversation->id, $notification->data['conversation_id']);
        $this->assertSame(NotificationCategory::CHAT, $notification->data['category']);
        $this->assertSame(0, $outsider->notifications()->count());
    }

    private function period(User $creator): Period
    {
        return Period::create([
            'name' => 'Notification Integration Period',
            'month' => 8,
            'year' => 2026,
            'status' => 'open',
            'created_by' => $creator->id,
        ]);
    }

    private function installFailingBroadcaster(): void
    {
        config([
            'broadcasting.default' => 'mission2_failing',
            'broadcasting.connections.mission2_failing' => ['driver' => 'mission2_failing'],
            'queue.default' => 'sync',
        ]);

        app(BroadcastManager::class)->extend('mission2_failing', fn () => new class implements Broadcaster
        {
            public function auth($request)
            {
                return null;
            }

            public function validAuthenticationResponse($request, $result)
            {
                return $result;
            }

            public function broadcast(array $channels, $event, array $payload = []): void
            {
                throw new BroadcastException('Reverb unavailable');
            }
        });
    }
}
