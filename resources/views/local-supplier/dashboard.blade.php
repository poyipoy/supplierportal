@extends('layouts.app')
@section('title', 'Supplier Local Dashboard - ADASI Portal')
@section('page-title', 'Local Invoice Dashboard')

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        title="Local Supplier Dashboard"
        description="Submit digital invoices, track physical document verification, and monitor payment schedules."
        eyebrow="Local Billing & Invoicing"
    >
        <x-slot:actions>
            <x-ui.button :href="route('local-supplier.invoices.create')" variant="primary">
                <x-ui.icon name="plus" size="sm" />
                <span>Submit New Invoice</span>
            </x-ui.button>
            <x-ui.button :href="route('local-supplier.invoices.index')" variant="outline">
                <x-ui.icon name="list" size="sm" />
                <span>Track All Invoices</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- KPI Metric Cards Grid --}}
    @php
        $totalInvoices = $counts->sum();
        $inProgress = ($counts['SUBMITTED'] ?? 0) + ($counts['WAITING_PHYSICAL_DOCUMENT'] ?? 0) + ($counts['UNDER_REVIEW'] ?? 0);
        $readyToPay = ($counts['APPROVED'] ?? 0) + ($counts['PAYMENT_SCHEDULED'] ?? 0);
    @endphp

    <div class="tw-grid tw-grid-cols-2 md:tw-grid-cols-3 lg:tw-grid-cols-5 tw-gap-4">
        <x-ui.metric-card
            label="Total Submitted"
            :value="$totalInvoices"
            icon="receipt"
            tone="primary"
            :href="route('local-supplier.invoices.index')"
        />
        <x-ui.metric-card
            label="In Process"
            :value="$inProgress"
            icon="clock"
            tone="warning"
            :href="route('local-supplier.invoices.index')"
        />
        <x-ui.metric-card
            label="Need Revision"
            :value="$counts['NEED_REVISION'] ?? 0"
            icon="alert-circle"
            tone="error"
            :href="route('local-supplier.invoices.index', ['status' => 'NEED_REVISION'])"
        />
        <x-ui.metric-card
            label="Ready to Pay"
            :value="$readyToPay"
            icon="check-circle"
            tone="success"
            :href="route('local-supplier.invoices.index')"
        />
        <x-ui.metric-card
            label="Completed / Paid"
            :value="$counts['COMPLETED'] ?? 0"
            icon="check"
            tone="primary"
            :href="route('local-supplier.invoices.index', ['status' => 'COMPLETED'])"
        />
    </div>

    {{-- Vendor Organization Quick Status --}}
    <x-ui.card>
        <div class="tw-flex tw-flex-col sm:tw-flex-row sm:tw-items-center sm:tw-justify-between tw-gap-4">
            <div class="tw-flex tw-items-center tw-gap-3">
                <div class="tw-w-10 tw-h-10 tw-rounded-full tw-bg-primary/10 tw-text-primary tw-flex tw-items-center tw-justify-center tw-shrink-0">
                    <x-ui.icon name="building-2" size="md" />
                </div>
                <div>
                    <h3 class="tw-m-0 tw-text-ui-sm tw-font-semibold tw-text-on-surface">
                        {{ auth()->user()->supplier?->company_name ?: auth()->user()->name }}
                    </h3>
                    <span class="tw-text-ui-xs tw-text-on-surface-variant">
                        Agreed Terms: <strong>Net {{ auth()->user()->supplier?->payment_term_days ?? 30 }} Days</strong>
                        @if(auth()->user()->supplier?->npwp) · NPWP: {{ auth()->user()->supplier->npwp }} @endif
                    </span>
                </div>
            </div>
            <div>
                <x-ui.button :href="route('local-supplier.invoices.create')" variant="outline" size="sm">
                    <x-ui.icon name="upload-cloud" size="sm" />
                    <span>Upload New Tagihan</span>
                </x-ui.button>
            </div>
        </div>
    </x-ui.card>

    {{-- Recent Invoices --}}
    <x-ui.data-table
        title="Recent Invoices"
        description="Your 5 most recent invoice submissions."
    >
        <x-slot:toolbar>
            <x-ui.button :href="route('local-supplier.invoices.index')" variant="ghost" size="sm">
                <span>View All Invoices</span>
                <x-ui.icon name="arrow-right" size="sm" />
            </x-ui.button>
        </x-slot:toolbar>

        @include('local-invoices.table', ['portal' => 'local-supplier', 'payments' => false])
    </x-ui.data-table>
</div>
@endsection
