@extends('layouts.app')
@section('title', 'Purchasing Dashboard - ADASI Portal')
@section('page-title', 'Purchasing Dashboard')

@push('styles')
<style>
    .operational-check-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: .75rem;
    }

    .operational-check-item {
        border: 1px solid var(--md-outline-variant);
        border-radius: .5rem;
        color: inherit;
        display: flex;
        gap: .75rem;
        padding: .9rem;
        text-decoration: none;
        transition: border-color .15s ease, box-shadow .15s ease;
    }

    .operational-check-item:hover {
        border-color: rgba(var(--md-primary-rgb), .28);
        box-shadow: var(--ui-shadow-1);
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
<div class="tw-grid tw-gap-6">
    <x-ui.page-header
        title="Purchasing dashboard"
        eyebrow="Operations overview"
        description="Track requisitions, quotation response, active orders, arrivals, and items that need attention."
    >
        <x-slot:actions>
            <x-ui.button :href="\App\Support\PurchasingNavigation::toRoute('purchasing.requisitions.create')" size="sm">
                <x-ui.icon name="plus-circle" />
                Create requisition
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

{{-- Insight & Anomaly Alerts --}}
@php
    $hasInsights = ($poStatusDist['overdue'] ?? 0) > 0 || $menungguPenawaran > 0 || ($poStatusDist['waiting_qc'] ?? 0) > 0;
@endphp
@if($hasInsights)
    <x-ui.alert tone="warning" title="Action required">
        <div class="tw-flex tw-flex-wrap tw-gap-x-2 tw-gap-y-1">
                    @if(($poStatusDist['overdue'] ?? 0) > 0) <span class="text-danger fw-semibold"><x-ui.icon name="circle-alert" /> {{ $poStatusDist['overdue'] }} overdue PO</span> have passed their estimated date. @endif
                    @if($menungguPenawaran > 0) <span class="text-warning fw-semibold ms-1"><x-ui.icon name="clock" /> {{ $menungguPenawaran }} PR</span> have not received any quotations yet. @endif
                    @if(($poStatusDist['waiting_qc'] ?? 0) > 0) <span class="text-primary fw-semibold ms-1"><x-ui.icon name="package" /> {{ $poStatusDist['waiting_qc'] }} PO</span> are waiting for QC inspection. @endif
        </div>
    </x-ui.alert>
@endif

{{-- Clickable metrics --}}
<div class="tw-grid tw-gap-4 sm:tw-grid-cols-2 xl:tw-grid-cols-4">
    <x-ui.metric-card label="Active requisitions" :value="$prAktif" icon="clipboard-list" :href="route('purchasing.requisitions.index', ['status' => 'submitted'])" />
    <x-ui.metric-card label="Waiting for quotation" :value="$menungguPenawaran" icon="hourglass" tone="warning" :href="route('purchasing.requisitions.index', ['status' => 'bidding'])" />
    <x-ui.metric-card label="Active PO" :value="$poBerjalan" icon="receipt" tone="success" :href="route('purchasing.purchase-orders.index', ['status' => 'active'])" />
    <x-ui.metric-card label="Arriving this week" :value="$materialMingguIni" icon="truck" tone="info" :href="route('purchasing.purchase-orders.index', ['arrival' => 'this_week'])" />
</div>

{{-- Quick operational checks --}}
<x-ui.card title="Needs review" description="Operational checks that may need Purchasing attention.">
    <x-slot:actions><x-ui.status-chip tone="neutral">Quick checks</x-ui.status-chip></x-slot:actions>
        <div class="operational-check-grid">
            @foreach($operationalChecks as $check)
                <a href="{{ $check['url'] }}" class="operational-check-item">
                    <x-ui.icon :name="$check['icon']" size="lg" class="mt-1 flex-shrink-0 text-{{ $check['class'] }}" />
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
</x-ui.card>

{{-- Grafik --}}
<div class="tw-grid tw-gap-6 lg:tw-grid-cols-[minmax(0,2fr)_minmax(18rem,1fr)]">
    <x-ui.card title="Purchase requisitions per month" description="Monthly creation volume for the current reporting window." class="tw-min-w-0">
        <div class="tw-h-[16.25rem]"><canvas id="prChart" role="img" aria-label="Purchase requisition volume by month">Purchase requisition volume chart by month.</canvas></div>
    </x-ui.card>
    <x-ui.card title="PO status distribution" description="Current purchase-order workload by state." class="tw-min-w-0">
            <div class="tw-flex tw-min-h-[16.25rem] tw-items-center tw-justify-center">
                @if(count($poStatusDist) > 0)
                    <div class="tw-h-[13.75rem] tw-w-[13.75rem]"><canvas id="poDonut" role="img" aria-label="Purchase order status distribution">Purchase order status distribution chart.</canvas></div>
                @else
                    <x-ui.empty-state icon="pie-chart" title="No PO status data" description="Status distribution will appear after purchase orders are created." />
                @endif
            </div>
    </x-ui.card>
</div>

{{-- Table + Exchange Rate --}}
<div class="tw-grid tw-items-start tw-gap-6 lg:tw-grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)]">
    <div class="tw-grid tw-gap-6">
        <x-ui.data-table title="Latest 5 PRs" description="Most recently created requisitions.">
            <x-slot:toolbar>
                <x-ui.button :href="route('purchasing.requisitions.index')" variant="ghost" size="sm">View all</x-ui.button>
            </x-slot:toolbar>
                    <table class="table table-hover align-middle mb-0 tw-text-ui-sm">
                        <thead class="table-light"><tr><th>PR No.</th><th>Period</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                            @forelse($prTerbaru as $pr)
                            <tr>
                                <td class="fw-bold">{{ $pr->pr_number ?? 'DRAFT' }}</td>
                                <td>{{ $pr->period->display_label ?? $pr->period->name }}</td>
                                <td><x-status-badge type="pr" :status="$pr->status" /></td>
                                <td class="text-end"><a href="{{ \App\Support\PurchasingNavigation::toRoute('purchasing.requisitions.show', $pr) }}" class="btn btn-sm btn-outline-info"><x-ui.icon name="eye" /></a></td>
                            </tr>
                            @empty<tr><td colspan="4" class="text-center text-muted py-3">No data available.</td></tr>@endforelse
                        </tbody>
                    </table>
        </x-ui.data-table>
        {{-- Exchange Rate --}}
        <x-ui.card>
            <x-slot:header>
                <h2 class="tw-m-0 tw-text-ui-lg tw-font-semibold tw-text-on-surface">
                    <x-ui.icon name="badge-dollar-sign" class="me-1" /> Today Exchange Rate
                    <x-ui.icon name="info" class="ms-1 text-muted" data-bs-toggle="tooltip" data-bs-title="The latest exchange rate is used for new input. Quotation and PO history keep their own exchange rate snapshots." />
                </h2>
            </x-slot:header>
            <x-slot:actions>
                <x-ui.button type="button" variant="ghost" size="sm" data-bs-toggle="modal" data-bs-target="#kursModal">
                    <x-ui.icon name="square-pen" />
                    Update
                </x-ui.button>
            </x-slot:actions>
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
                    <div class="text-muted text-center mt-2 tw-text-ui-xs">Latest exchange rate update: {{ $lastRateUpdated->format('d M Y') }}</div>
                @endif
        </x-ui.card>
    </div>
    <div>
        <x-ui.data-table title="Nearest PO arrivals" description="Active orders with the closest estimated arrival dates.">
            <x-slot:toolbar>
                <x-ui.button :href="route('purchasing.purchase-orders.index')" variant="ghost" size="sm">View all PO</x-ui.button>
            </x-slot:toolbar>
                    <table class="table table-hover align-middle mb-0 tw-text-ui-sm">
                        <thead class="table-light"><tr><th>PO No.</th><th>Supplier</th><th>Estimated Arrival</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                            @forelse($poTerdekat as $po)
                            <tr>
                                <td class="fw-bold">{{ $po->po_number }}</td>
                                <td>{{ $po->supplier->name }}</td>
                                <td>{{ \Carbon\Carbon::parse($po->estimated_arrival)->format('d M Y') }}</td>
                                <td><x-status-badge type="po" :status="$po->status" :is-overdue="$po->is_overdue ?? false" /></td>
                                <td class="text-end"><a href="{{ \App\Support\PurchasingNavigation::toRoute('purchasing.purchase-orders.show', $po) }}" class="btn btn-sm btn-outline-info"><x-ui.icon name="eye" /></a></td>
                            </tr>
                            @empty<tr><td colspan="5" class="text-center text-muted py-3">No active PO.</td></tr>@endforelse
                        </tbody>
                    </table>
        </x-ui.data-table>
    </div>
</div>
</div>

{{-- Exchange Rate Modal --}}
<div class="modal fade" id="kursModal" tabindex="-1" aria-labelledby="purchasingKursModalTitle" aria-hidden="true"><div class="modal-dialog modal-sm"><div class="modal-content">
    <form action="{{ route('purchasing.kurs.update') }}" method="POST">@csrf
        <div class="modal-header"><h6 class="modal-title fw-bold" id="purchasingKursModalTitle">Update Exchange Rate</h6><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
            <div class="mb-3">
                <label class="form-label small fw-bold" for="exchange-rate-currency">Currency</label>
                <select name="currency" id="exchange-rate-currency" class="form-select form-select-sm" required>
                    @foreach(\App\Models\ExchangeRate::CURRENCY_LABELS as $code => $label)
                        <option value="{{ $code }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-bold" for="exchange-rate-value">
                    Rate to IDR
                    <x-ui.icon name="info" class="ms-1 text-muted" data-bs-toggle="tooltip" data-bs-title="New exchange rate is saved as new history, not overwriting the old one." />
                </label>
                <input type="number" step="0.01" name="rate_to_idr" id="exchange-rate-value" class="form-control form-control-sm" required placeholder="16500">
            </div>
        </div>
        <div class="modal-footer"><button type="submit" class="btn btn-primary btn-sm w-100">Save</button></div>
    </form>
</div></div></div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
        const dashboardThemeStyles = getComputedStyle(document.documentElement);
        const dashboardThemeColor = (token) => dashboardThemeStyles.getPropertyValue(token).trim();
        const dashboardThemeRgba = (token, alpha) => `rgba(${dashboardThemeColor(token)}, ${alpha})`;

        const commonTooltip = {
            backgroundColor: dashboardThemeColor('--md-surface'),
            titleColor: dashboardThemeColor('--md-on-surface'),
            bodyColor: dashboardThemeColor('--md-on-surface-variant'),
            borderColor: dashboardThemeColor('--md-outline'),
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
                    backgroundColor: dashboardThemeRgba('--md-primary-rgb', .7),
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
            $statusColorTokens = [
                'active' => '--md-primary',
                'waiting_qc' => '--md-warning',
                'completed' => '--md-success',
                'overdue' => '--md-error',
                'claim_needed' => '--md-error',
                'cancelled' => '--md-secondary',
            ];
            $chartLabels = [];
            $chartData = [];
            $chartColorTokens = [];
            foreach($poStatusDist as $status => $count) {
                $chartLabels[] = ucwords(str_replace('_', ' ', $status));
                $chartData[] = $count;
                $chartColorTokens[] = $statusColorTokens[$status] ?? '--md-secondary';
            }
        @endphp
        new Chart(document.getElementById('poDonut'), {
            type: 'doughnut',
            data: {
                labels: {!! json_encode($chartLabels) !!},
                datasets: [{
                    data: {!! json_encode($chartData) !!},
                    backgroundColor: {!! json_encode($chartColorTokens) !!}.map(dashboardThemeColor),
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
