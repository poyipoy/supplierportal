@extends('layouts.app')

@section('title', 'Quotation Details - ADASI Portal')
@section('page-title', 'Quotation Details')

@section('content')
<div class="tw-grid tw-gap-4">
    {{-- Breadcrumb & Compact Page Header --}}
    <x-ui.breadcrumb :items="[
        'Dashboard' => route('supplier.dashboard'),
        'Quotation Periods' => route('supplier.quotations.index'),
        ($quotation->purchaseRequisition->period->display_label ?? 'Requisitions') => route('supplier.quotations.period', $quotation->purchaseRequisition->period_id),
        'Quotation Details' => null,
    ]" />

    <x-ui.page-header
        :title="'Quotation — ' . ($quotation->purchaseRequisition->pr_number ?? '-')"
        eyebrow="Submitted Quotation"
        description="Review your submitted prices, availability parameters, supporting MTC files, and Purchasing evaluation feedback."
    >
        <x-slot:actions>
            <x-status-badge type="quotation" :status="$quotation->status" size="lg" />
            <x-ui.button :href="route('supplier.export.quotations.detail', $quotation)" variant="outline" size="sm" data-async-export>
                <x-ui.icon name="file-spreadsheet" />
                <span>Export Excel</span>
            </x-ui.button>
            <x-ui.button :href="route('supplier.quotations.period', $quotation->purchaseRequisition->period_id)" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" />
                <span>Back to Requisitions</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="tw-grid tw-items-start tw-gap-4 xl:tw-grid-cols-[minmax(0,2fr)_minmax(19rem,1fr)]">
        {{-- Main Column: Quoted Items Table --}}
        <div class="tw-grid tw-min-w-0 tw-gap-4">
            <x-ui.data-table
                title="Material Price Breakdown"
                description="Commercial values reflect your quotation's locked exchange-rate snapshot."
            >
                <table class="table table-hover align-middle mb-0 tw-text-ui-xs w-100">
                    <thead class="table-light text-center">
                        <tr>
                            <th scope="col" style="width: 35px;">No</th>
                            <th scope="col" class="text-start">Material</th>
                            <th scope="col">Offered Availability</th>
                            <th scope="col">Qty</th>
                            <th scope="col" class="text-end">Weight/Unit</th>
                            <th scope="col" class="text-end">Total Weight</th>
                            <th scope="col" class="text-end">Price/Kg ({{ $quotation->currency }})</th>
                            <th scope="col" class="text-end">Amount ({{ $quotation->currency }})</th>
                            <th scope="col" class="text-end">Est. IDR</th>
                            <th scope="col">Notes</th>
                            <th scope="col" class="text-center" style="width: 50px;">MTC</th>
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
                                $amount = $item->resolved_amount;
                                $idr = $amount * $rate;
                                $totalAmount += $amount;
                                $totalIdr += $idr;
                                $availability = $item->availability_comparison;
                            @endphp
                            <tr>
                                <td class="text-center tw-text-on-surface-variant ui-tabular-nums">{{ $index + 1 }}</td>
                                <td class="text-start">
                                    <div class="fw-bold tw-text-on-surface">{{ $item->prItem->material_name }}</div>
                                    <div class="tw-text-on-surface-variant tw-text-ui-xs tw-mt-0.5">
                                        @if($item->prItem->shape)
                                            <span class="ui-status-chip ui-status-chip--neutral">{{ $item->prItem->shape }}</span>
                                            {{ $item->prItem->dimension_label }}
                                        @else
                                            -
                                        @endif
                                    </div>
                                </td>
                                <td class="text-start">
                                    @if($availability['quantity']['code'] === 'not_specified' && $availability['specification']['code'] === 'not_specified')
                                        <span class="tw-text-outline">Not specified</span>
                                    @else
                                        <div class="tw-text-ui-xs">
                                            <span class="tw-text-on-surface-variant">Qty:</span> <span class="fw-semibold">{{ $item->available_qty ?? '-' }}</span>
                                            <div class="tw-text-on-surface-variant tw-mt-0.5">{{ $item->available_dimension_label }}</div>
                                        </div>
                                    @endif
                                </td>
                                <td class="text-center fw-bold ui-tabular-nums">{{ number_format($item->prItem->quantity_value, 0) }}</td>
                                <td class="text-end ui-tabular-nums tw-text-on-surface-variant">{{ number_format($item->prItem->weight_needed, 2) }}</td>
                                <td class="text-end fw-bold text-primary ui-tabular-nums">{{ number_format($item->prItem->total_weight, 2) }}</td>
                                <td class="text-end fw-bold ui-tabular-nums">{{ number_format($item->price_per_kg, 4) }}</td>
                                <td class="text-end fw-semibold ui-tabular-nums">{{ number_format($amount, 2) }}</td>
                                <td class="text-end fw-bold tw-text-on-surface ui-tabular-nums">Rp {{ number_format($idr, 0, ',', '.') }}</td>
                                <td class="tw-text-on-surface-variant tw-text-ui-xs">{{ $item->notes ?: '-' }}</td>
                                <td class="text-center">
                                    @if($item->attachments->isNotEmpty())
                                        @foreach($item->attachments as $attachment)
                                            <x-ui.icon-button :href="route('attachments.show', $attachment->id)" icon="paperclip" :label="'Open attachment: ' . $attachment->file_name" size="sm" target="_blank" />
                                        @endforeach
                                    @else
                                        <span class="tw-text-outline">-</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="table-light fw-bold border-top">
                        <tr>
                            <td colspan="7" class="text-end tw-text-on-surface">TOTAL:</td>
                            <td class="text-end tw-text-on-surface ui-tabular-nums">{{ number_format($totalAmount, 2) }} {{ $quotation->currency }}</td>
                            <td class="text-end text-primary ui-tabular-nums fs-6">Rp {{ number_format($totalIdr, 0, ',', '.') }}</td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </x-ui.data-table>
        </div>

        {{-- Sidebar Column: Commercial Info & Feedback --}}
        <aside class="tw-grid tw-gap-4">
            <x-ui.card title="Quotation Parameters">
                <div class="tw-grid tw-gap-2.5">
                    <div class="tw-p-2.5 tw-bg-surface-low border rounded">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">Date Submitted</div>
                        <div class="fw-semibold tw-text-on-surface tw-text-ui-sm tw-mt-0.5">
                            {{ $quotation->submitted_at ? $quotation->submitted_at->format('d M Y, H:i') : '-' }}
                        </div>
                    </div>
                    <div class="tw-p-2.5 tw-bg-surface-low border rounded">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">Exchange Rate Snapshot</div>
                        <div class="fw-bold tw-text-on-surface tw-text-ui-sm tw-mt-0.5">
                            @if($quotation->exchange_rate)
                                1 {{ $quotation->currency }} = Rp {{ number_format($quotation->exchange_rate->rate_to_idr, 0, ',', '.') }}
                            @else
                                -
                            @endif
                        </div>
                    </div>
                    <div class="tw-p-2.5 tw-bg-surface-low border rounded">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">Estimated Delivery</div>
                        <div class="fw-semibold tw-text-on-surface tw-text-ui-sm tw-mt-0.5">
                            {{ $quotation->estimated_delivery ? \Carbon\Carbon::parse($quotation->estimated_delivery)->format('d F Y') : '-' }}
                        </div>
                    </div>
                    <div class="tw-p-2.5 tw-bg-surface-low border rounded">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">Valid Until</div>
                        <div class="fw-semibold tw-text-on-surface tw-text-ui-sm tw-mt-0.5">
                            {{ $quotation->validity_period ? \Carbon\Carbon::parse($quotation->validity_period)->format('d F Y') : '-' }}
                        </div>
                    </div>
                    <div class="tw-p-2.5 tw-bg-surface-low border rounded">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">Payment Terms</div>
                        <div class="tw-text-on-surface tw-text-ui-xs tw-mt-0.5">{{ $quotation->payment_terms ?: 'Standard terms' }}</div>
                    </div>
                    @if($quotation->general_notes)
                        <div class="tw-p-2.5 tw-bg-surface-low border rounded">
                            <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">General Notes</div>
                            <div class="tw-text-on-surface tw-text-ui-xs tw-mt-0.5">{{ $quotation->general_notes }}</div>
                        </div>
                    @endif
                    @if($quotation->reviewer_notes)
                        <div class="tw-p-2.5 bg-warning-subtle border border-warning-subtle rounded text-warning-emphasis">
                                    <div class="fw-bold tw-text-ui-xs tw-uppercase tw-mb-0.5"><x-ui.icon name="square-pen" size="sm" class="me-1" />Purchasing Reviewer Notes</div>
                            <div class="tw-text-ui-xs">{{ $quotation->reviewer_notes }}</div>
                        </div>
                    @endif
                </div>
            </x-ui.card>

            {{-- Workflow Status Card --}}
            <x-ui.card title="Status and Follow-up">
                @if($quotation->status === 'revision_requested')
                    <x-ui.alert tone="warning" title="Revision requested" class="tw-mb-3">Purchasing requested a revision. Update unit prices, estimated delivery, and validity date before resubmitting.</x-ui.alert>
                    <div class="tw-grid tw-gap-2">
                        <x-ui.button :href="route('supplier.quotations.create', $quotation->purchaseRequisition)" variant="primary" size="sm" class="tw-w-full">
                            <x-ui.icon name="square-pen" size="sm" />
                            <span>Revise Quotation</span>
                        </x-ui.button>
                        @if($conversation)
                            <x-ui.button :href="route('supplier.conversations.show', $conversation)" variant="outline" size="sm" class="tw-w-full" data-open-chat-conversation="{{ $conversation->getRouteKey() }}">
                                <x-ui.icon name="message-square" size="sm" />
                                <span>Open Revision Chat</span>
                            </x-ui.button>
                        @endif
                    </div>
                @elseif($quotation->status === 'rejected')
                    <x-ui.alert tone="error" title="Quotation not selected">This quotation was not selected for an order by ADASI Purchasing.</x-ui.alert>
                @elseif($quotation->status === 'accepted')
                    <x-ui.alert tone="success" title="Quotation selected">An official PO will be issued through the procurement workflow.</x-ui.alert>
                @else
                    <x-ui.alert tone="info" title="Commercial evaluation pending">Your quotation has been recorded and is waiting for evaluation by Purchasing.</x-ui.alert>
                @endif
            </x-ui.card>
        </aside>
    </div>
</div>
@endsection
