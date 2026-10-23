<?php

namespace Tests\Feature\Procurement;

use App\Models\Conversation;
use App\Models\PrItemAward;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\User;
use App\Services\PrItemAwardService;
use App\Services\PurchaseOrderGenerationService;
use App\Support\ConversationPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A quotation that an item award or a Purchase Order refers to is frozen for
 * review. The conversation and supplier paths already refused to change it;
 * the Purchasing quotation page did not, so it could move an awarded quotation
 * to revision_requested or rejected. The supplier could then not submit the
 * requested revision and PO generation refused the award, leaving it stranded.
 */
class QuotationReviewInvariantTest extends TestCase
{
    use ProcurementFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurementActors();
    }

    public function test_purchasing_page_cannot_request_a_revision_once_an_item_is_awarded(): void
    {
        [, $quotation, $award] = $this->awardedQuotation();

        $this->actingAs($this->purchasing)
            ->post(route('purchasing.quotations.request-revision', $quotation), ['revision_note' => 'Please extend the validity.'])
            ->assertRedirect()
            ->assertSessionHas('error', __('notifications.conversation.quotation_in_use'));

        $this->assertSame(Quotation::STATUS_SUBMITTED, $quotation->fresh()->status);
        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $this->supplier->id]);

        // The award stays finalizable into a Purchase Order.
        $po = app(PurchaseOrderGenerationService::class)->generateFromAwards([$award->id], $this->purchasing)->sole();
        $this->assertSame($po->id, $award->fresh()->purchase_order_id);
        $this->assertSame(Quotation::STATUS_ACCEPTED, $quotation->fresh()->status);
    }

    public function test_purchasing_page_cannot_reject_once_an_item_is_awarded(): void
    {
        [, $quotation] = $this->awardedQuotation();

        $this->actingAs($this->purchasing)
            ->post(route('purchasing.quotations.reject', $quotation), ['reviewer_notes' => 'Changed our mind.'])
            ->assertRedirect()
            ->assertSessionHas('error', __('notifications.conversation.quotation_in_use'));

        $this->assertSame(Quotation::STATUS_SUBMITTED, $quotation->fresh()->status);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $this->supplier->id]);
    }

    public function test_purchasing_page_cannot_accept_once_an_item_is_awarded(): void
    {
        [, $quotation] = $this->awardedQuotation();

        $this->actingAs($this->purchasing)
            ->post(route('purchasing.quotations.accept', $quotation))
            ->assertRedirect()
            ->assertSessionHas('error', __('notifications.conversation.quotation_in_use'));

        $this->assertSame(Quotation::STATUS_SUBMITTED, $quotation->fresh()->status);
    }

    public function test_review_actions_on_an_unreferenced_quotation_are_unchanged(): void
    {
        $pr = $this->createRequisition([[], []]);
        $toAccept = $this->createQuotation($pr);
        $toReject = $this->createQuotation($pr, supplier: $this->anotherSupplier());
        $toRevise = $this->createQuotation($pr, supplier: $this->anotherSupplier());

        $this->actingAs($this->purchasing)
            ->post(route('purchasing.quotations.accept', $toAccept))
            ->assertSessionHas('success');
        $this->actingAs($this->purchasing)
            ->post(route('purchasing.quotations.reject', $toReject), ['reviewer_notes' => 'Higher price.'])
            ->assertSessionHas('success');
        $this->actingAs($this->purchasing)
            ->post(route('purchasing.quotations.request-revision', $toRevise), ['revision_note' => 'Check the price.'])
            ->assertSessionHas('success');

        $this->assertSame(Quotation::STATUS_ACCEPTED, $toAccept->fresh()->status);
        $this->assertSame(Quotation::STATUS_REJECTED, $toReject->fresh()->status);
        $this->assertSame(Quotation::STATUS_REVISION_REQUESTED, $toRevise->fresh()->status);
    }

    public function test_quotation_page_offers_review_actions_only_when_the_server_allows_them(): void
    {
        [$pr, $awarded] = $this->awardedQuotation();
        $open = $this->createQuotation($pr, supplier: $this->anotherSupplier());

        $this->actingAs($this->purchasing)
            ->get(route('purchasing.quotations.show', $awarded))
            ->assertOk()
            ->assertSee(__('notifications.conversation.quotation_in_use'))
            ->assertDontSee(route('purchasing.quotations.accept', $awarded), false)
            ->assertDontSee(route('purchasing.quotations.reject', $awarded), false)
            ->assertDontSee(route('purchasing.quotations.request-revision', $awarded), false);

        $this->actingAs($this->purchasing)
            ->get(route('purchasing.quotations.show', $open))
            ->assertOk()
            ->assertDontSee(__('notifications.conversation.quotation_in_use'))
            ->assertSee(route('purchasing.quotations.accept', $open), false)
            ->assertSee(route('purchasing.quotations.reject', $open), false)
            ->assertSee(route('purchasing.quotations.request-revision', $open), false);
    }

    public function test_conversation_offers_only_the_actions_the_server_accepts(): void
    {
        [$pr, $quotation] = $this->awardedQuotation();
        $conversation = $this->conversationFor($pr);

        $keys = collect(ConversationPresenter::quickActions($conversation, $this->purchasing))->pluck('key');
        $this->assertNotContains('accept_quotation', $keys);
        $this->assertNotContains('reject_quotation', $keys);
        $this->assertNotContains('request_price_revision', $keys);
        // Message-only follow-ups do not change the quotation and stay available.
        $this->assertContains('request_validity_extension', $keys);

        $this->actingAs($this->purchasing)
            ->postJson(route('conversations.quick-action', $conversation), ['action' => 'reject_quotation', 'note' => 'No.'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('action');
        $this->assertSame(Quotation::STATUS_SUBMITTED, $quotation->fresh()->status);
    }

    public function test_supplier_is_not_offered_a_revision_that_the_server_refuses(): void
    {
        // An inconsistent state the old Purchasing page could produce.
        [$pr, $quotation] = $this->awardedQuotation();
        DB::table('quotations')->where('id', $quotation->id)->update(['status' => Quotation::STATUS_REVISION_REQUESTED]);
        $conversation = $this->conversationFor($pr);

        $this->assertSame([], ConversationPresenter::quickActions($conversation, $this->supplier));

        $this->actingAs($this->supplier)
            ->get(route('supplier.quotations.create', $pr))
            ->assertRedirect(route('supplier.quotations.show', $quotation))
            ->assertSessionHas('error', __('supplier.copy.this_quotation_is_locked_or_used_by_an_award_or_purchase_order_and_cannot_be_changed'));
    }

    public function test_reference_rule_covers_awards_and_purchase_orders_including_trashed_ones(): void
    {
        [$pr, $quotation, $award] = $this->awardedQuotation();
        $unreferenced = $this->createQuotation($pr, supplier: $this->anotherSupplier());
        $this->assertFalse($unreferenced->isReferencedByAwardOrPurchaseOrder());
        $this->assertTrue($quotation->isReferencedByAwardOrPurchaseOrder());

        $po = app(PurchaseOrderGenerationService::class)->generateFromAwards([$award->id], $this->purchasing)->sole();
        $po->delete();
        PrItemAward::whereKey($award->id)->update([
            'quotation_id' => $unreferenced->id,
            'quotation_item_id' => $unreferenced->items->firstWhere('pr_item_id', $award->pr_item_id)->id,
        ]);

        // Only the (soft-deleted) Purchase Order still refers to the quotation.
        $this->assertTrue($quotation->fresh()->isReferencedByAwardOrPurchaseOrder());
    }

    public function test_reconcile_reports_unassigned_awards_on_quotations_that_can_no_longer_be_ordered(): void
    {
        [, $quotation] = $this->awardedQuotation();
        $this->assertSame(0, $this->reconcileMetric('unassigned_award_on_ineligible_quotation_count'));

        DB::table('quotations')->where('id', $quotation->id)->update(['status' => Quotation::STATUS_REJECTED]);
        $this->assertSame(1, $this->reconcileMetric('unassigned_award_on_ineligible_quotation_count'));
    }

    /**
     * @return array{0: PurchaseRequisition, 1: Quotation, 2: PrItemAward}
     */
    private function awardedQuotation(): array
    {
        $pr = $this->createRequisition([[], []]);
        $quotation = $this->createQuotation($pr);
        $prItem = $pr->items->first();
        $award = app(PrItemAwardService::class)->awardItem(
            $prItem,
            $quotation->items->firstWhere('pr_item_id', $prItem->id),
            $this->purchasing,
        );

        return [$pr, $quotation, $award];
    }

    private function anotherSupplier(): User
    {
        return User::factory()->create(['role' => 'supplier', 'is_active' => true]);
    }

    private function conversationFor(PurchaseRequisition $pr): Conversation
    {
        return Conversation::create([
            'conversable_type' => PurchaseRequisition::class,
            'conversable_id' => $pr->id,
            'purchasing_user_id' => $this->purchasing->id,
            'supplier_user_id' => $this->supplier->id,
            'status' => Conversation::STATUS_OPEN,
        ]);
    }

    private function reconcileMetric(string $metric): mixed
    {
        $this->assertSame(0, Artisan::call('procurement:reconcile', ['--json' => true]));

        return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['metrics'][$metric];
    }
}
