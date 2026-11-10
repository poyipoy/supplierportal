<?php

namespace Tests\Feature\Maintenance;

use App\Exceptions\BusinessRuleException;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceRecordModule;
use App\Models\User;
use App\Services\Maintenance\MaintenanceResolver;
use App\Services\Maintenance\MaintenanceService;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MaintenanceLifecycleTest extends TestCase
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

    public function test_draft_keeps_an_optional_window_and_never_blocks(): void
    {
        $record = $this->service()->create($this->actor, $this->data(['import.quotations'], [
            'estimated_end_at' => null,
        ]), MaintenanceService::ACTION_DRAFT);

        $this->assertSame(MaintenanceRecord::STATUS_DRAFT, $record->status);
        $this->assertNull($record->starts_at);
        $this->assertSame(['import.quotations'], $record->activeModuleKeys());
        $this->assertSame($this->actor->id, $record->created_by);
        $this->assertTrue((new MaintenanceResolver)->effectiveRecords()->isEmpty());
    }

    public function test_schedule_blocks_once_due_even_before_the_scheduler_runs_and_the_command_is_idempotent(): void
    {
        $record = $this->service()->create($this->actor, $this->data(['import.shipments'], [
            'starts_at' => BusinessTime::now()->addMinutes(30),
            'estimated_end_at' => BusinessTime::now()->addHours(3),
        ]), MaintenanceService::ACTION_SCHEDULE);

        $this->assertSame(MaintenanceRecord::STATUS_SCHEDULED, $record->status);
        $this->assertSame(MaintenanceRecord::STATUS_SCHEDULED, $record->displayStatus());
        $this->assertSame([], (new MaintenanceResolver)->blockedModuleCounts());

        $this->travel(31)->minutes();

        $this->assertSame(['import.shipments' => 1], (new MaintenanceResolver)->blockedModuleCounts());
        $this->assertSame(MaintenanceRecord::STATUS_SCHEDULED, $record->fresh()->status);
        $this->assertSame(MaintenanceRecord::STATUS_ACTIVE, $record->fresh()->displayStatus());

        $this->artisan('maintenance:activate-due')->expectsOutputToContain('Activated 1')->assertSuccessful();
        $this->assertSame(MaintenanceRecord::STATUS_ACTIVE, $record->fresh()->status);
        $this->artisan('maintenance:activate-due')->expectsOutputToContain('Activated 0')->assertSuccessful();
    }

    public function test_schedule_requires_a_future_start_and_an_end_after_it(): void
    {
        try {
            $this->service()->create($this->actor, $this->data(['import.chat'], [
                'starts_at' => BusinessTime::now()->subMinute(),
            ]), MaintenanceService::ACTION_SCHEDULE);
            $this->fail('A schedule in the past must be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('starts_on', $exception->errors());
        }

        try {
            $this->service()->create($this->actor, $this->data(['import.chat'], [
                'starts_at' => BusinessTime::now()->addHours(2),
                'estimated_end_at' => BusinessTime::now()->addHour(),
            ]), MaintenanceService::ACTION_SCHEDULE);
            $this->fail('An end before the start must be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('ends_on', $exception->errors());
        }

        $this->assertSame(0, MaintenanceRecord::withTrashed()->count());
    }

    public function test_manual_activation_takes_the_start_from_the_server_clock(): void
    {
        $record = $this->service()->create($this->actor, $this->data(['import.chat'], [
            'starts_at' => BusinessTime::now()->subDays(5),
        ]), MaintenanceService::ACTION_ACTIVATE);

        $this->assertSame(MaintenanceRecord::STATUS_ACTIVE, $record->status);
        $this->assertSame('2026-11-10 03:00:00', $record->fresh()->getRawOriginal('starts_at'));
    }

    public function test_activate_now_from_a_draft_requires_an_end_later_than_now(): void
    {
        $draft = $this->service()->create($this->actor, $this->data(['local.invoices'], [
            'estimated_end_at' => null,
        ]), MaintenanceService::ACTION_DRAFT);

        $this->expectRefusal(fn () => $this->service()->activate($this->actor, $draft), 'maintenance.errors.activate_needs_future_end');

        $this->travel(10)->minutes();
        $this->service()->update($this->actor, $draft, $this->data(['local.invoices'], [
            'estimated_end_at' => BusinessTime::now()->addHour(),
        ]), MaintenanceService::ACTION_DRAFT);
        $active = $this->service()->activate($this->actor, $draft);

        $this->assertSame(MaintenanceRecord::STATUS_ACTIVE, $active->status);
        $this->assertSame('2026-11-10 03:10:00', $active->getRawOriginal('starts_at'));
        $this->expectRefusal(fn () => $this->service()->activate($this->actor, $draft), 'maintenance.errors.already_active');
    }

    public function test_overdue_is_informational_and_keeps_blocking_until_explicit_deactivation(): void
    {
        $record = $this->activeMaintenance($this->actor, ['import.price_history'], [
            'estimated_end_at' => BusinessTime::now()->addHour(),
        ]);

        $this->travel(3)->hours();

        $this->assertSame(MaintenanceRecord::DISPLAY_OVERDUE, $record->fresh()->displayStatus());
        $this->assertSame(MaintenanceRecord::STATUS_ACTIVE, $record->fresh()->status);
        $this->assertSame(['import.price_history' => 1], (new MaintenanceResolver)->blockedModuleCounts());
        $this->assertSame(1, MaintenanceRecord::query()->overdue()->count());

        $this->service()->deactivate($this->actor, $record);

        $this->assertSame(MaintenanceRecord::STATUS_COMPLETED, $record->fresh()->status);
        $this->assertSame([], (new MaintenanceResolver)->blockedModuleCounts());
    }

    public function test_releasing_modules_one_by_one_completes_the_record_which_cannot_be_reactivated(): void
    {
        $record = $this->activeMaintenance($this->actor, ['import.quotations', 'import.shipments']);
        $this->travel(5)->minutes();

        $this->service()->deactivateModule($this->actor, $record, 'import.quotations');

        $record->refresh();
        $this->assertSame(MaintenanceRecord::STATUS_ACTIVE, $record->status);
        $this->assertSame(['import.shipments'], $record->activeModuleKeys());
        $this->assertSame('2026-11-10 03:05:00', $record->modules->firstWhere('module_key', 'import.quotations')->getRawOriginal('deactivated_at'));
        $this->expectRefusal(fn () => $this->service()->deactivateModule($this->actor, $record, 'import.quotations'), 'maintenance.errors.module_already_released');

        $this->service()->deactivateModule($this->actor, $record, 'import.shipments');

        $record->refresh();
        $this->assertSame(MaintenanceRecord::STATUS_COMPLETED, $record->status);
        $this->assertSame('2026-11-10 03:05:00', $record->getRawOriginal('completed_at'));
        $this->assertSame(2, $record->modules()->whereNotNull('deactivated_at')->count());
        $this->expectRefusal(fn () => $this->service()->activate($this->actor, $record), 'maintenance.errors.completed_cannot_reactivate');
        $this->expectRefusal(fn () => $this->service()->deactivate($this->actor, $record), 'maintenance.errors.already_completed');
    }

    public function test_editing_active_coverage_takes_effect_immediately_and_keeps_released_rows(): void
    {
        $record = $this->activeMaintenance($this->actor, ['import.quotations']);

        $this->service()->update($this->actor, $record, $this->data(['import.shipments'], [
            'type' => null,
            'title' => 'Shipment upgrade',
        ]), MaintenanceService::ACTION_SAVE);

        $this->assertSame(['import.shipments' => 1], (new MaintenanceResolver)->blockedModuleCounts());
        $this->assertNotNull(MaintenanceRecordModule::query()->where('module_key', 'import.quotations')->value('deactivated_at'));
        $this->assertSame('Shipment upgrade', $record->fresh()->title);
        $this->assertSame(MaintenanceRecord::TYPE_MODULE, $record->fresh()->type);

        $this->service()->update($this->actor, $record, $this->data(['import.shipments', 'import.quotations']), MaintenanceService::ACTION_SAVE);

        $this->assertEqualsCanonicalizing(['import.shipments' => 1, 'import.quotations' => 1], (new MaintenanceResolver)->blockedModuleCounts());
        $this->assertSame(2, $record->modules()->count());
    }

    public function test_running_maintenance_only_accepts_save_and_never_moves_its_start(): void
    {
        $record = $this->activeMaintenance($this->actor, ['import.chat']);
        $this->travel(1)->hour();

        try {
            $this->service()->update($this->actor, $record, $this->data(['import.chat'], [
                'starts_at' => BusinessTime::now()->addDay(),
            ]), MaintenanceService::ACTION_SCHEDULE);
            $this->fail('A running maintenance cannot be rescheduled.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('action', $exception->errors());
        }

        $this->service()->update($this->actor, $record, $this->data(['import.chat'], [
            'starts_at' => BusinessTime::now()->addDay(),
            'estimated_end_at' => BusinessTime::now()->addHours(4),
        ]), MaintenanceService::ACTION_SAVE);

        $this->assertSame('2026-11-10 03:00:00', $record->fresh()->getRawOriginal('starts_at'));
        $this->assertSame('2026-11-10 08:00:00', $record->fresh()->getRawOriginal('estimated_end_at'));
    }

    public function test_soft_delete_removes_only_its_own_blocking_effect_and_keeps_history(): void
    {
        $first = $this->activeMaintenance($this->actor, ['import.quotations']);
        $second = $this->activeMaintenance($this->actor, ['import.quotations', 'import.chat']);

        $this->service()->delete($this->actor, $second);

        $this->assertSoftDeleted('maintenance_records', ['id' => $second->id]);
        $this->assertSame(['import.quotations' => 1], (new MaintenanceResolver)->blockedModuleCounts());
        $this->assertSame(2, MaintenanceRecordModule::query()->where('maintenance_record_id', $second->id)->count());
        $this->assertSame(MaintenanceRecord::DISPLAY_DELETED, MaintenanceRecord::withTrashed()->find($second->id)->displayStatus());
        $this->assertTrue($second->modules()->first()->record->is($second));

        $this->service()->delete($this->actor, $first);
        $this->assertTrue((new MaintenanceResolver)->effectiveRecords()->isEmpty());
        $this->assertSame(2, MaintenanceRecord::withTrashed()->count());
    }

    public function test_timestamps_are_stored_in_utc_and_displayed_in_business_time(): void
    {
        $record = $this->service()->create($this->actor, $this->data(['import.chat'], [
            'starts_at' => $this->businessMoment('2026-11-12 09:30'),
            'estimated_end_at' => $this->businessMoment('2026-11-12 17:00'),
        ]), MaintenanceService::ACTION_SCHEDULE);

        $this->assertSame('2026-11-12 02:30:00', $record->fresh()->getRawOriginal('starts_at'));
        $this->assertSame('2026-11-12 10:00:00', $record->fresh()->getRawOriginal('estimated_end_at'));
        $this->assertSame('12 Nov 2026 09:30 WIB', BusinessTime::format($record->fresh()->starts_at));
    }

    public function test_database_constraints_back_the_lifecycle_rules(): void
    {
        $record = $this->activeMaintenance($this->actor, ['import.chat']);

        $this->assertQueryFails(fn () => DB::table('maintenance_records')->where('id', $record->id)
            ->update(['estimated_end_at' => '2026-11-10 02:00:00']));
        $this->assertQueryFails(fn () => DB::table('maintenance_records')->where('id', $record->id)
            ->update(['status' => MaintenanceRecord::STATUS_COMPLETED]));
        $this->assertQueryFails(fn () => DB::table('maintenance_record_modules')->insert([
            'maintenance_record_id' => $record->id, 'module_key' => 'import.chat', 'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    public function test_scheduler_runs_activation_every_minute_in_business_time_without_overlapping(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'maintenance:activate-due'));

        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame('* * * * *', $event->expression);
        $this->assertSame(config('app.business_timezone'), $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
    }

    private function expectRefusal(callable $callback, string $messageKey): void
    {
        try {
            $callback();
            $this->fail("Expected refusal [{$messageKey}].");
        } catch (BusinessRuleException $exception) {
            $this->assertSame(__($messageKey), $exception->getMessage());
        }
    }

    private function assertQueryFails(callable $callback): void
    {
        try {
            $callback();
            $this->fail('The database constraint should have refused this write.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
