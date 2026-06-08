@extends('layouts.app')

@section('title', 'Quotation Details - ADASI Portal')
@section('page-title', 'Quotation Details')

@section('content')
<x-breadcrumb :items="[
    'Dashboard' => route('supplier.dashboard'),
    'Quotation List' => route('supplier.quotations.index'),
    'Quotation Details' => '#'
]" />
    <div class="mb-3">
        <a href="{{ route('supplier.quotations.period', $quotation->purchaseRequisition->period_id) }}"
            class="text-decoration-none text-muted small">
            <i class="bi bi-arrow-left me-1"></i> Back to Requisition List
        </a>
    </div>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold">Material Price Details</h6>
                    <span class="badge {{ $quotation->statusBadgeClass() }} px-3 py-2 text-uppercase">{{ $quotation->statusLabel() }}</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-bordered align-middle mb-0" style="font-size: 0.85rem;">
                            <thead class="table-light text-center">
                                <tr>
                                    <th width="5%">No</th>
                                    <th width="22%">Material</th>
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
                                            <div class="text-muted" style="font-size: 0.75rem;">
                                                @if($item->prItem->shape)
                                                    {{ $item->prItem->shape }}: {{ $item->prItem->dimension_label }}
                                                @else
                                                    -
                                                @endif
                                            </div>
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
                                    <td colspan="6" class="text-end">TOTAL</td>
                                    <td class="text-end">{{ number_format($totalAmount, 2) }}</td>
                                    <td class="text-end text-primary">Rp {{ number_format($totalIdr, 0, ',', '.') }}</td>
                                    <td colspan="2"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold">Quotation Information</h6>
                </div>
                <div class="card-body">
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
                </div>
            </div>

            @if($quotation->status === 'revision_requested')
                <div class="alert alert-warning small">
                    <i class="bi bi-arrow-repeat me-1"></i>
                    Purchasing requested a revision for this quotation. Update the price, estimated delivery, and validity date before resubmitting.
                </div>
                <div class="d-grid gap-2">
                    <a href="{{ route('supplier.quotations.create', $quotation->purchaseRequisition->id) }}" class="btn btn-warning text-dark fw-semibold">
                        <i class="bi bi-pencil-square me-1"></i> Revise Quotation
                    </a>
                    @if($conversation)
                        <a href="{{ route('supplier.conversations.show', $conversation->id) }}" class="btn btn-outline-primary" data-open-chat-conversation="{{ $conversation->id }}">
                            <i class="bi bi-chat-dots me-1"></i> Open Revision Chat
                        </a>
                    @endif
                </div>
            @elseif($quotation->status === 'rejected')
                <div class="alert alert-dark small">
                    <i class="bi bi-x-circle-fill me-1"></i> This quotation was not selected by the ADASI Purchasing team.
                </div>
            @elseif($quotation->status === 'accepted')
                <div class="alert alert-success small">
                    <i class="bi bi-check-circle-fill me-1"></i> This quotation was selected by the ADASI Purchasing team.
                </div>
            @else
                <div class="alert alert-info small">
                    <i class="bi bi-info-circle-fill me-1"></i> Your quotation has been recorded and is waiting for evaluation by the ADASI Purchasing team.
                </div>
            @endif
        </div>
    </div>
@endsection
