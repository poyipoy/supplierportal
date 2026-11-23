<?php

namespace Tests\Feature\Maintenance;

use App\Models\MaintenanceRecord;
use App\Models\User;
use App\Services\Maintenance\MaintenanceResolver;
use App\Services\Maintenance\MaintenanceService;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MaintenanceResolverCacheTest extends TestCase
{
    use MaintenanceFixtures;
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-10 03:00:00', 'UTC'));
        $this->actor = $this->admin();
    }

    public function test_effective_records_hit_cross_request_cache_and_invalidate_on_model_and_command_changes(): void
    {
        $record = $this->activeMaintenance($this->actor, ['import.quotations', 'import.shipments'], ['title' => 'Cached window']);

        $queries = 0;
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains(strtolower($query->sql), 'maintenance_records')) {
                $queries++;
            }
        });

        $first = (new MaintenanceResolver)->effectiveRecords();
        $this->assertCount(1, $first);
        $this->assertSame(1, $queries);

        // A new resolver instance (simulating another HTTP request) must reuse the cached collection.
        $second = (new MaintenanceResolver)->effectiveRecords();
        $this->assertCount(1, $second);
        $this->assertSame(1, $queries);

        // Cached Eloquent collection with eager-loaded modules must serialize cleanly for FileStore.
        $roundTripped = unserialize(serialize(Cache::get(MaintenanceResolver::CACHE_KEY)));
        $this->assertCount(1, $roundTripped);
        $this->assertEqualsCanonicalizing(['import.quotations', 'import.shipments'], $roundTripped->first()->activeModuleKeys());

        // Custom timestamp bypasses the cross-request cache deterministically.
        $past = (new MaintenanceResolver(BusinessTime::now()->subHour()))->effectiveRecords();
        $this->assertCount(0, $past);
        $this->assertSame(2, $queries);

        // Releasing a single module with the same actor and frozen clock must still invalidate cache.
        $this->service()->deactivateModule($this->actor, $record, 'import.quotations');
        $afterPartial = (new MaintenanceResolver)->effectiveRecords();
        $this->assertSame(['import.shipments'], $afterPartial->first()->activeModuleKeys());

        // Saving/deleting the record invalidates cache immediately.
        $record->update(['title' => 'Updated window']);
        $this->assertSame('Updated window', (new MaintenanceResolver)->effectiveRecords()->first()->title);

        $record->delete();
        $this->assertCount(0, (new MaintenanceResolver)->effectiveRecords());
    }

    public function test_activate_due_command_invalidates_cached_effective_records_when_records_transition(): void
    {
        $scheduled = $this->service()->create($this->actor, $this->data(['import.chat'], [
            'starts_at' => BusinessTime::now()->addMinutes(5),
            'estimated_end_at' => BusinessTime::now()->addHours(2),
        ]), MaintenanceService::ACTION_SCHEDULE);

        $this->travel(5)->minutes();

        // Warm cache with a stale empty collection to verify activate-due explicitly clears the key.
        Cache::put(MaintenanceResolver::CACHE_KEY, collect(), MaintenanceResolver::CACHE_TTL_SECONDS);
        $this->assertTrue(Cache::has(MaintenanceResolver::CACHE_KEY));

        $this->artisan('maintenance:activate-due')
            ->expectsOutputToContain('Activated 1')
            ->assertSuccessful();

        $this->assertFalse(Cache::has(MaintenanceResolver::CACHE_KEY));
        $this->assertSame(MaintenanceRecord::STATUS_ACTIVE, $scheduled->fresh()->status);
        $this->assertSame(['import.chat' => 1], (new MaintenanceResolver)->blockedModuleCounts());
    }
}
