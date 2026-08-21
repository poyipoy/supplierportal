@extends('layouts.app')

@section('title', 'Supplier Dashboard - ADASI Portal')
@section('page-title', 'Supplier Dashboard')

@section('content')
<div class="tw-grid tw-gap-4">
    {{-- Page Header --}}
    <x-ui.page-header
        title="Supplier Dashboard"
        eyebrow="Supplier Portal"
        description="Focus on open quotation opportunities, active orders, and quality claims requiring your response."
    />

    {{-- Operational Action Banner if unresponded PRs exist --}}
    @if($belumDirespons > 0)
        <x-ui.alert tone="warning" title="Quotation response required">
            <div class="tw-flex tw-flex-col tw-gap-3 sm:tw-flex-row sm:tw-items-center sm:tw-justify-between">
                <span>You have <strong>{{ $belumDirespons }} active requisition(s)</strong> awaiting your quotation pricing. Submit before period closing.</span>
                <x-ui.button :href="route('supplier.quotations.index')" size="sm">
                    View Opportunities
                    <x-slot:trailing><x-ui.icon name="arrow-right" /></x-slot:trailing>
                </x-ui.button>
            </div>
        </x-ui.alert>
    @endif

    {{-- Operational Tables & Side Column --}}
    <div class="tw-grid tw-items-start tw-gap-4 lg:tw-grid-cols-[minmax(0,2fr)_minmax(19rem,1fr)]">
        {{-- Main Operational Column --}}
        <div class="tw-grid tw-min-w-0 tw-gap-4">
            {{-- Requisitions Awaiting Quotation Queue --}}
            <x-ui.data-table
                title="Action Required: Requisitions Awaiting Quotation"
                description="Open procurement opportunities from ADASI Purchasing available for your bid."
                :empty="$prBelumRespons->isEmpty()"
            >
                <x-slot:toolbar>
                    <x-ui.button :href="route('supplier.quotations.index')" variant="ghost" size="sm">
                        <span>View All Requisitions</span>
                        <x-ui.icon name="arrow-right" size="sm" />
                    </x-ui.button>
                </x-slot:toolbar>

                <x-slot:emptyState>
                    <x-ui.empty-state
                        icon="check-circle"
                        title="All caught up!"
                        description="You have responded to all open requisitions in the active periods."
                    />
                </x-slot:emptyState>

                <table class="table table-hover align-middle mb-0 tw-text-ui-xs w-100">
                    <thead class="table-light">
                        <tr>
                            <th scope="col">PR Number</th>
                            <th scope="col">Period</th>
                            <th scope="col" class="text-center">Items</th>
                            <th scope="col">Date Issued</th>
                            <th scope="col" class="text-end" style="width: 140px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($prBelumRespons as $pr)
                            <tr>
                                <td class="fw-bold text-primary">{{ $pr->pr_number ?? '-' }}</td>
                                <td class="fw-medium tw-text-on-surface">{{ $pr->period->display_label ?? $pr->period->name }}</td>
                                <td class="text-center fw-semibold ui-tabular-nums">{{ $pr->items->count() }} item(s)</td>
                                <td class="tw-text-on-surface-variant ui-tabular-nums">{{ $pr->created_at->format('d M Y') }}</td>
                                <td class="text-end">
                                    <x-ui.button :href="route('supplier.quotations.create', $pr)" size="sm">
                                        <x-ui.icon name="square-pen" size="sm" />
                                        <span>Quote Price</span>
                                    </x-ui.button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-ui.data-table>

            {{-- Restrained operational summary follows the primary action queue. --}}
            <div class="tw-grid tw-gap-px tw-overflow-hidden tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-outline-variant sm:tw-grid-cols-2 xl:tw-grid-cols-4" aria-label="Supplier operational summary">
                <x-ui.metric-card flat label="Active Periods" :value="$periodeAktif" icon="calendar" tone="neutral" :href="route('supplier.quotations.index')" />
                <x-ui.metric-card flat label="Awaiting Quotation" :value="$belumDirespons" icon="clock" :tone="$belumDirespons > 0 ? 'error' : 'neutral'" :href="route('supplier.quotations.index')" />
                <x-ui.metric-card flat label="Submitted This Month" :value="$penawaranTerkirim" icon="send" tone="success" :href="route('supplier.quotations.index')" />
                <x-ui.metric-card flat label="Received POs" :value="$poDiterima" icon="receipt" tone="primary" :href="route('supplier.purchase-orders.index')" />
            </div>

            {{-- Latest Purchase Orders Tracker --}}
            <x-ui.data-table
                title="Latest Purchase Orders"
                description="Active orders and recent deliveries issued to your supplier account."
                :empty="$poTerbaru->isEmpty()"
            >
                <x-slot:toolbar>
                    <x-ui.button :href="route('supplier.purchase-orders.index')" variant="ghost" size="sm">
                        <span>All POs</span>
                        <x-ui.icon name="arrow-right" size="sm" />
                    </x-ui.button>
                </x-slot:toolbar>

                <x-slot:emptyState>
                    <x-ui.empty-state
                        icon="receipt"
                        title="No purchase orders yet"
                        description="Purchase orders will appear here once your quotations are accepted by Purchasing."
                    />
                </x-slot:emptyState>

                <table class="table table-hover align-middle mb-0 tw-text-ui-xs w-100">
                    <thead class="table-light">
                        <tr>
                            <th scope="col">PO Number</th>
                            <th scope="col">Period</th>
                            <th scope="col">Status</th>
                            <th scope="col">PO Date</th>
                            <th scope="col" class="text-end" style="width: 130px;">Action</th>
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
                                <td class="tw-text-on-surface-variant ui-tabular-nums">{{ $po->created_at->format('d M Y') }}</td>
                                <td class="text-end">
                                    <div class="d-inline-flex gap-1 justify-content-end align-items-center">
                                        @if($pendingClaim)
                                            <x-ui.icon-button :href="route('supplier.claims.show', $pendingClaim)" icon="reply" label="Respond to NG claim" variant="danger" size="sm" />
                                        @elseif($latestClaim)
                                            <x-ui.icon-button :href="route('supplier.claims.show', $latestClaim)" icon="octagon-alert" label="View claim" variant="danger" size="sm" />
                                        @endif
                                        <x-ui.icon-button :href="route('supplier.purchase-orders.show', $po)" icon="eye" label="View PO details" size="sm" />
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-ui.data-table>
        </div>

        {{-- Side Column: Announcements & Quick Links --}}
        <aside class="tw-grid tw-gap-4">
            {{-- ADASI Announcements Card --}}
            <x-ui.card title="ADASI Announcements" padding="none">
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
                            No announcements published yet.
                        </div>
                    @endforelse
                </div>

                @if($announcements->count() > 0)
                    <x-slot:footer>
                        <x-ui.button :href="route('supplier.announcements.index')" variant="ghost" size="sm" class="tw-w-full">
                            <span>View All Announcements</span>
                            <x-ui.icon name="arrow-right" size="sm" />
                        </x-ui.button>
                    </x-slot:footer>
                @endif
            </x-ui.card>

            {{-- Supplier Support & Direct Negotiation Card --}}
            <x-ui.card title="Purchasing Support">
                <p class="tw-text-on-surface-variant tw-text-ui-xs mb-3">
                    Have questions about procurement specifications, schedules, or technical tolerances? Connect with the ADASI Purchasing team.
                </p>
                <x-ui.button :href="route('supplier.conversations.index')" variant="outline" size="sm" class="tw-w-full">
                    <x-ui.icon name="message-square" size="sm" />
                    <span>Open Negotiations</span>
                </x-ui.button>
            </x-ui.card>
        </aside>
    </div>
</div>
@endsection
