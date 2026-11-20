@extends('layouts.app')
@section('title', __('maintenance.page.browser_title'))
@section('page-title', __('maintenance.page.title'))

@php
    use App\Models\MaintenanceRecord;
    use App\Support\BusinessTime;
    use App\Support\StatusHelper;

    $hasFilters = collect($filters)->filter(fn ($value) => filled($value))->isNotEmpty();
    $queue = [
        [MaintenanceRecord::STATUS_SCHEDULED, 'maintenance.summary.scheduled', 'calendar-clock'],
        [MaintenanceRecord::DISPLAY_OVERDUE, 'maintenance.summary.overdue', 'triangle-alert'],
        [MaintenanceRecord::STATUS_DRAFT, 'maintenance.summary.drafts', 'file-pen'],
    ];
@endphp

@section('content')
<div class="tw:grid tw:gap-6">
    <x-ui.page-header :title="__('maintenance.page.title')" :description="__('maintenance.page.description')" :eyebrow="__('maintenance.page.eyebrow')">
        <x-slot:actions>
            <x-ui.button :href="route('admin.maintenance.create')" size="sm"><x-ui.icon name="plus" /> {{ __('maintenance.actions.create') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <section aria-labelledby="maintenanceSummaryTitle" class="tw:overflow-hidden tw:rounded-ui-md tw:border tw:border-outline tw:bg-surface" data-maintenance-summary>
        <header class="tw:border-b tw:border-outline-variant tw:bg-surface-container tw:px-4 tw:py-3">
            <h2 id="maintenanceSummaryTitle" class="tw:m-0 tw:text-ui-xs tw:font-bold tw:uppercase tw:tracking-wider tw:text-on-surface">{{ __('maintenance.summary.title') }}</h2>
            <p class="tw:m-0 tw:mt-0.5 tw:text-ui-xs tw:text-on-surface-variant">{{ __('maintenance.summary.description') }}</p>
        </header>
        <div class="tw:grid tw:gap-5 tw:p-4 tw:lg:grid-cols-3">
            <div class="tw:grid tw:content-start tw:gap-4 tw:lg:col-span-2">
                @if($globalRecords->isNotEmpty())
                    <x-ui.alert tone="warning" data-maintenance-global-active>
                        <p class="tw:m-0 tw:font-semibold">{{ __('maintenance.summary.global_active') }}</p>
                        <ul class="tw:m-0 tw:mt-1 tw:ps-4">
                            @foreach($globalRecords as $globalRecord)
                                <li><a href="{{ route('admin.maintenance.show', $globalRecord) }}" class="tw:font-semibold tw:text-inherit tw:underline">{{ $globalRecord->title }}</a></li>
                            @endforeach
                        </ul>
                    </x-ui.alert>
                @else
                    <p class="tw:m-0 tw:flex tw:items-center tw:gap-2 tw:text-ui-sm tw:text-on-surface">
                        <x-ui.icon name="circle-check" size="sm" class="tw:shrink-0 tw:text-success" /> {{ __('maintenance.summary.no_global') }}
                    </p>
                @endif

                <div>
                    <h3 class="tw:m-0 tw:text-ui-sm tw:font-semibold tw:text-on-surface">{{ __('maintenance.summary.blocked_modules') }}</h3>
                    @if($blockedModules === [])
                        <p class="tw:m-0 tw:mt-1 tw:text-ui-sm tw:text-on-surface-variant">{{ __('maintenance.summary.no_blocked_modules') }}</p>
                    @else
                        <ul class="tw:m-0 tw:mt-2 tw:grid tw:list-none tw:gap-2 tw:p-0 tw:sm:grid-cols-2" data-maintenance-blocked-modules>
                            @foreach($blockedModules as $moduleKey => $holders)
                                <li class="tw:flex tw:items-start tw:justify-between tw:gap-3 tw:rounded-ui-sm tw:border tw:border-outline-variant tw:px-3 tw:py-2">
                                    <span class="tw:min-w-0">
                                        <a href="{{ route('admin.maintenance.index', ['module' => $moduleKey]) }}" class="tw:font-semibold tw:text-on-surface tw:underline-offset-2 tw:hover:underline">{{ $registry->label($moduleKey) }}</a>
                                        <span class="tw:block tw:text-ui-xs tw:text-on-surface-variant">{{ $registry->scopeLabel((string) $registry->scopeOf($moduleKey)) }}</span>
                                    </span>
                                    <span class="tw:shrink-0 tw:text-ui-xs tw:text-on-surface-variant ui-tabular-nums">{{ trans_choice('maintenance.summary.held_by', $holders, ['count' => $holders]) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>

            <div class="tw:grid tw:content-start tw:gap-2 tw:border-outline-variant tw:lg:border-s tw:lg:ps-5">
                <h3 class="tw:m-0 tw:text-ui-sm tw:font-semibold tw:text-on-surface">{{ __('maintenance.summary.queue') }}</h3>
                <ul class="tw:m-0 tw:grid tw:list-none tw:gap-1 tw:p-0">
                    @foreach($queue as [$queueStatus, $queueLabel, $queueIcon])
                        <li>
                            <a href="{{ route('admin.maintenance.index', ['status' => $queueStatus]) }}" class="ui-focus-ring ui-motion tw:flex tw:min-h-10 tw:items-center tw:justify-between tw:gap-3 tw:rounded-ui-sm tw:px-2 tw:text-ui-sm tw:text-on-surface tw:no-underline tw:hover:bg-surface-container">
                                <span class="tw:flex tw:items-center tw:gap-2"><x-ui.icon :name="$queueIcon" size="sm" class="tw:text-on-surface-variant" /> {{ __($queueLabel) }}</span>
                                <span @class(['tw:font-semibold ui-tabular-nums', 'tw:text-error' => $queueStatus === MaintenanceRecord::DISPLAY_OVERDUE && $counts[$queueStatus] > 0])>{{ $counts[$queueStatus] }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </section>

    @php
        $maintenanceResetUrl = route('admin.maintenance.index');
        $maintenanceRemoveUrl = static function (array $dropKeys) use ($maintenanceResetUrl): string {
            $query = request()->query();
            foreach ($dropKeys as $key) {
                unset($query[$key]);
            }

            return $query === [] ? $maintenanceResetUrl : $maintenanceResetUrl.'?'.http_build_query($query);
        };

        $maintenanceChips = [];
        if (! empty($filters['q'])) {
            $maintenanceChips[] = [
                'key' => 'q',
                'label' => __('maintenance.filters.search'),
                'value' => (string) $filters['q'],
                'removeUrl' => $maintenanceRemoveUrl(['q']),
            ];
        }
        if (! empty($filters['type'])) {
            $maintenanceChips[] = [
                'key' => 'type',
                'label' => __('maintenance.filters.type'),
                'value' => __('maintenance.types.'.$filters['type']),
                'removeUrl' => $maintenanceRemoveUrl(['type']),
            ];
        }
        if (! empty($filters['status'])) {
            $maintenanceChips[] = [
                'key' => 'status',
                'label' => __('maintenance.filters.status'),
                'value' => StatusHelper::maintenanceLabel($filters['status']),
                'removeUrl' => $maintenanceRemoveUrl(['status']),
            ];
        }
        if (! empty($filters['module'])) {
            $maintenanceChips[] = [
                'key' => 'module',
                'label' => __('maintenance.filters.module'),
                'value' => $registry->label($filters['module']),
                'removeUrl' => $maintenanceRemoveUrl(['module']),
            ];
        }
    @endphp

    <x-ui.filter-bar
        id="maintenanceFilterForm"
        :action="$maintenanceResetUrl"
        :aria-label="__('maintenance.filters.controls')"
        :has-active-filters="$hasFilters || count($maintenanceChips) > 0"
        :reset-url="$maintenanceResetUrl"
        :reset-label="__('maintenance.filters.reset')"
        :submit-label="__('maintenance.filters.apply')"
    >
        <x-slot:search>
            <label for="maintenanceSearch" class="tw:sr-only">{{ __('maintenance.filters.search') }}</label>
            <x-ui.icon name="search" size="sm" class="ui-filter-bar__search-icon" aria-hidden="true" />
            <input
                name="q"
                id="maintenanceSearch"
                type="search"
                value="{{ $filters['q'] ?? '' }}"
                placeholder="{{ __('maintenance.filters.search_placeholder') }}"
                aria-label="{{ __('maintenance.filters.search') }}"
                maxlength="150"
                autocomplete="off"
                class="form-control form-control-sm ui-filter-bar__search-input"
            />
        </x-slot:search>
        <x-slot:filters>
            <label for="maintenanceTypeFilter" class="tw:sr-only">{{ __('maintenance.filters.type') }}</label>
            <select name="type" id="maintenanceTypeFilter" class="form-select form-select-sm tw:w-full tw:min-w-0 tw:sm:w-44" aria-label="{{ __('maintenance.filters.type') }}">
                <option value="">{{ __('maintenance.filters.all_types') }}</option>
                @foreach(MaintenanceRecord::TYPES as $type)
                    <option value="{{ $type }}" @selected(($filters['type'] ?? '') === $type)>{{ __('maintenance.types.'.$type) }}</option>
                @endforeach
            </select>

            <label for="maintenanceStatusFilter" class="tw:sr-only">{{ __('maintenance.filters.status') }}</label>
            <select name="status" id="maintenanceStatusFilter" class="form-select form-select-sm tw:w-full tw:min-w-0 tw:sm:w-44" aria-label="{{ __('maintenance.filters.status') }}">
                <option value="">{{ __('maintenance.filters.all_statuses') }}</option>
                @foreach(MaintenanceRecord::DISPLAY_STATUSES as $status)
                    <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ StatusHelper::maintenanceLabel($status) }}</option>
                @endforeach
            </select>

            <label for="maintenanceModuleFilter" class="tw:sr-only">{{ __('maintenance.filters.module') }}</label>
            <select name="module" id="maintenanceModuleFilter" class="form-select form-select-sm tw:w-full tw:min-w-0 tw:sm:w-52" aria-label="{{ __('maintenance.filters.module') }}">
                <option value="">{{ __('maintenance.filters.all_modules') }}</option>
                @foreach($registry->grouped() as $scope => $moduleKeys)
                    <optgroup label="{{ $registry->scopeLabel($scope) }}">
                        @foreach($moduleKeys as $moduleKey)
                            <option value="{{ $moduleKey }}" @selected(($filters['module'] ?? '') === $moduleKey)>{{ $registry->label($moduleKey) }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
        </x-slot:filters>
        <x-slot:chips>
            <x-ui.filter-chips :items="$maintenanceChips" :clear-url="$maintenanceResetUrl" />
        </x-slot:chips>
    </x-ui.filter-bar>

    <x-ui.data-table :title="__('maintenance.table.title')" :description="trans_choice('maintenance.table.count', $records->total(), ['count' => $records->total()])">
        <div class="ui-data-table__scroll tw:overflow-x-auto">
            <table class="table table-hover align-middle tw:m-0 tw:w-full tw:text-ui-sm" data-maintenance-table>
                <thead class="table-light">
                    <tr>
                        <th scope="col">{{ __('maintenance.table.record') }}</th>
                        <th scope="col">{{ __('maintenance.table.coverage') }}</th>
                        <th scope="col">{{ __('maintenance.table.window') }}</th>
                        <th scope="col">{{ __('maintenance.table.status') }}</th>
                        <th scope="col" class="text-end">{{ __('maintenance.table.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($records as $maintenanceRecord)
                        @php $displayStatus = $maintenanceRecord->displayStatus($now); @endphp
                        <tr data-maintenance-row="{{ $maintenanceRecord->hash }}">
                            <td class="tw:min-w-56">
                                <a href="{{ route('admin.maintenance.show', $maintenanceRecord) }}" class="tw:font-semibold tw:text-on-surface tw:underline-offset-2 tw:hover:underline">{{ $maintenanceRecord->title }}</a>
                                <div class="tw:mt-0.5 tw:text-ui-xs tw:text-on-surface-variant">
                                    {{ __('maintenance.types.'.$maintenanceRecord->type) }}
                                    @if($maintenanceRecord->creator) · {{ __('maintenance.table.created_by', ['name' => $maintenanceRecord->creator->name]) }} @endif
                                </div>
                            </td>
                            <td class="tw:min-w-48">
                                @if($maintenanceRecord->isGlobal())
                                    <span class="tw:font-semibold">{{ __('maintenance.table.all_portal_modules') }}</span>
                                @else
                                    <ul class="tw:m-0 tw:list-none tw:p-0">
                                        @foreach($maintenanceRecord->modules as $moduleRow)
                                            <li>
                                                {{ $registry->label($moduleRow->module_key) }}
                                                @if($moduleRow->deactivated_at)<span class="tw:text-ui-xs tw:text-on-surface-variant">({{ __('maintenance.table.released') }})</span>@endif
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </td>
                            <td class="tw:whitespace-nowrap ui-tabular-nums">
                                <div><span class="tw:text-ui-xs tw:text-on-surface-variant">{{ __('maintenance.fields.start') }}</span> {{ $maintenanceRecord->starts_at ? BusinessTime::format($maintenanceRecord->starts_at) : __('maintenance.table.not_set') }}</div>
                                <div><span class="tw:text-ui-xs tw:text-on-surface-variant">{{ __('maintenance.fields.estimated_end') }}</span> {{ $maintenanceRecord->estimated_end_at ? BusinessTime::format($maintenanceRecord->estimated_end_at) : __('maintenance.table.not_set') }}</div>
                            </td>
                            <td><x-ui.status-chip :tone="StatusHelper::maintenanceTone($displayStatus)">{{ StatusHelper::maintenanceLabel($displayStatus) }}</x-ui.status-chip></td>
                            <td class="text-end text-nowrap">
                                <div class="tw:inline-flex tw:items-center tw:gap-1">
                                    <x-ui.button :href="route('admin.maintenance.show', $maintenanceRecord)" variant="outline" size="sm">{{ __('maintenance.table.view') }}</x-ui.button>
                                    @unless($maintenanceRecord->trashed())
                                        <x-ui.button :href="route('admin.maintenance.edit', $maintenanceRecord)" variant="ghost" size="sm">{{ __('maintenance.actions.edit') }}</x-ui.button>
                                    @endunless
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                @if($hasFilters)
                                    <x-ui.empty-state icon="search-x" :title="__('maintenance.table.no_match_title')" :description="__('maintenance.table.no_match_description')" />
                                @else
                                    <x-ui.empty-state icon="wrench" :title="__('maintenance.table.empty_title')" :description="__('maintenance.table.empty_description')" :action-url="route('admin.maintenance.create')" :action-text="__('maintenance.actions.create')" />
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($records->hasPages())
            <x-slot:pagination>
                {{ $records->links('pagination::bootstrap-5') }}
            </x-slot:pagination>
        @endif
    </x-ui.data-table>
</div>
@endsection
