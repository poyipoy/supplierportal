@extends('layouts.app')
@section('title', 'Local Invoice Dashboard - Accounting')
@section('page-title', 'Local Invoice Dashboard')

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        title="Local Invoice Dashboard"
        description="Monitor invoice submissions, physical document verifications, and payment processing."
        eyebrow="Accounting & Finance"
    >
        <x-slot:actions>
            <x-ui.button :href="route('accounting.invoices.index')" variant="primary">
                <x-ui.icon name="list" size="sm" />
                <span>Invoice Register</span>
            </x-ui.button>
            <x-ui.button :href="route('accounting.payment-schedule')" variant="outline">
                <x-ui.icon name="calendar-clock" size="sm" />
                <span>Payment Schedule</span>
            </x-ui.button>
            <x-ui.button :href="route('accounting.physical-verification')" variant="outline">
                <x-ui.icon name="file-check" size="sm" />
                <span>Physical Verification</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.dashboard-layout audience="accounting">
    <x-slot:statuses>
    {{-- KPI Metric Cards Grid --}}
    @php
        $totalInvoices = $counts->sum();
        $scheduledTotal = ($counts['APPROVED'] ?? 0) + ($counts['PAYMENT_SCHEDULED'] ?? 0);
    @endphp

    <div class="tw-grid tw-grid-cols-2 md:tw-grid-cols-3 lg:tw-grid-cols-5 tw-gap-4">
        <x-ui.metric-card
            label="Total Invoices"
            :value="$regionalFormatter->number((string) ($totalInvoices), 'plain')"
            icon="receipt"
            tone="primary"
            :href="route('accounting.invoices.index')"
        />
        <x-ui.metric-card
            label="Waiting Physical"
            :value="$regionalFormatter->number((string) ($counts['WAITING_PHYSICAL_DOCUMENT'] ?? 0), 'plain')"
            icon="file-check"
            tone="warning"
            :href="route('accounting.physical-verification')"
        />
        <x-ui.metric-card
            label="Need Revision"
            :value="$regionalFormatter->number((string) ($counts['NEED_REVISION'] ?? 0), 'plain')"
            icon="file-edit"
            tone="error"
            :href="route('accounting.invoices.index', ['status' => 'NEED_REVISION'])"
        />
        <x-ui.metric-card
            label="Ready to Pay"
            :value="$regionalFormatter->number((string) ($scheduledTotal), 'plain')"
            icon="calendar-clock"
            tone="success"
            :href="route('accounting.payment-schedule')"
        />
        <x-ui.metric-card
            label="Overdue Invoices"
            :value="$regionalFormatter->number((string) ($overdue), 'plain')"
            icon="alert-triangle"
            tone="error"
            :href="route('accounting.payment-schedule', ['overdue' => 1])"
        />
    </div>

    </x-slot:statuses>
    <x-slot:lifecycle>
    {{-- Secondary Status Funnel / Pipeline Overview --}}
    <x-ui.card>
        <div class="tw-flex tw-flex-wrap tw-items-center tw-justify-between tw-gap-4">
            <div class="tw-min-w-0">
                <span class="tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wider tw-text-on-surface-variant">Lifecycle Breakdown</span>
                <span class="tw-block tw-text-ui-xs tw-text-on-surface-variant tw-mt-0.5">Quick distribution of active invoice statuses across all suppliers.</span>
            </div>
            <div class="tw-flex tw-flex-wrap tw-items-center tw-gap-2.5">
                <span class="tw-inline-flex tw-items-center tw-gap-1.5 tw-px-2.5 tw-py-1 tw-rounded-full tw-text-ui-xs tw-bg-surface-container tw-text-on-surface">
                    <span class="tw-w-2 tw-h-2 tw-rounded-full tw-bg-primary"></span>
                    Submitted: <strong>{{ $regionalFormatter->number((string) ($counts['SUBMITTED'] ?? 0), 'plain') }}</strong>
                </span>
                <span class="tw-inline-flex tw-items-center tw-gap-1.5 tw-px-2.5 tw-py-1 tw-rounded-full tw-text-ui-xs tw-bg-surface-container tw-text-on-surface">
                    <span class="tw-w-2 tw-h-2 tw-rounded-full tw-bg-info"></span>
                    Under Review: <strong>{{ $regionalFormatter->number((string) ($counts['UNDER_REVIEW'] ?? 0), 'plain') }}</strong>
                </span>
                <span class="tw-inline-flex tw-items-center tw-gap-1.5 tw-px-2.5 tw-py-1 tw-rounded-full tw-text-ui-xs tw-bg-surface-container tw-text-on-surface">
                    <span class="tw-w-2 tw-h-2 tw-rounded-full tw-bg-success"></span>
                    Completed: <strong>{{ $regionalFormatter->number((string) ($counts['COMPLETED'] ?? 0), 'plain') }}</strong>
                </span>
                <span class="tw-inline-flex tw-items-center tw-gap-1.5 tw-px-2.5 tw-py-1 tw-rounded-full tw-text-ui-xs tw-bg-surface-container tw-text-on-surface">
                    <span class="tw-w-2 tw-h-2 tw-rounded-full tw-bg-error"></span>
                    Rejected: <strong>{{ $regionalFormatter->number((string) ($counts['REJECTED'] ?? 0), 'plain') }}</strong>
                </span>
            </div>
        </div>
    </x-ui.card>

    </x-slot:lifecycle>
    <x-slot:invoices>
    {{-- Recent Submissions --}}
    <x-ui.data-table
        title="Recent Submissions"
        description="Latest 5 invoice submissions received into the portal."
    >
        <x-slot:toolbar>
            <x-ui.button :href="route('accounting.invoices.index')" variant="ghost" size="sm">
                <span>View All Invoices</span>
                <x-ui.icon name="arrow-right" size="sm" />
            </x-ui.button>
        </x-slot:toolbar>

        @include('local-invoices.table', ['portal' => 'accounting', 'payments' => false])
    </x-ui.data-table>
    </x-slot:invoices>
    </x-ui.dashboard-layout>
</div>
@endsection
