@extends('layouts.app')
@section('title', 'Quotation Details - ADASI Portal')
@section('page-title', 'Quotation Details')

@section('content')
@php
    $relatedPrBaseUrl = route('purchasing.requisitions.show', $quotation->purchaseRequisition);
    $relatedPrPath = parse_url($relatedPrBaseUrl, PHP_URL_PATH);
    $returnUrl = request(\App\Support\PurchasingNavigation::RETURN_URL_KEY);
    $returnPath = is_string($returnUrl) ? parse_url($returnUrl, PHP_URL_PATH) : null;
    $relatedPrUrl = (
        $returnPath === $relatedPrPath
        && \App\Support\PurchasingNavigation::isSafeUrl($returnUrl)
    )
        ? $returnUrl
        : route('purchasing.requisitions.show', [
            $quotation->purchaseRequisition,
            \App\Support\PurchasingNavigation::RETURN_URL_KEY => \App\Support\PurchasingNavigation::backUrl('purchasing.quotations.index'),
        ]);
@endphp

<x-breadcrumb :items="[
    'Dashboard' => route('purchasing.dashboard'),
    'Quotation List' => route('purchasing.quotations.index'),
    'Quotation Details' => '#'
]" />
@php
    $validityMeta = \App\Support\StatusHelper::quotationValidityMeta($quotation->validity_period);
@endphp

<div class="tw-grid tw-gap-6">
    <x-ui.page-header
        title="Quotation Details"
        :description="'Review ' . ($quotation->purchaseRequisition->pr_number ?? 'quotation') . ' from ' . $supplierDisplayName . ' before taking the next workflow action.'"
        eyebrow="Purchasing"
    >
        <x-slot:actions>
            <x-ui.button :href="\App\Support\PurchasingNavigation::backUrl('purchasing.quotations.index')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" />
                Back to Quotation List
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

