@extends('layouts.app')
@section('title', 'Purchasing Dashboard - ADASI Portal')
@section('page-title', 'Dashboard Purchasing')

@push('styles')
<style>
    .operational-check-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: .75rem;
    }

    .operational-check-item {
        border: 1px solid #e9ecef;
        border-radius: .5rem;
        color: inherit;
        display: flex;
        gap: .75rem;
        padding: .9rem;
        text-decoration: none;
        transition: border-color .15s ease, box-shadow .15s ease, transform .15s ease;
    }

    .operational-check-item:hover {
        border-color: rgba(31, 95, 166, .28);
        box-shadow: 0 .35rem 1rem rgba(31, 95, 166, .08);
        transform: translateY(-1px);
    }

    .operational-check-icon {
        align-items: center;
        border-radius: 50%;
        display: flex;
        flex: 0 0 38px;
        height: 38px;
        justify-content: center;
        width: 38px;
    }

    @media (max-width: 991.98px) {
        .operational-check-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 575.98px) {
        .operational-check-grid {
            grid-template-columns: minmax(0, 1fr);
        }
    }
</style>
@endpush

@section('content')

{{-- Insight & Anomaly Alerts --}}
@php
    $hasInsights = ($poStatusDist['overdue'] ?? 0) > 0 || $menungguPenawaran > 0 || ($poStatusDist['waiting_qc'] ?? 0) > 0;
@endphp
@if($hasInsights)
<div class="row mb-4 animate-fade-in">
    <div class="col-12">
        <div class="alert alert-warning border-0 shadow-sm d-flex align-items-center gap-3 mb-0" style="background-color: #fff9e6; border-left: 4px solid #ffc107 !important;">
            <div class="bg-warning bg-opacity-25 rounded-circle p-2 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                <i class="bi bi-lightbulb-fill fs-4 text-warning"></i>
            </div>
            <div>
                <h6 class="fw-bold mb-1 text-dark">Action Required</h6>
                <p class="mb-0 text-muted small">
                    @if(($poStatusDist['overdue'] ?? 0) > 0) <span class="text-danger fw-semibold"><i class="bi bi-exclamation-circle"></i> {{ $poStatusDist['overdue'] }} overdue PO</span> have passed their estimated date. @endif
                    @if($menungguPenawaran > 0) <span class="text-warning fw-semibold ms-1"><i class="bi bi-clock"></i> {{ $menungguPenawaran }} PR</span> have not received any quotations yet. @endif
                    @if(($poStatusDist['waiting_qc'] ?? 0) > 0) <span class="text-primary fw-semibold ms-1"><i class="bi bi-box-seam"></i> {{ $poStatusDist['waiting_qc'] }} PO</span> are waiting for QC inspection. @endif
                </p>
            </div>
        </div>
    </div>
</div>
@endif

{{-- Card Statistik (Clickable) --}}
<div class="row g-4 mb-4 animate-fade-in">
    <div class="col-md-6 col-xl-3">
        <a href="{{ route('purchasing.requisitions.index', ['status' => 'submitted']) }}" class="kpi-card card border-0 shadow-sm h-100 border-start border-4 border-primary">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div><div class="text-muted small fw-medium mb-1">ACTIVE REQUISITIONS</div><h3 class="fw-bold mb-0">{{ $prAktif }}</h3></div>
                    <div class="position-relative">
                        <div class="bg-primary bg-opacity-10 rounded-circle p-3"><i class="bi bi-clipboard-data text-primary fs-4"></i></div>
                        <i class="bi bi-arrow-right kpi-arrow text-primary position-absolute" style="bottom:-2px;right:-2px;font-size:.7rem"></i>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-6 col-xl-3">
        <a href="{{ route('purchasing.requisitions.index', ['status' => 'bidding']) }}" class="kpi-card card border-0 shadow-sm h-100 border-start border-4 border-warning">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div><div class="text-muted small fw-medium mb-1">WAITING FOR QUOTATION</div><h3 class="fw-bold mb-0 text-warning">{{ $menungguPenawaran }}</h3></div>
                    <div class="position-relative">
                        <div class="bg-warning bg-opacity-10 rounded-circle p-3"><i class="bi bi-hourglass-split text-warning fs-4"></i></div>
                        <i class="bi bi-arrow-right kpi-arrow text-warning position-absolute" style="bottom:-2px;right:-2px;font-size:.7rem"></i>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-6 col-xl-3">
        <a href="{{ route('purchasing.purchase-orders.index', ['status' => 'active']) }}" class="kpi-card card border-0 shadow-sm h-100 border-start border-4 border-success">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div><div class="text-muted small fw-medium mb-1">ACTIVE PO</div><h3 class="fw-bold mb-0 text-success">{{ $poBerjalan }}</h3></div>
                    <div class="position-relative">
                        <div class="bg-success bg-opacity-10 rounded-circle p-3"><i class="bi bi-receipt text-success fs-4"></i></div>
                        <i class="bi bi-arrow-right kpi-arrow text-success position-absolute" style="bottom:-2px;right:-2px;font-size:.7rem"></i>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-6 col-xl-3">
        <a href="{{ route('purchasing.purchase-orders.index', ['arrival' => 'this_week']) }}" class="kpi-card card border-0 shadow-sm h-100 border-start border-4 border-info">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div><div class="text-muted small fw-medium mb-1">ARRIVING THIS WEEK</div><h3 class="fw-bold mb-0 text-info">{{ $materialMingguIni }}</h3></div>
                    <div class="position-relative">
                        <div class="bg-info bg-opacity-10 rounded-circle p-3"><i class="bi bi-truck text-info fs-4"></i></div>
                        <i class="bi bi-arrow-right kpi-arrow text-info position-absolute" style="bottom:-2px;right:-2px;font-size:.7rem"></i>
                    </div>
                </div>
            </div>
        </a>
    </div>
