<?php

namespace Tests\Feature\Procurement;

use App\Exceptions\ProcurementAvailabilityException;
use App\Models\Conversation;
use App\Models\PrItemAward;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Services\ConversationMessageService;
use App\Services\DocumentChain\DocumentChainResolverService;
use App\Services\NotificationService;
use App\Services\NotificationUrlResolver;
use App\Services\PrItemAwardService;
use App\Services\Procurement\PurchaseRequisitionCancellationService;
use App\Services\Procurement\QuotationSubmissionService;
use App\Services\Procurement\SlaWatchdogService;
use App\Services\PurchaseOrderGenerationService;
use App\Support\Auth\SessionLifecycle;
use App\Support\NotificationCategory;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class PurchaseRequisitionCancellationTest extends TestCase
{
    use ProcurementFixtures;
    use RefreshDatabase;

    private User $secondSupplier;

    private User $neverInvitedSupplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurementActors();
        $this->secondSupplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $this->neverInvitedSupplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
    }

    public function test_any_active_purchasing_user_can_cancel_submitted_pr_with_zero_quotations_and_mandatory_reason(): void
    {
        $pr = $this->createRequisition([[]], PurchaseRequisition::STATUS_SUBMITTED);
        $pr->update(['notes' => 'SENSITIVE-ENGINEERING-NOTE']);
        $pr->invitedSuppliers()->attach([
            $this->supplier->id => ['invited_at' => now()],
            $this->secondSupplier->id => ['invited_at' => now()],
        ]);

        // Pre-cancel secondSupplier earlier to verify they do not receive a duplicate whole-PR notification.
        $pr->allInvitedSuppliers()->updateExistingPivot($this->secondSupplier->id, [
            'cancelled_at' => now()->subHour(),
            'cancelled_by' => $this->purchasing->id,
        ]);

        $otherPurchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        Cache::put('purchasing_dashboard_widgets', ['prAktif' => 5], 300);
        Cache::put('supplier_dashboard_widgets_'.$this->supplier->id, ['belumDirespons' => 2], 300);

        $this->actingAs($otherPurchasing)
            ->postJson(route('purchasing.requisitions.cancel', $pr), [
                'cancellation_reason' => 'Budget reallocated to Q4 overhaul',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $pr->refresh();
        $this->assertSame(PurchaseRequisition::STATUS_CANCELLED, $pr->status);
        $this->assertSame($otherPurchasing->id, $pr->cancelled_by);
        $this->assertSame('Budget reallocated to Q4 overhaul', $pr->cancellation_reason);
        $this->assertNotNull($pr->cancelled_at);
        $this->assertFalse(Cache::has('purchasing_dashboard_widgets'));
        $this->assertFalse(Cache::has('supplier_dashboard_widgets_'.$this->supplier->id));

        // Active invited supplier receives neutral pr.cancelled notification; previously removed supplier does not get spammed again.
        $notification = $this->supplier->notifications()->where('data->event', 'pr.cancelled')->first();
        $this->assertNotNull($notification);
        $this->assertSame($pr->hash, $notification->data['pr_hash'] ?? null);
        $this->assertStringNotContainsString('Budget reallocated to Q4 overhaul', json_encode($notification->data));
        $this->assertStringNotContainsString($otherPurchasing->name, json_encode($notification->data));
        $this->assertSame(0, $this->secondSupplier->notifications()->where('data->event', 'pr.cancelled')->count());
        $this->assertSame(0, $this->neverInvitedSupplier->notifications()->count());

        // Supplier sees Cancelled summary with zero specs or internal reason.
        $html = $this->actingAs($this->supplier)
            ->get(route('supplier.requests.show', $pr))
            ->assertOk()
            ->assertSee($pr->pr_number)
            ->assertSee(__('status.pr.cancelled'))
            ->getContent();
        $this->assertStringNotContainsString('Mission 01 Material 1', $html);
        $this->assertStringNotContainsString('SENSITIVE-ENGINEERING-NOTE', $html);
        $this->assertStringNotContainsString('Budget reallocated to Q4 overhaul', $html);
        $this->assertStringNotContainsString($otherPurchasing->name, $html);

        // Purchasing and Admin see internal cancellation reason.
        $this->actingAs($this->purchasing)
            ->get(route('purchasing.requisitions.show', $pr))
            ->assertOk()
            ->assertSee('Budget reallocated to Q4 overhaul');

        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->actingAs($admin)
            ->get(route('admin.requisitions.show', $pr))
            ->assertOk()
            ->assertSee('Budget reallocated to Q4 overhaul');
    }

    public function test_only_purchasing_can_cancel_and_reason_is_mandatory(): void
    {
        $pr = $this->createRequisition([[]], PurchaseRequisition::STATUS_SUBMITTED);

        foreach (['admin', 'supplier', 'qc', 'finance'] as $role) {
            $actor = User::factory()->create(['role' => $role, 'is_active' => true]);
            $this->actingAs($actor)
                ->postJson(route('purchasing.requisitions.cancel', $pr), ['cancellation_reason' => 'Valid reason here'])
                ->assertForbidden();
        }

        $this->actingAs($this->purchasing)
            ->postJson(route('purchasing.requisitions.cancel', $pr), ['cancellation_reason' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cancellation_reason');

        $this->actingAs($this->purchasing)
            ->postJson(route('purchasing.requisitions.cancel', $pr), ['cancellation_reason' => 'abc'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cancellation_reason');

        $this->assertSame(PurchaseRequisition::STATUS_SUBMITTED, $pr->fresh()->status);
    }

    public function test_cancellation_is_blocked_for_non_submitted_or_any_existing_quotation_including_draft_and_trashed(): void
    {
        foreach (['draft', 'bidding', 'completed', 'rejected', 'cancelled'] as $status) {
            $pr = $this->createRequisition([[]], $status);
            $this->actingAs($this->purchasing)
                ->postJson(route('purchasing.requisitions.cancel', $pr), ['cancellation_reason' => 'Cannot cancel this state'])
                ->assertStatus(422);
            $this->assertSame($status, $pr->fresh()->status);
        }

        // Submitted PR with a draft quotation cannot be cancelled.
        $withDraft = $this->createRequisition([[]], PurchaseRequisition::STATUS_SUBMITTED);
        $draft = $this->createQuotation($withDraft, [], Quotation::STATUS_DRAFT, $this->supplier);

        $this->actingAs($this->purchasing)
            ->postJson(route('purchasing.requisitions.cancel', $withDraft), ['cancellation_reason' => 'Draft exists'])
            ->assertStatus(422);
        $this->assertSame(PurchaseRequisition::STATUS_SUBMITTED, $withDraft->fresh()->status);

        // Even after soft-deleting the quotation, cancellation stays blocked.
        $draft->delete();
        $this->actingAs($this->purchasing)
            ->postJson(route('purchasing.requisitions.cancel', $withDraft), ['cancellation_reason' => 'Trashed draft exists'])
            ->assertStatus(422);
        $this->assertSame(PurchaseRequisition::STATUS_SUBMITTED, $withDraft->fresh()->status);
    }

    public function test_lock_level_toctou_guard_rejects_stale_quotation_save_after_supplier_removal_or_pr_cancellation(): void
    {
        $pr = $this->createRequisition([[]], PurchaseRequisition::STATUS_SUBMITTED);
        $pr->invitedSuppliers()->attach([
            $this->supplier->id => ['invited_at' => now()],
            $this->secondSupplier->id => ['invited_at' => now()],
        ]);
        $item = $pr->items->firstOrFail();

        // Simulate stale PR model already loaded before Purchasing removes supplier.
        $stalePr = PurchaseRequisition::with('items')->findOrFail($pr->id);
        $this->actingAs($this->purchasing)
            ->delete(route('purchasing.requisitions.suppliers.destroy', [$pr, $this->supplier]))
            ->assertSessionHas('success');

        $this->actingAs($this->supplier);
        $request = Request::create(route('supplier.quotations.store', $pr), 'POST', [
            'action' => 'draft',
            'currency' => 'USD',
            'items' => [[
                'pr_item_id' => $item->id,
                'is_available' => '1',
                'available_qty' => 10,
                'offered_weight_per_unit' => 3,
                'price_per_kg' => 2.5,
            ]],
        ]);

        try {
            app(QuotationSubmissionService::class)->save(
                $request,
                $stalePr,
                $stalePr->id,
                [
                    'currency' => 'USD',
                    'items' => [['pr_item_id' => $item->id, 'notes' => null]],
                ],
                false,
            );
            $this->fail('Expected ProcurementAvailabilityException when supplier invitation was cancelled.');
        } catch (ProcurementAvailabilityException $exception) {
            $this->assertSame(ProcurementAvailabilityException::CODE_INVITATION_CANCELLED, $exception->errorCode);
        }

        $this->assertDatabaseMissing('quotations', ['pr_id' => $pr->id, 'supplier_id' => $this->supplier->id]);

        // Now cancel whole PR and verify secondSupplier with stale model is rejected under lock.
        $stalePr2 = PurchaseRequisition::with('items')->findOrFail($pr->id);
        $this->actingAs($this->purchasing)
            ->postJson(route('purchasing.requisitions.cancel', $pr), ['cancellation_reason' => 'Cancelled while supplier typing'])
            ->assertOk();

        $this->actingAs($this->secondSupplier);
        try {
            app(QuotationSubmissionService::class)->save(
                $request,
                $stalePr2,
                $stalePr2->id,
                [
                    'currency' => 'USD',
                    'items' => [['pr_item_id' => $item->id, 'notes' => null]],
                ],
                false,
            );
            $this->fail('Expected ProcurementAvailabilityException when PR was cancelled.');
        } catch (ProcurementAvailabilityException $exception) {
            $this->assertSame(ProcurementAvailabilityException::CODE_PR_CANCELLED, $exception->errorCode);
        }

        $this->assertDatabaseMissing('quotations', ['pr_id' => $pr->id, 'supplier_id' => $this->secondSupplier->id]);
    }

    public function test_open_pr_cancellation_notifies_import_suppliers_and_keeps_period_visible_with_zero_unresponded(): void
    {
        $pr = $this->createRequisition([[]], PurchaseRequisition::STATUS_SUBMITTED);

        $this->actingAs($this->purchasing)
            ->postJson(route('purchasing.requisitions.cancel', $pr), ['cancellation_reason' => 'No longer required'])
            ->assertOk();

        $this->assertSame(1, $this->supplier->notifications()->where('data->event', 'pr.cancelled')->count());
        $this->assertSame(1, $this->secondSupplier->notifications()->where('data->event', 'pr.cancelled')->count());

        // Period remains visible on supplier.quotations.index with unresponded_prs = 0.
        $response = $this->actingAs($this->supplier)->get(route('supplier.quotations.index'))->assertOk();
        $period = $response->viewData('periods')->firstWhere('id', $pr->period_id);
        $this->assertNotNull($period);
        $this->assertSame(0, (int) $period->unresponded_prs);
        $this->assertSame(1, (int) $period->total_prs);

        // Period DataTable shows Cancelled status chip and Detail link to supplier.requests.show.
        $rows = $this->actingAs($this->supplier)
            ->getJson(route('supplier.quotations.period', $pr->period_id).'?draw=1&start=0&length=20&status=cancelled', ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame($pr->pr_number, $rows[0]['pr_number_display']);
        $this->assertSame('-', $rows[0]['item_count']);
        $this->assertStringContainsString(__('status.pr.cancelled'), $rows[0]['status_badge']);
        $this->assertStringContainsString(route('supplier.requests.show', $pr), $rows[0]['action']);
    }

    public function test_availability_endpoint_is_passive_and_reports_open_then_cancelled(): void
    {
        $pr = $this->createRequisition([[]], PurchaseRequisition::STATUS_SUBMITTED);
        $pr->invitedSuppliers()->attach([
            $this->supplier->id => ['invited_at' => now()],
            $this->secondSupplier->id => ['invited_at' => now()],
        ]);

        $initialLastActivity = now()->subMinutes(5)->timestamp;
        $this->actingAs($this->supplier)
            ->withSession([SessionLifecycle::LAST_ACTIVITY_KEY => $initialLastActivity])
            ->getJson(route('supplier.quotations.availability', $pr))
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('status', 'open');

        // Passive poll route must NOT extend idle session timestamp.
        $this->assertSame($initialLastActivity, session(SessionLifecycle::LAST_ACTIVITY_KEY));

        // After Purchasing removes supplier, endpoint immediately reports cancelled.
        $this->actingAs($this->purchasing)
            ->delete(route('purchasing.requisitions.suppliers.destroy', [$pr, $this->supplier]))
            ->assertSessionHas('success');

        $this->actingAs($this->supplier)
            ->getJson(route('supplier.quotations.availability', $pr))
            ->assertOk()
            ->assertJsonPath('available', false)
            ->assertJsonPath('status', 'cancelled')
            ->assertJsonPath('code', ProcurementAvailabilityException::CODE_INVITATION_CANCELLED)
            ->assertJsonPath('redirect_url', route('supplier.requests.show', $pr));
    }

    public function test_notification_url_resolver_and_document_chain_handle_cancelled_supplier_pov_safely(): void
    {
        $pr = $this->createRequisition([[]], PurchaseRequisition::STATUS_SUBMITTED);
        $pr->invitedSuppliers()->attach([
            $this->supplier->id => ['invited_at' => now()],
            $this->secondSupplier->id => ['invited_at' => now()],
        ]);

        // Historical pr.supplier_invited notification pointing to supplier.quotations.create.
        app(NotificationService::class)->send(
            $this->supplier,
            'pr.supplier_invited',
            'pr.supplier_invited:'.$pr->id.':test-1',
            'purchasing.notify.pr_invited_title',
            'purchasing.notify.pr_invited_body',
            route('supplier.quotations.create', $pr, absolute: false),
            'clipboard-plus',
            ['category' => NotificationCategory::QUOTATION, 'pr_id' => $pr->id, 'pr_hash' => $pr->hash],
            ['pr_number' => $pr->pr_number],
        );

        $this->actingAs($this->purchasing)
            ->delete(route('purchasing.requisitions.suppliers.destroy', [$pr, $this->supplier]))
            ->assertSessionHas('success');

        $notification = $this->supplier->notifications()->where('data->event', 'pr.supplier_invited')->firstOrFail();
        $resolvedUrl = app(NotificationUrlResolver::class)->resolve($notification, $this->supplier);
        $this->assertSame(route('supplier.requests.show', $pr, absolute: false), $resolvedUrl);

        // Document chain for removed supplier exposes only minimal CANCELLED PR node.
        $chain = app(DocumentChainResolverService::class)->resolve($this->supplier, 'import-pr', $pr->hash);
        $this->assertSame('CANCELLED', $chain['status']);
        $this->assertSame('error', $chain['status_tone']);
        $this->assertCount(1, $chain['nodes']);
        $this->assertSame([], $chain['sla_summary']);
    }

    public function test_sla_watchdog_ignores_cancelled_prs_and_removed_suppliers(): void
    {
        $cancelledPr = $this->createRequisition([[]], PurchaseRequisition::STATUS_SUBMITTED);
        $cancelledPr->invitedSuppliers()->attach([$this->supplier->id => ['invited_at' => now()]]);
        $this->actingAs($this->purchasing)
            ->postJson(route('purchasing.requisitions.cancel', $cancelledPr), ['cancellation_reason' => 'Cancelled PR'])
            ->assertOk();
        DB::table('purchase_requisitions')->where('id', $cancelledPr->id)->update(['updated_at' => now()->subDays(10)]);

        $openPr = $this->createRequisition([[]], PurchaseRequisition::STATUS_SUBMITTED);
        $openPr->invitedSuppliers()->attach([
            $this->supplier->id => ['invited_at' => now()],
            $this->secondSupplier->id => ['invited_at' => now()],
        ]);
        $this->actingAs($this->purchasing)
            ->delete(route('purchasing.requisitions.suppliers.destroy', [$openPr, $this->secondSupplier]))
            ->assertSessionHas('success');
        DB::table('purchase_requisitions')->where('id', $openPr->id)->update(['updated_at' => now()->subDays(10)]);

        $beforeSupplier = $this->supplier->notifications()->count();
        $beforeSecond = $this->secondSupplier->notifications()->count();

        app(SlaWatchdogService::class)->evaluateAll();

        // Active supplier receives reminder only for openPr; removed supplier receives 0 reminders.
        $this->assertSame($beforeSecond, $this->secondSupplier->notifications()->count());
        $this->assertGreaterThanOrEqual($beforeSupplier, $this->supplier->notifications()->count());
        $this->assertSame(
            0,
            $this->supplier->notifications()->where('data->pr_id', $cancelledPr->id)->where('data->event', 'like', 'sla.%')->count()
        );
    }

    public function test_downstream_purchasing_services_reject_cancelled_pr(): void
    {
        $pr = $this->createRequisition([[]], PurchaseRequisition::STATUS_BIDDING);
        $quotation = $this->createQuotation($pr, [], Quotation::STATUS_SUBMITTED, $this->supplier);
        $prItem = $pr->items->firstOrFail();
        $quotationItem = $quotation->items->firstOrFail();

        $award = PrItemAward::create([
            'pr_id' => $pr->id,
            'pr_item_id' => $prItem->id,
            'quotation_id' => $quotation->id,
            'quotation_item_id' => $quotationItem->id,
            'supplier_id' => $this->supplier->id,
            'awarded_by' => $this->purchasing->id,
            'awarded_at' => now(),
        ]);

        // Force cancelled state at DB level to test defense-in-depth downstream guards.
        DB::table('purchase_requisitions')->where('id', $pr->id)->update(['status' => PurchaseRequisition::STATUS_CANCELLED]);

        // 1. PrItemAwardService::awardItem & awardBatch
        try {
            app(PrItemAwardService::class)->awardItem($prItem, $quotationItem, $this->purchasing);
            $this->fail('awardItem must reject cancelled PR');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        try {
            app(PrItemAwardService::class)->awardBatch($pr->fresh(), [$prItem->id => $quotationItem->id], $this->purchasing);
            $this->fail('awardBatch must reject cancelled PR');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        // 2. PurchaseOrderGenerationService::generateFromAwards
        try {
            app(PurchaseOrderGenerationService::class)->generateFromAwards([$award->id], $this->purchasing);
            $this->fail('generateFromAwards must reject cancelled PR');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        // 3. ConversationMessageService quick action
        $conversation = Conversation::create([
            'conversable_type' => PurchaseRequisition::class,
            'conversable_id' => $pr->id,
            'purchasing_user_id' => $this->purchasing->id,
            'supplier_user_id' => $this->supplier->id,
        ]);
        $this->actingAs($this->purchasing);
        try {
            app(ConversationMessageService::class)->executeQuickAction($conversation, [
                'action' => 'accept_quotation',
                'quotation_id' => $quotation->id,
                'note' => '',
            ]);
            $this->fail('Conversation quick action must reject cancelled PR');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
    }

    public function test_cancellation_migrations_refuse_rollback_when_cancelled_data_exists(): void
    {
        $pr = $this->createRequisition([[]], PurchaseRequisition::STATUS_SUBMITTED);
        $pr->invitedSuppliers()->attach([
            $this->supplier->id => ['invited_at' => now()],
            $this->secondSupplier->id => ['invited_at' => now()],
        ]);

        $this->actingAs($this->purchasing)
            ->delete(route('purchasing.requisitions.suppliers.destroy', [$pr, $this->secondSupplier]), ['reason' => 'Audit note'])
            ->assertSessionHas('success');

        $this->actingAs($this->purchasing)
            ->postJson(route('purchasing.requisitions.cancel', $pr), ['cancellation_reason' => 'Cancelled for rollback test'])
            ->assertOk();

        $migrations = [
            database_path('migrations/2026_11_24_000001_add_cancelled_state_to_purchase_requisitions_table.php'),
            database_path('migrations/2026_11_24_000002_add_cancellation_columns_to_purchase_requisition_suppliers_table.php'),
            database_path('migrations/2026_11_24_000003_add_reason_to_pr_supplier_invitation_events_table.php'),
        ];

        foreach ($migrations as $path) {
            $migration = require $path;
            try {
                $migration->down();
                $this->fail("Expected RuntimeException when rolling back {$path} with existing data.");
            } catch (RuntimeException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
        }
    }

    public function test_period_datatable_json_never_leaks_internal_cancellation_fields_notes_or_items(): void
    {
        $removedPr = $this->createRequisition([[]], PurchaseRequisition::STATUS_SUBMITTED);
        $removedPr->update(['notes' => 'SECRET-PR-NOTE-99']);
        $removedPr->items->firstOrFail()->update([
            'material_name' => 'CONFIDENTIAL-STEEL-99',
            'hs_code' => '72089999',
        ]);
        $removedPr->invitedSuppliers()->attach([
            $this->supplier->id => ['invited_at' => now()],
            $this->secondSupplier->id => ['invited_at' => now()],
        ]);
        $this->actingAs($this->purchasing)
            ->delete(route('purchasing.requisitions.suppliers.destroy', [$removedPr, $this->supplier]), [
                'reason' => 'INTERNAL-REMOVAL-REASON-99',
            ])
            ->assertSessionHas('success');

        $cancelledPr = $this->createRequisition([[]], PurchaseRequisition::STATUS_SUBMITTED);
        $cancelledPr->update([
            'period_id' => $removedPr->period_id,
            'notes' => 'SECRET-PR-NOTE-88',
        ]);
        $cancelledPr->items->firstOrFail()->update([
            'material_name' => 'CONFIDENTIAL-ALLOY-88',
            'hs_code' => '72098888',
        ]);
        $cancelledPr->invitedSuppliers()->attach([
            $this->supplier->id => ['invited_at' => now()],
        ]);
        $this->actingAs($this->purchasing)
            ->postJson(route('purchasing.requisitions.cancel', $cancelledPr), [
                'cancellation_reason' => 'INTERNAL-CANCEL-REASON-88',
            ])
            ->assertOk();

        $activePr = $this->createRequisition([[]], PurchaseRequisition::STATUS_SUBMITTED);
        $activePr->update([
            'period_id' => $removedPr->period_id,
            'notes' => 'SECRET-ACTIVE-NOTE-77',
        ]);
        $activePr->items->firstOrFail()->update([
            'material_name' => 'CONFIDENTIAL-ACTIVE-77',
            'hs_code' => '72077777',
        ]);
        $activePr->invitedSuppliers()->attach([
            $this->supplier->id => ['invited_at' => now()],
        ]);

        $response = $this->actingAs($this->supplier)
            ->getJson(
                route('supplier.quotations.period', $removedPr->period_id).'?draw=1&start=0&length=20',
                ['X-Requested-With' => 'XMLHttpRequest']
            )
            ->assertOk();

        $rawJson = $response->getContent();
        $rows = collect($response->json('data'));
        $this->assertCount(3, $rows);

        $expectedKeys = ['DT_RowIndex', 'action', 'item_count', 'pr_number_display', 'status_badge', 'updated_date'];
        foreach ($rows as $row) {
            $keys = array_keys($row);
            sort($keys);
            $this->assertSame($expectedKeys, $keys);
        }

        $this->assertSame('-', $rows->firstWhere('pr_number_display', $removedPr->pr_number)['item_count']);
        $this->assertSame('-', $rows->firstWhere('pr_number_display', $cancelledPr->pr_number)['item_count']);
        $this->assertSame('1 Item', $rows->firstWhere('pr_number_display', $activePr->pr_number)['item_count']);

        foreach ([
            'cancellation_reason',
            'cancelled_by',
            'cancelled_invited_suppliers',
            'INTERNAL-REMOVAL-REASON-99',
            'INTERNAL-CANCEL-REASON-88',
            'SECRET-PR-NOTE-99',
            'SECRET-PR-NOTE-88',
            'SECRET-ACTIVE-NOTE-77',
            'CONFIDENTIAL-STEEL-99',
            'CONFIDENTIAL-ALLOY-88',
            'CONFIDENTIAL-ACTIVE-77',
            '72089999',
            '72098888',
            '72077777',
            '"items"',
            '"notes"',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $rawJson);
        }
    }

    public function test_notification_url_resolver_blocks_availability_json_route_and_draft_rejected_prs(): void
    {
        $resolver = app(NotificationUrlResolver::class);
        $activePr = $this->createRequisition([[]], PurchaseRequisition::STATUS_SUBMITTED);
        $activePr->invitedSuppliers()->attach([
            $this->supplier->id => ['invited_at' => now()],
            $this->secondSupplier->id => ['invited_at' => now()],
        ]);

        // Availability polling JSON route must never be resolved as a notification destination.
        $availabilityNotif = $this->supplier->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => SystemNotification::class,
            'data' => [
                'title' => 'Availability probe',
                'message' => 'Should not land on JSON endpoint',
                'url' => route('supplier.quotations.availability', $activePr, absolute: false),
            ],
        ]);
        $this->assertSame(
            route('supplier.dashboard', absolute: false),
            $resolver->resolve($availabilityNotif, $this->supplier),
        );

        // Cancelled PR import-template notification URL redirects to neutral cancelled summary.
        $this->actingAs($this->purchasing)
            ->delete(route('purchasing.requisitions.suppliers.destroy', [$activePr, $this->supplier]))
            ->assertSessionHas('success');
        $templateNotif = $this->supplier->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => SystemNotification::class,
            'data' => [
                'title' => 'Template link',
                'message' => 'Legacy template link',
                'url' => route('supplier.quotations.import-template', $activePr, absolute: false),
            ],
        ]);
        $this->assertSame(
            route('supplier.requests.show', $activePr, absolute: false),
            $resolver->resolve($templateNotif, $this->supplier),
        );

        // Draft and rejected PRs are not visible to suppliers and must not resolve to quotation create.
        foreach ([PurchaseRequisition::STATUS_DRAFT, PurchaseRequisition::STATUS_REJECTED] as $status) {
            $nonOpenPr = $this->createRequisition([[]], $status);
            $this->assertFalse($nonOpenPr->isVisibleToSupplier($this->supplier->id));

            $fallbackNotif = $this->supplier->notifications()->create([
                'id' => (string) Str::uuid(),
                'type' => SystemNotification::class,
                'data' => [
                    'title' => 'Non-open PR',
                    'message' => 'Should fall back to dashboard',
                    'url' => '#',
                    'pr_id' => $nonOpenPr->id,
                ],
            ]);
            $this->assertSame(
                route('supplier.dashboard', absolute: false),
                $resolver->resolve($fallbackNotif, $this->supplier),
            );
        }
    }

    public function test_unicode_whitespace_cancellation_reason_is_rejected_at_request_and_service_layers(): void
    {
        $pr = $this->createRequisition([[]], PurchaseRequisition::STATUS_SUBMITTED);
        $unicodeBlank = str_repeat("\u{00A0}", 8);

        $this->actingAs($this->purchasing)
            ->postJson(route('purchasing.requisitions.cancel', $pr), [
                'cancellation_reason' => $unicodeBlank,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cancellation_reason');

        $this->assertSame(PurchaseRequisition::STATUS_SUBMITTED, $pr->fresh()->status);

        try {
            app(PurchaseRequisitionCancellationService::class)->cancel($pr, $this->purchasing, $unicodeBlank);
            $this->fail('Expected DomainException for Unicode whitespace-only cancellation reason.');
        } catch (DomainException) {
            $this->assertSame(PurchaseRequisition::STATUS_SUBMITTED, $pr->fresh()->status);
        }
    }
}
