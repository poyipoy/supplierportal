@extends('layouts.app')
@section('title', 'User Management - ADASI Portal')
@section('page-title', 'User Management')

@section('content')
<div class="tw-grid tw-gap-6">
    <x-ui.page-header title="User Management" description="Manage role assignment, account status, and supplier profile access." eyebrow="Admin" />
    <x-ui.data-table title="User List" description="Search and administer registered portal users.">
        <x-slot:toolbar><x-ui.button :href="route('admin.users.create')" size="sm"><x-ui.icon name="plus" /> Add User</x-ui.button></x-slot:toolbar>
                <table class="table table-hover align-middle w-100 tw-text-ui-sm" id="usersTable">
                    <thead class="table-light">
                        <tr>
                            <th>No</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Registered Since</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
    </x-ui.data-table>
</div>
@endsection

@push('scripts')
<script>
    $(document).ready(function() {
        var table = $('#usersTable').DataTable({
            processing: true,
            serverSide: true,
            ajax: '{{ route("admin.users.index") }}',
            columns: [
                { data: 'DT_RowIndex', name: 'DT_RowIndex', orderable: false, searchable: false, className: 'text-center' },
                { data: 'name_display', name: 'name' },
                { data: 'email', name: 'email' },
                { data: 'role_badge', name: 'role', searchable: false },
                { data: 'status_badge', name: 'is_active', searchable: false },
                { data: 'created_date', name: 'created_at' },
                { data: 'action', name: 'action', orderable: false, searchable: false, className: 'text-end' }
            ],
            language: {},
            pageLength: 25,
            order: []
        });

        // ADASI Alert delete confirmation (delegated for dynamic rows)
        $(document).on('click', '.btn-delete', function() {
            const form = $(this).closest('form');
            AdasiAlert.confirmDanger({
                title: @json('Are you sure you want to delete?'),
                text: @json('This user will be permanently deleted!'),
                confirmText: @json('Yes, delete!'),
                cancelText: @json('Cancel')
            }).then((result) => {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
        });
    });
</script>
@endpush
