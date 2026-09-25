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
            <x-ui.button :href="route('finance.drp.ga')" variant="outline">
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

    {{-- Payment Forecast Module --}}
    <div x-data="paymentForecastModule()">
        <x-ui.card padding="none">
            <x-slot:header>
                <div class="tw-flex tw-flex-col sm:tw-flex-row sm:tw-items-center sm:tw-justify-between tw-gap-3 tw-w-full">
                    <div>
                        <h2 class="tw-m-0 tw-text-sm tw-font-bold tw-text-on-surface">Payment Forecast</h2>
                        <p class="tw-m-0 tw-mt-0.5 tw-text-ui-xs tw-text-on-surface-variant">Akumulasi Invoice Ready to Pay</p>
                    </div>
                    <div class="tw-flex tw-flex-wrap tw-items-center tw-gap-2">
                        {{-- Toggle Mingguan / Bulanan (Fixed Width & Fixed Position) --}}
                        <div class="tw-inline-flex tw-h-8 tw-items-center tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface-container tw-p-0.5 tw-shrink-0" role="group" aria-label="Pilihan Periode Forecast">
                            <button
                                type="button"
                                @click="setMode('weekly')"
                                :class="mode === 'weekly' ? 'tw-bg-surface tw-text-primary tw-shadow-sm tw-font-semibold' : 'tw-bg-transparent tw-text-on-surface-variant hover:tw-text-on-surface tw-font-medium'"
                                class="tw-border-0 tw-outline-none focus:tw-outline-none tw-h-full tw-w-20 tw-flex tw-items-center tw-justify-center tw-text-ui-xs tw-rounded-ui-sm tw-transition-colors tw-cursor-pointer"
                                :aria-pressed="mode === 'weekly'"
                            >
                                Mingguan
                            </button>
                            <button
                                type="button"
                                @click="setMode('monthly')"
                                :class="mode === 'monthly' ? 'tw-bg-surface tw-text-primary tw-shadow-sm tw-font-semibold' : 'tw-bg-transparent tw-text-on-surface-variant hover:tw-text-on-surface tw-font-medium'"
                                class="tw-border-0 tw-outline-none focus:tw-outline-none tw-h-full tw-w-20 tw-flex tw-items-center tw-justify-center tw-text-ui-xs tw-rounded-ui-sm tw-transition-colors tw-cursor-pointer"
                                :aria-pressed="mode === 'monthly'"
                            >
                                Bulanan
                            </button>
                        </div>

                        {{-- Dropdown Bulan untuk Mode Mingguan (tampil di sebelah kanan toggle) --}}
                        <div x-show="mode === 'weekly'" x-transition class="tw-relative tw-inline-flex tw-items-center tw-shrink-0">
                            <x-ui.icon name="calendar" size="xs" class="tw-absolute tw-left-2.5 tw-pointer-events-none tw-text-on-surface-variant tw-z-10" />
                            <label for="forecastMonthSelect" class="tw-sr-only">Pilih Bulan</label>
                            <select
                                id="forecastMonthSelect"
                                x-model="selectedMonth"
                                @change="changeMonth($event.target.value)"
                                :disabled="isLoading"
                                class="form-select form-select-sm tw-h-8 tw-min-w-[195px] tw-text-ui-xs tw-pl-8 tw-pr-8 tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface tw-text-on-surface tw-font-medium hover:tw-border-outline focus:tw-border-primary focus:tw-ring-1 focus:tw-ring-primary tw-shadow-none"
                            >
                                @foreach($availableMonths as $m)
                                    <option value="{{ $m['key'] }}">
                                        {{ $m['label'] }} {{ $m['is_current'] ? '(Bulan Ini)' : '' }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
            </x-slot:header>

            <div class="tw-p-4 shell:tw-p-5 tw-transition-opacity tw-duration-200" :class="isLoading ? 'tw-opacity-50 tw-pointer-events-none' : ''">
                {{-- Metrics Summary Grid --}}
                <div class="tw-grid tw-grid-cols-1 md:tw-grid-cols-3 tw-gap-4 tw-mb-6">
                    {{-- Primary: Total Akumulasi Periode --}}
                    <div class="tw-p-4 tw-rounded-ui-md tw-border tw-border-primary/20 tw-bg-primary/5">
                        <div class="tw-flex tw-items-center tw-justify-between tw-mb-1">
                            <span class="tw-text-ui-xs tw-font-semibold tw-text-primary tw-tracking-wide tw-uppercase">Total Akumulasi Periode</span>
                            <x-ui.icon name="trending-up" class="tw-text-primary" size="sm" />
                        </div>
                        <div class="tw-text-xl lg:tw-text-2xl tw-font-bold tw-font-mono tw-text-on-surface tw-tracking-tight" x-text="formatRupiah(totalAccumulation)">
                            Rp {{ number_format(end($weeklyForecast)['cumulative_amount'] ?? 0, 0, ',', '.') }}
                        </div>
                        <span class="tw-block tw-mt-1 tw-text-ui-xs tw-text-on-surface-variant">
                            Akumulasi hingga <span x-text="latestPeriodLabel">{{ end($weeklyForecast)['label'] ?? 'periode terakhir' }}</span>
                        </span>
                    </div>

                    {{-- Secondary: Ready to Pay Saat Ini --}}
                    <div class="tw-p-4 tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container">
                        <div class="tw-flex tw-items-center tw-justify-between tw-mb-1">
                            <span class="tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant tw-tracking-wide tw-uppercase">Ready to Pay Saat Ini</span>
                            <x-ui.icon name="badge-check" class="tw-text-success" size="sm" />
                        </div>
                        <div class="tw-flex tw-items-baseline tw-gap-2">
                            <span class="tw-text-xl lg:tw-text-2xl tw-font-bold tw-font-mono tw-text-on-surface tw-tracking-tight">
                                {{ $readyToPaySummary['total_amount_formatted'] ?? 'Rp 0' }}
                            </span>
                        </div>
                        <span class="tw-block tw-mt-1 tw-text-ui-xs tw-text-on-surface-variant">
                            <strong class="tw-text-on-surface">{{ $readyToPaySummary['count'] ?? 0 }} invoice</strong> outstanding antrean aktif
                        </span>
                    </div>

                    {{-- Additional: Invoice Masuk Periode --}}
                    <div class="tw-p-4 tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container">
                        <div class="tw-flex tw-items-center tw-justify-between tw-mb-1">
                            <span class="tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant tw-tracking-wide tw-uppercase">Invoice Masuk Periode</span>
                            <x-ui.icon name="inbox" class="tw-text-info" size="sm" />
                        </div>
                        <div class="tw-flex tw-items-baseline tw-gap-2">
                            <span class="tw-text-xl lg:tw-text-2xl tw-font-bold tw-font-mono tw-text-on-surface tw-tracking-tight" x-text="formatRupiah(currentPeriodAmount)">
                                Rp {{ number_format(end($weeklyForecast)['period_amount'] ?? 0, 0, ',', '.') }}
                            </span>
                        </div>
                        <span class="tw-block tw-mt-1 tw-text-ui-xs tw-text-on-surface-variant">
                            <strong class="tw-text-on-surface" x-text="currentPeriodCount + ' invoice'">{{ end($weeklyForecast)['count'] ?? 0 }} invoice</strong> masuk pada periode berjalan
                        </span>
                    </div>
                </div>

                {{-- Chart Visualization --}}
                <div class="tw-mb-6">
                    <div class="tw-flex tw-flex-col sm:tw-flex-row sm:tw-items-center sm:tw-justify-between tw-gap-2 tw-mb-2">
                        <h3 class="tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant tw-uppercase tw-tracking-wide tw-m-0">
                            Visualisasi Arus Masuk & Akumulasi Pipeline
                        </h3>
                        <div class="tw-flex tw-items-center tw-gap-4 tw-text-ui-xs tw-text-on-surface-variant">
                            <span class="tw-inline-flex tw-items-center tw-gap-1.5">
                                <span class="tw-w-3 tw-h-3 tw-rounded-sm tw-bg-primary"></span>
                                <span>Nilai Periode (Bar)</span>
                            </span>
                            <span class="tw-inline-flex tw-items-center tw-gap-1.5">
                                <span class="tw-w-3 tw-h-0.5 tw-bg-teal-600"></span>
                                <span class="tw-w-1.5 tw-h-1.5 tw-rounded-full tw-bg-teal-600"></span>
                                <span>Akumulasi (Line)</span>
                            </span>
                        </div>
                    </div>
                    <div class="tw-h-[300px] tw-w-full">
                        <canvas id="paymentForecastChart" aria-label="Grafik akumulasi invoice Ready to Pay" role="img"></canvas>
                    </div>
                </div>

                {{-- Fallback / Detail Data Table --}}
                <div class="tw-border-t tw-border-outline-variant tw-pt-4">
                    <div class="tw-flex tw-flex-col sm:tw-flex-row sm:tw-items-center sm:tw-justify-between tw-gap-2 tw-mb-3">
                        <h3 class="tw-text-ui-sm tw-font-bold tw-text-on-surface tw-m-0">
                            Rincian Data Per Periode
                        </h3>
                        <span class="tw-text-ui-xs tw-text-on-surface-variant" x-text="mode === 'weekly' ? 'Menampilkan ' + currentData.length + ' minggu (Week 1 s/d Week ' + currentData.length + ')' : 'Menampilkan ' + currentData.length + ' bulan terakhir'">
                            Menampilkan {{ count($weeklyForecast) }} minggu (Week 1 s/d Week {{ count($weeklyForecast) }})
                        </span>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle tw-m-0 tw-text-ui-sm w-100" aria-label="Tabel rincian akumulasi Ready to Pay">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col" class="tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant">Periode</th>
                                    <th scope="col" class="tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant text-center">Invoice Ready to Pay</th>
                                    <th scope="col" class="tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant text-end">Nilai Periode</th>
                                    <th scope="col" class="tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant text-end">Akumulasi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="(row, idx) in currentData" :key="row.start + '_' + mode">
                                    <tr>
                                        <td>
                                            <span class="tw-font-semibold tw-text-on-surface" x-text="row.week || row.month || row.label"></span>
                                            <span class="tw-block tw-text-ui-xs tw-text-on-surface-variant" x-text="row.start + ' s/d ' + row.end"></span>
                                        </td>
                                        <td class="text-center">
                                            <span class="tw-inline-flex tw-items-center tw-px-2 tw-py-0.5 tw-rounded tw-text-ui-xs tw-font-semibold"
                                                  :class="row.count > 0 ? 'tw-bg-primary/10 tw-text-primary' : 'tw-bg-surface-container tw-text-on-surface-variant'"
                                                  x-text="row.count + ' invoice'">
                                            </span>
                                        </td>
                                        <td class="text-end tw-font-mono tw-font-semibold tw-text-on-surface" x-text="formatRupiah(row.period_amount)">
                                        </td>
                                        <td class="text-end tw-font-mono tw-font-bold tw-text-primary" x-text="formatRupiah(row.cumulative_amount)">
                                        </td>
                                    </tr>
                                </template>
                                @foreach($weeklyForecast as $w)
                                    <tr class="forecast-ssr-row" x-show="false">
                                        <td>
                                            <span class="tw-font-semibold tw-text-on-surface">{{ $w['week'] ?? $w['label'] }}</span>
                                            <span class="tw-block tw-text-ui-xs tw-text-on-surface-variant">{{ $w['start'] }} s/d {{ $w['end'] }}</span>
                                        </td>
                                        <td class="text-center">
                                            <span class="tw-inline-flex tw-items-center tw-px-2 tw-py-0.5 tw-rounded tw-text-ui-xs tw-font-semibold {{ $w['count'] > 0 ? 'tw-bg-primary/10 tw-text-primary' : 'tw-bg-surface-container tw-text-on-surface-variant' }}">
                                                {{ $w['count'] }} invoice
                                            </span>
                                        </td>
                                        <td class="text-end tw-font-mono tw-font-semibold tw-text-on-surface">
                                            {{ $w['period_amount_formatted'] ?? ('Rp ' . number_format($w['period_amount'], 0, ',', '.')) }}
                                        </td>
                                        <td class="text-end tw-font-mono tw-font-bold tw-text-primary">
                                            {{ $w['cumulative_amount_formatted'] ?? ('Rp ' . number_format($w['cumulative_amount'], 0, ',', '.')) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
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
                                <x-ui.status-chip :tone="\App\Support\StatusHelper::paymentBatchTone($batch->status)">
                                    {{ $batch->status }}
                                </x-ui.status-chip>
                            </td>
                            <td class="text-end tw-font-mono tw-font-semibold tw-text-on-surface">
                                Rp {{ number_format($batch->total_subtotal, 0, ',', '.') }}
                            </td>
                            <td class="text-end tw-font-mono tw-text-on-surface-variant">
                                Rp {{ number_format($batch->total_bank_fee, 0, ',', '.') }}
                            </td>
                            <td>
                                <span class="tw-text-ui-xs">{{ $batch->creator?->name ?? 'System' }}</span>
                            </td>
                            <td class="text-end">
                                <x-ui.button :href="route('finance.drp.show', $batch)" size="sm" variant="outline">
                                    <x-ui.icon name="eye" size="sm" />
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

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
window.paymentForecastModule = function() {
    return {
        mode: 'weekly',
        selectedMonth: '{{ $selectedMonth }}',
        weeklyData: @json($weeklyForecast),
        monthlyData: @json($monthlyForecast),
        summary: @json($readyToPaySummary),
        isLoading: false,
        chartInstance: null,

        get currentData() {
            return this.mode === 'weekly' ? this.weeklyData : this.monthlyData;
        },

        get latestPeriod() {
            return this.currentData.length > 0 ? this.currentData[this.currentData.length - 1] : null;
        },

        get totalAccumulation() {
            return this.latestPeriod ? this.latestPeriod.cumulative_amount : 0;
        },

        get latestPeriodLabel() {
            return this.latestPeriod ? (this.latestPeriod.label || this.latestPeriod.week || this.latestPeriod.month) : '-';
        },

        get currentPeriodCount() {
            return this.latestPeriod ? this.latestPeriod.count : 0;
        },

        get currentPeriodAmount() {
            return this.latestPeriod ? this.latestPeriod.period_amount : 0;
        },

        formatRupiah(val) {
            const num = Number(val) || 0;
            return 'Rp ' + num.toLocaleString('id-ID');
        },

        setMode(newMode) {
            if (this.mode === newMode) return;
            this.mode = newMode;
            this.updateChart();
        },

        async changeMonth(month) {
            this.selectedMonth = month;
            this.isLoading = true;
            try {
                const response = await fetch(`{{ route('finance.forecast') }}?month=${month}`);
                if (!response.ok) throw new Error('Network error');
                const data = await response.json();
                this.weeklyData = data.weekly;
                if (this.mode === 'weekly') {
                    this.updateChart();
                }
            } catch (err) {
                console.error('Failed to load forecast for month', month, err);
            } finally {
                this.isLoading = false;
            }
        },

        init() {
            this.$nextTick(() => {
                this.initChart();
            });
        },

        initChart() {
            const canvas = document.getElementById('paymentForecastChart');
            if (!canvas) return;

            const colors = window.AdasiChart ? window.AdasiChart.getColors() : {
                primary: '#1F5FA6',
                surface: '#FFFFFF',
                onSurface: '#1A202C',
                onSurfaceVariant: '#64748B',
                gridLine: 'rgba(226, 232, 240, 0.75)',
            };

            const labels = this.currentData.map(d => d.short_label || d.label || d.week || d.month);
            const periodAmounts = this.currentData.map(d => d.period_amount);
            const cumulativeAmounts = this.currentData.map(d => d.cumulative_amount);

            const self = this;

            this.chartInstance = new Chart(canvas, {
                data: {
                    labels: labels,
                    datasets: [
                        {
                            type: 'line',
                            label: 'Akumulasi',
                            data: cumulativeAmounts,
                            borderColor: '#0D9488',
                            backgroundColor: (context) => window.AdasiChart?.createAreaGradient(context, '#0D9488', 0.15, 0.01) || 'rgba(13, 148, 136, 0.05)',
                            fill: true,
                            tension: 0.25,
                            borderWidth: 2.5,
                            pointRadius: 4,
                            pointHoverRadius: 6,
                            pointBackgroundColor: '#0D9488',
                            pointBorderColor: '#FFFFFF',
                            pointBorderWidth: 1.5,
                            order: 1,
                        },
                        {
                            type: 'bar',
                            label: 'Nilai Periode',
                            data: periodAmounts,
                            backgroundColor: (context) => window.AdasiChart?.createBarGradient(context, colors.primary, 0.9, 0.35) || colors.primary,
                            borderColor: colors.primary,
                            borderWidth: 1,
                            borderRadius: 4,
                            borderSkipped: false,
                            maxBarThickness: 32,
                            barPercentage: 0.55,
                            order: 2,
                        },
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: { duration: 350 },
                    interaction: {
                        mode: 'index',
                        intersect: false,
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            enabled: true,
                            backgroundColor: 'rgba(15, 23, 42, 0.94)',
                            titleColor: '#F8FAFC',
                            bodyColor: '#F8FAFC',
                            titleFont: { family: 'Inter', size: 12, weight: '600' },
                            bodyFont: { family: 'Inter', size: 11, weight: '400' },
                            padding: { top: 8, bottom: 8, left: 12, right: 12 },
                            cornerRadius: 8,
                            borderColor: 'rgba(255, 255, 255, 0.08)',
                            borderWidth: 1,
                            callbacks: {
                                title: function(items) {
                                    if (!items || !items.length) return '';
                                    const idx = items[0].dataIndex;
                                    const item = self.currentData[idx];
                                    return item ? (item.label || item.week || item.month) : items[0].label;
                                },
                                label: function(context) {
                                    const idx = context.dataIndex;
                                    const item = self.currentData[idx];
                                    if (!item) return '';
                                    if (context.datasetIndex === 1) { // Bar
                                        return [
                                            '  Invoice Masuk: ' + item.count + ' invoice',
                                            '  Nilai Periode: ' + self.formatRupiah(item.period_amount)
                                        ];
                                    } else { // Line
                                        return '  Total Akumulasi: ' + self.formatRupiah(item.cumulative_amount);
                                    }
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            border: { display: false },
                            ticks: {
                                font: { family: 'Inter', size: 11, weight: '500' },
                                color: colors.onSurfaceVariant,
                                padding: 6,
                            }
                        },
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: colors.gridLine,
                                drawBorder: false,
                            },
                            border: { display: false },
                            ticks: {
                                font: { family: 'Inter', size: 11 },
                                color: colors.onSurfaceVariant,
                                padding: 8,
                                callback: function(value) {
                                    if (value >= 1e9) return 'Rp ' + (value / 1e9).toFixed(1) + 'M';
                                    if (value >= 1e6) return 'Rp ' + (value / 1e6).toFixed(0) + 'jt';
                                    return 'Rp ' + Number(value).toLocaleString('id-ID');
                                }
                            }
                        }
                    }
                }
            });
        },

        updateChart() {
            if (!this.chartInstance) return;

            const labels = this.currentData.map(d => d.short_label || d.label || d.week || d.month);
            const periodAmounts = this.currentData.map(d => d.period_amount);
            const cumulativeAmounts = this.currentData.map(d => d.cumulative_amount);

            this.chartInstance.data.labels = labels;
            this.chartInstance.data.datasets[0].data = cumulativeAmounts;
            this.chartInstance.data.datasets[1].data = periodAmounts;
            this.chartInstance.update();
        }
    };
};
</script>
@endpush
