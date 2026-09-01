@extends('layouts.app')

@section('title', 'Create Purchase Order - ADASI Portal')
@section('page-title', 'Create Purchase Order')

@section('content')
<div class="tw-grid tw-gap-4">
    {{-- Breadcrumb & Compact Page Header --}}
    <x-ui.breadcrumb :items="[
        'Purchase Orders' => \App\Support\PurchasingNavigation::backUrl('purchasing.purchase-orders.index'),
        'Create' => null,
    ]" />

    <x-ui.page-header
        title="Create Purchase Order"
        eyebrow="Order Processing"
        :description="'Build an official PO from ' . ($quotation->purchaseRequisition->pr_number ?? 'the accepted quotation') . ' with fixed commercial rates.'"
    >
        <x-slot:actions>
            <x-ui.button :href="\App\Support\PurchasingNavigation::backUrl('purchasing.quotations.index')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>Back to Quotations</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Section 1: Primary Quotation Snapshot --}}
    <x-ui.form-section
        title="Commercial Snapshot"
        description="The supplier, currency, and exchange rate are locked based on the accepted quotation."
    >
        <div class="tw-grid tw-gap-3 sm:tw-grid-cols-2 lg:tw-grid-cols-4">
            <div class="p-3 tw-bg-surface-low border rounded">
                <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">Supplier</div>
                <div class="fw-bold tw-text-on-surface fs-6 mt-1">{{ $quotation->supplier->name }}</div>
            </div>
            <div class="p-3 tw-bg-surface-low border rounded">
                <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">Primary PR Reference</div>
                <div class="fw-bold text-primary fs-6 mt-1">{{ $quotation->purchaseRequisition->pr_number ?? '-' }}</div>
            </div>
            <div class="p-3 tw-bg-surface-low border rounded">
                <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">Procurement Period</div>
                <div class="fw-semibold tw-text-on-surface fs-6 mt-1">{{ $quotation->purchaseRequisition->period->display_label ?? $quotation->purchaseRequisition->period->name }}</div>
            </div>
            <div class="p-3 tw-bg-surface-low border rounded">
                <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">Locked Currency &amp; Exchange Rate</div>
                <div class="fw-bold tw-text-on-surface fs-6 mt-1">
                    <span class="ui-status-chip ui-status-chip--neutral me-1">{{ $quotation->currency }}</span>
                    @if($rate)
                        <span class="tw-text-on-surface tw-text-ui-xs tw-font-mono">1 {{ $quotation->currency }} = Rp {{ number_format($rate->rate_to_idr, 0, ',', '.') }}</span>
                    @else
                        <span class="text-danger tw-text-ui-xs">Exchange rate not found</span>
                    @endif
                </div>
            </div>
        </div>
    </x-ui.form-section>

    {{-- Section 2: Multi-PR Consolidation (if other compatible quotations exist) --}}
    @if($otherQuotations->count() > 0)
        <x-ui.form-section
            title="Combine Additional PRs"
            description="Select compatible approved quotations from {{ $quotation->supplier->name }} ({{ $quotation->currency }}) to combine into this PO."
        >
            <x-slot:actions>
                <span class="ui-status-chip ui-status-chip--info">
                    {{ $otherQuotations->count() }} Compatible Quotations Available
                </span>
            </x-slot:actions>

            <div class="border rounded overflow-hidden">
                <div class="list-group list-group-flush">
                    @foreach($otherQuotations as $oq)
                        @php
                            $oqItems = [];
                            foreach ($oq->items as $i) {
                                if (!$i->isAvailable()) {
                                    continue;
                                }
                                $oqItems[] = [
                                    'material' => $i->prItem->material_name,
                                    'quantity' => (int)($i->available_qty ?? $i->prItem->quantity_value),
                                    'weight_unit' => (float)($i->offered_weight_per_unit ?? $i->prItem->weight_needed),
                                    'weight' => (float)($i->offered_total_weight ?? $i->prItem->total_weight),
                                    'price' => $i->price_per_kg === null ? null : (float) $i->price_per_kg,
                                    'amount' => $i->resolved_amount,
                                    'rate' => (float)($oq->exchange_rate?->rate_to_idr ?? 0),
                                ];
                            }
                            $oqTotal = $oq->total_amount;
                            $oqRate = $oq->exchange_rate;
                            $oqIdr = $oqTotal * ($oqRate ? $oqRate->rate_to_idr : 1);
                        @endphp
                        <label class="list-group-item list-group-item-action d-flex align-items-center gap-3 tw-py-2.5 px-3 consolidate-item tw-cursor-pointer" for="oq_{{ $oq->id }}">
                            <input type="checkbox" class="form-check-input consolidate-check mt-0" id="oq_{{ $oq->id }}" value="{{ $oq->id }}" data-items='@json($oqItems)'>
                            <div class="flex-grow-1">
                                <div class="fw-bold tw-text-on-surface tw-text-ui-sm">{{ $oq->purchaseRequisition->pr_number ?? '-' }}</div>
                                <div class="tw-text-on-surface-variant tw-text-ui-xs">
                                    {{ $oq->purchaseRequisition->period->display_label ?? $oq->purchaseRequisition->period->name ?? '-' }} &bull; {{ $oq->items->count() }} item(s)
                                    @if($oq->exchange_rate)
                                        &bull; Rate: Rp {{ number_format($oq->exchange_rate->rate_to_idr, 0, ',', '.') }}
                                    @endif
                                </div>
                            </div>
                            <div class="text-end">
                                <div class="fw-bold tw-text-on-surface tw-text-ui-sm">{{ number_format($oqTotal, 2) }} {{ $oq->currency }}</div>
                                <div class="tw-text-on-surface-variant tw-text-ui-xs">≈ Rp {{ number_format($oqIdr, 0, ',', '.') }}</div>
                            </div>
                        </label>
                    @endforeach
                </div>
            </div>
        </x-ui.form-section>
    @endif

    {{-- Section 3: Material Breakdown Table --}}
    <x-ui.form-section
        title="Material Breakdown"
        description="Comprehensive item list including consolidated PR lines, quantities, and converted costs."
    >
        <x-slot:actions>
            <span class="ui-status-chip ui-status-chip--neutral ui-tabular-nums" id="totalItemCount">
                {{ $quotation->items->count() }} Item(s)
            </span>
        </x-slot:actions>

        <div class="table-responsive border rounded overflow-hidden">
            <table class="table table-hover align-middle mb-0 tw-text-ui-xs w-100">
                <thead class="table-light text-center">
                    <tr>
                        <th scope="col" style="width: 40px;">No</th>
                        <th scope="col">PR No.</th>
                        <th scope="col">Material</th>
                        <th scope="col" class="text-center">Qty</th>
                        <th scope="col" class="text-end">Weight/Unit (kg)</th>
                        <th scope="col" class="text-end">Total Weight (kg)</th>
                        <th scope="col" class="text-end">Price/Kg ({{ $quotation->currency }})</th>
                        <th scope="col" class="text-end">Amount ({{ $quotation->currency }})</th>
                        <th scope="col" class="text-end">Est. IDR</th>
                    </tr>
                </thead>
                <tbody id="materialTableBody">
                    @php $totalAmount = 0; $totalIdr = 0; $no = 1; @endphp
                    @foreach($quotation->items as $item)
                        @php
                            $isAvail = $item->isAvailable();
                            $amount = $isAvail ? $item->resolved_amount : 0;
                            $idr = $amount * ($rate ? $rate->rate_to_idr : 1);
                            $totalAmount += $amount;
                            $totalIdr += $idr;
                        @endphp
                        <tr class="{{ $isAvail ? '' : 'table-secondary tw-opacity-75' }}">
                            <td class="text-center tw-text-on-surface-variant ui-tabular-nums">{{ $no++ }}</td>
                            <td class="fw-bold text-primary">{{ $quotation->purchaseRequisition->pr_number ?? '-' }}</td>
                            <td class="fw-semibold tw-text-on-surface">
                                {{ $item->prItem->material_name }}
                                @if(!$isAvail)
                                    <span class="ui-status-chip ui-status-chip--error ms-1">Not Available</span>
                                @endif
                            </td>
                            <td class="text-center ui-tabular-nums">{{ $isAvail ? number_format($item->available_qty ?? $item->prItem->quantity_value, 0) : '—' }}</td>
                            <td class="text-end ui-tabular-nums tw-text-on-surface-variant">{{ $isAvail ? \App\Support\NumberFormat::maxDecimals($item->offered_weight_per_unit ?? $item->prItem->weight_needed) : '—' }}</td>
                            <td class="text-end fw-bold text-primary ui-tabular-nums">{{ $isAvail ? \App\Support\NumberFormat::maxDecimals($item->offered_total_weight ?? $item->prItem->total_weight) : '—' }}</td>
                            <td class="text-end ui-tabular-nums tw-text-on-surface-variant">{{ $isAvail && $item->price_per_kg !== null ? \App\Support\NumberFormat::maxDecimals($item->price_per_kg) : '—' }}</td>
                            <td class="text-end fw-semibold ui-tabular-nums">{{ $isAvail ? number_format($amount, 2) : '—' }}</td>
                            <td class="text-end fw-bold tw-text-on-surface ui-tabular-nums">{{ $isAvail ? 'Rp '.number_format($idr, 0, ',', '.') : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="table-light fw-bold border-top">
                    <tr>
                        <td colspan="7" class="text-end tw-text-on-surface">GRAND TOTAL</td>
                        <td class="text-end tw-text-on-surface ui-tabular-nums" id="grandTotalAmount">{{ number_format($totalAmount, 2) }} {{ $quotation->currency }}</td>
                        <td class="text-end text-primary ui-tabular-nums fs-6" id="grandTotalIdr">Rp {{ number_format($totalIdr, 0, ',', '.') }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </x-ui.form-section>

    {{-- Section 4: Target Arrival & Order Form --}}
    <form action="{{ route('purchasing.purchase-orders.store') }}" method="POST" id="poForm">
        @csrf
        <input type="hidden" name="return_url" value="{{ request('return_url') }}">
        <input type="hidden" name="quotation_ids[]" value="{{ $quotation->id }}">
        <div id="additionalQuotationInputs"></div>

        <x-ui.form-section
            title="Order Logistics and Remarks"
            description="Specify the estimated material delivery arrival date and any operational order instructions."
        >
            <div class="tw-grid tw-gap-4 sm:tw-grid-cols-2">
                <x-ui.date-picker name="estimated_arrival" label="Estimated Arrival Date" required />
                <x-ui.textarea name="notes" label="Purchase Order Notes / Instructions" :rows="2" placeholder="e.g. Include original COO & B/L with cargo, notify 3 days prior to ETA..." />
            </div>
        </x-ui.form-section>

        {{-- Sticky Action Bar --}}
        <x-ui.action-bar class="tw-mt-6">
            <x-slot:left>
                <x-ui.button :href="\App\Support\PurchasingNavigation::backUrl('purchasing.quotations.index')" variant="ghost" size="sm">
                    <x-ui.icon name="arrow-left" size="sm" />
                    <span>Cancel</span>
                </x-ui.button>
            </x-slot:left>

            <x-slot:right>
                <x-ui.button type="button" id="btnCreatePo" size="sm">
                    <x-ui.icon name="check-circle" size="sm" />
                    <span>Create Purchase Order</span>
                </x-ui.button>
            </x-slot:right>
        </x-ui.action-bar>
    </form>
</div>
@endsection

@php
    $primaryItemsData = [];
    foreach ($quotation->items as $i) {
        $primaryItemsData[] = [
            'pr_number' => $quotation->purchaseRequisition->pr_number ?? '-',
            'material' => $i->prItem->material_name,
            'quantity' => (int)$i->prItem->quantity_value,
            'weight_unit' => (float)$i->prItem->weight_needed,
            'weight' => (float)$i->prItem->total_weight,
            'price' => $i->price_per_kg === null ? null : (float) $i->price_per_kg,
            'amount' => $i->resolved_amount,
            'rate' => (float)($rate?->rate_to_idr ?? 0),
        ];
    }
@endphp

@push('scripts')
<script>
    const primaryItems = @json($primaryItemsData);
    const currency = @json($quotation->currency);

    function formatNumber(num, decimals) {
        if (num === null || num === undefined || num === '') {
            return '-';
        }

        return Number(num).toLocaleString('id-ID', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
    }

    function rebuildTable() {
        let allItems = [];

        // Primary quotation items
        primaryItems.forEach(item => allItems.push(item));

        // Additional checked quotation items
        $('.consolidate-check:checked').each(function() {
            const items = $(this).data('items');
            const prLabel = $(this).closest('.consolidate-item').find('.fw-bold').text().trim();
            items.forEach(item => {
                allItems.push({
                    pr_number: prLabel,
                    material: item.material,
                    quantity: item.quantity,
                    weight_unit: item.weight_unit,
                    weight: item.weight,
                    price: item.price,
                    amount: item.amount,
                    rate: item.rate,
                });
            });
        });

        // Rebuild table body
        let html = '';
        let totalAmount = 0;
        let totalIdr = 0;
        allItems.forEach((item, i) => {
            const idr = item.amount * (item.rate || 1);
            totalAmount += item.amount;
            totalIdr += idr;
            html += `<tr>
                <td class="text-center tw-text-on-surface-variant ui-tabular-nums">${i + 1}</td>
                <td class="fw-bold text-primary">${item.pr_number}</td>
                <td class="fw-semibold tw-text-on-surface">${item.material}</td>
                <td class="text-center ui-tabular-nums">${formatNumber(item.quantity, 0)}</td>
                <td class="text-end ui-tabular-nums tw-text-on-surface-variant">${formatNumber(item.weight_unit, 2)}</td>
                <td class="text-end fw-bold text-primary ui-tabular-nums">${formatNumber(item.weight, 2)}</td>
                <td class="text-end ui-tabular-nums tw-text-on-surface-variant">${formatNumber(item.price, 4)}</td>
                <td class="text-end fw-semibold ui-tabular-nums">${formatNumber(item.amount, 2)}</td>
                <td class="text-end fw-bold tw-text-on-surface ui-tabular-nums">Rp ${formatNumber(idr, 0)}</td>
            </tr>`;
        });

        $('#materialTableBody').html(html);
        $('#grandTotalAmount').text(formatNumber(totalAmount, 2) + ' ' + currency);
        $('#grandTotalIdr').text('Rp ' + formatNumber(totalIdr, 0));
        $('#totalItemCount').text(allItems.length + ' Item(s)');

        // Update hidden inputs for additional quotation_ids
        $('#additionalQuotationInputs').empty();
        $('.consolidate-check:checked').each(function() {
            $('#additionalQuotationInputs').append(
                `<input type="hidden" name="quotation_ids[]" value="${$(this).val()}">`
            );
        });
    }

    $(document).on('change', '.consolidate-check', function() {
        rebuildTable();
    });

    $('#btnCreatePo').on('click', function() {
        const checkedCount = $('.consolidate-check:checked').length;
        const totalPr = 1 + checkedCount;
        const prMsg = totalPr > 1
            ? `The PO will be created by combining <strong>${totalPr} PRs</strong>. `
            : '';

        AdasiAlert.confirm({
            title: 'Create Purchase Order?',
            html: prMsg + 'Quotations from other suppliers on the same PR will automatically be <strong>rejected</strong>.',
            confirmText: 'Yes, Create PO!',
            cancelText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                $('#poForm').submit();
            }
        });
    });
</script>
@endpush
