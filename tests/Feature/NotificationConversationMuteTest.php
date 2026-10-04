<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\NotificationMute;
use App\Models\Period;
use App\Models\PurchaseRequisition;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Services\NotificationPreferenceService;
use App\Services\NotificationSummaryService;
use App\Support\NotificationCategory;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NotificationConversationMuteTest extends TestCase
{
    use RefreshDatabase;

    private function createImportSupplier(): User
    {
        return User::factory()->create([
            'role' => 'supplier',
            'is_active' => true,
            'account_status' => User::ACCOUNT_STATUS_ACTIVE,
        ]);
    }

    private function createConversation(?User $purchasing = null, ?User $supplier = null): Conversation
    {
        $purchasing ??= User::factory()->create(['role' => 'purchasing']);
        $supplier ??= $this->createImportSupplier();

        $period = Period::create([
            'name' => 'Test Period',
            'month' => 10,
            'year' => 2026,
            'status' => 'open',
            'created_by' => $purchasing->id,
        ]);

        $pr = PurchaseRequisition::create([
            'period_id' => $period->id,
            'pr_number' => 'PR-TEST-'.uniqid(),
            'created_by' => $purchasing->id,
            'status' => 'draft',
        ]);

        return Conversation::create([
            'conversable_type' => PurchaseRequisition::class,
            'conversable_id' => $pr->id,
            'purchasing_user_id' => $purchasing->id,
            'supplier_user_id' => $supplier->id,
            'status' => Conversation::STATUS_OPEN,
        ]);
    }

    public function test_table_shape_and_unique_constraint(): void
    {
        $this->assertTrue(Schema::hasTable('notification_mutes'));
        $this->assertTrue(Schema::hasColumns('notification_mutes', [
            'id', 'user_id', 'subject_type', 'subject_id', 'created_at', 'updated_at',
        ]));

        $user = User::factory()->create();

        NotificationMute::create([
            'user_id' => $user->id,
            'subject_type' => 'conversation',
            'subject_id' => 101,
        ]);

        $this->expectException(QueryException::class);
        NotificationMute::create([
            'user_id' => $user->id,
            'subject_type' => 'conversation',
            'subject_id' => 101,
        ]);
    }

    public function test_cascade_delete_on_user(): void
    {
        $user = User::factory()->create();

        NotificationMute::create([
            'user_id' => $user->id,
            'subject_type' => 'conversation',
            'subject_id' => 202,
        ]);

        $this->assertDatabaseHas('notification_mutes', ['user_id' => $user->id, 'subject_id' => 202]);

        $user->delete();

        $this->assertDatabaseMissing('notification_mutes', ['user_id' => $user->id, 'subject_id' => 202]);
    }

    public function test_mute_and_unmute_service_idempotency(): void
    {
        $service = app(NotificationPreferenceService::class);
        $user = User::factory()->create();

        $this->assertFalse($service->isMuted($user, 'conversation', 555));

        $this->assertTrue($service->mute($user, 'conversation', 555));
        $this->assertTrue($service->isMuted($user, 'conversation', 555));
        $this->assertSame(1, NotificationMute::where('user_id', $user->id)->where('subject_id', 555)->count());

        // Calling mute again is idempotent
        $this->assertTrue($service->mute($user, 'conversation', 555));
        $this->assertSame(1, NotificationMute::where('user_id', $user->id)->where('subject_id', 555)->count());

        // Unmute
        $this->assertTrue($service->unmute($user, 'conversation', 555));
        $this->assertFalse($service->isMuted($user, 'conversation', 555));
        $this->assertSame(0, NotificationMute::where('user_id', $user->id)->where('subject_id', 555)->count());

        // Calling unmute again is idempotent
        $this->assertTrue($service->unmute($user, 'conversation', 555));
        $this->assertFalse($service->isMuted($user, 'conversation', 555));
    }

    public function test_authorization_for_mute_and_unmute_endpoints(): void
    {
        $purchasing = User::factory()->create(['role' => 'purchasing']);
        $supplier = $this->createImportSupplier();
        $otherUser = User::factory()->create(['role' => 'purchasing']);
        $conversation = $this->createConversation($purchasing, $supplier);
        $hashId = $conversation->getRouteKey();

        // 1. Guest is redirected
        $this->post("/conversations/{$hashId}/mute")->assertRedirect(route('login'));
        $this->delete("/conversations/{$hashId}/mute")->assertRedirect(route('login'));

        // 2. Non-participant denied with 403
        $this->actingAs($otherUser)
            ->post("/conversations/{$hashId}/mute")
            ->assertForbidden();
        $this->actingAs($otherUser)
            ->delete("/conversations/{$hashId}/mute")
            ->assertForbidden();

        // 3. Purchasing participant allowed (JSON response)
        $response = $this->actingAs($purchasing)
            ->postJson("/conversations/{$hashId}/mute");
        $response->assertOk()
            ->assertJson([
                'success' => true,
                'muted' => true,
                'conversation_id' => $hashId,
            ]);

        $this->assertDatabaseHas('notification_mutes', [
            'user_id' => $purchasing->id,
            'subject_type' => 'conversation',
            'subject_id' => $conversation->id,
        ]);

        // Partner supplier is unaffected by purchasing's mute
        $service = app(NotificationPreferenceService::class);
        $this->assertTrue($service->isMuted($purchasing, 'conversation', $conversation->id));
        $this->assertFalse($service->isMuted($supplier, 'conversation', $conversation->id));

        // 4. Supplier participant allowed (DELETE unmute with web redirect back)
        $this->actingAs($purchasing)
            ->delete("/conversations/{$hashId}/mute")
            ->assertRedirect();
        $this->assertDatabaseMissing('notification_mutes', [
            'user_id' => $purchasing->id,
            'subject_type' => 'conversation',
            'subject_id' => $conversation->id,
        ]);
    }

    public function test_delivery_muted_and_normal_delivers_silent_row_with_no_broadcast(): void
    {
        Queue::fake();
        $purchasing = User::factory()->create(['role' => 'purchasing']);
        $supplier = $this->createImportSupplier();
        $conversation = $this->createConversation($purchasing, $supplier);

        // Supplier mutes the conversation
        app(NotificationPreferenceService::class)->mute($supplier, 'conversation', $conversation->id);

        $notification = new SystemNotification(
            'New message',
            'Hello from purchasing',
            '#',
            'message-circle-more',
            [
                'category' => NotificationCategory::CHAT,
                'event' => 'conversation.message_created',
                'conversation_id' => $conversation->id,
            ]
        );

        $supplier->notify($notification);

        $this->assertSame(1, $supplier->notifications()->count());
        $stored = $supplier->notifications()->sole();
        $this->assertTrue($stored->data['silent'] ?? false);

        // Suppresses broadcast channel
        Queue::assertNothingPushed();
    }

    public function test_delivery_muted_and_off_suppresses_everything_off_wins(): void
    {
        Queue::fake();
        $purchasing = User::factory()->create(['role' => 'purchasing']);
        $supplier = $this->createImportSupplier();
        $conversation = $this->createConversation($purchasing, $supplier);

        // Turn event Off in preferences
        $pref = $supplier->preference()->create(config('user_preferences.defaults'));
        $pref->forceFill(['notification_preferences' => ['conversation_message_created' => false]])->save();

        // Also mute conversation
        app(NotificationPreferenceService::class)->mute($supplier, 'conversation', $conversation->id);

        $notification = new SystemNotification(
            'New message',
            'Hello',
            '#',
            'message-circle-more',
            [
                'category' => NotificationCategory::CHAT,
                'event' => 'conversation.message_created',
                'conversation_id' => $conversation->id,
            ]
        );

        $supplier->notify($notification);

        // Off wins: nothing stored, nothing broadcast
        $this->assertSame(0, $supplier->notifications()->count());
        Queue::assertNothingPushed();
    }

    public function test_delivery_unmuted_delivers_normal_with_broadcast(): void
    {
        Queue::fake();
        $purchasing = User::factory()->create(['role' => 'purchasing']);
        $supplier = $this->createImportSupplier();
        $conversation = $this->createConversation($purchasing, $supplier);

        $notification = new SystemNotification(
            'New message',
            'Hello unmuted',
            '#',
            'message-circle-more',
            [
                'category' => NotificationCategory::CHAT,
                'event' => 'conversation.message_created',
                'conversation_id' => $conversation->id,
            ]
        );

        $supplier->notify($notification);

        $this->assertSame(1, $supplier->notifications()->count());
        $stored = $supplier->notifications()->sole();
        $this->assertArrayNotHasKey('silent', $stored->data);

        Queue::assertPushed(BroadcastEvent::class);
    }

    public function test_partner_is_unaffected_when_one_participant_mutes(): void
    {
        Queue::fake();
        $purchasing = User::factory()->create(['role' => 'purchasing']);
        $supplier = $this->createImportSupplier();
        $conversation = $this->createConversation($purchasing, $supplier);

        // Purchasing mutes the conversation, supplier does not
        app(NotificationPreferenceService::class)->mute($purchasing, 'conversation', $conversation->id);

        // Message to purchasing arrives silent with no broadcast
        $purchasingNotification = new SystemNotification(
            'Message to Purchasing',
            'From supplier',
            '#',
            'message-circle-more',
            [
                'category' => NotificationCategory::CHAT,
                'event' => 'conversation.message_created',
                'conversation_id' => $conversation->id,
            ]
        );
        $purchasing->notify($purchasingNotification);
        $this->assertTrue($purchasing->notifications()->sole()->data['silent'] ?? false);
        Queue::assertNothingPushed();

        // Message to supplier arrives normal with broadcast
        $supplierNotification = new SystemNotification(
            'Message to Supplier',
            'From purchasing',
            '#',
            'message-circle-more',
            [
                'category' => NotificationCategory::CHAT,
                'event' => 'conversation.message_created',
                'conversation_id' => $conversation->id,
            ]
        );
        $supplier->notify($supplierNotification);
        $this->assertArrayNotHasKey('silent', $supplier->notifications()->sole()->data);
        Queue::assertPushed(BroadcastEvent::class);
    }

    public function test_conversation_linked_non_allow_listed_event_stays_normal_even_when_muted(): void
    {
        Queue::fake();
        $purchasing = User::factory()->create(['role' => 'purchasing']);
        $supplier = $this->createImportSupplier();
        $conversation = $this->createConversation($purchasing, $supplier);

        app(NotificationPreferenceService::class)->mute($supplier, 'conversation', $conversation->id);

        // quotation.negotiation_message carries conversation_id but is NOT mutable_subject
        $notification = new SystemNotification(
            'Negotiation',
            'Quotation negotiation',
            '#',
            'message-circle-more',
            [
                'category' => NotificationCategory::CHAT,
                'event' => 'quotation.negotiation_message',
                'conversation_id' => $conversation->id,
            ]
        );

        $supplier->notify($notification);

        $this->assertSame(1, $supplier->notifications()->count());
        $stored = $supplier->notifications()->sole();
        $this->assertArrayNotHasKey('silent', $stored->data);
        Queue::assertPushed(BroadcastEvent::class);
    }

    public function test_unregistered_event_untouched_and_zero_mute_queries(): void
    {
        $supplier = $this->createImportSupplier();
        $conversation = $this->createConversation(null, $supplier);

        app(NotificationPreferenceService::class)->mute($supplier, 'conversation', $conversation->id);

        $muteQueries = 0;
        DB::listen(function ($query) use (&$muteQueries): void {
            if (str_contains(strtolower($query->sql), 'notification_mutes')) {
                $muteQueries++;
            }
        });

        $notification = new SystemNotification(
            'Unregistered',
            'Unregistered body',
            '#',
            'bell',
            ['conversation_id' => $conversation->id]
        );

        $supplier->notify($notification);

        $this->assertSame(1, $supplier->notifications()->count());
        $this->assertSame(0, $muteQueries);
    }

    public function test_query_budget_at_most_one_mute_query_per_recipient_and_zero_for_non_mutable(): void
    {
        $purchasing = User::factory()->create(['role' => 'purchasing']);
        $supplier = $this->createImportSupplier();
        $conversation = $this->createConversation($purchasing, $supplier);

        // 1. Non-mutable event triggers ZERO notification_mutes queries
        $muteQueries = 0;
        DB::listen(function ($query) use (&$muteQueries): void {
            if (str_contains(strtolower($query->sql), 'notification_mutes')) {
                $muteQueries++;
            }
        });

        $notificationNonMutable = new SystemNotification(
            'PO Issued',
            'PO issued',
            '#',
            'bell',
            ['event' => 'po.issued', 'conversation_id' => $conversation->id]
        );
        $supplier->notify($notificationNonMutable);
        $this->assertSame(0, $muteQueries);

        // 2. Mutable event executes at most 1 query for the recipient across repeated checks
        $service = app(NotificationPreferenceService::class);
        $service->deliveryFor($supplier, 'conversation_message_created', ['conversation_id' => $conversation->id]);
        $service->deliveryFor($supplier, 'conversation_message_created', ['conversation_id' => $conversation->id]);
        $service->deliveryFor($supplier, 'conversation_message_created', ['conversation_id' => $conversation->id]);

        $this->assertSame(1, $muteQueries);
    }

    public function test_fail_open_on_lookup_failure(): void
    {
        $supplier = $this->createImportSupplier();
        $conversation = $this->createConversation(null, $supplier);

        // Drop table temporarily to simulate failure
        Schema::drop('notification_mutes');

        $service = app(NotificationPreferenceService::class);
        $result = $service->deliveryFor($supplier, 'conversation_message_created', ['conversation_id' => $conversation->id]);

        $this->assertSame('normal', $result);
    }

    public function test_badge_counts_exclude_muted_notification_while_inbox_list_includes_it(): void
    {
        $purchasing = User::factory()->create(['role' => 'purchasing']);
        $supplier = $this->createImportSupplier();
        $conversation = $this->createConversation($purchasing, $supplier);

        app(NotificationPreferenceService::class)->mute($supplier, 'conversation', $conversation->id);

        $notification = new SystemNotification(
            'Muted message',
            'Silent delivery test',
            '#',
            'message-circle-more',
            [
                'category' => NotificationCategory::CHAT,
                'event' => 'conversation.message_created',
                'conversation_id' => $conversation->id,
            ]
        );
        $supplier->notify($notification);

        // Summary and counts exclude silent
        $summaryService = app(NotificationSummaryService::class);
        $counts = $summaryService->countsForUser($supplier);
        $this->assertSame(0, $counts['count']);
        $this->assertSame(0, $counts['category_counts']['chat']['unread']);

        $summary = $summaryService->forUser($supplier);
        $this->assertSame(0, $summary['count']);
        $this->assertSame(0, $summary['category_counts']['chat']['unread']);
        $this->assertSame(1, $summary['notifications']->count());

        // unread-count endpoint
        $this->actingAs($supplier)
            ->get(route('notifications.unread-count'))
            ->assertOk()
            ->assertJsonPath('count', 0);

        // inbox panel endpoint includes it
        $this->actingAs($supplier)
            ->get(route('notifications.summary'))
            ->assertOk()
            ->assertSee('Muted message');
    }

    public function test_reset_to_defaults_does_not_clear_mutes(): void
    {
        $supplier = $this->createImportSupplier();
        $conversation = $this->createConversation(null, $supplier);

        app(NotificationPreferenceService::class)->mute($supplier, 'conversation', $conversation->id);
        $this->assertDatabaseHas('notification_mutes', ['user_id' => $supplier->id, 'subject_id' => $conversation->id]);

        $this->actingAs($supplier)
            ->delete(route('profile.notifications.reset'))
            ->assertRedirect(route('profile.notifications'));

        // notification_mutes is NOT cleared by reset to defaults
        $this->assertDatabaseHas('notification_mutes', ['user_id' => $supplier->id, 'subject_id' => $conversation->id]);
    }

    public function test_drawer_and_conversation_json_contains_muted(): void
    {
        $purchasing = User::factory()->create(['role' => 'purchasing']);
        $supplier = $this->createImportSupplier();
        $conversation = $this->createConversation($purchasing, $supplier);
        $hashId = $conversation->getRouteKey();

        // 1. Initial state: not muted
        $res = $this->actingAs($purchasing)
            ->getJson("/conversations/{$hashId}/drawer");
        $res->assertOk();
        $this->assertFalse($res->json('conversation.muted'));

        $listRes = $this->actingAs($purchasing)
            ->getJson('/conversations/drawer');
        $listRes->assertOk();
        $this->assertFalse($listRes->json('conversations.0.muted'));

        // 2. Muted state
        app(NotificationPreferenceService::class)->mute($purchasing, 'conversation', $conversation->id);

        $resMuted = $this->actingAs($purchasing)
            ->getJson("/conversations/{$hashId}/drawer");
        $resMuted->assertOk();
        $this->assertTrue($resMuted->json('conversation.muted'));

        $listResMuted = $this->actingAs($purchasing)
            ->getJson('/conversations/drawer');
        $listResMuted->assertOk();
        $this->assertTrue($listResMuted->json('conversations.0.muted'));
    }

    public function test_ui_contract_toggle_renders_for_participants_only_with_correct_aria_state(): void
    {
        $purchasing = User::factory()->create(['role' => 'purchasing']);
        $supplier = $this->createImportSupplier();
        $conversation = $this->createConversation($purchasing, $supplier);

        // 0. Non-participant denied access
        $otherPurchasing = User::factory()->create(['role' => 'purchasing']);
        $responseOther = $this->actingAs($otherPurchasing)->get(route('purchasing.conversations.show', $conversation));
        $responseOther->assertForbidden();

        // 1. Unmuted view for participant
        $response = $this->actingAs($purchasing)->get(route('purchasing.conversations.show', $conversation));
        $response->assertOk();
        $response->assertSee('aria-label="Mute conversation notifications"', false);
        $response->assertDontSee('aria-label="Unmute conversation notifications"', false);
        $response->assertDontSee('value="DELETE"', false);
        $response->assertDontSee('title="Notifications are currently muted. Click to unmute."', false);

        // 2. Muted view for participant
        app(NotificationPreferenceService::class)->mute($purchasing, 'conversation', $conversation->id);
        $responseMuted = $this->actingAs($purchasing)->get(route('purchasing.conversations.show', $conversation));
        $responseMuted->assertOk();
        $responseMuted->assertSee('aria-label="Unmute conversation notifications"', false);
        $responseMuted->assertSee('aria-pressed="true"', false);
        $responseMuted->assertSee('value="DELETE"', false);
        $responseMuted->assertSee('title="Notifications are currently muted. Click to unmute."', false);
    }
}