<div class="tw-grid tw-gap-6 xl:tw-grid-cols-[minmax(0,2fr)_minmax(18rem,1fr)]">
    <div class="tw-grid tw-min-w-0 tw-content-start tw-gap-6">
        {{-- Info Quotation --}}
        <x-ui.card title="Quotation Information">
            <x-slot:actions>
                <div class="tw-flex tw-flex-wrap tw-items-center tw-gap-2">
                    <span class="badge {{ $quotation->statusBadgeClass() }} text-uppercase px-3 py-2">{{ $quotation->statusLabel() }}</span>
                    <x-ui.button :href="route('purchasing.export.quotations.detail', $quotation)" variant="secondary" size="sm" data-async-export>
                        <x-ui.icon name="file-spreadsheet" />
                        Export Excel
                    </x-ui.button>
                </div>
            </x-slot:actions>
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="mb-3"><span class="text-muted small d-block">PR No.</span><span class="fw-bold text-primary">{{ $quotation->purchaseRequisition->pr_number ?? '-' }}</span></div>
                        <div class="mb-3"><span class="text-muted small d-block">Period</span><span class="fw-medium">{{ $quotation->purchaseRequisition->period->display_label ?? $quotation->purchaseRequisition->period->name ?? '-' }}</span></div>
                        <div class="mb-3"><span class="text-muted small d-block">Currency</span><span class="badge bg-dark">{{ $quotation->currency }}</span></div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3"><span class="text-muted small d-block">Date Submitted</span><span class="fw-medium">{{ $quotation->submitted_at ? $quotation->submitted_at->format('d F Y, H:i') : '-' }}</span></div>
                        <div class="mb-3"><span class="text-muted small d-block">Estimated Delivery</span><span class="fw-medium">{{ $quotation->estimated_delivery ? $quotation->estimated_delivery->format('d F Y') : '-' }}</span></div>
                        <div class="mb-3">
                            <span class="text-muted small d-block">
                                Quotation Valid Until
                                <x-ui.icon name="info" class="ms-1" data-bs-toggle="tooltip" data-bs-title="Expired quotations cannot be used to create a PO until the supplier submits a revision." />
                            </span>
                            @if($quotation->validity_period)
                                <span class="fw-medium">{{ $quotation->validity_period->format('d F Y') }}</span>
                                {!! \App\Support\StatusHelper::badgeWithTooltip($validityMeta['class'] . ' ms-1', $validityMeta['label'], $validityMeta['description']) !!}
                            @else
                                {!! \App\Support\StatusHelper::badgeWithTooltip($validityMeta['class'], $validityMeta['label'], $validityMeta['description']) !!}
                            @endif
                        </div>
                        <div class="mb-3"><span class="text-muted small d-block">Payment Terms</span><span class="fw-medium">{{ $quotation->payment_terms ?? '-' }}</span></div>
                    </div>
                </div>
                @if($quotation->status === 'revision_requested')
                    <x-ui.alert tone="warning" class="tw-mt-2">
                        Revision has already been requested. The supplier needs to resubmit the quotation with a new validity date.
                    </x-ui.alert>
                @elseif($quotation->isExpired())
                    <x-ui.alert tone="error" class="tw-mt-2">
                        The quotation validity has expired. Ask the supplier to resubmit the quotation before creating a PO.
                    </x-ui.alert>
                @endif
                @if($quotation->general_notes)
                    <div class="mt-2 p-3 bg-light rounded small"><x-ui.icon name="message-square-text" class="me-1" /> {{ $quotation->general_notes }}</div>
                @endif
                @if($quotation->reviewer_notes)
                    <div class="mt-2 p-3 bg-warning bg-opacity-10 border border-warning rounded small">
                        <div class="fw-semibold mb-1"><x-ui.icon name="square-pen" class="me-1" /> Review Notes</div>
                        {{ $quotation->reviewer_notes }}
                    </div>
                @endif
        </x-ui.card>

        {{-- Item + Price Table --}}
        <x-ui.data-table
            :title="'Material Price Details (' . $quotation->items->count() . ' Item)'"
            description="Requested specifications, supplier availability, and exchange-rate snapshot in one review table."
        >
                    <table class="table table-hover align-middle mb-0 tw-text-ui-sm">
                        <thead class="table-light text-center">
                            <tr>
                                 <th>No</th>
                                 <th class="text-start">Material</th>
                                 <th>Requested vs Offered</th>
                                 <th>Qty</th>
                                <th>Weight/Unit (Kg)</th>
                                <th>Total Weight (Kg)</th>
                                <th>Price/Kg ({{ $quotation->currency }})</th>
                                <th>Amount ({{ $quotation->currency }})</th>
                                <th>Price/Kg (IDR)</th>
                                <th>Amount (IDR)</th>
                                <th>MTC</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php
                                $totalOriginal = 0;
                                $totalIdr = 0;
                                $rateValue = $quotationRate ? (float) $quotationRate->rate_to_idr : null;
                            @endphp
                            @foreach($quotation->items as $idx => $item)
                                @php
                                    $quantity = $item->prItem ? $item->prItem->quantity_value : 1;
                                    $weight = $item->prItem ? (float)$item->prItem->weight_needed : 0;
                                    $totalWeight = $item->prItem ? (float)$item->prItem->total_weight : $weight;
                                    $pricePerKg = (float)$item->price_per_kg;
                                    $amount = $item->resolved_amount;
                                    $priceIdr = $rateValue !== null ? $pricePerKg * $rateValue : null;
                                    $amountIdr = $rateValue !== null ? $amount * $rateValue : null;
                                    $totalOriginal += $amount;
                                    $totalIdr += $amountIdr ?? 0;
                                @endphp
                                <tr>
                                    <td class="text-center">{{ $idx + 1 }}</td>
                                     <td>
                                         <div class="fw-medium">{{ $item->prItem->material_name ?? '-' }}</div>
                                        @if($item->prItem && $item->prItem->shape)
                                            <span class="badge bg-light text-dark border tw-text-ui-xs">{{ $item->prItem->shape }}</span>
                                             <div class="text-muted small">{{ $item->prItem->dimension_label }}</div>
                                         @endif
                                         @if($item->prItem?->remark)
                                             <div class="text-muted small mt-1">Remark: {{ $item->prItem->remark }}</div>
                                         @endif
                                     </td>
                                     @php($availability = $item->availability_comparison)
                                     <td class="text-start small tw-min-w-[230px]">
                                         <div class="border rounded p-2 bg-light mb-2">
                                             <div class="text-muted fw-semibold mb-1"><x-ui.icon name="building" class="me-1" />Requested by Purchasing</div>
                                             <div>Qty: {{ number_format($quantity, 0) }}</div>
                                             <div class="text-muted">{{ $item->prItem?->dimension_label ?? '-' }}</div>
                                         </div>
                                         <div class="border rounded p-2">
                                             <div class="text-primary fw-semibold mb-1"><x-ui.icon name="package" class="me-1" />Offered by Supplier</div>
                                             <div>Qty: {{ $item->available_qty ?? '-' }}</div>
                                             <div class="text-muted">{{ $item->available_dimension_label }}</div>
                                             <div class="d-flex flex-wrap gap-1 mt-2">
                                                 <span @class([
                                                     'badge',
                                                     'bg-secondary' => $availability['quantity']['code'] === 'not_specified',
                                                     'bg-warning text-dark' => $availability['quantity']['code'] === 'shortage',
                                                     'bg-success' => in_array($availability['quantity']['code'], ['match', 'surplus'], true),
                                                 ])>{{ $availability['quantity']['label'] }}</span>
                                                 <span @class([
                                                     'badge',
                                                     'bg-secondary' => $availability['specification']['code'] === 'not_specified',
                                                     'bg-warning text-dark' => $availability['specification']['code'] === 'different',
                                                     'bg-success' => $availability['specification']['code'] === 'exact',
                                                 ])>{{ $availability['specification']['label'] }}</span>
                                             </div>
                                         </div>
                                     </td>
                                     <td class="text-center fw-medium">{{ number_format($quantity, 0) }}</td>
                                    <td class="text-center">{{ number_format($weight, 2) }}</td>
                                    <td class="text-center fw-medium text-primary">{{ number_format($totalWeight, 2) }}</td>
                                    <td class="text-end fw-bold">{{ number_format($pricePerKg, 2) }}</td>
                                    <td class="text-end">{{ number_format($amount, 2) }}</td>
                                    <td class="text-end text-primary fw-bold">
                                        {{ $priceIdr !== null ? 'Rp ' . number_format($priceIdr, 0, ',', '.') : '-' }}
                                    </td>
                                    <td class="text-end text-primary">
                                        {{ $amountIdr !== null ? 'Rp ' . number_format($amountIdr, 0, ',', '.') : '-' }}
                                    </td>
                                    <td class="text-center">
                                        @if($item->attachments->isNotEmpty())
                                            @foreach($item->attachments as $attachment)
                                                <a href="{{ route('attachments.show', $attachment->id) }}" class="btn btn-sm btn-outline-primary mb-1" target="_blank" title="{{ $attachment->file_name }}">
                                                    <x-ui.icon name="paperclip" />
                                                </a>
                                            @endforeach
                                        @else
                                            <span class="text-muted">-</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="table-light">
                            <tr class="fw-bold">
                                <td colspan="7" class="text-end">Total:</td>
                                <td class="text-end">{{ number_format($totalOriginal, 2) }}</td>
                                <td></td>
                                <td class="text-end text-primary">{{ $rateValue !== null ? 'Rp ' . number_format($totalIdr, 0, ',', '.') : '-' }}</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
        </x-ui.data-table>
    </div>

    <aside class="tw-grid tw-min-w-0 tw-content-start tw-gap-6">
        {{-- Info Supplier --}}
        <x-ui.card title="Supplier">
                <h5 class="fw-bold mb-1">{{ $supplierDisplayName }}</h5>
                <p class="text-muted small mb-2">{{ $quotation->supplier->email }}</p>
                @if($quotation->supplier->supplier)
                    <div class="small text-muted mb-1"><x-ui.icon name="map-pin" class="me-1" />{{ $quotation->supplier->supplier->address ?? '-' }}</div>
                    <div class="small text-muted"><x-ui.icon name="phone" class="me-1" />{{ $quotation->supplier->supplier->phone ?? '-' }}</div>
                @endif
        </x-ui.card>

        {{-- Negotiation & Chat --}}
        <x-ui.card title="Negotiation & Chat">
                @if($chatAvailable)
                    <form action="{{ route('purchasing.conversations.start.pr', ['pr_id' => $quotation->purchaseRequisition, 'supplier_id' => $quotation->supplier]) }}" method="POST" data-chat-start-form>
                        @csrf
                        <input type="hidden" name="return_url" value="{{ \App\Support\PurchasingNavigation::currentUrlForReturn() }}">
                        <x-ui.button type="submit" class="tw-w-full tw-justify-between">
                            <x-ui.icon name="message-circle-more" />
                            Chat with {{ $supplierDisplayName }}
                             <x-ui.icon name="chevron-right" />
                        </x-ui.button>
                    </form>
                    <div class="mt-3 text-muted small">
                        Use this chat to clarify price, lead time, quotation validity, or supporting documents before creating a PO.
                    </div>
                @else
                    <x-ui.alert>
                        Chat is available after the quotation is submitted by the supplier or accepted.
                    </x-ui.alert>
                @endif
        </x-ui.card>

        {{-- Exchange Rate --}}
        <x-ui.card title="Conversion Rate" description="Historical totals use this quotation's exchange-rate snapshot, not the latest rate.">
            <div class="tw-text-center">
                @if($quotationRate)
                    <div class="tw-rounded-ui-sm tw-bg-surface-low tw-p-4">
                        <div class="text-muted small mb-1">{{ $quotation->currency }} → IDR</div>
                        <div class="tw-mt-1 tw-text-ui-xl tw-font-semibold tw-text-primary ui-tabular-nums">Rp {{ number_format($quotationRate->rate_to_idr, 0, ',', '.') }}</div>
                        <div class="tw-mt-1 tw-text-ui-xs tw-text-on-surface-variant">Quotation exchange rate: {{ $quotationRate->valid_from->format('d M Y') }}</div>
                        @if($latestRate && $latestRate->id !== $quotationRate->id)
                            <div class="tw-mt-3 tw-border-t tw-border-outline-variant tw-pt-3 tw-text-ui-xs tw-text-on-surface-variant">
                                Latest exchange rate: Rp {{ number_format($latestRate->rate_to_idr, 0, ',', '.') }}<br>
                                <span>Not used for historical totals.</span>
                            </div>
                        @endif
                    </div>
                @else
                    <x-ui.alert tone="warning">Quotation exchange rate {{ $quotation->currency }} is not available yet.</x-ui.alert>
                @endif
            </div>
        </x-ui.card>

        {{-- Action --}}
        <x-ui.card title="Actions" description="Available actions follow the current quotation state.">
                @if($quotation->status === 'submitted' && $quotation->purchaseOrders->isEmpty() && !$quotation->isExpired())
                    <form action="{{ route('purchasing.quotations.accept', $quotation) }}" method="POST" class="tw-mb-3">
                        @csrf
                        <x-ui.button type="submit" class="tw-w-full">
                            <x-ui.icon name="check-circle" />
                            Accept Quotation
                        </x-ui.button>
                    </form>

                    <form action="{{ route('purchasing.quotations.request-revision', $quotation) }}" method="POST" class="tw-mb-3 tw-grid tw-gap-3" id="requestRevisionForm">
                        @csrf
                        <input type="hidden" name="return_url" value="{{ request('return_url') }}">
                        <x-ui.textarea name="revision_note" id="revisionNote" label="Revision Notes" :rows="3" maxlength="1000" required placeholder="Example: Please revise the price, lead time, MTC, or payment terms." />
                        <x-ui.button type="submit" variant="secondary" class="tw-w-full">
                            <x-ui.icon name="refresh-cw" />
                            Request Revision
                        </x-ui.button>
                    </form>

                    <form action="{{ route('purchasing.quotations.reject', $quotation) }}" method="POST" class="tw-mb-3 tw-grid tw-gap-3">
                        @csrf
                        <x-ui.textarea name="reviewer_notes" label="Rejection Notes" :rows="3" maxlength="1000" required placeholder="Required if the quotation is rejected." />
                        <x-ui.button type="submit" variant="danger" class="tw-w-full">
                            <x-ui.icon name="x-circle" />
                            Reject Quotation
                        </x-ui.button>
                    </form>
                @endif

                @if($canCreatePo)
                    <x-ui.button :href="\App\Support\PurchasingNavigation::toRoute('purchasing.purchase-orders.create', $quotation)" class="tw-mb-3 tw-w-full">
                        <x-ui.icon name="receipt" />
                        Create PO from This Quotation
                    </x-ui.button>
                @elseif($quotation->status === 'submitted' && $quotation->isExpired())
                    @if($canRequestRevision)
                        <form action="{{ route('purchasing.quotations.request-revision', $quotation) }}" method="POST" class="tw-mb-3 tw-grid tw-gap-3" id="requestRevisionForm">
                            @csrf
                            <input type="hidden" name="return_url" value="{{ request('return_url') }}">
                            <x-ui.alert tone="warning">
                                The validity date has passed. Ask the supplier to resubmit the quotation before creating a PO.
                            </x-ui.alert>
                            <x-ui.textarea name="revision_note" id="revisionNote" label="Revision Notes" :rows="3" maxlength="1000" placeholder="Example: Please update the validity date, lead time, and latest price." />
                            <x-ui.button type="submit" variant="secondary" class="tw-w-full">
                                <x-ui.icon name="refresh-cw" />
                                Request Quotation Revision
                            </x-ui.button>
                        </form>
                    @else
                        <x-ui.button disabled variant="danger" class="tw-mb-3 tw-w-full">
                            <x-ui.icon name="lock" />
                            Quotation Expired
                        </x-ui.button>
                    @endif
                @elseif($quotation->status === 'revision_requested')
                    <x-ui.alert tone="warning" class="tw-mb-3">
                        Waiting for the supplier to resubmit the revised quotation.
                    </x-ui.alert>
                @elseif($quotation->first_purchase_order)
                    <x-ui.alert tone="success" class="tw-mb-3">PO already created: <a href="{{ \App\Support\PurchasingNavigation::toRoute('purchasing.purchase-orders.show', $quotation->first_purchase_order) }}" class="tw-font-semibold tw-underline">{{ $quotation->first_purchase_order->po_number }}</a></x-ui.alert>
                @endif
                <x-ui.button :href="$relatedPrUrl" variant="ghost" size="sm" class="tw-w-full">
                    <x-ui.icon name="clipboard-list" />
                    View Related PR
                </x-ui.button>
        </x-ui.card>

        {{-- Attachments --}}
        @if($quotation->attachments->count() > 0)
        <x-ui.card :title="'Attachments (' . $quotation->attachments->count() . ')'" padding="none">
                <div class="list-group list-group-flush">
                    @foreach($quotation->attachments as $att)
                        <a href="{{ route('attachments.show', $att->id) }}" class="list-group-item list-group-item-action py-2 px-3 small d-flex justify-content-between align-items-center" target="_blank">
                            <span><x-ui.icon name="file" class="me-2" />{{ $att->file_name }}</span>
                            <x-ui.icon name="download" class="text-muted" />
                        </a>
                    @endforeach
                </div>
        </x-ui.card>
        @endif
    </aside>
</div>
</div>
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const form = document.getElementById('requestRevisionForm');
        if (!form) return;

        form.addEventListener('submit', (event) => {
            event.preventDefault();

            AdasiAlert.confirm({
                title: @json('Request Quotation Revision?'),
                text: @json('The supplier will be notified and the quotation will be reopened for resubmission.'),
                type: 'warning',
                confirmText: @json('Yes, Request Revision'),
                cancelText: @json('Cancel')
            }).then((result) => {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
        });
    });
</script>
@endpush
