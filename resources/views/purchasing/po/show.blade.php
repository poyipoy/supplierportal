@extends('layouts.app')

@section('title', __('purchasing.titles.po_detail', ['number' => $po->po_number]))
@section('page-title', __('purchasing.copy.purchase_order_details'))

@push('styles')
<style>
    .po-tracking-strip {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 0.75rem;
    }

    .po-tracking-step {
        padding: 0.75rem 1rem;
        background: var(--md-surface);
        border: 1px solid var(--md-outline-variant);
        border-radius: var(--md-shape-sm);
        position: relative;
    }

    .po-tracking-step.is-active {
        border-color: var(--md-primary);
        background: var(--md-primary-container);
    }

    .po-sticky-nav-bar {
        position: sticky;
        top: var(--topbar-height, 56px);
        z-index: 1010;
        background-color: var(--md-surface);
        border: 1px solid var(--md-outline-variant);
        border-radius: var(--md-shape-sm, 8px);
        padding: 0.35rem 0.5rem;
        box-shadow: 0 2px 8px -2px rgba(0, 0, 0, 0.08);
        transition: box-shadow 0.2s ease;
    }

    .po-nav-pills {
        display: flex;
        flex-wrap: nowrap;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: none;
        gap: 0.35rem;
        margin: 0;
        padding: 0.1rem 0;
    }

    .po-nav-pills::-webkit-scrollbar {
        display: none;
    }

    .po-nav-pills .nav-item {
        flex-shrink: 0;
    }

    .po-nav-pills .nav-link {
        font-size: var(--ui-font-size-sm);
        font-weight: 600;
        padding: 0.4rem 0.95rem;
        color: var(--md-on-surface-variant);
        border-radius: var(--md-shape-full);
        transition: all 0.15s ease;
        white-space: nowrap;
        text-decoration: none;
    }

    .po-nav-pills .nav-link:hover:not(.active) {
        background-color: var(--md-surface-container-high);
        color: var(--md-on-surface);
    }

    .po-nav-pills .nav-link.active {
        background-color: var(--md-primary) !important;
        color: var(--md-on-primary) !important;
        box-shadow: 0 1px 3px rgba(var(--md-primary-rgb), 0.35);
    }

    .po-nav-pills .nav-link.text-danger.active {
        background-color: var(--md-error) !important;
        color: var(--md-on-error) !important;
        box-shadow: 0 1px 3px rgba(220, 53, 69, 0.35);
    }

    .po-doc-card {
        transition: border-color 0.15s ease;
    }

    .po-doc-card:hover {
        border-color: var(--md-primary);
    }

    @keyframes sectionPulsePrimary {
        0% {
            box-shadow: 0 0 0 0 rgba(31, 95, 166, 0);
            outline: 2px solid transparent;
            outline-offset: 2px;
        }
        20% {
            box-shadow: 0 0 0 5px rgba(31, 95, 166, 0.35), 0 8px 24px -4px rgba(31, 95, 166, 0.2);
            outline: 2px solid var(--md-primary);
            outline-offset: 2px;
        }
        50% {
            box-shadow: 0 0 0 6px rgba(31, 95, 166, 0.2), 0 6px 18px -4px rgba(31, 95, 166, 0.15);
            outline: 2px solid var(--md-primary);
            outline-offset: 2px;
        }
        100% {
            box-shadow: 0 0 0 0 rgba(31, 95, 166, 0);
            outline: 2px solid transparent;
            outline-offset: 2px;
        }
    }

    @keyframes sectionPulseDanger {
        0% {
            box-shadow: 0 0 0 0 rgba(192, 57, 43, 0);
            outline: 2px solid transparent;
            outline-offset: 2px;
        }
        20% {
            box-shadow: 0 0 0 5px rgba(192, 57, 43, 0.4), 0 8px 24px -4px rgba(192, 57, 43, 0.25);
            outline: 2px solid var(--md-error);
            outline-offset: 2px;
        }
        50% {
            box-shadow: 0 0 0 6px rgba(192, 57, 43, 0.2), 0 6px 18px -4px rgba(192, 57, 43, 0.15);
            outline: 2px solid var(--md-error);
            outline-offset: 2px;
        }
        100% {
            box-shadow: 0 0 0 0 rgba(192, 57, 43, 0);
            outline: 2px solid transparent;
            outline-offset: 2px;
        }
    }

    .section-target-highlight {
        animation: sectionPulsePrimary 1.8s cubic-bezier(0.25, 1, 0.5, 1) forwards;
        border-radius: var(--md-shape-md, 12px);
    }

    .section-target-highlight--danger {
        animation: sectionPulseDanger 1.8s cubic-bezier(0.25, 1, 0.5, 1) forwards;
        border-radius: var(--md-shape-md, 12px);
    }
</style>
@endpush

