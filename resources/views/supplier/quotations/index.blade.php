@extends('layouts.app')

@section('title', 'Quotation Period List - ADASI Portal')
@section('page-title', 'Quotation Periods')

@section('content')
<div class="tw-grid tw-gap-4">
    {{-- Breadcrumb & Compact Page Header --}}
    <x-ui.breadcrumb :items="[
        'Dashboard' => route('supplier.dashboard'),
        'Quotation Periods' => null,
    ]" />

    <x-ui.page-header
        title="Quotation Periods"
        eyebrow="Supplier Portal"
        description="Select an active procurement period to review open requisitions and submit your quotation pricing."
    >
        <x-slot:actions>
            <x-ui.button
                :href="route('supplier.export.quotations')"
                variant="outline"
                size="sm"
                data-async-export
                data-export-source-singular="quotation"
                data-export-source-plural="quotations"
                data-export-row-label="quotation item rows"
                data-export-row-explanation="Each quotation item will be written as a separate Excel row."
                data-export-filtered="false"
            >
                <x-ui.icon name="file-spreadsheet" />
                <span>Export History</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Data Table --}}
    <x-ui.data-table
        title="Procurement Periods"
        description="Counts indicate open requisitions and your supplier quotation submissions per period."
        :empty="$periods->isEmpty()"
    >
        <table class="table table-hover align-middle mb-0 tw-text-ui-xs w-100">
            <thead class="table-light">
                <tr>
                    <th scope="col" class="ps-3">Procurement Period</th>
                    <th scope="col" class="text-center">Status</th>
                    <th scope="col" class="text-center">Awaiting Quotation</th>
                    <th scope="col" class="text-center">Submitted Quotations</th>
                    <th scope="col" class="text-center">Rejected Quotations</th>
                    <th scope="col" class="tw-w-44 text-end pe-3">Action</th>
                </tr>
            </thead>
            <tbody>
                @foreach($periods as $period)
                    <tr>
                        <td class="fw-bold tw-text-on-surface ps-3">{{ $period->display_label }}</td>
                        <td class="text-center">
                            @if($period->status === 'open')
                                <span class="ui-status-chip ui-status-chip--success">
                                    <x-ui.icon name="circle-dot" size="sm" class="me-1" />OPEN
                                </span>
                            @else
                                <span class="ui-status-chip ui-status-chip--neutral">
                                    CLOSED
                                </span>
                            @endif
                        </td>
                        <td class="text-center">
                            @if($period->unresponded_prs > 0)
                                <span class="ui-status-chip ui-status-chip--error ui-tabular-nums">
                                    {{ $period->unresponded_prs }} PR(s)
                                </span>
                            @else
                                <span class="tw-text-outline">-</span>
                            @endif
                        </td>
                        <td class="text-center">
                            @if($period->responded_prs > 0)
                                <span class="ui-status-chip ui-status-chip--info ui-tabular-nums">
                                    {{ $period->responded_prs }} PR(s)
                                </span>
                            @else
                                <span class="tw-text-outline">-</span>
                            @endif
                        </td>
                        <td class="text-center">
                            @if($period->rejected_prs > 0)
                                <span class="ui-status-chip ui-status-chip--neutral ui-tabular-nums">
                                    {{ $period->rejected_prs }} PR(s)
                                </span>
                            @else
                                <span class="tw-text-outline">-</span>
                            @endif
                        </td>
                        <td class="text-end pe-3">
                            <x-ui.button :href="route('supplier.quotations.period', $period->id)" size="sm" variant="secondary">
                                <span>View Requisitions</span>
                                        <x-ui.icon name="arrow-right" size="sm" />
                            </x-ui.button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-ui.data-table>
</div>
@endsection
