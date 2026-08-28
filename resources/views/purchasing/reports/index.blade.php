@extends('layouts.app')
@section('title', 'Report - ADASI Portal')
@section('page-title', 'Reports & Exports')

@section('content')
<div class="tw-grid tw-gap-4">
    {{-- 1. Compact Page Header --}}
    <x-ui.page-header
        title="Reports and Exports"
        eyebrow="Purchasing"
        description="Generate and download filtered Excel reports asynchronously without interrupting ongoing work."
    />

    {{-- 2. Export Cards Grid --}}
    <div class="tw-grid tw-gap-5 lg:tw-grid-cols-2">
        {{-- PR Export Card --}}
        <x-ui.card
            title="Purchase Requisitions Report"
            description="Export comprehensive PR dataset with invited suppliers, items, weights, and status."
            class="tw-h-full"
        >
            <form action="{{ route('purchasing.export.requisitions') }}" method="GET" data-async-export data-export-source-singular="requisition" data-export-source-plural="requisitions" data-export-row-label="material rows" data-export-row-explanation="Each material item will be written as a separate Excel row." class="tw-grid tw-gap-4">
                <div>
                    <label class="form-label small fw-semibold tw-text-on-surface" for="pr-report-period">Procurement Period</label>
                    <select name="period_id" id="pr-report-period" class="form-select form-select-sm">
                        <option value="">All Periods</option>
                        @foreach($periods as $period)
                            <option value="{{ $period->id }}">{{ $period->display_label }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="form-label small fw-semibold tw-text-on-surface" for="pr-report-status">Workflow Status</label>
                    <select name="status" id="pr-report-status" class="form-select form-select-sm">
                        <option value="">All Statuses</option>
                        <option value="draft">Draft</option>
                        <option value="submitted">Submitted</option>
                        <option value="rejected">Rejected</option>
                        <option value="bidding">Bidding</option>
                        <option value="completed">Completed</option>
                    </select>
                </div>

                <div class="pt-2">
                    <x-ui.button type="submit" variant="primary" size="sm" class="tw-w-full">
                        <x-slot:leading><x-ui.icon name="file-spreadsheet" /></x-slot:leading>
                        Generate PR Excel
                    </x-ui.button>
                </div>
            </form>
        </x-ui.card>

        {{-- PO Export Card --}}
        <x-ui.card
            title="Purchase Orders Report"
            description="Export purchase orders dataset by supplier and date range, including delivery and QC state."
            class="tw-h-full"
        >
            <form action="{{ route('purchasing.export.purchase-orders') }}" method="GET" data-async-export data-export-source-singular="purchase order" data-export-source-plural="purchase orders" data-export-row-label="purchase order rows" data-export-row-explanation="Each purchase order will be written as one Excel row." class="tw-grid tw-gap-4">
                <div>
                    <label class="form-label small fw-semibold tw-text-on-surface" for="po-report-supplier">Supplier</label>
                    <select name="supplier_id" id="po-report-supplier" class="form-select form-select-sm">
                        <option value="">All Suppliers</option>
                        @foreach($suppliers as $supplier)
                            <option value="{{ $supplier->getRouteKey() }}">{{ $supplier->name }}</option>
                        @endforeach
                    </select>
                </div>

                <x-ui.date-range-picker
                    id="poReportDateRange"
                    start-name="start_date"
                    start-id="po-report-start-date"
                    start-label="Start Date"
                    end-name="end_date"
                    end-id="po-report-end-date"
                    end-label="End Date"
                />

                <div class="pt-2">
                    <x-ui.button type="submit" variant="primary" size="sm" class="tw-w-full">
                        <x-slot:leading><x-ui.icon name="file-spreadsheet" /></x-slot:leading>
                        Generate PO Excel
                    </x-ui.button>
                </div>
            </form>
        </x-ui.card>
    </div>
</div>
@endsection
