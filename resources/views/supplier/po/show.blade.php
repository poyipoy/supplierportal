@extends('layouts.app')

@section('title', 'PO Details: ' . $po->po_number . ' - ADASI Portal')
@section('page-title', 'Purchase Order Details')

@section('content')
<div class="tw-grid tw-gap-4">
    {{-- Breadcrumb & Compact Page Header --}}
    <x-ui.breadcrumb :items="[
        'Dashboard' => route('supplier.dashboard'),
        'Purchase Orders' => route('supplier.purchase-orders.index'),
        $po->po_number => null,
    ]" />

    <x-ui.page-header
        :title="$po->po_number"
        eyebrow="Supplier Purchase Order"
        description="Review commercial parameters, ordered material lines, customs documentation progress, and quality claim records."
    >
        <x-slot:actions>
            <x-status-badge type="po" :status="$po->status" :is-overdue="$po->is_overdue" />
            <x-ui.button :href="route('supplier.export.purchase-orders.detail', $po)" variant="outline" size="sm" data-async-export data-export-source-singular="purchase order" data-export-source-plural="purchase orders" data-export-source-count="1" data-export-filtered="false" data-export-row-label="ordered material rows" data-export-row-explanation="Each ordered material item will be written as a separate Excel row.">
                <x-ui.icon name="file-spreadsheet" />
                <span>Export Excel</span>
            </x-ui.button>
            <x-ui.button :href="route('shared.pdf.purchase-order', $po)" variant="danger" size="sm" target="_blank" title="Print Purchase Order" data-pdf-confirm>
                <x-ui.icon name="printer" />
                <span>Print PDF</span>
            </x-ui.button>
            <x-ui.button :href="route('supplier.purchase-orders.index')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" />
                <span>Back to POs</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- 4-Key Tracking Dates Strip --}}
    <div class="tw-grid tw-gap-px tw-overflow-hidden tw-rounded-ui-md tw-border tw-border-outline tw-bg-outline-variant sm:tw-grid-cols-2 lg:tw-grid-cols-4">
        @php
            $firstPr = $po->quotations->map(fn($q) => $q->purchaseRequisition)->filter()->first();
        @endphp
        <div class="tw-flex tw-items-center tw-gap-3 tw-bg-surface-container tw-p-3">
            <x-ui.icon name="file-plus" size="sm" class="tw-shrink-0 tw-text-on-surface-variant" />
            <div>
                <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">1. PR Issued</div>
                <div class="fw-bold tw-text-on-surface tw-text-ui-xs tw-mt-0.5">
                    {{ $firstPr?->created_at ? $firstPr->created_at->format('d M Y') : '-' }}
                </div>
            </div>
        </div>

        <div class="tw-flex tw-items-center tw-gap-3 tw-bg-surface-container tw-p-3">
            <x-ui.icon name="receipt" size="sm" class="tw-shrink-0 tw-text-primary" />
            <div>
                <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">2. PO Created</div>
                <div class="fw-bold tw-text-on-surface tw-text-ui-xs tw-mt-0.5">
                    {{ $po->created_at->format('d M Y') }}
                </div>
            </div>
        </div>

        <div class="tw-flex tw-items-center tw-gap-3 tw-bg-surface-container tw-p-3">
            <x-ui.icon name="calendar" size="sm" class="tw-shrink-0 {{ $po->is_overdue ? 'tw-text-error' : 'tw-text-on-surface-variant' }}" />
            <div>
                <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">3. Estimated Arrival</div>
                <div class="fw-bold {{ $po->is_overdue ? 'text-danger' : 'tw-text-on-surface' }} tw-text-ui-xs tw-mt-0.5">
                    {{ $po->estimated_arrival ? $po->estimated_arrival->format('d M Y') : '-' }}
                    @if($po->is_overdue) <span class="ui-status-chip ui-status-chip--error ms-1">Overdue</span> @endif
                </div>
            </div>
        </div>

        <div class="tw-flex tw-items-center tw-gap-3 tw-bg-surface-container tw-p-3">
            <x-ui.icon name="{{ $po->actual_arrival ? 'circle-check' : 'clock' }}" size="sm" class="tw-shrink-0 {{ $po->actual_arrival ? 'tw-text-success' : 'tw-text-on-surface-variant' }}" />
            <div>
                <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">4. Actual Arrival</div>
                <div class="fw-bold {{ $po->actual_arrival ? 'text-success' : 'tw-text-on-surface-variant' }} tw-text-ui-xs tw-mt-0.5">
                    {{ $po->actual_arrival ? $po->actual_arrival->format('d M Y') : 'In Transit' }}
                </div>
            </div>
        </div>
    </div>

    {{-- Main Content & Sidebar --}}
    <div class="tw-grid tw-items-start tw-gap-4 xl:tw-grid-cols-[minmax(0,2fr)_minmax(19rem,1fr)]">
        {{-- Main Column: Materials Table --}}
        <div class="tw-grid tw-min-w-0 tw-gap-4">
            <x-ui.data-table
                title="Ordered Material Breakdown"
                description="Line items consolidated from your accepted quotation offers."
            >
                <table class="table table-hover align-middle mb-0 tw-text-ui-xs w-100">
                    <thead class="table-light text-center">
                        <tr>
                            <th scope="col" class="tw-w-10">No</th>
                            <th scope="col" class="text-start">Material</th>
                            <th scope="col">Reference (No. PR)</th>
                            <th scope="col">Remark</th>
                            <th scope="col">Qty</th>
                            <th scope="col" class="text-end">Weight/Unit</th>
                            <th scope="col" class="text-end">Total Weight</th>
                            <th scope="col" class="text-end">Price/Kg</th>
                            <th scope="col" class="text-end">Amount</th>
                            <th scope="col" class="text-end">Amount (IDR)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php
                            $totalAmount = 0;
                            $totalIdr = 0;
                            $no = 1;
                            $poRemark = trim((string) $po->notes);
                        @endphp
                        @foreach($po->quotations as $quotation)
                            @php $rate = $quotationRates[$quotation->id] ?? null; @endphp
                            @if($po->quotations->count() > 1)
                                <tr class="table-primary">
                                    <td colspan="10" class="fw-bold ps-3 tw-text-ui-xs">
                                    <x-ui.icon name="folder" size="sm" class="me-1" />
                                        {{ $quotation->purchaseRequisition->pr_number ?? 'PR -' }}
                                        <span class="tw-text-on-surface-variant fw-normal ms-2">
                                            @if($rate)
                                                &bull; Exchange Rate: 1 {{ $quotation->currency }} = Rp {{ \App\Support\NumberFormat::maxDecimals($rate->rate_to_idr) }}
                                            @endif
                                        </span>
                                    </td>
                                </tr>
                            @endif
                            @foreach($quotation->items as $item)
                                @php
                                    $amount = $item->resolved_amount;
                                    $idr = $amount * ($rate ? $rate->rate_to_idr : 1);
                                    $totalAmount += $amount;
                                    $totalIdr += $idr;
                                @endphp
                                <tr>
                                    <td class="text-center tw-text-on-surface-variant ui-tabular-nums">{{ $no++ }}</td>
                                    <td class="text-start tw-text-on-surface">
                                        <div class="fw-bold">{{ $item->prItem->material_name }}</div>
                                        @if($item->is_available)
                                            <div class="tw-text-on-surface-variant tw-text-ui-xs">Offer: {{ $item->available_dimension_label }}</div>
                                        @else
                                            <span class="ui-status-chip ui-status-chip--error tw-mt-0.5">Not Available</span>
                                        @endif
                                    </td>
                                    <td class="text-start">
                                        @if($quotation->purchaseRequisition)
                                            <a href="{{ route('supplier.quotations.show', $quotation) }}" class="text-primary fw-semibold text-decoration-none d-inline-flex align-items-center gap-1" title="Open related quotation">
                                                <span>{{ $quotation->purchaseRequisition->pr_number ?? '-' }}</span>
                                            <x-ui.icon name="external-link" size="sm" />
                                            </a>
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td class="text-start">
                                        @if($poRemark !== '')
                                            <span class="d-inline-block text-truncate tw-text-on-surface-variant tw-max-w-[180px]" title="{{ $poRemark }}">
                                                {{ \Illuminate\Support\Str::limit($poRemark, 40) }}
                                            </span>
                                        @else
                                            <span class="tw-text-outline">-</span>
                                        @endif
                                    </td>
                                    <td class="text-center fw-bold ui-tabular-nums">
                                        @if($item->is_available)
                                            {{ $item->available_qty ?? $item->prItem->quantity_value }}
                                        @else
                                            <span class="ui-status-chip ui-status-chip--error">Not Available</span>
                                        @endif
                                    </td>
                                    <td class="text-end ui-tabular-nums tw-text-on-surface-variant">
                                        {{ $item->is_available ? \App\Support\NumberFormat::maxDecimals($item->offered_weight_per_unit ?? $item->prItem->weight_needed) : '—' }}
                                        @if($item->is_available && $item->is_estimated_weight)<span class="ui-status-chip ui-status-chip--warning ms-1">Est Weight</span>@endif
                                    </td>
                                    <td class="text-end fw-bold text-primary ui-tabular-nums">{{ $item->is_available ? \App\Support\NumberFormat::maxDecimals($item->offered_total_weight ?? $item->prItem->total_weight) : '—' }}</td>
                                    <td class="text-end ui-tabular-nums">
                                        {{ \App\Support\NumberFormat::maxDecimals($item->price_per_kg) }}
                                    </td>
                                    <td class="text-end fw-semibold ui-tabular-nums">{{ $item->is_available ? \App\Support\NumberFormat::maxDecimals($amount) : '—' }}</td>
                                    <td class="text-end fw-bold tw-text-on-surface ui-tabular-nums">{{ $item->is_available ? 'Rp '.\App\Support\NumberFormat::maxDecimals($idr) : '—' }}</td>
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                    <tfoot class="table-light fw-bold border-top">
                        <tr>
                            <td colspan="8" class="text-end tw-text-on-surface">TOTAL:</td>
                            <td class="text-end tw-text-on-surface ui-tabular-nums">{{ \App\Support\NumberFormat::maxDecimals($totalAmount) }} {{ $po->currency }}</td>
                            <td class="text-end text-primary ui-tabular-nums fs-6">Rp {{ \App\Support\NumberFormat::maxDecimals($totalIdr) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </x-ui.data-table>
        </div>

        {{-- Sidebar Column: Details & Claim Notice --}}
        <aside class="tw-grid tw-gap-4">
            @php
                $pendingClaim = $po->materialClaims->where('status', 'pending')->sortByDesc('created_at')->first();
                $latestClaim = $po->materialClaims->sortByDesc('created_at')->first();
            @endphp

            @if($pendingClaim || $latestClaim)
                <x-ui.card title="Material Claim Notice" class="border-danger">
                    <x-slot:actions>
                        <span class="ui-status-chip {{ $pendingClaim ? 'ui-status-chip--error' : 'ui-status-chip--neutral' }}">
                            {{ $pendingClaim ? 'Action Required' : 'Claim Logged' }}
                        </span>
                    </x-slot:actions>

                    @if($pendingClaim)
                        <p class="tw-text-on-surface-variant tw-text-ui-xs mb-3">
                            ADASI Quality Control has submitted an NG defect claim for this order. Please respond before the deadline.
                        </p>
                        <x-ui.button :href="route('supplier.claims.show', $pendingClaim)" variant="danger" size="sm" class="tw-w-full">
                            <x-ui.icon name="reply" size="sm" />
                            <span>Respond to Claim</span>
                        </x-ui.button>
                    @else
                        <p class="tw-text-on-surface-variant tw-text-ui-xs mb-3">
                            This purchase order has historical claim resolutions recorded.
                        </p>
                        <x-ui.button :href="route('supplier.claims.show', $latestClaim)" variant="outline" size="sm" class="tw-w-full">
                            <x-ui.icon name="octagon-alert" size="sm" />
                            <span>View Claim History</span>
                        </x-ui.button>
                    @endif
                </x-ui.card>
            @endif

            {{-- PO Commercial Parameters --}}
            <x-ui.card title="Order Information">
                <div class="tw-grid tw-gap-2.5">
                    <div class="tw-p-2.5 tw-bg-surface-container border rounded">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">Reference (No. PR)</div>
                        <div class="fw-bold text-primary tw-text-ui-sm tw-mt-0.5">{{ $po->pr_reference }}</div>
                    </div>
                    <div class="tw-p-2.5 tw-bg-surface-container border rounded">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">Currency</div>
                        <div class="fw-bold tw-text-on-surface tw-text-ui-sm tw-mt-0.5">{{ $po->currency }}</div>
                    </div>
                    <div class="tw-p-2.5 tw-bg-surface-container border rounded">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">Order Remarks</div>
                        <div class="tw-text-on-surface tw-text-ui-xs tw-mt-0.5">{{ $po->notes ?: 'No special notes recorded.' }}</div>
                    </div>
                </div>
            </x-ui.card>

            {{-- Read-Only Customs Documents Tracking --}}
            <x-ui.card title="Customs Documentation Progress" description="Read-only status maintained by ADASI Logistics.">
                @php
                    $docLabels = [
                        'invoice' => 'Commercial Invoice',
                        'bl' => 'Bill of Lading (B/L)',
                        'packing_list' => 'Packing List',
                        'form_e' => 'Form-E Certificate',
                    ];
                    $statusLabels = [
                        'pending' => 'Not Available',
                        'received' => 'Received',
                        'verified' => 'Verified',
                        'issued' => 'Issued',
                        'processing' => 'Processing',
                        'done' => 'Completed'
                    ];
                @endphp
                <div class="tw-grid tw-gap-2">
                    @foreach($po->documents as $doc)
                        @php
                            $statusTone = match($doc->status) {
                                'pending' => 'neutral',
                                'received', 'issued', 'processing' => 'info',
                                'verified', 'done' => 'success',
                                default => 'neutral'
                            };
                        @endphp
                        <div class="tw-flex tw-items-center tw-justify-between tw-border-b tw-border-outline-variant tw-bg-surface-container tw-p-2 tw-text-ui-xs last:tw-border-b-0">
                            <span class="fw-semibold tw-text-on-surface">{{ $docLabels[$doc->doc_type] ?? $doc->doc_type }}</span>
                            <span class="ui-status-chip ui-status-chip--{{ $statusTone }}">{{ $statusLabels[$doc->status] ?? $doc->status }}</span>
                        </div>
                    @endforeach
                </div>
            </x-ui.card>
        </aside>
    </div>
</div>
@endsection