@section('content')
<div class="tw-grid tw-gap-4">
    {{-- Breadcrumb & Compact Page Header --}}
    <x-ui.breadcrumb :items="[
        __('purchasing.breadcrumbs.dashboard') => route('purchasing.dashboard'),
        __('purchasing.breadcrumbs.purchase_orders') => route('purchasing.purchase-orders.index'),
        $po->po_number => null,
    ]" />

    <x-ui.page-header
        :title="$po->po_number"
        :eyebrow="__('purchasing.copy.purchase_order_details')"
        :description="__('purchasing.page.po_help', ['supplier' => $po->supplier->name])"
    >
        <x-slot:actions>
            <x-status-badge type="po" :status="$po->status" :is-overdue="$po->is_overdue" size="lg" />
            <x-ui.button :href="route('purchasing.export.purchase-orders.detail', $po)" variant="outline" size="sm" data-async-export data-export-source-singular="{{ __('exports.sources.purchase_order') }}" data-export-source-plural="{{ __('exports.sources.purchase_orders') }}" data-export-source-count="1" data-export-filtered="false" data-export-row-label="{{ __('purchasing.copy.ordered_material_rows') }}" data-export-row-explanation="{{ __('purchasing.copy.each_ordered_material_item_will_be_written_as_a_separate_excel_row') }}">
                <x-ui.icon name="file-spreadsheet" />
                <span>{{ __('purchasing.copy.export_excel') }}</span>
            </x-ui.button>
            <x-ui.button :href="route('shared.pdf.purchase-order', $po)" variant="danger" size="sm" target="_blank" :title="__('purchasing.copy.print_purchase_order')" data-pdf-confirm>
                <x-ui.icon name="file-text" />
                <span>{{ __('purchasing.copy.print_pdf') }}</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- 4-Key Tracking Dates Strip --}}
    @php
        $primaryPr = $po->quotations->first()?->purchaseRequisition;
        $prDate = $primaryPr?->created_at;
    @endphp
    <div class="po-tracking-strip">
        <div class="po-tracking-step {{ $prDate ? 'is-active' : '' }}">
            <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('purchasing.copy.1_pr_created') }}</div>
            <div class="fw-bold tw-text-on-surface tw-text-ui-sm tw-mt-0.5">{{ $prDate ? $regionalFormatter->timestamp($prDate, 'date') : '-' }}</div>
            <div class="tw-text-outline tw-text-ui-xs">{{ $primaryPr?->pr_number ?? __('terms.purchase_requisition') }}</div>
        </div>
        <div class="po-tracking-step is-active">
            <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('purchasing.copy.2_po_created') }}</div>
            <div class="fw-bold tw-text-on-surface tw-text-ui-sm tw-mt-0.5">{{ $regionalFormatter->timestamp($po->created_at, 'date') }}</div>
            <div class="tw-text-outline tw-text-ui-xs">{{ $regionalFormatter->time($po->created_at) }}</div>
        </div>
        <div class="po-tracking-step {{ $po->estimated_arrival ? 'is-active' : '' }}">
            <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('purchasing.copy.3_po_target_arrival_date') }}</div>
            <div class="fw-bold {{ $po->is_overdue ? 'text-danger' : 'tw-text-on-surface' }} tw-text-ui-sm tw-mt-0.5">
                {{ $po->estimated_arrival ? $regionalFormatter->date($po->estimated_arrival) : '-' }}
            </div>
            <div class="tw-text-outline tw-text-ui-xs">
                {{ $po->is_overdue ? __('purchasing.copy.overdue') : __('purchasing.copy.purchasing_target_at_adasi') }}
            </div>
        </div>
        <div class="po-tracking-step {{ $po->actual_arrival ? 'is-active' : '' }}">
            <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('purchasing.copy.4_actual_arrival') }}</div>
            <div class="fw-bold {{ $po->actual_arrival ? 'text-success' : 'tw-text-outline' }} tw-text-ui-sm tw-mt-0.5">
                @if($po->actual_arrival)
                    <x-ui.icon name="circle-check" size="sm" class="me-1 text-success" />
                    {{ $regionalFormatter->date($po->actual_arrival) }}
                @else
                    {{ __('purchasing.audit_ui.pending_delivery') }}
                @endif
            </div>
            <div class="tw-text-outline tw-text-ui-xs">
                {{ $po->actual_arrival ? __('purchasing.copy.received_at_plant') : __('purchasing.copy.waiting_arrival') }}
            </div>
        </div>
    </div>

    {{-- Sticky In-Page Navigation Bar --}}
    <nav class="po-sticky-nav-bar" aria-label="{{ __('purchasing.copy.purchase_order_sections') }}">
        <ul class="nav po-nav-pills" id="po-section-nav">
            <li class="nav-item"><a class="nav-link active" href="#sec-info">{{ __('purchasing.copy.order_info') }}</a></li>
            <li class="nav-item"><a class="nav-link" href="#sec-material">{{ __('purchasing.copy.materials_commercials') }}</a></li>
            @if(isset($itemProjections) && $itemProjections->isNotEmpty())
                <li class="nav-item"><a class="nav-link" href="#sec-material-progress">{{ __('purchasing.copy.material_progress') }}</a></li>
            @endif
            @if($po->qcInspections->isNotEmpty())
                <li class="nav-item"><a class="nav-link" href="#sec-inspection">{{ __('purchasing.copy.qc_inspection') }}</a></li>
            @endif
            <li class="nav-item"><a class="nav-link" href="#sec-document">{{ __('purchasing.copy.import_documents') }}</a></li>
            @if($po->status === 'claim_needed')
                <li class="nav-item"><a class="nav-link text-danger" href="#sec-claim">{{ __('purchasing.copy.material_claim') }}</a></li>
            @endif
            <li class="nav-item"><a class="nav-link" href="#sec-timeline">{{ __('purchasing.copy.timeline') }}</a></li>
        </ul>
    </nav>

    <div class="tw-grid tw-items-start tw-gap-4 lg:tw-grid-cols-[minmax(0,2fr)_minmax(19rem,1fr)]">
        {{-- Main Column --}}
        <div class="tw-grid tw-min-w-0 tw-gap-4">
            {{-- Order Info Card --}}
            <x-ui.card :title="__('purchasing.copy.order_information')" id="sec-info" class="tw-scroll-mt-24">
                <div class="tw-grid tw-gap-px tw-overflow-hidden tw-border tw-border-outline-variant tw-bg-outline-variant sm:tw-grid-cols-2 lg:tw-grid-cols-3">
                    <div class="tw-bg-surface-container tw-p-2.5">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('purchasing.copy.supplier') }}</div>
                        <div class="fw-bold tw-text-on-surface tw-text-ui-sm tw-mt-0.5">{{ $po->supplier->name }}</div>
                    </div>
                    <div class="tw-bg-surface-container tw-p-2.5">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('purchasing.copy.reference_no_pr') }}</div>
                        <div class="fw-bold text-primary tw-text-ui-sm tw-mt-0.5">
                            @php $prs = $po->purchaseRequisitions(); @endphp
                            @if($prs->isEmpty())
                                -
                            @else
                                @foreach($prs as $pr)
                                    <a href="{{ \App\Support\PurchasingNavigation::toRoute('purchasing.requisitions.show', $pr) }}" class="text-primary text-decoration-none tw-me-1.5">
                                        {{ $pr->pr_number ?? '-' }}
                                    </a>
                                @endforeach
                                @if($prs->count() > 1)
                                    <span class="ui-status-chip ui-status-chip--info">{{ trans_choice('purchasing.copy.pr_count', $prs->count(), ['count' => $prs->count()]) }}</span>
                                @endif
                            @endif
                        </div>
                    </div>
                    <div class="tw-bg-surface-container tw-p-2.5">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('purchasing.copy.procurement_period') }}</div>
                        <div class="fw-semibold tw-text-on-surface tw-text-ui-sm tw-mt-0.5">
                            @php $periods = $prs->map(fn($pr) => $pr->period?->display_label ?? '-')->unique(); @endphp
                            {{ $periods->implode(', ') }}
                        </div>
                    </div>
                    <div class="tw-bg-surface-container tw-p-2.5">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('purchasing.copy.locked_currency') }}</div>
                        <div class="fw-bold tw-text-on-surface tw-text-ui-sm tw-mt-0.5">
                            <span class="ui-status-chip ui-status-chip--neutral">{{ $po->currency }}</span>
                        </div>
                    </div>
                    <div class="tw-bg-surface-container tw-p-2.5 sm:tw-col-span-2">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('purchasing.copy.po_notes_remark') }}</div>
                        <div class="tw-text-on-surface tw-text-ui-sm tw-mt-0.5">{{ $po->notes ?: '-' }}</div>
                    </div>
                </div>
            </x-ui.card>

            {{-- Material Details Table --}}
            <x-ui.data-table
            :title="__('purchasing.copy.materials_and_commercial_breakdown')"
                :description="__('purchasing.copy.line_items_grouped_by_quotation_and_reference_pr')"
                id="sec-material"
                class="tw-scroll-mt-24"
            >
                <table class="table table-hover align-middle mb-0 tw-text-ui-xs w-100">
                    <thead class="table-light text-center">
                        <tr>
                            <th scope="col" style="width: 35px;">{{ __('purchasing.copy.no') }}</th>
                            <th scope="col">{{ __('purchasing.copy.material') }}</th>
                            <th scope="col">{{ __('purchasing.copy.specification') }}</th>
                            <th scope="col">{{ __('purchasing.copy.qty') }}</th>
                            <th scope="col" class="text-end">{{ __('purchasing.copy.weight_unit') }}</th>
                            <th scope="col" class="text-end">{{ __('purchasing.copy.total_weight') }}</th>
                            <th scope="col" class="text-end">{{ __('purchasing.copy.price_kg') }}</th>
                            <th scope="col" class="text-end">{{ __('purchasing.copy.amount') }}</th>
                            <th scope="col" class="text-end">{{ __('purchasing.copy.converted_idr') }}</th>
                            <th scope="col">{{ __('purchasing.copy.reference_no_pr') }}</th>
                            <th scope="col">{{ __('purchasing.copy.remark') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php
                            $globalNo = 1;
                            $grandTotalAmount = 0;
                            $grandTotalIdr = 0;
                            $poRemark = trim((string) $po->notes);
                        @endphp
                        @foreach($po->commercialQuotations() as $quotation)
                            @php $rate = $quotationRates[$quotation->id] ?? null; @endphp
                            @if($po->quotations->count() > 1)
                                <tr class="bg-primary-subtle text-primary border-top border-bottom">
                                    <td colspan="11" class="fw-bold py-2 ps-3">
                                        <x-ui.icon name="folder" size="sm" class="me-1" />
                                        {{ $quotation->purchaseRequisition->pr_number ?? 'PR -' }}
                                        <span class="tw-text-on-surface-variant fw-normal ms-2">
                                            ({{ $quotation->purchaseRequisition->period->display_label ?? $quotation->purchaseRequisition->period->name ?? '-' }})
                                            @if($rate)
                                                &bull; {{ __('purchasing.copy.locked_exchange_rate') }}: 1 {{ $quotation->currency }} = Rp {{ $regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($rate->rate_to_idr), 'decimal') }}
                                            @endif
                                        </span>
                                    </td>
                                </tr>
                            @endif
                            @foreach($quotation->items as $item)
                                @php
                                    $amount = $item->resolved_amount;
                                    $idr = $amount * ($rate ? $rate->rate_to_idr : 1);
                                    $grandTotalAmount += $amount;
                                    $grandTotalIdr += $idr;
                                @endphp
                                <tr>
                                    <td class="text-center tw-text-on-surface-variant ui-tabular-nums">{{ $globalNo++ }}</td>
                                    <td class="fw-bold tw-text-on-surface">{{ $item->prItem->material_name }}</td>
                                    <td class="text-center">
                                        @if(!$item->is_available)
                                            <span class="ui-status-chip ui-status-chip--error">{{ __('purchasing.copy.not_available') }}</span>
                                        @elseif($item->prItem->shape)
                                            <div class="tw-text-on-surface-variant tw-text-ui-xs">{{ __('common.final_copy.requested') }} {{ $item->prItem->dimension_label }}</div>
                                            <div class="fw-semibold tw-text-ui-xs tw-mt-0.5">{{ __('common.final_copy.offer') }} {{ $item->available_dimension_label }}</div>
                                        @else
                                            <span class="tw-text-outline">-</span>
                                        @endif
                                    </td>
                                    <td class="text-center ui-tabular-nums">{{ $item->is_available ? ($item->available_qty ?? $item->prItem->quantity_value) : '—' }}</td>
                                    <td class="text-end ui-tabular-nums tw-text-on-surface-variant">
                                        {{ $item->is_available ? $regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($item->offered_weight_per_unit ?? $item->prItem->weight_needed), 'decimal') : '—' }}
                                        @if($item->is_available && $item->is_estimated_weight)<span class="ui-status-chip ui-status-chip--warning ms-1">{{ __('purchasing.copy.est_weight') }}</span>@endif
                                    </td>
                                    <td class="text-end fw-bold text-primary ui-tabular-nums">{{ $item->is_available ? $regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($item->offered_total_weight ?? $item->prItem->total_weight), 'decimal') : '—' }}</td>
                                    <td class="text-end ui-tabular-nums tw-text-on-surface-variant">
                                        {{ $regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($item->price_per_kg, 4), 'decimal') }}
                                    </td>
                                    <td class="text-end fw-semibold ui-tabular-nums">{{ $item->is_available ? $regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($amount), 'decimal') : '—' }}</td>
                                    <td class="text-end fw-bold tw-text-on-surface ui-tabular-nums">{{ $item->is_available ? 'Rp '.$regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($idr), 'decimal') : '—' }}</td>
                                    <td class="text-nowrap">
                                        @if($quotation->purchaseRequisition)
                                            <a href="{{ \App\Support\PurchasingNavigation::toRoute('purchasing.requisitions.show', $quotation->purchaseRequisition) }}" class="text-primary text-decoration-none fw-medium" title="{{ __('purchasing.copy.open_pr_detail') }}">
                                                {{ $quotation->purchaseRequisition->pr_number ?? '-' }}
                                            </a>
                                        @else
                                            <span class="tw-text-outline">-</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($poRemark !== '')
                                            <span class="d-inline-block text-truncate tw-max-w-[220px]" title="{{ $poRemark }}">
                                                {{ \Illuminate\Support\Str::limit($poRemark, 80) }}
                                            </span>
                                        @else
                                            -
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                    <tfoot class="table-light fw-bold border-top">
                        <tr>
                            <td colspan="7" class="text-end tw-text-on-surface">{{ __('purchasing.copy.grand_total') }}</td>
                            <td class="text-end tw-text-on-surface ui-tabular-nums">{{ $regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($grandTotalAmount), 'decimal') }} {{ $po->currency }}</td>
                            <td class="text-end text-primary ui-tabular-nums fs-6">Rp {{ $regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($grandTotalIdr), 'decimal') }}</td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </x-ui.data-table>

            {{-- Supplier Material Progress Section --}}
            @if(isset($itemProjections) && $itemProjections->isNotEmpty())
                <x-ui.data-table
                    :title="__('purchasing.copy.supplier_material_progress')"
                    :description="__('purchasing.copy.live_tracking_of_supplier_manufacturing_and_dispatch_preparation_per_awarded_item')"
                    id="sec-material-progress"
                    class="tw-scroll-mt-24"
                >
                    <x-slot:actions>
                        @if(isset($poProgressSummary))
                            <span class="ui-status-chip ui-status-chip--info">
                                {{ $poProgressSummary['text'] }}
                            </span>
                        @endif
                    </x-slot:actions>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 tw-text-ui-xs w-100">
                            <thead class="table-light text-center">
                                <tr>
                                    <th scope="col" style="width: 35px;">{{ __('purchasing.copy.no') }}</th>
                                    <th scope="col" class="text-start">{{ __('purchasing.copy.material') }}</th>
                                    <th scope="col">{{ __('purchasing.copy.ordered') }}</th>
                                    <th scope="col">{{ __('purchasing.copy.accepted') }}</th>
                                    <th scope="col">{{ __('purchasing.copy.in_transit') }}</th>
                                    <th scope="col">{{ __('purchasing.copy.waiting_qc') }}</th>
                                    <th scope="col">{{ __('purchasing.copy.supplier_controlled') }}</th>
                                    <th scope="col">{{ __('purchasing.copy.supplier_progress') }}</th>
                                    <th scope="col">{{ __('purchasing.copy.original_ready_date') }}</th>
                                    <th scope="col">{{ __('purchasing.copy.current_estimated_ready') }}</th>
                                    <th scope="col">{{ __('purchasing.copy.last_supplier_update') }}</th>
                                    <th scope="col" class="text-end">{{ __('purchasing.copy.history') }}</th>
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
                                        <td class="text-center ui-tabular-nums fw-semibold {{ $p['arrived_pending_qc_qty'] > 0 ? 'text-warning' : 'tw-text-on-surface-variant' }}">
                                            {{ $p['arrived_pending_qc_qty'] }} pcs
                                        </td>
                                        <td class="text-center ui-tabular-nums fw-bold">
                                            <span class="badge {{ $p['supplier_controlled_qty'] > 0 ? 'bg-primary' : 'bg-secondary' }}">
                                                {{ $p['supplier_controlled_qty'] }} pcs
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <span class="ui-status-chip ui-status-chip--{{ $p['manual_progress_tone'] }}">
                                                {{ $p['manual_progress_label'] }}
                                            </span>
                                        </td>
                                        <td class="text-center ui-tabular-nums tw-text-on-surface-variant" title="{{ __('purchasing.copy.supplier_original_estimated_ready_dispatch_date') }}">
                                            {{ $p['original_supplier_ready_date'] ? $regionalFormatter->date(\Carbon\Carbon::parse($p['original_supplier_ready_date'])) : '-' }}
                                        </td>
                                        <td class="text-center ui-tabular-nums fw-semibold {{ $p['current_estimated_ready_date'] ? 'text-primary' : 'tw-text-on-surface-variant' }}">
                                            {{ $p['current_estimated_ready_date'] ? $regionalFormatter->date($p['current_estimated_ready_date']) : '-' }}
                                        </td>
                                        <td class="text-center tw-text-on-surface-variant" style="font-size: 0.75rem;">
                                            @if($p['last_progress_update_at'])
                                                <div>{{ $regionalFormatter->timestamp($p['last_progress_update_at'], 'datetime') }}</div>
                                                <div class="tw-text-outline">{{ $p['last_updated_by'] ?? __('purchasing.copy.supplier') }}</div>
                                            @else
                                                <span class="text-muted">{{ __('purchasing.copy.no_updates') }}</span>
                                            @endif
                                        </td>
                                        <td class="text-end">
                                            <button type="button"
                                                    class="btn btn-outline-secondary btn-sm px-2 py-1 tw-text-ui-xs btn-view-purchasing-history"
                                                    data-award-id="{{ $p['award_id'] }}"
                                                    data-material-name="{{ $p['material_name'] }}"
                                                    data-history-url="{{ route('purchasing.purchase-orders.item-progress.history', ['po_id' => $po, 'award_id' => $p['award']]) }}">
                                                {{ __('purchasing.copy.history') }}
                                            </button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-ui.data-table>
            @endif

            {{-- QC Inspection Results --}}
            @php
                $latestInspection = $po->qcInspections->sortByDesc('inspected_at')->first();
                $latestNgInspection = $po->qcInspections->where('status', 'ng')->sortByDesc('inspected_at')->first();
                $activeClaim = $po->materialClaims->whereIn('status', ['pending', 'responded', 'escalated'])->sortByDesc('created_at')->first();
            @endphp
            @if($latestInspection)
                <x-ui.card
                    :title="__('purchasing.copy.qc_inspection_report')"
                    :description="__('purchasing.copy.incoming_quality_verification_by_adasi_qc_team')"
                    id="sec-inspection"
                    class="tw-scroll-mt-24"
                >
                    <x-slot:actions>
                        <span class="ui-status-chip {{ $latestInspection->status === 'ng' ? 'ui-status-chip--error' : 'ui-status-chip--success' }}">
                            {{ __('common.final_copy.status') }} {{ \App\Support\StatusHelper::qcLabel($latestInspection->status) }}
                        </span>
                    </x-slot:actions>

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('purchasing.copy.inspection_date') }}</div>
                            <div class="fw-semibold tw-text-on-surface tw-text-ui-sm tw-mt-0.5">{{ $latestInspection->inspected_at ? $regionalFormatter->timestamp($latestInspection->inspected_at, 'datetime_comma') : '-' }}</div>
                        </div>
                        <div class="col-md-4">
                            <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('purchasing.copy.inspected_by') }}</div>
                            <div class="fw-semibold tw-text-on-surface tw-text-ui-sm tw-mt-0.5">{{ $latestInspection->inspector->name ?? '-' }}</div>
                        </div>
                        <div class="col-md-4">
                            <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('purchasing.copy.defective_ng_items') }}</div>
                            <div class="fw-bold {{ $latestInspection->items->where('status', 'ng')->count() > 0 ? 'text-danger' : 'text-success' }} tw-text-ui-sm tw-mt-0.5">
                                {{ trans_choice('purchasing.copy.item_count', $latestInspection->items->where('status', 'ng')->count(), ['count' => $latestInspection->items->where('status', 'ng')->count()]) }}
                            </div>
                        </div>
                    </div>

                    @if($latestInspection->items->where('status', 'ng')->count() > 0)
                        <div class="mb-3">
                            <div class="fw-bold text-danger tw-text-ui-xs tw-uppercase tw-mb-1.5">{{ __('purchasing.copy.problematic_materials_ng') }}</div>
                            <ul class="list-group list-group-flush border rounded overflow-hidden">
                                @foreach($latestInspection->items->where('status', 'ng') as $item)
                                    <li class="list-group-item py-2 px-3 tw-text-ui-xs">
                                        <span class="fw-bold tw-text-on-surface d-block">{{ $item->prItem->material_name }}</span>
                                        @if($item->notes)
                                            <span class="tw-text-on-surface-variant fst-italic">{{ __('common.final_copy.qc_remarks') }} {{ $item->notes }}</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if($latestInspection->attachments->count() > 0)
                        <div>
                            <div class="fw-bold tw-text-on-surface-variant tw-text-ui-xs tw-uppercase tw-mb-1.5">{{ __('purchasing.copy.qc_photo_evidence') }}</div>
                            <div class="row g-2">
                                @foreach($latestInspection->attachments as $att)
                                    <div class="col-4 col-md-3">
                                        <a href="{{ route('attachments.show', $att) }}" target="_blank" class="d-block border rounded overflow-hidden tw-bg-surface-low tw-h-24">
                                            <img src="{{ route('attachments.show', $att) }}" alt="{{ $att->file_name }}" class="w-100 h-100 tw-object-cover">
                                        </a>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </x-ui.card>
            @endif

            {{-- Import Document Tracking --}}
            <x-ui.card
                :title="__('purchasing.copy.import_document_tracking')"
                :description="__('purchasing.copy.status_tracking_for_4_mandatory_import_customs_documents_invoice_bill_of_lading_packing_list_and_for')"
                id="sec-document"
                class="tw-scroll-mt-24"
            >
                <x-slot:actions>
                    @php
                        $docProgressTone = str_contains($docProgress['class'], 'success') ? 'success' : (str_contains($docProgress['class'], 'danger') ? 'error' : 'warning');
                    @endphp
                    <span class="ui-status-chip ui-status-chip--{{ $docProgressTone }}" id="docProgressBadge" data-bs-toggle="tooltip" data-bs-title="{{ $docProgress['description'] }}">
                        {{ $docProgress['label'] }}
                    </span>
                </x-slot:actions>

                {{-- Progress Bar --}}
                <div class="progress mb-3" style="height: 6px;">
                    <div class="progress-bar tw-bg-success" role="progressbar" id="docProgressBar"
                         aria-label="{{ __('purchasing.copy.import_document_completion') }}"
                         aria-valuemin="0"
                         aria-valuemax="100"
                         aria-valuenow="{{ $totalDocs > 0 ? round($completedDocs / $totalDocs * 100) : 0 }}"
                         style="width: {{ $totalDocs > 0 ? ($completedDocs/$totalDocs*100) : 0 }}%"></div>
                </div>

                @if($allDocsComplete)
                    <x-ui.alert id="allDocsAlert" tone="success" :title="__('purchasing.copy.import_documents_complete')" class="tw-mb-3">
                        {{ __('purchasing.copy.all_mandatory_import_customs_documents_have_been_fully_verified') }}
                    </x-ui.alert>
                @endif
                <template id="allDocsAlertTemplate">
                    <x-ui.alert id="allDocsAlert" tone="success" :title="__('purchasing.copy.import_documents_complete')" class="tw-mb-3">
                        {{ __('purchasing.copy.all_mandatory_import_customs_documents_have_been_fully_verified') }}
                    </x-ui.alert>
                </template>

                <div class="tw-grid tw-gap-3 sm:tw-grid-cols-2 xl:tw-grid-cols-4">
                    @php
                        $docConfig = [
                            'invoice' => ['label' => __('purchasing.copy.invoice_document'), 'icon' => 'receipt', 'statuses' => ['pending' => __('purchasing.copy.not_available'), 'received' => __('purchasing.copy.accepted'), 'verified' => __('purchasing.copy.verified')]],
                            'bl' => ['label' => __('purchasing.copy.bill_of_lading'), 'icon' => 'truck', 'statuses' => ['pending' => __('purchasing.copy.not_available'), 'issued' => __('purchasing.copy.issued'), 'done' => __('purchasing.copy.accepted')]],
                            'packing_list' => ['label' => __('purchasing.copy.packing_list'), 'icon' => 'list-checks', 'statuses' => ['pending' => __('purchasing.copy.not_available'), 'received' => __('purchasing.copy.accepted'), 'verified' => __('purchasing.copy.verified')]],
                            'form_e' => ['label' => 'Form-E', 'icon' => 'file-badge', 'statuses' => ['pending' => __('purchasing.copy.not_available'), 'processing' => __('purchasing.copy.processing'), 'done' => __('purchasing.copy.completed')]],
                        ];
                    @endphp

                    @php
                        $customsSummary = $customsSummary ?? $po->customsDocumentationSummary();
                    @endphp

                    @foreach($po->documents as $doc)
                        @php
                            $config = $docConfig[$doc->doc_type] ?? ['label' => $doc->doc_type, 'icon' => 'file', 'statuses' => []];
                            $summaryItem = $customsSummary[$doc->doc_type] ?? null;
                            $effectiveStatus = $summaryItem['status'] ?? $doc->status;
                            $statusLabel = $config['statuses'][$effectiveStatus] ?? \App\Support\StatusHelper::shipmentDocLabel($effectiveStatus);
                            $chipClasses = match($effectiveStatus) {
                                'pending' => 'ui-status-chip--neutral',
                                'received', 'issued', 'processing' => 'ui-status-chip--info',
                                'verified', 'done' => 'ui-status-chip--success',
                                default => 'ui-status-chip--neutral'
                            };
                            $iconClasses = match($effectiveStatus) {
                                'pending' => 'tw-bg-surface-container-high tw-text-on-surface-variant/80',
                                'verified', 'done' => 'tw-bg-success-container/40 text-success',
                                default => 'tw-bg-primary/10 text-primary'
                            };
                            $shipmentDocs = collect($summaryItem['shipment_documents'] ?? []);
                            $uploadedDocs = $shipmentDocs->filter(fn($sd) => !empty($sd['attachment']));
                        @endphp
                        <div class="po-doc-card tw-h-full tw-rounded-xl tw-border tw-border-outline-variant/80 tw-bg-surface tw-p-3.5 tw-shadow-2xs tw-flex tw-flex-col tw-justify-between tw-transition-all hover:tw-border-primary/40 hover:tw-shadow-xs" id="doc-card-{{ $doc->id }}">
                            {{-- Zone 1: Document Header (Centered & Uniform) --}}
                            <div class="tw-flex tw-flex-col tw-items-center tw-text-center">
                                <div class="tw-w-10 tw-h-10 tw-rounded-full tw-flex tw-items-center tw-justify-center tw-mb-2 {{ $iconClasses }}">
                                    <x-ui.icon :name="$config['icon']" size="md" />
                                </div>
                                <h6 class="fw-bold tw-text-on-surface tw-text-ui-sm mb-1.5">{{ $config['label'] }}</h6>
                                <span class="ui-status-chip {{ $chipClasses }} tw-mb-1 doc-status-badge" id="doc-badge-{{ $doc->id }}" data-status="{{ $effectiveStatus }}">
                                    {{ $statusLabel }}
                                </span>
                                <div class="tw-text-on-surface-variant tw-text-ui-xs tw-h-5 tw-flex tw-items-center tw-justify-center" id="doc-date-{{ $doc->id }}">
                                    @if($effectiveStatus !== 'pending')
                                        <span>{{ $regionalFormatter->timestamp($doc->updated_at, 'datetime_comma') }}</span>
                                    @else
                                        <span class="tw-text-outline/50">&mdash;</span>
                                    @endif
                                </div>
                            </div>

                            {{-- Zone 2: Shipment Files (Equal height container with clean stacked action) --}}
                            <div class="tw-my-2.5 tw-pt-2.5 tw-border-t tw-border-outline-variant/60 tw-flex-1 tw-flex tw-flex-col tw-justify-center" style="min-height: 72px;">
                                @if($uploadedDocs->isNotEmpty())
                                    <div class="tw-space-y-2 w-100">
                                        @foreach($uploadedDocs as $sDoc)
                                            <div class="tw-rounded-lg tw-bg-surface-container-low tw-border tw-border-outline-variant/70 tw-p-2 tw-transition-colors">
                                                <div class="tw-flex tw-items-center tw-justify-center tw-gap-1.5 tw-mb-1.5 tw-min-w-0">
                                                    <x-ui.icon name="truck" size="sm" class="text-primary flex-shrink-0" />
                                                    <a href="{{ route('purchasing.shipments.show', $sDoc['shipment']) }}" 
                                                       class="tw-text-ui-xs tw-font-semibold text-primary text-decoration-none hover:tw-underline tw-truncate" 
                                                       title="{{ __('common.final_review.open_shipment', ['number' => $sDoc['shipment_number']]) }}">
                                                        {{ $sDoc['shipment_number'] }}
                                                    </a>
                                                </div>
                                                <a href="{{ route('attachments.show', $sDoc['attachment']) }}"
                                                   target="_blank" 
                                                   class="btn btn-outline-primary btn-sm py-1 px-2 tw-text-ui-xs tw-w-full d-inline-flex align-items-center justify-content-center gap-1.5 rounded" 
                                                   title="{{ __('common.actions.view') }} {{ $sDoc['attachment']->file_name }}">
                                                    <x-ui.icon name="file-text" size="sm" />
                                                    <span class="tw-truncate">{{ __('purchasing.copy.view_file') }}</span>
                                                </a>
                                            </div>
                                        @endforeach
                                    </div>
                                @else
                                    <div class="tw-rounded-lg tw-bg-surface-container-low/50 tw-border tw-border-dashed tw-border-outline-variant/60 tw-p-2 tw-text-center tw-flex tw-flex-col tw-items-center tw-justify-center" style="min-height: 72px;">
                                        <x-ui.icon name="file" size="sm" class="tw-text-outline/40 tw-mb-1" />
                                        <span class="tw-text-outline/70 tw-text-ui-xs tw-font-medium">{{ __('purchasing.copy.no_file_uploaded') }}</span>
                                    </div>
                                @endif
                            </div>

                            {{-- Zone 3: Footer Action --}}
                            <div class="tw-pt-1 tw-mt-auto">
                                <x-ui.button type="button" variant="outline" size="sm" class="btn-update-doc tw-w-full"
                                        data-doc-id="{{ $doc->id }}"
                                        data-doc-type="{{ $doc->doc_type }}"
                                        data-doc-label="{{ $config['label'] }}"
                                        data-doc-status="{{ $effectiveStatus }}"
                                        :data-doc-statuses="json_encode($config['statuses'])">
                                    <x-ui.icon name="square-pen" size="sm" class="me-1" /> {{ __('purchasing.copy.update_status') }}
                                </x-ui.button>
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-ui.card>
        </div>

        {{-- Sidebar Column --}}
        <aside class="tw-grid tw-gap-4" aria-label="{{ __('purchasing.copy.po_operations_and_timeline') }}">
            {{-- Supplier Chat Channel --}}
            <x-ui.card :title="__('purchasing.copy.supplier_negotiation')">
                <form action="{{ route('purchasing.conversations.start.po', $po) }}" method="POST" data-chat-start-form data-managed-submit>
                    @csrf
                    <input type="hidden" name="return_url" value="{{ \App\Support\PurchasingNavigation::currentUrlForReturn() }}">
                    <x-ui.button type="submit" variant="outline" size="sm" class="tw-w-full tw-justify-between">
                        <span class="d-inline-flex align-items-center gap-2">
                            <x-ui.icon name="message-square" size="sm" />
                            <span class="fw-semibold">{{ __('purchasing.copy.chat_with_supplier') }}</span>
                        </span>
                        <x-ui.icon name="chevron-right" size="sm" />
                    </x-ui.button>
                </form>
            </x-ui.card>

            {{-- Material Claim Alert (if status claim_needed) --}}
            @if($po->status === 'claim_needed')
                <x-ui.card :title="__('purchasing.copy.defect_claim_follow_up')" id="sec-claim" class="tw-scroll-mt-24 border-danger">
                    <x-slot:actions><span class="ui-status-chip ui-status-chip--error">NG</span></x-slot:actions>
                    <p class="text-danger tw-text-ui-xs fw-medium mb-3">
                        {{ __('purchasing.copy.quality_inspection_failed_status_ng_immediate_claim_action_is_required') }}
                    </p>

                    @if($activeClaim)
                        <x-ui.button :href="\App\Support\PurchasingNavigation::toRoute('purchasing.claims.show', $activeClaim)" variant="danger" size="sm" class="tw-w-full tw-justify-between">
                            <span><x-ui.icon name="octagon-alert" size="sm" class="me-1" /> {{ __('purchasing.copy.view_active_claim') }}</span>
                            <x-ui.icon name="chevron-right" size="sm" />
                        </x-ui.button>
                    @elseif($latestNgInspection)
                        <x-ui.button :href="\App\Support\PurchasingNavigation::toRoute('purchasing.claims.create', $latestNgInspection)" variant="danger" size="sm" class="tw-w-full tw-justify-between">
                            <span><x-ui.icon name="plus-circle" size="sm" class="me-1" /> {{ __('purchasing.copy.submit_material_claim') }}</span>
                            <x-ui.icon name="chevron-right" size="sm" />
                        </x-ui.button>
                    @else
                        <x-ui.button :href="\App\Support\PurchasingNavigation::toRoute('purchasing.claims.index')" variant="outline" size="sm" class="tw-w-full tw-justify-between">
                            <span><x-ui.icon name="folder-open" size="sm" class="me-1" /> {{ __('purchasing.copy.open_claim_list') }}</span>
                            <x-ui.icon name="chevron-right" size="sm" />
                        </x-ui.button>
                    @endif
                </x-ui.card>
            @endif

            {{-- Confirm Arrival Action Button --}}
            @if(in_array($po->status, ['active', 'overdue']) && !$po->actual_arrival && $po->isLegacyArrivalEligible())
                <x-ui.card :title="__('purchasing.copy.delivery_status_action')">
                    <form action="{{ route('purchasing.purchase-orders.confirm-arrival', $po) }}" method="POST" id="arrivalForm">
                        @csrf
                        <x-ui.button type="button" size="sm" class="tw-w-full" id="btnConfirmArrival">
                            <x-ui.icon name="package-check" size="sm" />
                            <span>{{ __('purchasing.copy.confirm_material_arrival') }}</span>
                        </x-ui.button>
                    </form>
                </x-ui.card>
            @endif

            {{-- Timeline History --}}
            <x-ui.card :title="__('purchasing.copy.order_timeline')" id="sec-timeline" class="tw-scroll-mt-24">
                <ol class="pr-timeline">
                    <li class="pr-timeline-item is-complete">
                        <span class="pr-timeline-marker" aria-hidden="true"></span>
                        <div class="tw-text-ui-sm fw-bold text-primary">{{ __('purchasing.copy.po_created') }}</div>
                        <time class="ui-tabular-nums tw-text-on-surface-variant tw-text-ui-xs" datetime="{{ $po->created_at->toIso8601String() }}">{{ $regionalFormatter->timestamp($po->created_at, 'datetime_comma') }}</time>
                    </li>

                    @foreach($po->documents->sortBy('updated_at') as $doc)
                        @if($doc->status !== 'pending')
                            @php
                                $docLabels = [
                                    'invoice' => __('purchasing.copy.invoice_document'),
                                    'bl' => __('purchasing.copy.bill_of_lading'),
                                    'packing_list' => __('purchasing.copy.packing_list'),
                                    'form_e' => 'Form-E',
                                ];
                            @endphp
                            <li class="pr-timeline-item is-complete">
                                <span class="pr-timeline-marker" aria-hidden="true"></span>
                                <div class="tw-text-ui-sm fw-bold tw-text-on-surface">{{ $docLabels[$doc->doc_type] ?? __('purchasing.copy.document') }}: {{ \App\Support\StatusHelper::shipmentDocLabel($doc->status) }}</div>
                                <time class="ui-tabular-nums tw-text-on-surface-variant tw-text-ui-xs" datetime="{{ $doc->updated_at->toIso8601String() }}">{{ $regionalFormatter->timestamp($doc->updated_at, 'datetime_comma') }}</time>
                            </li>
                        @endif
                    @endforeach

                    <li class="pr-timeline-item {{ $po->estimated_arrival && $po->estimated_arrival->isPast() ? 'is-current' : '' }}">
                        <span class="pr-timeline-marker" aria-hidden="true"></span>
                        <div class="tw-text-ui-sm fw-bold {{ $po->estimated_arrival && $po->estimated_arrival->isPast() ? 'text-warning' : 'tw-text-on-surface-variant' }}">{{ __('purchasing.copy.estimated_arrival') }}</div>
                        <time class="ui-tabular-nums tw-text-on-surface-variant tw-text-ui-xs">{{ $po->estimated_arrival ? $regionalFormatter->date($po->estimated_arrival) : '-' }}</time>
                    </li>

                    <li class="pr-timeline-item {{ $po->actual_arrival ? 'is-complete' : '' }}">
                        <span class="pr-timeline-marker" aria-hidden="true"></span>
                        <div class="tw-text-ui-sm fw-bold {{ $po->actual_arrival ? 'text-success' : 'tw-text-outline' }}">{{ __('purchasing.copy.material_arrival') }}</div>
                        @if($po->actual_arrival)
                            <time class="ui-tabular-nums tw-text-on-surface-variant tw-text-ui-xs">{{ $regionalFormatter->date($po->actual_arrival) }}</time>
                        @endif
                    </li>
                </ol>
            </x-ui.card>
        </aside>
    </div>
