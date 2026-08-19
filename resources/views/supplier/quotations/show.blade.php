@extends('layouts.app')

@section('title', 'Quotation Details - ADASI Portal')
@section('page-title', 'Quotation Details')

@section('content')
<x-breadcrumb :items="[
    'Dashboard' => route('supplier.dashboard'),
    'Quotation List' => route('supplier.quotations.index'),
    'Quotation Details' => '#'
]" />
<div class="tw-grid tw-gap-6">
    <x-ui.page-header :title="'Quotation — ' . ($quotation->purchaseRequisition->pr_number ?? '-')" description="Review your submitted prices, supporting MTC files, and Purchasing feedback." eyebrow="Supplier Portal">
        <x-slot:actions><x-ui.button :href="route('supplier.quotations.period', $quotation->purchaseRequisition->period_id)" variant="ghost" size="sm"><x-slot:leading><i class="bi bi-arrow-left"></i></x-slot:leading>Back to Requisition List</x-ui.button></x-slot:actions>
    </x-ui.page-header>

    <div class="tw-grid tw-gap-6 xl:tw-grid-cols-[minmax(0,2fr)_minmax(18rem,1fr)]">
        <div class="tw-min-w-0">
            <x-ui.data-table title="Material Price Details" description="Values reflect your quotation's stored exchange-rate snapshot.">
                <x-slot:toolbar><div class="tw-flex tw-flex-wrap tw-items-center tw-gap-2">
                        <span class="badge {{ $quotation->statusBadgeClass() }} px-3 py-2 text-uppercase">{{ $quotation->statusLabel() }}</span>
                        <x-ui.button :href="route('supplier.export.quotations.detail', $quotation)" variant="secondary" size="sm" data-async-export><x-slot:leading><i class="bi bi-file-earmark-excel"></i></x-slot:leading>Export Excel</x-ui.button>
                    </div></x-slot:toolbar>
                        <table class="table table-bordered align-middle mb-0 tw-text-ui-sm">
                            <thead class="table-light text-center">
                                <tr>
                                     <th width="5%">No</th>
                                     <th width="22%">Material</th>
                                     <th width="15%">Supplier Availability</th>
                                     <th width="8%">Qty</th>
                                    <th width="10%">Weight/Unit (Kg)</th>
                                    <th width="10%">Total Weight (Kg)</th>
                                    <th width="15%">Price ({{ $quotation->currency }})</th>
                                    <th width="15%">Amount ({{ $quotation->currency }})</th>
                                    <th width="15%">Est. IDR</th>
                                    <th width="10%">Notes</th>
                                    <th width="10%">MTC</th>
                                </tr>
                            </thead>
                            <tbody>
                                @php
                                    $totalAmount = 0;
                                    $totalIdr = 0;
                                    $rate = $quotation->exchange_rate ? $quotation->exchange_rate->rate_to_idr : 1;
                                @endphp
                                @foreach($quotation->items as $index => $item)
                                    @php
                                        $idr = $item->amount * $rate;
                                        $totalAmount += $item->amount;
                                        $totalIdr += $idr;
                                    @endphp
                                    <tr>
                                        <td class="text-center">{{ $index + 1 }}</td>
                                         <td>
                                            <div class="fw-bold">{{ $item->prItem->material_name }}</div>
                                            <div class="text-muted tw-text-ui-xs">
                                                @if($item->prItem->shape)
                                                    {{ $item->prItem->shape }}: {{ $item->prItem->dimension_label }}
                                                @else
                                                    -
                                                @endif
                                            </div>
                                         </td>
                                         <td class="small">
                                             @php($availability = $item->availability_comparison)
                                             @if($availability['quantity']['code'] === 'not_specified' && $availability['specification']['code'] === 'not_specified')
                                                 <span class="text-muted">Not specified</span>
                                             @else
                                                 <div><span class="text-muted">Qty:</span> {{ $item->available_qty ?? '-' }}</div>
                                                 <div class="text-muted mt-1">{{ $item->available_dimension_label }}</div>
                                             @endif
                                         </td>
                                         <td class="text-center fw-medium">{{ number_format($item->prItem->quantity_value, 0) }}</td>
                                        <td class="text-center">{{ number_format($item->prItem->weight_needed, 2) }}</td>
                                        <td class="text-center fw-medium text-primary">{{ number_format($item->prItem->total_weight, 2) }}</td>
                                        <td class="text-end">{{ number_format($item->price_per_kg, 4) }}</td>
                                        <td class="text-end fw-medium">{{ number_format($item->amount, 2) }}</td>
                                        <td class="text-end text-muted">{{ number_format($idr, 0, ',', '.') }}</td>
                                        <td>{{ $item->notes ?? '-' }}</td>
                                        <td class="text-center">
                                            @if($item->attachments->isNotEmpty())
                                                @foreach($item->attachments as $attachment)
                                                    <a href="{{ route('attachments.show', $attachment->id) }}" class="btn btn-sm btn-outline-primary mb-1" target="_blank">
                                                        <i class="bi bi-paperclip"></i>
                                                    </a>
                                                @endforeach
                                            @else
                                                <span class="text-muted">-</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="table-light fw-bold">
                                <tr>
                                    <td colspan="7" class="text-end">TOTAL</td>
                                    <td class="text-end">{{ number_format($totalAmount, 2) }}</td>
                                    <td class="text-end text-primary">Rp {{ number_format($totalIdr, 0, ',', '.') }}</td>
                                    <td colspan="2"></td>
                                </tr>
                            </tfoot>
                        </table>
            </x-ui.data-table>
        </div>

        <aside class="tw-min-w-0">
            <x-ui.card title="Quotation Information">
                    <div class="row mb-3">
                        <div class="col-5 text-muted small">Submit Time</div>
                        <div class="col-7 fw-medium">
                            {{ $quotation->submitted_at ? $quotation->submitted_at->format('d M Y, H:i') : '-' }}</div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-5 text-muted small">Exchange Rate Used</div>
                        <div class="col-7 fw-medium">
                            @if($quotation->exchange_rate)
                                1 {{ $quotation->currency }} = Rp
                                {{ number_format($quotation->exchange_rate->rate_to_idr, 0, ',', '.') }}
                            @else
                                -
                            @endif
                        </div>
                    </div>
                    <hr>
                    <div class="row mb-3">
                        <div class="col-5 text-muted small">Est. Delivery</div>
                        <div class="col-7 fw-medium">
                            {{ $quotation->estimated_delivery ? \Carbon\Carbon::parse($quotation->estimated_delivery)->format('d F Y') : '-' }}
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-5 text-muted small">Valid Until</div>
                        <div class="col-7 fw-medium">
                            {{ $quotation->validity_period ? \Carbon\Carbon::parse($quotation->validity_period)->format('d F Y') : '-' }}
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-12 text-muted small mb-1">Payment Terms</div>
                        <div class="col-12 fw-medium p-2 bg-light rounded">
                            {{ $quotation->payment_terms ?: 'No special terms' }}</div>
                    </div>
                    <div class="row">
                        <div class="col-12 text-muted small mb-1">General Notes</div>
                        <div class="col-12 fw-medium p-2 bg-light rounded">
                            {{ $quotation->general_notes ?: 'No notes' }}</div>
                    </div>
                    @if($quotation->reviewer_notes)
                        <div class="row mt-3">
                            <div class="col-12 text-muted small mb-1">Notes Purchasing</div>
                            <div class="col-12 fw-medium p-2 bg-warning bg-opacity-10 border border-warning rounded">
                                {{ $quotation->reviewer_notes }}
                            </div>
                        </div>
                    @endif
            </x-ui.card>

            @if($quotation->status === 'revision_requested')
                <x-ui.alert tone="warning" class="tw-mt-4">
                    Purchasing requested a revision for this quotation. Update the price, estimated delivery, and validity date before resubmitting.
                </x-ui.alert>
                <div class="tw-mt-3 tw-grid tw-gap-2">
                    <x-ui.button :href="route('supplier.quotations.create', $quotation->purchaseRequisition)" variant="secondary"><x-slot:leading><i class="bi bi-pencil-square"></i></x-slot:leading>Revise Quotation</x-ui.button>
                    @if($conversation)
                        <x-ui.button :href="route('supplier.conversations.show', $conversation)" variant="ghost" data-open-chat-conversation="{{ $conversation->getRouteKey() }}"><x-slot:leading><i class="bi bi-chat-dots"></i></x-slot:leading>Open Revision Chat</x-ui.button>
                    @endif
                </div>
            @elseif($quotation->status === 'rejected')
                <x-ui.alert tone="error" class="tw-mt-4">This quotation was not selected by the ADASI Purchasing team.</x-ui.alert>
            @elseif($quotation->status === 'accepted')
                <x-ui.alert tone="success" class="tw-mt-4">This quotation was selected by the ADASI Purchasing team.</x-ui.alert>
            @else
                <x-ui.alert class="tw-mt-4">Your quotation has been recorded and is waiting for evaluation by the ADASI Purchasing team.</x-ui.alert>
            @endif
        </aside>
    </div>
</div>
@endsection
