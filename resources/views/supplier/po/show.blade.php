@extends('layouts.app')

@section('title', 'PO Details: ' . $po->po_number . ' - ADASI Portal')
@section('page-title', 'Purchase Order Details')

@section('content')
<div class="tw-grid tw-gap-4">
    {{-- Breadcrumb & Compact Page Header --}}
    <x-ui.breadcrumb :items="[
        'Dashboard' => route('supplier.dashboard'),
        'Purchase Orders' => route('supplier.purchase-orders.index'),
        $po->po_number => null,
    ]" />

    <x-ui.page-header
        :title="$po->po_number"
        eyebrow="Supplier Purchase Order"
        description="Review commercial parameters, ordered material lines, customs documentation progress, and quality claim records."
    >
        <x-slot:actions>
            <x-status-badge type="po" :status="$po->status" :is-overdue="$po->is_overdue" />
            <x-ui.button :href="route('supplier.export.purchase-orders.detail', $po)" variant="outline" size="sm" data-async-export data-export-source-singular="purchase order" data-export-source-plural="purchase orders" data-export-source-count="1" data-export-filtered="false" data-export-row-label="ordered material rows" data-export-row-explanation="Each ordered material item will be written as a separate Excel row.">
                <x-ui.icon name="file-spreadsheet" />
                <span>Export Excel</span>
            </x-ui.button>
            <x-ui.button :href="route('shared.pdf.purchase-order', $po)" variant="danger" size="sm" target="_blank" title="Print Purchase Order" data-pdf-confirm>
                <x-ui.icon name="printer" />
                <span>Print PDF</span>
            </x-ui.button>
            <x-ui.button :href="route('supplier.purchase-orders.index')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" />
                <span>Back to POs</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- 4-Key Tracking Dates Strip --}}
    <div class="tw-grid tw-gap-px tw-overflow-hidden tw-rounded-ui-md tw-border tw-border-outline tw-bg-outline-variant sm:tw-grid-cols-2 lg:tw-grid-cols-4">
        @php
            $firstPr = $po->quotations->map(fn($q) => $q->purchaseRequisition)->filter()->first();
        @endphp
        <div class="tw-flex tw-items-center tw-gap-3 tw-bg-surface-container tw-p-3">
            <x-ui.icon name="file-plus" size="sm" class="tw-shrink-0 tw-text-on-surface-variant" />
            <div>
                <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">1. PR Issued</div>
                <div class="fw-bold tw-text-on-surface tw-text-ui-xs tw-mt-0.5">
                    {{ $firstPr?->created_at ? $firstPr->created_at->format('d M Y') : '-' }}
                </div>
            </div>
        </div>

        <div class="tw-flex tw-items-center tw-gap-3 tw-bg-surface-container tw-p-3">
            <x-ui.icon name="receipt" size="sm" class="tw-shrink-0 tw-text-primary" />
            <div>
                <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">2. PO Created</div>
                <div class="fw-bold tw-text-on-surface tw-text-ui-xs tw-mt-0.5">
                    {{ $po->created_at->format('d M Y') }}
                </div>
            </div>
        </div>

        <div class="tw-flex tw-items-center tw-gap-3 tw-bg-surface-container tw-p-3">
            <x-ui.icon name="calendar" size="sm" class="tw-shrink-0 {{ $po->is_overdue ? 'tw-text-error' : 'tw-text-on-surface-variant' }}" />
            <div>
                <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">3. PO Target Arrival Date</div>
                <div class="fw-bold {{ $po->is_overdue ? 'text-danger' : 'tw-text-on-surface' }} tw-text-ui-xs tw-mt-0.5">
                    {{ $po->estimated_arrival ? $po->estimated_arrival->format('d M Y') : '-' }}
                    @if($po->is_overdue) <span class="ui-status-chip ui-status-chip--error ms-1">Overdue</span> @endif
                </div>
                <div class="tw-text-on-surface-variant tw-text-ui-xs">Purchasing target at ADSI</div>
            </div>
        </div>

        <div class="tw-flex tw-items-center tw-gap-3 tw-bg-surface-container tw-p-3">
            <x-ui.icon name="{{ $po->actual_arrival ? 'circle-check' : 'clock' }}" size="sm" class="tw-shrink-0 {{ $po->actual_arrival ? 'tw-text-success' : 'tw-text-on-surface-variant' }}" />
            <div>
                <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">4. Actual Arrival</div>
                <div class="fw-bold {{ $po->actual_arrival ? 'text-success' : 'tw-text-on-surface-variant' }} tw-text-ui-xs tw-mt-0.5">
                    {{ $po->actual_arrival ? $po->actual_arrival->format('d M Y') : 'In Transit' }}
                </div>
            </div>
        </div>
    </div>

    {{-- Main Content & Sidebar --}}
    <div class="tw-grid tw-items-start tw-gap-4 xl:tw-grid-cols-[minmax(0,2fr)_minmax(19rem,1fr)]">
        {{-- Main Column: Materials Table --}}
        <div class="tw-grid tw-min-w-0 tw-gap-4">
            {{-- Supplier Material Progress Section --}}
            @if(isset($itemProjections) && $itemProjections->isNotEmpty())
                <x-ui.data-table
                    title="Supplier Material Progress"
                    description="Update operational manufacturing and preparation status per awarded item before shipment dispatch."
                    id="sec-material-progress"
                >
                    <x-slot:actions>
                        @if(isset($poSummary))
                            <span class="ui-status-chip ui-status-chip--info">
                                {{ $poSummary['text'] }}
                            </span>
                        @endif
                    </x-slot:actions>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 tw-text-ui-xs w-100">
                            <thead class="table-light text-center">
                                <tr>
                                    <th scope="col" style="width: 35px;">No</th>
                                    <th scope="col" class="text-start">Material</th>
                                    <th scope="col">Ordered</th>
                                    <th scope="col">Accepted</th>
                                    <th scope="col">In Transit</th>
                                    <th scope="col">Supplier-Controlled</th>
                                    <th scope="col">Current Progress</th>
                                    <th scope="col">Original Ready Date</th>
                                    <th scope="col">Current Estimated Ready</th>
                                    <th scope="col">Last Update</th>
                                    <th scope="col" class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($itemProjections as $idx => $p)
                                    <tr>
                                        <td class="text-center tw-text-on-surface-variant ui-tabular-nums">{{ $idx + 1 }}</td>
                                        <td class="text-start">
                                            <div class="fw-bold tw-text-on-surface">{{ $p['material_name'] }}</div>
                                            @if($p['hs_code'])
                                                <div class="tw-text-on-surface-variant tw-text-ui-xs">HS: {{ $p['hs_code'] }}</div>
                                            @endif
                                        </td>
                                        <td class="text-center ui-tabular-nums fw-semibold">{{ $p['ordered_qty'] }} pcs</td>
                                        <td class="text-center ui-tabular-nums text-success fw-semibold">{{ $p['accepted_qty'] }} pcs</td>
                                        <td class="text-center ui-tabular-nums text-primary fw-semibold">{{ $p['in_transit_qty'] }} pcs</td>
                                        <td class="text-center ui-tabular-nums fw-bold">
                                            <x-ui.status-chip :tone="$p['supplier_controlled_qty'] > 0 ? 'info' : 'neutral'" size="sm">
                                                {{ $p['supplier_controlled_qty'] }} pcs
                                            </x-ui.status-chip>
                                        </td>
                                        <td class="text-center">
                                            <span class="ui-status-chip ui-status-chip--{{ $p['manual_progress_tone'] }}">
                                                {{ $p['manual_progress_label'] }}
                                            </span>
                                        </td>
                                        <td class="text-center ui-tabular-nums tw-text-on-surface-variant" title="Original quotation ready/dispatch commitment">
                                            {{ $p['original_supplier_ready_date'] ? \Carbon\Carbon::parse($p['original_supplier_ready_date'])->format('d M Y') : '-' }}
                                        </td>
                                        <td class="text-center ui-tabular-nums fw-semibold {{ $p['current_estimated_ready_date'] ? 'text-primary' : 'tw-text-on-surface-variant' }}">
                                            {{ $p['current_estimated_ready_date'] ? $p['current_estimated_ready_date']->format('d M Y') : '-' }}
                                        </td>
                                        <td class="text-center tw-text-on-surface-variant" style="font-size: 0.75rem;">
                                            @if($p['last_progress_update_at'])
                                                <div>{{ $p['last_progress_update_at']->format('d M Y H:i') }}</div>
                                                <div class="tw-text-outline">{{ $p['last_updated_by'] ?? 'Supplier' }}</div>
                                            @else
                                                <span class="text-muted">No updates</span>
                                            @endif
                                        </td>
                                        <td class="text-end">
                                            <div class="d-inline-flex gap-1 justify-content-end">
                                                @if($p['can_update'])
                                                    <button type="button"
                                                            class="btn btn-outline-primary btn-sm px-2 py-1 tw-text-ui-xs"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#updateProgressModal{{ $p['award_id'] }}">
                                                        Update
                                                    </button>
                                                @else
                                                    <span class="badge bg-light text-muted border tw-text-ui-xs" title="All quantity is already allocated to shipment or PO is closed">
                                                        Fully Dispatched
                                                    </span>
                                                @endif
                                                <button type="button"
                                                        class="btn btn-outline-secondary btn-sm px-2 py-1 tw-text-ui-xs btn-view-progress-history"
                                                        data-award-id="{{ $p['award_hashid'] }}"
                                                        data-material-name="{{ $p['material_name'] }}"
                                                        data-history-url="{{ route('supplier.purchase-orders.item-progress.history', ['po_id' => $po, 'award_id' => $p['award']]) }}">
                                                    History
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-ui.data-table>
            @endif

            <x-ui.data-table
                title="Ordered Material Breakdown"
                description="Line items consolidated from your accepted quotation offers."
            >
                <table class="table table-hover align-middle mb-0 tw-text-ui-xs w-100">
                    <thead class="table-light text-center">
                        <tr>
                            <th scope="col" class="tw-w-10">No</th>
                            <th scope="col" class="text-start">Material</th>
                            <th scope="col">Reference (No. PR)</th>
                            <th scope="col">Remark</th>
                            <th scope="col">Qty</th>
                            <th scope="col" class="text-end">Weight/Unit</th>
                            <th scope="col" class="text-end">Total Weight</th>
                            <th scope="col" class="text-end">Price/Kg</th>
                            <th scope="col" class="text-end">Amount</th>
                            <th scope="col" class="text-end">Amount (IDR)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php
                            $totalAmount = 0;
                            $totalIdr = 0;
                            $no = 1;
                            $poRemark = trim((string) $po->notes);
                        @endphp
                        @foreach($po->commercialQuotations() as $quotation)
                            @php $rate = $quotationRates[$quotation->id] ?? null; @endphp
                            @if($po->quotations->count() > 1)
                                <tr class="table-primary">
                                    <td colspan="10" class="fw-bold ps-3 tw-text-ui-xs">
                                    <x-ui.icon name="folder" size="sm" class="me-1" />
                                        {{ $quotation->purchaseRequisition->pr_number ?? 'PR -' }}
                                        <span class="tw-text-on-surface-variant fw-normal ms-2">
                                            @if($rate)
                                                &bull; Exchange Rate: 1 {{ $quotation->currency }} = Rp {{ \App\Support\NumberFormat::maxDecimals($rate->rate_to_idr) }}
                                            @endif
                                        </span>
                                    </td>
                                </tr>
                            @endif
                            @foreach($quotation->items as $item)
                                @php
                                    $amount = $item->resolved_amount;
                                    $idr = $amount * ($rate ? $rate->rate_to_idr : 1);
                                    $totalAmount += $amount;
                                    $totalIdr += $idr;
                                @endphp
                                <tr>
                                    <td class="text-center tw-text-on-surface-variant ui-tabular-nums">{{ $no++ }}</td>
                                    <td class="text-start tw-text-on-surface">
                                        <div class="fw-bold">{{ $item->prItem->material_name }}</div>
                                        @if($item->is_available)
                                            <div class="tw-text-on-surface-variant tw-text-ui-xs">Offer: {{ $item->available_dimension_label }}</div>
                                        @else
                                            <span class="ui-status-chip ui-status-chip--error tw-mt-0.5">Not Available</span>
                                        @endif
                                    </td>
                                    <td class="text-start">
                                        @if($quotation->purchaseRequisition)
                                            <a href="{{ route('supplier.quotations.show', $quotation) }}" class="text-primary fw-semibold text-decoration-none d-inline-flex align-items-center gap-1" title="Open related quotation">
                                                <span>{{ $quotation->purchaseRequisition->pr_number ?? '-' }}</span>
                                            <x-ui.icon name="external-link" size="sm" />
                                            </a>
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td class="text-start">
                                        @if($poRemark !== '')
                                            <span class="d-inline-block text-truncate tw-text-on-surface-variant tw-max-w-[180px]" title="{{ $poRemark }}">
                                                {{ \Illuminate\Support\Str::limit($poRemark, 40) }}
                                            </span>
                                        @else
                                            <span class="tw-text-outline">-</span>
                                        @endif
                                    </td>
                                    <td class="text-center fw-bold ui-tabular-nums">
                                        @if($item->is_available)
                                            {{ $item->available_qty ?? $item->prItem->quantity_value }}
                                        @else
                                            <span class="ui-status-chip ui-status-chip--error">Not Available</span>
                                        @endif
                                    </td>
                                    <td class="text-end ui-tabular-nums tw-text-on-surface-variant">
                                        {{ $item->is_available ? \App\Support\NumberFormat::maxDecimals($item->offered_weight_per_unit ?? $item->prItem->weight_needed) : '—' }}
                                        @if($item->is_available && $item->is_estimated_weight)<span class="ui-status-chip ui-status-chip--warning ms-1">Est Weight</span>@endif
                                    </td>
                                    <td class="text-end fw-bold text-primary ui-tabular-nums">{{ $item->is_available ? \App\Support\NumberFormat::maxDecimals($item->offered_total_weight ?? $item->prItem->total_weight) : '—' }}</td>
                                    <td class="text-end ui-tabular-nums">
                                        {{ \App\Support\NumberFormat::maxDecimals($item->price_per_kg, 4) }}
                                    </td>
                                    <td class="text-end fw-semibold ui-tabular-nums">{{ $item->is_available ? \App\Support\NumberFormat::maxDecimals($amount) : '—' }}</td>
                                    <td class="text-end fw-bold tw-text-on-surface ui-tabular-nums">{{ $item->is_available ? 'Rp '.\App\Support\NumberFormat::maxDecimals($idr) : '—' }}</td>
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                    <tfoot class="table-light fw-bold border-top">
                        <tr>
                            <td colspan="8" class="text-end tw-text-on-surface">TOTAL:</td>
                            <td class="text-end tw-text-on-surface ui-tabular-nums">{{ \App\Support\NumberFormat::maxDecimals($totalAmount) }} {{ $po->currency }}</td>
                            <td class="text-end text-primary ui-tabular-nums fs-6">Rp {{ \App\Support\NumberFormat::maxDecimals($totalIdr) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </x-ui.data-table>
        </div>

        {{-- Sidebar Column: Details & Claim Notice --}}
        <aside class="tw-grid tw-gap-4">
            @php
                $pendingClaim = $po->materialClaims->where('status', 'pending')->sortByDesc('created_at')->first();
                $latestClaim = $po->materialClaims->sortByDesc('created_at')->first();
            @endphp

            @if($pendingClaim || $latestClaim)
                <x-ui.card title="Material Claim Notice" class="border-danger">
                    <x-slot:actions>
                        <span class="ui-status-chip {{ $pendingClaim ? 'ui-status-chip--error' : 'ui-status-chip--neutral' }}">
                            {{ $pendingClaim ? 'Action Required' : 'Claim Logged' }}
                        </span>
                    </x-slot:actions>

                    @if($pendingClaim)
                        <p class="tw-text-on-surface-variant tw-text-ui-xs mb-3">
                            ADASI Quality Control has submitted an NG defect claim for this order. Please respond before the deadline.
                        </p>
                        <x-ui.button :href="route('supplier.claims.show', $pendingClaim)" variant="danger" size="sm" class="tw-w-full">
                            <x-ui.icon name="reply" size="sm" />
                            <span>Respond to Claim</span>
                        </x-ui.button>
                    @else
                        <p class="tw-text-on-surface-variant tw-text-ui-xs mb-3">
                            This purchase order has historical claim resolutions recorded.
                        </p>
                        <x-ui.button :href="route('supplier.claims.show', $latestClaim)" variant="outline" size="sm" class="tw-w-full">
                            <x-ui.icon name="octagon-alert" size="sm" />
                            <span>View Claim History</span>
                        </x-ui.button>
                    @endif
                </x-ui.card>
            @endif

            {{-- PO Commercial Parameters --}}
            <x-ui.card title="Order Information">
                <div class="tw-grid tw-gap-2.5">
                    <div class="tw-p-2.5 tw-bg-surface-container border rounded">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">Reference (No. PR)</div>
                        <div class="fw-bold text-primary tw-text-ui-sm tw-mt-0.5">{{ $po->pr_reference }}</div>
                    </div>
                    <div class="tw-p-2.5 tw-bg-surface-container border rounded">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">Currency</div>
                        <div class="fw-bold tw-text-on-surface tw-text-ui-sm tw-mt-0.5">{{ $po->currency }}</div>
                    </div>
                    <div class="tw-p-2.5 tw-bg-surface-container border rounded">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">Order Remarks</div>
                        <div class="tw-text-on-surface tw-text-ui-xs tw-mt-0.5">{{ $po->notes ?: 'No special notes recorded.' }}</div>
                    </div>
                </div>
            </x-ui.card>

            {{-- Read-Only Customs Documents Tracking --}}
            <x-ui.card title="Customs Documentation Progress" description="Document status synchronized with your shipments.">
                @php
                    $customsSummary = $customsSummary ?? $po->customsDocumentationSummary();
                    $docIcons = [
                        'invoice' => 'receipt',
                        'bl' => 'truck',
                        'packing_list' => 'list-checks',
                        'form_e' => 'file-badge',
                    ];
                    $statusLabels = [
                        'pending' => 'Not Available',
                        'received' => 'Received',
                        'verified' => 'Verified',
                        'issued' => 'Issued',
                        'processing' => 'Processing',
                        'done' => 'Completed'
                    ];
                @endphp
                <div class="tw-grid tw-gap-2.5">
                    @foreach($customsSummary as $docType => $item)
                        @php
                            $chipClasses = match($item['status']) {
                                'pending' => 'bg-light text-muted border border-secondary-subtle',
                                'received', 'issued', 'processing' => 'ui-status-chip--info',
                                'verified', 'done' => 'ui-status-chip--success',
                                default => 'ui-status-chip--neutral'
                            };
                            $shipmentDocs = collect($item['shipment_documents'] ?? []);
                            $uploadedShipmentDocs = $shipmentDocs->filter(fn($sd) => !empty($sd['attachment']));
                        @endphp
                        <div class="tw-border tw-border-outline-variant/70 tw-bg-surface tw-rounded-lg tw-p-3 tw-text-ui-xs tw-shadow-2xs">
                            <div class="tw-flex tw-items-center tw-justify-between tw-gap-2">
                                <div class="tw-flex tw-items-center tw-gap-2 tw-min-w-0">
                                    <x-ui.icon :name="$docIcons[$docType] ?? 'file-text'" size="sm" class="{{ $item['status'] === 'pending' ? 'tw-text-outline' : 'text-primary' }} flex-shrink-0" />
                                    <span class="fw-semibold tw-text-on-surface text-truncate">{{ $item['label'] }}</span>
                                </div>
                                <span class="ui-status-chip {{ $chipClasses }} tw-shadow-2xs flex-shrink-0">
                                    {{ $statusLabels[$item['status']] ?? ucfirst($item['status']) }}
                                </span>
                            </div>

                            @if($uploadedShipmentDocs->isNotEmpty())
                                <div class="tw-mt-2.5 tw-pt-2.5 tw-border-t tw-border-outline-variant/60 tw-grid tw-gap-1.5">
                                    @foreach($uploadedShipmentDocs as $sDoc)
                                        <div class="tw-flex tw-items-center tw-justify-between tw-gap-2 tw-bg-surface-container-low tw-px-2.5 tw-py-1.5 tw-rounded-md tw-border tw-border-outline-variant/70">
                                            <a href="{{ route('supplier.shipments.show', $sDoc['shipment']) }}" class="text-primary fw-semibold text-decoration-none d-inline-flex align-items-center gap-1.5 text-truncate hover:tw-underline" title="Open shipment {{ $sDoc['shipment_number'] }}">
                                                <x-ui.icon name="truck" size="sm" class="flex-shrink-0" />
                                                <span class="text-truncate">{{ $sDoc['shipment_number'] }}</span>
                                            </a>
                                            <a href="{{ route('attachments.show', $sDoc['attachment']->id) }}" target="_blank" class="btn btn-xs btn-outline-primary py-0.5 px-2 tw-text-ui-xs d-inline-flex align-items-center gap-1 flex-shrink-0 rounded" title="View {{ $sDoc['attachment']->file_name }}">
                                                <x-ui.icon name="file-text" size="sm" />
                                                <span>View</span>
                                            </a>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-ui.card>
        </aside>
    </div>
</div>

{{-- Modals for Progress Updates --}}
@if(isset($itemProjections))
    @foreach($itemProjections as $p)
        @if($p['can_update'])
            <div class="modal fade" id="updateProgressModal{{ $p['award_id'] }}" tabindex="-1" aria-labelledby="updateProgressModalLabel{{ $p['award_id'] }}" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <form method="POST" action="{{ route('supplier.purchase-orders.item-progress.update', ['po_id' => $po, 'award_id' => $p['award']]) }}" class="progress-update-form">
                            @csrf
                            <div class="modal-header">
                                <h5 class="modal-title tw-text-ui-base fw-bold" id="updateProgressModalLabel{{ $p['award_id'] }}">
                                    Update Progress: {{ $p['material_name'] }}
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <div class="alert alert-info py-2 px-3 tw-text-ui-xs mb-3">
                                    <x-ui.icon name="info" size="sm" class="me-1" />
                                    This update applies to: <strong>{{ $p['supplier_controlled_qty'] }} pcs</strong> currently under Supplier control.
                                </div>

                                <div class="mb-3">
                                    <label class="form-label small fw-semibold">Progress Status <span class="text-danger">*</span></label>
                                    <select name="status" class="form-select form-select-sm progress-status-select" data-current-rank="{{ \App\Models\PoItemProgressUpdate::STAGE_RANKS[$p['manual_progress_status']] ?? 10 }}" required>
                                        <option value="awaiting_confirmation" {{ $p['manual_progress_status'] === 'awaiting_confirmation' ? 'selected' : '' }}>Awaiting Confirmation</option>
                                        <option value="order_confirmed" {{ $p['manual_progress_status'] === 'order_confirmed' ? 'selected' : '' }}>Order Confirmed</option>
                                        <option value="material_preparation" {{ $p['manual_progress_status'] === 'material_preparation' ? 'selected' : '' }}>Material Preparation</option>
                                        <option value="on_production" {{ $p['manual_progress_status'] === 'on_production' ? 'selected' : '' }}>On Production</option>
                                        <option value="ready_to_ship" {{ $p['manual_progress_status'] === 'ready_to_ship' ? 'selected' : '' }}>Ready to Ship</option>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <x-ui.date-picker
                                        name="estimated_ready_date"
                                        id="estimated_ready_date_{{ $p['award_id'] }}"
                                        label="Current Estimated Ready Date"
                                        value="{{ optional($p['current_estimated_ready_date'])->format('Y-m-d') }}"
                                        helper="Estimated forecast for when outstanding material will be ready for dispatch."
                                    />
                                </div>

                                <div class="mb-3">
                                    <label class="form-label small fw-semibold">
                                        Progress Note / Reason <span class="backward-required-marker text-danger d-none">*</span>
                                    </label>
                                    <textarea name="note" class="form-control form-control-sm progress-note-input" rows="3" placeholder="Provide details about current stage or operational updates..."></textarea>
                                    <div class="backward-notice alert alert-warning py-1.5 px-2.5 tw-text-ui-xs mt-1.5 d-none">
                                        <x-ui.icon name="triangle-alert" size="sm" class="me-1 text-warning" />
                                        <span>A note / reason is required because progress is moving backward.</span>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-primary btn-sm">Save Progress Update</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        @endif
    @endforeach
@endif

{{-- Shared Progress History Modal --}}
<div class="modal fade" id="progressHistoryModal" tabindex="-1" aria-labelledby="progressHistoryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title tw-text-ui-base fw-bold" id="progressHistoryModalLabel">
                    Material Progress History
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="progressHistoryModalBody">
                <div class="text-center py-4 text-muted">
                    <div class="spinner-border spinner-border-sm text-primary me-1" role="status"></div> Loading history...
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const STAGE_RANKS = {
        'awaiting_confirmation': 10,
        'order_confirmed': 20,
        'material_preparation': 30,
        'on_production': 40,
        'ready_to_ship': 50,
    };

    document.querySelectorAll('.progress-status-select').forEach(select => {
        select.addEventListener('change', function() {
            const form = this.closest('form');
            const currentRank = parseInt(this.dataset.currentRank, 10) || 10;
            const newRank = STAGE_RANKS[this.value] || 10;
            const isBackward = newRank < currentRank;

            const noteInput = form.querySelector('.progress-note-input');
            const notice = form.querySelector('.backward-notice');
            const marker = form.querySelector('.backward-required-marker');

            if (isBackward) {
                notice?.classList.remove('d-none');
                marker?.classList.remove('d-none');
                noteInput?.setAttribute('required', 'required');
            } else {
                notice?.classList.add('d-none');
                marker?.classList.add('d-none');
                noteInput?.removeAttribute('required');
            }
        });
    });

    document.querySelectorAll('.btn-view-progress-history').forEach(btn => {
        btn.addEventListener('click', function() {
            const materialName = this.dataset.materialName;
            const historyUrl = this.dataset.historyUrl;
            const modalEl = document.getElementById('progressHistoryModal');
            const titleEl = document.getElementById('progressHistoryModalLabel');
            const bodyEl = document.getElementById('progressHistoryModalBody');

            titleEl.textContent = `Progress History: ${materialName}`;
            bodyEl.innerHTML = '<div class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm text-primary me-1" role="status"></div> Loading history...</div>';

            const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
            modal.show();

            fetch(historyUrl, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(res => res.json())
            .then(data => {
                if (!data.history || data.history.length === 0) {
                    bodyEl.innerHTML = '<div class="text-center py-4 text-muted">No progress updates recorded yet. Default state is <strong>Awaiting Confirmation</strong>.</div>';
                    return;
                }

                let html = '<div class="tw-space-y-3">';
                data.history.forEach(item => {
                    html += `
                        <div class="tw-p-3 tw-rounded tw-border tw-border-outline-variant tw-bg-surface-container tw-text-ui-xs">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="ui-status-chip ui-status-chip--${item.status_tone} fw-bold">${item.status_label}</span>
                                <span class="text-muted">${item.created_at || '-'}</span>
                            </div>
                            <div class="tw-text-on-surface-variant mb-1">
                                <strong>Supplier-controlled Qty snapshot:</strong> ${item.supplier_controlled_qty_snapshot} pcs
                                ${item.estimated_ready_date ? ` &bull; <strong>Estimated Ready:</strong> ${item.estimated_ready_date}` : ''}
                                &bull; <strong>Updated by:</strong> ${item.updated_by}
                            </div>
                            ${item.note ? `<div class="tw-p-2 tw-rounded tw-bg-surface tw-border tw-border-outline-variant text-dark mt-1"><em>"${item.note}"</em></div>` : ''}
                        </div>
                    `;
                });
                html += '</div>';
                bodyEl.innerHTML = html;
            })
            .catch(() => {
                bodyEl.innerHTML = '<div class="alert alert-danger py-2 px-3">Failed to load progress history.</div>';
            });
        });
    });
});
</script>
@endpush
@endsection
