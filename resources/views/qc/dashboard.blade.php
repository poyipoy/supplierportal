@extends('layouts.app')
@section('title', 'QC Dashboard - ADASI Portal')
@section('page-title', 'Dashboard Quality Control')

@section('content')
<div class="tw-grid tw-gap-6">
    <x-ui.page-header title="Quality Control Dashboard" description="Prioritize arrivals waiting for inspection and monitor OK/NG quality outcomes." eyebrow="QC" />
    <div class="tw-grid tw-gap-4 sm:tw-grid-cols-2 xl:tw-grid-cols-4">
        <x-ui.metric-card label="Total Inspections" :value="$totalInspections" icon="bi-clipboard2-check" :href="route('qc.inspections.index')" />
        <x-ui.metric-card label="Material OK" :value="$totalOk" icon="bi-check-circle" tone="success" :href="route('qc.inspections.index')" />
        <x-ui.metric-card label="Material NG" :value="$totalNg" icon="bi-x-octagon" tone="error" :href="route('qc.inspections.index')" />
        <x-ui.metric-card label="Waiting for Inspection" :value="$waitingInspections" icon="bi-hourglass-split" tone="warning" :href="$firstWaitingPo ? route('qc.inspections.create', $firstWaitingPo) : route('qc.inspections.index')" />
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-4">
            <x-ui.card title="Quality Ratio" class="tw-h-full">
                <div class="tw-flex tw-items-center tw-justify-center">
                    @if($totalInspections > 0)
                        <div class="tw-h-[220px] tw-w-[220px]"><canvas id="qualityChart"></canvas></div>
                    @else
                        <div class="text-muted text-center">No inspection data available.</div>
                    @endif
                </div>
            </x-ui.card>
        </div>
        <div class="col-lg-8">
            <x-ui.card title="OK vs NG Trend" description="Historical inspection outcome by period." class="tw-h-full"><canvas id="trendChart" height="200"></canvas></x-ui.card>
        </div>
    </div>

    <x-ui.data-table title="10 Latest Inspections" description="Recent inspection results and report access.">
        <x-slot:toolbar><x-ui.button :href="route('qc.inspections.index')" variant="ghost" size="sm">View All</x-ui.button></x-slot:toolbar>
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>PO No.</th>
                            <th>Supplier</th>
                            <th>Inspection Date</th>
                            <th class="text-center">Status</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($recentInspections as $insp)
                            <tr>
                                <td class="fw-bold">{{ $insp->purchaseOrder->po_number }}</td>
                                <td>{{ $insp->purchaseOrder->supplier->name }}</td>
                                <td>{{ $insp->inspected_at->format('d M Y') }}</td>
                                <td class="text-center"><x-status-badge type="qc" :status="$insp->status" /></td>
                                <td class="text-end"><a href="{{ route('qc.inspections.show', $insp) }}"
                                        class="btn btn-sm btn-outline-info">Details</a></td>
                            </tr>
                        @empty<tr>
                            <td colspan="5" class="text-center text-muted py-3">No data.</td>
                        </tr>@endforelse
                    </tbody>
                </table>
            </div>
        </div>
        </tbody>
                </table>
    </x-ui.data-table>
</div>
@endsection

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        const qcThemeStyles = getComputedStyle(document.documentElement);
        const qcThemeColor = (token) => qcThemeStyles.getPropertyValue(token).trim();
        const qcThemeRgba = (token, alpha) => `rgba(${qcThemeColor(token)}, ${alpha})`;

        const commonTooltip = {
            backgroundColor: qcThemeColor('--md-surface'),
            titleColor: qcThemeColor('--md-on-surface'),
            bodyColor: qcThemeColor('--md-on-surface-variant'),
            borderColor: qcThemeColor('--md-outline'),
            borderWidth: 1,
            titleFont: { size: 14, family: 'Inter', weight: 'bold' },
            bodyFont: { size: 13, family: 'Inter' },
            padding: 12,
            cornerRadius: 8,
            boxPadding: 6,
            displayColors: true,
        };

        @if($totalInspections > 0)
            new Chart(document.getElementById('qualityChart'), {
                type: 'doughnut',
                data: {
                    labels: ['OK', 'NG'],
                    datasets: [{
                        data: [{{ $totalOk }}, {{ $totalNg }}],
                        backgroundColor: [qcThemeColor('--md-success'), qcThemeColor('--md-error')],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom', labels: { font: { family: 'Inter' } } },
                        tooltip: commonTooltip
                    },
                    cutout: '70%'
                }
            });
        @endif

        new Chart(document.getElementById('trendChart'), {
            type: 'line',
            data: {
                labels: {!! json_encode(array_column($trendData, 'label')) !!},
                datasets: [
                    {
                        label: 'OK',
                        data: {!! json_encode(array_column($trendData, 'ok')) !!},
                        borderColor: qcThemeColor('--md-success'),
                        backgroundColor: qcThemeRgba('--md-success-rgb', .1),
                        fill: true,
                        tension: 0.3,
                        pointRadius: 5,
                        pointBackgroundColor: qcThemeColor('--md-success')
                    },
                    {
                        label: 'NG',
                        data: {!! json_encode(array_column($trendData, 'ng')) !!},
                        borderColor: qcThemeColor('--md-error'),
                        backgroundColor: qcThemeRgba('--md-error-rgb', .1),
                        fill: true,
                        tension: 0.3,
                        pointRadius: 5,
                        pointBackgroundColor: qcThemeColor('--md-error')
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: { beginAtZero: true, ticks: { stepSize: 1 } }
                },
                plugins: {
                    legend: { position: 'bottom', labels: { font: { family: 'Inter' } } },
                    tooltip: commonTooltip
                }
            }
        });
    </script>
@endpush
