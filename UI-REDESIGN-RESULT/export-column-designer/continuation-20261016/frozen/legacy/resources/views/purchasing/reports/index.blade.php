@extends('layouts.app')
@section('title', __('purchasing.copy.report_adasi_portal'))
@section('page-title', __('purchasing.titles.reports', []))

@section('content')
<div class="tw-grid tw-gap-4">
    {{-- 1. Compact Page Header --}}
    <x-ui.page-header
        :title="__('purchasing.copy.reports_and_exports')"
        :eyebrow="__('purchasing.copy.purchasing')"
        :description="__('purchasing.copy.generate_and_download_filtered_excel_reports_asynchronously_without_interrupting_ongoing_work')"
    />

    {{-- 2. Export Cards Grid --}}
    <div class="tw-grid tw-gap-5 lg:tw-grid-cols-2">
        {{-- PR Export Card --}}
        <x-ui.card
            :title="__('purchasing.copy.purchase_requisitions_report')"
            :description="__('purchasing.copy.export_comprehensive_pr_dataset_with_invited_suppliers_items_weights_and_status')"
            class="tw-h-full"
        >
            <form action="{{ route('purchasing.export.requisitions') }}" method="GET" data-async-export data-export-source-singular="{{ __('exports.sources.requisition') }}" data-export-source-plural="{{ __('exports.sources.requisitions') }}" data-export-row-label="{{ __('purchasing.copy.material_rows') }}" data-export-row-explanation="{{ __('purchasing.copy.each_material_item_will_be_written_as_a_separate_excel_row') }}" class="tw-grid tw-gap-4">
                <div>
                    <label class="form-label small fw-semibold tw-text-on-surface" for="pr-report-period">{{ __('purchasing.copy.procurement_period') }}</label>
                    <select name="period_id" id="pr-report-period" class="form-select form-select-sm">
                        <option value="">{{ __('purchasing.copy.all_periods') }}</option>
                        @foreach($periods as $period)
                            <option value="{{ $period->id }}">{{ $period->display_label }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="form-label small fw-semibold tw-text-on-surface" for="pr-report-status">{{ __('purchasing.copy.workflow_status') }}</label>
                    <select name="status" id="pr-report-status" class="form-select form-select-sm">
                        <option value="">{{ __('purchasing.copy.all_statuses') }}</option>
                        <option value="draft">{{ __('purchasing.copy.draft') }}</option>
                        <option value="submitted">{{ __('purchasing.copy.submitted') }}</option>
                        <option value="rejected">{{ __('purchasing.copy.rejected') }}</option>
                        <option value="bidding">{{ __('purchasing.copy.bidding') }}</option>
                        <option value="completed">{{ __('purchasing.copy.completed') }}</option>
                    </select>
                </div>

                <div class="pt-2">
                    <x-ui.button type="submit" variant="primary" size="sm" class="tw-w-full">
                        <x-slot:leading><x-ui.icon name="file-spreadsheet" /></x-slot:leading>
                        {{ __('purchasing.copy.generate_pr_excel') }}
                    </x-ui.button>
                </div>
            </form>
        </x-ui.card>

        {{-- PO Export Card --}}
        <x-ui.card
            :title="__('purchasing.copy.purchase_orders_report')"
            :description="__('purchasing.copy.export_purchase_orders_dataset_by_supplier_and_date_range_including_delivery_and_qc_state')"
            class="tw-h-full"
        >
            <form action="{{ route('purchasing.export.purchase-orders') }}" method="GET" data-async-export data-export-source-singular="{{ __('exports.sources.purchase_order') }}" data-export-source-plural="{{ __('exports.sources.purchase_orders') }}" data-export-row-label="{{ __('purchasing.copy.purchase_order_rows') }}" data-export-row-explanation="{{ __('purchasing.copy.each_purchase_order_will_be_written_as_one_excel_row') }}" class="tw-grid tw-gap-4">
                <div>
                    <label class="form-label small fw-semibold tw-text-on-surface" for="po-report-supplier">{{ __('purchasing.copy.supplier') }}</label>
                    <select name="supplier_id" id="po-report-supplier" class="form-select form-select-sm">
                        <option value="">{{ __('purchasing.copy.all_suppliers_3a7a53') }}</option>
                        @foreach($suppliers as $supplier)
                            <option value="{{ $supplier->getRouteKey() }}">{{ $supplier->name }}</option>
                        @endforeach
                    </select>
                </div>

                <x-ui.date-range-picker
                    id="poReportDateRange"
                    start-name="start_date"
                    start-id="po-report-start-date"
                    :start-label="__('purchasing.copy.start_date')"
                    end-name="end_date"
                    end-id="po-report-end-date"
                    :end-label="__('purchasing.copy.end_date')"
                />

                <div class="pt-2">
                    <x-ui.button type="submit" variant="primary" size="sm" class="tw-w-full">
                        <x-slot:leading><x-ui.icon name="file-spreadsheet" /></x-slot:leading>
                        {{ __('purchasing.copy.generate_po_excel') }}
                    </x-ui.button>
                </div>
            </form>
        </x-ui.card>
    </div>
</div>
@endsection
