@extends('layouts.app')
@section('title', 'DRP Paid - Purchasing')
@section('page-title', 'DRP Paid (Monitoring Pelunasan)')

@section('content')
<div id="purchasingDrpPaidContainer" class="tw-grid tw-gap-6 tw-pb-16" data-server-tabs-container>
    <x-ui.page-header
        title="DRP Paid (Monitoring Pelunasan)"
        description="Pantau status pelunasan seluruh batch DRP Supplier & GA, telusuri rincian transfer, dan riwayat penyelesaian pembayaran."
        eyebrow="Purchasing & Procurement"
    >
        <x-slot:actions>
            <x-ui.button :href="\App\Support\PurchasingNavigation::listUrl('purchasing.dashboard')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>Dashboard</span>
            </x-ui.button>
            <x-ui.button :href="\App\Support\PurchasingNavigation::listUrl('purchasing.drp.supplier')" variant="outline" size="sm">
                <x-ui.icon name="wallet" size="sm" />
                <span>DRP Supplier</span>
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
            label="Total Batch DRP"
            :value="number_format($metrics['total_batches'])"
            icon="layers"
            tone="neutral"
            meta="Seluruh batch terdaftar"
            value-metric="total_batches"
        />

        <x-ui.metric-card
            label="Belum Paid (Pending)"
            :value="'Rp ' . number_format($metrics['unpaid_amount'], 0, ',', '.')"
            icon="clock"
            tone="warning"
            :meta="number_format($metrics['unpaid_count']) . ' Batch belum lunas'"
            :href="route('purchasing.drp.paid.index', array_merge(request()->query(), ['tab' => 'unpaid']))"
            data-server-tab
            data-server-tab-card
            data-tab-name="unpaid"
            :active="$tab === 'unpaid'"
            value-metric="unpaid_amount"
            meta-metric="unpaid_count"
        />

        <x-ui.metric-card
            label="Sudah Paid (Lunas)"
            :value="'Rp ' . number_format($metrics['paid_amount'], 0, ',', '.')"
            icon="badge-check"
            tone="success"
            :meta="number_format($metrics['paid_count']) . ' Batch selesai dibayar'"
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
        <nav class="tw-inline-flex tw-items-center tw-gap-1.5 tw-rounded-ui-md tw-border tw-border-outline tw-bg-surface-container tw-p-1.5 tw-shadow-none" aria-label="Tab status pelunasan batch DRP">
            <a
                href="{{ route('purchasing.drp.paid.index', array_merge(request()->query(), ['tab' => 'unpaid'])) }}"
                class="ui-focus-ring ui-motion tw-inline-flex tw-items-center tw-gap-2 tw-rounded-ui-sm tw-px-3.5 tw-py-1.5 tw-text-ui-xs tw-font-semibold tw-no-underline {{ $tab === 'unpaid' ? 'tw-bg-primary tw-text-primary-foreground tw-shadow-xs' : 'tw-text-on-surface-variant hover:tw-bg-surface hover:tw-text-on-surface' }}"
                data-server-tab
                data-tab-name="unpaid"
            >
                <x-ui.icon name="clock" size="sm" />
                <span>Belum Paid</span>
                <span class="tw-inline-flex tw-items-center tw-justify-center tw-rounded-full tw-px-2 tw-py-0.5 tw-text-[11px] tw-font-bold {{ $tab === 'unpaid' ? 'tw-bg-white/20 tw-text-white' : 'tw-bg-surface tw-text-on-surface-variant' }}" data-tab-count="unpaid">
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
                <span>Sudah Paid</span>
                <span class="tw-inline-flex tw-items-center tw-justify-center tw-rounded-full tw-px-2 tw-py-0.5 tw-text-[11px] tw-font-bold {{ $tab === 'paid' ? 'tw-bg-white/20 tw-text-white' : 'tw-bg-surface tw-text-on-surface-variant' }}" data-tab-count="paid">
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
                <span>Semua Batch</span>
                <span class="tw-inline-flex tw-items-center tw-justify-center tw-rounded-full tw-px-2 tw-py-0.5 tw-text-[11px] tw-font-bold {{ $tab === 'all' ? 'tw-bg-white/20 tw-text-white' : 'tw-bg-surface tw-text-on-surface-variant' }}" data-tab-count="all">
                    {{ $metrics['total_batches'] }}
                </span>
            </a>
        </nav>
    </div>

    {{-- Search & Filter Toolbar --}}
    <form method="GET" action="{{ route('purchasing.drp.paid.index') }}" id="drpPaidFilterForm" class="tw-m-0" data-server-tabs-form>
        <input type="hidden" name="tab" value="{{ $tab }}">

        <x-ui.toolbar aria-label="Kontrol filter batch DRP">
            <x-slot:search>
                <div class="tw-relative tw-w-full">
                    <input
                        type="search"
                        name="q"
                        id="drp-paid-search"
                        value="{{ $q }}"
                        placeholder="Cari nomor batch, supplier, atau karyawan..."
                        class="form-control form-control-sm tw-text-ui-xs"
                        maxlength="100"
                        autocomplete="off"
                    >
                </div>
            </x-slot:search>

            <x-slot:filters>
                <div class="tw-flex tw-flex-wrap tw-items-center tw-gap-2.5">
                    <div class="tw-w-36">
                        <label for="drp-type" class="visually-hidden">Tipe DRP</label>
                        <select id="drp-type" name="type" class="form-select form-select-sm tw-text-ui-xs">
                            <option value="">Semua Tipe</option>
                            <option value="SUPPLIER" @selected($type === 'SUPPLIER')>Supplier</option>
                            <option value="GA" @selected($type === 'GA')>General Affairs</option>
                        </select>
                    </div>

                    <div class="tw-w-44">
                        <label for="drp-overpayment-status" class="visually-hidden">Status Overpayment</label>
                        <select id="drp-overpayment-status" name="overpayment_status" class="form-select form-select-sm tw-text-ui-xs">
                            <option value="all" @selected(($overpaymentStatus ?? 'all') === 'all')>Semua Overpayment</option>
                            <option value="has_overpayment" @selected(($overpaymentStatus ?? '') === 'has_overpayment')>Ada Kelebihan Bayar</option>
                            <option value="open" @selected(($overpaymentStatus ?? '') === 'open')>Perlu Refund (Open)</option>
                            <option value="settled" @selected(($overpaymentStatus ?? '') === 'settled')>Refund Selesai (Settled)</option>
                        </select>
                    </div>

                    <div class="tw-w-64">
                        <x-ui.date-range-picker
                            id="paid-date-range"
                            start-name="date_from"
                            end-name="date_to"
                            start-label="Dari Tanggal"
                            end-label="Sampai Tanggal"
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
                    <span>Filter</span>
                </x-ui.button>
                @if($q || $type || $dateFrom || $dateTo || ($overpaymentStatus && $overpaymentStatus !== 'all') || $tab !== 'unpaid')
                    <x-ui.button :href="route('purchasing.drp.paid.index', ['tab' => $tab])" size="sm" variant="ghost">
                        <x-ui.icon name="rotate-ccw" size="sm" />
                        <span>Reset</span>
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
