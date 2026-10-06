@extends('layouts.app')

@section('title', __('purchasing.copy.create_purchase_order_adasi_portal'))
@section('page-title', __('purchasing.copy.create_purchase_order'))

@section('content')
<div class="tw-grid tw-gap-4">
    {{-- Breadcrumb & Compact Page Header --}}
    <x-ui.breadcrumb :items="[
        __('purchasing.breadcrumbs.purchase_orders') => \App\Support\PurchasingNavigation::backUrl('purchasing.purchase-orders.index'),
        __('purchasing.breadcrumbs.create') => null,
    ]" />

    <x-ui.page-header
        :title="__('purchasing.copy.create_purchase_order')"
        :eyebrow="__('purchasing.copy.order_processing')"
        :description="__('purchasing.page.create_po_help', ['reference' => $quotation->purchaseRequisition->pr_number ?? __('purchasing.copy.quotation')])"
    >
        <x-slot:actions>
            <x-ui.button :href="\App\Support\PurchasingNavigation::backUrl('purchasing.quotations.index')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>{{ __('purchasing.copy.back_to_quotations') }}</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Section 1: Primary Quotation Snapshot --}}
    <x-ui.form-section
        :title="__('purchasing.copy.commercial_snapshot')"
        :description="__('purchasing.copy.the_supplier_currency_and_exchange_rate_are_locked_based_on_the_accepted_quotation')"
    >
        <div class="tw-grid tw-gap-3 sm:tw-grid-cols-2 lg:tw-grid-cols-4">
            <div class="p-3 tw-bg-surface-low border rounded">
                <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('purchasing.copy.supplier') }}</div>
                <div class="fw-bold tw-text-on-surface fs-6 mt-1">{{ $quotation->supplier->name }}</div>
            </div>
            <div class="p-3 tw-bg-surface-low border rounded">
                <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('purchasing.copy.primary_pr_reference') }}</div>
                <div class="fw-bold text-primary fs-6 mt-1">{{ $quotation->purchaseRequisition->pr_number ?? '-' }}</div>
            </div>
            <div class="p-3 tw-bg-surface-low border rounded">
                <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('purchasing.copy.procurement_period') }}</div>
                <div class="fw-semibold tw-text-on-surface fs-6 mt-1">{{ $quotation->purchaseRequisition->period->display_label ?? $quotation->purchaseRequisition->period->name }}</div>
            </div>
            <div class="p-3 tw-bg-surface-low border rounded">
                <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('purchasing.copy.locked_currency_exchange_rate') }}</div>
                <div class="fw-bold tw-text-on-surface fs-6 mt-1">
                    <span class="ui-status-chip ui-status-chip--neutral me-1">{{ $quotation->currency }}</span>
                    @if($rate)
                        <span class="tw-text-on-surface tw-text-ui-xs tw-font-mono">1 {{ $quotation->currency }} = Rp {{ number_format($rate->rate_to_idr, 0, ',', '.') }}</span>
                    @else
                        <span class="text-danger tw-text-ui-xs">{{ __('purchasing.copy.exchange_rate_not_found') }}</span>
                    @endif
                </div>
            </div>
        </div>
    </x-ui.form-section>

    {{-- Section 2: Multi-PR Consolidation (if other compatible quotations exist) --}}
    @if($otherQuotations->count() > 0)
        <x-ui.form-section
            :title="__('purchasing.copy.combine_additional_prs')"
            :description="__('purchasing.copy.combine_approved_quotations_help', ['supplier' => $quotation->supplier->name, 'currency' => $quotation->currency])"
        >
            <x-slot:actions>
                <span class="ui-status-chip ui-status-chip--info">
                    {{ __('purchasing.page.compatible_count', ['count' => $otherQuotations->count()]) }}
                </span>
            </x-slot:actions>

            <div class="border rounded overflow-hidden">
                <div class="list-group list-group-flush">
                    @foreach($otherQuotations as $oq)
                        @php
                            $prNumber = $oq->purchaseRequisition->pr_number ?? '-';
                            $oqItems = [];
                            foreach ($oq->items as $i) {
                                if (!$i->isAvailable()) {
                                    continue;
                                }
                                $oqItems[] = [
                                    'pr_number' => $prNumber,
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
                            <input type="checkbox" class="form-check-input consolidate-check mt-0" id="oq_{{ $oq->id }}" value="{{ $oq->id }}" data-items='@json($oqItems)' data-pr-number="{{ $prNumber }}">
                            <div class="flex-grow-1">
                                <div class="fw-bold tw-text-on-surface tw-text-ui-sm pr-label">{{ $prNumber }}</div>
                                <div class="tw-text-on-surface-variant tw-text-ui-xs">
                                     {{ $oq->purchaseRequisition->period->display_label ?? $oq->purchaseRequisition->period->name ?? '-' }} &bull; {{ trans_choice('purchasing.copy.item_count', $oq->items->count(), ['count' => $oq->items->count()]) }}
                                    @if($oq->exchange_rate)
                                        &bull; {{ __('purchasing.copy.exchange_rate_value') }}: Rp {{ number_format($oq->exchange_rate->rate_to_idr, 0, ',', '.') }}
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
        :title="__('purchasing.copy.material_breakdown')"
        :description="__('purchasing.copy.comprehensive_item_list_including_consolidated_pr_lines_quantities_and_converted_costs')"
    >
        <x-slot:actions>
            <span class="ui-status-chip ui-status-chip--neutral ui-tabular-nums" id="totalItemCount">
                {{ trans_choice('purchasing.copy.item_count', $quotation->items->count(), ['count' => $quotation->items->count()]) }}
            </span>
        </x-slot:actions>

        <div class="table-responsive border rounded overflow-hidden">
            <table class="table table-hover align-middle mb-0 tw-text-ui-xs w-100">
                <thead class="table-light text-center">
                    <tr>
                        <th scope="col" style="width: 40px;">{{ __('purchasing.copy.no') }}</th>
                        <th scope="col">{{ __('purchasing.copy.pr_no') }}</th>
                        <th scope="col">{{ __('purchasing.copy.material') }}</th>
                        <th scope="col" class="text-center">{{ __('purchasing.copy.qty') }}</th>
                        <th scope="col" class="text-end">{{ __('purchasing.copy.weight_unit_kg_e8ab70') }}</th>
                        <th scope="col" class="text-end">{{ __('purchasing.copy.total_weight_kg_a35c48') }}</th>
                        <th scope="col" class="text-end">{{ __('purchasing.copy.price_kg') }} ({{ $quotation->currency }})</th>
                        <th scope="col" class="text-end">{{ __('purchasing.copy.amount') }} ({{ $quotation->currency }})</th>
                        <th scope="col" class="text-end">{{ __('purchasing.copy.est_idr') }}</th>
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
                                    <span class="ui-status-chip ui-status-chip--error ms-1">{{ __('purchasing.copy.not_available') }}</span>
                                @endif
                            </td>
                            <td class="text-center ui-tabular-nums">{{ $isAvail ? number_format($item->available_qty ?? $item->prItem->quantity_value, 0) : '—' }}</td>
                            <td class="text-end ui-tabular-nums tw-text-on-surface-variant">{{ $isAvail ? \App\Support\NumberFormat::maxDecimals($item->offered_weight_per_unit ?? $item->prItem->weight_needed) : '—' }}</td>
                            <td class="text-end fw-bold text-primary ui-tabular-nums">{{ $isAvail ? \App\Support\NumberFormat::maxDecimals($item->offered_total_weight ?? $item->prItem->total_weight) : '—' }}</td>
                            <td class="text-end ui-tabular-nums tw-text-on-surface-variant">{{ $isAvail && $item->price_per_kg !== null ? \App\Support\NumberFormat::maxDecimals($item->price_per_kg, 4) : '—' }}</td>
                            <td class="text-end fw-semibold ui-tabular-nums">{{ $isAvail ? number_format($amount, 2) : '—' }}</td>
                            <td class="text-end fw-bold tw-text-on-surface ui-tabular-nums">{{ $isAvail ? 'Rp '.number_format($idr, 0, ',', '.') : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="table-light fw-bold border-top">
                    <tr>
                        <td colspan="7" class="text-end tw-text-on-surface">{{ __('purchasing.copy.grand_total') }}</td>
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
            :title="__('purchasing.copy.order_logistics_and_remarks')"
            :description="__('purchasing.copy.specify_the_estimated_material_delivery_arrival_date_and_any_operational_order_instructions')"
        >
            <div class="tw-grid tw-gap-4 sm:tw-grid-cols-2">
                <x-ui.date-picker name="estimated_arrival" :label="__('purchasing.copy.estimated_arrival_date')" required />
                <x-ui.textarea name="notes" :label="__('purchasing.copy.purchase_order_notes_instructions')" :rows="2" :placeholder="__('purchasing.copy.e_g_include_original_coo_b_l_with_cargo_notify_3_days_prior_to_eta')" />
            </div>
        </x-ui.form-section>

        {{-- Sticky Action Bar --}}
        <x-ui.action-bar class="tw-mt-6">
            <x-slot:left>
                <x-ui.button :href="\App\Support\PurchasingNavigation::backUrl('purchasing.quotations.index')" variant="ghost" size="sm">
                    <x-ui.icon name="arrow-left" size="sm" />
                    <span>{{ __('purchasing.copy.cancel') }}</span>
                </x-ui.button>
            </x-slot:left>

            <x-slot:right>
                <x-ui.button type="button" id="btnCreatePo" size="sm">
                    <x-ui.icon name="check-circle" size="sm" />
                    <span>{{ __('purchasing.copy.create_purchase_order') }}</span>
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
            const fallbackPrLabel = $(this).data('pr-number') || $(this).closest('.consolidate-item').find('.pr-label').text().trim();
            items.forEach(item => {
                allItems.push({
                    pr_number: item.pr_number || fallbackPrLabel,
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
        $('#totalItemCount').text(window.AdasiI18n.choice('purchasing.js.item_count', allItems.length, { count: allItems.length }));

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
        const confirmationText = totalPr > 1
            ? @js(__('common.final_review.po_consolidated_warning')).replace(':count', String(totalPr))
            : @js(__('common.final_review.po_warning'));

        AdasiAlert.confirm({
            title: @json(__('purchasing.copy.create_purchase_order')),
            text: confirmationText,
            confirmText: @json(__('purchasing.copy.yes_create_po')),
            cancelText: @json(__('purchasing.copy.cancel'))
        }).then((result) => {
            if (result.isConfirmed) {
                window.AdasiButton?.startLoading('#btnCreatePo');
                $('#poForm').submit();
            }
        });
    });
</script>
@endpush
