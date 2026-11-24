<?php

namespace Tests\Feature\Procurement;

use App\Models\PrSupplierInvitationEvent;
use App\Models\PurchaseRequisition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class PurchaseRequisitionSupplierInvitationTest extends TestCase
{
    use ProcurementFixtures;
    use RefreshDatabase;

    private User $second;

    private User $third;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurementActors();
        $this->second = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $this->third = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
    }

    private function invitedRequisition(string $status = 'submitted', array $invited = []): PurchaseRequisition
    {
        $pr = $this->createRequisition([[]], $status);
        $pr->invitedSuppliers()->attach(
            collect($invited ?: [$this->supplier])->mapWithKeys(fn (User $u) => [$u->id => ['invited_at' => now()]])->all()
        );

        return $pr;
    }

    private function store(PurchaseRequisition $pr, array $ids, ?User $actor = null)
    {
        return $this->actingAs($actor ?? $this->purchasing)
            ->post(route('purchasing.requisitions.suppliers.store', $pr), ['supplier_ids' => $ids]);
    }

    public function test_purchasing_adds_suppliers_to_a_submitted_requisition_with_audit_and_notification(): void
    {
        $pr = $this->invitedRequisition('submitted');
        $otherPurchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);

        $this->store($pr, [$this->second->id, $this->third->id], $otherPurchasing)
            ->assertRedirect(route('purchasing.requisitions.show', $pr))
            ->assertSessionHas('success');

        $this->assertEqualsCanonicalizing(
            [$this->supplier->id, $this->second->id, $this->third->id],
            $pr->invitedSuppliers()->pluck('users.id')->all()
        );
        $this->assertSame(2, PrSupplierInvitationEvent::where('pr_id', $pr->id)->where('event', 'added')->where('actor_id', $otherPurchasing->id)->count());
        $this->assertSame(1, $this->second->notifications()->count());
        $this->assertSame(0, $this->supplier->notifications()->count());
    }

    public function test_bidding_requisition_accepts_new_suppliers(): void
    {
        $pr = $this->invitedRequisition('bidding');

        $this->store($pr, [$this->second->id])->assertSessionHas('success');

        $this->assertTrue($pr->invitedSuppliers()->where('users.id', $this->second->id)->exists());
    }

    public function test_open_requisition_without_invitations_is_not_restricted_by_adding(): void
    {
        $pr = $this->createRequisition([[]], 'submitted');

        $this->store($pr, [$this->second->id])->assertSessionHasErrors('supplier_ids');

        $this->assertSame(0, $pr->invitedSuppliers()->count());
        $this->assertDatabaseCount('pr_supplier_invitation_events', 0);
    }

    public function test_non_open_statuses_are_refused(): void
    {
        foreach (['draft', 'rejected', 'completed'] as $status) {
            $pr = $this->invitedRequisition($status);

            $this->store($pr, [$this->second->id])->assertSessionHasErrors('supplier_ids');

            $this->assertFalse($pr->invitedSuppliers()->where('users.id', $this->second->id)->exists(), $status);
        }
    }

    public function test_ineligible_or_already_invited_suppliers_are_refused_without_partial_writes(): void
    {
        $pr = $this->invitedRequisition('submitted');
        $inactive = User::factory()->create(['role' => 'supplier', 'is_active' => false]);

        $this->store($pr, [$this->second->id, $inactive->id])->assertSessionHasErrors('supplier_ids');
        $this->store($pr, [$this->supplier->id])->assertSessionHasErrors('supplier_ids');
        $this->store($pr, [$this->purchasing->id])->assertSessionHasErrors('supplier_ids');

        $this->assertSame([$this->supplier->id], $pr->invitedSuppliers()->pluck('users.id')->all());
        $this->assertDatabaseCount('pr_supplier_invitation_events', 0);
    }

    public function test_only_purchasing_can_change_suppliers(): void
    {
        $pr = $this->invitedRequisition('submitted');

        foreach (['admin', 'supplier', 'qc'] as $role) {
            $actor = User::factory()->create(['role' => $role, 'is_active' => true]);
            $this->store($pr, [$this->second->id], $actor)->assertForbidden();
            $this->actingAs($actor)
                ->delete(route('purchasing.requisitions.suppliers.destroy', [$pr, $this->supplier]))
                ->assertForbidden();
        }

        $this->assertSame([$this->supplier->id], $pr->invitedSuppliers()->pluck('users.id')->all());
    }

    public function test_supplier_without_quotation_can_be_removed_with_audit(): void
    {
        $pr = $this->invitedRequisition('submitted', [$this->supplier, $this->second]);
        cache()->put('supplier_dashboard_widgets_'.$this->second->id, ['belumDirespons' => 3], 300);

        $this->actingAs($this->purchasing)
            ->delete(route('purchasing.requisitions.suppliers.destroy', [$pr, $this->second]), [
                'reason' => 'Internal sourcing reallocation',
            ])
            ->assertRedirect(route('purchasing.requisitions.show', $pr))
            ->assertSessionHas('success');

        $this->assertSame([$this->supplier->id], $pr->invitedSuppliers()->pluck('users.id')->all());
        $this->assertDatabaseHas('purchase_requisition_suppliers', [
            'pr_id' => $pr->id,
            'supplier_id' => $this->second->id,
            'cancelled_by' => $this->purchasing->id,
            'cancellation_reason' => 'Internal sourcing reallocation',
        ]);
        $this->assertNotNull(
            DB::table('purchase_requisition_suppliers')
                ->where('pr_id', $pr->id)
                ->where('supplier_id', $this->second->id)
                ->value('cancelled_at')
        );
        $this->assertDatabaseHas('pr_supplier_invitation_events', [
            'pr_id' => $pr->id,
            'supplier_id' => $this->second->id,
            'event' => 'removed',
            'reason' => 'Internal sourcing reallocation',
            'actor_id' => $this->purchasing->id,
        ]);
        $this->assertFalse(cache()->has('supplier_dashboard_widgets_'.$this->second->id));

        $notification = $this->second->notifications()->latest()->first();
        $this->assertNotNull($notification);
        $this->assertSame('pr.supplier_removed', $notification->data['event'] ?? null);
        $this->assertSame($pr->hash, $notification->data['pr_hash'] ?? null);
        $this->assertStringNotContainsString('Internal sourcing reallocation', json_encode($notification->data));
        $this->assertStringNotContainsString($this->purchasing->name, json_encode($notification->data));
    }

    public function test_supplier_with_quotation_including_soft_deleted_cannot_be_removed(): void
    {
        $pr = $this->invitedRequisition('submitted', [$this->supplier, $this->second]);
        $quotation = $this->createQuotation($pr, [], 'draft', $this->second);

        $this->actingAs($this->purchasing)
            ->delete(route('purchasing.requisitions.suppliers.destroy', [$pr, $this->second]))
            ->assertSessionHasErrors('supplier');

        $quotation->delete();

        $this->actingAs($this->purchasing)
            ->delete(route('purchasing.requisitions.suppliers.destroy', [$pr, $this->second]))
            ->assertSessionHasErrors('supplier');

        $this->assertTrue($pr->invitedSuppliers()->where('users.id', $this->second->id)->exists());
    }

    public function test_last_invited_supplier_cannot_be_removed_because_it_would_open_the_requisition(): void
    {
        $pr = $this->invitedRequisition('submitted');

        $this->actingAs($this->purchasing)
            ->delete(route('purchasing.requisitions.suppliers.destroy', [$pr, $this->supplier]))
            ->assertSessionHasErrors('supplier');

        $this->assertSame(1, $pr->invitedSuppliers()->count());
    }

    public function test_added_supplier_can_now_see_the_requisition_and_removed_supplier_sees_cancelled_summary_without_specs(): void
    {
        $pr = $this->invitedRequisition('submitted', [$this->supplier, $this->third]);
        $pr->update(['notes' => 'CONFIDENTIAL-SPEC-NOTE']);
        $neverInvited = User::factory()->create(['role' => 'supplier', 'is_active' => true]);

        $this->assertFalse($pr->fresh()->isVisibleToSupplier($this->second->id));
        $this->assertFalse($pr->fresh()->isAccessibleBySupplier($this->second->id));

        $this->store($pr, [$this->second->id]);
        $this->assertTrue($pr->fresh()->isVisibleToSupplier($this->second->id));

        $this->actingAs($this->purchasing)->delete(route('purchasing.requisitions.suppliers.destroy', [$pr, $this->second]), [
            'reason' => 'SECRET-INTERNAL-REASON',
        ]);

        $freshPr = $pr->fresh();
        $this->assertFalse($freshPr->isVisibleToSupplier($this->second->id));
        $this->assertTrue($freshPr->isAccessibleBySupplier($this->second->id));
        $this->assertTrue($freshPr->isCancelledForSupplier($this->second->id));
        $this->assertFalse($freshPr->isCancelledForSupplier($this->supplier->id));

        // Removed supplier sees minimal cancelled detail (200 OK) with zero item specs/notes/internal reason.
        $detailHtml = $this->actingAs($this->second)
            ->get(route('supplier.requests.show', $pr))
            ->assertOk()
            ->assertSee($pr->pr_number)
            ->assertSee(__('status.pr.cancelled'))
            ->getContent();

        $this->assertStringNotContainsString('Mission 01 Material 1', $detailHtml);
        $this->assertStringNotContainsString('7209.16.00', $detailHtml);
        $this->assertStringNotContainsString('CONFIDENTIAL-SPEC-NOTE', $detailHtml);
        $this->assertStringNotContainsString('SECRET-INTERNAL-REASON', $detailHtml);
        $this->assertStringNotContainsString($this->purchasing->name, $detailHtml);

        // Create route redirects cleanly to requests.show; store / importPreview / exportForm return 409 Conflict JSON.
        $this->actingAs($this->second)
            ->get(route('supplier.quotations.create', $pr))
            ->assertRedirect(route('supplier.requests.show', $pr))
            ->assertSessionHas('warning');

        $this->actingAs($this->second)
            ->postJson(route('supplier.quotations.store', $pr), ['action' => 'draft', 'currency' => 'USD', 'items' => []])
            ->assertStatus(409)
            ->assertJsonPath('code', 'invitation_cancelled');

        $this->actingAs($this->second)
            ->postJson(route('supplier.quotations.import-preview', $pr), [])
            ->assertStatus(409)
            ->assertJsonPath('code', 'invitation_cancelled');

        $this->actingAs($this->second)
            ->postJson(route('supplier.quotations.export-form', $pr), [])
            ->assertStatus(409)
            ->assertJsonPath('code', 'invitation_cancelled');

        // Never-invited supplier still gets 403/404 (IDOR isolation preserved).
        $this->actingAs($neverInvited)->get(route('supplier.requests.show', $pr))->assertNotFound();
        $this->actingAs($neverInvited)->get(route('supplier.quotations.create', $pr))->assertForbidden();
        $this->actingAs($neverInvited)->getJson(route('supplier.quotations.availability', $pr))->assertForbidden();
    }

    public function test_removed_supplier_can_be_reinvited_and_removed_again_with_fresh_notifications(): void
    {
        $pr = $this->invitedRequisition('submitted', [$this->supplier, $this->second]);

        // First removal
        $this->actingAs($this->purchasing)
            ->delete(route('purchasing.requisitions.suppliers.destroy', [$pr, $this->second]))
            ->assertSessionHas('success');
        $this->assertTrue($pr->fresh()->isCancelledForSupplier($this->second->id));
        $this->assertSame(1, $this->second->notifications()->where('data->event', 'pr.supplier_removed')->count());

        // Re-invite reactivates existing pivot row without unique constraint error
        $this->store($pr, [$this->second->id])
            ->assertRedirect(route('purchasing.requisitions.show', $pr))
            ->assertSessionHas('success');

        $this->assertFalse($pr->fresh()->isCancelledForSupplier($this->second->id));
        $this->assertTrue($pr->fresh()->isVisibleToSupplier($this->second->id));
        $this->assertSame(1, DB::table('purchase_requisition_suppliers')->where('pr_id', $pr->id)->where('supplier_id', $this->second->id)->count());
        $this->assertNull(DB::table('purchase_requisition_suppliers')->where('pr_id', $pr->id)->where('supplier_id', $this->second->id)->value('cancelled_at'));
        $this->assertSame(1, $this->second->notifications()->where('data->event', 'pr.supplier_invited')->count());

        // Second removal sends a fresh notification (distinct event-scoped eventKey)
        $this->actingAs($this->purchasing)
            ->delete(route('purchasing.requisitions.suppliers.destroy', [$pr, $this->second]))
            ->assertSessionHas('success');
        $this->assertSame(2, $this->second->notifications()->where('data->event', 'pr.supplier_removed')->count());
    }

    public function test_audit_events_are_append_only(): void
    {
        $pr = $this->invitedRequisition('submitted');
        $this->store($pr, [$this->second->id]);
        $event = PrSupplierInvitationEvent::firstOrFail();

        $this->expectException(LogicException::class);
        $event->update(['event' => 'removed']);
    }

    public function test_detail_page_shows_manage_dialog_only_for_open_invited_requisitions_and_history(): void
    {
        $pr = $this->invitedRequisition('submitted');
        $this->store($pr, [$this->second->id]);

        $this->actingAs($this->purchasing)
            ->get(route('purchasing.requisitions.show', $pr))
            ->assertOk()
            ->assertSee('id="btn-manage-suppliers"', false)
            ->assertSee('role="dialog"', false)
            ->assertSee('for="invite-supplier-'.$this->third->id.'"', false)
            ->assertSee(__('purchasing.copy.invitation_history'));

        $open = $this->createRequisition([[]], 'submitted');
        $draft = $this->invitedRequisition('draft');

        foreach ([$open, $draft] as $other) {
            $this->actingAs($this->purchasing)
                ->get(route('purchasing.requisitions.show', $other))
                ->assertOk()
                ->assertDontSee('id="btn-manage-suppliers"', false);
        }
    }

    public function test_concurrent_status_change_is_rechecked_under_lock(): void
    {
        $pr = $this->invitedRequisition('submitted');
        DB::table('purchase_requisitions')->where('id', $pr->id)->update(['status' => 'completed']);

        $this->store($pr, [$this->second->id])->assertSessionHasErrors('supplier_ids');
        $this->assertSame(1, $pr->invitedSuppliers()->count());
    }
}
