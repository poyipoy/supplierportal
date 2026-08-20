@extends('layouts.app')
@section('title', 'Report - ADASI Portal')
@section('page-title', 'Report & Export')

@section('content')
<div class="tw-grid tw-gap-6">
    <x-ui.page-header
        title="Reports & exports"
        eyebrow="Purchasing"
        description="Generate filtered Excel reports without leaving or refreshing the current page."
    />

    <div class="tw-grid tw-gap-6 lg:tw-grid-cols-2">
        <x-ui.card
            title="Purchase requisitions"
            description="Export PR records with their periods, workflow status, and material totals."
            class="tw-h-full"
        >
            <form action="{{ route('purchasing.export.requisitions') }}" method="GET" data-async-export class="tw-grid tw-gap-5">
                <x-ui.select name="period_id" label="Period">
                    <option value="">All periods</option>
                    @foreach($periods as $period)
                        <option value="{{ $period->id }}">{{ $period->display_label }}</option>
                    @endforeach
                </x-ui.select>

                <x-ui.select name="status" label="Status">
                    <option value="">All statuses</option>
                    <option value="draft">Draft</option>
                    <option value="submitted">Submitted</option>
                    <option value="rejected">Rejected</option>
                    <option value="bidding">Bidding</option>
                    <option value="completed">Completed</option>
                </x-ui.select>

                <x-ui.button type="submit" variant="secondary" class="tw-w-full">
                    <x-slot:leading><i class="bi bi-file-earmark-excel" aria-hidden="true"></i></x-slot:leading>
                    Download Excel
                </x-ui.button>
            </form>
        </x-ui.card>

        <x-ui.card
            title="Purchase orders"
            description="Export PO records by supplier and date range, including arrival status."
            class="tw-h-full"
        >
            <form action="{{ route('purchasing.export.purchase-orders') }}" method="GET" data-async-export class="tw-grid tw-gap-5">
                <x-ui.select name="supplier_id" label="Supplier">
                    <option value="">All suppliers</option>
                    @foreach($suppliers as $supplier)
                        <option value="{{ $supplier->getRouteKey() }}">{{ $supplier->name }}</option>
                    @endforeach
                </x-ui.select>

                <div class="tw-grid tw-gap-4 sm:tw-grid-cols-2">
                    <x-ui.input name="start_date" type="date" label="Start date" />
                    <x-ui.input name="end_date" type="date" label="End date" />
                </div>

                <x-ui.button type="submit" variant="secondary" class="tw-w-full">
                    <x-slot:leading><i class="bi bi-file-earmark-excel" aria-hidden="true"></i></x-slot:leading>
                    Download Excel
                </x-ui.button>
            </form>
        </x-ui.card>
    </div>
</div>
@endsection