</div>

{{-- Quick operational checks --}}
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-3 d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div>
            <h6 class="mb-0 fw-bold">Needs Review</h6>
            <div class="text-muted small">Operational summary that needs Purchasing attention.</div>
        </div>
        <span class="badge bg-light text-muted border">Quick Wins</span>
    </div>
    <div class="card-body">
        <div class="operational-check-grid">
            @foreach($operationalChecks as $check)
                <a href="{{ $check['url'] }}" class="operational-check-item">
                    <span class="operational-check-icon bg-{{ $check['class'] }} bg-opacity-10 text-{{ $check['class'] }}">
                        <i class="bi {{ $check['icon'] }}"></i>
                    </span>
                    <span class="min-w-0">
                        <span class="d-flex align-items-center gap-2">
                            <span class="fw-bold fs-5 lh-1">{{ $check['count'] }}</span>
                            @if($check['count'] > 0)
                                <span class="badge bg-{{ $check['class'] }}">Needs Action</span>
                            @else
                                <span class="badge bg-success">Safe</span>
                            @endif
                        </span>
                        <span class="d-block fw-semibold mt-1">{{ $check['label'] }}</span>
                        <span class="d-block text-muted small">{{ $check['description'] }}</span>
                    </span>
                </a>
            @endforeach
        </div>
    </div>
</div>

{{-- Grafik --}}
<div class="row g-4 mb-4">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3"><h6 class="mb-0 fw-bold">Purchase Requisition per Month</h6></div>
            <div class="card-body"><canvas id="prChart" height="260"></canvas></div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3"><h6 class="mb-0 fw-bold">Distribusi Status PO</h6></div>
            <div class="card-body d-flex align-items-center justify-content-center">
                @if(count($poStatusDist) > 0)
                    <div style="width:220px;height:220px;"><canvas id="poDonut"></canvas></div>
                @else
                    <div class="text-muted text-center small">No data available PO.</div>
                @endif
            </div>
        </div>
    </div>
</div>

