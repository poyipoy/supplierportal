@extends('layouts.app')
@section('title', __('finance.closure.paid_title', ['audience' => 'Purchasing']))
@section('page-title', __('finance.drp.paid_monitoring'))

@section('content')
<div id="purchasingDrpPaidContainer" class="tw:flex tw:flex-col tw:gap-6 tw:pb-16 tw:w-full tw:min-w-0 tw:max-w-full" data-server-tabs-container>
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
    <div class="tw:grid tw:grid-cols-1 tw:sm:grid-cols-3 tw:gap-4 tw:w-full tw:min-w-0">
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
            :value="'Rp ' . $regionalFormatter->money($metrics['unpaid_amount'], 0)"
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
            :value="'Rp ' . $regionalFormatter->money($metrics['paid_amount'], 0)"
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
    <div class="tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-3 tw:w-full tw:min-w-0">
        <nav class="tw:inline-flex tw:items-center tw:gap-1.5 tw:rounded-ui-md tw:border tw:border-outline tw:bg-surface-container tw:p-1.5 tw:shadow-none" aria-label="{{ __('common.accessibility.drp_tabs') }}">
            <a
                href="{{ route('purchasing.drp.paid.index', array_merge(request()->query(), ['tab' => 'unpaid'])) }}"
                class="ui-focus-ring ui-motion tw:inline-flex tw:items-center tw:gap-2 tw:rounded-ui-sm tw:px-3.5 tw:py-1.5 tw:text-ui-xs tw:font-semibold tw:no-underline {{ $tab === 'unpaid' ? 'tw:bg-primary tw:text-primary-foreground tw-shadow-xs' : 'tw:text-on-surface-variant tw:hover:bg-surface tw:hover:text-on-surface' }}"
                data-server-tab
                data-tab-name="unpaid"
            >
                <x-ui.icon name="clock" size="sm" />
                <span>{{ __('finance.drp.unpaid') }}</span>
                <span class="tw:inline-flex tw:items-center tw:justify-center tw:rounded-full tw:px-2 tw:py-0.5 tw:text-[11px] tw:font-bold ui-tabular-nums {{ $tab === 'unpaid' ? 'tw:bg-primary-foreground/20 tw:text-primary-foreground' : 'tw:bg-surface tw:text-on-surface-variant' }}" data-tab-count="unpaid">
                    {{ $metrics['unpaid_count'] }}
                </span>
            </a>
            <a
                href="{{ route('purchasing.drp.paid.index', array_merge(request()->query(), ['tab' => 'paid'])) }}"
                class="ui-focus-ring ui-motion tw:inline-flex tw:items-center tw:gap-2 tw:rounded-ui-sm tw:px-3.5 tw:py-1.5 tw:text-ui-xs tw:font-semibold tw:no-underline {{ $tab === 'paid' ? 'tw:bg-primary tw:text-primary-foreground tw-shadow-xs' : 'tw:text-on-surface-variant tw:hover:bg-surface tw:hover:text-on-surface' }}"
                data-server-tab
                data-tab-name="paid"
            >
                <x-ui.icon name="badge-check" size="sm" />
                <span>{{ __('finance.drp.paid') }}</span>
                <span class="tw:inline-flex tw:items-center tw:justify-center tw:rounded-full tw:px-2 tw:py-0.5 tw:text-[11px] tw:font-bold ui-tabular-nums {{ $tab === 'paid' ? 'tw:bg-primary-foreground/20 tw:text-primary-foreground' : 'tw:bg-surface tw:text-on-surface-variant' }}" data-tab-count="paid">
                    {{ $metrics['paid_count'] }}
                </span>
            </a>
            <a
                href="{{ route('purchasing.drp.paid.index', array_merge(request()->query(), ['tab' => 'all'])) }}"
                class="ui-focus-ring ui-motion tw:inline-flex tw:items-center tw:gap-2 tw:rounded-ui-sm tw:px-3.5 tw:py-1.5 tw:text-ui-xs tw:font-semibold tw:no-underline {{ $tab === 'all' ? 'tw:bg-primary tw:text-primary-foreground tw-shadow-xs' : 'tw:text-on-surface-variant tw:hover:bg-surface tw:hover:text-on-surface' }}"
                data-server-tab
                data-tab-name="all"
            >
                <x-ui.icon name="layers" size="sm" />
                <span>{{ __('finance.drp.all_batches') }}</span>
                <span class="tw:inline-flex tw:items-center tw:justify-center tw:rounded-full tw:px-2 tw:py-0.5 tw:text-[11px] tw:font-bold ui-tabular-nums {{ $tab === 'all' ? 'tw:bg-primary-foreground/20 tw:text-primary-foreground' : 'tw:bg-surface tw:text-on-surface-variant' }}" data-tab-count="all">
                    {{ $metrics['total_batches'] }}
                </span>
            </a>
        </nav>
    </div>

    {{-- Search & Filter Toolbar --}}
    @php
        $drpPaidResetUrl = route('purchasing.drp.paid.index', ['tab' => $tab]);
        $drpPaidRemoveUrl = static function (array $dropKeys) use ($tab): string {
            $query = array_merge(request()->query(), ['tab' => $tab]);
            foreach ($dropKeys as $key) {
                unset($query[$key]);
            }

            return route('purchasing.drp.paid.index', $query);
        };

        $hasDrpOverpaymentFilter = ! empty($overpaymentStatus) && $overpaymentStatus !== 'all';
        $drpPaidAdvancedCount = collect([
            $hasDrpOverpaymentFilter,
            ! empty($dateFrom) || ! empty($dateTo),
        ])->filter()->count();

        $drpPaidChips = [];
        if (! empty($q)) {
            $drpPaidChips[] = [
                'key' => 'q',
                'label' => __('local_invoice.actions.search'),
                'value' => (string) $q,
                'removeUrl' => $drpPaidRemoveUrl(['q']),
            ];
        }
        if (! empty($type)) {
            $drpPaidChips[] = [
                'key' => 'type',
                'label' => __('finance.drp.type'),
                'value' => $type === 'SUPPLIER' ? __('local_invoice.labels.supplier') : __('navigation.general_affairs'),
                'removeUrl' => $drpPaidRemoveUrl(['type']),
            ];
        }
        if ($hasDrpOverpaymentFilter) {
            $drpOpVal = match ($overpaymentStatus) {
                'has_overpayment' => __('local_invoice.labels.overpayment'),
                'open' => __('finance.refund.open_status'),
                'settled' => __('finance.refund.completed_status'),
                default => (string) $overpaymentStatus,
            };
            $drpPaidChips[] = [
                'key' => 'overpayment_status',
                'label' => __('finance.drp_surface.overpayment_status'),
                'value' => $drpOpVal,
                'removeUrl' => $drpPaidRemoveUrl(['overpayment_status']),
            ];
        }
        if (! empty($dateFrom) || ! empty($dateTo)) {
            $drpDateVal = ! empty($dateFrom) && ! empty($dateTo)
                ? $dateFrom.' - '.$dateTo
                : (string) ($dateFrom ?: $dateTo);
            $drpPaidChips[] = [
                'key' => 'date_range',
                'label' => __('local_invoice.labels.date'),
                'value' => $drpDateVal,
                'removeUrl' => $drpPaidRemoveUrl(['date_from', 'date_to']),
            ];
        }
    @endphp

    <x-ui.filter-bar
        id="drpPaidFilterForm"
        :action="route('purchasing.drp.paid.index')"
        :server-tabs="true"
        :aria-label="__('common.accessibility.drp_filters')"
        advanced-id="drpPaidMoreFilters"
        :advanced-count="$drpPaidAdvancedCount"
        :advanced-open="$drpPaidAdvancedCount > 0"
        :has-active-filters="count($drpPaidChips) > 0 || $tab !== 'unpaid'"
        :reset-url="$drpPaidResetUrl"
        :reset-label="__('common.actions.reset')"
        :submit-label="__('common.labels_review.filter')"
    >
        <x-slot:hidden>
            <input type="hidden" name="tab" value="{{ $tab }}">
        </x-slot:hidden>

        <x-slot:search>
            <label for="drp-paid-search" class="tw:sr-only">{{ __('local_invoice.actions.search') }}</label>
            <x-ui.icon name="search" size="sm" class="ui-filter-bar__search-icon" aria-hidden="true" />
            <input
                type="search"
                name="q"
                id="drp-paid-search"
                value="{{ $q }}"
                placeholder="{{ __('finance.drp.filter_placeholder') }}"
                class="form-control form-control-sm ui-filter-bar__search-input"
                maxlength="100"
                autocomplete="off"
            >
        </x-slot:search>

        <x-slot:filters>
            <label for="drp-type" class="tw:sr-only">{{ __('finance.drp.type') }}</label>
            <select id="drp-type" name="type" class="form-select form-select-sm tw:w-full tw:min-w-0 tw:sm:w-40">
                <option value="">{{ __('local_invoice.labels.all_types') }}</option>
                <option value="SUPPLIER" @selected($type === 'SUPPLIER')>{{ __('local_invoice.labels.supplier') }}</option>
                <option value="GA" @selected($type === 'GA')>{{ __('navigation.general_affairs') }}</option>
            </select>
        </x-slot:filters>

        <x-slot:advanced>
            <div>
                <label for="drp-overpayment-status" class="tw:block tw:text-ui-xs tw:font-semibold tw:text-on-surface tw:mb-1.5">{{ __('finance.drp_surface.overpayment_status') }}</label>
                <select id="drp-overpayment-status" name="overpayment_status" class="form-select form-select-sm tw:w-full">
                    <option value="all" @selected(($overpaymentStatus ?? 'all') === 'all')>{{ __('finance.refund.all') }}</option>
                    <option value="has_overpayment" @selected(($overpaymentStatus ?? '') === 'has_overpayment')>{{ __('local_invoice.labels.overpayment') }}</option>
                    <option value="open" @selected(($overpaymentStatus ?? '') === 'open')>{{ __('finance.refund.open_status') }}</option>
                    <option value="settled" @selected(($overpaymentStatus ?? '') === 'settled')>{{ __('finance.refund.completed_status') }}</option>
                </select>
            </div>

            <div>
                <label class="tw:block tw:text-ui-xs tw:font-semibold tw:text-on-surface tw:mb-1.5">{{ __('local_invoice.labels.date') }}</label>
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
        </x-slot:advanced>

        <x-slot:chips>
            <x-ui.filter-chips :items="$drpPaidChips" :clear-url="$drpPaidResetUrl" :server-tabs="true" />
        </x-slot:chips>
    </x-ui.filter-bar>

    {{-- Data Table Section (AJAX-swappable container) --}}
    <div id="purchasingDrpPaidTableContent" class="tw:w-full tw:min-w-0 tw:max-w-full" data-server-tabs-content>
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
