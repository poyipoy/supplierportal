@extends('layouts.app')
@section('title', 'QC Dashboard - ADASI Portal')
@section('page-title', 'Dashboard Quality Control')

@section('content')
    <div class="row g-4 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100 border-start border-4 border-primary">
                <div class="card-body">
                    <div class="text-muted small fw-medium mb-1">TOTAL INSPECTIONS</div>
                    <h3 class="fw-bold mb-0">{{ $totalInspections }}</h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100 border-start border-4 border-success">
                <div class="card-body">
                    <div class="text-muted small fw-medium mb-1">MATERIAL OK</div>
                    <h3 class="fw-bold mb-0 text-success">{{ $totalOk }}</h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100 border-start border-4 border-danger">
                <div class="card-body">
                    <div class="text-muted small fw-medium mb-1">MATERIAL NG</div>
                    <h3 class="fw-bold mb-0 text-danger">{{ $totalNg }}</h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100 border-start border-4 border-warning">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small fw-medium mb-1">WAITING FOR INSPECTION</div>
                        <h3 class="fw-bold mb-0 text-warning">{{ $waitingInspections }}</h3>
                    </div>
                    @if($firstWaitingPo)
                        <a href="{{ route('qc.inspections.create', $firstWaitingPo) }}"
                            class="btn btn-warning btn-sm text-dark"><i class="bi bi-play-fill"></i> Mulai</a>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold">Rasio Kualitas</h6>
                </div>
                <div class="card-body d-flex align-items-center justify-content-center">
                    @if($totalInspections > 0)
                        <div style="width:220px;height:220px;"><canvas id="qualityChart"></canvas></div>
                    @else
                        <div class="text-muted text-center">No inspection data available.</div>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold">OK vs NG Trend (History)</h6>
                </div>
                <div class="card-body"><canvas id="trendChart" height="200"></canvas></div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h6 class="mb-0 fw-bold">10 Latest Inspections</h6>
            <a href="{{ route('qc.inspections.index') }}" class="btn btn-sm btn-light">View All</a>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
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
    </div>
    </div>
    </div>
@endsection

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        const commonTooltip = {
            backgroundColor: 'rgba(255, 255, 255, 0.95)',
            titleColor: '#333',
            bodyColor: '#555',
            borderColor: 'rgba(0,0,0,0.1)',
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
                        backgroundColor: ['#198754', '#dc3545'],
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
                        borderColor: '#198754',
                        backgroundColor: 'rgba(25,135,84,0.1)',
                        fill: true,
                        tension: 0.3,
                        pointRadius: 5,
                        pointBackgroundColor: '#198754'
                    },
                    {
                        label: 'NG',
                        data: {!! json_encode(array_column($trendData, 'ng')) !!},
                        borderColor: '#dc3545',
                        backgroundColor: 'rgba(220,53,69,0.1)',
                        fill: true,
                        tension: 0.3,
                        pointRadius: 5,
                        pointBackgroundColor: '#dc3545'
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