<?php

namespace App\Http\Requests\Admin;

use App\Models\MaintenanceRecord;
use App\Services\Maintenance\MaintenanceService;
use App\Support\BusinessTime;
use App\Support\Maintenance\MaintenanceModuleRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the maintenance form for its current lifecycle stage. Date and time
 * arrive as separate business-time fields; payload() joins them in the business
 * timezone. Clock-relative rules live in MaintenanceService, under the row lock.
 */
class MaintenanceRecordRequest extends FormRequest
{
    public const STAGE_PLAN = 'plan';

    public const STAGE_ACTIVE = 'active';

    public const STAGE_COMPLETED = 'completed';

    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    public function rules(): array
    {
        $stage = self::stageOf($this->record());
        $rules = [
            'action' => ['required', 'string', Rule::in($stage === self::STAGE_PLAN ? MaintenanceService::PLAN_ACTIONS : [MaintenanceService::ACTION_SAVE])],
            'title' => ['required', 'string', 'max:150'],
            'description' => ['required', 'string', 'max:2000'],
        ];

        if ($stage === self::STAGE_COMPLETED) {
            return $rules;
        }

        $moduleRules = [
            'modules' => ['required', 'array', 'min:1'],
            'modules.*' => ['string', 'distinct', Rule::in(app(MaintenanceModuleRegistry::class)->keys())],
        ];
        $endRules = [
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'required_with:ends_time', 'after_or_equal:2000-01-01', 'before:2100-01-01'],
            'ends_time' => ['nullable', 'date_format:H:i', 'required_with:ends_on'],
        ];

        if ($stage === self::STAGE_ACTIVE) {
            $endRules['ends_on'][] = 'required';
            $endRules['ends_time'][] = 'required';

            return $this->record()?->isGlobal() ? [...$rules, ...$endRules] : [...$rules, ...$endRules, ...$moduleRules];
        }

        $endRules['ends_on'][] = 'required_unless:action,'.MaintenanceService::ACTION_DRAFT;
        $endRules['ends_time'][] = 'required_unless:action,'.MaintenanceService::ACTION_DRAFT;

        return [
            ...$rules,
            ...$endRules,
            'type' => ['required', 'string', Rule::in(MaintenanceRecord::TYPES)],
            'modules' => ['exclude_unless:type,'.MaintenanceRecord::TYPE_MODULE, ...$moduleRules['modules']],
            'modules.*' => ['exclude_unless:type,'.MaintenanceRecord::TYPE_MODULE, ...$moduleRules['modules.*']],
            'starts_on' => ['nullable', 'date_format:Y-m-d', 'required_with:starts_time', 'required_if:action,'.MaintenanceService::ACTION_SCHEDULE, 'after_or_equal:2000-01-01', 'before:2100-01-01'],
            'starts_time' => ['nullable', 'date_format:H:i', 'required_with:starts_on', 'required_if:action,'.MaintenanceService::ACTION_SCHEDULE],
        ];
    }

    /** Accepts typed times such as "930", "9:30" or "09.30" (no-JS fallback) as HH:MM. */
    protected function prepareForValidation(): void
    {
        foreach (['starts_time', 'ends_time'] as $field) {
            $value = trim((string) $this->input($field, ''));
            if ($value === '') {
                continue;
            }
            if (preg_match('/^(\d{1,2})[:.](\d{2})$/', $value, $parts) === 1) {
                [$hour, $minute] = [$parts[1], $parts[2]];
            } elseif (preg_match('/^\d{1,4}$/', $value) === 1) {
                [$hour, $minute] = match (strlen($value)) {
                    1, 2 => [$value, '0'],
                    3 => [substr($value, 0, 1), substr($value, 1)],
                    default => [substr($value, 0, 2), substr($value, 2)],
                };
            } else {
                continue;
            }
            if ((int) $hour <= 23 && (int) $minute <= 59) {
                $this->merge([$field => sprintf('%02d:%02d', (int) $hour, (int) $minute)]);
            }
        }
    }

    /** A schedule that starts while its form is open accepts only "save" afterwards. */
    public function messages(): array
    {
        return ['action.in' => __('maintenance.errors.state_changed')];
    }

    public function attributes(): array
    {
        return [
            'type' => __('maintenance.fields.type'),
            'modules' => __('maintenance.fields.modules'),
            'modules.*' => __('maintenance.fields.modules'),
            'title' => __('maintenance.fields.title'),
            'description' => __('maintenance.fields.description'),
            'starts_on' => __('maintenance.fields.start_date'),
            'starts_time' => __('maintenance.fields.start_time'),
            'ends_on' => __('maintenance.fields.end_date'),
            'ends_time' => __('maintenance.fields.end_time'),
            'action' => __('maintenance.fields.action'),
        ];
    }

    /**
     * @return array{type: ?string, modules: list<string>, title: string, description: string, starts_at: ?CarbonImmutable, estimated_end_at: ?CarbonImmutable}
     */
    public function payload(): array
    {
        return [
            'type' => $this->validated('type'),
            'modules' => array_values((array) $this->validated('modules', [])),
            'title' => trim((string) $this->validated('title')),
            'description' => trim((string) $this->validated('description')),
            'starts_at' => $this->moment('starts_on', 'starts_time'),
            'estimated_end_at' => $this->moment('ends_on', 'ends_time'),
        ];
    }

    public function action(): string
    {
        return (string) $this->validated('action');
    }

    /** Which fields a record still accepts: the full plan, the running window, or text only. */
    public static function stageOf(?MaintenanceRecord $record): string
    {
        if ($record === null) {
            return self::STAGE_PLAN;
        }
        if ($record->status === MaintenanceRecord::STATUS_COMPLETED) {
            return self::STAGE_COMPLETED;
        }

        return $record->status === MaintenanceRecord::STATUS_ACTIVE || $record->isEffective()
            ? self::STAGE_ACTIVE
            : self::STAGE_PLAN;
    }

    private function record(): ?MaintenanceRecord
    {
        $record = $this->route('maintenance');

        return $record instanceof MaintenanceRecord ? $record : null;
    }

    private function moment(string $dateKey, string $timeKey): ?CarbonImmutable
    {
        $date = $this->validated($dateKey);
        $time = $this->validated($timeKey);
        if (blank($date) || blank($time)) {
            return null;
        }

        $moment = CarbonImmutable::createFromFormat('!Y-m-d H:i', $date.' '.$time, BusinessTime::tz());

        return $moment === false ? null : $moment;
    }
}
