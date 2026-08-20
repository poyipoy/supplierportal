@extends('layouts.app')
@section('title', 'Supplier Dashboard - ADASI Portal')
@section('page-title', 'Dashboard Supplier')

@section('content')
<div class="tw-grid tw-gap-6">
    <x-ui.page-header title="Supplier Dashboard" description="Focus on open quotation opportunities, active orders, and claims that need your response." eyebrow="Supplier Portal" />
    {{-- Insight & Alerts --}}
    @if($belumDirespons > 0)
        <x-ui.alert title="Quotation opportunities" class="animate-fade-in">
            There are <strong>{{ $belumDirespons }} active requisitions</strong> that you have not quoted yet. Submit your best offer before the period closes.
        </x-ui.alert>
    @endif

    {{-- Card Statistik --}}
    <div class="tw-grid tw-gap-4 sm:tw-grid-cols-2 xl:tw-grid-cols-4">
        <x-ui.metric-card label="Active Periods" :value="$periodeAktif" icon="bi-calendar-event" :href="route('supplier.quotations.index')" />
        <x-ui.metric-card label="Not Responded" :value="$belumDirespons" icon="bi-exclamation-circle" tone="error" :href="route('supplier.quotations.index')" />
        <x-ui.metric-card label="Submitted Quotations" :value="$penawaranTerkirim" icon="bi-send-check" tone="success" :href="route('supplier.quotations.index')" />
        <x-ui.metric-card label="Received PO" :value="$poDiterima" icon="bi-receipt" tone="info" :href="route('supplier.purchase-orders.index')" />
    </div>

    {{-- Tabel + Announcement --}}
    <div class="row g-4">
        <div class="col-lg-8">
            {{-- PR Not Responded --}}
            <x-ui.data-table title="Requisitions Awaiting Your Quotation" description="These open opportunities still need a supplier response.">
                <x-slot:toolbar><x-ui.button :href="route('supplier.quotations.index')" variant="ghost" size="sm">View All</x-ui.button></x-slot:toolbar>
                        <table class="table table-hover align-middle mb-0 tw-text-ui-sm">
                            <thead class="table-light">
                                <tr>
                                    <th>PR No.</th>
                                    <th>Period</th>
                                    <th>Amount Item</th>
                                    <th>Date</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($prBelumRespons as $pr)
                                    <tr>
                                        <td class="fw-bold">{{ $pr->pr_number ?? '-' }}</td>
                                        <td>{{ $pr->period->display_label ?? $pr->period->name }}</td>
                                        <td>{{ $pr->items->count() }} Item</td>
                                        <td>{{ $pr->created_at->format('d M Y') }}</td>
                                        <td class="text-end"><a href="{{ route('supplier.quotations.create', $pr) }}"
                                                class="btn btn-sm btn-primary py-0"><i
                                                    class="bi bi-pencil-square me-1"></i>Create Quotation</a></td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-4"><i
                                                class="bi bi-check-circle text-success fs-4 d-block mb-2"></i>All requisitions
                                            have been responded to!</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
            </x-ui.data-table>
            {{-- PO Terbaru --}}
            <x-ui.data-table title="Latest Purchase Orders" description="Recent orders and claim actions visible to your supplier account." class="tw-mt-6">
                <x-slot:toolbar><x-ui.button :href="route('supplier.purchase-orders.index')" variant="ghost" size="sm">All PO</x-ui.button></x-slot:toolbar>
                        <table class="table table-hover align-middle mb-0 tw-text-ui-sm">
                            <thead class="table-light">
                                <tr>
                                    <th>PO No.</th>
                                    <th>Period</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($poTerbaru as $po)
                                    @php
                                        $pendingClaim = $po->materialClaims
                                            ->where('status', 'pending')
                                            ->sortByDesc('created_at')
                                            ->first();
                                        $latestClaim = $po->materialClaims
                                            ->sortByDesc('created_at')
                                            ->first();
                                    @endphp
                                    <tr>
                                        <td class="fw-bold">{{ $po->po_number }}</td>
                                        <td>{{ $po->quotations->map(fn($q) => optional(optional($q->purchaseRequisition)->period)->display_label)->filter()->first() ?? '-' }}
                                        </td>
                                        <td><x-status-badge type="po" :status="$po->status" :is-overdue="$po->is_overdue" />
                                        </td>
                                        <td>{{ $po->created_at->format('d M Y') }}</td>
                                        <td class="text-end">
                                            <div class="d-inline-flex gap-1 justify-content-end flex-wrap">
                                                @if($pendingClaim)
                                                    <a href="{{ route('supplier.claims.show', $pendingClaim) }}"
                                                        class="btn btn-sm btn-danger" title="Claim Response">
                                                        <i class="bi bi-reply"></i>
                                                    </a>
                                                @elseif($latestClaim)
                                                    <a href="{{ route('supplier.claims.show', $latestClaim) }}"
                                                        class="btn btn-sm btn-outline-danger" title="View Claim">
                                                        <i class="bi bi-exclamation-octagon"></i>
                                                    </a>
                                                @endif
                                                <a href="{{ route('supplier.purchase-orders.show', $po) }}"
                                                    class="btn btn-sm btn-outline-info" title="Details"><i
                                                        class="bi bi-eye"></i></a>
                                            </div>
                                        </td>
                                    </tr>
                                @empty<tr>
                                    <td colspan="5" class="text-center text-muted py-3">No PO.</td>
                                </tr>@endforelse
                            </tbody>
                        </table>
            </x-ui.data-table>
        </div>
        {{-- Announcement --}}
        <div class="col-lg-4">
            <x-ui.card title="ADASI Announcements" padding="none">
                    @forelse($announcements as $ann)
                        <div class="p-3 border-bottom">
                            <h6 class="mb-1 small fw-bold"><a href="{{ route('supplier.announcements.show', $ann->id) }}"
                                    class="text-decoration-none">{{ $ann->title }}</a></h6>
                            <div class="text-muted small mb-2">{{ Str::limit($ann->content, 80) }}</div>
                            <small class="text-muted tw-text-ui-xs"><i
                                    class="bi bi-clock me-1"></i>{{ $ann->published_at->diffForHumans() }}</small>
                        </div>
                    @empty
                        <div class="p-4 text-center text-muted small">No new announcements.</div>
                    @endforelse
                @if($announcements->count() > 0)
                    <x-slot:footer><x-ui.button :href="route('supplier.announcements.index')" variant="ghost" size="sm" class="tw-w-full">View All Announcements</x-ui.button></x-slot:footer>
                @endif
            </x-ui.card>
        </div>
    </div>
</div>
@endsection
