@extends('layouts.app')
@section('uses-datatables', true)

@section('title', __('supplier.titles.requisitions', ['period' => $period->display_label]))
@section('page-title', __('supplier.titles.requisitions_page', ['period' => $period->display_label]))

@section('content')
<div class="tw:grid tw:gap-4">
    {{-- Breadcrumb & Compact Page Header --}}
    <x-ui.breadcrumb :items="[
        __('purchasing.breadcrumbs.dashboard') => route('supplier.dashboard'),
        __('purchasing.breadcrumbs.quotation_periods') => route('supplier.quotations.index'),
        $period->display_label => null,
    ]" />

    <x-ui.page-header
        :title="__('supplier.titles.requisitions_page', ['period' => $period->display_label])"
        :eyebrow="__('supplier.copy.supplier_opportunities')"
        :description="__('supplier.copy.review_requisitions_and_manage_quotations_assigned_to_your_supplier_account')"
    >
        <x-slot:actions>
<x-export.advanced-modal export-key="supplier.quotations" :action="route('supplier.export.quotations')" :periods="collect([$period])" :initial-filters="['period_id'=>$period->id]" :filter-selectors="['pr_number'=>'#filter_pr_number','status'=>'#filter_status']" table="#prTable" trigger-id="exportSupplierQuotationsBtn" />
            <x-ui.button :href="route('supplier.quotations.index')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" />
                <span>{{ __('supplier.copy.back_to_periods') }}</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Data Table with Integrated Toolbar --}}
    <x-ui.data-table
        :title="__('supplier.copy.requisition_list')"
        :description="__('supplier.copy.filter_by_pr_number_or_your_quotation_status')"
    >
        <x-slot:filters>
            <x-ui.filter-bar
                id="supplierPeriodFilterBar"
                mode="live"
                variant="embedded"
                reset-id="resetFilter"
                :reset-label="__('supplier.copy.reset_filters')"
            >
                <x-slot:search>
                    <label for="filter_pr_number" class="tw:sr-only">{{ __('supplier.copy.pr_number') }}</label>
                    <x-ui.icon name="search" size="sm" class="ui-filter-bar__search-icon" aria-hidden="true" />
                    <input
                        type="search"
                        id="filter_pr_number"
                        class="form-control form-control-sm ui-filter-bar__search-input"
                        placeholder="{{ __('supplier.copy.e_g_req_05_2026_001') }}"
                    >
                </x-slot:search>

                <x-slot:filters>
                    <label for="filter_status" class="tw:sr-only">{{ __('supplier.copy.my_quotation_status') }}</label>
                    <select id="filter_status" class="form-select form-select-sm tw:w-full tw:min-w-0 tw:sm:w-52">
                        <option value="">{{ __('supplier.copy.all_statuses') }}</option>
                        <option value="unresponded">{{ __('supplier.copy.awaiting_quotation') }}</option>
                        <option value="draft">{{ __('supplier.copy.draft') }}</option>
                        <option value="revision_requested">{{ __('supplier.copy.revision_requested') }}</option>
                        <option value="submitted">{{ __('supplier.copy.submitted') }}</option>
                        <option value="accepted">{{ __('supplier.copy.accepted') }}</option>
                        <option value="rejected">{{ __('supplier.copy.rejected') }}</option>
                    </select>
                </x-slot:filters>

                <x-slot:chips>
                    <x-ui.filter-chips id="filterChips" />
                </x-slot:chips>
            </x-ui.filter-bar>
        </x-slot:filters>

        <table class="table table-hover align-middle mb-0 tw:text-ui-xs w-100" id="prTable">
            <thead class="table-light">
                <tr>
                    <th scope="col" style="width: 40px;">{{ __('supplier.copy.no') }}</th>
                    <th scope="col">{{ __('supplier.copy.pr_number') }}</th>
                    <th scope="col">{{ __('supplier.copy.date_issued') }}</th>
                    <th scope="col" class="text-center">{{ __('supplier.copy.items') }}</th>
                    <th scope="col" class="text-center">{{ __('supplier.copy.my_quotation_status') }}</th>
                    <th scope="col" class="text-end" style="width: 140px;">{{ __('supplier.copy.action') }}</th>
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
                url: '{{ route("supplier.quotations.period", $period->id) }}',
                data: function(d) {
                    d.pr_number = $('#filter_pr_number').val();
                    d.status = $('#filter_status').val();
                }
            },
            columns: [
                { data: 'DT_RowIndex', name: 'DT_RowIndex', orderable: false, searchable: false, className: 'text-center tw:text-on-surface-variant' },
                { data: 'pr_number_display', name: 'pr_number', className: 'fw-bold text-primary' },
                { data: 'updated_date', name: 'updated_at', className: 'ui-tabular-nums tw:text-on-surface-variant' },
                { data: 'item_count', name: 'item_count', orderable: false, searchable: false, className: 'text-center fw-medium ui-tabular-nums' },
                { data: 'status_badge', name: 'status', searchable: false, className: 'text-center' },
                { data: 'action', name: 'action', orderable: false, searchable: false, className: 'text-end' }
            ],
            language: {},

            order: []
        });


        window.AdasiFilterBar.bindLive('#supplierPeriodFilterBar', {
            fields: [
                { key: 'pr_number', label: @json(__('supplier.copy.pr_number')), selector: '#filter_pr_number', type: 'search' },
                { key: 'status', label: @json(__('supplier.copy.my_quotation_status')), selector: '#filter_status', type: 'select' },
            ],
            onFilterChange: () => table.ajax.reload(),
        });
    });
</script>
@endpush
