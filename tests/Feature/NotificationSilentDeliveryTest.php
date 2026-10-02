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
}
