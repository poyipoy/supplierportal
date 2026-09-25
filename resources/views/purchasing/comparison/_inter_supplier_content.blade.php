<style>
.comparison-matrix-table {
    border-collapse: separate !important;
    border-spacing: 0;
    min-width: max-content;
    width: max-content;
    max-width: none;
}
.comparison-matrix-table th,
.comparison-matrix-table td {
    border-right: 1px solid var(--md-outline-variant);
    border-bottom: 1px solid var(--md-outline-variant);
    background-color: var(--md-surface);
}
.comparison-matrix-table thead th {
    background-color: var(--md-surface-container-low) !important;
    vertical-align: middle;
}
.col-sticky-material {
    position: sticky;
    left: 0;
    z-index: 5;
    background-color: var(--md-surface) !important;
    box-shadow: 2px 0 6px -2px rgba(0, 0, 0, 0.12);
    min-width: 200px;
    max-width: 240px;
    width: 220px;
}
thead th.col-sticky-material {
    background-color: var(--md-surface-container-low) !important;
    z-index: 12;
}
.col-ref-qty {
    min-width: 65px;
}
.col-ref-weight {
    min-width: 110px;
}
.col-ref-total-weight {
    min-width: 120px;
}
.col-supplier-group {
    min-width: 360px;
}
.col-supplier-cell {
    min-width: 120px;
}
</style>

<x-ui.toolbar class="tw-mb-0">
    <form method="GET" action="{{ route('purchasing.comparison.inter-supplier') }}" class="tw-grid tw-w-full tw-gap-4 md:tw-grid-cols-[minmax(0,1fr)_auto] md:tw-items-end" id="interSupplierFilterForm" data-server-tabs-form>
        <div>
            <label for="comparisonPrSearch" class="form-label small fw-bold">Purchase Requisition</label>
            <div class="position-relative">
                <input type="hidden" name="pr_id" id="comparisonPrId" value="{{ $selectedPrOption['id'] ?? '' }}">
                <div class="input-group input-group-sm">
                    <span class="input-group-text tw-bg-surface"><x-ui.icon name="search" /></span>
                    <input type="text"
                            class="form-control"
                            id="comparisonPrSearch"
                            value="{{ $selectedPrOption['label'] ?? '' }}"
                            placeholder="Type a PR number or period..."
                            autocomplete="off">
                    <x-ui.icon-button
                        icon="x"
                        label="Clear PR selection"
                        size="sm"
                        id="comparisonPrClear"
                        class="tw-rounded-none {{ $selectedPrOption ? '' : 'd-none' }}"
                    />
                </div>
                <div class="list-group position-absolute w-100 shadow-sm d-none tw-z-[1050] tw-max-h-[260px] tw-overflow-y-auto"
                     id="comparisonPrSuggestions"></div>
            </div>
            <div class="form-text">Type to display PR options, then select one option.</div>
        </div>
        <div>
            <x-ui.button type="submit" size="sm" class="tw-w-full md:tw-w-auto">
                <x-slot:leading><x-ui.icon name="search" /></x-slot:leading>
                Compare
            </x-ui.button>
        </div>
    </form>
</x-ui.toolbar>

