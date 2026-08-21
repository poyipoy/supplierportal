@extends('layouts.app')
@section('title', 'Purchasing Dashboard - ADASI Portal')
@section('page-title', 'Purchasing Dashboard')

@push('styles')
<style>
    .op-queue-table td {
        padding-top: 0.75rem !important;
        padding-bottom: 0.75rem !important;
    }
</style>
@endpush

@section('content')
<div class="tw-grid tw-gap-5">
    {{-- Page Header --}}
    <x-ui.page-header
        title="Purchasing Dashboard"
        eyebrow="Operations Overview"
        description="Monitor immediate operational actions, requisition volume, active orders, and currency rates."
    >
        <x-slot:actions>
            <x-ui.button :href="\App\Support\PurchasingNavigation::toRoute('purchasing.requisitions.create')" size="sm">
                <x-ui.icon name="plus-circle" />
                Create Requisition
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- 1. Operational Action Queue (What needs Purchasing attention now?) --}}
    @php
        $totalActionRequired = collect($operationalChecks)->sum('count');
    @endphp
    <x-ui.data-table
        title="Operational Action Queue"
        description="Priority items and workflow exceptions requiring immediate Purchasing review or follow-up."
    >
        <x-slot:toolbar>
            @if($totalActionRequired > 0)
                <span class="role-badge role-badge-qc">
                    {{ $totalActionRequired }} Action{{ $totalActionRequired > 1 ? 's' : '' }} Required
                </span>
            @else
                <span class="role-badge role-badge-purchasing">No Items Require Action</span>
            @endif
        </x-slot:toolbar>

        <table class="table table-hover align-middle mb-0 op-queue-table tw-text-ui-sm">
            <thead class="table-light">
                <tr>
                    <th scope="col">Operational Checklist Item</th>
                    <th scope="col" class="tw-w-40 text-center">Count / Severity</th>
                    <th scope="col">Description &amp; Workflow Impact</th>
                    <th scope="col" class="tw-w-32 text-end">Action</th>
                </tr>
            </thead>
            <tbody>
                @foreach($operationalChecks as $check)
                    @php
                        $checkTone = $check['class'] === 'danger' ? 'error' : ($check['class'] === 'warning' ? 'warning' : ($check['class'] === 'success' ? 'success' : 'info'));
                        $checkIconClass = $checkTone === 'error' ? 'tw-text-error' : ($checkTone === 'warning' ? 'tw-text-warning' : ($checkTone === 'success' ? 'tw-text-success' : 'tw-text-primary'));
                    @endphp
                    <tr>
                        <td>
                            <div class="d-flex align-items-center tw-gap-2.5">
                                <x-ui.icon :name="$check['icon']" size="md" class="{{ $checkIconClass }} tw-shrink-0" />
                                <span class="fw-bold tw-text-on-surface">{{ $check['label'] }}</span>
                            </div>
                        </td>
                        <td class="text-center">
                            @if($check['count'] > 0)
                                <span class="ui-status-chip ui-status-chip--{{ $checkTone }} ui-tabular-nums">
                                    {{ $check['count'] }} Pending
                                </span>
                            @else
                                <span class="ui-status-chip ui-status-chip--neutral ui-tabular-nums">
                                    0 Safe
                                </span>
                            @endif
                        </td>
                        <td class="tw-text-on-surface-variant tw-text-ui-xs">
                            {{ $check['description'] }}
                        </td>
                        <td class="text-end">
                            <x-ui.button :href="$check['url']" size="sm" variant="{{ $check['count'] > 0 ? 'outline' : 'ghost' }}">
                                <span>Review</span>
                                <x-ui.icon name="arrow-right" size="sm" />
                            </x-ui.button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-ui.data-table>

    {{-- 2. Restrained Operational Summary Metric Strip --}}
    <div class="tw-grid tw-gap-px tw-overflow-hidden tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-outline-variant sm:tw-grid-cols-2 xl:tw-grid-cols-4" aria-label="Purchasing operational summary">
        <x-ui.metric-card
            flat
            label="Active Requisitions"
            :value="$prAktif"
            icon="clipboard-list"
            tone="neutral"
            :href="route('purchasing.requisitions.index', ['status' => 'submitted'])"
        />
        <x-ui.metric-card
            flat
            label="Waiting for Quotation"
            :value="$menungguPenawaran"
            icon="hourglass"
            tone="{{ $menungguPenawaran > 0 ? 'warning' : 'neutral' }}"
            :href="route('purchasing.requisitions.index', ['status' => 'bidding'])"
        />
        <x-ui.metric-card
            flat
            label="Active Purchase Orders"
            :value="$poBerjalan"
            icon="receipt"
            tone="primary"
            :href="route('purchasing.purchase-orders.index', ['status' => 'active'])"
        />
        <x-ui.metric-card
            flat
            label="Arriving This Week"
            :value="$materialMingguIni"
            icon="truck"
            tone="{{ $materialMingguIni > 0 ? 'info' : 'neutral' }}"
            :href="route('purchasing.purchase-orders.index', ['arrival' => 'this_week'])"
        />
    </div>

    {{-- 3. Analytics & Quick Reference --}}
    <div class="tw-grid tw-gap-5 lg:tw-grid-cols-[minmax(0,2fr)_minmax(19rem,1fr)]">
        {{-- PR Monthly Inflow Chart --}}
        <x-ui.card
            title="Purchase Requisitions Trend"
            description="Monthly creation volume over the past 6-month window."
            class="tw-min-w-0"
        >
            <div class="tw-h-[16rem]">
                <canvas id="prChart" role="img" aria-label="Purchase requisition volume by month">Monthly requisition volume trend chart.</canvas>
            </div>
        </x-ui.card>

        {{-- PO Status Distribution & Exchange Rate --}}
        <div class="tw-grid tw-gap-5">
            <x-ui.card
                title="PO Workload Distribution"
                description="Current purchase order states."
                class="tw-min-w-0"
            >
                <div class="tw-flex tw-min-h-[11rem] tw-items-center tw-justify-center">
                    @if(count($poStatusDist) > 0)
                        <div class="tw-h-[10.5rem] tw-w-[10.5rem]">
                            <canvas id="poDonut" role="img" aria-label="Purchase order status distribution">PO status distribution chart.</canvas>
                        </div>
                    @else
                        <x-ui.empty-state icon="pie-chart" title="No PO status data" description="Status distribution will appear once purchase orders are created." />
                    @endif
                </div>
            </x-ui.card>

            {{-- Exchange Rate Quick Reference --}}
            <x-ui.card title="Exchange Rate Benchmark">
                <x-slot:actions>
                    <x-ui.button type="button" variant="ghost" size="sm" data-bs-toggle="modal" data-bs-target="#kursModal">
                        <x-ui.icon name="square-pen" />
                        Update Rate
                    </x-ui.button>
                </x-slot:actions>
                <div class="row g-2">
                    @foreach(\App\Models\ExchangeRate::CURRENCIES as $currency)
                        @php $rate = $latestRates[$currency] ?? null; @endphp
                        <div class="col-6">
                            <div class="tw-p-2.5 tw-bg-surface-low border rounded text-center">
                                <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ $currency }} → IDR</div>
                                <div class="fw-bold tw-text-on-surface fs-6 tw-mt-0.5">Rp {{ $rate ? number_format($rate->rate_to_idr, 0, ',', '.') : '-' }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>
                @php $lastRateUpdated = $latestRates->filter()->sortByDesc('valid_from')->first()?->valid_from; @endphp
                @if($lastRateUpdated)
                    <div class="tw-text-outline text-center mt-2 tw-text-ui-xs">Latest update: {{ $lastRateUpdated->format('d M Y') }}</div>
                @endif
            </x-ui.card>
        </div>
    </div>

    {{-- 4. Recent Operational Records (Latest 5 PRs & Nearest PO Arrivals) --}}
    <div class="tw-grid tw-items-start tw-gap-5 lg:tw-grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)]">
        {{-- Latest PRs --}}
        <x-ui.data-table title="Recent Requisitions" description="Most recently created purchase requisitions.">
            <x-slot:toolbar>
                <x-ui.button :href="route('purchasing.requisitions.index')" variant="ghost" size="sm">
                    <span>View all</span>
                    <x-ui.icon name="arrow-right" size="sm" />
                </x-ui.button>
            </x-slot:toolbar>
            <table class="table table-hover align-middle mb-0 tw-text-ui-sm">
                <thead class="table-light">
                    <tr>
                        <th scope="col">PR No.</th>
                        <th scope="col">Period</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($prTerbaru as $pr)
                        <tr>
                            <td class="fw-bold tw-text-on-surface">{{ $pr->pr_number ?? 'DRAFT' }}</td>
                            <td>{{ $pr->period->display_label ?? $pr->period->name }}</td>
                            <td><x-status-badge type="pr" :status="$pr->status" /></td>
                            <td class="text-end">
                                <x-ui.icon-button :href="\App\Support\PurchasingNavigation::toRoute('purchasing.requisitions.show', $pr)" icon="eye" label="View requisition details" size="sm" />
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted py-3">No requisition records available.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.data-table>

        {{-- Nearest PO Arrivals --}}
        <x-ui.data-table title="Upcoming PO Arrivals" description="Active orders with closest estimated arrival dates.">
            <x-slot:toolbar>
                <x-ui.button :href="route('purchasing.purchase-orders.index')" variant="ghost" size="sm">
                    <span>View all PO</span>
                    <x-ui.icon name="arrow-right" size="sm" />
                </x-ui.button>
            </x-slot:toolbar>
            <table class="table table-hover align-middle mb-0 tw-text-ui-sm">
                <thead class="table-light">
                    <tr>
                        <th scope="col">PO No.</th>
                        <th scope="col">Supplier</th>
                        <th scope="col">Estimated Arrival</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($poTerdekat as $po)
                        <tr>
                            <td class="fw-bold tw-text-on-surface">{{ $po->po_number }}</td>
                            <td>{{ $po->supplier->name }}</td>
                            <td>{{ \Carbon\Carbon::parse($po->estimated_arrival)->format('d M Y') }}</td>
                            <td><x-status-badge type="po" :status="$po->status" :is-overdue="$po->is_overdue ?? false" /></td>
                            <td class="text-end">
                                <x-ui.icon-button :href="\App\Support\PurchasingNavigation::toRoute('purchasing.purchase-orders.show', $po)" icon="eye" label="View PO details" size="sm" />
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-3">No active upcoming purchase orders.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.data-table>
    </div>
</div>

{{-- Exchange Rate Update Modal --}}
<div class="modal fade" id="kursModal" tabindex="-1" aria-labelledby="purchasingKursModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <form action="{{ route('purchasing.kurs.update') }}" method="POST">
                @csrf
                <div class="modal-header">
                    <h6 class="modal-title fw-bold" id="purchasingKursModalTitle">Update Exchange Rate</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
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
                            <x-ui.icon name="info" class="ms-1 text-muted" data-bs-toggle="tooltip" data-bs-title="New exchange rate is saved as a new historical record, preserving past snapshots." />
                        </label>
                        <input type="number" step="0.01" name="rate_to_idr" id="exchange-rate-value" class="form-control form-control-sm" required placeholder="16500">
                    </div>
                </div>
                <div class="modal-footer">
                    <x-ui.button type="submit" size="sm" class="tw-w-full">Save Rate</x-ui.button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    const dashboardThemeStyles = getComputedStyle(document.documentElement);
    const dashboardThemeColor = (token) => dashboardThemeStyles.getPropertyValue(token).trim();
    const dashboardColors = {
        surface: dashboardThemeColor('--md-surface'),
        onSurface: dashboardThemeColor('--md-on-surface'),
        onSurfaceVariant: dashboardThemeColor('--md-on-surface-variant'),
        outlineVariant: dashboardThemeColor('--md-outline-variant'),
        outlineStrong: dashboardThemeColor('--md-outline-strong'),
        surfaceContainer: dashboardThemeColor('--md-surface-container'),
        primary: dashboardThemeColor('--md-primary'),
        warning: dashboardThemeColor('--md-warning'),
        success: dashboardThemeColor('--md-success'),
        error: dashboardThemeColor('--md-error'),
        secondary: dashboardThemeColor('--md-secondary'),
    };

    const commonTooltip = {
        backgroundColor: dashboardColors.surface,
        titleColor: dashboardColors.onSurface,
        bodyColor: dashboardColors.onSurfaceVariant,
        borderColor: dashboardColors.outlineVariant,
        borderWidth: 1,
        titleFont: { size: 13, family: 'Inter', weight: 'bold' },
        bodyFont: { size: 12, family: 'Inter' },
        padding: 10,
        cornerRadius: 6,
        boxPadding: 4,
        displayColors: true,
    };

    new Chart(document.getElementById('prChart'), {
        type: 'bar',
        data: {
            labels: {!! json_encode(array_column($prPerBulan, 'label')) !!},
            datasets: [{
                label: 'Requisitions',
                data: {!! json_encode(array_column($prPerBulan, 'count')) !!},
                backgroundColor: dashboardColors.primary,
                borderRadius: 4,
                maxBarThickness: 36
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            plugins: {
                legend: { display: false },
                tooltip: commonTooltip
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { font: { family: 'Inter', size: 11 }, color: dashboardColors.onSurfaceVariant }
                },
                y: {
                    beginAtZero: true,
                    grid: { color: dashboardColors.surfaceContainer },
                    ticks: { stepSize: 1, font: { family: 'Inter', size: 11 }, color: dashboardColors.onSurfaceVariant }
                }
            }
        }
    });

    @if(count($poStatusDist) > 0)
    @php
        $chartLabels = [];
        $chartData = [];
        $chartStatuses = [];
        foreach($poStatusDist as $status => $count) {
            $chartLabels[] = ucwords(str_replace('_', ' ', $status));
            $chartData[] = $count;
            $chartStatuses[] = $status;
        }
    @endphp
    const poStatusColors = {
        active: dashboardColors.primary,
        waiting_qc: dashboardColors.warning,
        completed: dashboardColors.success,
        overdue: dashboardColors.error,
        claim_needed: dashboardColors.error,
        cancelled: dashboardColors.secondary,
    };
    new Chart(document.getElementById('poDonut'), {
        type: 'doughnut',
        data: {
            labels: {!! json_encode($chartLabels) !!},
            datasets: [{
                data: {!! json_encode($chartData) !!},
                backgroundColor: @json($chartStatuses).map((status) => poStatusColors[status] || dashboardColors.outlineStrong),
                borderWidth: 2,
                borderColor: dashboardColors.surface
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            cutout: '70%',
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { boxWidth: 10, boxHeight: 10, font: { family: 'Inter', size: 11 }, color: dashboardColors.onSurfaceVariant, padding: 8 }
                },
                tooltip: commonTooltip
            }
        }
    });
    @endif
</script>
@endpush
