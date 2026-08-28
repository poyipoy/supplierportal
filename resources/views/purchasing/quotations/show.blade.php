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
    $validityMeta = \App\Support\StatusHelper::quotationValidityMeta($quotation->validity_period);
@endphp

<div class="tw-grid tw-gap-4">
    {{-- Breadcrumb & Compact Page Header --}}
    <x-ui.breadcrumb :items="[
        'Dashboard' => route('purchasing.dashboard'),
        'Quotation List' => route('purchasing.quotations.index'),
        'Quotation Details' => null,
    ]" />

    <x-ui.page-header
        title="Quotation Details"
        eyebrow="Commercial Evaluation"
        :description="'Review ' . ($quotation->purchaseRequisition->pr_number ?? 'quotation') . ' from ' . $supplierDisplayName . ' before taking the next workflow action.'"
    >
        <x-slot:actions>
            <x-status-badge type="quotation" :status="$quotation->status" size="lg" />
            <x-ui.button :href="route('purchasing.export.quotations.detail', $quotation)" variant="outline" size="sm" data-async-export data-export-source-singular="quotation" data-export-source-plural="quotations" data-export-source-count="1" data-export-filtered="false" data-export-row-label="quotation item rows" data-export-row-explanation="Each quotation item will be written as a separate Excel row.">
                <x-ui.icon name="file-spreadsheet" />
                <span>Export Excel</span>
            </x-ui.button>
            <x-ui.button :href="\App\Support\PurchasingNavigation::backUrl('purchasing.quotations.index')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" />
                <span>Back to Quotations</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="tw-grid tw-items-start tw-gap-4 xl:tw-grid-cols-[minmax(0,2fr)_minmax(19rem,1fr)]">
        {{-- Main Column --}}
        <div class="tw-grid tw-min-w-0 tw-gap-4">
            {{-- Info Quotation Card --}}
            <x-ui.card title="Commercial Summary">
                <div class="tw-grid tw-gap-3 sm:tw-grid-cols-2 lg:tw-grid-cols-3">
                    <div class="tw-p-2.5 tw-bg-surface-low border rounded">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">PR Number</div>
                        <div class="fw-bold text-primary tw-text-ui-sm tw-mt-0.5">{{ $quotation->purchaseRequisition->pr_number ?? '-' }}</div>
                    </div>
                    <div class="tw-p-2.5 tw-bg-surface-low border rounded">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">Procurement Period</div>
                        <div class="fw-semibold tw-text-on-surface tw-text-ui-sm tw-mt-0.5">{{ $quotation->purchaseRequisition->period->display_label ?? $quotation->purchaseRequisition->period->name ?? '-' }}</div>
                    </div>
                    <div class="tw-p-2.5 tw-bg-surface-low border rounded">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">Currency</div>
                        <div class="fw-bold tw-text-on-surface tw-text-ui-sm tw-mt-0.5"><span class="ui-status-chip ui-status-chip--neutral">{{ $quotation->currency }}</span></div>
                    </div>
                    <div class="tw-p-2.5 tw-bg-surface-low border rounded">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">Date Submitted</div>
                        <div class="fw-semibold tw-text-on-surface tw-text-ui-sm tw-mt-0.5">{{ $quotation->submitted_at ? $quotation->submitted_at->format('d M Y, H:i') : '-' }}</div>
                    </div>
                    <div class="tw-p-2.5 tw-bg-surface-low border rounded">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">Estimated Delivery</div>
                        <div class="fw-semibold tw-text-on-surface tw-text-ui-sm tw-mt-0.5">{{ $quotation->estimated_delivery ? $quotation->estimated_delivery->format('d M Y') : '-' }}</div>
                    </div>
                    <div class="tw-p-2.5 tw-bg-surface-low border rounded">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">Quotation Valid Until</div>
                        <div class="fw-semibold tw-text-on-surface tw-text-ui-sm tw-mt-0.5">
                            @if($quotation->validity_period)
                                {{ $quotation->validity_period->format('d M Y') }}
                                {!! \App\Support\StatusHelper::badgeWithTooltip($validityMeta['class'] . ' ms-1', $validityMeta['label'], $validityMeta['description']) !!}
                            @else
                                {!! \App\Support\StatusHelper::badgeWithTooltip($validityMeta['class'], $validityMeta['label'], $validityMeta['description']) !!}
                            @endif
                        </div>
                    </div>
                    <div class="sm:tw-col-span-2 lg:tw-col-span-3 tw-p-2.5 tw-bg-surface-low border rounded">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">Payment Terms &amp; Conditions</div>
                        <div class="tw-text-on-surface tw-text-ui-sm tw-mt-0.5">{{ $quotation->payment_terms ?? '-' }}</div>
                    </div>
                </div>

                @if($quotation->status === 'revision_requested')
                    <x-ui.alert tone="warning" title="Revision requested" class="tw-mt-3">The supplier must resubmit the quotation with updated pricing and validity.</x-ui.alert>
                @elseif($quotation->isExpired())
                    <x-ui.alert tone="error" title="Quotation expired" class="tw-mt-3">The supplier must resubmit a valid offer before a PO can be created.</x-ui.alert>
                @endif

                @if($quotation->general_notes)
                    <div class="mt-3 tw-p-2.5 tw-bg-surface-low border rounded tw-text-ui-xs tw-text-on-surface">
                        <span class="fw-bold d-block tw-mb-0.5">Supplier Notes:</span>
                        {{ $quotation->general_notes }}
                    </div>
                @endif

                @if($quotation->reviewer_notes)
                    <div class="mt-3 tw-p-2.5 bg-warning-subtle border border-warning-subtle rounded tw-text-ui-xs text-warning-emphasis">
                        <span class="fw-bold d-block tw-mb-0.5"><x-ui.icon name="square-pen" size="sm" class="me-1" />Purchasing Reviewer Notes:</span>
                        {{ $quotation->reviewer_notes }}
                    </div>
                @endif
            </x-ui.card>

            {{-- Material Price Breakdown Table --}}
            <x-ui.data-table
                :title="'Material Price Breakdown (' . $quotation->items->count() . ' items)'"
                description="Requested specifications, supplier availability, and exchange-rate snapshot in one review table."
            >
                <table class="table table-hover align-middle mb-0 tw-text-ui-xs w-100">
                    <thead class="table-light text-center">
                        <tr>
                            <th scope="col" style="width: 35px;">No</th>
                            <th scope="col" class="text-start">Material</th>
                            <th scope="col">Requested vs Offered</th>
                            <th scope="col">Qty</th>
                            <th scope="col" class="text-end">Weight/Unit</th>
                            <th scope="col" class="text-end">Total Weight</th>
                            <th scope="col" class="text-end">Price/Kg ({{ $quotation->currency }})</th>
                            <th scope="col" class="text-end">Amount ({{ $quotation->currency }})</th>
                            <th scope="col" class="text-end">Est. IDR</th>
                            <th scope="col" class="text-center" style="width: 50px;">MTC</th>
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
                                $pricePerKg = $item->price_per_kg === null ? null : (float) $item->price_per_kg;
                                $amount = $item->resolved_amount;
                                $priceIdr = $pricePerKg !== null && $rateValue !== null ? $pricePerKg * $rateValue : null;
                                $amountIdr = $rateValue !== null ? $amount * $rateValue : null;
                                $totalOriginal += $amount;
                                $totalIdr += $amountIdr ?? 0;
                                $availability = $item->availability_comparison;
                            @endphp
                            <tr>
                                <td class="text-center tw-text-on-surface-variant ui-tabular-nums">{{ $idx + 1 }}</td>
                                <td>
                                    <div class="fw-bold tw-text-on-surface">{{ $item->prItem->material_name ?? '-' }}</div>
                                    @if($item->prItem && $item->prItem->shape)
                                        <span class="ui-status-chip ui-status-chip--neutral tw-mt-0.5">{{ $item->prItem->shape }}</span>
                                        <div class="tw-text-on-surface-variant tw-text-ui-xs tw-mt-0.5">{{ $item->prItem->dimension_label }}</div>
                                    @endif
                                    @if($item->prItem?->remark)
                                        <div class="tw-text-on-surface-variant tw-text-ui-xs tw-mt-0.5">Remark: {{ $item->prItem->remark }}</div>
                                    @endif
                                </td>
                                <td class="text-start tw-min-w-[210px]">
                                    <div class="border rounded tw-p-1.5 tw-bg-surface-low mb-1 tw-text-ui-xs">
                                        <span class="tw-text-on-surface-variant fw-semibold d-block">Requested:</span>
                                        <span class="fw-medium">Qty {{ number_format($quantity, 0) }} &bull; {{ $item->prItem?->dimension_label ?? '-' }}</span>
                                    </div>
                                    <div class="border rounded tw-p-1.5 tw-bg-surface tw-text-ui-xs">
                                        <span class="text-primary fw-semibold d-block">Offered:</span>
                                        @if($availability['specification']['code'] === 'not_available')
                                            <span class="fw-medium">Not Available</span>
                                        @else
                                            <span class="fw-medium">Qty {{ $item->available_qty ?? '-' }} &bull; {{ $item->available_dimension_label }}</span>
                                        @endif
                                        <div class="d-flex flex-wrap gap-1 mt-1">
                                            <span @class([
                                                'badge',
                                                'bg-secondary' => in_array($availability['quantity']['code'], ['not_specified', 'not_available'], true),
                                                'bg-warning text-dark' => $availability['quantity']['code'] === 'shortage',
                                                'bg-success' => in_array($availability['quantity']['code'], ['match', 'surplus'], true),
                                            ]) style="font-size: var(--ui-font-size-xs);">{{ $availability['quantity']['label'] }}</span>
                                            <span @class([
                                                'badge',
                                                'bg-secondary' => in_array($availability['specification']['code'], ['not_specified', 'not_available'], true),
                                                'bg-warning text-dark' => $availability['specification']['code'] === 'different',
                                                'bg-success' => in_array($availability['specification']['code'], ['exact', 'within_range'], true),
                                            ]) style="font-size: var(--ui-font-size-xs);">{{ $availability['specification']['label'] }}</span>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-center fw-bold ui-tabular-nums">{{ number_format($quantity, 0) }}</td>
                                <td class="text-end ui-tabular-nums tw-text-on-surface-variant">{{ number_format($weight, 2) }}</td>
                                <td class="text-end fw-bold text-primary ui-tabular-nums">{{ number_format($totalWeight, 2) }}</td>
                                <td class="text-end fw-bold ui-tabular-nums">{{ $pricePerKg === null ? '-' : number_format($pricePerKg, 2) }}</td>
                                <td class="text-end fw-semibold ui-tabular-nums">{{ number_format($amount, 2) }}</td>
                                <td class="text-end fw-bold tw-text-on-surface ui-tabular-nums">
                                    {{ $amountIdr !== null ? 'Rp ' . number_format($amountIdr, 0, ',', '.') : '-' }}
                                </td>
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
                            <td colspan="7" class="text-end tw-text-on-surface">Total ({{ $quotation->currency }}):</td>
                            <td class="text-end tw-text-on-surface ui-tabular-nums">{{ number_format($totalOriginal, 2) }}</td>
                            <td class="text-end text-primary ui-tabular-nums fs-6">
                                {{ $rateValue !== null ? 'Rp ' . number_format($totalIdr, 0, ',', '.') : '-' }}
                            </td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </x-ui.data-table>
        </div>

        {{-- Sidebar Column --}}
        <aside class="tw-grid tw-gap-4">
            {{-- Supplier Details Card --}}
            <x-ui.card title="Supplier Profile">
                <div class="fw-bold tw-text-on-surface tw-text-ui-sm">{{ $supplierDisplayName }}</div>
                <div class="tw-text-on-surface-variant tw-text-ui-xs tw-mt-0.5">{{ $quotation->supplier->email }}</div>
                @if($quotation->supplier->supplier)
                    <div class="tw-text-on-surface-variant tw-text-ui-xs mt-2 pt-2 border-top">
                        <div class="mb-1"><x-ui.icon name="map-pin" size="sm" class="me-1 tw-text-outline" />{{ $quotation->supplier->supplier->address ?? '-' }}</div>
                        <div><x-ui.icon name="phone" size="sm" class="me-1 tw-text-outline" />{{ $quotation->supplier->supplier->phone ?? '-' }}</div>
                    </div>
                @endif
            </x-ui.card>

            {{-- Chat Action Card --}}
            <x-ui.card title="Direct Negotiation">
                @if($chatAvailable)
                    <form action="{{ route('purchasing.conversations.start.pr', ['pr_id' => $quotation->purchaseRequisition, 'supplier_id' => $quotation->supplier]) }}" method="POST" data-chat-start-form>
                        @csrf
                        <input type="hidden" name="return_url" value="{{ \App\Support\PurchasingNavigation::currentUrlForReturn() }}">
                        <x-ui.button type="submit" variant="outline" size="sm" class="tw-w-full tw-justify-between">
                            <span class="d-inline-flex align-items-center gap-2">
                                <x-ui.icon name="message-square" size="sm" />
                                <span class="fw-semibold">Chat with Supplier</span>
                            </span>
                            <x-ui.icon name="chevron-right" size="sm" />
                        </x-ui.button>
                    </form>
                    <div class="tw-text-on-surface-variant tw-text-ui-xs mt-2">
                        Clarify specifications, lead times, MTC certificates, or price before creating a PO.
                    </div>
                @else
                    <div class="tw-text-on-surface-variant tw-text-ui-xs">
                        Chat is accessible once quotation is submitted by supplier.
                    </div>
                @endif
            </x-ui.card>

            {{-- Conversion Rate Snapshot --}}
            <x-ui.card title="Conversion Rate Snapshot" description="Applied quotation exchange rate snapshot.">
                @if($quotationRate)
                    <div class="p-3 tw-bg-surface-low border rounded text-center">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ $quotation->currency }} → IDR</div>
                        <div class="fw-bold text-primary fs-5 mt-1">Rp {{ number_format($quotationRate->rate_to_idr, 0, ',', '.') }}</div>
                        <div class="tw-text-outline tw-text-ui-xs tw-mt-0.5">Snapshot Date: {{ $quotationRate->valid_from->format('d M Y') }}</div>
                    </div>
                @else
                    <x-ui.alert tone="warning" title="Exchange rate unavailable">No exchange-rate snapshot is recorded for {{ $quotation->currency }}.</x-ui.alert>
                @endif
            </x-ui.card>

            {{-- Review Actions Card --}}
            <x-ui.card title="Commercial Review Actions" description="Actions based on quotation state.">
                @if($quotation->status === 'submitted' && $quotation->purchaseOrders->isEmpty() && !$quotation->isExpired())
                    <form action="{{ route('purchasing.quotations.accept', $quotation) }}" method="POST" class="tw-mb-2.5">
                        @csrf
                        <x-ui.button type="submit" size="sm" class="tw-w-full">
                            <x-slot:leading><x-ui.icon name="circle-check" /></x-slot:leading>
                            Accept Quotation
                        </x-ui.button>
                    </form>

                    <form action="{{ route('purchasing.quotations.request-revision', $quotation) }}" method="POST" class="tw-mb-2.5" id="requestRevisionForm">
                        @csrf
                        <input type="hidden" name="return_url" value="{{ request('return_url') }}">
                        <div class="mb-2">
                            <label class="form-label small fw-semibold tw-text-on-surface mb-1" for="revisionNote">Revision Notes</label>
                            <textarea name="revision_note" id="revisionNote" class="form-control form-control-sm" rows="2" maxlength="1000" required placeholder="Specify what needs to be revised (price, validity, lead time)..."></textarea>
                        </div>
                        <x-ui.button type="submit" variant="outline" size="sm" class="tw-w-full">
                            <x-slot:leading><x-ui.icon name="rotate-ccw" /></x-slot:leading>
                            Request Revision
                        </x-ui.button>
                    </form>

                    <form action="{{ route('purchasing.quotations.reject', $quotation) }}" method="POST" class="tw-mb-2.5">
                        @csrf
                        <div class="mb-2">
                            <label class="form-label small fw-semibold tw-text-on-surface mb-1" for="reviewerNotes">Rejection Reason</label>
                            <textarea name="reviewer_notes" id="reviewerNotes" class="form-control form-control-sm" rows="2" maxlength="1000" required placeholder="State reason for rejecting offer..."></textarea>
                        </div>
                        <x-ui.button type="submit" variant="danger" size="sm" class="tw-w-full">
                            <x-slot:leading><x-ui.icon name="circle-x" /></x-slot:leading>
                            Reject Quotation
                        </x-ui.button>
                    </form>
                @endif

                @if($canCreatePo)
                    <x-ui.button :href="\App\Support\PurchasingNavigation::toRoute('purchasing.purchase-orders.create', $quotation)" size="sm" class="tw-w-full tw-mb-2.5">
                        <x-ui.icon name="receipt" size="sm" />
                        <span>Create PO from Quotation</span>
                    </x-ui.button>
                @elseif($quotation->status === 'submitted' && $quotation->isExpired())
                    @if($canRequestRevision)
                        <form action="{{ route('purchasing.quotations.request-revision', $quotation) }}" method="POST" class="tw-mb-2.5" id="requestRevisionForm">
                            @csrf
                            <input type="hidden" name="return_url" value="{{ request('return_url') }}">
                            <x-ui.alert tone="warning" title="Validity update required" class="tw-mb-2">Request the supplier to extend the expired offer.</x-ui.alert>
                            <div class="mb-2">
                                <textarea name="revision_note" id="revisionNote" class="form-control form-control-sm" rows="2" maxlength="1000" placeholder="Please update validity date and re-confirm pricing..."></textarea>
                            </div>
                            <x-ui.button type="submit" variant="outline" size="sm" class="tw-w-full">
                                <x-slot:leading><x-ui.icon name="rotate-ccw" /></x-slot:leading>
                                Request Validity Update
                            </x-ui.button>
                        </form>
                    @else
                        <x-ui.button variant="danger" size="sm" class="tw-mb-2.5 tw-w-full" disabled>
                            <x-slot:leading><x-ui.icon name="lock" /></x-slot:leading>
                            Quotation Expired
                        </x-ui.button>
                    @endif
                @elseif($quotation->status === 'revision_requested')
                    <x-ui.alert tone="warning" title="Revised quotation pending" class="tw-mb-2.5">Waiting for the supplier to resubmit.</x-ui.alert>
                @elseif($quotation->first_purchase_order)
                    <x-ui.alert tone="success" title="PO created" class="tw-mb-2.5"><a href="{{ \App\Support\PurchasingNavigation::toRoute('purchasing.purchase-orders.show', $quotation->first_purchase_order) }}" class="tw-font-semibold tw-underline">{{ $quotation->first_purchase_order->po_number }}</a></x-ui.alert>
                @endif

                <x-ui.button :href="$relatedPrUrl" variant="ghost" size="sm" class="tw-w-full">
                    <x-ui.icon name="clipboard-list" size="sm" />
                    <span>View Related PR</span>
                </x-ui.button>
            </x-ui.card>

            {{-- Attachments List --}}
            @if($quotation->attachments->count() > 0)
                <x-ui.card :title="'Attachments (' . $quotation->attachments->count() . ')'" padding="none">
                    <div class="list-group list-group-flush">
                        @foreach($quotation->attachments as $att)
                            <a href="{{ route('attachments.show', $att->id) }}" class="list-group-item list-group-item-action py-2 px-3 tw-text-ui-xs d-flex justify-content-between align-items-center" target="_blank">
                                <span class="text-truncate"><x-ui.icon name="paperclip" size="sm" class="tw-me-1.5 tw-text-outline" />{{ $att->file_name }}</span>
                                <x-ui.icon name="download" size="sm" class="tw-text-outline" />
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