{{-- Table + Exchange Rate --}}
<div class="row g-4">
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold">Latest 5 PRs</h6>
                <a href="{{ route('purchasing.requisitions.index') }}" class="btn btn-sm btn-light">All</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size:.85rem">
                        <thead class="table-light"><tr><th>PR No.</th><th>Period</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                            @forelse($prTerbaru as $pr)
                            <tr>
                                <td class="fw-bold">{{ $pr->pr_number ?? 'DRAFT' }}</td>
                                <td>{{ $pr->period->name }}</td>
                                <td><x-status-badge type="pr" :status="$pr->status" /></td>
                                <td class="text-end"><a href="{{ \App\Support\PurchasingNavigation::toRoute('purchasing.requisitions.show', $pr) }}" class="btn btn-sm btn-outline-info"><i class="bi bi-eye"></i></a></td>
                            </tr>
                            @empty<tr><td colspan="4" class="text-center text-muted py-3">No data available.</td></tr>@endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        {{-- Exchange Rate --}}
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold">
                    <i class="bi bi-currency-exchange me-1"></i> Today Exchange Rate
                    <i class="bi bi-info-circle ms-1 text-muted" data-bs-toggle="tooltip" data-bs-title="The latest exchange rate is used for new input. Quotation and PO history keep their own exchange rate snapshots."></i>
                </h6>
                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#kursModal"><i class="bi bi-pencil-square"></i> Update</button>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    @foreach(\App\Models\ExchangeRate::CURRENCIES as $currency)
                        @php
                            $rate = $latestRates[$currency] ?? null;
                        @endphp
                        <div class="col-6">
                            <div class="p-3 bg-light rounded text-center h-100">
                                <div class="text-muted small mb-1">{{ $currency }} → IDR</div>
                                <h5 class="fw-bold mb-0">Rp {{ $rate ? number_format($rate->rate_to_idr, 0, ',', '.') : '-' }}</h5>
                            </div>
                        </div>
                    @endforeach
                </div>
                @php
                    $lastRateUpdated = $latestRates->filter()->sortByDesc('valid_from')->first()?->valid_from;
                @endphp
                @if($lastRateUpdated)
                    <div class="text-muted text-center mt-2" style="font-size:.7rem">Latest exchange rate update: {{ $lastRateUpdated->format('d M Y') }}</div>
                @endif
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold">PO - Nearest Arrival</h6>
                <a href="{{ route('purchasing.purchase-orders.index') }}" class="btn btn-sm btn-light">All PO</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size:.85rem">
                        <thead class="table-light"><tr><th>PO No.</th><th>Supplier</th><th>Estimated Arrival</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                            @forelse($poTerdekat as $po)
                            <tr>
                                <td class="fw-bold">{{ $po->po_number }}</td>
                                <td>{{ $po->supplier->name }}</td>
                                <td>{{ \Carbon\Carbon::parse($po->estimated_arrival)->format('d M Y') }}</td>
                                <td><x-status-badge type="po" :status="$po->status" :is-overdue="$po->is_overdue ?? false" /></td>
                                <td class="text-end"><a href="{{ \App\Support\PurchasingNavigation::toRoute('purchasing.purchase-orders.show', $po) }}" class="btn btn-sm btn-outline-info"><i class="bi bi-eye"></i></a></td>
                            </tr>
                            @empty<tr><td colspan="5" class="text-center text-muted py-3">No active PO.</td></tr>@endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Exchange Rate Modal --}}
<div class="modal fade" id="kursModal" tabindex="-1"><div class="modal-dialog modal-sm"><div class="modal-content">
    <form action="{{ route('purchasing.kurs.update') }}" method="POST">@csrf
        <div class="modal-header"><h6 class="modal-title fw-bold">Update Exchange Rate</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="mb-3">
                <label class="form-label small fw-bold">Currency</label>
                <select name="currency" class="form-select form-select-sm" required>
                    @foreach(\App\Models\ExchangeRate::CURRENCY_LABELS as $code => $label)
                        <option value="{{ $code }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-bold">
                    Rate to IDR
                    <i class="bi bi-info-circle ms-1 text-muted" data-bs-toggle="tooltip" data-bs-title="New exchange rate is saved as new history, not overwriting the old one."></i>
                </label>
                <input type="number" step="0.01" name="rate_to_idr" class="form-control form-control-sm" required placeholder="16500">
            </div>
        </div>
        <div class="modal-footer"><button type="submit" class="btn btn-primary btn-sm w-100">Save</button></div>
    </form>
</div></div></div>
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

        new Chart(document.getElementById('prChart'), {
            type: 'bar',
            data: {
                labels: {!! json_encode(array_column($prPerBulan, 'label')) !!},
                datasets: [{
                    label: 'Amount PR',
                    data: {!! json_encode(array_column($prPerBulan, 'count')) !!},
                    backgroundColor: 'rgba(31,95,166,0.7)',
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: commonTooltip
                },
                scales: {
                    y: { beginAtZero: true, ticks: { stepSize: 1 } }
                }
            }
        });

        @if(count($poStatusDist)>0)
        @php
            $statusColors = [
                'active' => '#0d6efd',
                'waiting_qc' => '#ffc107',
                'completed' => '#198754',
                'overdue' => '#dc3545',
                'claim_needed' => '#dc3545',
                'cancelled' => '#6c757d',
            ];
            $chartLabels = [];
            $chartData = [];
            $chartColors = [];
            foreach($poStatusDist as $status => $count) {
                $chartLabels[] = ucwords(str_replace('_', ' ', $status));
                $chartData[] = $count;
                $chartColors[] = $statusColors[$status] ?? '#6c757d';
            }
        @endphp
        new Chart(document.getElementById('poDonut'), {
            type: 'doughnut',
            data: {
                labels: {!! json_encode($chartLabels) !!},
                datasets: [{
                    data: {!! json_encode($chartData) !!},
                    backgroundColor: {!! json_encode($chartColors) !!},
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '65%',
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 12, font: { family: 'Inter' } } },
                    tooltip: commonTooltip
                }
            }
        });
        @endif
</script>
@endpush
