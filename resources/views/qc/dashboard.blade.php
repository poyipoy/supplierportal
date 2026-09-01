@extends('layouts.app')

@section('title', 'QC Dashboard - ADASI Portal')
@section('page-title', 'Quality Control Dashboard')

@section('content')
<div class="tw-grid tw-gap-4">
    {{-- Page Header --}}
    <x-ui.page-header
        title="Quality Control Dashboard"
        eyebrow="Quality Control"
        description="Monitor inbound material quality, prioritize pending arrivals, and track inspection outcomes."
    />

    {{-- Operational Action Queue Banner if Waiting Inspections Exist --}}
    @if($waitingInspections > 0)
        <x-ui.alert tone="warning" title="Inspection queue requires attention">
            <div class="tw-flex tw-flex-col tw-gap-3 sm:tw-flex-row sm:tw-items-center sm:tw-justify-between">
                <span>You have <strong>{{ $waitingInspections }} shipment(s)</strong> awaiting QC inspection. Conduct inspections promptly upon material arrival.</span>
                @if($firstWaitingPo)
                    <x-ui.button :href="route('qc.inspections.create', $firstWaitingPo)" size="sm">
                        Inspect Next Arrival ({{ $firstWaitingPo->po_number }})
                        <x-slot:trailing><x-ui.icon name="arrow-right" /></x-slot:trailing>
                    </x-ui.button>
                @else
                    <x-ui.button :href="route('qc.inspections.index')" size="sm">
                        View Inspection Queue
                        <x-slot:trailing><x-ui.icon name="arrow-right" /></x-slot:trailing>
                    </x-ui.button>
                @endif
            </div>
        </x-ui.alert>
    @endif

    {{-- Operational Queue Table: Recent Inspections --}}
    <x-ui.data-table
        title="Recent Inspection Activity"
        description="Latest quality evaluations and outcome reports."
        :empty="$recentInspections->isEmpty()"
    >
        <x-slot:toolbar>
            <x-ui.button :href="route('qc.inspections.index')" variant="ghost" size="sm">
                <span>View Full History</span>
                <x-ui.icon name="arrow-right" size="sm" />
            </x-ui.button>
        </x-slot:toolbar>

        <x-slot:emptyState>
            <x-ui.empty-state
                icon="clipboard-check"
                title="No inspections recorded yet"
                description="Completed quality inspections will appear here."
            />
        </x-slot:emptyState>

        <table class="table table-hover align-middle mb-0 tw-text-ui-xs w-100">
            <thead class="table-light">
                <tr>
                    <th scope="col">PO Number</th>
                    <th scope="col">Supplier</th>
                    <th scope="col">Inspection Date</th>
                    <th scope="col">Inspector</th>
                    <th scope="col" class="text-center">Status</th>
                    <th scope="col" class="text-end" style="width: 110px;">Action</th>
                </tr>
            </thead>
            <tbody>
                @foreach($recentInspections as $insp)
                    <tr>
                        <td class="fw-bold tw-text-on-surface">{{ $insp->purchaseOrder->po_number ?? '-' }}</td>
                        <td class="tw-text-on-surface fw-medium">{{ $insp->purchaseOrder->supplier->name ?? '-' }}</td>
                        <td class="tw-text-on-surface-variant ui-tabular-nums">{{ $insp->inspected_at ? $insp->inspected_at->format('d M Y, H:i') : '-' }}</td>
                        <td class="tw-text-on-surface-variant">{{ $insp->inspector->name ?? '-' }}</td>
                        <td class="text-center">
                            <x-status-badge type="qc" :status="$insp->status" />
                        </td>
                        <td class="text-end">
                            <x-ui.button :href="route('qc.inspections.show', $insp)" variant="outline" size="sm">
                                            <x-ui.icon name="eye" size="sm" />
                                <span>Details</span>
                            </x-ui.button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-ui.data-table>

    {{-- Restrained operational summary --}}
    <div class="tw-grid tw-gap-px tw-overflow-hidden tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-outline-variant sm:tw-grid-cols-2 xl:tw-grid-cols-4" aria-label="Quality control summary">
        <x-ui.metric-card flat label="Total Inspections" :value="$totalInspections" icon="clipboard-check" tone="neutral" :href="route('qc.inspections.index')" />
        <x-ui.metric-card flat label="Material OK" :value="$totalOk" icon="circle-check" tone="success" :href="route('qc.inspections.index', ['status' => 'ok'])" />
        <x-ui.metric-card flat label="Material NG (Defective)" :value="$totalNg" icon="circle-x" :tone="$totalNg > 0 ? 'error' : 'neutral'" :href="route('qc.inspections.index', ['status' => 'ng'])" />
        <x-ui.metric-card flat label="Waiting for Inspection" :value="$waitingInspections" icon="clock" :tone="$waitingInspections > 0 ? 'warning' : 'neutral'" :href="route('qc.inspections.index')" />
    </div>

    {{-- Restrained Quality Charts Grid --}}
    <div class="tw-grid tw-gap-4 lg:tw-grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
        {{-- Quality Ratio Doughnut Chart --}}
        <x-ui.card title="Quality Pass/Fail Ratio" description="Aggregate OK vs NG outcome ratio." class="tw-h-full">
            <div class="tw-flex tw-flex-col tw-items-center tw-justify-center tw-py-2">
                @if($totalInspections > 0)
                    <div class="tw-h-[220px] tw-w-[220px] position-relative">
                        <canvas id="qualityChart" role="img" aria-label="QC OK and NG distribution">QC OK and NG distribution chart.</canvas>
                    </div>
                    <div class="d-flex justify-content-center gap-4 mt-3 tw-text-ui-xs">
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
                        <p class="mb-0">No inspection records available.</p>
                    </div>
                @endif
            </div>
        </x-ui.card>

        {{-- OK vs NG 6-Month Trend Chart --}}
        <x-ui.card title="Inspection Outcomes Trend (6-Month)" description="Monthly distribution of inspected material quality." class="tw-h-full">
            <div class="tw-h-[260px] w-100">
                <canvas id="trendChart" role="img" aria-label="QC OK and NG trend by period">QC OK and NG trend chart by period.</canvas>
            </div>
        </x-ui.card>
    </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
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
                                    label: (ctx) => ' ' + ctx.label + ': ' + Number(ctx.parsed).toLocaleString('id-ID') + ' Inspections',
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
                            label: 'Material OK',
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
                            label: 'Material NG',
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
                                label: (ctx) => ' ' + ctx.dataset.label + ': ' + Number(ctx.parsed.y).toLocaleString('id-ID'),
                            }
                        }) || {},
                    },
                    scales: window.AdasiChart?.getScales({
                        yMaxTicks: 5,
                        yBeginAtZero: true,
                        yFormat: (val) => Number(val).toLocaleString('id-ID'),
                    }) || {},
                }
            });
        }
    });
</script>
@endpush
