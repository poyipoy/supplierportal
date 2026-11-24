@extends('layouts.app')
@section('uses-datatables', true)
@section('title', __('admin.copy.users_adasi_portal'))
@section('page-title', __('admin.copy.users'))

@section('content')
<div class="tw:grid tw:gap-6">
    <x-ui.page-header
        :title="__('admin.copy.users')"
        :description="__('admin.copy.manage_identity_role_access_account_status_mfa_and_supplier_organization_records')"
        :eyebrow="__('admin.copy.admin')">
        
        <x-slot:actions>
            <x-ui.button :href="route('admin.users.create')" size="sm">
                <x-ui.icon name="plus" size="sm" />
                {{ __('admin.copy.add_user') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.filter-bar
        id="usersFilterBar"
        mode="live"
        :aria-label="__('admin.copy.user_table_controls')"
        advanced-id="userMoreFilters"
        :advanced-label="__('admin.copy.more_filters')"
        reset-id="resetUserFilters"
        :reset-label="__('admin.copy.reset')"
    >
        <x-slot:search>
            <label for="userSearch" class="tw:sr-only">{{ __('admin.copy.search_users') }}</label>
            <x-ui.icon name="search" size="sm" class="ui-filter-bar__search-icon" aria-hidden="true" />
            <input
                name="user_search"
                id="userSearch"
                type="search"
                placeholder="{{ __('admin.copy.search_name_or_email') }}"
                aria-label="{{ __('admin.copy.search_users') }}"
                autocomplete="off"
                class="form-control form-control-sm ui-filter-bar__search-input"
            />
        </x-slot:search>
        <x-slot:filters>
            <label for="userRoleFilter" class="tw:sr-only">{{ __('admin.copy.role') }}</label>
            <select id="userRoleFilter" class="form-select form-select-sm tw:w-full tw:min-w-0 tw:sm:w-44">
                <option value="">{{ __('admin.copy.all_roles') }}</option>
                <option value="admin">{{ __('admin.copy.admin') }}</option>
                <option value="purchasing">{{ __('admin.copy.purchasing') }}</option>
                <option value="supplier">{{ __('admin.copy.supplier') }}</option>
                <option value="qc">QC</option>
                <option value="finance">{{ __('admin.copy.finance') }}</option>
                <option value="ga">{{ __('admin.copy.general_affairs') }}</option>
            </select>
        </x-slot:filters>
        <x-slot:advanced>
            <div>
                <label for="userStatusFilter" class="tw:block tw:text-ui-xs tw:font-semibold tw:text-on-surface tw:mb-1.5">{{ __('admin.copy.account_status') }}</label>
                <select id="userStatusFilter" class="form-select form-select-sm tw:w-full">
                    <option value="">{{ __('admin.copy.all_statuses') }}</option>
                    <option value="1">{{ __('admin.copy.active') }}</option>
                    <option value="0">{{ __('admin.copy.inactive') }}</option>
                </select>
            </div>
        </x-slot:advanced>
        <x-slot:chips>
            <x-ui.filter-chips id="usersFilterChips" />
        </x-slot:chips>
    </x-ui.filter-bar>

    <x-ui.data-table
        :title="__('admin.copy.user_directory')"
        :description="__('admin.copy.edit_is_the_primary_row_action_destructive_actions_remain_in_the_overflow_menu')"
    >
        <table class="table table-hover align-middle w-100 tw:m-0 tw:text-ui-sm" id="usersTable">
            <thead class="table-light">
                <tr>
                    <th scope="col" style="width: 50px;">{{ __('admin.copy.no') }}</th>
                    <th scope="col">{{ __('admin.copy.identity') }}</th>
                    <th scope="col">{{ __('admin.copy.email') }}</th>
                    <th scope="col">{{ __('admin.copy.role') }}</th>
                    <th scope="col">{{ __('admin.copy.status') }}</th>
                    <th scope="col">MFA</th>
                    <th scope="col">{{ __('admin.copy.registered') }}</th>
                    <th scope="col" class="tw:text-right text-end" style="width: 140px;">{{ __('common.table.action') }}</th>
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

            order: []
        });

        window.AdasiFilterBar.bindLive('#usersFilterBar', {
            debounceMs: 250,
            fields: [
                { key: 'search', label: @json(__('local_invoice.actions.search')), selector: '#userSearch', type: 'search' },
                { key: 'role', label: @json(__('admin.copy.role')), selector: '#userRoleFilter', type: 'select' },
                { key: 'status', label: @json(__('admin.copy.account_status')), selector: '#userStatusFilter', type: 'select', advanced: true },
            ],
            onFilterChange: () => {
                table
                    .search($('#userSearch').val() || '')
                    .column(3).search($('#userRoleFilter').val() || '')
                    .column(4).search($('#userStatusFilter').val() || '')
                    .draw();
            },
        });

        // ADASI Alert delete confirmation (delegated for dynamic rows)
        $(document).on('click', '.btn-delete', function(e) {
            e.preventDefault();
            const $btn = $(this);
            const form = $btn.closest('form');
            AdasiAlert.confirmDanger({
                title: @json(__('admin.copy.delete_this_user')),
                text: @json(__('admin.copy.the_account_and_its_directly_managed_supplier_profile_will_be_permanently_removed')),
                confirmText: @json(__('admin.copy.delete_user')),
                cancelText: @json(__('admin.copy.cancel'))
            }).then((result) => {
                if (result.isConfirmed) {
                    window.AdasiButton?.startLoading($btn[0]);
                    form.submit();
                }
            });
        });
    });
</script>
@endpush
