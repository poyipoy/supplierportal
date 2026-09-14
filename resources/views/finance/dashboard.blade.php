@extends('layouts.app')
@section('title', 'Finance AP Dashboard - ADASI')
@section('page-title', 'Finance AP Dashboard')

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        title="Finance AP Dashboard"
        description="Pantau penerimaan dokumen fisik invoice, verifikasi berkas, forecast arus kas, dan eksekusi pembayaran DRP."
        eyebrow="Finance & Accounts Payable"
    >
        <x-slot:actions>
            <x-ui.button :href="route('finance.invoices.index')" variant="primary">
                <x-ui.icon name="list" size="sm" />
                <span>Invoice Register</span>
            </x-ui.button>
            <x-ui.button :href="route('finance.drp.supplier')" variant="outline">
                <x-ui.icon name="wallet" size="sm" />
                <span>DRP Supplier</span>
            </x-ui.button>
            <x-ui.button :href="route('finance.drp.ga')" variant="ghost">
                <x-ui.icon name="credit-card" size="sm" />
                <span>DRP GA</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- KPI Metric Cards Grid --}}
    <div class="tw-grid tw-grid-cols-2 md:tw-grid-cols-3 lg:tw-grid-cols-6 tw-gap-4">
        <x-ui.metric-card
            label="Menunggu Fisik"
            :value="$kpis['waiting_physical'] ?? 0"
            icon="file-clock"
            tone="warning"
            :href="route('finance.invoices.index', ['status' => 'WAITING_PHYSICAL_DOCUMENT'])"
        />
        <x-ui.metric-card
            label="Sedang Verifikasi"
            :value="$kpis['under_verification'] ?? 0"
            icon="clipboard-check"
            tone="primary"
            :href="route('finance.invoices.index', ['status' => 'UNDER_VERIFICATION'])"
        />
        <x-ui.metric-card
            label="Perlu Revisi"
            :value="$kpis['need_revision'] ?? 0"
            icon="file-edit"
            tone="error"
            :href="route('finance.invoices.index', ['status' => 'NEED_REVISION'])"
        />
        <x-ui.metric-card
            label="Ready to Pay"
            :value="$kpis['ready_to_pay'] ?? 0"
            icon="badge-check"
            tone="success"
            :href="route('finance.invoices.index', ['status' => 'READY_TO_PAY'])"
        />
        <x-ui.metric-card
            label="Selesai Dibayar"
            :value="$kpis['paid'] ?? 0"
            icon="check-circle-2"
            tone="neutral"
            :href="route('finance.invoices.index', ['status' => 'PAID'])"
        />
        <x-ui.metric-card
            label="Expired / Overdue"
            :value="($kpis['expired'] ?? 0) + ($kpis['overdue'] ?? 0)"
            icon="alert-octagon"
            tone="error"
            :href="route('finance.invoices.index', ['overdue' => 1])"
        />
    </div>

    {{-- Cash Outflow Forecast Section --}}
    <div class="tw-grid tw-grid-cols-1 lg:tw-grid-cols-2 tw-gap-6">
        {{-- Weekly Forecast --}}
        <x-ui.card
            title="Weekly Cash Outflow Forecast (Next 4 Weeks)"
            description="Estimasi kewajiban pembayaran berdasarkan jatuh tempo (Tgl Kasir + Term)"
        >
            <div class="tw-space-y-3">
                @forelse($weeklyForecast as $w)
                    <div class="tw-flex tw-items-center tw-justify-between tw-p-3 tw-rounded-ui-sm tw-bg-surface-container tw-border tw-border-outline-variant">
                        <div>
                            <span class="tw-font-semibold tw-text-ui-sm tw-text-on-surface">{{ $w['week'] ?? $w['label'] ?? ('Week '.($w['week_number'] ?? $loop->iteration)) }}</span>
                            <span class="tw-block tw-text-ui-xs tw-text-on-surface-variant">
                                {{ \Carbon\Carbon::parse($w['start'] ?? $w['start_date'] ?? now())->format('d M') }} — {{ \Carbon\Carbon::parse($w['end'] ?? $w['end_date'] ?? now())->format('d M Y') }} ({{ $w['count'] ?? 0 }} invoices)
                            </span>
                        </div>
                        <div class="text-end">
                            <span class="tw-font-mono tw-font-bold tw-text-primary tw-text-ui-sm">
                                Rp {{ number_format($w['total'] ?? $w['total_amount'] ?? 0, 0, ',', '.') }}
                            </span>
                        </div>
                    </div>
                @empty
                    <div class="tw-text-center tw-py-6 tw-text-ui-xs tw-text-on-surface-variant">
                        Tidak ada kewajiban jatuh tempo dalam 4 minggu ke depan.
                    </div>
                @endforelse
            </div>
        </x-ui.card>

        {{-- Monthly Forecast --}}
        <x-ui.card
            title="Monthly Cash Outflow Forecast (Next 3 Months)"
            description="Proyeksi beban pembayaran per bulan kalender"
        >
            <div class="tw-space-y-3">
                @forelse($monthlyForecast as $m)
                    <div class="tw-flex tw-items-center tw-justify-between tw-p-3 tw-rounded-ui-sm tw-bg-surface-container tw-border tw-border-outline-variant">
                        <div>
                            <span class="tw-font-semibold tw-text-ui-sm tw-text-on-surface">{{ $m['month'] ?? $m['label'] ?? '' }}</span>
                            <span class="tw-block tw-text-ui-xs tw-text-on-surface-variant">
                                Total {{ $m['count'] ?? 0 }} invoice / tagihan
                            </span>
                        </div>
                        <div class="text-end">
                            <span class="tw-font-mono tw-font-bold tw-text-success tw-text-ui-sm">
                                Rp {{ number_format($m['total'] ?? $m['amount'] ?? 0, 0, ',', '.') }}
                            </span>
                        </div>
                    </div>
                @empty
                    <div class="tw-text-center tw-py-6 tw-text-ui-xs tw-text-on-surface-variant">
                        Tidak ada data proyeksi bulanan.
                    </div>
                @endforelse
            </div>
        </x-ui.card>
    </div>

    {{-- Recent DRP Batches --}}
    <x-ui.data-table
        title="Recent Payment Batches (DRP)"
        description="Daftar 5 batch pembayaran DRP terakhir yang dibuat."
    >
        <x-slot:toolbar>
            <x-ui.button :href="route('finance.drp.supplier')" variant="ghost" size="sm">
                <span>Kelola DRP Supplier</span>
                <x-ui.icon name="arrow-right" size="sm" />
            </x-ui.button>
        </x-slot:toolbar>

        <div class="table-responsive">
            <table class="table table-hover align-middle tw-m-0 tw-text-ui-sm w-100">
                <thead class="table-light">
                    <tr>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant">Batch Number</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant">Tipe</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant">Status</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant text-end">Total Nominal</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant text-end">Total Fee Bank</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant">Dibuat Oleh</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($recentBatches as $batch)
                        <tr>
                            <td>
                                <span class="tw-font-bold tw-font-mono tw-text-on-surface">{{ $batch->batch_number }}</span>
                                <span class="tw-block tw-text-ui-xs tw-text-on-surface-variant">{{ $batch->created_at->format('d M Y H:i') }}</span>
                            </td>
                            <td>
                                <span class="tw-inline-flex tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-semibold {{ $batch->batch_type === 'SUPPLIER' ? 'tw-bg-primary/10 tw-text-primary' : 'tw-bg-info/10 tw-text-info' }}">
                                    {{ $batch->batch_type }}
                                </span>
                            </td>
                            <td>
                                <x-ui.status-chip :tone="match($batch->status) { 'PAID' => 'success', 'PARTIALLY_PAID' => 'info', 'FINALIZED' => 'primary', default => 'warning' }">
                                    {{ $batch->status }}
                                </x-ui.status-chip>
                            </td>
                            <td class="text-end tw-font-mono tw-font-semibold tw-text-on-surface">
                                Rp {{ number_format($batch->total_amount, 0, ',', '.') }}
                            </td>
                            <td class="text-end tw-font-mono tw-text-on-surface-variant">
                                Rp {{ number_format($batch->total_bank_fee, 0, ',', '.') }}
                            </td>
                            <td>
                                <span class="tw-text-ui-xs">{{ $batch->creator?->name ?? 'System' }}</span>
                            </td>
                            <td class="text-end">
                                <x-ui.button :href="route('finance.drp.show', $batch)" size="sm" variant="outline">
                                    <x-ui.icon name="eye" size="xs" />
                                    <span>Detail DRP</span>
                                </x-ui.button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center tw-py-8 tw-text-on-surface-variant tw-text-ui-sm">
                                Belum ada batch DRP yang dibuat.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.data-table>

    {{-- Recent Submissions Table --}}
    <x-ui.data-table
        title="Recent Invoice Submissions"
        description="5 tagihan invoice supplier lokal terbaru yang masuk ke sistem."
    >
        <x-slot:toolbar>
            <x-ui.button :href="route('finance.invoices.index')" variant="ghost" size="sm">
                <span>Buka Invoice Register</span>
                <x-ui.icon name="arrow-right" size="sm" />
            </x-ui.button>
        </x-slot:toolbar>

        @include('local-invoices.table', ['invoices' => $recentInvoices ?? $invoices ?? [], 'portal' => 'finance', 'payments' => false])
    </x-ui.data-table>
</div>
@endsection
