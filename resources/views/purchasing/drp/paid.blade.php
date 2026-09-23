@extends('layouts.app')
@section('title', 'DRP Paid - Purchasing')
@section('page-title', 'DRP Paid (Monitoring Pelunasan)')

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
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
        />

        <x-ui.metric-card
            label="Belum Paid (Pending)"
            :value="'Rp ' . number_format($metrics['unpaid_amount'], 0, ',', '.')"
            icon="clock"
            tone="warning"
            :meta="number_format($metrics['unpaid_count']) . ' Batch belum lunas'"
            :href="route('purchasing.drp.paid.index', array_merge(request()->query(), ['tab' => 'unpaid']))"
        />

        <x-ui.metric-card
            label="Sudah Paid (Lunas)"
            :value="'Rp ' . number_format($metrics['paid_amount'], 0, ',', '.')"
            icon="badge-check"
            tone="success"
            :meta="number_format($metrics['paid_count']) . ' Batch selesai dibayar'"
            :href="route('purchasing.drp.paid.index', array_merge(request()->query(), ['tab' => 'paid']))"
        />
    </div>

    {{-- Status Tabs Navigation (Segmented Pill Bar) --}}
    <div class="tw-flex tw-flex-wrap tw-items-center tw-justify-between tw-gap-3">
        <nav class="tw-inline-flex tw-items-center tw-gap-1.5 tw-rounded-ui-md tw-border tw-border-outline tw-bg-surface-container tw-p-1.5 tw-shadow-none" aria-label="Tab status pelunasan batch DRP">
            <a
                href="{{ route('purchasing.drp.paid.index', array_merge(request()->query(), ['tab' => 'unpaid'])) }}"
                class="ui-focus-ring ui-motion tw-inline-flex tw-items-center tw-gap-2 tw-rounded-ui-sm tw-px-3.5 tw-py-1.5 tw-text-ui-xs tw-font-semibold tw-no-underline {{ $tab === 'unpaid' ? 'tw-bg-primary tw-text-primary-foreground tw-shadow-xs' : 'tw-text-on-surface-variant hover:tw-bg-surface hover:tw-text-on-surface' }}"
            >
                <x-ui.icon name="clock" size="sm" />
                <span>Belum Paid</span>
                <span class="tw-inline-flex tw-items-center tw-justify-center tw-rounded-full tw-px-2 tw-py-0.5 tw-text-[11px] tw-font-bold {{ $tab === 'unpaid' ? 'tw-bg-white/20 tw-text-white' : 'tw-bg-surface tw-text-on-surface-variant' }}">
                    {{ $metrics['unpaid_count'] }}
                </span>
            </a>
            <a
                href="{{ route('purchasing.drp.paid.index', array_merge(request()->query(), ['tab' => 'paid'])) }}"
                class="ui-focus-ring ui-motion tw-inline-flex tw-items-center tw-gap-2 tw-rounded-ui-sm tw-px-3.5 tw-py-1.5 tw-text-ui-xs tw-font-semibold tw-no-underline {{ $tab === 'paid' ? 'tw-bg-primary tw-text-primary-foreground tw-shadow-xs' : 'tw-text-on-surface-variant hover:tw-bg-surface hover:tw-text-on-surface' }}"
            >
                <x-ui.icon name="badge-check" size="sm" />
                <span>Sudah Paid</span>
                <span class="tw-inline-flex tw-items-center tw-justify-center tw-rounded-full tw-px-2 tw-py-0.5 tw-text-[11px] tw-font-bold {{ $tab === 'paid' ? 'tw-bg-white/20 tw-text-white' : 'tw-bg-surface tw-text-on-surface-variant' }}">
                    {{ $metrics['paid_count'] }}
                </span>
            </a>
            <a
                href="{{ route('purchasing.drp.paid.index', array_merge(request()->query(), ['tab' => 'all'])) }}"
                class="ui-focus-ring ui-motion tw-inline-flex tw-items-center tw-gap-2 tw-rounded-ui-sm tw-px-3.5 tw-py-1.5 tw-text-ui-xs tw-font-semibold tw-no-underline {{ $tab === 'all' ? 'tw-bg-primary tw-text-primary-foreground tw-shadow-xs' : 'tw-text-on-surface-variant hover:tw-bg-surface hover:tw-text-on-surface' }}"
            >
                <x-ui.icon name="layers" size="sm" />
                <span>Semua Batch</span>
                <span class="tw-inline-flex tw-items-center tw-justify-center tw-rounded-full tw-px-2 tw-py-0.5 tw-text-[11px] tw-font-bold {{ $tab === 'all' ? 'tw-bg-white/20 tw-text-white' : 'tw-bg-surface tw-text-on-surface-variant' }}">
                    {{ $metrics['total_batches'] }}
                </span>
            </a>
        </nav>
    </div>

    {{-- Search & Filter Toolbar --}}
    <form method="GET" action="{{ route('purchasing.drp.paid.index') }}" id="drpPaidFilterForm" class="tw-m-0">
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

    {{-- Data Table Section --}}
    <x-ui.data-table
        title="Daftar Monitoring & Pelunasan Batch DRP"
        :description="'Menampilkan ' . $batches->total() . ' batch DRP terdaftar.'"
    >
        <div class="table-responsive">
            <table class="table table-hover align-middle tw-m-0 tw-text-ui-sm w-100">
                <thead class="table-light">
                    <tr>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">Batch Number</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">Tipe & Tanggal</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold text-center">Grup / Item</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">Total Tagihan (Rp)</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">Net Bayar (Rp)</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold text-center">Status</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">Info Pembayaran</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($batches as $batch)
                        <tr>
                            <td>
                                <strong class="tw-font-mono tw-text-on-surface">{{ $batch->batch_number }}</strong>
                                @if($batch->notes)
                                    <div class="tw-text-[11px] tw-text-on-surface-variant tw-truncate tw-max-w-xs" title="{{ $batch->notes }}">
                                        {{ $batch->notes }}
                                    </div>
                                @endif
                            </td>
                            <td>
                                <div class="tw-flex tw-items-center tw-gap-1.5">
                                    <span class="tw-inline-flex tw-items-center tw-px-1.5 tw-py-0.5 tw-rounded tw-text-[10px] tw-font-semibold {{ $batch->batch_type === 'SUPPLIER' ? 'tw-bg-primary/10 tw-text-primary' : 'tw-bg-secondary/10 tw-text-secondary' }}">
                                        {{ $batch->batch_type }}
                                    </span>
                                    <span class="tw-text-ui-xs tw-text-on-surface-variant">{{ $batch->created_at?->format('d M Y') }}</span>
                                </div>
                                <div class="tw-text-[11px] tw-text-on-surface-variant tw-mt-0.5">
                                    Oleh: {{ $batch->creator?->name ?? 'System' }}
                                </div>
                            </td>
                            <td class="text-center">
                                <span class="tw-font-semibold">{{ $batch->groups_count }}</span>
                                <span class="tw-text-ui-xs tw-text-on-surface-variant">rekening</span>
                            </td>
                            <td class="text-end tw-font-mono">
                                Rp {{ number_format($batch->total_subtotal, 0, ',', '.') }}
                            </td>
                            <td class="text-end tw-font-mono">
                                <div class="tw-font-bold tw-text-on-surface">
                                    Rp {{ number_format($batch->total_net_amount, 0, ',', '.') }}
                                </div>
                                @if($batch->hasOverpayment())
                                    <div class="tw-text-[11px] tw-text-amber-700 dark:tw-text-amber-400 tw-font-semibold tw-mt-0.5" title="Nominal transfer riil melebihi net DRP">
                                        Transfer: Rp {{ number_format($batch->actual_transferred_amount, 0, ',', '.') }}
                                    </div>
                                @endif
                                @if($batch->status === \App\Models\PaymentBatch::STATUS_PARTIALLY_PAID)
                                    <div class="tw-text-[11px] tw-text-warning-container-foreground tw-font-semibold tw-mt-0.5" title="Sisa nominal yang belum lunas">
                                        Sisa: Rp {{ number_format($batch->remaining_amount, 0, ',', '.') }}
                                    </div>
                                @endif
                            </td>
                            <td class="text-center">
                                <x-ui.status-chip :tone="\App\Support\StatusHelper::localFinanceTone($batch->status)">
                                    {{ $batch->status }}
                                </x-ui.status-chip>
                            </td>
                            <td>
                                @if($batch->status === \App\Models\PaymentBatch::STATUS_PAID)
                                    @php
                                        $sampleGroup = $batch->groups->firstWhere('status', 'PAID');
                                    @endphp
                                    <div class="tw-text-ui-xs tw-text-success tw-font-semibold tw-flex tw-items-center tw-gap-1">
                                        <x-ui.icon name="check-circle" size="sm" />
                                        <span>Lunas: {{ $batch->paid_at?->format('d M Y') ?? '-' }}</span>
                                    </div>
                                    @if($sampleGroup?->transfer_reference)
                                        <div class="tw-text-[11px] tw-font-mono tw-text-on-surface-variant">
                                            Ref: {{ $sampleGroup->transfer_reference }}
                                        </div>
                                    @endif
                                    @if($batch->hasOverpayment())
                                        @if($batch->hasOpenOverpayment())
                                            <span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-semibold tw-bg-amber-100 tw-text-amber-800 dark:tw-bg-amber-950 dark:tw-text-amber-300 tw-mt-1"
                                                  title="Ada kelebihan bayar yang belum direfund">
                                                <x-ui.icon name="alert-circle" size="xs" />
                                                <span>Overpayment: Rp {{ number_format($batch->total_overpayment_amount, 0, ',', '.') }} (Open)</span>
                                            </span>
                                        @else
                                            <span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-semibold tw-bg-emerald-100 tw-text-emerald-800 dark:tw-bg-emerald-950 dark:tw-text-emerald-300 tw-mt-1"
                                                  title="Kelebihan bayar telah diselesaikan">
                                                <x-ui.icon name="check-circle" size="xs" />
                                                <span>Overpayment Selesai (Rp {{ number_format($batch->total_overpayment_amount, 0, ',', '.') }})</span>
                                            </span>
                                        @endif
                                    @endif
                                @elseif($batch->status === \App\Models\PaymentBatch::STATUS_PARTIALLY_PAID)
                                    <div class="tw-flex tw-flex-col tw-gap-0.5">
                                        <span class="tw-text-ui-xs tw-text-primary tw-font-semibold">Sebagian Sudah Dibayar</span>
                                        <div class="tw-text-[11px] tw-font-mono tw-text-on-surface-variant">
                                            <span class="tw-text-success tw-font-medium">Terbayar: Rp {{ number_format($batch->actual_paid_amount, 0, ',', '.') }}</span>
                                            <span class="tw-text-on-surface-variant">·</span>
                                            <span class="tw-text-warning-container-foreground tw-font-bold">Sisa: Rp {{ number_format($batch->remaining_amount, 0, ',', '.') }}</span>
                                        </div>
                                    </div>
                                @elseif($batch->status === \App\Models\PaymentBatch::STATUS_FINALIZED)
                                    <span class="tw-text-ui-xs tw-text-on-surface-variant tw-font-medium">Siap Ditransfer / Dibayar</span>
                                @elseif($batch->status === \App\Models\PaymentBatch::STATUS_CANCELLED)
                                    <span class="tw-text-ui-xs tw-text-error tw-font-medium">Dibatalkan</span>
                                @elseif($batch->status === \App\Models\PaymentBatch::STATUS_DRAFT)
                                    <span class="tw-text-ui-xs tw-text-warning-container-foreground tw-font-medium">Draft (Belum Final)</span>
                                @else
                                    <span class="tw-text-ui-xs tw-text-on-surface-variant tw-font-medium">-</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <x-ui.button :href="route('purchasing.drp.show', $batch)" size="sm" variant="outline">
                                    <x-ui.icon name="eye" size="sm" />
                                    <span>Detail</span>
                                </x-ui.button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center tw-py-8 tw-text-on-surface-variant tw-text-ui-sm">
                                Tidak ada batch DRP yang sesuai dengan filter yang dipilih.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($batches->hasPages())
            <x-slot:pagination>
                {{ $batches->links() }}
            </x-slot:pagination>
        @endif
    </x-ui.data-table>
</div>
@endsection
