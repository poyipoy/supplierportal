<?php

namespace App\Services\Maintenance;

use App\Exceptions\BusinessRuleException;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceRecordModule;
use App\Models\User;
use App\Support\BusinessTime;
use App\Support\Maintenance\MaintenanceModuleRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Write side of maintenance. Every transition re-reads the record under a row
 * lock and decides from its current state, so a stale form or a scheduler run
 * between render and submit cannot reactivate, double-complete or overwrite.
 */
final class MaintenanceService
{
    public const ACTION_DRAFT = 'draft';

    public const ACTION_SCHEDULE = 'schedule';

    public const ACTION_ACTIVATE = 'activate';

    public const ACTION_SAVE = 'save';

    public const PLAN_ACTIONS = [self::ACTION_DRAFT, self::ACTION_SCHEDULE, self::ACTION_ACTIVATE];

    public function __construct(private readonly MaintenanceModuleRegistry $registry) {}

    /**
     * @param  array{type: string, modules: list<string>, title: string, description: string, starts_at: ?CarbonImmutable, estimated_end_at: ?CarbonImmutable}  $data
     */
    public function create(User $actor, array $data, string $action): MaintenanceRecord
    {
        return DB::transaction(function () use ($actor, $data, $action): MaintenanceRecord {
            $now = BusinessTime::now();
            $modules = $this->moduleSelection($data['type'], $data['modules']);

            $record = MaintenanceRecord::create([
                'type' => $data['type'],
                'title' => $data['title'],
                'description' => $data['description'],
                ...$this->plan($action, $data['starts_at'], $data['estimated_end_at'], $now),
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $this->syncModules($record, $modules, $now);

            return $record->load('modules');
        });
    }

    /**
     * @param  array{type: ?string, modules: list<string>, title: string, description: string, starts_at: ?CarbonImmutable, estimated_end_at: ?CarbonImmutable}  $data
     */
    public function update(User $actor, MaintenanceRecord $record, array $data, string $action): MaintenanceRecord
    {
        return DB::transaction(function () use ($actor, $record, $data, $action): MaintenanceRecord {
            $now = BusinessTime::now();
            $locked = $this->lock($record, $now);
            $attributes = ['title' => $data['title'], 'description' => $data['description'], 'updated_by' => $actor->id];

            if ($locked->status === MaintenanceRecord::STATUS_COMPLETED) {
                $this->expectAction($action, [self::ACTION_SAVE]);
                $locked->update($attributes);

                return $locked->load('modules');
            }

            if ($locked->status === MaintenanceRecord::STATUS_ACTIVE) {
                $this->expectAction($action, [self::ACTION_SAVE]);
                $end = $data['estimated_end_at'] ?? throw ValidationException::withMessages(['ends_on' => __('maintenance.errors.end_required')]);
                if ($end->lessThanOrEqualTo($locked->starts_at)) {
                    throw ValidationException::withMessages(['ends_on' => __('maintenance.errors.end_after_start')]);
                }

                $locked->update([...$attributes, 'estimated_end_at' => BusinessTime::toStorage($end)]);
                if (! $locked->isGlobal()) {
                    $this->syncModules($locked, $this->moduleSelection(MaintenanceRecord::TYPE_MODULE, $data['modules']), $now);
                }

                return $locked->load('modules');
            }

            // Draft or scheduled but not yet due: the whole plan is still editable.
            $this->expectAction($action, self::PLAN_ACTIONS);
            $type = $data['type'] ?? $locked->type;
            $modules = $this->moduleSelection($type, $data['modules']);

            $locked->update([
                ...$attributes,
                'type' => $type,
                ...$this->plan($action, $data['starts_at'], $data['estimated_end_at'], $now),
            ]);
            $this->syncModules($locked, $modules, $now);

            return $locked->load('modules');
        });
    }

    /** Activate Now from a draft or a schedule that has not started. */
    public function activate(User $actor, MaintenanceRecord $record): MaintenanceRecord
    {
        return DB::transaction(function () use ($actor, $record): MaintenanceRecord {
            $now = BusinessTime::now();
            $locked = $this->lock($record, $now);

            if ($locked->status === MaintenanceRecord::STATUS_COMPLETED) {
                throw new BusinessRuleException(__('maintenance.errors.completed_cannot_reactivate'));
            }
            if ($locked->status === MaintenanceRecord::STATUS_ACTIVE) {
                throw new BusinessRuleException(__('maintenance.errors.already_active'));
            }
            if (! $locked->isGlobal() && $locked->modules()->doesntExist()) {
                throw new BusinessRuleException(__('maintenance.errors.modules_required'));
            }
            if ($locked->estimated_end_at === null || $locked->estimated_end_at->lessThanOrEqualTo(BusinessTime::toStorage($now))) {
                throw new BusinessRuleException(__('maintenance.errors.activate_needs_future_end'));
            }

            $locked->update([
                'status' => MaintenanceRecord::STATUS_ACTIVE,
                'starts_at' => BusinessTime::toStorage($now),
                'updated_by' => $actor->id,
            ]);

            return $locked->load('modules');
        });
    }

    /** Explicit release of the whole record; overdue records stay active until this runs. */
    public function deactivate(User $actor, MaintenanceRecord $record): MaintenanceRecord
    {
        return DB::transaction(function () use ($actor, $record): MaintenanceRecord {
            $now = BusinessTime::now();
            $locked = $this->lock($record, $now);
            $this->expectActive($locked);

            $locked->modules()->whereNull('deactivated_at')->update([
                'deactivated_at' => BusinessTime::toStorage($now),
                'updated_at' => BusinessTime::toStorage($now),
            ]);
            $this->complete($locked, $actor, $now);

            return $locked->load('modules');
        });
    }

    /** Release one module; the record completes when its last module is released. */
    public function deactivateModule(User $actor, MaintenanceRecord $record, string $moduleKey): MaintenanceRecord
    {
        return DB::transaction(function () use ($actor, $record, $moduleKey): MaintenanceRecord {
            $now = BusinessTime::now();
            $locked = $this->lock($record, $now);
            $this->expectActive($locked);

            if ($locked->isGlobal()) {
                throw new BusinessRuleException(__('maintenance.errors.global_has_no_modules'));
            }

            $module = $locked->modules()->where('module_key', $moduleKey)->lockForUpdate()->first();
            if (! $module instanceof MaintenanceRecordModule) {
                throw new BusinessRuleException(__('maintenance.errors.module_not_in_record'));
            }
            if ($module->deactivated_at !== null) {
                throw new BusinessRuleException(__('maintenance.errors.module_already_released'));
            }

            $module->update(['deactivated_at' => BusinessTime::toStorage($now)]);
            $locked->update(['updated_by' => $actor->id]);

            if ($locked->modules()->whereNull('deactivated_at')->doesntExist()) {
                $this->complete($locked, $actor, $now);
            }

            return $locked->load('modules');
        });
    }

    /** Soft delete at any stage; only this record's blocking effect disappears. */
    public function delete(User $actor, MaintenanceRecord $record): void
    {
        DB::transaction(function () use ($actor, $record): void {
            $locked = MaintenanceRecord::query()->lockForUpdate()->find($record->getKey());
            if (! $locked instanceof MaintenanceRecord) {
                return;
            }

            $locked->update(['updated_by' => $actor->id]);
            $locked->delete();
        });
    }

    /**
     * Scheduler: persist the start of every due schedule. Enforcement already
     * treats due schedules as active, so a late run only changes the stored state.
     */
    public function activateDue(): int
    {
        $now = BusinessTime::toStorage(BusinessTime::now());

        return MaintenanceRecord::query()
            ->where('status', MaintenanceRecord::STATUS_SCHEDULED)
            ->where('starts_at', '<=', $now)
            ->update(['status' => MaintenanceRecord::STATUS_ACTIVE, 'updated_at' => $now]);
    }

    private function lock(MaintenanceRecord $record, CarbonImmutable $now): MaintenanceRecord
    {
        $locked = MaintenanceRecord::query()->lockForUpdate()->find($record->getKey());
        if (! $locked instanceof MaintenanceRecord) {
            throw new BusinessRuleException(__('maintenance.errors.record_deleted'));
        }

        if ($locked->status === MaintenanceRecord::STATUS_SCHEDULED && $locked->isEffective($now)) {
            $locked->update(['status' => MaintenanceRecord::STATUS_ACTIVE]);
        }

        return $locked;
    }

    private function complete(MaintenanceRecord $record, User $actor, CarbonImmutable $now): void
    {
        $record->update([
            'status' => MaintenanceRecord::STATUS_COMPLETED,
            'completed_at' => BusinessTime::toStorage($now),
            'updated_by' => $actor->id,
        ]);
    }

    private function expectActive(MaintenanceRecord $record): void
    {
        if ($record->status === MaintenanceRecord::STATUS_COMPLETED) {
            throw new BusinessRuleException(__('maintenance.errors.already_completed'));
        }
        if ($record->status !== MaintenanceRecord::STATUS_ACTIVE) {
            throw new BusinessRuleException(__('maintenance.errors.not_active'));
        }
    }

    private function expectAction(string $action, array $allowed): void
    {
        if (! in_array($action, $allowed, true)) {
            throw ValidationException::withMessages(['action' => __('maintenance.errors.state_changed')]);
        }
    }

    /**
     * Status and window for a plan action. Manual activation always starts at the
     * server clock; a schedule must start in the future.
     *
     * @return array{status: string, starts_at: ?CarbonImmutable, estimated_end_at: ?CarbonImmutable}
     */
    private function plan(string $action, ?CarbonImmutable $start, ?CarbonImmutable $end, CarbonImmutable $now): array
    {
        $errors = [];

        if ($action === self::ACTION_ACTIVATE) {
            $start = $now;
        }

        if ($action === self::ACTION_SCHEDULE) {
            if ($start === null) {
                $errors['starts_on'] = __('maintenance.errors.start_required');
            } elseif ($start->lessThanOrEqualTo($now)) {
                $errors['starts_on'] = __('maintenance.errors.start_in_future');
            }
        }

        if ($action !== self::ACTION_DRAFT && $end === null) {
            $errors['ends_on'] = __('maintenance.errors.end_required');
        } elseif ($start !== null && $end !== null && $end->lessThanOrEqualTo($start)) {
            $errors['ends_on'] = $action === self::ACTION_ACTIVATE
                ? __('maintenance.errors.end_after_now')
                : __('maintenance.errors.end_after_start');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'status' => match ($action) {
                self::ACTION_ACTIVATE => MaintenanceRecord::STATUS_ACTIVE,
                self::ACTION_SCHEDULE => MaintenanceRecord::STATUS_SCHEDULED,
                default => MaintenanceRecord::STATUS_DRAFT,
            },
            'starts_at' => $start === null ? null : BusinessTime::toStorage($start),
            'estimated_end_at' => $end === null ? null : BusinessTime::toStorage($end),
        ];
    }

    /** @return list<string> */
    private function moduleSelection(string $type, array $modules): array
    {
        if ($type === MaintenanceRecord::TYPE_GLOBAL) {
            return [];
        }

        $keys = array_values(array_unique(array_filter($modules, fn ($key): bool => is_string($key) && $this->registry->has($key))));
        if ($keys === []) {
            throw ValidationException::withMessages(['modules' => __('maintenance.errors.modules_required')]);
        }

        return $keys;
    }

    /**
     * Before a record takes effect its rows are only a plan and are replaced.
     * Once it blocks, a removed module is released (deactivated_at) instead of
     * deleted, and re-selecting a released module blocks it again.
     *
     * @param  list<string>  $keys
     */
    private function syncModules(MaintenanceRecord $record, array $keys, CarbonImmutable $now): void
    {
        $existing = $record->modules()->lockForUpdate()->get()->keyBy('module_key');
        $effective = $record->status === MaintenanceRecord::STATUS_ACTIVE;
        $stamp = BusinessTime::toStorage($now);

        foreach ($existing as $key => $row) {
            if (in_array($key, $keys, true)) {
                if ($row->deactivated_at !== null) {
                    $row->update(['deactivated_at' => null]);
                }

                continue;
            }

            if ($effective) {
                if ($row->deactivated_at === null) {
                    $row->update(['deactivated_at' => $stamp]);
                }
            } else {
                $row->delete();
            }
        }

        foreach ($keys as $key) {
            if (! $existing->has($key)) {
                $record->modules()->create(['module_key' => $key]);
            }
        }
    }
}
