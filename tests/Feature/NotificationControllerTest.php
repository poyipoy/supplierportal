<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\SystemNotification;
use App\Support\NotificationCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_unread_summary_and_mark_read_are_scoped_to_authenticated_user(): void
    {
        $user = $this->user('purchasing');
        $other = $this->user('purchasing');
        $own = $this->notification($user, NotificationCategory::QUOTATION);
        $otherNotification = $this->notification($other, NotificationCategory::CHAT);

        $this->actingAs($user)->getJson(route('notifications.unread-count'))
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('category_counts.quotation.unread', 1)
            ->assertJsonPath('category_counts.chat.unread', 0);

        $this->actingAs($user)->postJson(route('notifications.read', $otherNotification->id))
            ->assertNotFound();

        $this->actingAs($user)->postJson(route('notifications.read', $own->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('count', 0);

        $this->assertNotNull($own->fresh()->read_at);
        $this->assertNull($otherNotification->fresh()->read_at);
    }

    public function test_mark_all_validates_category_and_updates_only_selected_category(): void
    {
        $user = $this->user('supplier');
        $chat = $this->notification($user, NotificationCategory::CHAT);
        $quotation = $this->notification($user, NotificationCategory::QUOTATION);

        $this->actingAs($user)->postJson(route('notifications.mark-all-read'), ['category' => 'invalid'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category');

        $this->actingAs($user)->postJson(route('notifications.mark-all-read'), ['category' => NotificationCategory::CHAT])
            ->assertOk()
            ->assertJsonPath('marked_count', 1)
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('category_counts.chat.unread', 0)
            ->assertJsonPath('category_counts.quotation.unread', 1);

        $this->assertNotNull($chat->fresh()->read_at);
        $this->assertNull($quotation->fresh()->read_at);

        $this->actingAs($user)->postJson(route('notifications.mark-all-read'))
            ->assertOk()
            ->assertJsonPath('category', NotificationCategory::ALL)
            ->assertJsonPath('unread_count', 0);
    }

    public function test_inactive_authenticated_session_is_rejected(): void
    {
        $inactive = $this->user('admin', false);

        $this->actingAs($inactive)->get(route('notifications.unread-count'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    private function user(string $role, bool $active = true): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => $active]);
    }

    private function notification(User $user, string $category): DatabaseNotification
    {
        return $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => SystemNotification::class,
            'data' => [
                'title' => 'Test notification',
                'message' => 'Test message',
                'url' => route($user->role.'.dashboard', absolute: false),
                'icon' => 'bi-bell',
                'category' => $category,
            ],
        ]);
    }
}
