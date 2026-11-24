@extends('layouts.app')
@section('uses-datatables', true)

@section('title', __('purchasing.copy.material_requisition_list_adasi_portal'))
@section('page-title', __('purchasing.copy.purchase_requisitions'))

@section('content')
<div class="tw:grid tw:gap-4">
    {{-- 1. Compact Page Header --}}
    <x-ui.page-header
        :title="__('purchasing.copy.purchase_requisition')"
        :eyebrow="__('purchasing.copy.purchasing')"
        :description="__('purchasing.copy.create_filter_and_monitor_material_requisitions_across_active_procurement_periods')"
    >
        <x-slot:actions>
<x-export.advanced-modal export-key="purchasing.pr" :action="route('purchasing.export.requisitions')" :periods="$periods" :filter-selectors="['period_id'=>'#period_id','status'=>'#status']" table="#prTable" trigger-id="exportRequisitionsBtn" />
            <x-ui.button :href="\App\Support\PurchasingNavigation::toRoute('purchasing.requisitions.create')" size="sm">
                <x-ui.icon name="plus-circle" size="sm" />
                <span>{{ __('purchasing.copy.create_requisition') }}</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- 2. Operational Filter Bar --}}
    <x-ui.filter-bar
        id="prFilterBar"
        mode="live"
        :sticky="true"
        reset-id="resetFilter"
        :reset-label="__('purchasing.copy.reset')"
    >
        <x-slot:filters>
            <label for="period_id" class="tw:sr-only">{{ __('purchasing.copy.filter_by_period') }}</label>
            <select name="period_id" id="period_id" class="form-select form-select-sm tw:w-full tw:min-w-0 tw:sm:w-52" aria-label="{{ __('purchasing.copy.filter_by_period') }}">
                <option value="">{{ __('purchasing.copy.all_periods') }}</option>
                @foreach($periods as $period)
                    <option value="{{ $period->id }}">{{ $period->display_label }}</option>
                @endforeach
            </select>

            <label for="status" class="tw:sr-only">{{ __('purchasing.copy.filter_by_status') }}</label>
            <select name="status" id="status" class="form-select form-select-sm tw:w-full tw:min-w-0 tw:sm:w-44" aria-label="{{ __('purchasing.copy.filter_by_status') }}">
                <option value="">{{ __('purchasing.copy.all_statuses') }}</option>
                <option value="draft">{{ __('purchasing.copy.draft') }}</option>
                <option value="submitted">{{ __('purchasing.copy.submitted') }}</option>
                <option value="rejected">{{ __('purchasing.copy.rejected') }}</option>
                <option value="bidding">{{ __('purchasing.copy.bidding') }}</option>
                <option value="completed">{{ __('purchasing.copy.completed') }}</option>
                <option value="cancelled">{{ __('status.pr.cancelled') }}</option>
            </select>
        </x-slot:filters>

        <x-slot:chips>
            <x-ui.filter-chips id="filterChips" />
        </x-slot:chips>
    </x-ui.filter-bar>

    {{-- 3. Balanced Data Table --}}
    <x-ui.data-table density="compact">
        <table class="table table-hover align-middle mb-0 tw:text-ui-sm w-100" id="prTable">
            <thead class="table-light text-center">
                <tr>
                    <th scope="col" style="width: 40px;">{{ __('purchasing.copy.no') }}</th>
                    <th scope="col">{{ __('purchasing.copy.pr_no') }}</th>
                    <th scope="col">{{ __('purchasing.copy.period') }}</th>
                    <th scope="col">{{ __('purchasing.copy.created_by') }}</th>
                    <th scope="col">{{ __('purchasing.copy.suppliers') }}</th>
                    <th scope="col">{{ __('purchasing.copy.items') }}</th>
                    <th scope="col" class="text-end">{{ __('purchasing.copy.total_kg') }}</th>
                    <th scope="col">{{ __('purchasing.copy.status') }}</th>
                    <th scope="col">{{ __('purchasing.copy.date_created') }}</th>
                    <th scope="col">{{ __('common.table.action') }}</th>
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
        var table = $('#prTable').DataTable({
            processing: true,
            serverSide: true,
            ajax: {
                url: '{{ route("purchasing.requisitions.index") }}',
                data: function(d) {
                    d.period_id = $('#period_id').val();
                    d.status = $('#status').val();
                }
            },
            columns: [
                { data: 'DT_RowIndex', name: 'DT_RowIndex', orderable: false, searchable: false, className: 'text-center' },
                { data: 'pr_number_display', name: 'pr_number', className: 'fw-bold tw:text-on-surface' },
                { data: 'period_name', name: 'period.name' },
                { data: 'creator_name', name: 'creator.name' },
                { data: 'supplier_count', name: 'invited_suppliers_count', searchable: false, className: 'text-center' },
                { data: 'item_count', name: 'item_count', searchable: false, className: 'text-center' },
                { data: 'total_kg', name: 'total_kg', searchable: false, className: 'text-end fw-semibold tw:text-on-surface ui-tabular-nums' },
                { data: 'status_badge', name: 'status', searchable: false, className: 'text-center' },
                { data: 'created_date', name: 'created_at', className: 'tw:text-on-surface-variant' },
                { data: 'action', name: 'action', orderable: false, searchable: false, className: 'text-center' }
            ],
            language: {},

            order: []
        });


        window.AdasiFilterBar.bindLive('#prFilterBar', {
            fields: [
                { key: 'period_id', label: @json(__('purchasing.copy.period')), selector: '#period_id', type: 'select' },
                { key: 'status', label: @json(__('purchasing.copy.status')), selector: '#status', type: 'select' },
            ],
            onFilterChange: () => table.ajax.reload(),
        });

        // ADASI Alert delete confirmation
        $(document).on('click', '.btn-delete', function() {
            const $btn = $(this);
            const form = $btn.closest('form');
            AdasiAlert.confirmDanger({
                title: @json(__('purchasing.copy.are_you_sure_you_want_to_delete')),
                text: @json(__('purchasing.copy.this_material_requisition_will_be_permanently_deleted')),
                confirmText: @json(__('purchasing.audit_ui.confirm_delete')),
                cancelText: @json(__('purchasing.copy.cancel'))
            }).then((result) => {
                if (result.isConfirmed) {
                    window.AdasiButton?.startLoading($btn[0]);
                    form.submit();
                }
            });
        });

        let draftSubmitConfirmationOpen = false;
        $(document).on('click', '.btn-submit-draft', function() {
            const $button = $(this);
            if (draftSubmitConfirmationOpen || $button.data('submitting')) {
                return;
            }

            const form = $button.closest('form');
            draftSubmitConfirmationOpen = true;

            AdasiAlert.confirm({
                title: @json(__('purchasing.copy.submit_requisition')),
                text: @json(__('purchasing.copy.status_will_change_to_submitted_and_cannot_be_edited_anymore')),
                confirmText: @json(__('purchasing.copy.yes_submit')),
                cancelText: @json(__('purchasing.copy.cancel'))
            }).then((result) => {
                draftSubmitConfirmationOpen = false;

                if (result.isConfirmed) {
                    window.AdasiButton?.startLoading($button[0]);
                    form.submit();
                }
            });
        });
    });
</script>
@endpush
