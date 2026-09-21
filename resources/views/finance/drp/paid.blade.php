@extends('layouts.app')
@section('title', 'DRP Paid - Finance AP')
@section('page-title', 'DRP Paid (Monitoring & Eksekusi Pelunasan)')

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        title="DRP Paid (Monitoring & Pelunasan)"
        description="Pantau status pelunasan seluruh batch DRP Supplier & GA, telusuri bukti transfer, dan tandai batch lunas (Mark Paid) secara terpadu."
        eyebrow="Finance & Accounts Payable"
    >
        <x-slot:actions>
            <x-ui.button :href="route('finance.dashboard')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>Dashboard</span>
            </x-ui.button>
            <x-ui.button :href="route('finance.drp.supplier')" variant="outline" size="sm">
                <x-ui.icon name="wallet" size="sm" />
                <span>DRP Supplier</span>
            </x-ui.button>
            <x-ui.button :href="route('finance.drp.ga')" variant="outline" size="sm">
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
            :href="route('finance.drp.paid.index', array_merge(request()->query(), ['tab' => 'unpaid']))"
        />

        <x-ui.metric-card
            label="Sudah Paid (Lunas)"
            :value="'Rp ' . number_format($metrics['paid_amount'], 0, ',', '.')"
            icon="badge-check"
            tone="success"
            :meta="number_format($metrics['paid_count']) . ' Batch selesai dibayar'"
            :href="route('finance.drp.paid.index', array_merge(request()->query(), ['tab' => 'paid']))"
        />
    </div>

    {{-- Status Tabs Navigation (Segmented Pill Bar) --}}
    <div class="tw-flex tw-flex-wrap tw-items-center tw-justify-between tw-gap-3">
        <nav class="tw-inline-flex tw-items-center tw-gap-1.5 tw-rounded-ui-md tw-border tw-border-outline tw-bg-surface-container tw-p-1.5 tw-shadow-none" aria-label="Tab status pelunasan batch DRP">
            <a
                href="{{ route('finance.drp.paid.index', array_merge(request()->query(), ['tab' => 'unpaid'])) }}"
                class="ui-focus-ring ui-motion tw-inline-flex tw-items-center tw-gap-2 tw-rounded-ui-sm tw-px-3.5 tw-py-1.5 tw-text-ui-xs tw-font-semibold tw-no-underline {{ $tab === 'unpaid' ? 'tw-bg-primary tw-text-primary-foreground tw-shadow-xs' : 'tw-text-on-surface-variant hover:tw-bg-surface hover:tw-text-on-surface' }}"
            >
                <x-ui.icon name="clock" size="sm" />
                <span>Belum Paid</span>
                <span class="tw-inline-flex tw-items-center tw-justify-center tw-rounded-full tw-px-2 tw-py-0.5 tw-text-[11px] tw-font-bold {{ $tab === 'unpaid' ? 'tw-bg-white/20 tw-text-white' : 'tw-bg-surface tw-text-on-surface-variant' }}">
                    {{ $metrics['unpaid_count'] }}
                </span>
            </a>
            <a
                href="{{ route('finance.drp.paid.index', array_merge(request()->query(), ['tab' => 'paid'])) }}"
                class="ui-focus-ring ui-motion tw-inline-flex tw-items-center tw-gap-2 tw-rounded-ui-sm tw-px-3.5 tw-py-1.5 tw-text-ui-xs tw-font-semibold tw-no-underline {{ $tab === 'paid' ? 'tw-bg-primary tw-text-primary-foreground tw-shadow-xs' : 'tw-text-on-surface-variant hover:tw-bg-surface hover:tw-text-on-surface' }}"
            >
                <x-ui.icon name="badge-check" size="sm" />
                <span>Sudah Paid</span>
                <span class="tw-inline-flex tw-items-center tw-justify-center tw-rounded-full tw-px-2 tw-py-0.5 tw-text-[11px] tw-font-bold {{ $tab === 'paid' ? 'tw-bg-white/20 tw-text-white' : 'tw-bg-surface tw-text-on-surface-variant' }}">
                    {{ $metrics['paid_count'] }}
                </span>
            </a>
            <a
                href="{{ route('finance.drp.paid.index', array_merge(request()->query(), ['tab' => 'all'])) }}"
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
    <form method="GET" action="{{ route('finance.drp.paid.index') }}" id="drpPaidFilterForm" class="tw-m-0">
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
                @if($q || $type || $dateFrom || $dateTo || $tab !== 'unpaid')
                    <x-ui.button :href="route('finance.drp.paid.index', ['tab' => $tab])" size="sm" variant="ghost">
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
                                <a href="{{ route('finance.drp.show', $batch) }}" class="tw-font-mono tw-font-semibold tw-text-primary hover:tw-text-primary-hover tw-no-underline hover:tw-underline">
                                    {{ $batch->batch_number }}
                                </a>
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
                                @elseif($batch->status === \App\Models\PaymentBatch::STATUS_PARTIALLY_PAID)
                                    <div class="tw-flex tw-flex-col tw-gap-0.5">
                                        <span class="tw-text-ui-xs tw-text-primary tw-font-semibold">Sebagian Sudah Dibayar</span>
                                        <div class="tw-text-[11px] tw-font-mono tw-text-on-surface-variant">
                                            <span class="tw-text-success tw-font-medium">Terbayar: Rp {{ number_format($batch->actual_paid_amount, 0, ',', '.') }}</span>
                                            <span class="tw-text-on-surface-variant">·</span>
                                            <span class="tw-text-warning-container-foreground tw-font-bold">Sisa: Rp {{ number_format($batch->remaining_amount, 0, ',', '.') }}</span>
                                        </div>
                                        @if($batch->hasUnvoucheredSupplierItems())
                                            <span class="tw-text-[11px] tw-text-warning-container-foreground tw-font-semibold tw-flex tw-items-center tw-gap-1 tw-mt-0.5">
                                                <x-ui.icon name="alert-triangle" size="sm" />
                                                <span>Ada voucher belum dibuat</span>
                                            </span>
                                        @endif
                                    </div>
                                @elseif($batch->status === \App\Models\PaymentBatch::STATUS_FINALIZED)
                                    @if($batch->hasUnvoucheredSupplierItems())
                                        <div class="tw-flex tw-flex-col tw-gap-0.5">
                                            <span class="tw-text-ui-xs tw-text-warning-container-foreground tw-font-semibold tw-flex tw-items-center tw-gap-1">
                                                <x-ui.icon name="alert-triangle" size="sm" />
                                                <span>Voucher Belum Lengkap</span>
                                            </span>
                                            <span class="tw-text-[11px] tw-text-on-surface-variant">Generate di Detail DRP</span>
                                        </div>
                                    @else
                                        <span class="tw-text-ui-xs tw-text-on-surface-variant tw-font-medium">Siap Ditransfer / Dibayar</span>
                                    @endif
                                @elseif($batch->status === \App\Models\PaymentBatch::STATUS_CANCELLED)
                                    <span class="tw-text-ui-xs tw-text-error tw-font-medium">Dibatalkan</span>
                                @elseif($batch->status === \App\Models\PaymentBatch::STATUS_DRAFT)
                                    <span class="tw-text-ui-xs tw-text-warning-container-foreground tw-font-medium">Perlu Difinalisasi</span>
                                @else
                                    <span class="tw-text-ui-xs tw-text-on-surface-variant tw-font-medium">-</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <div class="tw-inline-flex tw-items-center tw-gap-1.5">
                                    <x-ui.button :href="route('finance.drp.show', $batch)" size="sm" variant="outline">
                                        <x-ui.icon name="eye" size="sm" />
                                        <span>Detail</span>
                                    </x-ui.button>

                                    @if(in_array($batch->status, [\App\Models\PaymentBatch::STATUS_FINALIZED, \App\Models\PaymentBatch::STATUS_PARTIALLY_PAID]))
                                        @php
                                            $unvoucheredCount = $batch->unvoucheredSupplierItemsCount();
                                        @endphp

                                        @if($unvoucheredCount > 0)
                                            <span class="tw-inline-block" tabindex="0" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Terdapat {{ $unvoucheredCount }} tagihan yang belum diterbitkan Voucher Bayar. Buka Detail DRP untuk generate voucher terlebih dahulu.">
                                                <x-ui.button
                                                    type="button"
                                                    size="sm"
                                                    variant="secondary"
                                                    disabled
                                                    class="tw-opacity-50 tw-cursor-not-allowed"
                                                >
                                                    <x-ui.icon name="badge-check" size="sm" />
                                                    <span>Tandai Paid</span>
                                                </x-ui.button>
                                            </span>
                                        @else
                                            <x-ui.button
                                                type="button"
                                                size="sm"
                                                variant="primary"
                                                data-bs-toggle="modal"
                                                data-bs-target="#markPaidModal-{{ $batch->id }}"
                                            >
                                                <x-ui.icon name="badge-check" size="sm" />
                                                <span>Tandai Paid</span>
                                            </x-ui.button>
                                        @endif
                                    @endif
                                </div>
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

    {{-- Modal Konfirmasi Bayar Batch (Ditempatkan di luar tabel agar HTML5 DOM & Alpine.js valid) --}}
    @foreach($batches as $batch)
        @if(in_array($batch->status, [\App\Models\PaymentBatch::STATUS_FINALIZED, \App\Models\PaymentBatch::STATUS_PARTIALLY_PAID]) && ! $batch->hasUnvoucheredSupplierItems())
            <div class="modal fade" id="markPaidModal-{{ $batch->id }}" tabindex="-1" aria-hidden="true" x-data="{ hasAdjustment: false }">
                <div class="modal-dialog modal-dialog-centered {{ $batch->batch_type === \App\Models\PaymentBatch::TYPE_SUPPLIER ? 'modal-lg' : '' }}">
                    <form method="POST" action="{{ route('finance.drp.paid.mark-paid', $batch) }}">
                        @csrf
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title tw-text-ui-sm tw-font-bold tw-text-on-surface tw-flex tw-items-center tw-gap-2">
                                    <x-ui.icon name="badge-check" size="sm" class="tw-text-success" />
                                    <span>Tandai Lunas: {{ $batch->batch_number }}</span>
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body tw-space-y-3">
                                <div class="tw-rounded-lg tw-bg-surface-container tw-p-3 tw-text-ui-xs tw-space-y-1">
                                    <div class="tw-flex tw-justify-between">
                                        <span class="tw-text-on-surface-variant">Tipe Batch:</span>
                                        <span class="tw-font-semibold">{{ $batch->batch_type }}</span>
                                    </div>
                                    <div class="tw-flex tw-justify-between">
                                        <span class="tw-text-on-surface-variant">Jumlah Rekening Tujuan:</span>
                                        <span class="tw-font-semibold">{{ $batch->groups_count }} Rekening</span>
                                    </div>
                                    <div class="tw-flex tw-justify-between tw-border-t tw-border-outline-variant tw-pt-1">
                                        <span class="tw-text-on-surface-variant">Total Net DRP:</span>
                                        <span class="tw-font-bold tw-font-mono tw-text-on-surface">
                                            Rp {{ number_format($batch->total_net_amount, 0, ',', '.') }}
                                        </span>
                                    </div>
                                    @if($batch->status === \App\Models\PaymentBatch::STATUS_PARTIALLY_PAID)
                                        <div class="tw-flex tw-justify-between">
                                            <span class="tw-text-on-surface-variant">Sudah Terbayar:</span>
                                            <span class="tw-font-bold tw-font-mono tw-text-success">
                                                Rp {{ number_format($batch->actual_paid_amount, 0, ',', '.') }}
                                            </span>
                                        </div>
                                        <div class="tw-flex tw-justify-between tw-border-t tw-border-outline-variant tw-pt-1">
                                            <span class="tw-text-warning-container-foreground tw-font-semibold">Sisa Belum Lunas:</span>
                                            <span class="tw-font-bold tw-font-mono tw-text-warning-container-foreground">
                                                Rp {{ number_format($batch->remaining_amount, 0, ',', '.') }}
                                            </span>
                                        </div>
                                    @endif
                                </div>

                                <p class="tw-text-ui-xs tw-text-on-surface-variant">
                                    Setelah dikonfirmasi, seluruh tagihan/klaim dalam batch ini akan otomatis ditandai <strong>PAID (Lunas)</strong> dan bukti pelunasan akan tercatat.
                                </p>

                                <div class="tw-space-y-3">
                                    <div>
                                        <x-ui.date-picker
                                            id="transfer_date_{{ $batch->id }}"
                                            name="transfer_date"
                                            :value="now()->format('Y-m-d')"
                                            label="Tanggal Transfer Bank"
                                            required="true"
                                        />
                                    </div>

                                    <div>
                                        <label class="form-label tw-text-ui-xs tw-font-semibold">
                                            Nomor Referensi Transfer Bank <span class="text-danger">*</span>
                                        </label>
                                        <input
                                            type="text"
                                            name="transfer_reference"
                                            class="form-control form-control-sm"
                                            placeholder="Contoh: TRF-BCA-20260916-001"
                                            required
                                            maxlength="100"
                                        >
                                    </div>

                                    <div>
                                        <label class="form-label tw-text-ui-xs tw-font-semibold">
                                            Catatan Pelunasan (Opsional)
                                        </label>
                                        <textarea
                                            name="payment_notes"
                                            class="form-control form-control-sm"
                                            rows="2"
                                            placeholder="Contoh: Transfer kliring via Internet Banking Corporate..."
                                            maxlength="1000"
                                        ></textarea>
                                    </div>

                                    @if($batch->batch_type === \App\Models\PaymentBatch::TYPE_SUPPLIER)
                                        <div class="tw-pt-3 tw-border-t tw-border-outline-variant">
                                            <div class="form-check tw-mb-2">
                                                <input class="form-check-input"
                                                       type="checkbox"
                                                       id="has_adjustment_{{ $batch->id }}"
                                                       x-model="hasAdjustment"
                                                >
                                                <label class="form-check-label tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="has_adjustment_{{ $batch->id }}">
                                                    Ada penyesuaian nominal transfer bank (Overpayment / Selisih Transfer Riil)
                                                </label>
                                            </div>

                                            <div x-show="hasAdjustment"
                                                 x-transition
                                                 style="display: none;"
                                                 class="tw-space-y-3 tw-bg-surface-container/50 tw-p-3 tw-rounded-lg tw-border tw-border-outline-variant">
                                                <p class="tw-text-[11px] tw-text-on-surface-variant tw-m-0">
                                                    Ubah nominal transfer jika uang yang dikirimkan ke rekening supplier berbeda dari nilai voucher tagihan.
                                                    Kelebihan bayar akan otomatis dicatat sebagai <strong>Supplier Overpayment</strong>.
                                                </p>

                                                <div class="tw-space-y-2.5">
                                                    @php $hasActiveItem = false; @endphp
                                                    @foreach($batch->groups as $group)
                                                        @foreach($group->items as $item)
                                                            @if($item->status === \App\Models\PaymentItem::STATUS_ACTIVE)
                                                                @php
                                                                    $hasActiveItem = true;
                                                                    $voucher = $item->localInvoiceVoucher;
                                                                    $payment = $item->localInvoicePayment;
                                                                    $expectedAmount = (float) ($voucher ? $voucher->amount : ($item->amount ?? 0));
                                                                    $alreadyPaid = $payment ? (float) $payment->actual_paid_total : 0.0;
                                                                    $itemRemaining = $payment ? max(0.0, (float) $payment->expected_amount - $alreadyPaid) : $expectedAmount;
                                                                    $isCorrectionRequired = $payment && $payment->status === \App\Models\LocalInvoicePayment::STATUS_CORRECTION_REQUIRED;
                                                                    $isAlreadyFinal = $payment && $payment->status === \App\Models\LocalInvoicePayment::STATUS_FINALIZED;
                                                                    $defaultPayAmount = $isCorrectionRequired ? $itemRemaining : $expectedAmount;
                                                                @endphp
                                                                @if($isAlreadyFinal)
                                                                    <div class="tw-bg-surface tw-p-2.5 tw-rounded tw-border tw-border-outline-variant tw-opacity-80">
                                                                        <div class="tw-flex tw-justify-between tw-items-center">
                                                                            <div>
                                                                                <span class="tw-font-semibold tw-text-ui-xs tw-text-on-surface">{{ $group->payee_name }}</span>
                                                                                <span class="tw-text-[11px] tw-text-on-surface-variant tw-block">
                                                                                    Inv: {{ $item->payable?->invoice_number ?? $item->item_reference }} · PO: {{ $item->payable?->po_number ?? '-' }}
                                                                                </span>
                                                                            </div>
                                                                            <div class="tw-text-end">
                                                                                <span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-2 tw-py-0.5 tw-rounded-full tw-text-[11px] tw-font-semibold tw-bg-success/10 tw-text-success">
                                                                                    <x-ui.icon name="check-circle" size="sm" /> Sudah Lunas (Rp {{ number_format($alreadyPaid, 0, ',', '.') }})
                                                                                </span>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                @else
                                                                    <div class="tw-bg-surface tw-p-2.5 tw-rounded tw-border tw-border-outline-variant"
                                                                         x-data="{
                                                                             expected: {{ (float) $defaultPayAmount }},
                                                                             actual: '{{ (float) $defaultPayAmount }}',
                                                                             get diff() {
                                                                                 return Math.round(((parseFloat(this.actual) || 0) - this.expected) * 100) / 100;
                                                                             }
                                                                         }">
                                                                        <div class="tw-flex tw-justify-between tw-items-start tw-mb-1.5">
                                                                            <div>
                                                                                <div class="tw-flex tw-items-center tw-gap-1.5">
                                                                                    <span class="tw-font-semibold tw-text-ui-xs tw-text-on-surface">{{ $group->payee_name }}</span>
                                                                                    @if($isCorrectionRequired)
                                                                                        <span class="tw-inline-flex tw-items-center tw-px-1.5 tw-py-0.2 tw-rounded tw-text-[10px] tw-font-semibold tw-bg-warning/10 tw-text-warning-container-foreground">
                                                                                            Kurang Bayar
                                                                                        </span>
                                                                                    @endif
                                                                                </div>
                                                                                <span class="tw-text-[11px] tw-text-on-surface-variant tw-block">
                                                                                    Inv: {{ $item->payable?->invoice_number ?? $item->item_reference }} · PO: {{ $item->payable?->po_number ?? '-' }}
                                                                                </span>
                                                                            </div>
                                                                            <div class="tw-text-end">
                                                                                @if($isCorrectionRequired)
                                                                                    <div class="tw-text-[11px] tw-text-on-surface-variant">
                                                                                        Voucher: <span class="tw-font-mono tw-font-semibold tw-text-on-surface">Rp {{ number_format($expectedAmount, 0, ',', '.') }}</span>
                                                                                    </div>
                                                                                    <div class="tw-text-[11px] tw-font-mono">
                                                                                        <span class="tw-text-success tw-font-medium">Terbayar: Rp {{ number_format($alreadyPaid, 0, ',', '.') }}</span>
                                                                                        <span class="tw-text-on-surface-variant">·</span>
                                                                                        <span class="tw-text-warning-container-foreground tw-font-bold">Sisa: Rp {{ number_format($itemRemaining, 0, ',', '.') }}</span>
                                                                                    </div>
                                                                                @else
                                                                                    <span class="tw-text-[11px] tw-text-on-surface-variant">Voucher:</span>
                                                                                    @if($voucher)
                                                                                        <span class="tw-font-mono tw-text-ui-xs tw-font-bold tw-text-primary">
                                                                                            Rp {{ number_format($expectedAmount, 0, ',', '.') }}
                                                                                        </span>
                                                                                    @else
                                                                                        <span class="tw-font-mono tw-text-ui-xs tw-font-bold tw-text-warning" title="Voucher belum digenerate, menggunakan nominal item">
                                                                                            Rp {{ number_format($expectedAmount, 0, ',', '.') }} (Estimasi)
                                                                                        </span>
                                                                                    @endif
                                                                                @endif
                                                                            </div>
                                                                        </div>

                                                                        <div class="row g-2 align-items-end">
                                                                            <div class="col-12 col-md-6">
                                                                                <label class="form-label tw-text-[11px] tw-font-semibold mb-1">
                                                                                    {{ $isCorrectionRequired ? 'Nominal Pelunasan Sisa (Rp)' : 'Nominal Transfer Riil (Rp)' }}
                                                                                </label>
                                                                                <input
                                                                                    type="number"
                                                                                    step="0.01"
                                                                                    min="0.01"
                                                                                    name="custom_amounts[{{ $item->id }}]"
                                                                                    x-model="actual"
                                                                                    class="form-control form-control-sm tw-font-mono"
                                                                                    :disabled="!hasAdjustment"
                                                                                    :required="hasAdjustment"
                                                                                >
                                                                            </div>
                                                                            <div class="col-12 col-md-6">
                                                                                <template x-if="diff > 0.009">
                                                                                    <div class="tw-text-[11px] tw-font-semibold tw-text-primary tw-py-1">
                                                                                        <x-ui.icon name="arrow-up-right" size="sm" /> Overpayment: +Rp <span x-text="Math.abs(diff).toLocaleString('id-ID')"></span>
                                                                                    </div>
                                                                                </template>
                                                                                <template x-if="diff < -0.009">
                                                                                    <div>
                                                                                        <div class="tw-text-[11px] tw-font-semibold tw-text-warning tw-py-1">
                                                                                            <x-ui.icon name="arrow-down-right" size="sm" /> Kurang Bayar: -Rp <span x-text="Math.abs(diff).toLocaleString('id-ID')"></span>
                                                                                        </div>
                                                                                        <input
                                                                                            type="text"
                                                                                            name="correction_reasons[{{ $item->id }}]"
                                                                                            placeholder="Alasan kurang bayar (mis: potong admin)"
                                                                                            class="form-control form-control-sm tw-text-[11px] mt-1"
                                                                                            :disabled="!hasAdjustment"
                                                                                        >
                                                                                    </div>
                                                                                </template>
                                                                                <template x-if="Math.abs(diff) <= 0.009">
                                                                                    <div class="tw-text-[11px] tw-text-success tw-py-1">
                                                                                        <x-ui.icon name="check" size="sm" /> {{ $isCorrectionRequired ? 'Sesuai sisa pelunasan' : 'Sesuai nilai voucher' }}
                                                                                    </div>
                                                                                </template>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                @endif
                                                            @endif
                                                        @endforeach
                                                    @endforeach

                                                    @if(! $hasActiveItem)
                                                        <div class="tw-p-2 tw-rounded tw-bg-surface tw-border tw-border-outline-variant tw-text-ui-xs tw-text-on-surface-variant text-center">
                                                            Belum ada item tagihan aktif pada batch ini.
                                                        </div>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            </div>
                            <div class="modal-footer">
                                <x-ui.button type="button" variant="ghost" size="sm" data-bs-dismiss="modal">Batal</x-ui.button>
                                <x-ui.button type="submit" variant="primary" size="sm">
                                    <x-ui.icon name="badge-check" size="sm" />
                                    <span>Konfirmasi</span>
                                </x-ui.button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        @endif
    @endforeach
</div>
@endsection
