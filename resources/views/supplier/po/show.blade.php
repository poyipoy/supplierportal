@extends('layouts.app')

@section('title', 'PO Details: ' . $po->po_number . ' - ADASI Portal')
@section('page-title', 'Purchase Order Details')

@section('content')
<x-breadcrumb :items="[
    'Dashboard' => route('supplier.dashboard'),
    'Purchase Orders' => route('supplier.purchase-orders.index'),
    $po->po_number => '#'
]" />
<div class="tw-grid tw-gap-6">
    <x-ui.page-header :title="$po->po_number" description="Review order details, material values, claim actions, and read-only import document status." eyebrow="Supplier Portal">
        <x-slot:actions><x-ui.button :href="route('supplier.purchase-orders.index')" variant="ghost" size="sm"><i class="bi bi-arrow-left"></i> Back to PO List</x-ui.button></x-slot:actions>
    </x-ui.page-header>

<div class="tw-grid tw-gap-6 xl:tw-grid-cols-[minmax(0,2fr)_minmax(18rem,1fr)]">
    <div class="tw-grid tw-min-w-0 tw-content-start tw-gap-6">
        {{-- PO Info --}}
        <x-ui.card :title="$po->po_number">
                @php
                    $badgeClass = match(true) {
                        $po->is_overdue => 'bg-danger',
                        $po->status === 'active' => 'bg-primary',
                        $po->status === 'waiting_qc' => 'bg-warning text-dark',
                        $po->status === 'claim_needed' => 'bg-danger',
                        $po->status === 'completed' => 'bg-success',
                        default => 'bg-secondary'
                    };
                @endphp
                <x-slot:actions><div class="tw-flex tw-flex-wrap tw-items-center tw-gap-2">
                    <span class="badge {{ $badgeClass }} text-uppercase px-3 py-2 me-2">{{ $po->is_overdue ? 'Overdue' : ucwords(str_replace('_', ' ', $po->status)) }}</span>
                    <x-ui.button :href="route('supplier.export.purchase-orders.detail', $po)" variant="secondary" size="sm" data-async-export><i class="bi bi-file-earmark-excel"></i> Export Excel</x-ui.button>
                    <x-ui.button :href="route('shared.pdf.purchase-order', $po)" variant="danger" size="sm" target="_blank" title="Print Purchase Order" data-pdf-confirm><i class="bi bi-file-earmark-pdf"></i> Print PDF</x-ui.button>
                </div></x-slot:actions>
                <div class="row mb-2">
                    <div class="col-md-4 text-muted small">Reference (No. PR)</div>
                    <div class="col-md-8 fw-medium">{{ $po->pr_reference }}</div>
                </div>
                <div class="row mb-2">
                    <div class="col-md-4 text-muted small">Date Created</div>
                    <div class="col-md-8 fw-medium">{{ $po->created_at->format('d F Y, H:i') }}</div>
                </div>
                <div class="row mb-2">
                    <div class="col-md-4 text-muted small">Estimated Arrival</div>
                    <div class="col-md-8 fw-medium">{{ $po->estimated_arrival ? $po->estimated_arrival->format('d F Y') : '-' }}</div>
                </div>
                <div class="row mb-2">
                    <div class="col-md-4 text-muted small">Actual Arrival</div>
                    <div class="col-md-8 fw-medium">
                        @if($po->actual_arrival)
                            <span class="text-success"><i class="bi bi-check-circle-fill me-1"></i>{{ $po->actual_arrival->format('d F Y') }}</span>
                        @else
                            <span class="text-muted">Not arrived yet</span>
                        @endif
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-4 text-muted small">Currency</div>
                    <div class="col-md-8 fw-medium">{{ $po->currency }}</div>
                </div>
                <div class="row mt-2">
                    <div class="col-md-4 text-muted small">Remark</div>
                    <div class="col-md-8">{{ $po->notes ?: '-' }}</div>
                </div>
        </x-ui.card>

        {{-- Material Table --}}
        <x-ui.data-table title="Material Details" description="Commercial values are grouped by your related quotation and PR reference.">
                    <table class="table table-bordered align-middle mb-0 tw-text-ui-sm">
                        <thead class="table-light text-center">
                            <tr>
                                <th>No</th>
                                <th>Material</th>
                                <th>Reference (No. PR)</th>
                                <th>Remark</th>
                                <th>Qty</th>
                                <th>Weight/Unit (Kg)</th>
                                <th>Total Weight (Kg)</th>
                                <th>Price/Kg</th>
                                <th>Amount</th>
                                <th>IDR</th>
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
                                        <td colspan="10" class="fw-bold small ps-3">
                                            <i class="bi bi-folder2 me-1"></i>
                                            {{ $quotation->purchaseRequisition->pr_number ?? 'PR -' }}
                                            <span class="text-muted fw-normal ms-2">
                                                @if($rate)
                                                    &bull; Exchange rate: 1 {{ $quotation->currency }} = Rp {{ number_format($rate->rate_to_idr, 0, ',', '.') }}
                                                @endif
                                            </span>
                                        </td>
                                    </tr>
                                @endif
                                @foreach($quotation->items as $item)
                                    @php
                                        $idr = $item->amount * ($rate ? $rate->rate_to_idr : 1);
                                        $totalAmount += $item->amount;
                                        $totalIdr += $idr;
                                    @endphp
                                    <tr>
                                        <td class="text-center">{{ $no++ }}</td>
                                        <td class="fw-medium">{{ $item->prItem->material_name }}</td>
                                        <td class="text-nowrap">
                                            @if($quotation->purchaseRequisition)
                                                <a href="{{ route('supplier.quotations.show', $quotation) }}" class="text-primary text-decoration-none" title="Open related PR detail">
                                                    {{ $quotation->purchaseRequisition->pr_number ?? '-' }}
                                                    <i class="bi bi-box-arrow-up-right ms-1 tw-text-ui-xs"></i>
                                                </a>
                                            @else
                                                -
                                            @endif
                                        </td>
                                        <td>
                                            @if($poRemark !== '')
                                                <span class="d-inline-block text-truncate tw-max-w-[220px]" title="{{ $poRemark }}">
                                                    {{ \Illuminate\Support\Str::limit($poRemark, 80) }}
                                                </span>
                                            @else
                                                -
                                            @endif
                                        </td>
                                        <td class="text-center">{{ number_format($item->prItem->quantity_value, 0) }}</td>
                                        <td class="text-center">{{ number_format($item->prItem->weight_needed, 2) }}</td>
                                        <td class="text-center fw-medium text-primary">{{ number_format($item->prItem->total_weight, 2) }}</td>
                                        <td class="text-end">{{ number_format($item->price_per_kg, 4) }}</td>
                                        <td class="text-end fw-medium">{{ number_format($item->amount, 2) }}</td>
                                        <td class="text-end">Rp {{ number_format($idr, 0, ',', '.') }}</td>
                                    </tr>
                                @endforeach
                            @endforeach
                        </tbody>
                        <tfoot class="table-light fw-bold">
                            <tr>
                                <td colspan="8" class="text-end">TOTAL</td>
                                <td class="text-end">{{ number_format($totalAmount, 2) }}</td>
                                <td class="text-end text-primary">Rp {{ number_format($totalIdr, 0, ',', '.') }}</td>
                            </tr>
                        </tfoot>
                    </table>
        </x-ui.data-table>
    </div>

    <aside class="tw-grid tw-min-w-0 tw-content-start tw-gap-6">
        @php
            $pendingClaim = $po->materialClaims
                ->where('status', 'pending')
                ->sortByDesc('created_at')
                ->first();
            $latestClaim = $po->materialClaims
                ->sortByDesc('created_at')
                ->first();
        @endphp

        @if($pendingClaim || $latestClaim)
            <x-ui.card title="Material Claim" variant="tonal">
                <x-slot:actions><span class="badge {{ $pendingClaim ? 'bg-warning text-dark' : 'bg-danger' }}">
                        {{ $pendingClaim ? 'Needs Response' : 'Has Claim' }}
                    </span></x-slot:actions>
                    @if($pendingClaim)
                        <p class="small text-muted mb-3">
                            ADASI submitted a claim for this PO. Please provide a response and supporting attachments.
                        </p>
                        <x-ui.button :href="route('supplier.claims.show', $pendingClaim)" variant="danger" class="tw-w-full tw-justify-between"><i class="bi bi-reply"></i> Claim Response <i class="bi bi-chevron-right"></i></x-ui.button>
                    @else
                        <p class="small text-muted mb-3">
                            This PO has a material claim history. Open claim details to view status and response.
                        </p>
                        <x-ui.button :href="route('supplier.claims.show', $latestClaim)" variant="ghost" class="tw-w-full tw-justify-between"><i class="bi bi-exclamation-octagon"></i> View Material Claim <i class="bi bi-chevron-right"></i></x-ui.button>
                    @endif
            </x-ui.card>
        @endif

        {{-- Document Status (read-only for supplier) --}}
        <x-ui.card title="Import Document Status" description="Read-only progress maintained by ADASI.">
                @php
                    $docLabels = [
                        'invoice' => 'Invoice',
                        'bl' => 'Bill of Lading',
                        'packing_list' => 'Packing List',
                        'form_e' => 'Form-E',
                    ];
                    $statusLabels = [
                        'pending' => 'Not Available',
                        'received' => 'Accepted',
                        'verified' => 'Verified',
                        'issued' => 'Issued',
                        'processing' => 'Processing',
                        'done' => 'Completed'
                    ];
                @endphp
                @foreach($po->documents as $doc)
                    @php
                        $statusBadge = match($doc->status) {
                            'pending' => 'bg-secondary',
                            'received', 'issued', 'processing' => 'bg-info',
                            'verified', 'done' => 'bg-success',
                            default => 'bg-secondary'
                        };
                    @endphp
                    <div class="d-flex justify-content-between align-items-center py-2 {{ !$loop->last ? 'border-bottom' : '' }}">
                        <span class="fw-medium">{{ $docLabels[$doc->doc_type] ?? $doc->doc_type }}</span>
                        <span class="badge {{ $statusBadge }}">{{ $statusLabels[$doc->status] ?? $doc->status }}</span>
                    </div>
                @endforeach
        </x-ui.card>
    </aside>
</div>
</div>
@endsection
