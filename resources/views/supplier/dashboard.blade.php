@extends('layouts.app')

@section('title', __('supplier.copy.supplier_dashboard_adasi_portal'))
@section('page-title', __('supplier.copy.supplier_dashboard'))

@section('content')
<div class="tw-grid tw-gap-4">
    {{-- Page Header --}}
    <x-ui.page-header
        :title="__('supplier.copy.supplier_dashboard')"
        :eyebrow="__('supplier.copy.supplier_portal')"
        :description="__('supplier.copy.focus_on_open_quotation_opportunities_active_orders_and_quality_claims_requiring_your_response')"
    />

    {{-- Operational Action Banner if unresponded PRs exist --}}
    @if($belumDirespons > 0)
        <x-ui.alert tone="warning" :title="__('supplier.copy.quotation_response_required')">
            <div class="tw-flex tw-flex-col tw-gap-3 sm:tw-flex-row sm:tw-items-center sm:tw-justify-between">
                <span>{{ trans_choice('supplier.audit_ui.quotation_opportunities', $belumDirespons, ['count' => $belumDirespons]) }}</span>
                <x-ui.button :href="route('supplier.quotations.index')" size="sm">
                    {{ __('supplier.copy.view_opportunities') }}
                    <x-slot:trailing><x-ui.icon name="arrow-right" /></x-slot:trailing>
                </x-ui.button>
            </div>
        </x-ui.alert>
    @endif

    <x-ui.dashboard-layout audience="supplier.import">
        <x-slot:quotations class="lg:tw-col-span-8">
