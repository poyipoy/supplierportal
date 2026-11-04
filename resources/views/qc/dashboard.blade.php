@extends('layouts.app')

@section('title', __('qc.copy.qc_dashboard_adasi_portal'))
@section('page-title', __('qc.copy.quality_control_dashboard'))

@section('content')
<div class="tw:grid tw:gap-4">
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
            <div class="tw:flex tw:flex-col tw:gap-3 tw:sm:flex-row tw:sm:items-center tw:sm:justify-between">
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

        <table class="table table-hover align-middle mb-0 tw:text-ui-xs w-100">
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
                        <td class="fw-bold tw:text-on-surface">{{ $insp->purchaseOrder->po_number ?? '-' }}</td>
                        <td class="tw:text-on-surface fw-medium">{{ $insp->purchaseOrder->supplier->name ?? '-' }}</td>
                        <td class="tw:text-on-surface-variant ui-tabular-nums">{{ $insp->inspected_at ? $regionalFormatter->timestamp($insp->inspected_at, 'datetime_comma') : '-' }}</td>
                        <td class="tw:text-on-surface-variant">{{ $insp->inspector->name ?? '-' }}</td>
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
    <div class="tw:grid tw:gap-px tw:overflow-hidden tw:rounded-ui-md tw:border tw:border-outline-variant tw:bg-outline-variant tw:sm:grid-cols-2 tw:xl:grid-cols-4" aria-label="{{ __('qc.copy.quality_control_summary') }}">
        <x-ui.metric-card flat :label="__('qc.copy.total_inspections')" :value="$regionalFormatter->number((string) ($totalInspections), 'plain')" icon="clipboard-check" tone="neutral" :href="route('qc.inspections.index')" />
        <x-ui.metric-card flat :label="__('qc.copy.material_ok')" :value="$regionalFormatter->number((string) ($totalOk), 'plain')" icon="circle-check" tone="success" :href="route('qc.inspections.index', ['status' => 'ok'])" />
        <x-ui.metric-card flat :label="__('qc.copy.material_ng_defective')" :value="$regionalFormatter->number((string) ($totalNg), 'plain')" icon="circle-x" :tone="$totalNg > 0 ? 'error' : 'neutral'" :href="route('qc.inspections.index', ['status' => 'ng'])" />
        <x-ui.metric-card flat :label="__('qc.copy.waiting_for_inspection')" :value="$regionalFormatter->number((string) ($waitingInspections), 'plain')" icon="clock" :tone="$waitingInspections > 0 ? 'warning' : 'neutral'" :href="route('qc.inspections.index')" />
    </div>
        </x-slot:metrics>

        <x-slot:charts>
{{-- Quality charts: the pass rate is a stat (two values never need a donut); the trend is a described chart with a table. --}}
    @php
        $okPercent = $totalInspections > 0 ? (int) round(($totalOk / $totalInspections) * 100) : 0;
        $ngPercent = $totalInspections > 0 ? (int) round(($totalNg / $totalInspections) * 100) : 0;
    @endphp
    <div class="tw:grid tw:grid-cols-1 tw:gap-4 tw:lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
        <x-ui.card :title="__('qc.copy.quality_pass_fail_ratio')" :description="__('qc.copy.aggregate_ok_vs_ng_outcome_ratio')" class="tw:h-full">
            @if($totalInspections > 0)
                <div class="tw:grid tw:gap-3 tw:py-2">
                    <div>
                        <div class="tw:text-ui-xs tw:font-semibold tw:text-on-surface-variant">{{ __('charts.qc.ok_rate') }}</div>
                        <div class="tw:text-3xl tw:font-bold tw:text-on-surface">{{ $okPercent }}%</div>
                        <p class="tw:m-0 tw:mt-1 tw:text-ui-sm tw:text-on-surface-variant">{{ __('charts.qc.ok_rate_detail', ['ok' => $regionalFormatter->number((string) $totalOk, 'plain'), 'total' => $regionalFormatter->number((string) $totalInspections, 'plain')]) }}</p>
                    </div>
                    <div class="tw:flex tw:h-3 tw:w-full tw:gap-0.5 tw:overflow-hidden tw:rounded-ui-xs tw:bg-surface-container" aria-hidden="true">
                        @if($totalOk > 0)<span class="tw:block tw:h-full tw:bg-[var(--md-chart-ok)]" style="width: {{ $okPercent }}%"></span>@endif
                        @if($totalNg > 0)<span class="tw:block tw:h-full tw:bg-[var(--md-chart-ng)]" style="width: {{ $ngPercent }}%"></span>@endif
                    </div>
                    <ul class="tw:m-0 tw:grid tw:list-none tw:gap-1.5 tw:p-0 tw:text-ui-sm">
                        <li class="tw:flex tw:items-center tw:gap-2">
                            <x-ui.icon name="circle-check" size="sm" class="tw:text-[var(--md-chart-ok)]" />
                            <span class="tw:font-semibold tw:text-on-surface">{{ __('qc.copy.material_ok') }}</span>
                            <span class="tw:ms-auto tw:text-on-surface ui-tabular-nums">{{ $regionalFormatter->number((string) $totalOk, 'plain') }} ({{ $okPercent }}%)</span>
                        </li>
                        <li class="tw:flex tw:items-center tw:gap-2">
                            <x-ui.icon name="circle-x" size="sm" class="tw:text-[var(--md-chart-ng)]" />
                            <span class="tw:font-semibold tw:text-on-surface">{{ __('qc.copy.material_ng') }}</span>
                            <span class="tw:ms-auto tw:text-on-surface ui-tabular-nums">{{ $regionalFormatter->number((string) $totalNg, 'plain') }} ({{ $ngPercent }}%)</span>
                        </li>
                    </ul>
                </div>
            @else
                <x-ui.empty-state icon="chart-pie" :title="__('qc.copy.no_inspection_records_available')" />
            @endif
        </x-ui.card>

        <x-ui.card :title="__('qc.copy.inspection_outcomes_trend_6_month')" :description="__('qc.copy.monthly_distribution_of_inspected_material_quality')" class="tw:h-full">
            @if($totalInspections > 0)
                <x-ui.chart id="trendChart" :label="__('qc.copy.qc_ok_and_ng_trend_by_period')" :fallback="__('qc.copy.qc_ok_and_ng_trend_chart_by_period')" plot-class="tw:h-[260px]">
                    <x-slot:table>
                        <table class="table table-sm align-middle tw:m-0 tw:text-ui-xs">
                            <caption class="tw:sr-only">{{ __('qc.copy.inspection_outcomes_trend_6_month') }}</caption>
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('charts.columns.period') }}</th>
                                    <th scope="col" class="text-end">{{ __('qc.copy.material_ok') }}</th>
                                    <th scope="col" class="text-end">{{ __('qc.copy.material_ng') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($trendData as $month)
                                    <tr>
                                        <th scope="row" class="tw:font-normal">{{ $month['label'] }}</th>
                                        <td class="text-end ui-tabular-nums">{{ $regionalFormatter->number((string) $month['ok'], 'plain') }}</td>
                                        <td class="text-end ui-tabular-nums">{{ $regionalFormatter->number((string) $month['ng'], 'plain') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </x-slot:table>
                </x-ui.chart>
            @else
                <x-ui.empty-state icon="chart-line" :title="__('qc.copy.no_inspection_records_available')" />
            @endif
        </x-ui.card>
    </div>
        </x-slot:charts>
    </x-ui.dashboard-layout>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js" integrity="sha384-jb8JQMbMoBUzgWatfe6COACi2ljcDdZQ2OxczGA3bGNeWe+6DChMTBJemed7ZnvJ" crossorigin="anonymous"></script>
<script>
    // Runs after the deferred app module, so AdasiChart (tokens, scales, a11y plugin) is ready.
    document.addEventListener('DOMContentLoaded', function() {
        const trendCanvas = document.getElementById('trendChart');
        if (!trendCanvas || !window.AdasiChart) return;
        const chart = window.AdasiChart;
        const scales = chart.getScales({ yMaxTicks: 5, yBeginAtZero: true });
        scales.y.ticks.precision = 0;

        new Chart(trendCanvas, {
            type: 'line',
            data: {
                labels: @json(array_column($trendData, 'label')),
                datasets: [
                    {
                        label: @json(__('qc.copy.material_ok')),
                        data: @json(array_column($trendData, 'ok')),
                        borderColor: chart.color('ok'),
                        backgroundColor: chart.color('ok'),
                        pointBorderColor: chart.color('surface'),
                        pointStyle: 'circle',
                        borderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        pointBorderWidth: 2,
                        tension: 0,
                    },
                    {
                        label: @json(__('qc.copy.material_ng')),
                        data: @json(array_column($trendData, 'ng')),
                        borderColor: chart.color('ng'),
                        backgroundColor: chart.color('ng'),
                        pointBorderColor: chart.color('surface'),
                        pointStyle: 'triangle',
                        borderDash: [6, 4],
                        borderWidth: 2,
                        pointRadius: 5,
                        pointHoverRadius: 7,
                        pointBorderWidth: 2,
                        tension: 0,
                    },
                ],
            },
            options: {
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: chart.getLegend(),
                    tooltip: chart.getTooltip({
                        callbacks: {
                            label: (ctx) => ' ' + ctx.dataset.label + ': ' + chart.formatNumber(ctx.parsed.y),
                        },
                    }),
                },
                scales,
            },
        });
    });
</script>
@endpush
