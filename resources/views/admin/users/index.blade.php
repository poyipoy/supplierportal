@extends('layouts.app')
@section('uses-datatables', true)
@section('title', 'Users - ADASI Portal')
@section('page-title', 'Users')

@section('content')
<div class="tw-grid tw-gap-6">
    <x-ui.page-header
        title="Users"
        description="Manage identity, role access, account status, MFA, and supplier organization records."
        eyebrow="Admin"
    >
        <x-slot:actions>
            <x-ui.button :href="route('admin.users.create')" size="sm">
                <x-ui.icon name="plus" size="sm" />
                Add User
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.toolbar aria-label="User table controls">
        <x-slot:search>
            <x-ui.input name="user_search" id="userSearch" type="search" placeholder="Search name or email" aria-label="Search users" autocomplete="off" />
        </x-slot:search>
        <x-slot:filters>
            <label class="tw-grid tw-gap-1 tw-text-ui-xs tw-font-medium" for="userRoleFilter">Role
                <select id="userRoleFilter" class="form-select form-select-sm tw-min-w-36"><option value="">All roles</option><option value="admin">Admin</option><option value="purchasing">Purchasing</option><option value="supplier">Supplier</option><option value="qc">QC</option></select>
            </label>
            <x-ui.button variant="outline" size="sm" class="tw-self-end" type="button" data-bs-toggle="collapse" data-bs-target="#userMoreFilters" aria-expanded="false" aria-controls="userMoreFilters"><x-ui.icon name="sliders-horizontal" /> More Filters</x-ui.button>
        </x-slot:filters>
        <x-slot:actions><x-ui.button type="button" variant="ghost" size="sm" id="resetUserFilters"><x-ui.icon name="rotate-ccw" /> Reset</x-ui.button></x-slot:actions>
    </x-ui.toolbar>

    <div class="collapse" id="userMoreFilters">
        <div class="tw-mb-4 tw-border tw-border-outline tw-bg-surface-container tw-p-4">
            <label class="tw-grid tw-max-w-xs tw-gap-1 tw-text-ui-xs tw-font-medium" for="userStatusFilter">Account Status
                <select id="userStatusFilter" class="form-select form-select-sm"><option value="">All statuses</option><option value="1">Active</option><option value="0">Inactive</option></select>
            </label>
        </div>
    </div>

    <x-ui.data-table
        title="User Directory"
        description="Edit is the primary row action; destructive actions remain in the overflow menu."
    >
        <div class="ui-data-table__scroll tw-overflow-x-auto">
            <table class="table table-hover align-middle w-100 tw-m-0 tw-text-ui-sm" id="usersTable">
                <thead class="table-light">
                    <tr>
                        <th scope="col" style="width: 50px;">No</th>
                        <th scope="col">Identity</th>
                        <th scope="col">Email</th>
                        <th scope="col">Role</th>
                        <th scope="col">Status</th>
                        <th scope="col">MFA</th>
                        <th scope="col">Registered</th>
                        <th scope="col" class="text-end" style="width: 140px;">Actions</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
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
            dom: 'rtip',
            columns: [
                { data: 'DT_RowIndex', name: 'DT_RowIndex', orderable: false, searchable: false, className: 'text-center' },
                { data: 'name_display', name: 'name' },
                { data: 'email', name: 'email' },
                { data: 'role_badge', name: 'role' },
                { data: 'status_badge', name: 'is_active' },
                { data: 'mfa_badge', name: 'two_factor_confirmed_at', searchable: false, orderable: false },
                { data: 'created_date', name: 'created_at' },
                { data: 'action', name: 'action', orderable: false, searchable: false, className: 'text-end' }
            ],
            pageLength: 25,
            order: []
        });

        let searchTimer;
        $('#userSearch').on('input', function () {
            clearTimeout(searchTimer);
            const value = this.value;
            searchTimer = setTimeout(() => table.search(value).draw(), 250);
        });
        $('#userRoleFilter').on('change', function () { table.column(3).search(this.value).draw(); });
        $('#userStatusFilter').on('change', function () { table.column(4).search(this.value).draw(); });
        $('#resetUserFilters').on('click', function () {
            $('#userSearch, #userRoleFilter, #userStatusFilter').val('');
            table.search('').columns().search('').draw();
        });

        // ADASI Alert delete confirmation (delegated for dynamic rows)
        $(document).on('click', '.btn-delete', function(e) {
            e.preventDefault();
            const form = $(this).closest('form');
            AdasiAlert.confirmDanger({
                title: @json('Delete this user?'),
                text: @json('The account and its directly managed supplier profile will be permanently removed.'),
                confirmText: @json('Delete User'),
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
