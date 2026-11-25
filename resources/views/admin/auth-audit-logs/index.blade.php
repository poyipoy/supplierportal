@extends('layouts.app')
@section('uses-datatables', true)

@section('title', __('admin.copy.authentication_audit_adasi_portal'))
@section('page-title', __('admin.copy.authentication_audit'))

@section('content')
<div class="tw:grid tw:gap-6">
    <x-ui.page-header :title="__('admin.copy.authentication_audit')" :description="__('admin.page.retention', ['days' => config('auth_security.audit.retention_days', 180)])" :eyebrow="__('admin.copy.admin_security')" />

    <x-ui.filter-bar
        id="authAuditFilterBar"
        mode="live"
        :aria-label="__('admin.copy.authentication_audit_filters')"
        advanced-id="auditMoreFilters"
        :advanced-label="__('admin.copy.more_filters')"
        reset-id="resetAuditFilters"
        :reset-label="__('admin.copy.reset')"
    >
        <x-slot:search>
            <label for="auditEmail" class="tw:sr-only">{{ __('admin.copy.attempted_email') }}</label>
            <x-ui.icon name="search" size="sm" class="ui-filter-bar__search-icon" aria-hidden="true" />
            <input
                name="audit_email"
                id="auditEmail"
                type="search"
                placeholder="{{ __('admin.copy.search_attempted_email') }}"
                aria-label="{{ __('admin.copy.attempted_email') }}"
                maxlength="255"
                autocomplete="off"
                class="ui-input ui-input--sm ui-filter-bar__search-input"
            />
        </x-slot:search>
        <x-slot:filters>
            <label for="auditEvent" class="tw:sr-only">{{ __('admin.copy.event') }}</label>
            <select name="audit_event" id="auditEvent" class="ui-select ui-select--sm tw:w-full tw:min-w-0 tw:sm:w-52" aria-label="{{ __('admin.copy.event') }}">
                <option value="">{{ __('admin.copy.all_events') }}</option>
                @foreach ($events as $event)
                    <option value="{{ $event }}">{{ __('security.audit_events.'.$event) }}</option>
                @endforeach
            </select>
        </x-slot:filters>
        <x-slot:advanced>
            <div>
                <label for="auditUser" class="tw:block tw:text-ui-xs tw:font-semibold tw:text-on-surface tw:mb-1.5">{{ __('admin.copy.actor') }}</label>
                <select name="audit_user" id="auditUser" class="ui-select ui-select--sm tw:w-full">
                    <option value="">{{ __('admin.copy.all_users') }}</option>
                    @foreach ($users as $user)
                        <option value="{{ $user->getRouteKey() }}">{{ $user->name }} - {{ $user->email }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="tw:block tw:text-ui-xs tw:font-semibold tw:text-on-surface tw:mb-1.5">{{ __('admin.copy.date_time') }}</label>
                <x-ui.date-range-picker
                    id="auditDateRange"
                    start-name="audit_date_from"
                    start-id="auditDateFrom"
                    :start-label="__('admin.copy.from_date')"
                    end-name="audit_date_to"
                    end-id="auditDateTo"
                    :end-label="__('admin.copy.to_date')"
                    compact
                />
            </div>
        </x-slot:advanced>
        <x-slot:chips>
            <x-ui.filter-chips id="auditFilterChips" />
        </x-slot:chips>
    </x-ui.filter-bar>

    <x-ui.data-table :title="__('admin.copy.security_event_log')" :description="__('admin.copy.events_are_shown_with_actor_time_network_context_and_retained_metadata')">
        <div class="ui-data-table__scroll tw:overflow-x-auto">
            <table id="authAuditTable" class="ui-table ui-table--hover tw:align-middle tw:w-full tw:m-0 tw:text-ui-sm">
                <thead>
                    <tr>
                        <th scope="col">{{ __('admin.copy.date_time') }}</th>
                        <th scope="col">{{ __('admin.copy.event') }}</th>
                        <th scope="col">{{ __('admin.copy.actor') }}</th>
                        <th scope="col">{{ __('admin.copy.attempted_email') }}</th>
                        <th scope="col">{{ __('admin.copy.ip_address') }}</th>
                        <th scope="col">{{ __('admin.copy.user_agent') }}</th>
                        <th scope="col">{{ __('admin.copy.context') }}</th>
                    </tr>
                </thead>
            </table>
        </div>
    </x-ui.data-table>
</div>
@endsection

@push('scripts')
<script>
(() => {
    const bootAuthAuditLogs = () => {
        const tableEl = document.getElementById('authAuditTable');
        if (!tableEl || typeof window.DataTable !== 'function') return;

        const table = new DataTable(tableEl, {
            processing: true,
            serverSide: true,
            layout: { topStart: null, topEnd: null, bottomStart: 'info', bottomEnd: 'paging' },
            order: [],

            ajax: {
                url: @json(route('admin.auth-audit-logs.data')),
                data: function (data) {
                    data.user_id = document.getElementById('auditUser')?.value ?? '';
                    data.email = document.getElementById('auditEmail')?.value ?? '';
                    data.event = document.getElementById('auditEvent')?.value ?? '';
                    data.date_from = document.getElementById('auditDateFrom')?.value ?? '';
                    data.date_to = document.getElementById('auditDateTo')?.value ?? '';
                }
            },
            columns: [
                {data: 'created_at', name: 'created_at', className: 'tw:whitespace-nowrap'},
                {data: 'event_display', name: 'event', className: 'tw:whitespace-nowrap tw:font-medium'},
                {data: 'user_display', name: 'user.name', orderable: false},
                {data: 'email_attempted', name: 'email_attempted'},
                {data: 'ip_address', name: 'ip_address', orderable: false, className: 'ui-tabular-nums tw:whitespace-nowrap'},
                {data: 'user_agent', name: 'user_agent', orderable: false, className: 'tw:text-ui-xs tw:text-on-surface-variant'},
                {data: 'metadata', name: 'metadata', orderable: false, searchable: false, className: 'tw:text-ui-xs tw:font-mono'}
            ]
        });

        window.AdasiFilterBar.bindLive('#authAuditFilterBar', {
            debounceMs: 350,
            fields: [
                { key: 'email', label: @json(__('admin.copy.attempted_email')), selector: '#auditEmail', type: 'search' },
                { key: 'event', label: @json(__('admin.copy.event')), selector: '#auditEvent', type: 'select' },
                { key: 'user_id', label: @json(__('admin.copy.actor')), selector: '#auditUser', type: 'select', advanced: true },
                { key: 'date_range', label: @json(__('admin.copy.date_time')), type: 'date-range', picker: '#auditDateRange', start: '#auditDateFrom', end: '#auditDateTo', advanced: true },
            ],
            onFilterChange: () => table.ajax.reload(),
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootAuthAuditLogs, { once: true });
    } else {
        bootAuthAuditLogs();
    }
})();
</script>
@endpush
