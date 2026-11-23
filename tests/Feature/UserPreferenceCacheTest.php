<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\UserPreferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserPreferenceCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_preferences_hit_cross_request_cache_and_invalidate_on_updates(): void
    {
        $user = User::factory()->create(['role' => 'purchasing']);
        $other = User::factory()->create(['role' => 'finance']);
        $service = app(UserPreferenceService::class);

        $queries = 0;
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains(strtolower($query->sql), 'user_preferences')) {
                $queries++;
            }
        });

        $first = $service->for($user);
        $this->assertSame('en', $first['locale']);
        $this->assertSame(1, $queries);

        // Simulate a brand-new HTTP request without request-attribute memoization.
        request()->attributes->remove(UserPreferenceService::class.'.'.$user->getKey());

        $second = $service->for($user);
        $this->assertSame($first, $second);
        $this->assertSame(1, $queries, 'Second request must serve preferences from cross-request cache.');

        // Cached payload must survive real serialization (FileStore on cPanel).
        $roundTripped = unserialize(serialize(Cache::get(UserPreferenceService::cacheKey((int) $user->id))));
        $this->assertSame($first, $roundTripped);

        // Updating via service invalidates cache immediately and isolates other users.
        $service->saveLocale($user, 'id');
        request()->attributes->remove(UserPreferenceService::class.'.'.$user->getKey());

        $afterUpdate = $service->for($user);
        $this->assertSame('id', $afterUpdate['locale']);
        $this->assertSame('en', $service->for($other)['locale']);

        // Direct model save and delete must also invalidate cache via AppServiceProvider hooks.
        $preference = $user->preference()->firstOrFail();
        $preference->update(['theme' => 'dark']);
        request()->attributes->remove(UserPreferenceService::class.'.'.$user->getKey());
        $this->assertSame('dark', $service->for($user)['theme']);

        $preference->delete();
        request()->attributes->remove(UserPreferenceService::class.'.'.$user->getKey());
        $this->assertSame('en', $service->for($user)['locale']);
        $this->assertSame(0, $service->for($user)['revision']);
    }
}
