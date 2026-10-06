@extends('layouts.app')

@section('title', __('purchasing.titles.po_detail', ['number' => $po->po_number]))
@section('page-title', __('supplier.copy.purchase_order_details'))

@section('content')
<div class="tw-grid tw-gap-4">
    {{-- Breadcrumb & Compact Page Header --}}
    <x-ui.breadcrumb :items="[
        __('purchasing.breadcrumbs.dashboard') => route('supplier.dashboard'),
        __('purchasing.breadcrumbs.purchase_orders') => route('supplier.purchase-orders.index'),
        $po->po_number => null,
    ]" />

    <x-ui.page-header
        :title="$po->po_number"
        :eyebrow="__('supplier.copy.supplier_purchase_order')"
        :description="__('supplier.copy.review_commercial_parameters_ordered_material_lines_customs_documentation_progress_and_quality_claim')"
    >
        <x-slot:actions>
            <x-status-badge type="po" :status="$po->status" :is-overdue="$po->is_overdue" />
            <x-ui.button :href="route('supplier.export.purchase-orders.detail', $po)" variant="outline" size="sm" data-async-export data-export-source-singular="{{ __('exports.sources.purchase_order') }}" data-export-source-plural="{{ __('exports.sources.purchase_orders') }}" data-export-source-count="1" data-export-filtered="false" data-export-row-label="{{ __('supplier.copy.ordered_material_rows') }}" data-export-row-explanation="{{ __('supplier.copy.each_ordered_material_item_will_be_written_as_a_separate_excel_row') }}">
                <x-ui.icon name="file-spreadsheet" />
                <span>{{ __('supplier.copy.export_excel') }}</span>
            </x-ui.button>
            <x-ui.button :href="route('shared.pdf.purchase-order', $po)" variant="danger" size="sm" target="_blank" :title="__('supplier.copy.print_purchase_order')" data-pdf-confirm>
                <x-ui.icon name="printer" />
                <span>{{ __('supplier.copy.print_pdf') }}</span>
            </x-ui.button>
            <x-ui.button :href="route('supplier.purchase-orders.index')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" />
                <span>{{ __('supplier.copy.back_to_pos') }}</span>
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
                <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('supplier.copy.1_pr_issued') }}</div>
                <div class="fw-bold tw-text-on-surface tw-text-ui-xs tw-mt-0.5">
                    {{ $firstPr?->created_at ? $regionalFormatter->timestamp($firstPr->created_at, 'date') : '-' }}
                </div>
            </div>
        </div>

        <div class="tw-flex tw-items-center tw-gap-3 tw-bg-surface-container tw-p-3">
            <x-ui.icon name="receipt" size="sm" class="tw-shrink-0 tw-text-primary" />
            <div>
                <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('supplier.copy.2_po_created') }}</div>
                <div class="fw-bold tw-text-on-surface tw-text-ui-xs tw-mt-0.5">
                    {{ $regionalFormatter->timestamp($po->created_at, 'date') }}
                </div>
            </div>
        </div>

        <div class="tw-flex tw-items-center tw-gap-3 tw-bg-surface-container tw-p-3">
            <x-ui.icon name="calendar" size="sm" class="tw-shrink-0 {{ $po->is_overdue ? 'tw-text-error' : 'tw-text-on-surface-variant' }}" />
            <div>
                <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('supplier.copy.3_po_target_arrival_date') }}</div>
                <div class="fw-bold {{ $po->is_overdue ? 'text-danger' : 'tw-text-on-surface' }} tw-text-ui-xs tw-mt-0.5">
                    {{ $po->estimated_arrival ? $regionalFormatter->date($po->estimated_arrival) : '-' }}
                    @if($po->is_overdue) <span class="ui-status-chip ui-status-chip--error ms-1">{{ __('supplier.copy.overdue') }}</span> @endif
                </div>
                <div class="tw-text-on-surface-variant tw-text-ui-xs">{{ __('supplier.copy.purchasing_target_at_adsi') }}</div>
            </div>
        </div>

        <div class="tw-flex tw-items-center tw-gap-3 tw-bg-surface-container tw-p-3">
            <x-ui.icon name="{{ $po->actual_arrival ? 'circle-check' : 'clock' }}" size="sm" class="tw-shrink-0 {{ $po->actual_arrival ? 'tw-text-success' : 'tw-text-on-surface-variant' }}" />
            <div>
                <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('supplier.copy.4_actual_arrival') }}</div>
                <div class="fw-bold {{ $po->actual_arrival ? 'text-success' : 'tw-text-on-surface-variant' }} tw-text-ui-xs tw-mt-0.5">
                    {{ $po->actual_arrival ? $regionalFormatter->date($po->actual_arrival) : __('supplier.copy.in_transit') }}
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
                    :title="__('supplier.copy.supplier_material_progress')"
                    :description="__('supplier.copy.update_operational_manufacturing_and_preparation_status_per_awarded_item_before_shipment_dispatch')"
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
                                    <th scope="col" style="width: 35px;">{{ __('supplier.copy.no') }}</th>
                                    <th scope="col" class="text-start">{{ __('supplier.copy.material') }}</th>
                                    <th scope="col">{{ __('supplier.copy.ordered') }}</th>
                                    <th scope="col">{{ __('supplier.copy.accepted') }}</th>
                                    <th scope="col">{{ __('supplier.copy.in_transit') }}</th>
                                    <th scope="col">{{ __('supplier.copy.supplier_controlled') }}</th>
                                    <th scope="col">{{ __('supplier.copy.current_progress') }}</th>
                                    <th scope="col">{{ __('supplier.copy.original_ready_date') }}</th>
                                    <th scope="col">{{ __('supplier.copy.current_estimated_ready') }}</th>
                                    <th scope="col">{{ __('supplier.copy.last_update') }}</th>
                                    <th scope="col" class="text-end">{{ __('supplier.copy.actions') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($itemProjections as $idx => $p)
                                    <tr>
                                        <td class="text-center tw-text-on-surface-variant ui-tabular-nums">{{ $idx + 1 }}</td>
                                        <td class="text-start">
                                            <div class="fw-bold tw-text-on-surface">{{ $p['material_name'] }}</div>
                                            @if($p['hs_code'])
                                                <div class="tw-text-on-surface-variant tw-text-ui-xs">{{ __('common.fields.hs_code') }}: {{ $p['hs_code'] }}</div>
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
                                        <td class="text-center ui-tabular-nums tw-text-on-surface-variant" title="{{ __('supplier.copy.original_quotation_ready_dispatch_commitment') }}">
                                            {{ $p['original_supplier_ready_date'] ? $regionalFormatter->date(\Carbon\Carbon::parse($p['original_supplier_ready_date'])) : '-' }}
                                        </td>
                                        <td class="text-center ui-tabular-nums fw-semibold {{ $p['current_estimated_ready_date'] ? 'text-primary' : 'tw-text-on-surface-variant' }}">
                                            {{ $p['current_estimated_ready_date'] ? $regionalFormatter->date($p['current_estimated_ready_date']) : '-' }}
                                        </td>
                                        <td class="text-center tw-text-on-surface-variant" style="font-size: 0.75rem;">
                                            @if($p['last_progress_update_at'])
                                                <div>{{ $regionalFormatter->timestamp($p['last_progress_update_at'], 'datetime') }}</div>
                                                <div class="tw-text-outline">{{ $p['last_updated_by'] ?? __('supplier.copy.supplier') }}</div>
                                            @else
                                                <span class="text-muted">{{ __('supplier.copy.no_updates') }}</span>
                                            @endif
                                        </td>
                                        <td class="text-end">
                                            <div class="d-inline-flex gap-1 justify-content-end">
                                                @if($p['can_update'])
                                                    <button type="button"
                                                            class="btn btn-outline-primary btn-sm px-2 py-1 tw-text-ui-xs"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#updateProgressModal{{ $p['award_id'] }}">
                                                        {{ __('supplier.copy.update') }}
                                                    </button>
                                                @else
                                                    <span class="badge bg-light text-muted border tw-text-ui-xs" title="{{ __('supplier.copy.all_quantity_is_already_allocated_to_shipment_or_po_is_closed') }}">
                                                        {{ __('supplier.copy.fully_dispatched') }}
                                                    </span>
                                                @endif
                                                <button type="button"
                                                        class="btn btn-outline-secondary btn-sm px-2 py-1 tw-text-ui-xs btn-view-progress-history"
                                                        data-award-id="{{ $p['award_hashid'] }}"
                                                        data-material-name="{{ $p['material_name'] }}"
                                                        data-history-url="{{ route('supplier.purchase-orders.item-progress.history', ['po_id' => $po, 'award_id' => $p['award']]) }}">
                                                    {{ __('supplier.copy.history') }}
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
                :title="__('supplier.copy.ordered_material_breakdown')"
                :description="__('supplier.copy.line_items_consolidated_from_your_accepted_quotation_offers')"
            >
                <table class="table table-hover align-middle mb-0 tw-text-ui-xs w-100">
                    <thead class="table-light text-center">
                        <tr>
                            <th scope="col" class="tw-w-10">{{ __('supplier.copy.no') }}</th>
                            <th scope="col" class="text-start">{{ __('supplier.copy.material') }}</th>
                            <th scope="col">{{ __('supplier.copy.reference_no_pr') }}</th>
                            <th scope="col">{{ __('supplier.copy.remark') }}</th>
                            <th scope="col">{{ __('supplier.copy.qty') }}</th>
                            <th scope="col" class="text-end">{{ __('supplier.copy.weight_unit') }}</th>
                            <th scope="col" class="text-end">{{ __('supplier.copy.total_weight') }}</th>
                            <th scope="col" class="text-end">{{ __('supplier.copy.price_kg') }}</th>
                            <th scope="col" class="text-end">{{ __('supplier.copy.amount') }}</th>
                            <th scope="col" class="text-end">{{ __('supplier.copy.amount_idr') }}</th>
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
                                                &bull; {{ __('supplier.copy.exchange_rate_value') }}: 1 {{ $quotation->currency }} = Rp {{ $regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($rate->rate_to_idr), 'decimal') }}
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
                                            <div class="tw-text-on-surface-variant tw-text-ui-xs">{{ __('common.final_copy.offer') }} {{ $item->available_dimension_label }}</div>
                                        @else
                                            <span class="ui-status-chip ui-status-chip--error tw-mt-0.5">{{ __('supplier.copy.not_available') }}</span>
                                        @endif
                                    </td>
                                    <td class="text-start">
                                        @if($quotation->purchaseRequisition)
                                            <a href="{{ route('supplier.quotations.show', $quotation) }}" class="text-primary fw-semibold text-decoration-none d-inline-flex align-items-center gap-1" title="{{ __('supplier.copy.open_related_quotation') }}">
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
                                            <span class="ui-status-chip ui-status-chip--error">{{ __('supplier.copy.not_available') }}</span>
                                        @endif
                                    </td>
                                    <td class="text-end ui-tabular-nums tw-text-on-surface-variant">
                                        {{ $item->is_available ? $regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($item->offered_weight_per_unit ?? $item->prItem->weight_needed), 'decimal') : '—' }}
                                        @if($item->is_available && $item->is_estimated_weight)<span class="ui-status-chip ui-status-chip--warning ms-1">{{ __('supplier.copy.est_weight') }}</span>@endif
                                    </td>
                                    <td class="text-end fw-bold text-primary ui-tabular-nums">{{ $item->is_available ? $regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($item->offered_total_weight ?? $item->prItem->total_weight), 'decimal') : '—' }}</td>
                                    <td class="text-end ui-tabular-nums">
                                        {{ $regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($item->price_per_kg, 4), 'decimal') }}
                                    </td>
                                    <td class="text-end fw-semibold ui-tabular-nums">{{ $item->is_available ? $regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($amount), 'decimal') : '—' }}</td>
                                    <td class="text-end fw-bold tw-text-on-surface ui-tabular-nums">{{ $item->is_available ? 'Rp '.$regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($idr), 'decimal') : '—' }}</td>
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                    <tfoot class="table-light fw-bold border-top">
                        <tr>
                            <td colspan="8" class="text-end tw-text-on-surface">{{ __('common.fields.total') }}</td>
                            <td class="text-end tw-text-on-surface ui-tabular-nums">{{ $regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($totalAmount), 'decimal') }} {{ $po->currency }}</td>
                            <td class="text-end text-primary ui-tabular-nums fs-6">Rp {{ $regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($totalIdr), 'decimal') }}</td>
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
                <x-ui.card :title="__('supplier.copy.material_claim_notice')" class="border-danger">
                    <x-slot:actions>
                        <span class="ui-status-chip {{ $pendingClaim ? 'ui-status-chip--error' : 'ui-status-chip--neutral' }}">
                            {{ $pendingClaim ? __('supplier.copy.action_required') : __('supplier.copy.claim_logged') }}
                        </span>
                    </x-slot:actions>

                    @if($pendingClaim)
                        <p class="tw-text-on-surface-variant tw-text-ui-xs mb-3">
                            {{ __('supplier.copy.adasi_quality_control_has_submitted_an_ng_defect_claim_for_this_order_please_respond_before_the_dead') }}
                        </p>
                        <x-ui.button :href="route('supplier.claims.show', $pendingClaim)" variant="danger" size="sm" class="tw-w-full">
                            <x-ui.icon name="reply" size="sm" />
                            <span>{{ __('supplier.copy.respond_to_claim') }}</span>
                        </x-ui.button>
                    @else
                        <p class="tw-text-on-surface-variant tw-text-ui-xs mb-3">
                            {{ __('supplier.copy.this_purchase_order_has_historical_claim_resolutions_recorded') }}
                        </p>
                        <x-ui.button :href="route('supplier.claims.show', $latestClaim)" variant="outline" size="sm" class="tw-w-full">
                            <x-ui.icon name="octagon-alert" size="sm" />
                            <span>{{ __('supplier.copy.view_claim_history') }}</span>
                        </x-ui.button>
                    @endif
                </x-ui.card>
            @endif

            {{-- PO Commercial Parameters --}}
            <x-ui.card :title="__('supplier.copy.order_information')">
                <div class="tw-grid tw-gap-2.5">
                    <div class="tw-p-2.5 tw-bg-surface-container border rounded">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('supplier.copy.reference_no_pr') }}</div>
                        <div class="fw-bold text-primary tw-text-ui-sm tw-mt-0.5">{{ $po->pr_reference }}</div>
                    </div>
                    <div class="tw-p-2.5 tw-bg-surface-container border rounded">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('supplier.copy.currency') }}</div>
                        <div class="fw-bold tw-text-on-surface tw-text-ui-sm tw-mt-0.5">{{ $po->currency }}</div>
                    </div>
                    <div class="tw-p-2.5 tw-bg-surface-container border rounded">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('supplier.copy.order_remarks') }}</div>
                        <div class="tw-text-on-surface tw-text-ui-xs tw-mt-0.5">{{ $po->notes ?: __('supplier.copy.no_special_notes_recorded') }}</div>
                    </div>
                </div>
            </x-ui.card>

            {{-- Read-Only Customs Documents Tracking --}}
            <x-ui.card :title="__('supplier.copy.customs_documentation_progress')" :description="__('supplier.copy.document_status_synchronized_with_your_shipments')">
                @php
                    $customsSummary = $customsSummary ?? $po->customsDocumentationSummary();
                    $docIcons = [
                        'invoice' => 'receipt',
                        'bl' => 'truck',
                        'packing_list' => 'list-checks',
                        'form_e' => 'file-badge',
                    ];
                    $statusLabels = [
                        'pending' => __('supplier.copy.not_available'),
                        'received' => \App\Support\StatusHelper::shipmentDocLabel('received'),
                        'verified' => __('supplier.copy.verified'),
                        'issued' => __('supplier.copy.issued'),
                        'processing' => __('supplier.copy.processing'),
                        'done' => __('supplier.copy.completed')
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
                                    {{ $statusLabels[$item['status']] ?? \App\Support\StatusHelper::shipmentDocLabel($item['status']) }}
                                </span>
                            </div>

                            @if($uploadedShipmentDocs->isNotEmpty())
                                <div class="tw-mt-2.5 tw-pt-2.5 tw-border-t tw-border-outline-variant/60 tw-grid tw-gap-1.5">
                                    @foreach($uploadedShipmentDocs as $sDoc)
                                        <div class="tw-flex tw-items-center tw-justify-between tw-gap-2 tw-bg-surface-container-low tw-px-2.5 tw-py-1.5 tw-rounded-md tw-border tw-border-outline-variant/70">
                                            <a href="{{ route('supplier.shipments.show', $sDoc['shipment']) }}" class="text-primary fw-semibold text-decoration-none d-inline-flex align-items-center gap-1.5 text-truncate hover:tw-underline" title="{{ __('common.final_review.open_shipment', ['number' => $sDoc['shipment_number']]) }}">
                                                <x-ui.icon name="truck" size="sm" class="flex-shrink-0" />
                                                <span class="text-truncate">{{ $sDoc['shipment_number'] }}</span>
                                            </a>
                                            <a href="{{ route('attachments.show', $sDoc['attachment']) }}" target="_blank" class="btn btn-xs btn-outline-primary py-0.5 px-2 tw-text-ui-xs d-inline-flex align-items-center gap-1 flex-shrink-0 rounded" title="{{ __('common.actions.view') }} {{ $sDoc['attachment']->file_name }}">
                                                <x-ui.icon name="file-text" size="sm" />
                                                <span>{{ __('supplier.copy.view') }}</span>
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
                                    {{ __('common.final_review.update_progress', ['material' => $p['material_name']]) }}
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('supplier.copy.close') }}"></button>
                            </div>
                            <div class="modal-body">
                                <div class="alert alert-info py-2 px-3 tw-text-ui-xs mb-3">
                                    <x-ui.icon name="info" size="sm" class="me-1" />
                                    {{ __('supplier.copy.this_update_applies_to') }} <strong>{{ $p['supplier_controlled_qty'] }} pcs</strong> {{ __('supplier.copy.currently_under_supplier_control') }}
                                </div>

                                <div class="mb-3">
                                    <label class="form-label small fw-semibold">{{ __('supplier.copy.progress_status') }} <span class="text-danger">*</span></label>
                                    <select name="status" class="form-select form-select-sm progress-status-select" data-current-rank="{{ \App\Models\PoItemProgressUpdate::STAGE_RANKS[$p['manual_progress_status']] ?? 10 }}" required>
                                        <option value="awaiting_confirmation" {{ $p['manual_progress_status'] === 'awaiting_confirmation' ? 'selected' : '' }}>{{ __('supplier.copy.awaiting_confirmation') }}</option>
                                        <option value="order_confirmed" {{ $p['manual_progress_status'] === 'order_confirmed' ? 'selected' : '' }}>{{ __('supplier.copy.order_confirmed') }}</option>
                                        <option value="material_preparation" {{ $p['manual_progress_status'] === 'material_preparation' ? 'selected' : '' }}>{{ __('supplier.copy.material_preparation') }}</option>
                                        <option value="on_production" {{ $p['manual_progress_status'] === 'on_production' ? 'selected' : '' }}>{{ __('supplier.copy.on_production') }}</option>
                                        <option value="ready_to_ship" {{ $p['manual_progress_status'] === 'ready_to_ship' ? 'selected' : '' }}>{{ __('supplier.copy.ready_to_ship') }}</option>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <x-ui.date-picker
                                        name="estimated_ready_date"
                                        id="estimated_ready_date_{{ $p['award_id'] }}"
                                        :label="__('supplier.copy.current_estimated_ready_date')"
                                        value="{{ optional($p['current_estimated_ready_date'])->format('Y-m-d') }}"
                                        :helper="__('supplier.copy.estimated_forecast_for_when_outstanding_material_will_be_ready_for_dispatch')"
                                    />
                                </div>

                                <div class="mb-3">
                                    <label class="form-label small fw-semibold">
                                        {{ __('supplier.copy.progress_note_reason') }} <span class="backward-required-marker text-danger d-none">*</span>
                                    </label>
                                    <textarea name="note" class="form-control form-control-sm progress-note-input" rows="3" placeholder="{{ __('supplier.copy.provide_details_about_current_stage_or_operational_updates') }}"></textarea>
                                    <div class="backward-notice alert alert-warning py-1.5 px-2.5 tw-text-ui-xs mt-1.5 d-none">
                                        <x-ui.icon name="triangle-alert" size="sm" class="me-1 text-warning" />
                                        <span>{{ __('supplier.copy.a_note_reason_is_required_because_progress_is_moving_backward') }}</span>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">{{ __('supplier.copy.cancel') }}</button>
                                <button type="submit" class="btn btn-primary btn-sm">{{ __('supplier.copy.save_progress_update') }}</button>
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
                    {{ __('supplier.copy.material_progress_history') }}
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('supplier.copy.close') }}"></button>
            </div>
            <div class="modal-body" id="progressHistoryModalBody">
                <div class="text-center py-4 text-muted">
                    <div class="spinner-border spinner-border-sm text-primary me-1" role="status"></div> {{ __('supplier.copy.loading_history') }}
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">{{ __('supplier.copy.close') }}</button>
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

    function renderProgressHistoryRows(history) {
        const allowedTones = ['success', 'info', 'warning', 'neutral'];
        const wrapper = document.createElement('div');
        wrapper.className = 'tw-space-y-3';

        function textElement(tagName, className, value) {
            const element = document.createElement(tagName);
            if (className) element.className = className;
            element.textContent = value === null || value === undefined ? '' : String(value);
            return element;
        }

        function appendText(parent, value) {
            parent.appendChild(textElement('span', '', value));
        }

        history.forEach(item => {
            const row = textElement('div', 'tw-p-3 tw-rounded tw-border tw-border-outline-variant tw-bg-surface-container tw-text-ui-xs', '');
            const header = textElement('div', 'd-flex justify-content-between align-items-center mb-1', '');
            const tone = allowedTones.includes(item.status_tone) ? item.status_tone : 'neutral';
            const status = textElement('span', `ui-status-chip ui-status-chip--${tone} fw-bold`, item.status_label);
            const timestamp = textElement('span', 'text-muted', item.created_at_display || '-');
            header.append(status, timestamp);

            const details = textElement('div', 'tw-text-on-surface-variant mb-1', '');
            details.appendChild(textElement('strong', '', @json(__('supplier.copy.supplier_controlled_qty_snapshot'))));
            appendText(details, ` ${item.supplier_controlled_qty_snapshot} pcs`);
            if (item.estimated_ready_date_display) {
                appendText(details, ' • ');
                details.appendChild(textElement('strong', '', @json(__('supplier.copy.estimated_ready'))));
                appendText(details, ` ${item.estimated_ready_date_display}`);
            }
            appendText(details, ' • ');
            details.appendChild(textElement('strong', '', @json(__('supplier.copy.updated_by'))));
            appendText(details, ` ${item.updated_by ?? @json(__('supplier.copy.supplier_user'))}`);

            row.append(header, details);
            if (item.note) {
                const note = textElement('div', 'tw-p-2 tw-rounded tw-bg-surface tw-border tw-border-outline-variant text-dark mt-1', '');
                note.appendChild(textElement('em', '', `"${item.note}"`));
                row.appendChild(note);
            }
            wrapper.appendChild(row);
        });

        return wrapper;
    }

    document.querySelectorAll('.btn-view-progress-history').forEach(btn => {
        btn.addEventListener('click', function() {
            const materialName = this.dataset.materialName;
            const historyUrl = this.dataset.historyUrl;
            const modalEl = document.getElementById('progressHistoryModal');
            const titleEl = document.getElementById('progressHistoryModalLabel');
            const bodyEl = document.getElementById('progressHistoryModalBody');

            titleEl.textContent = window.AdasiI18n.t('purchasing.js.progress_history', { material: materialName });
            bodyEl.innerHTML = '<div class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm text-primary me-1" role="status"></div> ' + window.AdasiI18n.t('purchasing.js.loading_history') + '</div>';

            const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
            modal.show();

            fetch(historyUrl, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(res => res.json())
            .then(data => {
                if (!data.history || data.history.length === 0) {
                    bodyEl.innerHTML = '<div class="text-center py-4 text-muted">' + window.AdasiI18n.t('purchasing.js.no_progress') + '</div>';
                    return;
                }

                bodyEl.replaceChildren(renderProgressHistoryRows(data.history));
            })
            .catch(() => {
                bodyEl.innerHTML = '<div class="alert alert-danger py-2 px-3">' + window.AdasiI18n.t('purchasing.js.history_failed') + '</div>';
            });
        });
    });
});
</script>
@endpush
@endsection
