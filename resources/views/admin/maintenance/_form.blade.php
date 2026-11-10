@php
    use App\Http\Requests\Admin\MaintenanceRecordRequest;
    use App\Models\MaintenanceRecord;
    use App\Services\Maintenance\MaintenanceService;
    use App\Support\BusinessTime;

    $isPlan = $stage === MaintenanceRecordRequest::STAGE_PLAN;
    $isActive = $stage === MaintenanceRecordRequest::STAGE_ACTIVE;
    $isCompleted = $stage === MaintenanceRecordRequest::STAGE_COMPLETED;
    $type = $isPlan ? old('type', $record?->type ?? MaintenanceRecord::TYPE_MODULE) : $record->type;
    $selectedModules = array_map('strval', (array) old('modules', $record ? $record->activeModuleKeys() : []));
    $startLocal = $record?->starts_at ? BusinessTime::toBusiness($record->starts_at) : null;
    $endLocal = $record?->estimated_end_at ? BusinessTime::toBusiness($record->estimated_end_at) : null;
@endphp

<form method="POST" action="{{ $action }}" id="maintenanceForm" class="tw:grid tw:gap-5" x-data="{ type: @js($type) }" data-maintenance-form="{{ $stage }}">
    @csrf
    @if($method !== 'POST') @method($method) @endif

    @if($errors->any())
        <x-ui.alert tone="error" :title="__('maintenance.form.error_summary')">
            <ul class="tw:m-0 tw:ps-4">
                @foreach($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </x-ui.alert>
    @endif

    <x-ui.form-section :title="__('maintenance.form.section_type')" :description="__('maintenance.form.section_type_description')">
        @if($isPlan)
            <fieldset class="tw:m-0 tw:grid tw:gap-3 tw:border-0 tw:p-0 tw:md:grid-cols-2">
                <legend class="tw:sr-only">{{ __('maintenance.fields.type') }}</legend>
                @foreach(MaintenanceRecord::TYPES as $typeOption)
                    <label class="tw:flex tw:cursor-pointer tw:items-start tw:gap-3 tw:rounded-ui-sm tw:border tw:border-outline tw:p-3 tw:has-checked:border-primary tw:has-checked:bg-primary-container">
                        <input type="radio" class="form-check-input tw:mt-1 tw:shrink-0" name="type" value="{{ $typeOption }}" x-model="type" @checked($type === $typeOption)>
                        <span>
                            <span class="tw:block tw:font-semibold tw:text-on-surface">{{ __('maintenance.types.'.$typeOption) }}</span>
                            <span class="tw:block tw:text-ui-xs tw:text-on-surface-variant">{{ __('maintenance.type_help.'.$typeOption) }}</span>
                        </span>
                    </label>
                @endforeach
            </fieldset>
            <x-ui.field-error :messages="$errors->get('type')" />
        @else
            <div>
                <p class="tw:m-0 tw:font-semibold tw:text-on-surface">{{ __('maintenance.types.'.$type) }}</p>
                <p class="tw:m-0 tw:mt-0.5 tw:text-ui-xs tw:text-on-surface-variant">{{ __('maintenance.form.type_locked') }}</p>
            </div>
        @endif
    </x-ui.form-section>

    @if($type === MaintenanceRecord::TYPE_MODULE || $isPlan)
        <x-ui.form-section :title="__('maintenance.form.section_modules')" :description="$isActive ? __('maintenance.form.modules_active_help') : __('maintenance.form.section_modules_description')" x-show="type === 'module'" :x-cloak="$type === \App\Models\MaintenanceRecord::TYPE_GLOBAL ? true : null" data-maintenance-modules>
            @if($isCompleted)
                <ul class="tw:m-0 tw:grid tw:list-none tw:gap-1 tw:p-0">
                    @foreach($record->modules as $moduleRow)
                        <li>{{ $registry->label($moduleRow->module_key) }} <span class="tw:text-ui-xs tw:text-on-surface-variant">({{ $registry->scopeLabel((string) $registry->scopeOf($moduleRow->module_key)) }})</span></li>
                    @endforeach
                </ul>
                <p class="tw:m-0 tw:text-ui-xs tw:text-on-surface-variant">{{ __('maintenance.form.completed_locked') }}</p>
            @else
                <div class="tw:grid tw:gap-6">
                    @foreach($registry->grouped() as $scope => $moduleKeys)
                        @php $groupId = 'maintenanceModules-'.$scope; @endphp
                        <fieldset
                            class="tw:m-0 tw:min-w-0 tw:border-0 tw:p-0"
                            aria-describedby="{{ $groupId }}-count"
                            data-maintenance-module-group="{{ $scope }}"
                            x-data="{
                                count: 0,
                                total: {{ count($moduleKeys) }},
                                labels: @js([
                                    'count' => __('maintenance.form.selected_count'),
                                    'selectAll' => __('maintenance.form.select_all'),
                                    'clearAll' => __('maintenance.form.clear_selection'),
                                    'selectAllIn' => __('maintenance.form.select_all_in', ['group' => $registry->scopeLabel($scope)]),
                                    'clearAllIn' => __('maintenance.form.clear_selection_in', ['group' => $registry->scopeLabel($scope)]),
                                ]),
                                sync() { this.count = $el.querySelectorAll('input[type=checkbox]:checked').length },
                                setAll(on) { $el.querySelectorAll('input[type=checkbox]').forEach((box) => { box.checked = on }); this.sync() },
                            }"
                            x-init="sync()"
                            x-on:change="sync()"
                        >
                            <legend class="tw:sr-only">{{ $registry->scopeLabel($scope) }}</legend>
                            <div class="tw:mb-2 tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-x-4 tw:gap-y-1">
                                <div class="tw:flex tw:flex-wrap tw:items-baseline tw:gap-x-3">
                                    <span class="tw:text-ui-sm tw:font-semibold tw:text-on-surface" aria-hidden="true">{{ $registry->scopeLabel($scope) }}</span>
                                    <span id="{{ $groupId }}-count" class="tw:text-ui-xs tw:text-on-surface-variant ui-tabular-nums" aria-live="polite"
                                        x-text="labels.count.replace(':count', count).replace(':total', total)">{{ __('maintenance.form.selected_count', ['count' => count(array_intersect($moduleKeys, $selectedModules)), 'total' => count($moduleKeys)]) }}</span>
                                </div>
                                <x-ui.button type="button" variant="ghost" size="sm" x-on:click="setAll(count < total)"
                                    x-text="count < total ? labels.selectAll : labels.clearAll"
                                    :aria-label="__('maintenance.form.select_all_in', ['group' => $registry->scopeLabel($scope)])"
                                    x-bind:aria-label="count < total ? labels.selectAllIn : labels.clearAllIn">{{ __('maintenance.form.select_all') }}</x-ui.button>
                            </div>
                            <div class="tw:grid tw:gap-2 tw:sm:grid-cols-2 tw:xl:grid-cols-3">
                                @foreach($moduleKeys as $moduleKey)
                                    @php $checkboxId = 'maintenanceModule-'.str_replace('.', '-', $moduleKey); @endphp
                                    <label for="{{ $checkboxId }}" class="ui-motion tw:m-0 tw:flex tw:min-h-11 tw:cursor-pointer tw:items-center tw:gap-3 tw:rounded-ui-sm tw:border tw:border-outline tw:bg-surface tw:px-3 tw:py-2 tw:text-ui-sm tw:text-on-surface tw:hover:bg-surface-container tw:has-checked:border-primary tw:has-checked:bg-primary-container tw:has-focus-visible:ring-2 tw:has-focus-visible:ring-primary">
                                        <input class="form-check-input tw:m-0 tw:shrink-0" type="checkbox" name="modules[]" value="{{ $moduleKey }}" id="{{ $checkboxId }}" @checked(in_array($moduleKey, $selectedModules, true))>
                                        <span class="tw:min-w-0">{{ $registry->label($moduleKey) }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>
                    @endforeach
                </div>
                <x-ui.field-error :messages="array_merge($errors->get('modules'), collect($errors->get('modules.*'))->flatten()->all())" />
            @endif
        </x-ui.form-section>
    @endif

    <x-ui.form-section :title="__('maintenance.form.section_notice')" :description="__('maintenance.form.section_notice_description')">
        <x-ui.input name="title" id="maintenanceTitle" :label="__('maintenance.fields.title')" :value="$record?->title" maxlength="150" required :helper="__('maintenance.form.character_limit', ['max' => 150])" />
        <x-ui.textarea name="description" id="maintenanceDescription" :label="__('maintenance.fields.description')" :value="$record?->description" rows="5" maxlength="2000" required :helper="__('maintenance.form.description_help').' '.__('maintenance.form.character_limit', ['max' => 2000])" />
    </x-ui.form-section>

    <x-ui.form-section :title="__('maintenance.form.section_window')" :description="__('maintenance.form.section_window_description', ['zone' => BusinessTime::label(), 'timezone' => BusinessTime::tz()])">
        @if($isPlan)
            <div class="tw:grid tw:gap-4 tw:md:grid-cols-2">
                <x-ui.date-picker name="starts_on" id="maintenanceStartsOn" :label="__('maintenance.fields.start_date')" :value="$startLocal?->format('Y-m-d')" :helper="__('maintenance.form.start_help')" />
                <x-ui.time-picker name="starts_time" id="maintenanceStartsTime" :label="__('maintenance.fields.start_time')" :value="$startLocal?->format('H:i')" />
            </div>
        @else
            <div>
                <p class="tw:m-0 tw:text-ui-sm tw:font-medium tw:text-on-surface">{{ __('maintenance.fields.start') }}</p>
                <p class="tw:m-0 tw:mt-0.5 tw:font-semibold ui-tabular-nums">{{ BusinessTime::format($record->starts_at) }}</p>
                <p class="tw:m-0 tw:mt-0.5 tw:text-ui-xs tw:text-on-surface-variant">{{ __('maintenance.form.start_locked', ['time' => BusinessTime::format($record->starts_at)]) }}</p>
            </div>
        @endif

        @if($isCompleted)
            <div>
                <p class="tw:m-0 tw:text-ui-sm tw:font-medium tw:text-on-surface">{{ __('maintenance.fields.estimated_end') }}</p>
                <p class="tw:m-0 tw:mt-0.5 tw:font-semibold ui-tabular-nums">{{ BusinessTime::format($record->estimated_end_at) }}</p>
            </div>
        @else
            <div class="tw:grid tw:gap-4 tw:md:grid-cols-2">
                <x-ui.date-picker name="ends_on" id="maintenanceEndsOn" :label="__('maintenance.fields.end_date')" :value="$endLocal?->format('Y-m-d')" :helper="__('maintenance.form.end_help')" :required="$isActive" />
                <x-ui.time-picker name="ends_time" id="maintenanceEndsTime" :label="__('maintenance.fields.end_time')" :value="$endLocal?->format('H:i')" :required="$isActive" />
            </div>
        @endif
    </x-ui.form-section>

    <x-ui.action-bar>
        <x-slot:left>
            <x-ui.button :href="$cancelUrl" variant="ghost">{{ __('maintenance.actions.cancel') }}</x-ui.button>
        </x-slot:left>
        <x-slot:right>
            @if($isPlan)
                <x-ui.button type="submit" name="action" :value="MaintenanceService::ACTION_DRAFT" variant="outline">{{ __('maintenance.actions.save_draft') }}</x-ui.button>
                <x-ui.button type="submit" name="action" :value="MaintenanceService::ACTION_SCHEDULE" variant="secondary">{{ __('maintenance.actions.save_schedule') }}</x-ui.button>
                <x-ui.button type="submit" name="action" :value="MaintenanceService::ACTION_ACTIVATE"
                    data-maintenance-confirm
                    :data-confirm-title="__('maintenance.confirm.activate_title')"
                    :data-confirm-text="__('maintenance.confirm.activate_text')"
                    :data-confirm-label="__('maintenance.confirm.activate_confirm')"
                    :data-confirm-cancel="__('maintenance.confirm.cancel')">{{ __('maintenance.actions.save_activate') }}</x-ui.button>
            @else
                <x-ui.button type="submit" name="action" :value="MaintenanceService::ACTION_SAVE">{{ __('maintenance.actions.save') }}</x-ui.button>
            @endif
        </x-slot:right>
    </x-ui.action-bar>
</form>

@include('admin.maintenance._confirm-script')
