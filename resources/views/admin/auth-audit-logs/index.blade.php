@extends('layouts.app')
@section('uses-datatables', true)

@section('title', __('admin.copy.authentication_audit_adasi_portal'))
@section('page-title', __('admin.copy.authentication_audit'))

@section('content')
<div class="tw-grid tw-gap-6">
    <x-ui.page-header :title="__('admin.copy.authentication_audit')" :description="__('admin.page.retention', ['days' => config('auth_security.audit.retention_days', 180)])" :eyebrow="__('admin.copy.admin_security')" />

    <x-ui.toolbar aria-label="{{ __('admin.copy.authentication_audit_filters') }}">
        <x-slot:search>
            <x-ui.input name="audit_email" id="auditEmail" type="search" :label="__('admin.copy.attempted_email')" :placeholder="__('admin.copy.search_attempted_email')" maxlength="255" autocomplete="off" />
        </x-slot:search>
        <x-slot:filters>
            <x-ui.select name="audit_event" id="auditEvent" :label="__('admin.copy.event')" :placeholder="__('admin.copy.all_events')" class="tw-min-w-48">
                @foreach ($events as $event)
                    <option value="{{ $event }}">{{ __('security.audit_events.'.$event) }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.button variant="outline" size="sm" class="tw-self-end" type="button" data-bs-toggle="collapse" data-bs-target="#auditMoreFilters" aria-expanded="false" aria-controls="auditMoreFilters">
                <x-ui.icon name="sliders-horizontal" /> {{ __('admin.copy.more_filters') }}
            </x-ui.button>
        </x-slot:filters>
        <x-slot:actions>
            <x-ui.button type="button" variant="ghost" size="sm" id="resetAuditFilters"><x-ui.icon name="rotate-ccw" /> {{ __('admin.copy.reset') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.toolbar>

    <div class="collapse" id="auditMoreFilters">
        <div class="tw-mb-4 tw-border tw-border-outline tw-bg-surface-container tw-p-4">
            <div class="tw-grid tw-gap-3 md:tw-grid-cols-3" id="auditFilters">
                <x-ui.select name="audit_user" id="auditUser" :label="__('admin.copy.actor')" :placeholder="__('admin.copy.all_users')">
                    @foreach ($users as $user)
                        <option value="{{ $user->getRouteKey() }}">{{ $user->name }} - {{ $user->email }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.date-range-picker
                    id="auditDateRange"
                    start-name="audit_date_from"
                    start-id="auditDateFrom"
                    :start-label="__('admin.copy.from_date')"
                    end-name="audit_date_to"
                    end-id="auditDateTo"
                    :end-label="__('admin.copy.to_date')"
                />
            </div>
        </div>
    </div>

    <x-ui.data-table :title="__('admin.copy.security_event_log')" :description="__('admin.copy.events_are_shown_with_actor_time_network_context_and_retained_metadata')">
        <div class="ui-data-table__scroll tw-overflow-x-auto">
            <table id="authAuditTable" class="table table-hover align-middle w-100 tw-m-0 tw-text-ui-sm">
                <thead class="table-light">
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
$(function () {
    const table = $('#authAuditTable').DataTable({
        processing: true,
        serverSide: true,
        dom: 'rtip',
        order: [],

        ajax: {
            url: @json(route('admin.auth-audit-logs.data')),
            data: function (data) {
                data.user_id = $('#auditUser').val();
                data.email = $('#auditEmail').val();
                data.event = $('#auditEvent').val();
                data.date_from = $('#auditDateFrom').val();
                data.date_to = $('#auditDateTo').val();
            }
        },
        columns: [
            {data: 'created_at', name: 'created_at', className: 'text-nowrap'},
            {data: 'event_display', name: 'event', className: 'text-nowrap fw-medium'},
            {data: 'user_display', name: 'user.name', orderable: false},
            {data: 'email_attempted', name: 'email_attempted'},
            {data: 'ip_address', name: 'ip_address', orderable: false, className: 'font-monospace text-nowrap'},
            {data: 'user_agent', name: 'user_agent', orderable: false, className: 'small text-muted'},
            {data: 'metadata', name: 'metadata', orderable: false, searchable: false, className: 'small font-monospace'}
        ]
    });

    let emailTimer;
    $('#auditUser, #auditEvent').on('change', () => table.ajax.reload());
    document.getElementById('auditDateRange')?.addEventListener('adasi:date-range-commit', () => table.ajax.reload());
    $('#auditDateFrom, #auditDateTo').on('change', function () {
        if (this.closest('[data-calendar-enhanced="true"]')) return;
        table.ajax.reload();
    });
    $('#auditEmail').on('input', function () {
        clearTimeout(emailTimer);
        emailTimer = setTimeout(() => table.ajax.reload(), 350);
    });

    $('#resetAuditFilters').on('click', function () {
        $('#auditUser, #auditEmail, #auditEvent, #auditDateFrom, #auditDateTo').val('');
        document.getElementById('auditDateRange')?.dispatchEvent(new CustomEvent('adasi:calendar-reset'));
        table.ajax.reload();
    });
});
</script>
@endpush
