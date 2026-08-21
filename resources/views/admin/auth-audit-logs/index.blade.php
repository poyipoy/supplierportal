@extends('layouts.app')

@section('title', 'Authentication Audit - ADASI Portal')
@section('page-title', 'Authentication Audit')

@section('content')
<div class="tw-grid tw-gap-6">
    <x-ui.page-header title="Authentication Audit" description="Review account and authentication events retained for {{ config('auth_security.audit.retention_days', 180) }} days." eyebrow="Admin Security" />

    <x-ui.toolbar aria-label="Authentication audit filters">
        <x-slot:search>
            <x-ui.input name="audit_email" id="auditEmail" type="search" label="Attempted Email" placeholder="Search attempted email" maxlength="255" autocomplete="off" />
        </x-slot:search>
        <x-slot:filters>
            <x-ui.select name="audit_event" id="auditEvent" label="Event" placeholder="All events" class="tw-min-w-48">
                @foreach ($events as $event)
                    <option value="{{ $event }}">{{ str($event)->replace('_', ' ')->title() }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.button variant="outline" size="sm" class="tw-self-end" type="button" data-bs-toggle="collapse" data-bs-target="#auditMoreFilters" aria-expanded="false" aria-controls="auditMoreFilters">
                <x-ui.icon name="sliders-horizontal" /> More Filters
            </x-ui.button>
        </x-slot:filters>
        <x-slot:actions>
            <x-ui.button type="button" variant="ghost" size="sm" id="resetAuditFilters"><x-ui.icon name="rotate-ccw" /> Reset</x-ui.button>
        </x-slot:actions>
    </x-ui.toolbar>

    <div class="collapse" id="auditMoreFilters">
        <div class="tw-mb-4 tw-border tw-border-outline-variant tw-bg-surface-low tw-p-4">
            <div class="tw-grid tw-gap-3 md:tw-grid-cols-3" id="auditFilters">
                <x-ui.select name="audit_user" id="auditUser" label="Actor" placeholder="All users">
                    @foreach ($users as $user)
                        <option value="{{ $user->getRouteKey() }}">{{ $user->name }} - {{ $user->email }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input name="audit_date_from" id="auditDateFrom" type="date" label="From Date" />
                <x-ui.input name="audit_date_to" id="auditDateTo" type="date" label="To Date" />
            </div>
        </div>
    </div>

    <x-ui.data-table title="Security Event Log" description="Events are shown with actor, time, network context, and retained metadata.">
        <div class="ui-data-table__scroll tw-overflow-x-auto">
            <table id="authAuditTable" class="table table-hover align-middle w-100 tw-m-0 tw-text-ui-sm">
                <thead class="table-light">
                    <tr>
                        <th scope="col">Date &amp; Time</th>
                        <th scope="col">Event</th>
                        <th scope="col">Actor</th>
                        <th scope="col">Attempted Email</th>
                        <th scope="col">IP Address</th>
                        <th scope="col">User Agent</th>
                        <th scope="col">Context</th>
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
        pageLength: 25,
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
            {data: 'event', name: 'event', className: 'text-nowrap fw-medium'},
            {data: 'user_display', name: 'user.name', orderable: false},
            {data: 'email_attempted', name: 'email_attempted'},
            {data: 'ip_address', name: 'ip_address', orderable: false, className: 'font-monospace text-nowrap'},
            {data: 'user_agent', name: 'user_agent', orderable: false, className: 'small text-muted'},
            {data: 'metadata', name: 'metadata', orderable: false, searchable: false, className: 'small font-monospace'}
        ]
    });

    let emailTimer;
    $('#auditUser, #auditEvent, #auditDateFrom, #auditDateTo').on('change', () => table.ajax.reload());
    $('#auditEmail').on('input', function () {
        clearTimeout(emailTimer);
        emailTimer = setTimeout(() => table.ajax.reload(), 350);
    });

    $('#resetAuditFilters').on('click', function () {
        $('#auditUser, #auditEmail, #auditEvent, #auditDateFrom, #auditDateTo').val('');
        table.ajax.reload();
    });
});
</script>
@endpush
