@extends('layouts.app')
@section('uses-datatables', true)
@section('title', __('admin.copy.users_adasi_portal'))
@section('page-title', __('admin.copy.users'))

@section('content')
<div class="tw-grid tw-gap-6">
    <x-ui.page-header
        :title="__('admin.copy.users')"
        :description="__('admin.copy.manage_identity_role_access_account_status_mfa_and_supplier_organization_records')"
        :eyebrow="__('admin.copy.admin')"
    >
        <x-slot:actions>
            <x-ui.button :href="route('admin.users.create')" size="sm">
                <x-ui.icon name="plus" size="sm" />
                {{ __('admin.copy.add_user') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.toolbar aria-label="{{ __('admin.copy.user_table_controls') }}">
        <x-slot:search>
            <x-ui.input name="user_search" id="userSearch" type="search" :placeholder="__('admin.copy.search_name_or_email')" aria-label="{{ __('admin.copy.search_users') }}" autocomplete="off" />
        </x-slot:search>
        <x-slot:filters>
            <label class="tw-grid tw-gap-1 tw-text-ui-xs tw-font-medium" for="userRoleFilter">{{ __('admin.copy.role') }}
                <select id="userRoleFilter" class="form-select form-select-sm tw-min-w-36"><option value="">{{ __('admin.copy.all_roles') }}</option><option value="admin">{{ __('admin.copy.admin') }}</option><option value="purchasing">{{ __('admin.copy.purchasing') }}</option><option value="supplier">{{ __('admin.copy.supplier') }}</option><option value="qc">QC</option><option value="finance">{{ __('admin.copy.finance') }}</option><option value="ga">{{ __('admin.copy.general_affairs') }}</option></select>
            </label>
            <x-ui.button variant="outline" size="sm" class="tw-self-end" type="button" data-bs-toggle="collapse" data-bs-target="#userMoreFilters" aria-expanded="false" aria-controls="userMoreFilters"><x-ui.icon name="sliders-horizontal" /> {{ __('admin.copy.more_filters') }}</x-ui.button>
        </x-slot:filters>
        <x-slot:actions><x-ui.button type="button" variant="ghost" size="sm" id="resetUserFilters"><x-ui.icon name="rotate-ccw" /> {{ __('admin.copy.reset') }}</x-ui.button></x-slot:actions>
    </x-ui.toolbar>

    <div class="collapse" id="userMoreFilters">
        <div class="tw-mb-4 tw-border tw-border-outline tw-bg-surface-container tw-p-4">
            <label class="tw-grid tw-max-w-xs tw-gap-1 tw-text-ui-xs tw-font-medium" for="userStatusFilter">{{ __('admin.copy.account_status') }}
                <select id="userStatusFilter" class="form-select form-select-sm"><option value="">{{ __('admin.copy.all_statuses') }}</option><option value="1">{{ __('admin.copy.active') }}</option><option value="0">{{ __('admin.copy.inactive') }}</option></select>
            </label>
        </div>
    </div>

    <x-ui.data-table
        :title="__('admin.copy.user_directory')"
        :description="__('admin.copy.edit_is_the_primary_row_action_destructive_actions_remain_in_the_overflow_menu')"
    >
        <div class="ui-data-table__scroll tw-overflow-x-auto">
            <table class="table table-hover align-middle w-100 tw-m-0 tw-text-ui-sm" id="usersTable">
                <thead class="table-light">
                    <tr>
                        <th scope="col" style="width: 50px;">{{ __('admin.copy.no') }}</th>
                        <th scope="col">{{ __('admin.copy.identity') }}</th>
                        <th scope="col">{{ __('admin.copy.email') }}</th>
                        <th scope="col">{{ __('admin.copy.role') }}</th>
                        <th scope="col">{{ __('admin.copy.status') }}</th>
                        <th scope="col">MFA</th>
                        <th scope="col">{{ __('admin.copy.registered') }}</th>
                        <th scope="col" class="text-end" style="width: 140px;">{{ __('admin.copy.actions') }}</th>
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
