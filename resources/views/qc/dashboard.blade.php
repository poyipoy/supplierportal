@extends('layouts.app')

@section('title', __('qc.copy.qc_dashboard_adasi_portal'))
@section('page-title', __('qc.copy.quality_control_dashboard'))

@section('content')
<div class="tw-grid tw-gap-4">
    {{-- Page Header --}}
    <x-ui.page-header
        :title="__('qc.copy.quality_control_dashboard')"
        :eyebrow="__('qc.copy.quality_control')"
        :description="__('qc.copy.monitor_inbound_material_quality_prioritize_pending_arrivals_and_track_inspection_outcomes')"
    />

    <x-ui.dashboard-layout audience="qc">
        <x-slot:waiting>
{{-- Operational Action Queue Banner if Waiting Inspections Exist --}}
    @if($waitingInspections > 0)
        <x-ui.alert tone="warning" :title="__('qc.copy.inspection_queue_requires_attention')">
            <div class="tw-flex tw-flex-col tw-gap-3 sm:tw-flex-row sm:tw-items-center sm:tw-justify-between">
                <span>{{ trans_choice('qc.summary.waiting_shipments', $waitingInspections, ['count' => $waitingInspections]) }}</span>
                @if($firstWaitingPo)
                    <x-ui.button :href="route('qc.inspections.create', $firstWaitingPo)" size="sm">
                        {{ __('qc.summary.inspect_next', ['po' => $firstWaitingPo->po_number]) }}
                        <x-slot:trailing><x-ui.icon name="arrow-right" /></x-slot:trailing>
                    </x-ui.button>
                @else
                    <x-ui.button :href="route('qc.inspections.index')" size="sm">
                        {{ __('qc.copy.view_inspection_queue') }}
                        <x-slot:trailing><x-ui.icon name="arrow-right" /></x-slot:trailing>
                    </x-ui.button>
                @endif
            </div>
        </x-ui.alert>
    @endif
        </x-slot:waiting>

        <x-slot:queue>
{{-- Operational Queue Table: Recent Inspections --}}
    <x-ui.data-table
        :title="__('qc.copy.recent_inspection_activity')"
        :description="__('qc.copy.latest_quality_evaluations_and_outcome_reports')"
        :empty="$recentInspections->isEmpty()"
    >
        <x-slot:toolbar>
            <x-ui.button :href="route('qc.inspections.index')" variant="ghost" size="sm">
                <span>{{ __('qc.copy.view_full_history') }}</span>
                <x-ui.icon name="arrow-right" size="sm" />
            </x-ui.button>
        </x-slot:toolbar>

        <x-slot:emptyState>
            <x-ui.empty-state
                icon="clipboard-check"
                :title="__('qc.copy.no_inspections_recorded_yet')"
                :description="__('qc.copy.completed_quality_inspections_will_appear_here')"
            />
        </x-slot:emptyState>

        <table class="table table-hover align-middle mb-0 tw-text-ui-xs w-100">
            <thead class="table-light">
                <tr>
                    <th scope="col">{{ __('qc.copy.po_number') }}</th>
                    <th scope="col">{{ __('qc.copy.supplier') }}</th>
                    <th scope="col">{{ __('qc.copy.inspection_date') }}</th>
                    <th scope="col">{{ __('qc.copy.inspector') }}</th>
                    <th scope="col" class="text-center">{{ __('qc.copy.status') }}</th>
                    <th scope="col" class="text-end" style="width: 110px;">{{ __('qc.copy.action') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($recentInspections as $insp)
                    <tr>
                        <td class="fw-bold tw-text-on-surface">{{ $insp->purchaseOrder->po_number ?? '-' }}</td>
                        <td class="tw-text-on-surface fw-medium">{{ $insp->purchaseOrder->supplier->name ?? '-' }}</td>
                        <td class="tw-text-on-surface-variant ui-tabular-nums">{{ $insp->inspected_at ? $regionalFormatter->timestamp($insp->inspected_at, 'datetime_comma') : '-' }}</td>
                        <td class="tw-text-on-surface-variant">{{ $insp->inspector->name ?? '-' }}</td>
                        <td class="text-center">
                            <x-status-badge type="qc" :status="$insp->status" />
                        </td>
                        <td class="text-end">
                            <x-ui.button :href="route('qc.inspections.show', $insp)" variant="outline" size="sm">
                                            <x-ui.icon name="eye" size="sm" />
                                <span>{{ __('qc.copy.details') }}</span>
                            </x-ui.button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-ui.data-table>
        </x-slot:queue>

        <x-slot:metrics>
{{-- Restrained operational summary --}}
    <div class="tw-grid tw-gap-px tw-overflow-hidden tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-outline-variant sm:tw-grid-cols-2 xl:tw-grid-cols-4" aria-label="{{ __('qc.copy.quality_control_summary') }}">
        <x-ui.metric-card flat :label="__('qc.copy.total_inspections')" :value="$regionalFormatter->number((string) ($totalInspections), 'plain')" icon="clipboard-check" tone="neutral" :href="route('qc.inspections.index')" />
        <x-ui.metric-card flat :label="__('qc.copy.material_ok')" :value="$regionalFormatter->number((string) ($totalOk), 'plain')" icon="circle-check" tone="success" :href="route('qc.inspections.index', ['status' => 'ok'])" />
        <x-ui.metric-card flat :label="__('qc.copy.material_ng_defective')" :value="$regionalFormatter->number((string) ($totalNg), 'plain')" icon="circle-x" :tone="$totalNg > 0 ? 'error' : 'neutral'" :href="route('qc.inspections.index', ['status' => 'ng'])" />
        <x-ui.metric-card flat :label="__('qc.copy.waiting_for_inspection')" :value="$regionalFormatter->number((string) ($waitingInspections), 'plain')" icon="clock" :tone="$waitingInspections > 0 ? 'warning' : 'neutral'" :href="route('qc.inspections.index')" />
    </div>
        </x-slot:metrics>

        <x-slot:charts>
{{-- Restrained Quality Charts Grid --}}
    <div class="tw-grid tw-grid-cols-1 tw-gap-4 lg:tw-grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
        {{-- Quality Ratio Doughnut Chart --}}
        <x-ui.card :title="__('qc.copy.quality_pass_fail_ratio')" :description="__('qc.copy.aggregate_ok_vs_ng_outcome_ratio')" class="tw-h-full">
            <div class="tw-flex tw-flex-col tw-items-center tw-justify-center tw-py-2">
                @if($totalInspections > 0)
                    <div class="tw-h-[220px] tw-w-[220px] position-relative">
                        <canvas id="qualityChart" role="img" aria-label="{{ __('qc.copy.qc_ok_and_ng_distribution') }}">{{ __('qc.copy.qc_ok_and_ng_distribution_chart') }}</canvas>
                    </div>
                    <div class="d-flex flex-wrap justify-content-center gap-4 mt-3 tw-text-ui-xs">
                        <div class="d-flex align-items-center tw-gap-1.5">
                            <span class="d-inline-block rounded-circle bg-success" style="width: 10px; height: 10px;"></span>
                            <span class="tw-text-on-surface fw-semibold">OK: {{ $totalOk }} ({{ $totalInspections > 0 ? round(($totalOk / $totalInspections) * 100) : 0 }}%)</span>
                        </div>
                        <div class="d-flex align-items-center tw-gap-1.5">
                            <span class="d-inline-block rounded-circle bg-danger" style="width: 10px; height: 10px;"></span>
                            <span class="tw-text-on-surface fw-semibold">NG: {{ $totalNg }} ({{ $totalInspections > 0 ? round(($totalNg / $totalInspections) * 100) : 0 }}%)</span>
                        </div>
                    </div>
                @else
                    <div class="tw-text-outline text-center py-5 tw-text-ui-xs">
                        <x-ui.icon name="chart-pie" size="lg" class="mb-2 tw-text-outline" />
                        <p class="mb-0">{{ __('qc.copy.no_inspection_records_available') }}</p>
                    </div>
                @endif
            </div>
        </x-ui.card>

        {{-- OK vs NG 6-Month Trend Chart --}}
        <x-ui.card :title="__('qc.copy.inspection_outcomes_trend_6_month')" :description="__('qc.copy.monthly_distribution_of_inspected_material_quality')" class="tw-h-full">
            <div class="tw-h-[260px] w-100">
                <canvas id="trendChart" role="img" aria-label="{{ __('qc.copy.qc_ok_and_ng_trend_by_period') }}">{{ __('qc.copy.qc_ok_and_ng_trend_chart_by_period') }}</canvas>
            </div>
        </x-ui.card>
    </div>
        </x-slot:charts>
    </x-ui.dashboard-layout>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const displayDashboardNumber = (text) => window.AdasiPreferences?.displayNumber
            ? window.AdasiPreferences.displayNumber(text, 'indonesian') : text;
        const themeColors = window.AdasiChart ? window.AdasiChart.getColors() : {
            success: '#1E8449',
            error: '#C0392B',
            surface: '#FFFFFF',
            onSurfaceVariant: '#64748B',
            gridLine: 'rgba(226, 232, 240, 0.75)',
        };

        const okColor = themeColors.success;
        const ngColor = themeColors.error;

        @if($totalInspections > 0)
            const qualityCanvas = document.getElementById('qualityChart');
            if (qualityCanvas) {
                new Chart(qualityCanvas, {
                    type: 'doughnut',
                    data: {
                        labels: ['OK', 'NG'],
                        datasets: [{
                            data: [{{ $totalOk }}, {{ $totalNg }}],
                            backgroundColor: [okColor, ngColor],
                            borderWidth: 2,
                            borderColor: themeColors.surface,
                            borderRadius: 4,
                            spacing: 2,
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        animation: { duration: 400 },
                        cutout: '75%',
                        plugins: {
                            legend: { display: false },
                            tooltip: window.AdasiChart?.getTooltip({
                                callbacks: {
                                    label: (ctx) => ' ' + window.AdasiI18n.choice('js.qc.inspection_count', Number(ctx.parsed), {
                                        label: ctx.label,
                                        count: displayDashboardNumber(Number(ctx.parsed).toLocaleString('id-ID')),
                                    }),
                                }
                            }) || {},
                        },
                    }
                });
            }
        @endif

        const trendCanvas = document.getElementById('trendChart');
        if (trendCanvas) {
            new Chart(trendCanvas, {
                type: 'line',
                data: {
                    labels: {!! json_encode(array_column($trendData, 'label')) !!},
                    datasets: [
                        {
                            label: @json(__('qc.copy.material_ok')),
                            data: {!! json_encode(array_column($trendData, 'ok')) !!},
                            borderColor: okColor,
                            backgroundColor: (context) => window.AdasiChart?.createAreaGradient(context, okColor, 0.16, 0.01) || 'transparent',
                            fill: true,
                            tension: 0.3,
                            borderWidth: 2.5,
                            pointRadius: 3,
                            pointHoverRadius: 6,
                            pointBackgroundColor: okColor,
                            pointBorderColor: themeColors.surface,
                            pointBorderWidth: 2,
                        },
                        {
                            label: @json(__('qc.copy.material_ng')),
                            data: {!! json_encode(array_column($trendData, 'ng')) !!},
                            borderColor: ngColor,
                            backgroundColor: (context) => window.AdasiChart?.createAreaGradient(context, ngColor, 0.12, 0.01) || 'transparent',
                            fill: true,
                            tension: 0.3,
                            borderWidth: 2.5,
                            pointRadius: 3,
                            pointHoverRadius: 6,
                            pointBackgroundColor: ngColor,
                            pointBorderColor: themeColors.surface,
                            pointBorderWidth: 2,
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: { duration: 400 },
                    plugins: {
                        legend: {
                            position: 'top',
                            align: 'end',
                            labels: {
                                boxWidth: 8,
                                boxHeight: 8,
                                usePointStyle: true,
                                font: { family: 'Inter', size: 11, weight: '500' },
                                color: themeColors.onSurfaceVariant,
                                padding: 12,
                            }
                        },
                        tooltip: window.AdasiChart?.getTooltip({
                            callbacks: {
                                label: (ctx) => ' ' + ctx.dataset.label + ': ' + displayDashboardNumber(Number(ctx.parsed.y).toLocaleString('id-ID')),
                            }
                        }) || {},
                    },
                    scales: window.AdasiChart?.getScales({
                        yMaxTicks: 5,
                        yBeginAtZero: true,
                        yFormat: (val) => displayDashboardNumber(Number(val).toLocaleString('id-ID')),
                    }) || {},
                }
            });
        }
    });
</script>
@endpush
