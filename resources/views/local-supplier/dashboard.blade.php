@extends('layouts.app')
@section('title', __('local_invoice.dashboard.title'))
@section('page-title', __('local_invoice.dashboard.page_heading'))

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        :title="__('local_invoice.dashboard.heading')"
        :description="__('local_invoice.dashboard.description')"
        :eyebrow="__('local_invoice.dashboard.eyebrow')"
    >
        <x-slot:actions>
            <x-ui.button :href="route('local-supplier.invoices.create')" :disabled="(bool) ($supplierAuditInvoiceBlock ?? null)" :title="($supplierAuditInvoiceBlock ?? null) ? __('supplier_audit.invoice_block.short') : null" variant="primary">
                <x-ui.icon name="plus" size="sm" />
                <span>{{ __('local_invoice.actions.submit') }}</span>
            </x-ui.button>
            <x-ui.button :href="route('local-supplier.invoices.index')" variant="outline">
                <x-ui.icon name="list" size="sm" />
                <span>{{ __('local_invoice.dashboard.all') }}</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @include('local-supplier.supplier-audits._invoice-block-banner')

    <x-ui.dashboard-layout audience="supplier.local">
    <x-slot:statuses>
    {{-- KPI Metric Cards Grid --}}
    @php
        $totalInvoices = $counts->sum();
        $inProgress = ($counts['SUBMITTED'] ?? 0) + ($counts['WAITING_PHYSICAL_DOCUMENT'] ?? 0) + ($counts['UNDER_REVIEW'] ?? 0);
        $readyToPay = ($counts['APPROVED'] ?? 0) + ($counts['PAYMENT_SCHEDULED'] ?? 0);
    @endphp

    <div class="tw-grid tw-grid-cols-2 md:tw-grid-cols-3 lg:tw-grid-cols-5 tw-gap-4">
        <x-ui.metric-card
            :label="__('local_invoice.dashboard.submitted')"
            :value="$regionalFormatter->number((string) ($totalInvoices), 'plain')"
            icon="receipt"
            tone="primary"
            :href="route('local-supplier.invoices.index')"
        />
        <x-ui.metric-card
            :label="__('local_invoice.dashboard.processing')"
            :value="$regionalFormatter->number((string) ($inProgress), 'plain')"
            icon="clock"
            tone="warning"
            :href="route('local-supplier.invoices.index')"
        />
        <x-ui.metric-card
            :label="__('local_invoice.dashboard.revision')"
            :value="$regionalFormatter->number((string) ($counts['NEED_REVISION'] ?? 0), 'plain')"
            icon="alert-circle"
            tone="error"
            :href="route('local-supplier.invoices.index', ['status' => 'NEED_REVISION'])"
        />
        <x-ui.metric-card
            :label="__('local_invoice.labels.ready_to_pay')"
            :value="$regionalFormatter->number((string) ($readyToPay), 'plain')"
            icon="check-circle"
            tone="success"
            :href="route('local-supplier.invoices.index')"
        />
        <x-ui.metric-card
            :label="__('local_invoice.labels.completed')"
            :value="$regionalFormatter->number((string) ($counts['COMPLETED'] ?? 0), 'plain')"
            icon="check"
            tone="primary"
            :href="route('local-supplier.invoices.index', ['status' => 'COMPLETED'])"
        />
    </div>

    </x-slot:statuses>
    <x-slot:company>
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
                    @if(auth()->user()->supplier?->npwp)
                        <span class="tw-text-ui-xs tw-text-on-surface-variant">
                            NPWP: {{ auth()->user()->supplier->npwp }}
                        </span>
                    @endif
                </div>
            </div>
            <div>
                <x-ui.button :href="route('local-supplier.invoices.create')" :disabled="(bool) ($supplierAuditInvoiceBlock ?? null)" :title="($supplierAuditInvoiceBlock ?? null) ? __('supplier_audit.invoice_block.short') : null" variant="outline" size="sm">
                    <x-ui.icon name="upload-cloud" size="sm" />
                    <span>{{ __('local_invoice.actions.upload_new') }}</span>
                </x-ui.button>
            </div>
        </div>
    </x-ui.card>

    </x-slot:company>
    <x-slot:invoices>
    {{-- Recent Invoices --}}
    <x-ui.data-table
        :title="__('finance.labels.invoices_recent')"
        :description="__('finance.labels.invoice_mine_help')"
    >
        <x-slot:toolbar>
            <x-ui.button :href="route('local-supplier.invoices.index')" variant="ghost" size="sm">
                <span>{{ __('local_invoice.dashboard.all') }}</span>
                <x-ui.icon name="arrow-right" size="sm" />
            </x-ui.button>
        </x-slot:toolbar>

        @include('local-invoices.table', ['portal' => 'local-supplier', 'payments' => false])
    </x-ui.data-table>
    </x-slot:invoices>
    </x-ui.dashboard-layout>
</div>
@endsection
