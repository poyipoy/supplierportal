@extends('layouts.app')
@section('title', __('maintenance.page.browser_title'))
@section('page-title', __('maintenance.page.title'))

@php
    use App\Models\MaintenanceRecord;
    use App\Support\BusinessTime;
    use App\Support\StatusHelper;

    $displayStatus = $record->displayStatus($now);
    $isDeleted = $record->trashed();
    $isEffective = ! $isDeleted && $record->isEffective($now);
    $canActivate = ! $isDeleted && ! $isEffective && in_array($record->status, [MaintenanceRecord::STATUS_DRAFT, MaintenanceRecord::STATUS_SCHEDULED], true);
    $notSet = __('maintenance.table.not_set');
    $preview = [[
        'title' => $record->title,
        'description' => $record->description,
        'starts_at' => $record->starts_at ? BusinessTime::format($record->starts_at) : $notSet,
        'estimated_end_at' => $record->estimated_end_at ? BusinessTime::format($record->estimated_end_at) : $notSet,
    ]];
    $lifecycle = [
        ['maintenance.fields.type', __('maintenance.types.'.$record->type)],
        ['maintenance.fields.created', BusinessTime::format($record->created_at)],
        ['maintenance.fields.created_by', $record->creator?->name ?? '—'],
        ['maintenance.fields.last_updated', BusinessTime::format($record->updated_at)],
        ['maintenance.fields.updated_by', $record->updater?->name ?? '—'],
        ['maintenance.fields.completed', $record->completed_at ? BusinessTime::format($record->completed_at) : '—'],
        ['maintenance.fields.deleted', $record->deleted_at ? BusinessTime::format($record->deleted_at) : '—'],
    ];
@endphp

