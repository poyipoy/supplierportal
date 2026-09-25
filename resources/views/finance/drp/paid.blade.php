@extends('layouts.app')
@section('title', 'DRP Paid - Finance AP')
@section('page-title', 'DRP Paid (Monitoring & Eksekusi Pelunasan)')

@section('content')
<div id="drpPaidContainer" class="tw-grid tw-gap-6 tw-pb-16" data-server-tabs-container>
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
            <x-ui.button :href="route('finance.overpayments.index')" variant="outline" size="sm" class="tw-relative">
                <x-ui.icon name="alert-circle" size="sm" class="{{ ($openOverpaymentsCount ?? 0) > 0 ? 'tw-text-amber-500' : '' }}" />
                <span>Refund Overpayment</span>
                @if(($openOverpaymentsCount ?? 0) > 0)
                    <span class="tw-inline-flex tw-items-center tw-justify-center tw-rounded-full tw-bg-amber-500 tw-text-white tw-text-[10px] tw-font-bold tw-px-1.5 tw-py-0.2" data-overpayment-count>
                        {{ $openOverpaymentsCount }}
                    </span>
                @endif
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
            :href="route('finance.drp.paid.index', array_merge(request()->query(), ['tab' => 'unpaid']))"
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
            :href="route('finance.drp.paid.index', array_merge(request()->query(), ['tab' => 'paid']))"
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
                href="{{ route('finance.drp.paid.index', array_merge(request()->query(), ['tab' => 'unpaid'])) }}"
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
                href="{{ route('finance.drp.paid.index', array_merge(request()->query(), ['tab' => 'paid'])) }}"
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
                href="{{ route('finance.drp.paid.index', array_merge(request()->query(), ['tab' => 'all'])) }}"
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
    <form method="GET" action="{{ route('finance.drp.paid.index') }}" id="drpPaidFilterForm" class="tw-m-0" data-server-tabs-form>
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
                    <x-ui.button :href="route('finance.drp.paid.index', ['tab' => $tab])" size="sm" variant="ghost">
                        <x-ui.icon name="rotate-ccw" size="sm" />
                        <span>Reset</span>
                    </x-ui.button>
                @endif
            </x-slot:actions>
        </x-ui.toolbar>
    </form>

    {{-- Data Table Section (AJAX-swappable container) --}}
    <div id="drpPaidTableContent" data-server-tabs-content>
        @include('finance.drp._paid_table_content', ['batches' => $batches])
    </div>

    {{-- Modal Konfirmasi Bayar Batch (Ditempatkan di luar tabel agar HTML5 DOM & Alpine.js valid) --}}
    @foreach($batches as $batch)
        @if(in_array($batch->status, [\App\Models\PaymentBatch::STATUS_FINALIZED, \App\Models\PaymentBatch::STATUS_PARTIALLY_PAID]) && ! $batch->hasUnvoucheredSupplierItems())
            <div class="modal fade" id="markPaidModal-{{ $batch->id }}" tabindex="-1" aria-hidden="true"
                 x-data="{
                     hasAdjustment: false,
                     adjustments: {},
                     updateItem(id, expected, actualVal) {
                         const actual = parseFloat(actualVal) || 0;
                         const diff = Math.round((actual - expected) * 100) / 100;
                         this.adjustments[id] = { expected, actual, diff };
                     },
                     get totalOverpayment() {
                         if (!this.hasAdjustment) return 0;
                         let total = 0;
                         for (const key in this.adjustments) {
                             if (this.adjustments[key] && this.adjustments[key].diff > 0.009) {
                                 total += this.adjustments[key].diff;
                             }
                         }
                         return Math.round(total * 100) / 100;
                     },
                     get overpaidCount() {
                         if (!this.hasAdjustment) return 0;
                         let count = 0;
                         for (const key in this.adjustments) {
                             if (this.adjustments[key] && this.adjustments[key].diff > 0.009) count++;
                         }
                         return count;
                     }
                 }">
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

                                                <template x-if="hasAdjustment && totalOverpayment > 0.009">
                                                    <div class="tw-rounded-lg tw-border tw-border-amber-300 tw-bg-amber-50 dark:tw-bg-amber-950/40 dark:tw-border-amber-700 tw-p-3 tw-text-ui-xs">
                                                        <div class="tw-flex tw-items-center tw-gap-2 tw-text-amber-800 dark:tw-text-amber-300 tw-font-bold">
                                                            <x-ui.icon name="alert-circle" size="sm" />
                                                            <span>Perhatian: Terdeteksi Kelebihan Bayar (Overpayment)</span>
                                                        </div>
                                                        <div class="tw-mt-1 tw-text-on-surface">
                                                            Total kelebihan bayar: <span class="tw-font-mono tw-font-bold tw-text-amber-700 dark:tw-text-amber-400">+Rp <span x-text="totalOverpayment.toLocaleString('id-ID')"></span></span> pada <span x-text="overpaidCount"></span> tagihan.
                                                        </div>
                                                        <p class="tw-text-[11px] tw-text-on-surface-variant tw-mt-1 tw-mb-0">
                                                            Kelebihan dana akan otomatis dicatat sebagai <strong>Supplier Overpayment (OPEN)</strong> pada modul Refund Overpayment. Supplier akan menerima notifikasi pengembalian dana ke rekening resmi ADASI.
                                                        </p>
                                                    </div>
                                                </template>

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
                                                                             },
                                                                             init() {
                                                                                 updateItem('{{ $item->id }}', this.expected, this.actual);
                                                                                 this.$watch('actual', val => updateItem('{{ $item->id }}', this.expected, val));
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

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    // --- Export Transfer Checkbox Logic (re-bindable after AJAX swap) ---
    function initBatchSelection() {
        const selectAll = document.getElementById('selectAllBatchesPaid');
        if (selectAll) {
            // Remove prior listeners by cloning
            const fresh = selectAll.cloneNode(true);
            selectAll.parentNode.replaceChild(fresh, selectAll);
            fresh.addEventListener('change', function () {
                document.querySelectorAll('.batch-checkbox-paid').forEach(cb => cb.checked = fresh.checked);
                syncExportButton();
            });
        }
        syncExportButton();
    }

    function syncExportButton() {
        const btnExport = document.getElementById('btnExportTransferPaid');
        const countBadge = document.getElementById('exportTransferCountPaid');
        const selectAll = document.getElementById('selectAllBatchesPaid');
        if (!btnExport) return;

        const checked = document.querySelectorAll('.batch-checkbox-paid:checked');
        const allBoxes = document.querySelectorAll('.batch-checkbox-paid');
        const count = checked.length;

        btnExport.disabled = count === 0;
        if (count > 0) {
            countBadge.textContent = count;
            countBadge.classList.remove('tw-hidden');
        } else {
            countBadge.classList.add('tw-hidden');
        }
        if (allBoxes.length > 0 && selectAll) {
            selectAll.checked = count === allBoxes.length;
            selectAll.indeterminate = count > 0 && count < allBoxes.length;
        }
    }

    // Delegated change handler (survives DOM swaps)
    document.addEventListener('change', function (e) {
        if (e.target.classList.contains('batch-checkbox-paid')) {
            syncExportButton();
        }
    });

    // Delegated export button click (survives DOM swaps)
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('#btnExportTransferPaid');
        if (!btn || btn.disabled) return;

        const selected = document.querySelectorAll('.batch-checkbox-paid:checked');
        if (selected.length === 0) return;

        const batchIds = Array.from(selected).map(cb => cb.value);

        if (typeof Swal !== 'undefined') {
            Swal.fire({
                title: 'Export Transfer DRP',
                html: `Anda akan mengekspor <strong>${batchIds.length}</strong> batch DRP menjadi satu file TARIKAN TRANSFER.<br><br>Lanjutkan?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Ya, Export',
                cancelButtonText: 'Batal',
            }).then(result => {
                if (result.isConfirmed) {
                    dispatchExport(batchIds);
                }
            });
        } else {
            if (confirm('Export ' + batchIds.length + ' batch DRP menjadi satu file transfer?')) {
                dispatchExport(batchIds);
            }
        }
    });

    function dispatchExport(batchIds) {
        const btnExport = document.getElementById('btnExportTransferPaid');
        if (btnExport) btnExport.disabled = true;

        fetch("{{ route('finance.drp.export-transfer') }}", {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '{{ csrf_token() }}',
            },
            body: JSON.stringify({ batch_ids: batchIds }),
        })
        .then(response => {
            if (!response.ok) {
                return response.json().then(data => { throw data; });
            }
            return response.json();
        })
        .then(data => {
            if (typeof AdasiToast !== 'undefined') {
                AdasiToast.success(data.message || 'Export transfer berhasil didispatch.');
            } else if (typeof Swal !== 'undefined') {
                Swal.fire('Berhasil', data.message || 'Export transfer berhasil didispatch.', 'success');
            } else {
                alert(data.message || 'Export berhasil didispatch.');
            }

            try {
                const existing = JSON.parse(localStorage.getItem('adasi:pending-export-jobs:v1') || '[]');
                existing.push({
                    exportJobId: String(data.export_job_id),
                    statusUrl: data.status_url,
                    startedAt: Date.now(),
                    exportsUrl: data.exports_url || null,
                    cancelUrl: data.cancel_url || null,
                    status: 'queued',
                    stage: 'queued',
                    progress: 0,
                    processedRows: 0,
                    totalRows: 0,
                });
                localStorage.setItem('adasi:pending-export-jobs:v1', JSON.stringify(existing.slice(-25)));
            } catch (e) {}

            if (data.status_url) {
                pollExportStatusPaid(data.status_url);
            }

            document.querySelectorAll('.batch-checkbox-paid').forEach(cb => cb.checked = false);
            initBatchSelection();
        })
        .catch(err => {
            const msg = err.message || err.error || 'Terjadi kesalahan saat export transfer.';
            if (typeof AdasiToast !== 'undefined') {
                AdasiToast.error(msg);
            } else if (typeof Swal !== 'undefined') {
                Swal.fire('Gagal', msg, 'error');
            } else {
                alert(msg);
            }
            syncExportButton();
        });
    }

    function pollExportStatusPaid(statusUrl) {
        let attempts = 0;
        const maxAttempts = 120;

        const check = () => {
            attempts++;
            if (attempts > maxAttempts) return;

            fetch(statusUrl, {
                headers: { 'Accept': 'application/json' }
            })
            .then(res => res.json())
            .then(job => {
                if (job.status === 'completed' && job.download_url) {
                    if (typeof AdasiToast !== 'undefined') {
                        AdasiToast.success('Export transfer selesai. Mengunduh file...');
                    }
                    const a = document.createElement('a');
                    a.href = job.download_url;
                    a.download = job.file_name || 'DRP_TRANSFER.xlsx';
                    document.body.appendChild(a);
                    a.click();
                    a.remove();
                } else if (job.status === 'failed') {
                    const failMsg = job.message || 'Export transfer gagal diproses.';
                    if (typeof AdasiToast !== 'undefined') {
                        AdasiToast.error(failMsg);
                    }
                } else if (job.status === 'queued' || job.status === 'processing') {
                    setTimeout(check, 1500);
                }
            })
            .catch(() => {});
        };

        setTimeout(check, 1500);
    }

    // Initial bind
    initBatchSelection();

    // --- Initialize AdasiServerTabs for zero-reload tab/filter/pagination ---
    if (typeof AdasiServerTabs !== 'undefined') {
        AdasiServerTabs.init('#drpPaidContainer', {
            onUpdated: function () {
                initBatchSelection();
            },
        });
    }
});
</script>
@endpush

