<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Period;
use App\Models\PurchaseRequisition;
use App\Models\SupplierScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Chat history loads the newest 50 messages and earlier pages of 50 by id.
 * Pages must join without gaps or duplicates, keep chronological order,
 * include attachments without per-message queries, and stay member-only.
 */
class ConversationHistoryPaginationTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasing;

    private User $supplier;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $this->supplier = $this->importSupplier();
        $period = Period::create(['name' => 'Chat Period', 'month' => 10, 'year' => 2026, 'status' => 'open', 'created_by' => $this->purchasing->id]);
        $requisition = PurchaseRequisition::create(['period_id' => $period->id, 'created_by' => $this->purchasing->id, 'pr_number' => 'REQ/CHAT/001', 'status' => 'bidding']);
        $this->conversation = Conversation::create(['conversable_type' => PurchaseRequisition::class, 'conversable_id' => $requisition->id,
            'purchasing_user_id' => $this->purchasing->id, 'supplier_user_id' => $this->supplier->id, 'status' => Conversation::STATUS_OPEN]);
    }

    public function test_pages_join_without_gaps_or_duplicates_in_chronological_order(): void
    {
        $ids = $this->seedMessages(120);

        $first = $this->actingAs($this->purchasing)->getJson(route('conversations.drawer.show', $this->conversation))->assertOk();
        $this->assertTrue($first->json('has_more_messages'));
        $pages = [array_column($first->json('messages'), 'id')];
        $this->assertSame(array_slice($ids, -50), $pages[0]);

        $before = $pages[0][0];
        do {
            $page = $this->getJson(route('conversations.messages.older', $this->conversation).'?before='.$before)->assertOk();
            $pageIds = array_column($page->json('messages'), 'id');
            array_unshift($pages, $pageIds);
            $before = $pageIds[0] ?? $before;
        } while ($page->json('has_more'));

        $this->assertSame([20, 50, 50], array_map('count', $pages));
        $this->assertSame($ids, array_merge(...$pages));
    }

    public function test_exactly_one_page_reports_no_older_messages(): void
    {
        $this->seedMessages(50);

        $response = $this->actingAs($this->purchasing)->getJson(route('conversations.drawer.show', $this->conversation))->assertOk();

        $this->assertCount(50, $response->json('messages'));
        $this->assertFalse($response->json('has_more_messages'));
    }

    public function test_full_page_renders_the_newest_page_and_marks_all_partner_messages_read(): void
    {
        $ids = $this->seedMessages(60);

        $response = $this->actingAs($this->purchasing)->get(route('purchasing.conversations.show', $this->conversation))->assertOk();

        $response->assertSee('id="chat-load-older"', false);
        $response->assertSee('data-message-id="'.$ids[10].'"', false);
        $response->assertDontSee('data-message-id="'.$ids[9].'"', false);
        $this->assertSame(0, $this->conversation->messages()->where('sender_id', $this->supplier->id)->whereNull('read_at')->count());
    }

    public function test_pages_include_attachments_without_per_message_queries(): void
    {
        $ids = $this->seedMessages(60, withAttachments: true);
        $this->actingAs($this->purchasing);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $older = $this->getJson(route('conversations.messages.older', $this->conversation).'?before='.$ids[10])->assertOk();
        $latest = $this->getJson(route('conversations.messages.latest', $this->conversation).'?after='.$ids[40])->assertOk();
        $attachmentQueries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'from `attachments`'))->count();

        $this->assertCount(10, $older->json('messages'));
        $this->assertCount(19, $latest->json('messages'));
        $this->assertSame('file-0.pdf', $older->json('messages.0.attachments.0.name'));
        $this->assertNotEmpty($latest->json('messages.0.attachments.0.url'));
        $this->assertSame(2, $attachmentQueries, 'One attachment query per request.');
    }

    public function test_non_members_and_invalid_cursors_are_rejected(): void
    {
        $ids = $this->seedMessages(3);
        $outsider = $this->importSupplier();

        $this->actingAs($outsider)->getJson(route('conversations.messages.older', $this->conversation).'?before='.$ids[2])->assertForbidden();
        $this->actingAs($outsider)->getJson(route('conversations.drawer.show', $this->conversation))->assertForbidden();
        $this->actingAs($this->supplier)->getJson(route('conversations.messages.older', $this->conversation).'?before=abc')->assertUnprocessable();
        $this->actingAs($this->supplier)->getJson(route('conversations.messages.older', $this->conversation))->assertUnprocessable();
    }

    /** @return list<int> message ids, oldest first */
    private function seedMessages(int $count, bool $withAttachments = false): array
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $sender = $i % 2 === 0 ? $this->supplier : $this->purchasing;
            // Several messages share a timestamp, as they do within one second.
            $message = $this->conversation->messages()->create(['sender_id' => $sender->id, 'body' => 'Message '.$i, 'created_at' => now()->subMinutes(intdiv($count - $i, 3))]);
            if ($withAttachments) {
                $message->attachments()->create(['file_path' => 'attachments/chat/file-'.$i.'.pdf', 'file_name' => 'file-'.$i.'.pdf', 'file_type' => 'application/pdf', 'uploaded_by' => $sender->id]);
            }
            $ids[] = $message->id;
        }

        return $ids;
    }

    private function importSupplier(): User
    {
        $supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true, 'account_status' => User::ACCOUNT_STATUS_ACTIVE]);
        SupplierScope::firstOrCreate(['supplier_id' => $supplier->id, 'scope' => 'import']);

        return $supplier->forgetSupplierScopes();
    }
}