@section('content')
<div class="tw:grid tw:gap-6" data-maintenance-detail="{{ $displayStatus }}">
    <x-ui.page-header :title="$record->title" :eyebrow="__('maintenance.page.detail_eyebrow')" :description="__('maintenance.types.'.$record->type)">
        <x-slot:status>
            <x-ui.status-chip :tone="StatusHelper::maintenanceTone($displayStatus)">{{ StatusHelper::maintenanceLabel($displayStatus) }}</x-ui.status-chip>
        </x-slot:status>
        <x-slot:actions>
            <x-ui.button :href="route('admin.maintenance.index')" variant="ghost" size="sm"><x-ui.icon name="arrow-left" size="sm" /> {{ __('maintenance.page.back') }}</x-ui.button>
            @unless($isDeleted)
                <x-ui.button :href="route('admin.maintenance.edit', $record)" variant="outline" size="sm"><x-ui.icon name="pencil-line" size="sm" /> {{ __('maintenance.actions.edit') }}</x-ui.button>
            @endunless
            @if($canActivate)
                <form method="POST" action="{{ route('admin.maintenance.activate', $record) }}" class="tw:m-0">
                    @csrf
                    <x-ui.button type="submit" size="sm" data-maintenance-confirm
                        :data-confirm-title="__('maintenance.confirm.activate_title')"
                        :data-confirm-text="__('maintenance.confirm.activate_text')"
                        :data-confirm-label="__('maintenance.confirm.activate_confirm')"
                        :data-confirm-cancel="__('maintenance.confirm.cancel')"><x-ui.icon name="play" size="sm" /> {{ __('maintenance.actions.activate_now') }}</x-ui.button>
                </form>
            @endif
            @if($isEffective)
                <form method="POST" action="{{ route('admin.maintenance.deactivate', $record) }}" class="tw:m-0">
                    @csrf
                    <x-ui.button type="submit" size="sm" data-maintenance-confirm
                        :data-confirm-title="__('maintenance.confirm.deactivate_title')"
                        :data-confirm-text="__('maintenance.confirm.deactivate_text')"
                        :data-confirm-label="__('maintenance.confirm.deactivate_confirm')"
                        :data-confirm-cancel="__('maintenance.confirm.cancel')"><x-ui.icon name="circle-stop" size="sm" /> {{ __('maintenance.actions.deactivate') }}</x-ui.button>
                </form>
            @endif
            @unless($isDeleted)
                <form method="POST" action="{{ route('admin.maintenance.destroy', $record) }}" class="tw:m-0">
                    @csrf
                    @method('DELETE')
                    <x-ui.button type="submit" variant="outline" size="sm" class="tw:text-error" data-maintenance-confirm data-confirm-danger="1"
                        :data-confirm-title="__('maintenance.confirm.delete_title')"
                        :data-confirm-text="__('maintenance.confirm.delete_text')"
                        :data-confirm-label="__('maintenance.confirm.delete_confirm')"
                        :data-confirm-cancel="__('maintenance.confirm.cancel')"><x-ui.icon name="trash-2" size="sm" /> {{ __('maintenance.actions.delete') }}</x-ui.button>
                </form>
            @endunless
        </x-slot:actions>
    </x-ui.page-header>

    @if($isDeleted)
        <x-ui.alert tone="info">{{ __('maintenance.detail.deleted_notice', ['time' => BusinessTime::format($record->deleted_at)]) }}</x-ui.alert>
    @elseif($displayStatus === MaintenanceRecord::DISPLAY_OVERDUE)
        <x-ui.alert tone="warning">{{ __('maintenance.detail.overdue_notice') }}</x-ui.alert>
    @elseif($record->status === MaintenanceRecord::STATUS_COMPLETED)
        <x-ui.alert tone="info">{{ __('maintenance.detail.completed_notice') }}</x-ui.alert>
    @endif

    <div class="tw:grid tw:gap-6 tw:lg:grid-cols-3">
        <section aria-labelledby="maintenanceNoticeTitle" class="tw:grid tw:content-start tw:gap-3 tw:lg:col-span-2">
            <h2 id="maintenanceNoticeTitle" class="tw:m-0 tw:text-ui-xs tw:font-bold tw:uppercase tw:tracking-wider tw:text-on-surface">{{ __('maintenance.detail.notice') }}</h2>
            @include('maintenance._notices', ['notices' => $preview, 'headingTag' => 'h3'])
        </section>

        <section aria-labelledby="maintenanceLifecycleTitle" class="tw:grid tw:content-start tw:gap-3">
            <h2 id="maintenanceLifecycleTitle" class="tw:m-0 tw:text-ui-xs tw:font-bold tw:uppercase tw:tracking-wider tw:text-on-surface">{{ __('maintenance.detail.lifecycle') }}</h2>
            <dl class="tw:m-0 tw:grid tw:gap-3 tw:rounded-ui-md tw:border tw:border-outline tw:bg-surface tw:p-4">
                @foreach($lifecycle as [$lifecycleLabel, $lifecycleValue])
                    <div class="tw:flex tw:flex-wrap tw:items-baseline tw:justify-between tw:gap-x-3">
                        <dt class="tw:text-ui-xs tw:font-semibold tw:text-on-surface-variant">{{ __($lifecycleLabel) }}</dt>
                        <dd class="tw:m-0 tw:text-ui-sm tw:text-on-surface ui-tabular-nums">{{ $lifecycleValue }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>
    </div>

    <section aria-labelledby="maintenanceModulesTitle" class="tw:grid tw:gap-3">
        <h2 id="maintenanceModulesTitle" class="tw:m-0 tw:text-ui-xs tw:font-bold tw:uppercase tw:tracking-wider tw:text-on-surface">{{ __('maintenance.detail.modules') }}</h2>
        @if($record->isGlobal())
            <p class="tw:m-0 tw:rounded-ui-md tw:border tw:border-outline tw:bg-surface tw:p-4 tw:text-ui-sm tw:text-on-surface">{{ __('maintenance.detail.global_coverage') }}</p>
        @else
            <x-ui.data-table>
                <div class="ui-data-table__scroll tw:overflow-x-auto">
                    <table class="table align-middle tw:m-0 tw:w-full tw:text-ui-sm" data-maintenance-modules-table>
                        <thead class="table-light">
                            <tr>
                                <th scope="col">{{ __('maintenance.detail.module') }}</th>
                                <th scope="col">{{ __('maintenance.detail.module_status') }}</th>
                                <th scope="col">{{ __('maintenance.detail.other_blockers') }}</th>
                                <th scope="col" class="text-end">{{ __('maintenance.table.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($record->modules as $moduleRow)
                                @php
                                    $blockers = $otherBlockers[$moduleRow->module_key] ?? collect();
                                    $rowBlocking = $isEffective && $moduleRow->deactivated_at === null;
                                @endphp
                                <tr data-maintenance-module="{{ $moduleRow->module_key }}">
                                    <td>
                                        <span class="tw:font-semibold tw:text-on-surface">{{ $registry->label($moduleRow->module_key) }}</span>
                                        <span class="tw:block tw:text-ui-xs tw:text-on-surface-variant">{{ $registry->scopeLabel((string) $registry->scopeOf($moduleRow->module_key)) }}</span>
                                    </td>
                                    <td class="tw:whitespace-nowrap">
                                        @if($rowBlocking)
                                            <x-ui.status-chip tone="warning">{{ __('maintenance.detail.blocking') }}</x-ui.status-chip>
                                        @elseif($moduleRow->deactivated_at)
                                            <span class="ui-tabular-nums">{{ __('maintenance.detail.released_at', ['time' => BusinessTime::format($moduleRow->deactivated_at)]) }}</span>
                                        @elseif(! $isDeleted && in_array($record->status, [MaintenanceRecord::STATUS_DRAFT, MaintenanceRecord::STATUS_SCHEDULED], true))
                                            {{ __('maintenance.detail.planned') }}
                                        @else
                                            {{ __('maintenance.detail.not_blocking') }}
                                        @endif
                                    </td>
                                    <td>
                                        @if($blockers->isEmpty())
                                            <span class="tw:text-on-surface-variant">{{ __('maintenance.detail.none') }}</span>
                                        @else
                                            <ul class="tw:m-0 tw:list-none tw:p-0" data-maintenance-other-blockers>
                                                @foreach($blockers as $blocker)
                                                    <li class="tw:flex tw:items-center tw:gap-1.5">
                                                        <x-ui.icon name="triangle-alert" size="xs" class="tw:shrink-0 tw:text-warning" />
                                                        <a href="{{ route('admin.maintenance.show', $blocker) }}" class="tw:text-on-surface tw:underline-offset-2 tw:hover:underline">{{ $blocker->title }}</a>
                                                        <span class="tw:text-ui-xs tw:text-on-surface-variant">({{ __('maintenance.types.'.$blocker->type) }})</span>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        @if($rowBlocking)
                                            <form method="POST" action="{{ route('admin.maintenance.modules.deactivate', [$record, $moduleRow->module_key]) }}" class="tw:m-0">
                                                @csrf
                                                <x-ui.button type="submit" variant="outline" size="sm" data-maintenance-confirm
                                                    :data-confirm-title="__('maintenance.confirm.module_title', ['module' => $registry->label($moduleRow->module_key)])"
                                                    :data-confirm-text="__('maintenance.confirm.module_text')"
                                                    :data-confirm-label="__('maintenance.confirm.module_confirm')"
                                                    :data-confirm-cancel="__('maintenance.confirm.cancel')">{{ __('maintenance.actions.deactivate_module') }}</x-ui.button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-ui.data-table>
        @endif
    </section>
</div>

@include('admin.maintenance._confirm-script')
@endsection
