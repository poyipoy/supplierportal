<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\PurchaseRequisition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatDrawerDraftIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasingUser;
    private User $supplierUser;
    private User $unauthorizedUser;
    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasingUser = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $this->supplierUser = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $this->unauthorizedUser = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);

        $period = \App\Models\Period::create([
            'name' => 'Period Chat Test',
            'month' => 9,
            'year' => 2026,
            'status' => 'open',
            'created_by' => $this->purchasingUser->id,
        ]);

        $pr = PurchaseRequisition::create([
            'period_id' => $period->id,
            'created_by' => $this->purchasingUser->id,
            'pr_number' => 'REQ/TEST/999',
            'notes' => 'Test PR for Chat Drawer',
            'status' => 'submitted',
        ]);

        $this->conversation = Conversation::create([
            'conversable_type' => PurchaseRequisition::class,
            'conversable_id' => $pr->id,
            'purchasing_user_id' => $this->purchasingUser->id,
            'supplier_user_id' => $this->supplierUser->id,
            'status' => 'active',
        ]);
    }

    public function test_chat_drawer_view_renders_draft_isolation_and_attachment_security_mechanisms(): void
    {
        $response = $this->actingAs($this->purchasingUser)
            ->get(route('purchasing.dashboard'));

        $response->assertOk();

        // Verify draft isolation helper functions and storage
        $response->assertSee('getDraftKey', false);
        $response->assertSee('getDraft', false);
        $response->assertSee('saveDraft', false);
        $response->assertSee('clearDraft', false);
        $response->assertSee('sessionStorage', false);
        $response->assertSee('adasi_chat_draft_', false);

        // Verify attachment purge helper ensuring cross-conversation leak prevention
        $response->assertSee('clearAttachments', false);

        // Verify input event listener for realtime draft saving
        $response->assertSee("inputEl.addEventListener('input'", false);
    }

    public function test_chat_drawer_drawer_show_enforces_membership_authorization(): void
    {
        // Authorized purchasing user can view drawer details
        $this->actingAs($this->purchasingUser)
            ->getJson(route('conversations.drawer.show', $this->conversation))
            ->assertOk()
            ->assertJsonStructure([
                'conversation',
                'context',
                'quick_actions',
                'templates',
                'messages',
            ]);

        // Unauthorized user cannot view drawer details
        $this->actingAs($this->unauthorizedUser)
            ->getJson(route('conversations.drawer.show', $this->conversation))
            ->assertForbidden();
    }

    public function test_chat_drawer_message_store_enforces_membership_and_validation(): void
    {
        // Unauthorized user cannot send message
        $this->actingAs($this->unauthorizedUser)
            ->postJson(route('conversations.messages.store', $this->conversation), [
                'body' => 'Unauthorized attempt',
            ])
            ->assertForbidden();

        // Authorized user cannot send completely empty payload
        $this->actingAs($this->purchasingUser)
            ->postJson(route('conversations.messages.store', $this->conversation), [
                'body' => '   ',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['body']);

        // Authorized user successfully sends message
        $response = $this->actingAs($this->purchasingUser)
            ->postJson(route('conversations.messages.store', $this->conversation), [
                'body' => 'Valid negotiation message',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $this->conversation->id,
            'sender_id' => $this->purchasingUser->id,
            'body' => 'Valid negotiation message',
        ]);
    }
}
