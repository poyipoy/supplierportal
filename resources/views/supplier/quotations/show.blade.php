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
            <x-ui.button :href="route('supplier.export.quotations.detail', $quotation)" variant="outline" size="sm" data-async-export data-export-source-singular="quotation" data-export-source-plural="quotations" data-export-source-count="1" data-export-filtered="false" data-export-row-label="quotation item rows" data-export-row-explanation="Each quotation item will be written as a separate Excel row.">
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
                <table class="table table-hover align-middle mb-0 tw-text-ui-xs w-100" style="min-width: 1200px;">
                        <thead class="table-light align-middle text-center">
                            <tr class="border-bottom">
                                <th scope="col" rowspan="2" style="width: 40px;" class="text-center">#</th>
                                <th scope="col" rowspan="2" class="text-start" style="min-width: 200px;">Material &amp; Requested Specs</th>
                                <th scope="col" rowspan="2" class="text-start" style="min-width: 180px;">Availability &amp; Offer Specs</th>
                                <th scope="col" colspan="3" class="border-bottom text-center tw-bg-surface-low">Quantity &amp; Weight</th>
                                <th scope="col" colspan="3" class="border-bottom text-center tw-bg-surface-low">Commercials ({{ $quotation->currency }})</th>
                                <th scope="col" rowspan="2" class="text-end" style="min-width: 130px;">Offer Est. IDR</th>
                                <th scope="col" rowspan="2" class="text-start" style="min-width: 130px;">Notes</th>
                                <th scope="col" rowspan="2" class="text-center" style="width: 54px;">MTC</th>
                            </tr>
                            <tr class="tw-text-[11px] tw-text-on-surface-variant">
                                <th scope="col" class="text-center" style="min-width: 85px;">Qty</th>
                                <th scope="col" class="text-end" style="min-width: 105px;">KG / Unit</th>
                                <th scope="col" class="text-end" style="min-width: 105px;">Total KG</th>
                                <th scope="col" class="text-end" style="min-width: 95px;">Price / KG</th>
                                <th scope="col" class="text-end" style="min-width: 105px;">Req. Amount</th>
                                <th scope="col" class="text-end" style="min-width: 110px;">Offer Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php
                                $totalAmount = 0;
                                $totalIdr = 0;
                                $rate = $quotation->exchange_rate ? (float) $quotation->exchange_rate->rate_to_idr : null;
                            @endphp
                            @foreach($quotation->items as $index => $item)
                                @php
                                    $amount = $item->resolved_amount;
                                    $requestedAmount = $item->requested_amount;
                                    $idr = $rate !== null ? $amount * $rate : null;
                                    $totalAmount += $amount;
                                    $totalIdr += $idr ?? 0;
                                    $availability = $item->availability_comparison;
                                @endphp
                                <tr>
                                    <td class="text-center tw-text-on-surface-variant ui-tabular-nums">{{ $index + 1 }}</td>
                                    <td class="text-start">
                                        <div class="fw-bold tw-text-on-surface">{{ $item->prItem->material_name }}</div>
                                        <div class="tw-text-on-surface-variant tw-text-ui-xs tw-mt-0.5">
                                            @if($item->prItem->shape)
                                                <span class="ui-status-chip ui-status-chip--neutral me-1">{{ $item->prItem->shape }}</span>
                                                <span>{{ $item->prItem->dimension_label }}</span>
                                            @else
                                                <span>-</span>
                                            @endif
                                        </div>
                                        @if($item->prItem?->remark)
                                            <div class="tw-text-on-surface-variant tw-text-[11px] tw-mt-1 tw-italic">
                                                <span class="fw-semibold">Remark:</span> {{ $item->prItem->remark }}
                                            </div>
                                        @endif
                                    </td>
                                    <td class="text-start">
                                        @if($availability['specification']['code'] === 'not_available')
                                            <span class="ui-status-chip ui-status-chip--error">
                                                <x-ui.icon name="x-circle" size="xs" class="me-1" /> Not Available
                                            </span>
                                        @elseif($availability['quantity']['code'] === 'not_specified' && $availability['specification']['code'] === 'not_specified')
                                            <span class="tw-text-outline">Not specified</span>
                                        @else
                                            <div class="tw-text-ui-xs">
                                                <div class="fw-semibold tw-text-on-surface">{{ $item->available_dimension_label }}</div>
                                                <div class="d-flex flex-wrap gap-1 mt-1">
                                                    <span class="ui-status-chip ui-status-chip--neutral" style="font-size: 10px;">{{ $availability['quantity']['label'] }}</span>
                                                    <span class="ui-status-chip ui-status-chip--neutral" style="font-size: 10px;">{{ $availability['specification']['label'] }}</span>
                                                </div>
                                            </div>
                                        @endif
                                    </td>
                                    <td class="text-center ui-tabular-nums">
                                        <div class="tw-text-[11px] tw-text-on-surface-variant"><span class="tw-opacity-70">Req:</span> {{ $item->requested_quantity ?? '-' }}</div>
                                        <div class="fw-bold text-primary"><span class="tw-opacity-70">Off:</span> {{ $item->is_available ? ($item->available_qty ?? '-') : '—' }}</div>
                                    </td>
                                    <td class="text-end ui-tabular-nums">
                                        <div class="tw-text-[11px] tw-text-on-surface-variant"><span class="tw-opacity-70">Req:</span> {{ \App\Support\NumberFormat::maxDecimals($item->requested_weight_per_unit) }}</div>
                                        <div class="fw-bold text-primary">
                                            <span class="tw-opacity-70">Off:</span> {{ $item->is_available ? \App\Support\NumberFormat::maxDecimals($item->offered_weight_per_unit ?? $item->requested_weight_per_unit) : '—' }}
                                            @if($item->is_available && $item->is_estimated_weight)
                                                <span class="ui-status-chip ui-status-chip--warning ms-1" style="font-size: 10px;">Est</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="text-end ui-tabular-nums">
                                        <div class="tw-text-[11px] tw-text-on-surface-variant"><span class="tw-opacity-70">Req:</span> {{ \App\Support\NumberFormat::maxDecimals($item->requested_total_weight) }}</div>
                                        <div class="fw-bold text-primary"><span class="tw-opacity-70">Off:</span> {{ $item->is_available ? \App\Support\NumberFormat::maxDecimals($item->offered_total_weight) : '—' }}</div>
                                    </td>
                                    <td class="text-end fw-bold ui-tabular-nums">
                                        {{ \App\Support\NumberFormat::maxDecimals($item->price_per_kg) }}
                                    </td>
                                    <td class="text-end tw-text-on-surface-variant ui-tabular-nums">{{ $item->is_available ? \App\Support\NumberFormat::maxDecimals($requestedAmount) : '—' }}</td>
                                    <td class="text-end fw-semibold text-primary ui-tabular-nums" data-offer-amount="{{ $item->is_available ? \App\Support\NumberFormat::maxDecimals($amount) : '' }}">
                                        {{ $item->is_available ? \App\Support\NumberFormat::maxDecimals($amount) : '—' }}
                                    </td>
                                    <td class="text-end fw-bold tw-text-on-surface ui-tabular-nums">
                                        {{ $idr !== null && $item->is_available ? 'Rp '.\App\Support\NumberFormat::maxDecimals($idr) : '—' }}
                                    </td>
                                    <td class="text-start tw-text-on-surface-variant tw-text-ui-xs">
                                        @if($item->notes)
                                            <div class="text-truncate" style="max-width: 140px;" title="{{ $item->notes }}">
                                                {{ $item->notes }}
                                            </div>
                                        @else
                                            <span class="tw-text-outline">—</span>
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        @if($item->attachments->isNotEmpty())
                                            @foreach($item->attachments as $attachment)
                                                <x-ui.icon-button :href="route('attachments.show', $attachment->id)" icon="paperclip" :label="'Open attachment: ' . $attachment->file_name" size="sm" target="_blank" />
                                            @endforeach
                                        @else
                                            <span class="tw-text-outline">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="table-light fw-bold border-top">
                            <tr>
                                <td colspan="7" class="text-end tw-text-on-surface tw-uppercase">Total Offer Amount:</td>
                                <td class="text-end tw-text-on-surface-variant ui-tabular-nums">{{ \App\Support\NumberFormat::maxDecimals($quotation->items->sum('requested_amount')) }}</td>
                                <td class="text-end text-primary ui-tabular-nums">{{ \App\Support\NumberFormat::maxDecimals($totalAmount) }} {{ $quotation->currency }}</td>
                                <td class="text-end text-primary ui-tabular-nums fs-6">{{ $rate !== null ? 'Rp '.\App\Support\NumberFormat::maxDecimals($totalIdr) : '—' }}</td>
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
                                1 {{ $quotation->currency }} = Rp {{ \App\Support\NumberFormat::maxDecimals($quotation->exchange_rate->rate_to_idr) }}
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
