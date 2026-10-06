@extends('layouts.app')
@section('title', __('finance.closure.paid_title', ['audience' => 'Purchasing']))
@section('page-title', __('finance.drp.paid_monitoring'))

@section('content')
<div id="purchasingDrpPaidContainer" class="tw-grid tw-gap-6 tw-pb-16" data-server-tabs-container>
    <x-ui.page-header
        :title="__('finance.drp.paid_monitoring')"
        :description="__('finance.drp_surface.paid_monitor_help')"
        :eyebrow="__('finance.drp_surface.purchasing_audience')"
    >
        <x-slot:actions>
            <x-ui.button :href="\App\Support\PurchasingNavigation::listUrl('purchasing.dashboard')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>{{ __('common.labels_review.dashboard') }}</span>
            </x-ui.button>
            <x-ui.button :href="\App\Support\PurchasingNavigation::listUrl('purchasing.drp.supplier')" variant="outline" size="sm">
                <x-ui.icon name="wallet" size="sm" />
                <span>{{ __('navigation.drp_supplier') }}</span>
            </x-ui.button>
            <x-ui.button :href="\App\Support\PurchasingNavigation::listUrl('purchasing.drp.ga')" variant="outline" size="sm">
                <x-ui.icon name="credit-card" size="sm" />
                <span>DRP GA</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- KPI Metric Cards Grid --}}
    <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-4">
        <x-ui.metric-card
            :label="__('finance.labels.total_batches')"
            :value="number_format($metrics['total_batches'])"
            icon="layers"
            tone="neutral"
            :meta="__('finance.drp_ui.all_registered_batches')"
            value-metric="total_batches"
        />

        <x-ui.metric-card
            :label="__('finance.drp.unpaid_pending')"
            :value="'Rp ' . number_format($metrics['unpaid_amount'], 0, ',', '.')"
            icon="clock"
            tone="warning"
            :meta="trans_choice('finance.drp_ui.unpaid_batches', $metrics['unpaid_count'], ['count' => number_format($metrics['unpaid_count'])])"
            :href="route('purchasing.drp.paid.index', array_merge(request()->query(), ['tab' => 'unpaid']))"
            data-server-tab
            data-server-tab-card
            data-tab-name="unpaid"
            :active="$tab === 'unpaid'"
            value-metric="unpaid_amount"
            meta-metric="unpaid_count"
        />

        <x-ui.metric-card
            :label="__('finance.drp.paid_complete')"
            :value="'Rp ' . number_format($metrics['paid_amount'], 0, ',', '.')"
            icon="badge-check"
            tone="success"
            :meta="trans_choice('finance.drp_ui.paid_batches', $metrics['paid_count'], ['count' => number_format($metrics['paid_count'])])"
            :href="route('purchasing.drp.paid.index', array_merge(request()->query(), ['tab' => 'paid']))"
            data-server-tab
            data-server-tab-card
            data-tab-name="paid"
            :active="$tab === 'paid'"
            value-metric="paid_amount"
            meta-metric="paid_count"
        />
    </div>

    {{-- Status Tabs Navigation (Segmented Pill Bar) --}}
    <div class="tw-flex tw-flex-wrap tw-items-center tw-justify-between tw-gap-3">
        <nav class="tw-inline-flex tw-items-center tw-gap-1.5 tw-rounded-ui-md tw-border tw-border-outline tw-bg-surface-container tw-p-1.5 tw-shadow-none" aria-label="{{ __('common.accessibility.drp_tabs') }}">
            <a
                href="{{ route('purchasing.drp.paid.index', array_merge(request()->query(), ['tab' => 'unpaid'])) }}"
                class="ui-focus-ring ui-motion tw-inline-flex tw-items-center tw-gap-2 tw-rounded-ui-sm tw-px-3.5 tw-py-1.5 tw-text-ui-xs tw-font-semibold tw-no-underline {{ $tab === 'unpaid' ? 'tw-bg-primary tw-text-primary-foreground tw-shadow-xs' : 'tw-text-on-surface-variant hover:tw-bg-surface hover:tw-text-on-surface' }}"
                data-server-tab
                data-tab-name="unpaid"
            >
                <x-ui.icon name="clock" size="sm" />
                <span>{{ __('finance.drp.unpaid') }}</span>
                <span class="tw-inline-flex tw-items-center tw-justify-center tw-rounded-full tw-px-2 tw-py-0.5 tw-text-[11px] tw-font-bold {{ $tab === 'unpaid' ? 'tw-bg-primary-foreground/20 tw-text-primary-foreground' : 'tw-bg-surface tw-text-on-surface-variant' }}" data-tab-count="unpaid">
                    {{ $metrics['unpaid_count'] }}
                </span>
            </a>
            <a
                href="{{ route('purchasing.drp.paid.index', array_merge(request()->query(), ['tab' => 'paid'])) }}"
                class="ui-focus-ring ui-motion tw-inline-flex tw-items-center tw-gap-2 tw-rounded-ui-sm tw-px-3.5 tw-py-1.5 tw-text-ui-xs tw-font-semibold tw-no-underline {{ $tab === 'paid' ? 'tw-bg-primary tw-text-primary-foreground tw-shadow-xs' : 'tw-text-on-surface-variant hover:tw-bg-surface hover:tw-text-on-surface' }}"
                data-server-tab
                data-tab-name="paid"
            >
                <x-ui.icon name="badge-check" size="sm" />
                <span>{{ __('finance.drp.paid') }}</span>
                <span class="tw-inline-flex tw-items-center tw-justify-center tw-rounded-full tw-px-2 tw-py-0.5 tw-text-[11px] tw-font-bold {{ $tab === 'paid' ? 'tw-bg-primary-foreground/20 tw-text-primary-foreground' : 'tw-bg-surface tw-text-on-surface-variant' }}" data-tab-count="paid">
                    {{ $metrics['paid_count'] }}
                </span>
            </a>
            <a
                href="{{ route('purchasing.drp.paid.index', array_merge(request()->query(), ['tab' => 'all'])) }}"
                class="ui-focus-ring ui-motion tw-inline-flex tw-items-center tw-gap-2 tw-rounded-ui-sm tw-px-3.5 tw-py-1.5 tw-text-ui-xs tw-font-semibold tw-no-underline {{ $tab === 'all' ? 'tw-bg-primary tw-text-primary-foreground tw-shadow-xs' : 'tw-text-on-surface-variant hover:tw-bg-surface hover:tw-text-on-surface' }}"
                data-server-tab
                data-tab-name="all"
            >
                <x-ui.icon name="layers" size="sm" />
                <span>{{ __('finance.drp.all_batches') }}</span>
                <span class="tw-inline-flex tw-items-center tw-justify-center tw-rounded-full tw-px-2 tw-py-0.5 tw-text-[11px] tw-font-bold {{ $tab === 'all' ? 'tw-bg-primary-foreground/20 tw-text-primary-foreground' : 'tw-bg-surface tw-text-on-surface-variant' }}" data-tab-count="all">
                    {{ $metrics['total_batches'] }}
                </span>
            </a>
        </nav>
    </div>

    {{-- Search & Filter Toolbar --}}
    <form method="GET" action="{{ route('purchasing.drp.paid.index') }}" id="drpPaidFilterForm" class="tw-m-0" data-server-tabs-form>
        <input type="hidden" name="tab" value="{{ $tab }}">

        <x-ui.toolbar aria-label="{{ __('common.accessibility.drp_filters') }}">
            <x-slot:search>
                <div class="tw-relative tw-w-full">
                    <input
                        type="search"
                        name="q"
                        id="drp-paid-search"
                        value="{{ $q }}"
                        placeholder="{{ __('finance.drp.filter_placeholder') }}"
                        class="form-control form-control-sm tw-text-ui-xs"
                        maxlength="100"
                        autocomplete="off"
                    >
                </div>
            </x-slot:search>

            <x-slot:filters>
                <div class="tw-flex tw-flex-wrap tw-items-center tw-gap-2.5">
                    <div class="tw-w-36">
                        <label for="drp-type" class="visually-hidden">{{ __('finance.drp.type') }}</label>
                        <select id="drp-type" name="type" class="form-select form-select-sm tw-text-ui-xs">
                            <option value="">{{ __('local_invoice.labels.all_types') }}</option>
                            <option value="SUPPLIER" @selected($type === 'SUPPLIER')>{{ __('local_invoice.labels.supplier') }}</option>
                            <option value="GA" @selected($type === 'GA')>{{ __('navigation.general_affairs') }}</option>
                        </select>
                    </div>

                    <div class="tw-w-44">
                        <label for="drp-overpayment-status" class="visually-hidden">{{ __('finance.drp_surface.overpayment_status') }}</label>
                        <select id="drp-overpayment-status" name="overpayment_status" class="form-select form-select-sm tw-text-ui-xs">
                            <option value="all" @selected(($overpaymentStatus ?? 'all') === 'all')>{{ __('finance.refund.all') }}</option>
                            <option value="has_overpayment" @selected(($overpaymentStatus ?? '') === 'has_overpayment')>{{ __('local_invoice.labels.overpayment') }}</option>
                            <option value="open" @selected(($overpaymentStatus ?? '') === 'open')>{{ __('finance.refund.open_status') }}</option>
                            <option value="settled" @selected(($overpaymentStatus ?? '') === 'settled')>{{ __('finance.refund.completed_status') }}</option>
                        </select>
                    </div>

                    <div class="tw-w-64">
                        <x-ui.date-range-picker
                            id="paid-date-range"
                            start-name="date_from"
                            end-name="date_to"
                            start-label="{{ __('finance.drp_surface.from_date') }}"
                            end-label="{{ __('finance.drp_surface.to_date') }}"
                            :start-value="$dateFrom"
                            :end-value="$dateTo"
                            :compact="true"
                        />
                    </div>
                </div>
            </x-slot:filters>

            <x-slot:actions>
                <x-ui.button type="submit" size="sm" variant="primary">
                    <x-ui.icon name="filter" size="sm" />
                    <span>{{ __('common.labels_review.filter') }}</span>
                </x-ui.button>
                @if($q || $type || $dateFrom || $dateTo || ($overpaymentStatus && $overpaymentStatus !== 'all') || $tab !== 'unpaid')
                    <x-ui.button :href="route('purchasing.drp.paid.index', ['tab' => $tab])" size="sm" variant="ghost">
                        <x-ui.icon name="rotate-ccw" size="sm" />
                        <span>{{ __('common.actions.reset') }}</span>
                    </x-ui.button>
                @endif
            </x-slot:actions>
        </x-ui.toolbar>
    </form>

    {{-- Data Table Section (AJAX-swappable container) --}}
    <div id="purchasingDrpPaidTableContent" data-server-tabs-content>
        @include('purchasing.drp._paid_table_content', ['batches' => $batches])
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof AdasiServerTabs !== 'undefined') {
        AdasiServerTabs.init('#purchasingDrpPaidContainer');
    }
});
</script>
@endpush