@if($comparison)
    @php
        $supplierTotals = [];
        $supplierWins = [];
        foreach($comparison['suppliers'] as $sup) {
            $supplierTotals[$sup['quotation_id']] = ['name' => $sup['name'], 'total' => 0];
            $supplierWins[$sup['quotation_id']] = 0;
        }

        foreach($comparison['matrix'] as &$row) {
            $idrPrices = collect($row['prices'])->pluck('price_idr')->filter()->values();
            $minIdr = $idrPrices->count() > 0 ? $idrPrices->min() : null;
            $maxIdr = $idrPrices->count() > 0 ? $idrPrices->max() : null;

            $row['spread_pct'] = 0;
            if($minIdr && $minIdr > 0 && $maxIdr) {
                $row['spread_pct'] = (($maxIdr - $minIdr) / $minIdr) * 100;
            }

            foreach($comparison['suppliers'] as $sup) {
                $p = $row['prices'][$sup['quotation_id']] ?? null;
                if($p && $p['is_available'] && $p['price_idr']) {
                    $supplierTotals[$sup['quotation_id']]['total'] += $p['offer_amount_idr'] ?? 0;
                    if($minIdr && $p['price_idr'] <= $minIdr) {
                        $supplierWins[$sup['quotation_id']]++;
                    }
                }
            }
        }
        unset($row);

        $validTotals = array_filter($supplierTotals, fn($v) => $v['total'] > 0);
        $recommendedSupId = null;
        if(count($validTotals) > 0) {
            $minTotal = min(array_column($validTotals, 'total'));
            foreach($validTotals as $qid => $data) {
                if($data['total'] == $minTotal) {
                    $recommendedSupId = $qid;
                    break;
                }
            }
        }
    @endphp

    {{-- Commercial overview --}}
    @if(count($validTotals) > 0)
        <x-ui.data-table
            title="Commercial Overview"
            description="Ranked supplier totals and line-item price leadership for the selected PR."
        >
            <table class="table table-hover align-middle mb-0 tw-text-ui-xs">
                <thead class="table-light">
                    <tr>
                        <th scope="col">Supplier</th>
                        <th scope="col" class="text-end">Estimated Total</th>
                        <th scope="col" class="text-center">Lowest-Price Items</th>
                        <th scope="col">Position</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($validTotals as $qid => $data)
                        <tr>
                            <td class="fw-semibold tw-text-on-surface">{{ $data['name'] }}</td>
                            <td class="text-end fw-bold ui-tabular-nums {{ $recommendedSupId === $qid ? 'text-success' : 'text-primary' }}">
                                Rp {{ \App\Support\NumberFormat::maxDecimals($data['total']) }}
                            </td>
                            <td class="text-center ui-tabular-nums">{{ $supplierWins[$qid] }}</td>
                            <td>
                                @if($recommendedSupId === $qid)
                                    <x-ui.status-chip tone="success" icon="star">Best Total</x-ui.status-chip>
                                @else
                                    <x-ui.status-chip tone="neutral">Alternative</x-ui.status-chip>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-ui.data-table>
    @endif

    {{-- Grafik Batang --}}
    <x-ui.card title="Price Comparison Chart per Material (IDR/Kg)">
        <x-slot:actions>
            <div class="tw-min-w-60">
                <label class="form-label small fw-bold mb-1" for="comparisonMaterialFilter">Material</label>
                <select class="form-select form-select-sm" id="comparisonMaterialFilter">
                    <option value="">All Material</option>
                    @foreach($materialOptions as $material)
                        <option value="{{ $material->id }}">{{ $material->material_name }}</option>
                    @endforeach
                </select>
            </div>
        </x-slot:actions>
        <div class="tw-min-h-[280px]">
            <canvas id="comparisonChart" height="280" role="img" aria-label="Supplier quotation price comparison">Supplier quotation price comparison chart.</canvas>
        </div>
    </x-ui.card>

    {{-- Item-Level Selection Coverage Banner --}}
    @if($awardCoverage)
        <div class="tw-p-4 tw-rounded tw-border tw-border-outline-variant tw-bg-surface-container tw-flex tw-flex-wrap tw-items-center tw-justify-between tw-gap-3">
            <div class="tw-flex tw-items-center tw-gap-3">
                <div class="tw-rounded-full tw-p-2.5 tw-bg-primary-container tw-text-primary">
                    <x-ui.icon name="clipboard-check" size="md" />
                </div>
                <div>
                    <div class="fw-bold tw-text-ui-sm tw-text-on-surface">Item Selection Status</div>
                    <div class="tw-text-ui-xs tw-text-on-surface-variant">
                        {{ $awardCoverage['awarded_items'] }} of {{ $awardCoverage['total_items'] }} items selected ({{ $awardCoverage['coverage_percentage'] }}% coverage)
                    </div>
                </div>
            </div>
            <div class="tw-flex tw-items-center tw-gap-2">
                @if($awardCoverage['is_fully_awarded'])
                    <span class="ui-status-chip ui-status-chip--success">100% Fully Selected</span>
                @else
                    <span class="ui-status-chip ui-status-chip--warning">{{ $awardCoverage['unawarded_items'] }} Item(s) Pending Selection</span>
                @endif
            </div>
        </div>
    @endif

    <form method="POST" action="{{ route('purchasing.comparison.save-awards') }}" id="itemAwardForm" class="tw-min-w-0 tw-max-w-full">
        @csrf
        <input type="hidden" name="pr_id" value="{{ $selectedPr->getRouteKey() }}">

        {{-- Side-by-side comparison table --}}
        <x-ui.data-table :title="'Comparison Table - ' . $selectedPr->pr_number" description="Select supplier offers per PR item. Maximum one offer per item." class="tw-min-w-0 tw-max-w-full">
                <table class="table table-bordered table-hover align-middle mb-0 tw-text-ui-xs comparison-matrix-table">
                    <thead class="table-light text-center">
                        <tr>
                            <th scope="col" rowspan="2" class="align-middle col-sticky-material">Material</th>
                            <th scope="col" rowspan="2" class="align-middle col-ref-qty text-center">Qty</th>
                            <th scope="col" rowspan="2" class="align-middle col-ref-weight text-center">Weight/Unit (Kg)</th>
                            <th scope="col" rowspan="2" class="align-middle col-ref-total-weight text-center">Total Weight (Kg)</th>
                            @foreach($comparison['suppliers'] as $sup)
                                <th scope="colgroup" colspan="3" class="text-center col-supplier-group">
                                    <div class="fw-bold tw-text-on-surface">{{ $sup['name'] }}</div>
                                    <div class="tw-mt-1">
                                        <x-ui.status-chip :tone="$sup['status'] === 'accepted' ? 'success' : ($sup['status'] === 'rejected' ? 'error' : 'info')" size="sm">
                                            {{ strtoupper($sup['status']) }}
                                        </x-ui.status-chip>
                                    </div>
                                    <div class="tw-mt-1.5 tw-text-ui-xs tw-text-on-surface-variant" title="Supplier Original Estimated Ready / Dispatch Date">
                                        <span class="fw-semibold">Ready / Dispatch:</span>
                                        <span class="tw-text-on-surface">{{ $sup['estimated_delivery_formatted'] ?? '-' }}</span>
                                    </div>
                                </th>
                            @endforeach
                        </tr>
                        <tr>
                            @foreach($comparison['suppliers'] as $sup)
                                <th scope="col" class="text-center small col-supplier-cell">Price/Kg ({{ $sup['currency'] }})</th>
                                <th scope="col" class="text-center small col-supplier-cell">Price/Kg (IDR)</th>
                                <th scope="col" class="text-center small col-supplier-cell">Offer Amount</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($comparison['matrix'] as $row)
                            @php
                                $idrPrices = collect($row['prices'])->pluck('price_idr')->filter()->values();
                                $minIdr = $idrPrices->count() > 0 ? $idrPrices->min() : null;
                            @endphp
                            <tr data-comparison-row data-material-id="{{ $row['item']->id }}" class="{{ ($row['spread_pct'] ?? 0) > 15 ? 'bg-warning bg-opacity-10' : '' }}">
                                <td class="fw-medium col-sticky-material">
                                    <div class="tw-font-semibold tw-text-on-surface">{{ $row['item']->material_name }}</div>
                                    @if(($row['spread_pct'] ?? 0) > 15)
                                        <div class="small text-danger mt-1" data-bs-toggle="tooltip" title="High price spread (>15%)">
                                            <x-ui.icon name="triangle-alert" class="me-1" />Spread {{ number_format($row['spread_pct'], 1) }}%
                                        </div>
                                    @endif
                                </td>
                                <td class="text-center col-ref-qty">{{ number_format($row['item']->quantity_value, 0) }}</td>
                                <td class="text-center col-ref-weight">{{ \App\Support\NumberFormat::maxDecimals($row['item']->weight_needed) }}</td>
                                <td class="text-center fw-medium text-primary col-ref-total-weight">{{ \App\Support\NumberFormat::maxDecimals($row['item']->total_weight) }}</td>
                                @foreach($comparison['suppliers'] as $sup)
                                    @php $p = $row['prices'][$sup['quotation_id']] ?? null; @endphp
                                    @if($p && !$p['is_available'])
                                        <td class="text-center" colspan="3">
                                            <span class="ui-status-chip ui-status-chip--error">Not Available</span>
                                            @if($p['detail_url'])
                                                <x-ui.icon-button :href="$p['detail_url']" icon="external-link" label="Open quotation details" size="sm" class="tw-ms-1" />
                                            @endif
                                        </td>
                                    @elseif($p && $p['price_per_kg'])
                                        <td class="text-end">
                                            {{ \App\Support\NumberFormat::maxDecimals($p['price_per_kg'], 4) }}
                                            <div class="tw-text-on-surface-variant tw-text-ui-xs tw-mt-0.5">
                                                Qty {{ $p['available_qty'] ?? '-' }} · {{ \App\Support\NumberFormat::maxDecimals($p['offered_total_weight']) }} kg
                                                @if($p['is_estimated_weight']) · Est Weight @endif
                                            </div>
                                        </td>
                                        <td class="text-end fw-bold {{ ($p['price_idr'] && $minIdr && $p['price_idr'] <= $minIdr) ? 'text-success bg-success bg-opacity-10' : '' }}">
                                            Rp {{ \App\Support\NumberFormat::maxDecimals($p['price_idr']) }}
                                            @if($p['price_idr'] && $minIdr && $p['price_idr'] <= $minIdr)
                                                <x-ui.icon name="circle-check" class="ms-1" />
                                            @endif
                                            @if($p['detail_url'])
                                                <x-ui.icon-button :href="$p['detail_url']" icon="external-link" label="Open quotation details" size="sm" class="tw-ms-1" />
                                            @endif
                                        </td>
                                        <td class="text-end fw-semibold ui-tabular-nums">
                                            {{ \App\Support\NumberFormat::maxDecimals($p['offer_amount']) }} {{ $p['currency'] }}
                                            <div class="tw-text-on-surface-variant tw-text-ui-xs">Rp {{ \App\Support\NumberFormat::maxDecimals($p['offer_amount_idr']) }}</div>
                                            @if(!empty($p['is_selectable']))
                                                <div class="tw-mt-2 tw-pt-1.5 tw-border-t tw-border-outline-variant text-center">
                                                    <label class="tw-inline-flex tw-items-center tw-gap-1.5 tw-cursor-pointer">
                                                        <input type="radio"
                                                               name="awards[{{ $row['item']->id }}]"
                                                               value="{{ $p['quotation_item_id'] }}"
                                                               {{ !empty($p['is_awarded']) ? 'checked' : '' }}
                                                               class="form-check-input tw-mt-0 award-radio"
                                                               data-pr-item-id="{{ $row['item']->id }}"
                                                               data-pr-item-name="{{ $row['item']->material_name }}"
                                                               data-supplier-id="{{ $sup['id'] }}"
                                                               data-supplier-name="{{ $sup['name'] }}"
                                                               data-supplier-estimated-ready="{{ $sup['estimated_delivery'] ?? '' }}"
                                                               data-price="Rp {{ \App\Support\NumberFormat::maxDecimals($p['price_idr'] ?? 0) }}"
                                                        >
                                                        <span class="tw-text-ui-xs fw-bold {{ !empty($p['is_awarded']) ? 'text-success' : 'text-primary' }}">
                                                            {{ !empty($p['is_awarded']) ? 'Selected Offer' : 'Select Offer' }}
                                                        </span>
                                                    </label>
                                                </div>
                                            @elseif(!empty($p['is_awarded']))
                                                <div class="tw-mt-2 tw-pt-1.5 tw-border-t tw-border-outline-variant text-center">
                                                    <span class="ui-status-chip ui-status-chip--success">
                                                        <x-ui.icon name="check" size="sm" /> PO Created
                                                    </span>
                                                    @if(!empty($p['purchase_order_url']))
                                                        <a href="{{ $p['purchase_order_url'] }}" class="d-block tw-mt-1 tw-text-ui-xs text-primary fw-semibold text-decoration-underline">
                                                            {{ $p['purchase_order_number'] ?? 'View Purchase Order' }}
                                                        </a>
                                                    @else
                                                        <span class="d-block tw-mt-1 tw-text-ui-xs tw-text-on-surface-variant">Purchase Order assigned</span>
                                                    @endif
                                                </div>
                                            @endif
                                        </td>
                                    @else
                                        <td class="text-center text-muted" colspan="3">- no quotation -</td>
                                    @endif
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
        </x-ui.data-table>

        @if($hasActionableAwardSelections)
            {{-- Existing PO assignments remain visible while unassigned items stay actionable. --}}
            @if($assignedPurchaseOrders->isNotEmpty() || $assignedPurchaseOrderCount > 0)
                <x-ui.card title="Existing Purchase Order(s)" class="tw-mt-4">
                    <div class="tw-flex tw-flex-wrap tw-gap-2">
                        @forelse($assignedPurchaseOrders as $assignedPurchaseOrder)
                            <a href="{{ \App\Support\PurchasingNavigation::toRoute('purchasing.purchase-orders.show', $assignedPurchaseOrder) }}" class="ui-status-chip ui-status-chip--success text-decoration-none">
                                <x-ui.icon name="receipt" size="sm" /> {{ $assignedPurchaseOrder->po_number }}
                            </a>
                        @empty
                            <span class="tw-text-ui-xs tw-text-on-surface-variant">Purchase Order assignment is recorded, but its detail is unavailable.</span>
                        @endforelse
                    </div>
                </x-ui.card>
            @endif

            {{-- Offer Selection & PO Grouping Preview Card --}}
            <x-ui.card title="Offer Selection & PO Grouping Preview" class="tw-mt-4">
                <div class="tw-grid tw-gap-4 md:tw-grid-cols-2">
                    <div>
                        <div class="fw-bold tw-text-ui-sm tw-text-on-surface tw-mb-2">
                            <x-ui.icon name="check-check" size="sm" class="me-1 text-primary" />
                            Selected Line-Item Offers
                        </div>
                        <div id="awardPreviewItems" class="tw-border tw-border-outline-variant tw-rounded tw-p-3 tw-bg-surface tw-text-ui-xs">
                            <span class="tw-text-on-surface-variant">Select offers in the table above to preview selection and supplier PO groups.</span>
                        </div>
                    </div>
                    <div>
                        <div class="fw-bold tw-text-ui-sm tw-text-on-surface tw-mb-2">
                            <x-ui.icon name="building" size="sm" class="me-1 text-primary" />
                            Resulting PO Grouping Preview (1 PO per Selected Supplier)
                        </div>
                        <div id="supplierGroupPreview" class="tw-border tw-border-outline-variant tw-rounded tw-p-3 tw-bg-surface-container tw-text-ui-xs">
                            <span class="tw-text-on-surface-variant">1 PO will be created per selected supplier group upon confirmation.</span>
                        </div>
                    </div>
                </div>

                <div class="tw-mt-4 tw-pt-4 tw-border-t tw-border-outline-variant tw-grid tw-gap-3 sm:tw-grid-cols-2">
                    <div>
                        <x-ui.date-picker
                            id="poEstimatedArrival"
                            name="estimated_arrival"
                            label="PO Target Arrival Date"
                            :value="now()->addDays(14)->format('Y-m-d')"
                        />
                        <div class="form-text tw-text-ui-xs tw-text-on-surface-variant tw-mt-1">
                            <span class="fw-semibold">Supplier Ready / Dispatch Date:</span> when Supplier expects material ready to send.<br>
                            <span class="fw-semibold">PO Target Arrival Date:</span> when Purchasing expects material at ADSI.
                        </div>
                        <div id="targetArrivalWarning" class="alert alert-warning py-1.5 px-2.5 tw-text-ui-xs tw-mt-2 d-none" role="alert">
                            <x-ui.icon name="triangle-alert" size="sm" class="me-1 text-warning" />
                            <span>PO Target Arrival Date is earlier than the selected Supplier's estimated ready / dispatch date. Review the target before generating the PO.</span>
                        </div>
                    </div>
                    <div>
                        <label for="poNotes" class="form-label small fw-semibold">PO Notes / Remarks</label>
                        <input type="text" name="notes" id="poNotes" class="form-control form-control-sm" placeholder="Optional notes for generated PO(s)...">
                    </div>
                </div>

                <x-slot:actions>
                    <div class="tw-flex tw-flex-wrap tw-gap-2">
                        <x-ui.button type="submit" name="action" value="save" variant="outline" size="sm">
                            <x-slot:leading><x-ui.icon name="save" size="sm" /></x-slot:leading>
                            Save Selected Offers
                        </x-ui.button>
                        <x-ui.button type="submit" name="action" value="generate_pos" variant="primary" size="sm" id="btnGeneratePos">
                            <x-slot:leading><x-ui.icon name="receipt" size="sm" /></x-slot:leading>
                            Confirm Selection &amp; Generate PO(s)
                        </x-ui.button>
                    </div>
                </x-slot:actions>
            </x-ui.card>
        @elseif($allItemsAssignedToPurchaseOrder)
            <x-ui.card title="Purchase Order(s) Already Generated" class="tw-mt-4">
                <div class="tw-flex tw-flex-col tw-gap-3">
                    <div class="tw-text-ui-sm tw-text-on-surface-variant">
                        All PR items have been assigned to Purchase Order(s). No additional selection or PO action is required.
                    </div>
                    <div class="tw-flex tw-flex-wrap tw-gap-2">
                        @forelse($assignedPurchaseOrders as $assignedPurchaseOrder)
                            <a href="{{ \App\Support\PurchasingNavigation::toRoute('purchasing.purchase-orders.show', $assignedPurchaseOrder) }}" class="ui-status-chip ui-status-chip--success text-decoration-none">
                                <x-ui.icon name="receipt" size="sm" /> {{ $assignedPurchaseOrder->po_number }}
                            </a>
                        @empty
                            <span class="tw-text-ui-xs tw-text-on-surface-variant">Purchase Order assignment is recorded, but its detail is unavailable.</span>
                        @endforelse
                    </div>
                </div>
            </x-ui.card>
        @else
            <x-ui.card title="Item Selection Status" class="tw-mt-4">
                <div class="tw-text-ui-sm tw-text-on-surface-variant">No additional offer selections are currently available for this PR.</div>
            </x-ui.card>
        @endif
    </form>
@elseif(request('pr_id'))
    <x-ui.alert tone="warning">No data found for the selected PR.</x-ui.alert>
@else
    <x-ui.card padding="none">
        <x-ui.empty-state icon="chart-no-axes-combined" title="Select a PR to compare" description="Choose an eligible purchase requisition above to compare supplier prices." />
    </x-ui.card>
@endif

<script id="interSupplierConfig" type="application/json">
{
    "eligiblePrOptions": @json($eligiblePrOptions),
    "chartData": @json($chartData),
    "chartMaterialIds": @json($chartMaterialIds)
}
</script>
