@extends('layouts.app')

@section('title', 'Authentication Audit - ADASI Portal')
@section('page-title', 'Authentication Audit')

@section('content')
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3">
            <h6 class="fw-bold mb-1">Authentication Activity</h6>
            <p class="small text-muted mb-0">Security events are retained for {{ config('auth_security.audit.retention_days', 180) }} days.</p>
        </div>
        <div class="card-body">
            <div class="row g-2 mb-3" id="auditFilters">
                <div class="col-lg-3 col-md-6">
                    <label for="auditUser" class="form-label small">User</label>
                    <select id="auditUser" class="form-select form-select-sm">
                        <option value="">All users</option>
                        @foreach ($users as $user)
                            <option value="{{ $user->getRouteKey() }}">{{ $user->name }} — {{ $user->email }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-lg-3 col-md-6">
                    <label for="auditEmail" class="form-label small">Attempted email</label>
                    <input id="auditEmail" type="search" class="form-control form-control-sm" maxlength="255" placeholder="Search email">
                </div>
                <div class="col-lg-2 col-md-4">
                    <label for="auditEvent" class="form-label small">Event</label>
                    <select id="auditEvent" class="form-select form-select-sm">
                        <option value="">All events</option>
                        @foreach ($events as $event)
                            <option value="{{ $event }}">{{ str($event)->replace('_', ' ')->title() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-lg-2 col-md-4">
                    <label for="auditDateFrom" class="form-label small">From</label>
                    <input id="auditDateFrom" type="date" class="form-control form-control-sm">
                </div>
                <div class="col-lg-2 col-md-4">
                    <label for="auditDateTo" class="form-label small">To</label>
                    <input id="auditDateTo" type="date" class="form-control form-control-sm">
                </div>
            </div>

            <div class="table-responsive">
                <table id="authAuditTable" class="table table-hover align-middle w-100" style="font-size:.84rem">
                    <thead class="table-light">
                        <tr>
                            <th>Date &amp; Time</th>
                            <th>Event</th>
                            <th>User</th>
                            <th>Attempted Email</th>
                            <th>IP Address</th>
                            <th>User Agent</th>
                            <th>Metadata</th>
                        </tr>
                    </thead>
                </table>
            </div>
        </div>
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
