<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\PurchaseRequisition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConversationUnreadCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_unread_count_uses_only_member_conversations_and_excludes_own_or_read_messages(): void
    {
        $purchasing = User::factory()->create(['role' => 'purchasing']);
        $supplier = User::factory()->create(['role' => 'supplier']);
        $otherPurchasing = User::factory()->create(['role' => 'purchasing']);
        $otherSupplier = User::factory()->create(['role' => 'supplier']);

        $conversation = $this->conversation($purchasing, $supplier, 1);
        $otherConversation = $this->conversation($otherPurchasing, $otherSupplier, 2);

        $conversation->messages()->create(['sender_id' => $supplier->id, 'body' => 'Unread one']);
        $conversation->messages()->create(['sender_id' => $supplier->id, 'body' => 'Unread two']);
        $conversation->messages()->create([
            'sender_id' => $supplier->id,
            'body' => 'Already read',
            'read_at' => now(),
        ]);
        $conversation->messages()->create(['sender_id' => $purchasing->id, 'body' => 'Own unread flag']);
        $otherConversation->messages()->create(['sender_id' => $otherSupplier->id, 'body' => 'Unrelated']);

        $this->actingAs($purchasing)->getJson(route('conversations.unread-count'))
            ->assertOk()
            ->assertJsonPath('count', 2);
    }

    public function test_drawer_projects_unread_counts_without_per_conversation_count_queries(): void
    {
        $purchasing = User::factory()->create(['role' => 'purchasing']);
        $supplier = User::factory()->create(['role' => 'supplier']);

        foreach (range(1, 3) as $sequence) {
            $conversation = $this->conversation($purchasing, $supplier, $sequence);
            $conversation->messages()->create([
                'sender_id' => $supplier->id,
                'body' => "Unread {$sequence}",
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->actingAs($purchasing)->getJson(route('conversations.drawer.index'))
            ->assertOk()
            ->assertJsonCount(3, 'conversations');

        $this->assertSame([1, 1, 1], collect($response->json('conversations'))->pluck('unread_count')->all());

        $perConversationCounts = collect(DB::getQueryLog())->filter(
            fn (array $query): bool => str_contains(strtolower($query['query']), 'select count(*) as aggregate')
                && (
                    str_contains(strtolower($query['query']), 'from `messages`')
                    || str_contains(strtolower($query['query']), 'from "messages"')
                ),
        );

        $this->assertCount(0, $perConversationCounts);
    }

    private function conversation(User $purchasing, User $supplier, int $contextId): Conversation
    {
        return Conversation::create([
            'conversable_type' => PurchaseRequisition::class,
            'conversable_id' => $contextId,
            'purchasing_user_id' => $purchasing->id,
            'supplier_user_id' => $supplier->id,
            'status' => Conversation::STATUS_OPEN,
        ]);
    }
}
