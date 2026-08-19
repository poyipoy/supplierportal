@extends('layouts.app')

@section('title', 'Authentication Audit - ADASI Portal')
@section('page-title', 'Authentication Audit')

@section('content')
<div class="tw-grid tw-gap-6">
    <x-ui.page-header
        title="Authentication Activity"
        description="Review security events retained for {{ config('auth_security.audit.retention_days', 180) }} days."
        eyebrow="Admin Security"
    />

    <x-ui.data-table title="Audit Events" description="Use the filters to narrow the server-side event log.">
        <x-slot:filters>
            <div class="tw-grid tw-w-full tw-gap-3 md:tw-grid-cols-2 xl:tw-grid-cols-5" id="auditFilters">
                <x-ui.select name="audit_user" id="auditUser" label="User" placeholder="All users">
                    @foreach ($users as $user)
                        <option value="{{ $user->getRouteKey() }}">{{ $user->name }} - {{ $user->email }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input name="audit_email" id="auditEmail" type="search" label="Attempted email" maxlength="255" placeholder="Search email" />
                <x-ui.select name="audit_event" id="auditEvent" label="Event" placeholder="All events">
                    @foreach ($events as $event)
                        <option value="{{ $event }}">{{ str($event)->replace('_', ' ')->title() }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input name="audit_date_from" id="auditDateFrom" type="date" label="From" />
                <x-ui.input name="audit_date_to" id="auditDateTo" type="date" label="To" />
            </div>
        </x-slot:filters>

        <table id="authAuditTable" class="table table-hover align-middle w-100 tw-m-0 tw-text-ui-sm">
            <thead class="table-light">
                <tr><th>Date &amp; Time</th><th>Event</th><th>User</th><th>Attempted Email</th><th>IP Address</th><th>User Agent</th><th>Metadata</th></tr>
            </thead>
        </table>
    </x-ui.data-table>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    const table = $('#authAuditTable').DataTable({
        processing: true,
        serverSide: true,
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
            {data: 'created_at', name: 'created_at'},
            {data: 'event', name: 'event'},
            {data: 'user_display', name: 'user.name', orderable: false},
            {data: 'email_attempted', name: 'email_attempted'},
            {data: 'ip_address', name: 'ip_address', orderable: false},
            {data: 'user_agent', name: 'user_agent', orderable: false},
            {data: 'metadata', name: 'metadata', orderable: false, searchable: false}
        ]
    });

    let emailTimer;
    $('#auditFilters select, #auditFilters input[type="date"]').on('change', () => table.ajax.reload());
    $('#auditEmail').on('input', function () {
        clearTimeout(emailTimer);
        emailTimer = setTimeout(() => table.ajax.reload(), 350);
    });
});
</script>
@endpush