</div>

{{-- Update Document Modal --}}
<div class="modal fade" id="updateDocModal" tabindex="-1" aria-labelledby="modalDocTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title fw-bold" id="modalDocTitle">{{ __('purchasing.copy.update_document_status') }}</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('purchasing.copy.close') }}"></button>
            </div>
            <div class="modal-body tw-p-3.5">
                <input type="hidden" id="modalDocId">
                <div>
                    <label class="form-label small fw-semibold tw-text-on-surface" for="modalDocStatus">{{ __('purchasing.copy.select_new_status') }}</label>
                    <select class="form-select form-select-sm" id="modalDocStatus"></select>
                </div>
            </div>
            <div class="modal-footer tw-bg-surface-low border-top">
                <x-ui.button type="button" variant="ghost" size="sm" data-bs-dismiss="modal">{{ __('purchasing.copy.cancel') }}</x-ui.button>
                <x-ui.button type="button" size="sm" id="btnSaveDocStatus">
                    <span class="spinner-border spinner-border-sm d-none me-1" id="docSpinner"></span>
                    {{ __('purchasing.copy.save_changes') }}
                </x-ui.button>
            </div>
        </div>
    </div>
</div>

{{-- Shared Progress History Modal --}}
<div class="modal fade" id="progressHistoryModal" tabindex="-1" aria-labelledby="progressHistoryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title tw-text-ui-base fw-bold" id="progressHistoryModalLabel">
                    {{ __('purchasing.copy.material_progress_history') }}
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('purchasing.copy.close') }}"></button>
            </div>
            <div class="modal-body" id="progressHistoryModalBody">
                <div class="text-center py-4 text-muted">
                    <div class="spinner-border spinner-border-sm text-primary me-1" role="status"></div> {{ __('purchasing.copy.loading_history') }}
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">{{ __('purchasing.copy.close') }}</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    $(document).ready(function() {
        const navLinks = $('#po-section-nav .nav-link');
        let isProgrammaticScroll = false;
        let manualClickLockId = null;
        let unlockTimer = null;

        function getStickyOffset() {
            const navHeight = $('.po-sticky-nav-bar').outerHeight() || 46;
            return 56 + navHeight + 16;
        }

        function setActiveTab(targetId) {
            navLinks.removeClass('active');
            const $activeLink = $(`#po-section-nav .nav-link[href="${targetId}"]`);
            if ($activeLink.length) {
                $activeLink.addClass('active');
            }
        }

        function updateActiveSection() {
            if (isProgrammaticScroll) return;
            if (manualClickLockId) {
                setActiveTab(manualClickLockId);
                return;
            }

            const stickyOffset = getStickyOffset();
            const scrollPos = $(window).scrollTop();
            const viewportFocusLine = scrollPos + stickyOffset + 40;

            let bestMatchId = null;
            let bestDistance = Infinity;

            navLinks.each(function() {
                const href = $(this).attr('href');
                if (!href || !href.startsWith('#')) return;
                const $target = $(href);
                if (!$target.length || !$target.is(':visible')) return;

                const top = $target.offset().top;
                const height = $target.outerHeight();
                const bottom = top + height;

                if (top <= viewportFocusLine && bottom > viewportFocusLine - 60) {
                    const distance = Math.abs(viewportFocusLine - top);
                    if (distance < bestDistance) {
                        bestDistance = distance;
                        bestMatchId = href;
                    }
                }
            });

            if (!bestMatchId) {
                if (scrollPos < 150) {
                    bestMatchId = navLinks.first().attr('href');
                } else {
                    let minDiff = Infinity;
                    navLinks.each(function() {
                        const href = $(this).attr('href');
                        if (!href || !href.startsWith('#')) return;
                        const $target = $(href);
                        if (!$target.length || !$target.is(':visible')) return;
                        const diff = Math.abs($target.offset().top - (scrollPos + stickyOffset));
                        if (diff < minDiff) {
                            minDiff = diff;
                            bestMatchId = href;
                        }
                    });
                }
            }

            if (bestMatchId) {
                setActiveTab(bestMatchId);
            }
        }

        let highlightTimer = null;

        function highlightTargetSection($target, isDanger) {
            $('.section-target-highlight, .section-target-highlight--danger').removeClass('section-target-highlight section-target-highlight--danger');
            if (highlightTimer) {
                clearTimeout(highlightTimer);
            }

            const highlightClass = isDanger ? 'section-target-highlight--danger' : 'section-target-highlight';

            // Force DOM reflow to re-trigger CSS animation smoothly on repeated clicks
            $target.removeClass(highlightClass);
            if ($target.length && $target[0]) {
                void $target[0].offsetWidth;
            }
            $target.addClass(highlightClass);

            highlightTimer = setTimeout(() => {
                $target.removeClass(highlightClass);
            }, 1850);
        }

        // Release lock when user manually scrolls with wheel, touch, or keys
        $(window).on('wheel touchmove keydown', function() {
            manualClickLockId = null;
            if (unlockTimer) {
                clearTimeout(unlockTimer);
                unlockTimer = null;
            }
        });

        $(window).on('scroll', updateActiveSection);

        navLinks.on('click', function(e) {
            e.preventDefault();
            const targetId = $(this).attr('href');
            const $target = $(targetId);
            if (!$target.length) return;

            // Lock this tab as the explicit active selection
            manualClickLockId = targetId;
            setActiveTab(targetId);

            if (unlockTimer) {
                clearTimeout(unlockTimer);
            }
            unlockTimer = setTimeout(() => {
                manualClickLockId = null;
            }, 1500);

            const isDanger = targetId === '#sec-claim' || $(this).hasClass('text-danger');
            highlightTargetSection($target, isDanger);

            const targetPosition = Math.max(0, $target.offset().top - getStickyOffset());

            isProgrammaticScroll = true;
            $('html, body').stop().animate({
                scrollTop: targetPosition
            }, 300, function() {
                setTimeout(() => {
                    isProgrammaticScroll = false;
                }, 50);
            });
        });

        // Initialize active state on load
        updateActiveSection();
    });

    $('.btn-update-doc').on('click', function() {
        const docId = $(this).data('doc-id');
        const docLabel = $(this).data('doc-label');
        const currentStatus = $(this).data('doc-status');
        const statuses = $(this).data('doc-statuses');

        $('#modalDocId').val(docId);
        $('#modalDocTitle').text(@js(__('common.final_review.update_document')).replace(':document', docLabel));

        const select = $('#modalDocStatus');
        select.empty();
        for (const [key, label] of Object.entries(statuses)) {
            select.append(`<option value="${key}" ${key === currentStatus ? 'selected' : ''}>${label}</option>`);
        }

        const modal = new bootstrap.Modal(document.getElementById('updateDocModal'));
        modal.show();
    });

    $('#btnSaveDocStatus').on('click', function() {
        const docId = $('#modalDocId').val();
        const newStatus = $('#modalDocStatus').val();

        $('#docSpinner').removeClass('d-none');
        $(this).prop('disabled', true);

        $.ajax({
            url: '/purchasing/po-documents/' + docId,
            method: 'PUT',
            data: {
                _token: '{{ csrf_token() }}',
                status: newStatus
            },
            success: function(res) {
                if (res.success) {
                    const statusLabels = {
                        'pending': @json(__('purchasing.copy.not_available')), 'received': @json(\App\Support\StatusHelper::shipmentDocLabel('received')), 'verified': @json(\App\Support\StatusHelper::shipmentDocLabel('verified')),
                        'issued': @json(\App\Support\StatusHelper::shipmentDocLabel('issued')), 'processing': @json(\App\Support\StatusHelper::shipmentDocLabel('processing')), 'done': @json(\App\Support\StatusHelper::shipmentDocLabel('done'))
                    };
                    const statusClasses = {
                        'pending': 'ui-status-chip--neutral', 'received': 'ui-status-chip--info', 'verified': 'ui-status-chip--success',
                        'issued': 'ui-status-chip--info', 'processing': 'ui-status-chip--info', 'done': 'ui-status-chip--success'
                    };

                    const badge = $('#doc-badge-' + docId);
                    badge.text(statusLabels[res.doc.status] || res.doc.status);
                    badge.attr('class', 'ui-status-chip tw-mb-1.5 doc-status-badge ' + (statusClasses[res.doc.status] || 'ui-status-chip--neutral'));
                    badge.attr('data-status', res.doc.status);
                    $('#doc-date-' + docId).text(res.doc.updated_at);

                    $(`.btn-update-doc[data-doc-id="${docId}"]`).data('doc-status', res.doc.status);

                    const completedStatuses = ['received', 'verified', 'done'];
                    let completed = 0;
                    const total = {{ $totalDocs }};
                    completed = $('.doc-status-badge').filter(function() {
                        return completedStatuses.includes($(this).attr('data-status'));
                    }).length;

                    const pct = total > 0 ? (completed / total * 100) : 0;
                    $('#docProgressBar')
                        .css('width', pct + '%')
                        .attr('aria-valuenow', Math.round(pct));
                    const docsComplete = completed >= total;
                    $('#docProgressBadge')
                        .text(completed + '/' + total + ' complete')
                        .attr('class', 'ui-status-chip ' + (docsComplete ? 'ui-status-chip--success' : 'ui-status-chip--warning'))
                        .attr('data-bs-title', docsComplete
                            ? @json(__('purchasing.copy.all_import_documents_are_complete'))
                            : @json(__('purchasing.copy.some_import_documents_still_need_to_be_completed_or_verified')));
                    window.initAdasiTooltips?.(document);

                    if (docsComplete) {
                        if ($('#allDocsAlert').length === 0) {
                            const alertTemplate = document.getElementById('allDocsAlertTemplate');
                            $('.progress').after(alertTemplate ? alertTemplate.innerHTML : '');
                        }
                    } else {
                        $('#allDocsAlert').remove();
                    }

                    bootstrap.Modal.getInstance(document.getElementById('updateDocModal')).hide();

                    AdasiToast.show({
                        type: 'success',
                        title: @json(__('common.feedback.success')),
                        message: res.message,
                        autoClose: 1500
                    });
                }
            },
            error: function(xhr) {
                AdasiToast.show({
                    type: 'error',
                    title: @json(__('purchasing.copy.update_failed')),
                    message: @json(__('purchasing.copy.the_document_status_could_not_be_updated')),
                    autoClose: 4000
                });
            },
            complete: function() {
                $('#docSpinner').addClass('d-none');
                $('#btnSaveDocStatus').prop('disabled', false);
            }
        });
    });

    $('#btnConfirmArrival').on('click', function() {
        AdasiAlert.confirm({
            title: @json(__('purchasing.copy.confirm_material_arrival')),
            text: @json(__('purchasing.copy.the_arrival_date_will_be_set_to_today_and_qc_will_be_notified')),
            confirmTone: 'success',
            confirmText: @json(__('purchasing.audit_ui.confirm_arrival')),
            cancelText: @json(__('purchasing.copy.cancel'))
        }).then((result) => {
            if (result.isConfirmed) {
                $('#arrivalForm').submit();
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
            details.appendChild(textElement('strong', '', @json(__('purchasing.copy.supplier_controlled_qty_snapshot'))));
            appendText(details, ` ${item.supplier_controlled_qty_snapshot} pcs`);
            if (item.estimated_ready_date_display) {
                appendText(details, ' • ');
                details.appendChild(textElement('strong', '', @json(__('purchasing.copy.estimated_ready'))));
                appendText(details, ` ${item.estimated_ready_date_display}`);
            }
            appendText(details, ' • ');
            details.appendChild(textElement('strong', '', @json(__('purchasing.copy.updated_by'))));
            appendText(details, ` ${item.updated_by ?? @json(__('purchasing.copy.supplier_user'))}`);

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

    $(document).on('click', '.btn-view-purchasing-history', function() {
        const materialName = $(this).data('materialName');
        const historyUrl = $(this).data('historyUrl');
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
</script>
@endpush