<x-ui.data-table
                :title="__('supplier.copy.action_required_requisitions_awaiting_quotation')"
                :description="__('supplier.copy.open_procurement_opportunities_from_adasi_purchasing_available_for_your_bid')"
                :empty="$prBelumRespons->isEmpty()"
            >
                <x-slot:toolbar>
                    <x-ui.button :href="route('supplier.quotations.index')" variant="ghost" size="sm">
                        <span>{{ __('supplier.copy.view_all_requisitions') }}</span>
                        <x-ui.icon name="arrow-right" size="sm" />
                    </x-ui.button>
                </x-slot:toolbar>

                <x-slot:emptyState>
                    <x-ui.empty-state
                        icon="check-circle"
                        :title="__('supplier.copy.all_caught_up')"
                        :description="__('supplier.copy.you_have_responded_to_all_open_requisitions_in_the_active_periods')"
                    />
                </x-slot:emptyState>

                <table class="table table-hover align-middle mb-0 tw-text-ui-xs w-100">
                    <thead class="table-light">
                        <tr>
                            <th scope="col">{{ __('supplier.copy.pr_number') }}</th>
                            <th scope="col">{{ __('supplier.copy.period') }}</th>
                            <th scope="col" class="text-center">{{ __('supplier.copy.items') }}</th>
                            <th scope="col">{{ __('supplier.copy.date_issued') }}</th>
                            <th scope="col" class="text-end" style="width: 140px;">{{ __('supplier.copy.action') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($prBelumRespons as $pr)
                            <tr>
                                <td class="fw-bold text-primary">{{ $pr->pr_number ?? '-' }}</td>
                                <td class="fw-medium tw-text-on-surface">{{ $pr->period->display_label ?? $pr->period->name }}</td>
                                <td class="text-center fw-semibold ui-tabular-nums">{{ trans_choice('purchasing.copy.item_count', $pr->items->count(), ['count' => $pr->items->count()]) }}</td>
                                <td class="tw-text-on-surface-variant ui-tabular-nums">{{ $regionalFormatter->timestamp($pr->created_at, 'date') }}</td>
                                <td class="text-end">
                                    <x-ui.button :href="route('supplier.quotations.create', $pr)" size="sm">
                                        <x-ui.icon name="square-pen" size="sm" />
                                        <span>{{ __('supplier.copy.quote_price') }}</span>
                                    </x-ui.button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-ui.data-table>
        </x-slot:quotations>

        <x-slot:metrics class="lg:tw-col-span-8">
            {{-- Restrained operational summary follows the primary action queue. --}}
            <div class="tw-grid tw-gap-px tw-overflow-hidden tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-outline-variant sm:tw-grid-cols-2 xl:tw-grid-cols-4" aria-label="{{ __('supplier.copy.supplier_operational_summary') }}">
                <x-ui.metric-card flat :label="__('supplier.copy.active_periods')" :value="$regionalFormatter->number((string) ($periodeAktif), 'plain')" icon="calendar" tone="neutral" :href="route('supplier.quotations.index')" />
                <x-ui.metric-card flat :label="__('supplier.copy.awaiting_quotation')" :value="$regionalFormatter->number((string) ($belumDirespons), 'plain')" icon="clock" :tone="$belumDirespons > 0 ? 'error' : 'neutral'" :href="route('supplier.quotations.index')" />
                <x-ui.metric-card flat :label="__('supplier.copy.submitted_this_month')" :value="$regionalFormatter->number((string) ($penawaranTerkirim), 'plain')" icon="send" tone="success" :href="route('supplier.quotations.index')" />
                <x-ui.metric-card flat :label="__('supplier.copy.received_pos')" :value="$regionalFormatter->number((string) ($poDiterima), 'plain')" icon="receipt" tone="primary" :href="route('supplier.purchase-orders.index')" />
            </div>
        </x-slot:metrics>

        <x-slot:orders class="lg:tw-col-span-8">
<x-ui.data-table
                :title="__('supplier.copy.latest_purchase_orders')"
                :description="__('supplier.copy.active_orders_and_recent_deliveries_issued_to_your_supplier_account')"
                :empty="$poTerbaru->isEmpty()"
            >
                <x-slot:toolbar>
                    <x-ui.button :href="route('supplier.purchase-orders.index')" variant="ghost" size="sm">
                        <span>{{ __('supplier.copy.all_pos') }}</span>
                        <x-ui.icon name="arrow-right" size="sm" />
                    </x-ui.button>
                </x-slot:toolbar>

                <x-slot:emptyState>
                    <x-ui.empty-state
                        icon="receipt"
                        :title="__('supplier.copy.no_purchase_orders_yet')"
                        :description="__('supplier.copy.purchase_orders_will_appear_here_once_your_quotations_are_accepted_by_purchasing')"
                    />
                </x-slot:emptyState>

                <table class="table table-hover align-middle mb-0 tw-text-ui-xs w-100">
                    <thead class="table-light">
                        <tr>
                            <th scope="col">{{ __('supplier.copy.po_number') }}</th>
                            <th scope="col">{{ __('supplier.copy.period') }}</th>
                            <th scope="col">{{ __('supplier.copy.status') }}</th>
                            <th scope="col">{{ __('supplier.copy.po_date') }}</th>
                            <th scope="col" class="text-end" style="width: 130px;">{{ __('supplier.copy.action') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($poTerbaru as $po)
                            @php
                                $pendingClaim = $po->materialClaims->where('status', 'pending')->sortByDesc('created_at')->first();
                                $latestClaim = $po->materialClaims->sortByDesc('created_at')->first();
                            @endphp
                            <tr>
                                <td class="fw-bold tw-text-on-surface">{{ $po->po_number }}</td>
                                <td class="tw-text-on-surface-variant">{{ $po->quotations->map(fn($q) => optional(optional($q->purchaseRequisition)->period)->display_label)->filter()->first() ?? '-' }}</td>
                                <td><x-status-badge type="po" :status="$po->status" :is-overdue="$po->is_overdue" /></td>
                                <td class="tw-text-on-surface-variant ui-tabular-nums">{{ $regionalFormatter->timestamp($po->created_at, 'date') }}</td>
                                <td class="text-end">
                                    <div class="d-inline-flex gap-1 justify-content-end align-items-center">
                                        @if($pendingClaim)
                                            <x-ui.icon-button :href="route('supplier.claims.show', $pendingClaim)" icon="reply" :label="__('supplier.copy.respond_to_ng_claim')" variant="danger" size="sm" />
                                        @elseif($latestClaim)
                                            <x-ui.icon-button :href="route('supplier.claims.show', $latestClaim)" icon="octagon-alert" :label="__('supplier.copy.view_claim')" variant="danger" size="sm" />
                                        @endif
                                        <x-ui.icon-button :href="route('supplier.purchase-orders.show', $po)" icon="eye" :label="__('supplier.copy.view_po_details')" size="sm" />
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-ui.data-table>
        </x-slot:orders>

        <x-slot:updates class="lg:tw-col-span-4">
        <aside class="tw-grid tw-gap-4">
            {{-- ADASI Announcements Card --}}
            <x-ui.card :title="__('supplier.copy.adasi_announcements')" padding="none">
                <div class="list-group list-group-flush">
                    @forelse($announcements as $ann)
                        <div class="list-group-item p-3 border-bottom">
                            <h6 class="mb-1 fw-bold tw-text-ui-xs">
                                <a href="{{ route('supplier.announcements.show', $ann->id) }}" class="text-decoration-none tw-text-on-surface hover:tw-text-primary">
                                    {{ $ann->title }}
                                </a>
                            </h6>
                            <div class="tw-text-on-surface-variant tw-text-ui-xs tw-mb-1.5">{{ Str::limit($ann->content, 90) }}</div>
                            <div class="tw-text-outline tw-text-ui-xs d-flex align-items-center gap-1">
                                <x-ui.icon name="clock" size="sm" />
                                <span>{{ $ann->published_at->diffForHumans() }}</span>
                            </div>
                        </div>
                    @empty
                        <div class="p-4 text-center tw-text-outline tw-text-ui-xs">
                            {{ __('supplier.copy.no_announcements_published_yet') }}
                        </div>
                    @endforelse
                </div>

                @if($announcements->count() > 0)
                    <x-slot:footer>
                        <x-ui.button :href="route('supplier.announcements.index')" variant="ghost" size="sm" class="tw-w-full">
                            <span>{{ __('supplier.copy.view_all_announcements') }}</span>
                            <x-ui.icon name="arrow-right" size="sm" />
                        </x-ui.button>
                    </x-slot:footer>
                @endif
            </x-ui.card>

            {{-- Supplier Support & Direct Negotiation Card --}}
            <x-ui.card :title="__('supplier.copy.purchasing_support')">
                <p class="tw-text-on-surface-variant tw-text-ui-xs mb-3">
                    {{ __('supplier.copy.have_questions_about_procurement_specifications_schedules_or_technical_tolerances_connect_with_the_a') }}
                </p>
                <x-ui.button :href="route('supplier.conversations.index')" variant="outline" size="sm" class="tw-w-full">
                    <x-ui.icon name="message-square" size="sm" />
                    <span>{{ __('supplier.copy.open_negotiations') }}</span>
                </x-ui.button>
            </x-ui.card>
        </aside>
        </x-slot:updates>
    </x-ui.dashboard-layout>
</div>
@endsection
