@extends('layouts.app')
@section('title', __('purchasing.copy.purchasing_dashboard_adasi_portal'))
@section('page-title', __('purchasing.copy.purchasing_dashboard'))

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
        :title="__('purchasing.copy.purchasing_dashboard')"
        :eyebrow="__('purchasing.copy.operations_overview')"
        :description="__('purchasing.copy.monitor_immediate_operational_actions_requisition_volume_active_orders_and_currency_rates')"
    >
        <x-slot:actions>
            <x-ui.button :href="\App\Support\PurchasingNavigation::toRoute('purchasing.requisitions.create')" size="sm">
                <x-ui.icon name="plus-circle" size="sm" />
                {{ __('purchasing.copy.create_requisition') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.dashboard-layout audience="purchasing">
        <x-slot:exceptions>
{{-- 1. Operational Action Queue (What needs Purchasing attention now?) --}}
    @php
        $totalActionRequired = collect($operationalChecks)->sum('count');
    @endphp
    <x-ui.data-table
        :title="__('purchasing.copy.operational_action_queue')"
        :description="__('purchasing.copy.priority_items_and_workflow_exceptions_requiring_immediate_purchasing_review_or_follow_up')"
    >
        <x-slot:toolbar>
            @if($totalActionRequired > 0)
                <span class="role-badge role-badge-qc">
                    {{ trans_choice('purchasing.summary.actions_required', $totalActionRequired, ['count' => $totalActionRequired]) }}
                </span>
            @else
                <span class="role-badge role-badge-purchasing">{{ __('purchasing.copy.no_items_require_action') }}</span>
            @endif
        </x-slot:toolbar>

        <table class="table table-hover align-middle mb-0 op-queue-table tw-text-ui-sm">
            <thead class="table-light">
                <tr>
                    <th scope="col">{{ __('purchasing.copy.operational_checklist_item') }}</th>
                    <th scope="col" class="tw-w-40 text-center">{{ __('purchasing.copy.count_severity') }}</th>
                    <th scope="col">{{ __('purchasing.copy.description_workflow_impact') }}</th>
                    <th scope="col" class="tw-w-32 text-end">{{ __('purchasing.copy.action') }}</th>
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
                                    {{ trans_choice('purchasing.summary.pending_count', $check['count'], ['count' => $check['count']]) }}
                                </span>
                            @else
                                <span class="ui-status-chip ui-status-chip--neutral ui-tabular-nums">
                                    {{ __('purchasing.copy.0_safe') }}
                                </span>
                            @endif
                        </td>
                        <td class="tw-text-on-surface-variant tw-text-ui-xs">
                            {{ $check['description'] }}
                        </td>
                        <td class="text-end">
                            <x-ui.button :href="$check['url']" size="sm" variant="{{ $check['count'] > 0 ? 'outline' : 'ghost' }}">
                                <span>{{ __('purchasing.copy.review') }}</span>
                                <x-ui.icon name="arrow-right" size="sm" />
                            </x-ui.button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-ui.data-table>
        </x-slot:exceptions>

        <x-slot:metrics>
{{-- 2. Restrained Operational Summary Metric Strip --}}
    <div class="tw-grid tw-gap-px tw-overflow-hidden tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-outline-variant sm:tw-grid-cols-2 xl:tw-grid-cols-4" aria-label="{{ __('purchasing.copy.purchasing_operational_summary') }}">
        <x-ui.metric-card
            flat
            :label="__('purchasing.copy.active_requisitions')"
            :value="$regionalFormatter->number((string) ($prAktif), 'plain')"
            icon="clipboard-list"
            tone="neutral"
            :href="route('purchasing.requisitions.index', ['status' => 'submitted'])"
        />
        <x-ui.metric-card
            flat
            :label="__('purchasing.copy.waiting_for_quotation')"
            :value="$regionalFormatter->number((string) ($menungguPenawaran), 'plain')"
            icon="hourglass"
            tone="{{ $menungguPenawaran > 0 ? 'warning' : 'neutral' }}"
            :href="route('purchasing.requisitions.index', ['status' => 'bidding'])"
        />
        <x-ui.metric-card
            flat
            :label="__('purchasing.copy.active_purchase_orders')"
            :value="$regionalFormatter->number((string) ($poBerjalan), 'plain')"
            icon="receipt"
            tone="primary"
            :href="route('purchasing.purchase-orders.index', ['status' => 'active'])"
        />
        <x-ui.metric-card
            flat
            :label="__('purchasing.copy.arriving_this_week')"
            :value="$regionalFormatter->number((string) ($materialMingguIni), 'plain')"
            icon="truck"
            tone="{{ $materialMingguIni > 0 ? 'info' : 'neutral' }}"
            :href="route('purchasing.purchase-orders.index', ['arrival' => 'this_week'])"
        />
    </div>
        </x-slot:metrics>

        <x-slot:analytics>
{{-- 3. Analytics & Quick Reference --}}
    <div class="tw-grid tw-gap-5 lg:tw-grid-cols-[minmax(0,2fr)_minmax(19rem,1fr)]">
        {{-- PR Monthly Inflow Chart --}}
        <x-ui.card
            :title="__('purchasing.copy.purchase_requisitions_trend')"
            :description="__('purchasing.copy.monthly_creation_volume_over_the_past_6_month_window')"
            class="tw-min-w-0"
        >
            <div class="tw-h-[16rem]">
                <canvas id="prChart" role="img" aria-label="{{ __('purchasing.copy.purchase_requisition_volume_by_month') }}">{{ __('purchasing.copy.monthly_requisition_volume_trend_chart') }}</canvas>
            </div>
        </x-ui.card>

        {{-- PO Status Distribution & Exchange Rate --}}
        <div class="tw-grid tw-gap-5">
            <x-ui.card
                :title="__('purchasing.copy.po_workload_distribution')"
                :description="__('purchasing.copy.current_purchase_order_states')"
                class="tw-min-w-0"
            >
                <div class="tw-flex tw-min-h-[11rem] tw-items-center tw-justify-center">
                    @if(count($poStatusDist) > 0)
                        <div class="tw-h-[10.5rem] tw-w-[10.5rem]">
                            <canvas id="poDonut" role="img" aria-label="{{ __('purchasing.copy.purchase_order_status_distribution') }}">{{ __('purchasing.copy.po_status_distribution_chart') }}</canvas>
                        </div>
                    @else
                        <x-ui.empty-state icon="pie-chart" :title="__('purchasing.copy.no_po_status_data')" :description="__('purchasing.copy.status_distribution_will_appear_once_purchase_orders_are_created')" />
                    @endif
                </div>
            </x-ui.card>

            {{-- Exchange Rate Quick Reference --}}
            <x-ui.card :title="__('purchasing.copy.exchange_rate_benchmark')">
                <x-slot:actions>
                    <x-ui.button type="button" variant="ghost" size="sm" data-bs-toggle="modal" data-bs-target="#kursModal">
                        <x-ui.icon name="square-pen" />
                        {{ __('purchasing.copy.update_rate') }}
                    </x-ui.button>
                </x-slot:actions>
                <div class="row g-2">
                    @foreach(\App\Models\ExchangeRate::CURRENCIES as $currency)
                        @php $rate = $latestRates[$currency] ?? null; @endphp
                        <div class="col-6">
                            <div class="tw-p-2.5 tw-bg-surface-low border rounded text-center">
                                <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ $currency }} → IDR</div>
                                <div class="fw-bold tw-text-on-surface fs-6 tw-mt-0.5">Rp {{ $rate ? $regionalFormatter->number(number_format($rate->rate_to_idr, 0, ',', '.'), 'indonesian') : '-' }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>
                @php $lastRateUpdated = $latestRates->filter()->sortByDesc('valid_from')->first()?->valid_from; @endphp
                @if($lastRateUpdated)
                    <div class="tw-text-outline text-center mt-2 tw-text-ui-xs">{{ __('purchasing.page.rate_updated', ['date' => $regionalFormatter->date($lastRateUpdated, 'human')]) }}</div>
                @endif
            </x-ui.card>
        </div>
    </div>
        </x-slot:analytics>

        <x-slot:recent_requisitions class="lg:tw-col-span-5">
<x-ui.data-table :title="__('purchasing.copy.recent_requisitions')" :description="__('purchasing.copy.most_recently_created_purchase_requisitions')">
            <x-slot:toolbar>
                <x-ui.button :href="route('purchasing.requisitions.index')" variant="ghost" size="sm">
                    <span>{{ __('purchasing.copy.view_all') }}</span>
                    <x-ui.icon name="arrow-right" size="sm" />
                </x-ui.button>
            </x-slot:toolbar>
            <table class="table table-hover align-middle mb-0 tw-text-ui-sm">
                <thead class="table-light">
                    <tr>
                        <th scope="col">{{ __('purchasing.copy.pr_no') }}</th>
                        <th scope="col">{{ __('purchasing.copy.period') }}</th>
                        <th scope="col">{{ __('purchasing.copy.status') }}</th>
                        <th scope="col" class="text-end">{{ __('purchasing.copy.action') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($prTerbaru as $pr)
                        <tr>
                            <td class="fw-bold tw-text-on-surface">{{ $pr->pr_number ?? __('purchasing.copy.draft') }}</td>
                            <td>{{ $pr->period->display_label ?? $pr->period->name }}</td>
                            <td><x-status-badge type="pr" :status="$pr->status" /></td>
                            <td class="text-end">
                                <x-ui.icon-button :href="\App\Support\PurchasingNavigation::toRoute('purchasing.requisitions.show', $pr)" icon="eye" :label="__('purchasing.copy.view_requisition_details')" size="sm" />
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted py-3">{{ __('purchasing.copy.no_requisition_records_available') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.data-table>
        </x-slot:recent_requisitions>

        <x-slot:arrivals class="lg:tw-col-span-7">
<x-ui.data-table :title="__('purchasing.copy.upcoming_po_arrivals')" :description="__('purchasing.copy.active_orders_with_closest_estimated_arrival_dates')">
            <x-slot:toolbar>
                <x-ui.button :href="route('purchasing.purchase-orders.index')" variant="ghost" size="sm">
                    <span>{{ __('purchasing.copy.view_all_po') }}</span>
                    <x-ui.icon name="arrow-right" size="sm" />
                </x-ui.button>
            </x-slot:toolbar>
            <table class="table table-hover align-middle mb-0 tw-text-ui-sm">
                <thead class="table-light">
                    <tr>
                        <th scope="col">{{ __('purchasing.copy.po_no') }}</th>
                        <th scope="col">{{ __('purchasing.copy.supplier') }}</th>
                        <th scope="col">{{ __('purchasing.copy.estimated_arrival') }}</th>
                        <th scope="col">{{ __('purchasing.copy.status') }}</th>
                        <th scope="col" class="text-end">{{ __('purchasing.copy.action') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($poTerdekat as $po)
                        <tr>
                            <td class="fw-bold tw-text-on-surface">{{ $po->po_number }}</td>
                            <td>{{ $po->supplier->name }}</td>
                            <td>{{ $regionalFormatter->date($po->estimated_arrival, 'human') }}</td>
                            <td><x-status-badge type="po" :status="$po->status" :is-overdue="$po->is_overdue ?? false" /></td>
                            <td class="text-end">
                                <x-ui.icon-button :href="\App\Support\PurchasingNavigation::toRoute('purchasing.purchase-orders.show', $po)" icon="eye" :label="__('purchasing.copy.view_po_details')" size="sm" />
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-3">{{ __('purchasing.copy.no_active_upcoming_purchase_orders') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.data-table>
        </x-slot:arrivals>
    </x-ui.dashboard-layout>
</div>

{{-- Exchange Rate Update Modal --}}
<div class="modal fade" id="kursModal" tabindex="-1" aria-labelledby="purchasingKursModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <form action="{{ route('purchasing.kurs.update') }}" method="POST">
                @csrf
                <div class="modal-header">
                    <h6 class="modal-title fw-bold" id="purchasingKursModalTitle">{{ __('purchasing.copy.update_exchange_rate') }}</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('purchasing.copy.close') }}"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-bold" for="exchange-rate-currency">{{ __('purchasing.copy.currency') }}</label>
                        <select name="currency" id="exchange-rate-currency" class="form-select form-select-sm" required>
                            @foreach(\App\Models\ExchangeRate::currencyOptions() as $code => $label)
                                <option value="{{ $code }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold" for="exchange-rate-value">
                            {{ __('purchasing.copy.rate_to_idr') }}
                            <x-ui.icon name="info" class="ms-1 text-muted" data-bs-toggle="tooltip" data-bs-title="{{ __('purchasing.copy.new_exchange_rate_is_saved_as_a_new_historical_record_preserving_past_snapshots') }}" />
                        </label>
                        <input type="number" step="0.01" name="rate_to_idr" id="exchange-rate-value" class="form-control form-control-sm" required placeholder="16500">
                    </div>
                </div>
                <div class="modal-footer">
                    <x-ui.button type="submit" size="sm" class="tw-w-full">{{ __('purchasing.copy.save_rate') }}</x-ui.button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    const displayDashboardNumber = (text) => window.AdasiPreferences?.displayNumber
        ? window.AdasiPreferences.displayNumber(text, 'indonesian') : text;
    const colors = window.AdasiChart ? window.AdasiChart.getColors() : {
        primary: '#1F5FA6',
        surface: '#FFFFFF',
        onSurface: '#1A202C',
        onSurfaceVariant: '#64748B',
        gridLine: 'rgba(226, 232, 240, 0.75)',
        warning: '#D35400',
        success: '#1E8449',
        error: '#C0392B',
        secondary: '#476072',
    };

    const prCanvas = document.getElementById('prChart');
    if (prCanvas) {
        new Chart(prCanvas, {
            type: 'bar',
            data: {
                labels: {!! json_encode(array_column($prPerBulan, 'label')) !!},
                datasets: [{
                    label: window.AdasiI18n.t('purchasing.js.requisitions'),
                    data: {!! json_encode(array_column($prPerBulan, 'count')) !!},
                    backgroundColor: (context) => window.AdasiChart?.createBarGradient(context, colors.primary, 0.95, 0.3) || colors.primary,
                    borderColor: colors.primary,
                    borderWidth: 1,
                    borderRadius: 6,
                    borderSkipped: false,
                    maxBarThickness: 32,
                    barPercentage: 0.55,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 400 },
                plugins: {
                    legend: { display: false },
                    tooltip: window.AdasiChart?.getTooltip({
                        callbacks: {
                            label: (ctx) => ' ' + window.AdasiI18n.choice('purchasing.js.requisition_count', Number(ctx.parsed.y), { count: displayDashboardNumber(Number(ctx.parsed.y).toLocaleString('id-ID')) }),
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

    @if(count($poStatusDist) > 0)
    @php
        $chartLabels = [];
        $chartData = [];
        $chartStatuses = [];
        foreach($poStatusDist as $status => $count) {
            $chartLabels[] = \App\Support\StatusHelper::poLabel($status);
            $chartData[] = $count;
            $chartStatuses[] = $status;
        }
    @endphp
    const poStatusColors = {
        active: colors.primary,
        waiting_qc: colors.warning,
        completed: colors.success,
        overdue: colors.error,
        claim_needed: colors.error,
        cancelled: colors.secondary,
    };
    const poCanvas = document.getElementById('poDonut');
    if (poCanvas) {
        new Chart(poCanvas, {
            type: 'doughnut',
            data: {
                labels: {!! json_encode($chartLabels) !!},
                datasets: [{
                    data: {!! json_encode($chartData) !!},
                    backgroundColor: @json($chartStatuses).map((status) => poStatusColors[status] || colors.secondary),
                    borderWidth: 3,
                    borderColor: colors.surface,
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
                    legend: {
                        position: 'bottom',
                        labels: {
                            boxWidth: 8,
                            boxHeight: 8,
                            usePointStyle: true,
                            font: { family: 'Inter', size: 11, weight: '500' },
                            color: colors.onSurfaceVariant,
                            padding: 12,
                        }
                    },
                    tooltip: window.AdasiChart?.getTooltip({
                        callbacks: {
                            label: (ctx) => ' ' + ctx.label + ': ' + window.AdasiI18n.choice('purchasing.js.purchase_order_count', Number(ctx.parsed), {
                                count: displayDashboardNumber(Number(ctx.parsed).toLocaleString('id-ID')),
                            }),
                        }
                    }) || {},
                }
            }
        });
    }
    @endif
</script>
@endpush
