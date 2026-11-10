<?php

namespace Tests\Feature\Maintenance;

use App\Models\MaintenanceRecord;
use App\Models\User;
use App\Services\Maintenance\MaintenanceService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminMaintenanceManagementTest extends TestCase
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

    public function test_only_admin_reaches_maintenance_management(): void
    {
        $record = $this->activeMaintenance($this->actor, ['import.chat']);

        foreach (['purchasing', 'finance', 'qc', 'ga'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->get(route('admin.maintenance.index'))->assertForbidden();
            $this->actingAs($user)->post(route('admin.maintenance.deactivate', $record))->assertForbidden();
        }
        $this->actingAs($this->supplier(['local']))->post(route('admin.maintenance.store'), [])->assertForbidden();
        auth()->logout();
        $this->get(route('admin.maintenance.index'))->assertRedirect();

        $this->assertSame(MaintenanceRecord::STATUS_ACTIVE, $record->fresh()->status);
    }

    public function test_admin_schedules_module_maintenance_with_business_time_inputs(): void
    {
        $this->actingAs($this->actor)->get(route('admin.maintenance.create'))
            ->assertOk()
            ->assertSee(__('maintenance.scopes.import'))
            ->assertSee(__('maintenance.scopes.local'))
            ->assertSee('name="modules[]"', false)
            ->assertSee('value="schedule"', false);

        $response = $this->actingAs($this->actor)->post(route('admin.maintenance.store'), [
            'action' => MaintenanceService::ACTION_SCHEDULE,
            'type' => MaintenanceRecord::TYPE_MODULE,
            'modules' => ['import.quotations', 'local.invoices'],
            'title' => 'Year-end closing',
            'description' => 'Quotation and invoice submission pause for closing.',
            'starts_on' => '2026-11-12',
            'starts_time' => '09:30',
            'ends_on' => '2026-11-12',
            'ends_time' => '17:00',
        ]);

        $record = MaintenanceRecord::query()->sole();
        $response->assertRedirect(route('admin.maintenance.show', $record))->assertSessionHas('success', __('maintenance.flash.saved_schedule'));
        $this->assertSame(MaintenanceRecord::STATUS_SCHEDULED, $record->status);
        $this->assertSame('2026-11-12 02:30:00', $record->getRawOriginal('starts_at'));
        $this->assertSame('2026-11-12 10:00:00', $record->getRawOriginal('estimated_end_at'));
        $this->assertEqualsCanonicalizing(['import.quotations', 'local.invoices'], $record->activeModuleKeys());

        $this->actingAs($this->actor)->get(route('admin.maintenance.show', $record))
            ->assertOk()
            ->assertSee('Year-end closing')
            ->assertSee('12 Nov 2026 09:30 WIB')
            ->assertSee(__('maintenance.modules.local.invoices'))
            ->assertSee(__('status.maintenance.scheduled'))
            ->assertSee(route('admin.maintenance.activate', $record), false);
    }

    public function test_save_and_activate_ignores_any_client_start(): void
    {
        $this->actingAs($this->actor)->post(route('admin.maintenance.store'), [
            'action' => MaintenanceService::ACTION_ACTIVATE,
            'type' => MaintenanceRecord::TYPE_GLOBAL,
            'title' => 'Emergency patch',
            'description' => 'All supplier features pause for an urgent patch.',
            'starts_on' => '2026-01-01',
            'starts_time' => '00:00',
            'ends_on' => '2026-11-10',
            'ends_time' => '12:00',
        ])->assertRedirect()->assertSessionHas('success', __('maintenance.flash.saved_activate'));

        $record = MaintenanceRecord::query()->sole();
        $this->assertSame(MaintenanceRecord::STATUS_ACTIVE, $record->status);
        $this->assertSame('2026-11-10 03:00:00', $record->getRawOriginal('starts_at'));
        $this->assertSame(0, $record->modules()->count());
    }

    public function test_validation_explains_missing_modules_window_and_past_schedule(): void
    {
        $this->actingAs($this->actor)->from(route('admin.maintenance.create'))->post(route('admin.maintenance.store'), [
            'action' => MaintenanceService::ACTION_SCHEDULE,
            'type' => MaintenanceRecord::TYPE_MODULE,
            'modules' => [],
            'title' => '',
            'description' => 'Missing parts.',
        ])->assertRedirect(route('admin.maintenance.create'))
            ->assertSessionHasErrors(['modules', 'title', 'starts_on', 'starts_time', 'ends_on', 'ends_time']);

        $this->actingAs($this->actor)->from(route('admin.maintenance.create'))->post(route('admin.maintenance.store'), [
            'action' => MaintenanceService::ACTION_SCHEDULE,
            'type' => MaintenanceRecord::TYPE_MODULE,
            'modules' => ['import.unknown'],
            'title' => 'Past',
            'description' => 'Starts in the past.',
            'starts_on' => '2026-11-10',
            'starts_time' => '09:00',
            'ends_on' => '2026-11-10',
            'ends_time' => '11:00',
        ])->assertSessionHasErrors(['modules.0']);

        $this->actingAs($this->actor)->from(route('admin.maintenance.create'))->post(route('admin.maintenance.store'), [
            'action' => MaintenanceService::ACTION_SCHEDULE,
            'type' => MaintenanceRecord::TYPE_MODULE,
            'modules' => ['import.chat'],
            'title' => 'Past',
            'description' => 'Starts in the past.',
            'starts_on' => '2026-11-10',
            'starts_time' => '09:00',
            'ends_on' => '2026-11-10',
            'ends_time' => '11:00',
        ])->assertSessionHasErrors(['starts_on' => __('maintenance.errors.start_in_future')]);

        $this->assertSame(0, MaintenanceRecord::withTrashed()->count());
    }

    public function test_index_summarises_coverage_and_filters_by_status_type_module_and_title(): void
    {
        $global = $this->activeMaintenance($this->actor, [], ['title' => 'Global closing']);
        $this->activeMaintenance($this->actor, ['import.chat'], ['title' => 'Chat migration']);
        $this->service()->create($this->actor, $this->data(['local.invoices'], ['title' => 'Invoice draft', 'estimated_end_at' => null]), MaintenanceService::ACTION_DRAFT);
        $deleted = $this->activeMaintenance($this->actor, ['import.shipments'], ['title' => 'Removed shipment window']);
        $this->service()->delete($this->actor, $deleted);

        $this->actingAs($this->actor)->get(route('admin.maintenance.index'))
            ->assertOk()
            ->assertSee(__('maintenance.summary.global_active'))
            ->assertSee(route('admin.maintenance.show', $global), false)
            ->assertSee('data-maintenance-blocked-modules', false)
            ->assertSee('Chat migration')
            ->assertSee('Invoice draft')
            ->assertDontSee('Removed shipment window');

        $this->actingAs($this->actor)->get(route('admin.maintenance.index', ['status' => 'deleted']))
            ->assertOk()->assertSee('Removed shipment window')->assertDontSee('Chat migration');
        $this->actingAs($this->actor)->get(route('admin.maintenance.index', ['status' => 'draft']))
            ->assertOk()->assertSee('Invoice draft')->assertDontSee('Chat migration');
        $this->actingAs($this->actor)->get(route('admin.maintenance.index', ['type' => 'global']))
            ->assertOk()->assertSee('Global closing')->assertDontSee('Chat migration');
        $this->actingAs($this->actor)->get(route('admin.maintenance.index', ['module' => 'import.chat']))
            ->assertOk()->assertSee('Chat migration')->assertDontSee('Invoice draft');
        $this->actingAs($this->actor)->get(route('admin.maintenance.index', ['q' => 'migration']))
            ->assertOk()->assertSee('Chat migration')->assertDontSee('data-maintenance-row="'.$global->hash.'"', false);
        $this->actingAs($this->actor)->get(route('admin.maintenance.index', ['status' => 'unknown']))
            ->assertRedirect();

        $this->travel(3)->hours();
        $this->actingAs($this->actor)->get(route('admin.maintenance.index', ['status' => 'overdue']))
            ->assertOk()->assertSee('Chat migration')->assertSee(__('status.maintenance.overdue'));
    }

    public function test_detail_flags_modules_still_held_by_another_record_and_releases_one_module(): void
    {
        $first = $this->activeMaintenance($this->actor, ['import.chat', 'import.quotations'], ['title' => 'First window']);
        $this->activeMaintenance($this->actor, ['import.chat'], ['title' => 'Second window']);

        $this->actingAs($this->actor)->get(route('admin.maintenance.show', $first))
            ->assertOk()
            ->assertSee('data-maintenance-other-blockers', false)
            ->assertSee('Second window')
            ->assertSee(route('admin.maintenance.modules.deactivate', [$first, 'import.chat']), false);

        $this->actingAs($this->actor)->post(route('admin.maintenance.modules.deactivate', [$first, 'import.chat']))
            ->assertRedirect(route('admin.maintenance.show', $first))
            ->assertSessionHas('success', __('maintenance.flash.module_deactivated', ['module' => __('maintenance.modules.import.chat')]));
        $this->actingAs($this->actor)->post(route('admin.maintenance.modules.deactivate', [$first, 'import.unknown']))->assertNotFound();
        $this->actingAs($this->actor)->post(route('admin.maintenance.modules.deactivate', [$first, 'import.chat']))
            ->assertSessionHas('error', __('maintenance.errors.module_already_released'));

        $this->assertSame(['import.quotations'], $first->fresh()->activeModuleKeys());
    }

    public function test_activate_deactivate_and_soft_delete_through_http(): void
    {
        $draft = $this->service()->create($this->actor, $this->data(['import.chat']), MaintenanceService::ACTION_DRAFT);

        $this->actingAs($this->actor)->post(route('admin.maintenance.activate', $draft))
            ->assertSessionHas('success', __('maintenance.flash.activated'));
        $this->assertSame(MaintenanceRecord::STATUS_ACTIVE, $draft->fresh()->status);

        $this->actingAs($this->actor)->post(route('admin.maintenance.deactivate', $draft))
            ->assertSessionHas('success', __('maintenance.flash.deactivated'));
        $this->assertSame(MaintenanceRecord::STATUS_COMPLETED, $draft->fresh()->status);

        $this->actingAs($this->actor)->post(route('admin.maintenance.activate', $draft))
            ->assertSessionHas('error', __('maintenance.errors.completed_cannot_reactivate'));

        $this->actingAs($this->actor)->delete(route('admin.maintenance.destroy', $draft))
            ->assertRedirect(route('admin.maintenance.index'))
            ->assertSessionHas('success', __('maintenance.flash.deleted'));
        $this->assertSoftDeleted('maintenance_records', ['id' => $draft->id]);

        $this->actingAs($this->actor)->get(route('admin.maintenance.show', $draft))
            ->assertOk()
            ->assertSee(__('status.maintenance.deleted'))
            ->assertDontSee(route('admin.maintenance.edit', $draft), false);
        $this->actingAs($this->actor)->get(route('admin.maintenance.edit', $draft))->assertNotFound();
        $this->actingAs($this->actor)->get(str_replace($draft->hash, (string) $draft->id, route('admin.maintenance.show', $draft)))->assertNotFound();
    }

    public function test_edit_form_follows_the_lifecycle_stage(): void
    {
        $active = $this->activeMaintenance($this->actor, ['import.chat'], ['title' => 'Running window']);

        $this->actingAs($this->actor)->get(route('admin.maintenance.edit', $active))
            ->assertOk()
            ->assertSee(__('maintenance.form.type_locked'))
            ->assertSee('name="modules[]"', false)
            ->assertDontSee('name="starts_on"', false)
            ->assertSee('value="save"', false)
            ->assertDontSee('value="schedule"', false);

        $this->actingAs($this->actor)->put(route('admin.maintenance.update', $active), [
            'action' => MaintenanceService::ACTION_SAVE,
            'modules' => ['import.chat', 'import.shipments'],
            'title' => 'Running window, extended',
            'description' => 'Extended to shipments.',
            'ends_on' => '2026-11-10',
            'ends_time' => '18:00',
        ])->assertRedirect(route('admin.maintenance.show', $active))->assertSessionHas('success', __('maintenance.flash.saved_save'));

        $active->refresh();
        $this->assertSame('Running window, extended', $active->title);
        $this->assertSame('2026-11-10 11:00:00', $active->getRawOriginal('estimated_end_at'));
        $this->assertEqualsCanonicalizing(['import.chat', 'import.shipments'], $active->activeModuleKeys());

        $this->service()->deactivate($this->actor, $active);
        $this->actingAs($this->actor)->get(route('admin.maintenance.edit', $active))
            ->assertOk()->assertSee(__('maintenance.form.completed_locked'))->assertDontSee('name="ends_on"', false);
        $this->actingAs($this->actor)->put(route('admin.maintenance.update', $active), [
            'action' => MaintenanceService::ACTION_SAVE,
            'title' => 'Closed window',
            'description' => 'Kept for history.',
            'modules' => ['import.quotations'],
        ])->assertRedirect();

        $active->refresh();
        $this->assertSame('Closed window', $active->title);
        $this->assertSame(MaintenanceRecord::STATUS_COMPLETED, $active->status);
        $this->assertSame([], $active->activeModuleKeys());
    }

    public function test_a_schedule_that_starts_while_its_form_is_open_refuses_plan_actions(): void
    {
        $scheduled = $this->service()->create($this->actor, $this->data(['import.chat'], [
            'starts_at' => $this->businessMoment('2026-11-10 10:30'),
            'estimated_end_at' => $this->businessMoment('2026-11-10 12:00'),
        ]), MaintenanceService::ACTION_SCHEDULE);
        $this->actingAs($this->actor)->get(route('admin.maintenance.edit', $scheduled))->assertOk()->assertSee('value="schedule"', false);

        $this->travel(45)->minutes();

        $this->actingAs($this->actor)->from(route('admin.maintenance.edit', $scheduled))->put(route('admin.maintenance.update', $scheduled), [
            'action' => MaintenanceService::ACTION_SCHEDULE,
            'type' => MaintenanceRecord::TYPE_MODULE,
            'modules' => ['import.chat'],
            'title' => 'Moved',
            'description' => 'Try to move a started schedule.',
            'starts_on' => '2026-11-11',
            'starts_time' => '09:00',
            'ends_on' => '2026-11-11',
            'ends_time' => '10:00',
        ])->assertSessionHasErrors(['action' => __('maintenance.errors.state_changed')]);

        $this->assertSame('2026-11-10 03:30:00', $scheduled->fresh()->getRawOriginal('starts_at'));
    }

    public function test_form_uses_the_custom_time_picker_and_full_row_module_choices(): void
    {
        $response = $this->actingAs($this->actor)->get(route('admin.maintenance.create'))->assertOk();

        $response->assertSee('data-adasi-time-picker', false)
            ->assertSee('role="combobox"', false)
            ->assertSee('aria-controls="maintenanceStartsTime-time-panel"', false)
            ->assertDontSee('type="time"', false)
            ->assertSee('data-maintenance-module-group="import"', false)
            ->assertSee('data-maintenance-module-group="local"', false)
            ->assertSee(__('maintenance.form.selected_count', ['count' => 0, 'total' => 10]))
            ->assertSee('for="maintenanceModule-import-quotations"', false);
        $this->assertStringNotContainsString('class="form-check ', $response->getContent());
    }

    public function test_typed_times_without_javascript_are_normalised_before_validation(): void
    {
        $this->actingAs($this->actor)->post(route('admin.maintenance.store'), [
            'action' => MaintenanceService::ACTION_SCHEDULE,
            'type' => MaintenanceRecord::TYPE_MODULE,
            'modules' => ['import.chat'],
            'title' => 'Typed times',
            'description' => 'Entered without the picker.',
            'starts_on' => '2026-11-12',
            'starts_time' => '930',
            'ends_on' => '2026-11-12',
            'ends_time' => '17.05',
        ])->assertSessionHasNoErrors();

        $record = MaintenanceRecord::query()->sole();
        $this->assertSame('2026-11-12 02:30:00', $record->getRawOriginal('starts_at'));
        $this->assertSame('2026-11-12 10:05:00', $record->getRawOriginal('estimated_end_at'));

        $this->actingAs($this->actor)->from(route('admin.maintenance.create'))->post(route('admin.maintenance.store'), [
            'action' => MaintenanceService::ACTION_SCHEDULE,
            'type' => MaintenanceRecord::TYPE_MODULE,
            'modules' => ['import.chat'],
            'title' => 'Bad time',
            'description' => 'Out of range.',
            'starts_on' => '2026-11-12',
            'starts_time' => '25:00',
            'ends_on' => '2026-11-12',
            'ends_time' => '17:00',
        ])->assertSessionHasErrors(['starts_time']);
    }

    public function test_admin_sidebar_links_to_maintenance(): void
    {
        $this->actingAs($this->actor)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee(route('admin.maintenance.index'), false)
            ->assertSee(__('navigation.maintenance'));
    }
}
