<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\NotificationPreferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationSilentDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'local_invoice_submitted';

    public function test_normalize_stored_accepts_false_and_silent_strings_only(): void
    {
        $service = app(NotificationPreferenceService::class);

        $stored = [
            'local_invoice_submitted' => 'silent',
            'local_invoice_paid' => false,
            'claim_created' => true,
            'export_completed' => 'normal',
            'invalid_key' => 'silent',
            'another_invalid' => false,
            'nested_legacy' => ['mail' => false],
        ];

        $normalized = $service->normalizeStored($stored);

        $this->assertSame([
            'local_invoice_submitted' => 'silent',
            'local_invoice_paid' => false,
        ], $normalized);
    }

    public function test_delivery_for_returns_normal_silent_and_off_with_fallback(): void
    {
        $service = app(NotificationPreferenceService::class);
        $user = User::factory()->create(['role' => 'finance']);

        // Default with no overrides stored -> normal
        $this->assertSame('normal', $service->deliveryFor($user, self::KEY));
        $this->assertTrue($service->enabled($user, self::KEY));

        // Stored silent -> silent (and still enabled)
        $pref = $user->preference()->create(config('user_preferences.defaults'));
        $pref->forceFill(['notification_preferences' => [self::KEY => 'silent']])->save();
        $service->forget($user);

        $this->assertSame('silent', $service->deliveryFor($user, self::KEY));
        $this->assertTrue($service->enabled($user, self::KEY));

        // Stored false -> off (and not enabled)
        $pref->forceFill(['notification_preferences' => [self::KEY => false]])->save();
        $service->forget($user);

        $this->assertSame('off', $service->deliveryFor($user, self::KEY));
        $this->assertFalse($service->enabled($user, self::KEY));

        // Unregistered key -> normal
        $this->assertSame('normal', $service->deliveryFor($user, 'unregistered_event_key'));

        // Ineligible user -> normal
        $supplier = User::factory()->create(['role' => 'supplier']);
        $this->assertSame('normal', $service->deliveryFor($supplier, self::KEY));
    }

    public function test_merge_overrides_stores_silent_only_when_enabled_and_off_wins(): void
    {
        $service = app(NotificationPreferenceService::class);
        $user = User::factory()->create(['role' => 'finance']);

        // 1. On + silent -> 'silent'
        $merged = $service->mergeOverrides($user, [], [self::KEY => 1], [self::KEY => 'silent']);
        $this->assertSame([self::KEY => 'silent'], $merged);

        // 2. On + normal -> key removed
        $merged = $service->mergeOverrides($user, [self::KEY => 'silent'], [self::KEY => 1], [self::KEY => 'normal']);
        $this->assertSame([], $merged);

        // 3. On + absent delivery -> key removed
        $merged = $service->mergeOverrides($user, [self::KEY => 'silent'], [self::KEY => 1], []);
        $this->assertSame([], $merged);

        // 4. Off + silent -> false (Off wins over silent)
        $merged = $service->mergeOverrides($user, [], [self::KEY => 0], [self::KEY => 'silent']);
        $this->assertSame([self::KEY => false], $merged);

        // 5. Partial update retains existing silent state when key not submitted
        $otherKey = 'local_invoice_paid';
        $stored = [self::KEY => 'silent', $otherKey => false];
        $merged = $service->mergeOverrides($user, $stored, [self::KEY => 0], []);
        $this->assertSame([self::KEY => false, $otherKey => false], $merged);
    }

    public function test_silent_event_delivers_to_database_with_silent_flag_and_suppresses_broadcast(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $user = User::factory()->create(['role' => 'finance']);
        $pref = $user->preference()->create(config('user_preferences.defaults'));
        $pref->forceFill(['notification_preferences' => [self::KEY => 'silent']])->save();

        $notification = new \App\Notifications\SystemNotification('Title', 'Msg', '#', 'bell', ['event' => 'local_invoice.submitted']);
        $user->notify($notification);

        $this->assertSame(1, $user->notifications()->count());
        $stored = $user->notifications()->sole();
        $this->assertTrue($stored->data['silent'] ?? false);
        \Illuminate\Support\Facades\Queue::assertNothingPushed();
    }

    public function test_off_event_suppresses_both_database_and_broadcast(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $user = User::factory()->create(['role' => 'finance']);
        $pref = $user->preference()->create(config('user_preferences.defaults'));
        $pref->forceFill(['notification_preferences' => [self::KEY => false]])->save();

        $notification = new \App\Notifications\SystemNotification('Title', 'Msg', '#', 'bell', ['event' => 'local_invoice.submitted']);
        $user->notify($notification);

        $this->assertSame(0, $user->notifications()->count());
        \Illuminate\Support\Facades\Queue::assertNothingPushed();
    }

    public function test_normal_event_delivers_to_database_and_broadcast_without_silent_key(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $user = User::factory()->create(['role' => 'finance']);

        $notification = new \App\Notifications\SystemNotification('Title', 'Msg', '#', 'bell', ['event' => 'local_invoice.submitted']);
        $user->notify($notification);

        $this->assertSame(1, $user->notifications()->count());
        $stored = $user->notifications()->sole();
        $this->assertArrayNotHasKey('silent', $stored->data);
        \Illuminate\Support\Facades\Queue::assertPushed(\Illuminate\Broadcasting\BroadcastEvent::class, 1);
    }

    public function test_caller_supplied_silent_flag_is_overridden_by_service(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $user = User::factory()->create(['role' => 'finance']);

        // User is Normal; caller attempts to spoof silent => true
        $notification = new \App\Notifications\SystemNotification('Title', 'Msg', '#', 'bell', [
            'event' => 'local_invoice.submitted',
            'silent' => true,
        ]);
        $user->notify($notification);

        $stored = $user->notifications()->sole();
        $this->assertArrayNotHasKey('silent', $stored->data);
    }

    public function test_unregistered_event_delivered_normally_even_if_similar_silent_key_stored(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $user = User::factory()->create(['role' => 'finance']);
        $pref = $user->preference()->create(config('user_preferences.defaults'));
        $pref->forceFill(['notification_preferences' => ['unregistered_event_key' => 'silent']])->save();

        $notification = new \App\Notifications\SystemNotification('Title', 'Msg', '#', 'bell', ['event' => 'unregistered_event']);
        $user->notify($notification);

        $this->assertSame(1, $user->notifications()->count());
        $stored = $user->notifications()->sole();
        $this->assertArrayNotHasKey('silent', $stored->data);
        \Illuminate\Support\Facades\Queue::assertPushed(\Illuminate\Broadcasting\BroadcastEvent::class, 1);
    }
}
