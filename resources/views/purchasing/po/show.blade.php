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
        min-width: 0;
        max-width: 100%;
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
        list-style: none;
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

    .po-nav-pills .po-nav-item {
        flex-shrink: 0;
    }

    .po-nav-pills .po-nav-link {
        display: inline-flex;
        align-items: center;
        min-height: 2.25rem;
        font-size: var(--ui-font-size-sm);
        font-weight: 600;
        padding: 0.4rem 0.95rem;
        color: var(--md-on-surface-variant);
        border-radius: var(--md-shape-full);
        transition: all 0.15s ease;
        white-space: nowrap;
        text-decoration: none;
    }

    .po-nav-pills .po-nav-link:focus-visible {
        outline: 2px solid var(--md-primary);
        outline-offset: 2px;
    }

    .po-nav-pills .po-nav-link:hover:not(.active) {
        background-color: var(--md-surface-container-high);
        color: var(--md-on-surface);
    }

    .po-nav-pills .po-nav-link.active {
        background-color: var(--md-primary) !important;
        color: var(--md-on-primary) !important;
        box-shadow: 0 1px 3px rgba(var(--md-primary-rgb), 0.35);
    }

    .po-nav-pills .po-nav-link.po-nav-link--danger.active {
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
<div class="tw:grid tw:grid-cols-[minmax(0,1fr)] tw:min-w-0 tw:gap-4">
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
        :record-nav="$recordNav ?? null"
    >
        <x-slot:actions>
            <x-status-badge type="po" :status="$po->status" :is-overdue="$po->is_overdue" :has-active-shipments="$po->hasActiveShipments()" size="lg" />
            <x-ui.button
                type="button"
                variant="outline"
                size="sm"
                data-smart-panel-trigger
                data-entity-type="import-po"
                :data-entity-hash="$po->hashid"
                :data-smart-panel-url="\App\Support\PortalPath::routePath('context-panel.show', ['entityType' => 'import-po', 'hash' => $po->hashid])"
            >
                <x-ui.icon name="git-branch" size="sm" />
                <span>{{ __('smart_panel.trigger') }}</span>
            </x-ui.button>
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

    {{-- PO logistics snapshot (Q1/Q7); "Not set" for POs created before it existed. --}}
    @include('partials.procurement-logistics', ['port' => $po->port_of_discharge, 'incoterm' => $po->incoterm])

    {{-- 4-Key Tracking Dates Strip --}}
    @php
        $primaryPr = $po->quotations->first()?->purchaseRequisition;
        $prDate = $primaryPr?->created_at;
    @endphp
    <div class="po-tracking-strip">
        <div class="po-tracking-step {{ $prDate ? 'is-active' : '' }}">
            <div class="tw:text-on-surface-variant tw:text-ui-xs tw:font-semibold tw:uppercase">{{ __('purchasing.copy.1_pr_created') }}</div>
            <div class="tw:font-bold tw:text-on-surface tw:text-ui-sm tw:mt-0.5">{{ $prDate ? $regionalFormatter->timestamp($prDate, 'date') : '-' }}</div>
            <div class="tw:text-on-surface-variant tw:text-ui-xs">{{ $primaryPr?->pr_number ?? __('terms.purchase_requisition') }}</div>
        </div>
        <div class="po-tracking-step is-active">
            <div class="tw:text-on-surface-variant tw:text-ui-xs tw:font-semibold tw:uppercase">{{ __('purchasing.copy.2_po_created') }}</div>
            <div class="tw:font-bold tw:text-on-surface tw:text-ui-sm tw:mt-0.5">{{ $regionalFormatter->timestamp($po->created_at, 'date') }}</div>
            <div class="tw:text-on-surface-variant tw:text-ui-xs">{{ $regionalFormatter->time($po->created_at) }}</div>
        </div>
        <div class="po-tracking-step {{ $po->estimated_arrival ? 'is-active' : '' }}">
            <div class="tw:text-on-surface-variant tw:text-ui-xs tw:font-semibold tw:uppercase">{{ __('purchasing.copy.3_po_target_arrival_date') }}</div>
            <div class="tw:font-bold {{ $po->is_overdue ? 'tw:text-error' : 'tw:text-on-surface' }} tw:text-ui-sm tw:mt-0.5">
                {{ $po->estimated_arrival ? $regionalFormatter->date($po->estimated_arrival) : '-' }}
            </div>
            <div class="tw:text-on-surface-variant tw:text-ui-xs">
                {{ $po->is_overdue ? __('purchasing.copy.overdue') : __('purchasing.copy.purchasing_target_at_adasi') }}
            </div>
        </div>
        <div class="po-tracking-step {{ $po->actual_arrival ? 'is-active' : '' }}">
            <div class="tw:text-on-surface-variant tw:text-ui-xs tw:font-semibold tw:uppercase">{{ __('purchasing.copy.4_actual_arrival') }}</div>
            <div class="tw:font-bold {{ $po->actual_arrival ? 'tw:text-success' : 'tw:text-on-surface-variant' }} tw:text-ui-sm tw:mt-0.5">
                @if($po->actual_arrival)
                    <x-ui.icon name="circle-check" size="sm" class="tw:me-1 tw:text-success" />
                    {{ $regionalFormatter->date($po->actual_arrival) }}
                @else
                    {{ __('purchasing.audit_ui.pending_delivery') }}
                @endif
            </div>
            <div class="tw:text-on-surface-variant tw:text-ui-xs">
                {{-- PUR-08: actual_arrival is the latest arrival; it means "received" only once completed. --}}
                {{ ! $po->actual_arrival ? __('purchasing.copy.waiting_arrival') : ($po->status === 'completed' ? __('purchasing.copy.received_at_plant') : __('purchasing.copy.latest_partial_arrival')) }}
            </div>
        </div>
    </div>

    {{-- D4/E1: Sales Contract handshake (Purchasing signed copy gates supplier acknowledgement and shipments) --}}
    @include('partials.po-sales-contract-panel', ['po' => $po, 'viewer' => 'purchasing'])

    {{-- Supplier PO Acknowledgement & Promise Date Handshake Status (A1) --}}
    <x-ui.card
        :title="__('purchasing.po_ack.card_title')"
        :description="__('purchasing.po_ack.purchasing_subtitle')"
        id="sec-po-acknowledgement"
    >
        <x-slot:actions>
            <x-ui.status-chip :tone="\App\Support\StatusHelper::poAcknowledgementTone($po->acknowledgement_status)">
                {{ \App\Support\StatusHelper::poAcknowledgementLabel($po->acknowledgement_status) }}
            </x-ui.status-chip>
        </x-slot:actions>

        <div class="tw:grid tw:gap-4 tw:md:grid-cols-3 tw:text-ui-xs">
            <div>
                <span class="tw:text-on-surface-variant tw:block">{{ __('purchasing.po_ack.fields.target_date') }}</span>
                <strong class="ui-tabular-nums tw:text-on-surface">{{ $po->estimated_arrival ? $regionalFormatter->date($po->estimated_arrival) : '-' }}</strong>
            </div>
            <div>
                <span class="tw:text-on-surface-variant tw:block">{{ __('purchasing.po_ack.fields.promised_date') }}</span>
                <strong class="ui-tabular-nums tw:text-on-surface">{{ $po->supplier_promised_date ? $regionalFormatter->date($po->supplier_promised_date) : '-' }}</strong>
            </div>
            <div>
                <span class="tw:text-on-surface-variant tw:block">{{ __('purchasing.po_ack.fields.acknowledged_at') }}</span>
                <strong class="ui-tabular-nums tw:text-on-surface">{{ $po->acknowledged_at ? $regionalFormatter->timestamp($po->acknowledged_at, 'datetime') : '-' }}</strong>
            </div>
            @if($po->acknowledgement_notes)
                <div class="tw:md:col-span-3 tw:rounded-ui-sm tw:border tw:border-outline-variant tw:bg-surface-container tw:p-3">
                    <span class="tw:text-on-surface-variant tw:font-semibold tw:block">{{ __('purchasing.po_ack.fields.notes') }}</span>
                    <p class="tw:mb-0 tw:mt-1 tw:text-on-surface">{{ $po->acknowledgement_notes }}</p>
                </div>
            @endif
        </div>

        @if($po->acknowledgementStatusEnum() === \App\Enums\PoAcknowledgementStatus::CHANGE_REQUESTED)
            <form method="POST" action="{{ route('purchasing.purchase-orders.respond-promise-change', $po) }}" class="tw:mt-4 tw:border-t tw:border-outline-variant tw:pt-4 tw:grid tw:gap-3 tw:md:grid-cols-12 tw:items-end">
                @csrf
                <div class="tw:md:col-span-6">
                    <label for="purchasing-ack-response-notes" class="ui-label tw:text-ui-xs tw:font-semibold">{{ __('purchasing.po_ack.fields.response_notes') }}</label>
                    <input type="text" name="response_notes" id="purchasing-ack-response-notes" class="ui-input ui-input--sm" placeholder="{{ __('purchasing.po_ack.placeholders.response_notes') }}">
                </div>
                <div class="tw:md:col-span-3">
                    <x-ui.button type="submit" name="decision" value="accept" variant="primary" size="sm" class="tw:w-full tw:justify-center">
                        <x-ui.icon name="check" size="sm" />
                        <span>{{ __('purchasing.po_ack.actions.accept_change') }}</span>
                    </x-ui.button>
                </div>
                <div class="tw:md:col-span-3">
                    <x-ui.button type="submit" name="decision" value="reject" variant="danger" size="sm" class="tw:w-full tw:justify-center">
                        <x-ui.icon name="x" size="sm" />
                        <span>{{ __('purchasing.po_ack.actions.reject_change') }}</span>
                    </x-ui.button>
                </div>
            </form>
        @endif
    </x-ui.card>

    {{-- Sticky In-Page Navigation Bar --}}
    <nav class="po-sticky-nav-bar" aria-label="{{ __('purchasing.copy.purchase_order_sections') }}">
        <ul class="po-nav-pills tw:list-none" id="po-section-nav">
            <li class="po-nav-item"><a class="po-nav-link active" href="#sec-info">{{ __('purchasing.copy.order_info') }}</a></li>
            <li class="po-nav-item"><a class="po-nav-link" href="#sec-material">{{ __('purchasing.copy.materials_commercials') }}</a></li>
            @if(isset($itemProjections) && $itemProjections->isNotEmpty())
                <li class="po-nav-item"><a class="po-nav-link" href="#sec-material-progress">{{ __('purchasing.copy.material_progress') }}</a></li>
            @endif
            @if($po->qcInspections->isNotEmpty())
                <li class="po-nav-item"><a class="po-nav-link" href="#sec-inspection">{{ __('purchasing.copy.qc_inspection') }}</a></li>
            @endif
            <li class="po-nav-item"><a class="po-nav-link" href="#sec-document">{{ __('purchasing.copy.import_documents') }}</a></li>
            @if($po->status === 'claim_needed')
                <li class="po-nav-item"><a class="po-nav-link po-nav-link--danger tw:text-error" href="#sec-claim">{{ __('purchasing.copy.material_claim') }}</a></li>
            @endif
            <li class="po-nav-item"><a class="po-nav-link" href="#sec-timeline">{{ __('purchasing.copy.timeline') }}</a></li>
        </ul>
    </nav>

    <div class="tw:grid tw:grid-cols-[minmax(0,1fr)] tw:items-start tw:gap-4 tw:xl:grid-cols-[minmax(0,1fr)_16rem]">
        {{-- Main Column --}}
        <div class="tw:grid tw:min-w-0 tw:gap-4">
            {{-- Order Info Card --}}
            <x-ui.card :title="__('purchasing.copy.order_information')" id="sec-info" class="tw:scroll-mt-24">
                <div class="tw:grid tw:gap-px tw:overflow-hidden tw:border tw:border-outline-variant tw:bg-outline-variant tw:sm:grid-cols-2 tw:lg:grid-cols-3">
                    <div class="tw:bg-surface-container tw:p-2.5">
                        <div class="tw:text-on-surface-variant tw:text-ui-xs tw:font-semibold tw:uppercase">{{ __('purchasing.copy.supplier') }}</div>
                        <div class="tw:font-bold tw:text-on-surface tw:text-ui-sm tw:mt-0.5">{{ $po->supplier->name }}</div>
                        @include('quotations._supplier_pic', ['profile' => $po->supplier->supplier])
                    </div>
                    <div class="tw:bg-surface-container tw:p-2.5">
                        <div class="tw:text-on-surface-variant tw:text-ui-xs tw:font-semibold tw:uppercase">{{ __('purchasing.copy.reference_no_pr') }}</div>
                        <div class="tw:font-bold tw:text-primary tw:text-ui-sm tw:mt-0.5">
                            @php $prs = $po->purchaseRequisitions(); @endphp
                            @if($prs->isEmpty())
                                -
                            @else
                                @foreach($prs as $pr)
                                    <a href="{{ \App\Support\PurchasingNavigation::toRoute('purchasing.requisitions.show', $pr) }}" class="tw:text-primary tw:no-underline tw:me-1.5">
                                        {{ $pr->pr_number ?? '-' }}
                                    </a>
                                @endforeach
                                @if($prs->count() > 1)
                                    <span class="ui-status-chip ui-status-chip--info">{{ trans_choice('purchasing.copy.pr_count', $prs->count(), ['count' => $prs->count()]) }}</span>
                                @endif
                            @endif
                        </div>
                    </div>
                    <div class="tw:bg-surface-container tw:p-2.5">
                        <div class="tw:text-on-surface-variant tw:text-ui-xs tw:font-semibold tw:uppercase">{{ __('purchasing.copy.procurement_period') }}</div>
                        <div class="tw:font-semibold tw:text-on-surface tw:text-ui-sm tw:mt-0.5">
                            @php $periods = $prs->map(fn($pr) => $pr->period?->display_label ?? '-')->unique(); @endphp
                            {{ $periods->implode(', ') }}
                        </div>
                    </div>
                    <div class="tw:bg-surface-container tw:p-2.5">
                        <div class="tw:text-on-surface-variant tw:text-ui-xs tw:font-semibold tw:uppercase">{{ __('purchasing.copy.locked_currency') }}</div>
                        <div class="tw:font-bold tw:text-on-surface tw:text-ui-sm tw:mt-0.5">
                            <span class="ui-status-chip ui-status-chip--neutral">{{ $po->currency }}</span>
                        </div>
                    </div>
                    <div class="tw:bg-surface-container tw:p-2.5 tw:sm:col-span-2">
                        <div class="tw:text-on-surface-variant tw:text-ui-xs tw:font-semibold tw:uppercase">{{ __('purchasing.copy.po_notes_remark') }}</div>
                        <div class="tw:text-on-surface tw:text-ui-sm tw:mt-0.5">{{ $po->notes ?: '-' }}</div>
                    </div>
                </div>
            </x-ui.card>

            {{-- Material Details Table --}}
            <x-ui.data-table
                sticky
                :scroll-label="__('procurement.tables.po_items')"
            :title="__('purchasing.copy.materials_and_commercial_breakdown')"
                :description="__('purchasing.copy.line_items_grouped_by_quotation_and_reference_pr')"
                id="sec-material"
                class="tw:scroll-mt-24"
            >
                <table class="ui-table ui-table--hover tw:align-middle tw:mb-0 tw:text-ui-xs tw:w-full">
                    <thead class="tw:text-center">
                        <tr>
                            <th scope="col" style="width: 35px;">{{ __('purchasing.copy.no') }}</th>
                            <th scope="col" class="ui-sticky-col">{{ __('purchasing.copy.material') }}</th>
                            <th scope="col">{{ __('purchasing.copy.specification') }}</th>
                            <th scope="col">{{ __('purchasing.copy.qty') }}</th>
                            <th scope="col" class="tw:text-end">{{ __('purchasing.copy.weight_unit') }}</th>
                            <th scope="col" class="tw:text-end">{{ __('purchasing.copy.total_weight') }}</th>
                            <th scope="col" class="tw:text-end">{{ __('purchasing.copy.price_kg') }}</th>
                            <th scope="col" class="tw:text-end">{{ __('purchasing.copy.amount') }}</th>
                            <th scope="col" class="tw:text-end">{{ __('purchasing.copy.converted_idr') }}</th>
                            <th scope="col">{{ __('purchasing.copy.reference_no_pr') }}</th>
                            <th scope="col">{{ __('purchasing.copy.remark') }}</th>
                        </tr>
                    </thead>
                    @php
                        $globalNo = 1;
                        $grandTotalAmount = 0;
                        $grandTotalIdr = 0;
                        // I-15: ordered qty/KG/amount per line from the single resolver.
                        $orderedLinesByItem = $po->orderedLines()->keyBy(fn ($line) => $line->quotationItem->id);
                        $grandIdrUnavailable = false;
                        $poRemark = trim((string) $po->notes);
                    @endphp
                    @foreach($po->commercialQuotations() as $quotation)
                        @php $rate = $quotationRates[$quotation->id] ?? null; @endphp
                        @if($po->quotations->count() > 1)
                            <tbody class="ui-po-group-banner">
                                <tr class="tw:bg-primary/10 tw:text-primary tw:border-y tw:border-outline-variant">
                                    <td colspan="11" class="tw:font-bold tw:py-2 tw:ps-3">
                                        <x-ui.icon name="folder" size="sm" class="tw:me-1" />
                                        {{ $quotation->purchaseRequisition->pr_number ?? 'PR -' }}
                                        <span class="tw:text-on-surface-variant tw:font-normal tw:ms-2">
                                            ({{ $quotation->purchaseRequisition->period->display_label ?? $quotation->purchaseRequisition->period->name ?? '-' }})
                                            @if($rate)
                                                &bull; {{ __('purchasing.copy.locked_exchange_rate') }}: 1 {{ $quotation->currency }} = Rp {{ $regionalFormatter->groupedDecimal($rate->rate_to_idr) }}
                                            @endif
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        @endif
                        <tbody>
                            @foreach($quotation->items as $item)
                                @php
                                    $orderedLine = $orderedLinesByItem->get($item->id);
                                    $amount = $orderedLine ? (float) $orderedLine->amount : $item->resolved_amount;
                                    // Q4C: IDR identity or snapshot rate; never an invented 1.
                                    $lineRate = strtoupper((string) $quotation->currency) === 'IDR' ? 1.0 : ($rate && (float) $rate->rate_to_idr > 0 ? (float) $rate->rate_to_idr : null);
                                    $idr = $lineRate === null ? null : $amount * $lineRate;
                                    $grandTotalAmount += $amount;
                                    if ($idr === null) { $grandIdrUnavailable = true; } else { $grandTotalIdr += $idr; }
                                @endphp
                                <tr>
                                    <td class="tw:text-center tw:text-on-surface-variant ui-tabular-nums">{{ $globalNo++ }}</td>
                                    <td class="tw:font-bold tw:text-on-surface ui-sticky-col ui-col-data-name">{{ $item->prItem->material_name }}</td>
                                    <td class="tw:text-center ui-col-data-name">
                                        @if(!$item->is_available)
                                            <span class="ui-status-chip ui-status-chip--error">{{ __('purchasing.copy.not_available') }}</span>
                                        @elseif($item->prItem->shape)
                                            <div class="tw:text-on-surface-variant tw:text-ui-xs">{{ __('common.final_copy.requested') }} {{ $item->prItem->dimension_label }}</div>
                                            <div class="tw:font-semibold tw:text-ui-xs tw:mt-0.5">{{ __('common.final_copy.offer') }} {{ $item->available_dimension_label }}</div>
                                        @else
                                            <span class="tw:text-on-surface-variant">-</span>
                                        @endif
                                    </td>
                                    <td class="tw:text-center ui-tabular-nums">{{ $item->is_available ? ($orderedLine?->qty ?? $item->available_qty ?? $item->prItem->quantity_value) : '—' }}
                                        @if($item->is_available)@include('procurement._ordered_delta', ['ordered' => $orderedLine?->qty, 'requested' => $item->prItem?->quantity_value])@endif
                                    </td>
                                    <td class="tw:text-end ui-tabular-nums tw:text-on-surface-variant">
                                        {{ $item->is_available ? $regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($item->offered_weight_per_unit ?? $item->prItem->weight_needed), 'decimal') : '—' }}
                                        @if($item->is_available && $item->is_estimated_weight)<span class="ui-status-chip ui-status-chip--warning tw:ms-1">{{ __('purchasing.copy.est_weight') }}</span>@endif
                                    </td>
                                    <td class="tw:text-end tw:font-bold tw:text-primary ui-tabular-nums">{{ $item->is_available ? $regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($orderedLine?->kg ?? $item->offered_total_weight ?? $item->prItem->total_weight), 'decimal') : '—' }}</td>
                                    <td class="tw:text-end ui-tabular-nums tw:text-on-surface-variant">
                                        {{ $regionalFormatter->groupedDecimal($item->price_per_kg, 4) }}
                                    </td>
                                    <td class="tw:text-end tw:font-semibold ui-tabular-nums">{{ $item->is_available ? $regionalFormatter->groupedDecimal($amount) : '—' }}</td>
                                    <td class="tw:text-end tw:font-bold tw:text-on-surface ui-tabular-nums">{{ ! $item->is_available ? '—' : ($idr === null ? '— ('.__('purchasing.copy.exchange_rate_unavailable').')' : 'Rp '.$regionalFormatter->groupedDecimal($idr)) }}</td>
                                    <td class="tw:whitespace-nowrap">
                                        @if($quotation->purchaseRequisition)
                                            <a href="{{ \App\Support\PurchasingNavigation::toRoute('purchasing.requisitions.show', $quotation->purchaseRequisition) }}" class="tw:text-primary tw:no-underline tw:font-medium" title="{{ __('purchasing.copy.open_pr_detail') }}">
                                                {{ $quotation->purchaseRequisition->pr_number ?? '-' }}
                                            </a>
                                        @else
                                            <span class="tw:text-on-surface-variant">-</span>
                                        @endif
                                    </td>
                                    <td class="ui-col-data-name">
                                        @if($poRemark !== '')
                                            <span class="tw:inline-block tw:truncate tw:max-w-[220px]" title="{{ $poRemark }}">
                                                {{ \Illuminate\Support\Str::limit($poRemark, 80) }}
                                            </span>
                                        @else
                                            -
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    @endforeach
                    <tfoot class="tw:bg-surface-container-low tw:font-bold tw:border-t tw:border-outline-variant">
                        <tr>
                            <td colspan="7" class="tw:text-end tw:text-on-surface">{{ __('purchasing.copy.grand_total') }}</td>
                            <td class="tw:text-end tw:text-on-surface ui-tabular-nums">{{ $regionalFormatter->groupedDecimal($grandTotalAmount) }} {{ $po->currency }}</td>
                            <td class="tw:text-end tw:text-primary ui-tabular-nums tw:text-ui-base">{{ $grandIdrUnavailable ? '— ('.__('purchasing.copy.exchange_rate_unavailable').')' : 'Rp '.$regionalFormatter->groupedDecimal($grandTotalIdr) }}</td>
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
                    class="tw:scroll-mt-24"
                >
                    <x-slot:actions>
                        @if(isset($poProgressSummary))
                            <span class="ui-status-chip ui-status-chip--info">
                                {{ $poProgressSummary['text'] }}
                            </span>
                        @endif
                    </x-slot:actions>

                    <table class="ui-table ui-table--hover tw:align-middle tw:mb-0 tw:text-ui-xs tw:w-full">
                            <thead class="tw:text-center">
                                <tr>
                                    <th scope="col" style="width: 35px;">{{ __('purchasing.copy.no') }}</th>
                                    <th scope="col" class="tw:text-start">{{ __('purchasing.copy.material') }}</th>
                                    <th scope="col">{{ __('purchasing.copy.ordered') }}</th>
                                    <th scope="col">{{ __('purchasing.copy.accepted') }}</th>
                                    <th scope="col">{{ __('purchasing.copy.in_transit') }}</th>
                                    <th scope="col">{{ __('purchasing.copy.waiting_qc') }}</th>
                                    <th scope="col">{{ __('purchasing.copy.ng_under_claim') }}</th>
                                    <th scope="col">{{ __('purchasing.copy.supplier_controlled') }}</th>
                                    <th scope="col">{{ __('purchasing.copy.supplier_progress') }}</th>
                                    <th scope="col">{{ __('purchasing.copy.original_ready_date') }}</th>
                                    <th scope="col">{{ __('purchasing.copy.current_estimated_ready') }}</th>
                                    <th scope="col">{{ __('purchasing.copy.last_supplier_update') }}</th>
                                    <th scope="col" class="tw:text-end ui-sticky-col-right">{{ __('purchasing.copy.history') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($itemProjections as $idx => $p)
                                    <tr>
                                        <td class="tw:text-center tw:text-on-surface-variant ui-tabular-nums">{{ $idx + 1 }}</td>
                                        <td class="tw:text-start ui-col-data-name">
                                            <div class="tw:font-bold tw:text-on-surface">{{ $p['material_name'] }}</div>
                                            @if($p['hs_code'])
                                                <div class="tw:text-on-surface-variant tw:text-ui-xs">{{ __('common.fields.hs_code') }}: {{ $p['hs_code'] }}</div>
                                            @endif
                                        </td>
                                        <td class="tw:text-center ui-tabular-nums tw:font-semibold">{{ $p['ordered_qty'] }} pcs</td>
                                        <td class="tw:text-center ui-tabular-nums tw:text-success tw:font-semibold">{{ $p['accepted_qty'] }} pcs</td>
                                        <td class="tw:text-center ui-tabular-nums tw:text-primary tw:font-semibold">{{ $p['in_transit_qty'] }} pcs</td>
                                        <td class="tw:text-center ui-tabular-nums tw:font-semibold {{ $p['arrived_pending_qc_qty'] > 0 ? 'tw:text-warning' : 'tw:text-on-surface-variant' }}">
                                            {{ $p['arrived_pending_qc_qty'] }} pcs
                                        </td>
                                        <td class="tw:text-center ui-tabular-nums tw:font-semibold {{ $p['unresolved_ng_qty'] > 0 ? 'tw:text-error' : 'tw:text-on-surface-variant' }}">
                                            {{ $p['unresolved_ng_qty'] }} pcs
                                        </td>
                                        <td class="tw:text-center ui-tabular-nums tw:font-bold">
                                            <span class="ui-status-chip {{ $p['supplier_controlled_qty'] > 0 ? 'ui-status-chip--info' : 'ui-status-chip--neutral' }}">
                                                {{ $p['supplier_controlled_qty'] }} pcs
                                            </span>
                                        </td>
                                        <td class="tw:text-center">
                                            <span class="ui-status-chip ui-status-chip--{{ $p['progress_tone'] }}">
                                                {{ $p['progress_label'] }}
                                            </span>
                                        </td>
                                        <td class="tw:text-center ui-tabular-nums tw:text-on-surface-variant" title="{{ __('purchasing.copy.supplier_estimate_delivery') }}">
                                            {{ $p['original_supplier_ready_date'] ? $regionalFormatter->date(\Carbon\Carbon::parse($p['original_supplier_ready_date'])) : '-' }}
                                        </td>
                                        <td class="tw:text-center ui-tabular-nums tw:font-semibold {{ $p['current_estimated_ready_date'] ? 'tw:text-primary' : 'tw:text-on-surface-variant' }}">
                                            {{ $p['current_estimated_ready_date'] ? $regionalFormatter->date($p['current_estimated_ready_date']) : '-' }}
                                        </td>
                                        <td class="tw:text-center tw:text-on-surface-variant" style="font-size: 0.75rem;">
                                            @if($p['last_progress_update_at'])
                                                <div>{{ $regionalFormatter->timestamp($p['last_progress_update_at'], 'datetime') }}</div>
                                                <div class="tw:text-on-surface-variant">{{ $p['last_updated_by'] ?? __('purchasing.copy.supplier') }}</div>
                                            @else
                                                <span class="tw:text-on-surface-variant">{{ __('purchasing.copy.no_updates') }}</span>
                                            @endif
                                        </td>
                                        <td class="tw:text-end ui-sticky-col-right">
                                            <x-ui.button type="button"
                                                    variant="outline"
                                                    size="sm"
                                                    class="btn-view-purchasing-history"
                                                    data-award-id="{{ $p['award_id'] }}"
                                                    data-material-name="{{ $p['material_name'] }}"
                                                    data-history-url="{{ route('purchasing.purchase-orders.item-progress.history', ['po_id' => $po, 'award_id' => $p['award']]) }}">
                                                {{ __('purchasing.copy.history') }}
                                            </x-ui.button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                </x-ui.data-table>
            @endif

            {{-- QC Inspection Results --}}
            @php
                $latestInspection = $po->qcInspections->sortByDesc('inspected_at')->first();
                // PUR-08: only an NG inspection without any claim (trashed included) can open one.
                $latestNgInspection = $po->qcInspections->where('status', 'ng')->filter(fn ($inspection) => $inspection->materialClaims->isEmpty())->sortByDesc('inspected_at')->first();
                $activeClaim = $po->materialClaims->whereIn('status', ['pending', 'responded', 'escalated'])->sortByDesc('created_at')->first();
            @endphp
            @if($latestInspection)
                <x-ui.card
                    :title="__('purchasing.copy.qc_inspection_report')"
                    :description="__('purchasing.copy.incoming_quality_verification_by_adasi_qc_team')"
                    id="sec-inspection"
                    class="tw:scroll-mt-24"
                >
                    <x-slot:actions>
                        <span class="ui-status-chip {{ $latestInspection->status === 'ng' ? 'ui-status-chip--error' : 'ui-status-chip--success' }}">
                            {{ __('common.final_copy.status') }} {{ \App\Support\StatusHelper::qcLabel($latestInspection->status) }}
                        </span>
                    </x-slot:actions>

                    <div class="tw:grid tw:gap-3 tw:md:grid-cols-3 tw:mb-3">
                        <div>
                            <div class="tw:text-on-surface-variant tw:text-ui-xs tw:font-semibold tw:uppercase">{{ __('purchasing.copy.inspection_date') }}</div>
                            <div class="tw:font-semibold tw:text-on-surface tw:text-ui-sm tw:mt-0.5">{{ $latestInspection->inspected_at ? $regionalFormatter->timestamp($latestInspection->inspected_at, 'datetime_comma') : '-' }}</div>
                        </div>
                        <div>
                            <div class="tw:text-on-surface-variant tw:text-ui-xs tw:font-semibold tw:uppercase">{{ __('purchasing.copy.inspected_by') }}</div>
                            <div class="tw:font-semibold tw:text-on-surface tw:text-ui-sm tw:mt-0.5">{{ $latestInspection->inspector->name ?? '-' }}</div>
                        </div>
                        <div>
                            <div class="tw:text-on-surface-variant tw:text-ui-xs tw:font-semibold tw:uppercase">{{ __('purchasing.copy.defective_ng_items') }}</div>
                            <div class="tw:font-bold {{ $latestInspection->items->where('status', 'ng')->count() > 0 ? 'tw:text-error' : 'tw:text-success' }} tw:text-ui-sm tw:mt-0.5">
                                {{ trans_choice('purchasing.copy.item_count', $latestInspection->items->where('status', 'ng')->count(), ['count' => $latestInspection->items->where('status', 'ng')->count()]) }}
                            </div>
                        </div>
                    </div>

                    @if($latestInspection->items->where('status', 'ng')->count() > 0)
                        <div class="tw:mb-3">
                            <div class="tw:font-bold tw:text-error tw:text-ui-xs tw:uppercase tw:mb-1.5">{{ __('purchasing.copy.problematic_materials_ng') }}</div>
                            <ul class="tw:list-none tw:m-0 tw:p-0 tw:divide-y tw:divide-outline-variant tw:border tw:border-outline-variant tw:rounded-ui-sm tw:overflow-hidden">
                                @foreach($latestInspection->items->where('status', 'ng') as $item)
                                    <li class="tw:py-2 tw:px-3 tw:bg-surface tw:text-ui-xs">
                                        <span class="tw:font-bold tw:text-on-surface tw:block">{{ $item->prItem->material_name }}</span>
                                        @if($item->notes)
                                            <span class="tw:text-on-surface-variant tw:italic">{{ __('common.final_copy.qc_remarks') }} {{ $item->notes }}</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if($latestInspection->attachments->count() > 0)
                        <div>
                            <div class="tw:font-bold tw:text-on-surface-variant tw:text-ui-xs tw:uppercase tw:mb-1.5">{{ __('purchasing.copy.qc_photo_evidence') }}</div>
                            <div class="tw:grid tw:grid-cols-3 tw:md:grid-cols-4 tw:gap-2">
                                @foreach($latestInspection->attachments as $att)
                                    <div>
                                        <a href="{{ route('attachments.show', $att) }}" target="_blank" class="tw:block tw:border tw:border-outline-variant tw:rounded-ui-sm tw:overflow-hidden tw:bg-surface-low tw:h-24">
                                            <img src="{{ route('attachments.show', $att) }}" alt="{{ $att->file_name }}" class="tw:w-full tw:h-full tw:object-cover">
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
                class="tw:scroll-mt-24"
            >
                <x-slot:actions>
                    @php
                        $customsSummary = $customsSummary ?? $po->customsDocumentationSummary();
                        $customsProgress = $customsProgress ?? $po->customsDocumentationProgress();
                        $usesShipmentWorkflow = (bool) ($customsProgress['uses_shipment_workflow'] ?? false);
                        $poDocsReadOnly = \App\Support\Procurement\ProcurementFeatures::poDocumentStatusReadOnly();
                        $docProgressTone = str_contains($docProgress['class'], 'success') ? 'success' : (str_contains($docProgress['class'], 'danger') ? 'error' : 'warning');
                    @endphp
                    <div class="tw:flex tw:items-center tw:gap-2">
                        @if($usesShipmentWorkflow && ! $poDocsReadOnly)
                            <x-ui.button type="button" variant="outline" size="sm" id="btnOpenBatchPoDocDialog">
                                <x-ui.icon name="layers" size="sm" class="tw:me-1" />
                                <span>{{ __('purchasing.copy.batch_update_po_docs') }}</span>
                            </x-ui.button>
                        @endif
                        <span class="ui-status-chip ui-status-chip--{{ $docProgressTone }}" id="docProgressBadge" data-ui-tooltip="{{ $docProgress['description'] }}">
                            {{ $docProgress['label'] }}
                        </span>
                    </div>
                </x-slot:actions>

                @if($poDocsReadOnly)
                    <x-ui.alert tone="info" class="tw:mb-3" id="poDocsReadOnlyBanner">
                        {{ __('documents.sync.po_read_only_use_shipment') }}
                    </x-ui.alert>
                @endif

                {{-- Progress Bar --}}
                <progress
                    class="ui-progress ui-progress--success tw:mb-3 tw:h-1.5 tw:w-full"
                    id="docProgressBar"
                    value="{{ $totalDocs > 0 ? round($completedDocs / $totalDocs * 100) : 0 }}"
                    max="100"
                    aria-label="{{ __('purchasing.copy.import_document_completion') }}"
                ></progress>

                @if($allDocsComplete)
                    <x-ui.alert id="allDocsAlert" tone="success" :title="__('purchasing.copy.import_documents_complete')" class="tw:mb-3">
                        {{ __('purchasing.copy.all_mandatory_import_customs_documents_have_been_fully_verified') }}
                    </x-ui.alert>
                @endif
                <template id="allDocsAlertTemplate">
                    <x-ui.alert id="allDocsAlert" tone="success" :title="__('purchasing.copy.import_documents_complete')" class="tw:mb-3">
                        {{ __('purchasing.copy.all_mandatory_import_customs_documents_have_been_fully_verified') }}
                    </x-ui.alert>
                </template>

                <div class="tw:grid tw:gap-3 tw:sm:grid-cols-2 tw:2xl:grid-cols-4">
                    @php
                        $docConfig = [
                            'invoice' => ['label' => __('purchasing.copy.invoice_document'), 'icon' => 'receipt', 'statuses' => ['pending' => __('purchasing.copy.not_available'), 'received' => __('purchasing.copy.accepted'), 'verified' => __('purchasing.copy.verified')]],
                            'bl' => ['label' => __('purchasing.copy.bill_of_lading'), 'icon' => 'truck', 'statuses' => ['pending' => __('purchasing.copy.not_available'), 'received' => __('purchasing.copy.accepted'), 'verified' => __('purchasing.copy.verified')]],
                            'packing_list' => ['label' => __('purchasing.copy.packing_list'), 'icon' => 'list-checks', 'statuses' => ['pending' => __('purchasing.copy.not_available'), 'received' => __('purchasing.copy.accepted'), 'verified' => __('purchasing.copy.verified')]],
                            'form_e' => ['label' => 'Form-E', 'icon' => 'file-badge', 'statuses' => ['pending' => __('purchasing.copy.not_available'), 'received' => __('purchasing.copy.accepted'), 'verified' => __('purchasing.copy.verified')]],
                        ];
                    @endphp

                    @php
                        $customsSummary = $customsSummary ?? $po->customsDocumentationSummary();
                        $customsProgress = $customsProgress ?? $po->customsDocumentationProgress();
                        $usesShipmentWorkflow = (bool) ($customsProgress['uses_shipment_workflow'] ?? false);
                    @endphp

                    {{-- Always the four import documents: a PO may lack a po_documents row (historical data),
                         while its shipments already carry the document. --}}
                    @foreach($customsSummary as $summaryDocType => $summaryItem)
                        @php
                            $doc = $summaryItem['po_doc'] ?? new \App\Models\PoDocument(['doc_type' => $summaryDocType, 'status' => \App\Models\PoDocument::STATUSES[0]]);
                            $docKey = $doc->exists ? $doc->id : 'type-'.$summaryDocType;
                            $config = $docConfig[$doc->doc_type] ?? ['label' => $doc->doc_type, 'icon' => 'file', 'statuses' => []];
                            $progressItem = $customsProgress['documents'][$doc->doc_type] ?? null;
                            $effectiveStatus = $summaryItem['status'] ?? $doc->status;
                            $statusLabel = $config['statuses'][$effectiveStatus] ?? \App\Support\StatusHelper::shipmentDocLabel($effectiveStatus);
                            $chipClasses = match($effectiveStatus) {
                                'pending' => 'ui-status-chip--neutral',
                                'received', 'issued', 'processing' => 'ui-status-chip--info',
                                'verified', 'done' => 'ui-status-chip--success',
                                default => 'ui-status-chip--neutral'
                            };
                            $iconClasses = match($effectiveStatus) {
                                'pending' => 'tw:bg-surface-container-high tw:text-on-surface-variant',
                                'verified', 'done' => 'tw:bg-success-container/40 tw:text-success',
                                default => 'tw:bg-primary/10 tw:text-primary'
                            };
                            $shipmentDocs = collect($summaryItem['shipment_documents'] ?? []);
                            $uploadedDocs = $shipmentDocs->filter(fn($sd) => !empty($sd['attachment']));
                            $activeShipmentCount = (int) ($progressItem['active_shipment_count'] ?? 0);
                            $receivedShipmentCount = (int) ($progressItem['received_count'] ?? 0);
                            $verifiedShipmentCount = (int) ($progressItem['verified_count'] ?? 0);
                            $evidenceGapCount = (int) ($progressItem['evidence_gap_count'] ?? 0);
                            $isWaitingForShipment = $usesShipmentWorkflow && $activeShipmentCount === 0;
                        @endphp
                        <div class="po-doc-card tw:h-full tw:rounded-xl tw:border tw:border-outline-variant/80 tw:bg-surface tw:p-3.5 tw:shadow-2xs tw:flex tw:flex-col tw:justify-between tw:transition-colors tw:hover:border-primary/40 tw:hover:shadow-xs tw:min-w-0" id="doc-card-{{ $docKey }}">
                            {{-- Zone 1: Document Header (Centered & Uniform) --}}
                            <div class="tw:flex tw:flex-col tw:items-center tw:text-center">
                                <div class="tw:w-10 tw:h-10 tw:rounded-full tw:flex tw:items-center tw:justify-center tw:mb-2 {{ $iconClasses }}">
                                    <x-ui.icon :name="$config['icon']" size="md" />
                                </div>
                                <h6 class="tw:font-bold tw:text-on-surface tw:text-ui-sm tw:mb-1.5">{{ $config['label'] }}</h6>
                                <span class="ui-status-chip {{ $chipClasses }} tw:mb-1 doc-status-badge" id="doc-badge-{{ $docKey }}" data-status="{{ $effectiveStatus }}">
                                    {{ $statusLabel }}
                                </span>
                                @if($usesShipmentWorkflow)
                                    @if($activeShipmentCount > 0)
                                        <div class="tw:flex tw:flex-wrap tw:items-center tw:justify-center tw:gap-1 tw:mt-1 ui-tabular-nums" data-doc-shipment-counters="{{ $doc->doc_type }}">
                                            <span class="ui-status-chip {{ $receivedShipmentCount === $activeShipmentCount ? 'ui-status-chip--info' : 'ui-status-chip--neutral' }}">
                                                {{ __('documents.sync.received_counter', ['received' => $receivedShipmentCount, 'total' => $activeShipmentCount]) }}
                                            </span>
                                            <span class="ui-status-chip {{ $verifiedShipmentCount === $activeShipmentCount ? 'ui-status-chip--success' : 'ui-status-chip--neutral' }}">
                                                {{ __('documents.sync.verified_counter', ['verified' => $verifiedShipmentCount, 'total' => $activeShipmentCount]) }}
                                            </span>
                                        </div>
                                        @if($evidenceGapCount > 0)
                                            <p class="tw:m-0 tw:mt-1 tw:text-ui-xs tw:text-warning" data-doc-evidence-gap="{{ $doc->doc_type }}">
                                                {{ __('documents.sync.evidence_gap_warning', ['count' => $evidenceGapCount]) }}
                                            </p>
                                        @endif
                                    @else
                                        <span class="tw:text-on-surface-variant tw:text-ui-xs tw:mt-1" data-doc-waiting-shipment="{{ $doc->doc_type }}">
                                            {{ __('documents.sync.waiting_for_shipment') }}
                                        </span>
                                    @endif
                                @else
                                    <span class="tw:text-on-surface-variant tw:text-ui-xs tw:mt-0.5">
                                        {{ __('documents.sync.legacy_manual_status') }}
                                    </span>
                                @endif
                                <div class="tw:text-on-surface-variant tw:text-ui-xs tw:min-h-5 tw:flex tw:items-center tw:justify-center" id="doc-date-{{ $docKey }}">
                                    @if($effectiveStatus !== 'pending' && $doc->updated_at)
                                        <span>{{ $regionalFormatter->timestamp($doc->updated_at, 'datetime_comma') }}</span>
                                    @else
                                        <span class="tw:text-on-surface-variant">&mdash;</span>
                                    @endif
                                </div>
                            </div>

                            {{-- Zone 2: Shipment Files (Equal height container with clean stacked action) --}}
                            <div class="tw:my-2.5 tw:pt-2.5 tw:border-t tw:border-outline-variant/60 tw:flex-1 tw:flex tw:flex-col tw:justify-center" style="min-height: 72px;">
                                @if($uploadedDocs->isNotEmpty())
                                    <div class="tw:space-y-2 tw:w-full">
                                        @foreach($uploadedDocs as $sDoc)
                                            <div class="tw:rounded-lg tw:bg-surface-container-low tw:border tw:border-outline-variant/70 tw:p-2 tw:transition-colors">
                                                <div class="tw:flex tw:items-center tw:justify-center tw:gap-1.5 tw:mb-1.5 tw:min-w-0">
                                                    <x-ui.icon name="truck" size="sm" class="tw:text-primary tw:shrink-0" />
                                                    <a href="{{ route('purchasing.shipments.show', $sDoc['shipment']) }}"
                                                       class="tw:text-ui-xs tw:font-semibold tw:text-primary tw:no-underline tw:hover:underline tw:truncate"
                                                       title="{{ __('common.final_review.open_shipment', ['number' => $sDoc['shipment_number']]) }}">
                                                        {{ $sDoc['shipment_number'] }}
                                                    </a>
                                                </div>
                                                <x-ui.button :href="route('attachments.show', $sDoc['attachment'])"
                                                   target="_blank"
                                                   variant="outline-primary"
                                                   size="sm"
                                                   class="tw:w-full"
                                                   :title="__('common.actions.view').' '.$sDoc['attachment']->file_name">
                                                    <x-ui.icon name="file-text" size="sm" />
                                                    <span class="tw:truncate">{{ __('purchasing.copy.view_file') }}</span>
                                                </x-ui.button>
                                            </div>
                                        @endforeach
                                    </div>
                                @else
                                    <div class="tw:rounded-lg tw:bg-surface-container-low/50 tw:border tw:border-dashed tw:border-outline-variant/60 tw:p-2 tw:text-center tw:flex tw:flex-col tw:items-center tw:justify-center" style="min-height: 72px;">
                                        <x-ui.icon name="file" size="sm" class="tw:text-on-surface-variant tw:mb-1" />
                                        <span class="tw:text-on-surface-variant tw:text-ui-xs tw:font-medium">{{ __('purchasing.copy.no_file_uploaded') }}</span>
                                    </div>
                                @endif
                            </div>

                            {{-- Zone 3: Footer Action --}}
                            <div class="tw:pt-1 tw:mt-auto tw:w-full tw:min-w-0">
                                @if($poDocsReadOnly)
                                    @php
                                        $firstLinkedShipment = $shipmentDocs->first()['shipment'] ?? null;
                                    @endphp
                                    @if($firstLinkedShipment)
                                        <x-ui.button :href="route('purchasing.shipments.show', $firstLinkedShipment)" variant="outline" size="sm" class="tw:w-full">
                                            <x-ui.icon name="external-link" size="sm" class="tw:me-1 tw:shrink-0" />
                                            <span class="tw:truncate">{{ __('documents.sync.open_shipment_to_update') }}</span>
                                        </x-ui.button>
                                    @else
                                        <x-ui.button :href="route('purchasing.shipments.index')" variant="outline" size="sm" class="tw:w-full">
                                            <x-ui.icon name="truck" size="sm" class="tw:me-1 tw:shrink-0" />
                                            <span class="tw:truncate">{{ __('documents.sync.open_shipment_to_update') }}</span>
                                        </x-ui.button>
                                    @endif
                                @elseif($usesShipmentWorkflow)
                                    @if($isWaitingForShipment)
                                        <x-ui.button type="button" variant="outline" size="sm" class="tw:w-full" disabled aria-disabled="true" data-doc-type="{{ $doc->doc_type }}" title="{{ __('documents.sync.waiting_for_shipment') }}">
                                            <x-ui.icon name="square-pen" size="sm" class="tw:me-1 tw:shrink-0" />
                                            <span class="tw:truncate">{{ __('purchasing.copy.update_status') }}</span>
                                        </x-ui.button>
                                    @else
                                        <x-ui.button type="button" variant="outline" size="sm" class="btn-open-shipment-doc-dialog tw:w-full"
                                                data-doc-id="{{ $docKey }}"
                                                data-doc-type="{{ $doc->doc_type }}"
                                                data-doc-label="{{ $config['label'] }}"
                                                data-update-url="{{ route('purchasing.purchase-orders.documents.shipments.update', ['id' => $po, 'doc_type' => $doc->doc_type]) }}"
                                                :data-doc-statuses="json_encode($config['statuses'])"
                                                title="{{ __('purchasing.copy.update_status') }}">
                                            <x-ui.icon name="square-pen" size="sm" class="tw:me-1 tw:shrink-0" />
                                            <span class="tw:truncate">{{ __('purchasing.copy.update_status') }}</span>
                                        </x-ui.button>
                                    @endif
                                @elseif($doc->exists)
                                    {{-- Legacy endpoint updates an existing po_documents row by id. --}}
                                    <x-ui.button type="button" variant="outline" size="sm" class="btn-update-doc tw:w-full"
                                            data-doc-id="{{ $docKey }}"
                                            data-doc-type="{{ $doc->doc_type }}"
                                            data-doc-label="{{ $config['label'] }}"
                                            data-doc-status="{{ $effectiveStatus }}"
                                            data-doc-status-label="{{ $statusLabel }}"
                                            :data-doc-statuses="json_encode($config['statuses'])"
                                            title="{{ __('purchasing.copy.update_status') }}">
                                        <x-ui.icon name="square-pen" size="sm" class="tw:me-1 tw:shrink-0" />
                                        <span class="tw:truncate">{{ __('purchasing.copy.update_status') }}</span>
                                    </x-ui.button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-ui.card>

            @include('procurement._reference_snapshot_panel', ['po' => $po])
        </div>

        {{-- Sidebar Column --}}
        <aside class="tw:grid tw:min-w-0 tw:gap-4" aria-label="{{ __('purchasing.copy.po_operations_and_timeline') }}">
            {{-- Supplier Chat Channel --}}
            <x-ui.card :title="__('purchasing.copy.supplier_negotiation')">
                <form action="{{ route('purchasing.conversations.start.po', $po) }}" method="POST" data-chat-start-form data-managed-submit>
                    @csrf
                    <input type="hidden" name="return_url" value="{{ \App\Support\PurchasingNavigation::currentUrlForReturn() }}">
                    <x-ui.button type="submit" variant="outline" size="sm" class="tw:w-full tw:justify-between">
                        <span class="tw:inline-flex tw:items-center tw:gap-2">
                            <x-ui.icon name="message-square" size="sm" />
                            <span class="tw:font-semibold">{{ __('purchasing.copy.chat_with_supplier') }}</span>
                        </span>
                        <x-ui.icon name="chevron-right" size="sm" />
                    </x-ui.button>
                </form>
            </x-ui.card>

            @include('shipments._po_mtc_list', ['po' => $po])

            {{-- Material Claim Alert (if status claim_needed) --}}
            @if($po->status === 'claim_needed')
                <x-ui.card :title="__('purchasing.copy.defect_claim_follow_up')" id="sec-claim" class="tw:scroll-mt-24 tw:border-error/40">
                    <x-slot:actions><span class="ui-status-chip ui-status-chip--error">NG</span></x-slot:actions>
                    <p class="tw:text-error tw:text-ui-xs tw:font-medium tw:mb-3">
                        {{ __('purchasing.copy.quality_inspection_failed_status_ng_immediate_claim_action_is_required') }}
                    </p>

                    @if($activeClaim)
                        <x-ui.button :href="\App\Support\PurchasingNavigation::toRoute('purchasing.claims.show', $activeClaim)" variant="danger" size="sm" class="tw:w-full tw:justify-between">
                            <span><x-ui.icon name="octagon-alert" size="sm" class="tw:me-1" /> {{ __('purchasing.copy.view_active_claim') }}</span>
                            <x-ui.icon name="chevron-right" size="sm" />
                        </x-ui.button>
                    @elseif($latestNgInspection)
                        <x-ui.button :href="\App\Support\PurchasingNavigation::toRoute('purchasing.claims.create', $latestNgInspection)" variant="danger" size="sm" class="tw:w-full tw:justify-between">
                            <span><x-ui.icon name="plus-circle" size="sm" class="tw:me-1" /> {{ __('purchasing.copy.submit_material_claim') }}</span>
                            <x-ui.icon name="chevron-right" size="sm" />
                        </x-ui.button>
                    @else
                        <x-ui.button :href="\App\Support\PurchasingNavigation::toRoute('purchasing.claims.index')" variant="outline" size="sm" class="tw:w-full tw:justify-between">
                            <span><x-ui.icon name="folder-open" size="sm" class="tw:me-1" /> {{ __('purchasing.copy.open_claim_list') }}</span>
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
                        <x-ui.button type="button" size="sm" class="tw:w-full" id="btnConfirmArrival">
                            <x-ui.icon name="package-check" size="sm" />
                            <span>{{ __('purchasing.copy.confirm_material_arrival') }}</span>
                        </x-ui.button>
                    </form>
                </x-ui.card>
            @endif

            {{-- Timeline History --}}
            <x-ui.card :title="__('purchasing.copy.order_timeline')" id="sec-timeline" class="tw:scroll-mt-24">
                <ol class="pr-timeline">
                    <li class="pr-timeline-item is-complete">
                        <span class="pr-timeline-marker" aria-hidden="true"></span>
                        <div class="tw:text-ui-sm tw:font-bold tw:text-primary">{{ __('purchasing.copy.po_created') }}</div>
                        <time class="ui-tabular-nums tw:text-on-surface-variant tw:text-ui-xs" datetime="{{ $po->created_at->toIso8601String() }}">{{ $regionalFormatter->timestamp($po->created_at, 'datetime_comma') }}</time>
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
                                <div class="tw:text-ui-sm tw:font-bold tw:text-on-surface">{{ $docLabels[$doc->doc_type] ?? __('purchasing.copy.document') }}: {{ \App\Support\StatusHelper::shipmentDocLabel($doc->status) }}</div>
                                <time class="ui-tabular-nums tw:text-on-surface-variant tw:text-ui-xs" datetime="{{ $doc->updated_at->toIso8601String() }}">{{ $regionalFormatter->timestamp($doc->updated_at, 'datetime_comma') }}</time>
                            </li>
                        @endif
                    @endforeach

                    <li class="pr-timeline-item {{ $po->is_overdue ? 'is-current' : '' }}">
                        <span class="pr-timeline-marker" aria-hidden="true"></span>
                        <div class="tw:text-ui-sm tw:font-bold {{ $po->is_overdue ? 'tw:text-warning' : 'tw:text-on-surface-variant' }}">{{ __('purchasing.copy.estimated_arrival') }}</div>
                        <time class="ui-tabular-nums tw:text-on-surface-variant tw:text-ui-xs">{{ $po->estimated_arrival ? $regionalFormatter->date($po->estimated_arrival) : '-' }}</time>
                    </li>

                    <li class="pr-timeline-item {{ $po->actual_arrival ? 'is-complete' : '' }}">
                        <span class="pr-timeline-marker" aria-hidden="true"></span>
                        <div class="tw:text-ui-sm tw:font-bold {{ $po->actual_arrival ? 'tw:text-success' : 'tw:text-on-surface-variant' }}">{{ __('purchasing.copy.material_arrival') }}</div>
                        @if($po->actual_arrival)
                            <time class="ui-tabular-nums tw:text-on-surface-variant tw:text-ui-xs">{{ $regionalFormatter->date($po->actual_arrival) }}</time>
                        @endif
                    </li>
                </ol>
            </x-ui.card>
        </aside>
    </div>
</div>

{{-- Update Document Modal --}}
<dialog class="ui-native-dialog" id="updateDocModal" aria-labelledby="modalDocTitle" closedby="any">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title tw:font-bold" id="modalDocTitle">{{ __('purchasing.copy.update_document_status') }}</h6>
                <button type="button" class="btn-close" data-ui-modal-close aria-label="{{ __('purchasing.copy.close') }}"></button>
            </div>
            <div class="modal-body tw:p-3.5">
                <input type="hidden" id="modalDocId">
                <div>
                    <label class="ui-label tw:text-ui-xs tw:font-semibold tw:text-on-surface" for="modalDocStatus">{{ __('purchasing.copy.select_new_status') }}</label>
                    <select class="ui-select ui-select--sm" id="modalDocStatus"></select>
                </div>
            </div>
            <div class="modal-footer tw:bg-surface-low tw:border-t tw:border-outline-variant">
                <x-ui.button type="button" variant="ghost" size="sm" data-ui-modal-close>{{ __('purchasing.copy.cancel') }}</x-ui.button>
                <x-ui.button type="button" size="sm" id="btnSaveDocStatus">
                    <span class="ui-spinner tw:hidden tw:me-1" id="docSpinner" aria-hidden="true"></span>
                    {{ __('purchasing.copy.save_changes') }}
                </x-ui.button>
            </div>
        </div>
    </div>
</dialog>

{{-- Shared Progress History Modal --}}
<dialog class="ui-native-dialog" id="progressHistoryModal" aria-labelledby="progressHistoryModalLabel" closedby="any">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title tw:text-ui-base tw:font-bold" id="progressHistoryModalLabel">
                    {{ __('purchasing.copy.material_progress_history') }}
                </h5>
                <button type="button" class="btn-close" data-ui-modal-close aria-label="{{ __('purchasing.copy.close') }}"></button>
            </div>
            <div class="modal-body" id="progressHistoryModalBody">
                <x-ui.inline-loader :label="__('purchasing.copy.loading_history')" class="tw:py-6" />
            </div>
            <div class="modal-footer">
                <x-ui.button type="button" variant="ghost" size="sm" data-ui-modal-close>{{ __('purchasing.copy.close') }}</x-ui.button>
            </div>
        </div>
    </div>
</dialog>

{{-- Gate 2 (Decision B): Shipment-Scoped Customs Document Status Dialog --}}
<x-ui.dialog
    name="update-shipment-docs"
    maxWidth="2xl"
    :title="__('documents.sync.dialog_title')"
    :description="__('documents.sync.dialog_subtitle', ['document' => ''])"
>
    <div id="shipmentDocDialogRoot" class="tw:space-y-4">
        <div id="shipmentDocDialogFeedback" class="tw:hidden" role="status" aria-live="polite"></div>

        <div class="tw:flex tw:items-center tw:justify-between tw:gap-2 tw:rounded-lg tw:border tw:border-outline-variant/80 tw:bg-surface-container-low tw:px-3 tw:py-2">
            <span class="tw:text-ui-xs tw:font-semibold tw:text-on-surface ui-tabular-nums" id="shipmentDocSelectionSummary">
                {{ __('documents.sync.selection_summary', ['count' => 0]) }}
            </span>
            <span class="tw:text-ui-xs tw:text-on-surface-variant ui-tabular-nums" id="shipmentDocCountersSummary"></span>
        </div>

        <div id="shipmentDocRowsContainer" class="tw:space-y-2.5" role="group" aria-labelledby="update-shipment-docs-title"></div>

        <div class="tw:grid tw:gap-3 tw:pt-2 tw:border-t tw:border-outline-variant/80">
            <div>
                <label class="ui-label tw:text-ui-xs tw:font-semibold tw:text-on-surface" for="shipmentDocTargetStatus">
                    {{ __('purchasing.copy.select_new_status') }}
                </label>
                <select class="ui-select ui-select--sm" id="shipmentDocTargetStatus">
                    <option value="" selected disabled>{{ __('documents.sync.select_target_placeholder') }}</option>
                </select>
            </div>

            <div>
                <label class="ui-label tw:text-ui-xs tw:font-semibold tw:text-on-surface" for="shipmentDocReason">
                    {{ __('documents.sync.reason_label') }}
                </label>
                <textarea
                    class="ui-textarea tw:text-ui-xs tw:px-2 tw:py-1"
                    id="shipmentDocReason"
                    rows="2"
                    maxlength="1000"
                    placeholder="{{ __('documents.sync.reason_placeholder') }}"
                ></textarea>
                <p id="shipmentDocReasonHint" class="tw:m-0 tw:mt-1 tw:text-ui-xs tw:text-on-surface-variant tw:hidden">
                    {{ __('documents.sync.reason_required_hint') }}
                </p>
            </div>
        </div>
    </div>

    <x-slot:actions>
        <x-ui.button type="button" variant="ghost" size="sm" id="btnCancelShipmentDocDialog" onclick="window.dispatchEvent(new CustomEvent('close-ui-dialog', { detail: 'update-shipment-docs' }))">
            {{ __('purchasing.copy.cancel') }}
        </x-ui.button>
        <x-ui.button type="button" size="sm" id="btnSubmitShipmentDocStatus" disabled>
            <span class="ui-spinner tw:hidden tw:me-1" id="shipmentDocSpinner" aria-hidden="true"></span>
            <span>{{ __('documents.sync.submit_button') }}</span>
        </x-ui.button>
    </x-slot:actions>
</x-ui.dialog>

{{-- Batch Multi-Doc PO Tracking Dialog --}}
<x-ui.dialog
    name="batch-update-po-docs"
    maxWidth="4xl"
    :title="__('purchasing.copy.batch_update_po_docs')"
    :description="__('purchasing.copy.status_tracking_for_4_mandatory_import_customs_documents_invoice_bill_of_lading_packing_list_and_for')"
>
    <div id="batchPoDocDialogRoot" class="tw:space-y-4">
        {{-- Batch Feedback Alert --}}
        <div id="batchPoDocDialogFeedback" class="tw:hidden" role="status" aria-live="polite"></div>

        {{-- Global Apply / Quick Batch Controls --}}
        <div class="tw:rounded-lg tw:border tw:border-outline-variant/80 tw:bg-surface-container-low tw:p-3 tw:space-y-2.5">
            <div class="tw:flex tw:items-center tw:justify-between tw:gap-2">
                <span class="tw:text-ui-xs tw:font-semibold tw:text-on-surface">
                    {{ __('purchasing.copy.apply_to_all_tabs') }}
                </span>
                <span class="tw:text-ui-xs tw:text-on-surface-variant ui-tabular-nums" id="batchPoGlobalCounterSummary"></span>
            </div>
            <div class="tw:grid tw:grid-cols-1 tw:sm:grid-cols-12 tw:gap-2 tw:items-end">
                <div class="tw:sm:col-span-4">
                    <label class="ui-label tw:text-ui-xs tw:font-semibold tw:text-on-surface tw:mb-1" for="batchPoGlobalTargetStatus">
                        {{ __('purchasing.copy.select_new_status') }}
                    </label>
                    <select class="ui-select ui-select--sm" id="batchPoGlobalTargetStatus">
                        <option value="" selected disabled>{{ __('documents.sync.select_target_placeholder') }}</option>
                        <option value="received">{{ \App\Support\StatusHelper::shipmentDocLabel('received') }}</option>
                        <option value="verified">{{ \App\Support\StatusHelper::shipmentDocLabel('verified') }}</option>
                    </select>
                </div>
                <div class="tw:sm:col-span-5">
                    <label class="ui-label tw:text-ui-xs tw:font-semibold tw:text-on-surface tw:mb-1" for="batchPoGlobalReason">
                        {{ __('documents.sync.reason_label') }}
                    </label>
                    <input type="text" class="ui-input ui-input--sm" id="batchPoGlobalReason" maxlength="1000" placeholder="{{ __('documents.sync.reason_placeholder') }}">
                </div>
                <div class="tw:sm:col-span-3">
                    <x-ui.button type="button" variant="outline" size="sm" class="tw:w-full" id="btnApplyGlobalToAllTabs">
                        <x-ui.icon name="check-check" size="sm" class="tw:me-1" />
                        <span>{{ __('purchasing.copy.apply_to_all_tabs') }}</span>
                    </x-ui.button>
                </div>
            </div>
        </div>

        {{-- Tabs Navigation --}}
        <div class="tw:border-b tw:border-outline-variant/80">
            <ul class="tw:flex tw:flex-wrap tw:list-none tw:m-0 tw:p-0 tw:gap-1" id="batchPoDocNavTabs" role="tablist">
                @php
                    $batchTabDefinitions = [
                        'invoice' => ['label' => __('purchasing.copy.invoice_document'), 'icon' => 'receipt'],
                        'packing_list' => ['label' => __('purchasing.copy.packing_list'), 'icon' => 'list-checks'],
                        'bl' => ['label' => __('purchasing.copy.bill_of_lading'), 'icon' => 'truck'],
                        'form_e' => ['label' => 'Form-E', 'icon' => 'file-badge'],
                    ];
                @endphp
                @foreach($batchTabDefinitions as $docType => $def)
                    <li class="tw:shrink-0" role="presentation">
                        <button class="tw:inline-flex tw:items-center tw:gap-1 tw:px-3 tw:py-1.5 tw:rounded-t-ui-sm tw:border tw:border-b-0 tw:border-outline-variant/80 tw:bg-surface-container-low tw:text-on-surface-variant tw:text-ui-xs tw:font-semibold tw:cursor-pointer tw:transition-colors tw:hover:bg-surface tw:aria-selected:bg-surface tw:aria-selected:text-primary ui-focus-ring {{ $loop->first ? 'active' : '' }}"
                                id="batch-tab-{{ $docType }}"
                                data-ui-tab
                                data-ui-tab-target="#batch-pane-{{ $docType }}"
                                type="button"
                                role="tab"
                                aria-controls="batch-pane-{{ $docType }}"
                                @unless($loop->first) tabindex="-1" @endunless
                                aria-selected="{{ $loop->first ? 'true' : 'false' }}">
                            <x-ui.icon name="{{ $def['icon'] }}" size="sm" class="tw:me-1" />
                            <span>{{ $def['label'] }}</span>
                            <span class="ui-status-chip tw:bg-surface-container-high tw:text-on-surface-variant tw:ms-1" id="batchPoTabBadge_{{ $docType }}">0</span>
                        </button>
                    </li>
                @endforeach
            </ul>
        </div>

        {{-- Tab Panes Content --}}
        <div id="batchPoDocTabContent">
            @foreach($batchTabDefinitions as $docType => $def)
                <div class="{{ $loop->first ? 'active' : '' }}"
                     id="batch-pane-{{ $docType }}"
                     role="tabpanel"
                     aria-labelledby="batch-tab-{{ $docType }}"
                     tabindex="0"
                     @unless($loop->first) hidden @endunless>
                    <div class="tw:space-y-3">
                        <div class="tw:flex tw:items-center tw:justify-between tw:gap-2 tw:rounded-lg tw:border tw:border-outline-variant/80 tw:bg-surface-container-low tw:px-3 tw:py-2">
                            <span class="tw:text-ui-xs tw:font-semibold tw:text-on-surface ui-tabular-nums" id="batchPoDocSelectionSummary_{{ $docType }}">
                                {{ __('documents.sync.selection_summary', ['count' => 0]) }}
                            </span>
                            <div class="tw:flex tw:items-center tw:gap-1.5">
                                <x-ui.button type="button" variant="ghost" size="xs" class="btn-select-all-tab" data-doc-type="{{ $docType }}">
                                    {{ __('purchasing.copy.select_all') }}
                                </x-ui.button>
                                <x-ui.button type="button" variant="ghost" size="xs" class="btn-clear-tab" data-doc-type="{{ $docType }}">
                                    {{ __('purchasing.copy.clear_all') }}
                                </x-ui.button>
                            </div>
                        </div>

                        <div id="batchPoDocRowsContainer_{{ $docType }}" class="tw:space-y-2.5 tw:max-h-64 tw:overflow-y-auto tw:p-0.5" role="group" aria-label="{{ $def['label'] }}"></div>

                        <div class="tw:grid tw:grid-cols-1 tw:sm:grid-cols-12 tw:gap-2 tw:pt-2 tw:border-t tw:border-outline-variant/80 tw:items-start">
                            <div class="tw:sm:col-span-4">
                                <label class="ui-label tw:text-ui-xs tw:font-semibold tw:text-on-surface tw:mb-1" for="batchPoTabTarget_{{ $docType }}">
                                    {{ __('purchasing.copy.select_new_status') }}
                                </label>
                                <select class="ui-select ui-select--sm batch-po-tab-target" id="batchPoTabTarget_{{ $docType }}" data-doc-type="{{ $docType }}">
                                    <option value="" selected disabled>{{ __('documents.sync.select_target_placeholder') }}</option>
                                    <option value="received">{{ \App\Support\StatusHelper::shipmentDocLabel('received') }}</option>
                                    <option value="verified">{{ \App\Support\StatusHelper::shipmentDocLabel('verified') }}</option>
                                </select>
                            </div>
                            <div class="tw:sm:col-span-8">
                                <label class="ui-label tw:text-ui-xs tw:font-semibold tw:text-on-surface tw:mb-1" for="batchPoTabReason_{{ $docType }}">
                                    {{ __('documents.sync.reason_label') }}
                                </label>
                                <input type="text" class="ui-input ui-input--sm batch-po-tab-reason" id="batchPoTabReason_{{ $docType }}" data-doc-type="{{ $docType }}" maxlength="1000" placeholder="{{ __('documents.sync.reason_placeholder') }}">
                                <p id="batchPoTabReasonHint_{{ $docType }}" class="tw:m-0 tw:mt-1 tw:text-ui-xs tw:text-on-surface-variant tw:hidden">
                                    {{ __('documents.sync.reason_required_hint') }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    <x-slot:actions>
        <x-ui.button type="button" variant="ghost" size="sm" id="btnCancelBatchPoDocDialog" onclick="window.dispatchEvent(new CustomEvent('close-ui-dialog', { detail: 'batch-update-po-docs' }))">
            {{ __('purchasing.copy.cancel') }}
        </x-ui.button>
        <x-ui.button type="button" size="sm" id="btnSubmitBatchPoDocStatus" disabled>
            <span class="ui-spinner tw:hidden tw:me-1" id="batchPoDocSpinner" aria-hidden="true"></span>
            <span>{{ __('purchasing.copy.save_all_po_doc_statuses') }}</span>
        </x-ui.button>
    </x-slot:actions>
</x-ui.dialog>
@endsection

@push('scripts')
<script>
    (function() {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '{{ csrf_token() }}';

        function isVisibleElement(el) {
            return Boolean(el && (el.offsetWidth || el.offsetHeight || el.getClientRects().length));
        }

        function getOffsetTop(el) {
            return el.getBoundingClientRect().top + (window.scrollY || window.pageYOffset || 0);
        }

        function parseJsonAttr(el, attrName, fallback = {}) {
            const raw = el?.getAttribute(attrName);
            if (!raw) return fallback;
            try {
                const parsed = JSON.parse(raw);
                return parsed !== null && typeof parsed === 'object' ? parsed : fallback;
            } catch {
                return fallback;
            }
        }

        function renderProgressHistoryRows(history) {
        const allowedTones = ['success', 'info', 'warning', 'neutral'];
        const wrapper = document.createElement('div');
        wrapper.className = 'tw:space-y-3';

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
            const row = textElement('div', 'tw:p-3 tw:rounded-sm tw:border tw:border-outline-variant tw:bg-surface-container tw:text-ui-xs', '');
            const header = textElement('div', 'tw:flex tw:justify-between tw:items-center tw:mb-1', '');
            const tone = allowedTones.includes(item.status_tone) ? item.status_tone : 'neutral';
            const status = textElement('span', `ui-status-chip ui-status-chip--${tone} tw:font-bold`, item.status_label);
            const timestamp = textElement('span', 'tw:text-on-surface-variant', item.created_at_display || '-');
            header.append(status, timestamp);

            const details = textElement('div', 'tw:text-on-surface-variant tw:mb-1', '');
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
                const note = textElement('div', 'tw:p-2 tw:rounded-sm tw:bg-surface tw:border tw:border-outline-variant tw:text-on-surface tw:mt-1', '');
                note.appendChild(textElement('em', '', `"${item.note}"`));
                row.appendChild(note);
            }
            wrapper.appendChild(row);
        });

        return wrapper;
    }

        function bootPoShow() {
            // Sticky Section Navigation
            const navLinks = Array.from(document.querySelectorAll('#po-section-nav .po-nav-link'));
            let isProgrammaticScroll = false;
            let manualClickLockId = null;
            let unlockTimer = null;
            let scrollResetTimer = null;
            let highlightTimer = null;

            function getStickyOffset() {
                const navBar = document.querySelector('.po-sticky-nav-bar');
                const navHeight = navBar ? navBar.getBoundingClientRect().height : 46;
                return 56 + (navHeight || 46) + 16;
            }

            function setActiveTab(targetId) {
                navLinks.forEach((link) => link.classList.remove('active'));
                const activeLink = navLinks.find((link) => link.getAttribute('href') === targetId);
                if (activeLink) {
                    activeLink.classList.add('active');
                }
            }

            function updateActiveSection() {
                if (isProgrammaticScroll) return;
                if (manualClickLockId) {
                    setActiveTab(manualClickLockId);
                    return;
                }

                const stickyOffset = getStickyOffset();
                const scrollPos = window.scrollY || window.pageYOffset || document.documentElement.scrollTop || 0;
                const viewportFocusLine = scrollPos + stickyOffset + 40;

                let bestMatchId = null;
                let bestDistance = Infinity;

                navLinks.forEach((link) => {
                    const href = link.getAttribute('href');
                    if (!href || !href.startsWith('#')) return;
                    const target = document.querySelector(href);
                    if (!isVisibleElement(target)) return;

                    const top = getOffsetTop(target);
                    const height = target.getBoundingClientRect().height;
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
                        bestMatchId = navLinks[0]?.getAttribute('href') || null;
                    } else {
                        let minDiff = Infinity;
                        navLinks.forEach((link) => {
                            const href = link.getAttribute('href');
                            if (!href || !href.startsWith('#')) return;
                            const target = document.querySelector(href);
                            if (!isVisibleElement(target)) return;
                            const diff = Math.abs(getOffsetTop(target) - (scrollPos + stickyOffset));
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

            function highlightTargetSection(target, isDanger) {
                document.querySelectorAll('.section-target-highlight, .section-target-highlight--danger').forEach((el) => {
                    el.classList.remove('section-target-highlight', 'section-target-highlight--danger');
                });
                if (highlightTimer) {
                    clearTimeout(highlightTimer);
                }

                const highlightClass = isDanger ? 'section-target-highlight--danger' : 'section-target-highlight';
                target.classList.remove(highlightClass);
                void target.offsetWidth;
                target.classList.add(highlightClass);

                highlightTimer = setTimeout(() => {
                    target.classList.remove(highlightClass);
                }, 1850);
            }

            function clearManualLock() {
                manualClickLockId = null;
                if (unlockTimer) {
                    clearTimeout(unlockTimer);
                    unlockTimer = null;
                }
            }

            window.addEventListener('wheel', clearManualLock, { passive: true });
            window.addEventListener('touchmove', clearManualLock, { passive: true });
            window.addEventListener('keydown', clearManualLock);
            window.addEventListener('scroll', updateActiveSection, { passive: true });

            navLinks.forEach((link) => {
                link.addEventListener('click', (e) => {
                    e.preventDefault();
                    const targetId = link.getAttribute('href');
                    if (!targetId || !targetId.startsWith('#')) return;
                    const target = document.querySelector(targetId);
                    if (!target) return;

                    manualClickLockId = targetId;
                    setActiveTab(targetId);

                    if (unlockTimer) {
                        clearTimeout(unlockTimer);
                    }
                    unlockTimer = setTimeout(() => {
                        manualClickLockId = null;
                    }, 1500);

                    const isDanger = targetId === '#sec-claim' || link.classList.contains('po-nav-link--danger');
                    highlightTargetSection(target, isDanger);

                    const targetPosition = Math.max(0, getOffsetTop(target) - getStickyOffset());
                    const prefersReducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches ?? false;

                    isProgrammaticScroll = true;
                    if (scrollResetTimer) {
                        clearTimeout(scrollResetTimer);
                    }
                    window.scrollTo({
                        top: targetPosition,
                        behavior: prefersReducedMotion ? 'auto' : 'smooth'
                    });
                    scrollResetTimer = setTimeout(() => {
                        isProgrammaticScroll = false;
                    }, prefersReducedMotion ? 50 : 360);
                });
            });

            updateActiveSection();

            // Gate 2 (Decision B): Shipment-scoped document selection dialog
            const customsProgressData = @js($customsProgress ?? ['documents' => []]);
            const statusRanks = @js(\App\Models\PoDocument::STATUS_RANKS);
            const shipmentDocStatusLabels = {
                pending: @json(__('purchasing.copy.not_available')),
                received: @json(\App\Support\StatusHelper::shipmentDocLabel('received')),
                verified: @json(\App\Support\StatusHelper::shipmentDocLabel('verified')),
                issued: @json(\App\Support\StatusHelper::shipmentDocLabel('issued')),
                processing: @json(\App\Support\StatusHelper::shipmentDocLabel('processing')),
                done: @json(\App\Support\StatusHelper::shipmentDocLabel('done'))
            };
            const shipmentStatusLabels = {
                draft: @json(\App\Support\StatusHelper::shipmentLabel('draft')),
                submitted: @json(\App\Support\StatusHelper::shipmentLabel('submitted')),
                arrived: @json(\App\Support\StatusHelper::shipmentLabel('arrived')),
                cancelled: @json(\App\Support\StatusHelper::shipmentLabel('cancelled'))
            };
            const shipmentDocCopy = @js([
                'dialog_title' => __('documents.sync.dialog_title_for_doc'),
                'dialog_subtitle' => __('documents.sync.dialog_subtitle'),
                'select_target' => __('documents.sync.select_target_placeholder'),
                'selection_summary' => __('documents.sync.selection_summary'),
                'no_file' => __('documents.sync.no_file_uploaded'),
                'view_file' => __('documents.sync.view_file'),
                'legacy_file' => __('documents.sync.legacy_file_badge'),
                'multi_po' => __('documents.sync.multi_po_warning'),
                'ineligible' => __('documents.sync.ineligible_shipment_status'),
                'checkbox_label' => __('documents.sync.checkbox_label'),
                'result_updated' => __('documents.sync.result_updated'),
                'result_skipped' => __('documents.sync.result_skipped'),
                'result_stale' => __('documents.sync.result_stale'),
                'result_rejected' => __('documents.sync.result_rejected'),
                'result_not_applied' => __('documents.sync.result_not_applied'),
                'update_failed' => __('documents.sync.update_failed'),
            ]);

            const shipmentDocDialogState = {
                docType: null,
                docLabel: '',
                updateUrl: '',
                statuses: {},
                rows: [],
                selectedShipments: new Set(),
                rowOutcomes: {}
            };

            function shipmentDocStatusChipClass(status) {
                if (status === 'verified' || status === 'done') return 'ui-status-chip--success';
                if (status === 'received' || status === 'issued' || status === 'processing') return 'ui-status-chip--info';
                return 'ui-status-chip--neutral';
            }

            function shipmentRowStatusChipClass(status) {
                if (status === 'arrived') return 'ui-status-chip--success';
                if (status === 'submitted') return 'ui-status-chip--info';
                if (status === 'cancelled') return 'ui-status-chip--error';
                return 'ui-status-chip--neutral';
            }

            function isReasonRequiredForSelection() {
                const target = document.getElementById('shipmentDocTargetStatus')?.value;
                if (!target) return false;
                const targetRank = statusRanks[target] ?? 0;
                for (const row of shipmentDocDialogState.rows) {
                    if (!row.eligible || !shipmentDocDialogState.selectedShipments.has(row.shipment)) continue;
                    const currentRank = statusRanks[row.status] ?? 0;
                    if (targetRank <= currentRank && row.status !== target) {
                        return true;
                    }
                }
                return false;
            }

            function syncShipmentDocDialogControls() {
                const selectedCount = shipmentDocDialogState.selectedShipments.size;
                const target = document.getElementById('shipmentDocTargetStatus')?.value;
                const reasonEl = document.getElementById('shipmentDocReason');
                const reason = (reasonEl?.value || '').trim();
                const needsReason = isReasonRequiredForSelection();

                const summaryEl = document.getElementById('shipmentDocSelectionSummary');
                if (summaryEl) {
                    summaryEl.textContent = shipmentDocCopy.selection_summary.replace(':count', String(selectedCount));
                }

                const reasonHint = document.getElementById('shipmentDocReasonHint');
                if (needsReason) {
                    if (reasonHint) {
                        reasonHint.classList.remove('tw:hidden');
                        reasonHint.classList.add('tw:text-warning', 'tw:font-semibold');
                    }
                    reasonEl?.setAttribute('required', 'required');
                } else {
                    if (reasonHint) {
                        reasonHint.classList.add('tw:hidden');
                        reasonHint.classList.remove('tw:text-warning', 'tw:font-semibold');
                    }
                    reasonEl?.removeAttribute('required');
                }

                const canSubmit = selectedCount > 0 && Boolean(target) && (!needsReason || reason.length > 0);
                const submitBtn = document.getElementById('btnSubmitShipmentDocStatus');
                if (submitBtn) {
                    submitBtn.disabled = !canSubmit;
                }
            }

            function renderShipmentDocDialogRows() {
                const container = document.getElementById('shipmentDocRowsContainer');
                if (!container) return;
                container.replaceChildren();

                shipmentDocDialogState.rows.forEach((row, index) => {
                    const checkboxId = `shipment-doc-row-${index}`;
                    const isChecked = row.eligible && shipmentDocDialogState.selectedShipments.has(row.shipment);
                    const outcome = shipmentDocDialogState.rowOutcomes[row.shipment] || null;

                    const card = document.createElement('div');
                    card.className = 'tw:rounded-lg tw:border tw:border-outline-variant/80 tw:bg-surface tw:p-3 tw:space-y-2';
                    card.setAttribute('data-shipment-doc-row', row.shipment);

                    const topBar = document.createElement('div');
                    topBar.className = 'tw:flex tw:items-start tw:justify-between tw:gap-3';

                    const leftGroup = document.createElement('div');
                    leftGroup.className = 'tw:flex tw:items-start tw:gap-2.5 tw:min-w-0';

                    const checkbox = document.createElement('input');
                    checkbox.type = 'checkbox';
                    checkbox.id = checkboxId;
                    checkbox.className = 'ui-check tw:mt-1 shipment-doc-row-checkbox';
                    checkbox.style.minWidth = '1.25rem';
                    checkbox.style.minHeight = '1.25rem';
                    checkbox.value = row.shipment;
                    checkbox.disabled = !row.eligible;
                    checkbox.checked = isChecked;
                    const docStatusLabel = shipmentDocDialogState.statuses[row.status] || shipmentDocStatusLabels[row.status] || row.status;
                    const shpStatusLabel = shipmentStatusLabels[row.shipment_status] || row.shipment_status;
                    checkbox.setAttribute(
                        'aria-label',
                        shipmentDocCopy.checkbox_label
                            .replace(':shipment_status', shpStatusLabel)
                            .replace(':doc_status', docStatusLabel)
                            .replace(':shipment', row.shipment_number)
                    );
                    checkbox.addEventListener('change', function() {
                        if (this.checked) {
                            shipmentDocDialogState.selectedShipments.add(row.shipment);
                        } else {
                            shipmentDocDialogState.selectedShipments.delete(row.shipment);
                        }
                        syncShipmentDocDialogControls();
                    });

                    const labelWrap = document.createElement('div');
                    labelWrap.className = 'tw:min-w-0';

                    const titleRow = document.createElement('div');
                    titleRow.className = 'tw:flex tw:flex-wrap tw:items-center tw:gap-2';

                    const labelEl = document.createElement('label');
                    labelEl.htmlFor = checkboxId;
                    labelEl.className = 'tw:text-ui-sm tw:font-semibold tw:text-on-surface tw:cursor-pointer tw:mb-0';
                    labelEl.textContent = row.shipment_number;

                    const shipmentLink = document.createElement('a');
                    shipmentLink.href = row.shipment_url;
                    shipmentLink.className = 'tw:text-ui-xs tw:text-primary tw:no-underline tw:hover:underline';
                    shipmentLink.textContent = `(${shpStatusLabel})`;

                    const shpBadge = document.createElement('span');
                    shpBadge.className = `ui-status-chip ${shipmentRowStatusChipClass(row.shipment_status)}`;
                    shpBadge.textContent = shpStatusLabel;

                    titleRow.append(labelEl, shpBadge, shipmentLink);
                    labelWrap.appendChild(titleRow);

                    const fileRow = document.createElement('div');
                    fileRow.className = 'tw:flex tw:flex-wrap tw:items-center tw:gap-2 tw:mt-1 tw:text-ui-xs';
                    if (row.has_file && row.attachment_url) {
                        const fileNameSpan = document.createElement('span');
                        fileNameSpan.className = 'tw:text-on-surface-variant tw:truncate';
                        fileNameSpan.textContent = row.attachment_name || '';

                        const viewLink = document.createElement('a');
                        viewLink.href = row.attachment_url;
                        viewLink.target = '_blank';
                        viewLink.rel = 'noopener';
                        viewLink.className = 'tw:font-semibold tw:text-primary tw:no-underline tw:hover:underline';
                        viewLink.textContent = shipmentDocCopy.view_file;

                        fileRow.append(fileNameSpan, viewLink);

                        if (row.is_legacy_evidence) {
                            const legacyChip = document.createElement('span');
                            legacyChip.className = 'ui-status-chip ui-status-chip--warning';
                            legacyChip.textContent = shipmentDocCopy.legacy_file;
                            fileRow.appendChild(legacyChip);
                        }
                    } else {
                        const noFileSpan = document.createElement('span');
                        noFileSpan.className = 'tw:text-on-surface-variant';
                        noFileSpan.textContent = shipmentDocCopy.no_file;
                        fileRow.appendChild(noFileSpan);
                    }
                    labelWrap.appendChild(fileRow);

                    if (!row.eligible) {
                        const ineligibleNote = document.createElement('p');
                        ineligibleNote.className = 'tw:m-0 tw:mt-1 tw:text-ui-xs tw:text-on-surface-variant';
                        ineligibleNote.textContent = shipmentDocCopy.ineligible;
                        labelWrap.appendChild(ineligibleNote);
                    }

                    if (row.is_multi_po && Array.isArray(row.shared_po_numbers) && row.shared_po_numbers.length > 0) {
                        const multiPoNote = document.createElement('p');
                        multiPoNote.className = 'tw:m-0 tw:mt-1 tw:text-ui-xs tw:text-warning tw:font-medium';
                        multiPoNote.textContent = shipmentDocCopy.multi_po.replace(':pos', row.shared_po_numbers.join(', '));
                        labelWrap.appendChild(multiPoNote);
                    }

                    leftGroup.append(checkbox, labelWrap);

                    const rightGroup = document.createElement('div');
                    rightGroup.className = 'tw:flex tw:flex-col tw:items-end tw:gap-1 tw:shrink-0';

                    const docChip = document.createElement('span');
                    docChip.className = `ui-status-chip ${shipmentDocStatusChipClass(row.status)}`;
                    docChip.textContent = docStatusLabel;
                    rightGroup.appendChild(docChip);

                    if (outcome) {
                        const outcomeChip = document.createElement('span');
                        const outcomeTone = outcome.result === 'updated'
                            ? 'ui-status-chip--success'
                            : (outcome.result === 'skipped' ? 'ui-status-chip--neutral' : 'ui-status-chip--error');
                        const outcomeKey = `result_${outcome.result}`;
                        outcomeChip.className = `ui-status-chip ${outcomeTone}`;
                        outcomeChip.textContent = shipmentDocCopy[outcomeKey] || outcome.result;
                        rightGroup.appendChild(outcomeChip);
                    }

                    topBar.append(leftGroup, rightGroup);
                    card.appendChild(topBar);

                    if (outcome && outcome.message) {
                        const outcomeMsg = document.createElement('p');
                        outcomeMsg.className = 'tw:m-0 tw:pt-1 tw:border-t tw:border-outline-variant/60 tw:text-ui-xs tw:text-error';
                        outcomeMsg.textContent = outcome.message;
                        card.appendChild(outcomeMsg);
                    }

                    container.appendChild(card);
                });
            }

            function handleShipmentDocError(payload = {}) {
                const message = payload.message || shipmentDocCopy.update_failed;

                shipmentDocDialogState.rowOutcomes = {};
                if (Array.isArray(payload.rows)) {
                    payload.rows.forEach((r) => {
                        if (r && r.shipment) {
                            shipmentDocDialogState.rowOutcomes[r.shipment] = r;
                        }
                    });
                }

                if (Array.isArray(payload.rows_state)) {
                    shipmentDocDialogState.rows = payload.rows_state.map((r) => ({ ...r }));
                    if (customsProgressData.documents && shipmentDocDialogState.docType && customsProgressData.documents[shipmentDocDialogState.docType]) {
                        customsProgressData.documents[shipmentDocDialogState.docType].rows = payload.rows_state.map((r) => ({ ...r }));
                    }
                    const eligibleSet = new Set(
                        shipmentDocDialogState.rows.filter((r) => r.eligible).map((r) => r.shipment)
                    );
                    for (const selected of Array.from(shipmentDocDialogState.selectedShipments)) {
                        if (!eligibleSet.has(selected)) {
                            shipmentDocDialogState.selectedShipments.delete(selected);
                        }
                    }
                }

                const feedback = document.getElementById('shipmentDocDialogFeedback');
                if (feedback) {
                    feedback.className = 'tw:rounded-lg tw:border tw:border-error/40 tw:bg-error-container/20 tw:p-3 tw:text-ui-xs tw:text-error';
                    feedback.textContent = message;
                }

                renderShipmentDocDialogRows();
                syncShipmentDocDialogControls();
            }

            // Batch PO Multi-Doc Tracking Dialog Controller
            const batchPoDialogState = {
                docTypes: ['invoice', 'packing_list', 'bl', 'form_e'],
                updateUrl: @json(route('purchasing.purchase-orders.documents.shipments.batch-update', $po)),
                dataByDocType: {
                    invoice: { rows: [], selected: new Set(), targetStatus: '', reason: '', rowOutcomes: {} },
                    packing_list: { rows: [], selected: new Set(), targetStatus: '', reason: '', rowOutcomes: {} },
                    bl: { rows: [], selected: new Set(), targetStatus: '', reason: '', rowOutcomes: {} },
                    form_e: { rows: [], selected: new Set(), targetStatus: '', reason: '', rowOutcomes: {} }
                }
            };

            function renderBatchPoTabRows(docType) {
                const container = document.getElementById(`batchPoDocRowsContainer_${docType}`);
                if (!container) return;
                container.replaceChildren();

                const tabData = batchPoDialogState.dataByDocType[docType];

                if (!tabData.rows || tabData.rows.length === 0) {
                    const emptyEl = document.createElement('div');
                    emptyEl.className = 'tw:text-center tw:py-6 tw:text-on-surface-variant tw:text-ui-xs';
                    emptyEl.textContent = @json(__('purchasing.copy.not_available'));
                    container.appendChild(emptyEl);
                    return;
                }

                tabData.rows.forEach((row, index) => {
                    const checkboxId = `batch-po-row-${docType}-${index}`;
                    const isChecked = row.eligible && tabData.selected.has(row.shipment);
                    const outcome = tabData.rowOutcomes[row.shipment] || null;

                    const card = document.createElement('div');
                    card.className = 'tw:rounded-lg tw:border tw:border-outline-variant/80 tw:bg-surface tw:p-3 tw:space-y-2';
                    card.setAttribute('data-shipment-row', row.shipment);

                    const topBar = document.createElement('div');
                    topBar.className = 'tw:flex tw:items-start tw:justify-between tw:gap-3';

                    const leftGroup = document.createElement('div');
                    leftGroup.className = 'tw:flex tw:items-start tw:gap-2.5 tw:min-w-0';

                    const checkbox = document.createElement('input');
                    checkbox.type = 'checkbox';
                    checkbox.id = checkboxId;
                    checkbox.className = 'ui-check tw:mt-1';
                    checkbox.style.minWidth = '1.25rem';
                    checkbox.style.minHeight = '1.25rem';
                    checkbox.value = row.shipment;
                    checkbox.disabled = !row.eligible;
                    checkbox.checked = isChecked;

                    const docStatusLabel = shipmentDocStatusLabels[row.status] || row.status;
                    const shpStatusLabel = shipmentStatusLabels[row.shipment_status] || row.shipment_status;
                    checkbox.setAttribute(
                        'aria-label',
                        shipmentDocCopy.checkbox_label
                            .replace(':shipment_status', shpStatusLabel)
                            .replace(':doc_status', docStatusLabel)
                            .replace(':shipment', row.shipment_number)
                    );

                    checkbox.addEventListener('change', function() {
                        if (this.checked) {
                            tabData.selected.add(row.shipment);
                        } else {
                            tabData.selected.delete(row.shipment);
                        }
                        syncBatchPoDialogControls();
                    });

                    const labelWrap = document.createElement('div');
                    labelWrap.className = 'tw:min-w-0';

                    const titleRow = document.createElement('div');
                    titleRow.className = 'tw:flex tw:flex-wrap tw:items-center tw:gap-2';

                    const labelEl = document.createElement('label');
                    labelEl.htmlFor = checkboxId;
                    labelEl.className = 'tw:text-ui-sm tw:font-semibold tw:text-on-surface tw:cursor-pointer tw:mb-0';
                    labelEl.textContent = row.shipment_number;

                    const shpBadge = document.createElement('span');
                    shpBadge.className = `ui-status-chip ${shipmentRowStatusChipClass(row.shipment_status)}`;
                    shpBadge.textContent = shpStatusLabel;

                    const shipmentLink = document.createElement('a');
                    shipmentLink.href = row.shipment_url;
                    shipmentLink.className = 'tw:text-ui-xs tw:text-primary tw:no-underline tw:hover:underline';
                    shipmentLink.textContent = `(${shpStatusLabel})`;

                    titleRow.append(labelEl, shpBadge, shipmentLink);
                    labelWrap.appendChild(titleRow);

                    const fileRow = document.createElement('div');
                    fileRow.className = 'tw:flex tw:flex-wrap tw:items-center tw:gap-2 tw:mt-1 tw:text-ui-xs';
                    if (row.has_file && row.attachment_url) {
                        const fileNameSpan = document.createElement('span');
                        fileNameSpan.className = 'tw:text-on-surface-variant tw:truncate';
                        fileNameSpan.textContent = row.attachment_name || '';

                        const viewLink = document.createElement('a');
                        viewLink.href = row.attachment_url;
                        viewLink.target = '_blank';
                        viewLink.rel = 'noopener';
                        viewLink.className = 'tw:font-semibold tw:text-primary tw:no-underline tw:hover:underline';
                        viewLink.textContent = shipmentDocCopy.view_file;

                        fileRow.append(fileNameSpan, viewLink);

                        if (row.is_legacy_evidence) {
                            const legacyChip = document.createElement('span');
                            legacyChip.className = 'ui-status-chip ui-status-chip--warning';
                            legacyChip.textContent = shipmentDocCopy.legacy_file;
                            fileRow.appendChild(legacyChip);
                        }
                    } else {
                        const noFileSpan = document.createElement('span');
                        noFileSpan.className = 'tw:text-on-surface-variant';
                        noFileSpan.textContent = shipmentDocCopy.no_file;
                        fileRow.appendChild(noFileSpan);
                    }
                    labelWrap.appendChild(fileRow);

                    if (!row.eligible) {
                        const ineligibleNote = document.createElement('p');
                        ineligibleNote.className = 'tw:m-0 tw:mt-1 tw:text-ui-xs tw:text-on-surface-variant';
                        ineligibleNote.textContent = shipmentDocCopy.ineligible;
                        labelWrap.appendChild(ineligibleNote);
                    }

                    if (row.is_multi_po && Array.isArray(row.shared_po_numbers) && row.shared_po_numbers.length > 0) {
                        const multiPoNote = document.createElement('p');
                        multiPoNote.className = 'tw:m-0 tw:mt-1 tw:text-ui-xs tw:text-warning tw:font-medium';
                        multiPoNote.textContent = shipmentDocCopy.multi_po.replace(':pos', row.shared_po_numbers.join(', '));
                        labelWrap.appendChild(multiPoNote);
                    }

                    leftGroup.append(checkbox, labelWrap);

                    const rightGroup = document.createElement('div');
                    rightGroup.className = 'tw:flex tw:flex-col tw:items-end tw:gap-1 tw:shrink-0';

                    const docChip = document.createElement('span');
                    docChip.className = `ui-status-chip ${shipmentDocStatusChipClass(row.status)}`;
                    docChip.textContent = docStatusLabel;
                    rightGroup.appendChild(docChip);

                    if (outcome) {
                        const outcomeChip = document.createElement('span');
                        const outcomeTone = outcome.result === 'updated'
                            ? 'ui-status-chip--success'
                            : (outcome.result === 'skipped' ? 'ui-status-chip--neutral' : 'ui-status-chip--error');
                        const outcomeKey = `result_${outcome.result}`;
                        outcomeChip.className = `ui-status-chip ${outcomeTone}`;
                        outcomeChip.textContent = shipmentDocCopy[outcomeKey] || outcome.result;
                        rightGroup.appendChild(outcomeChip);
                    }

                    topBar.append(leftGroup, rightGroup);
                    card.appendChild(topBar);

                    if (outcome && outcome.message) {
                        const outcomeMsg = document.createElement('p');
                        outcomeMsg.className = 'tw:m-0 tw:pt-1 tw:border-t tw:border-outline-variant/60 tw:text-ui-xs tw:text-error';
                        outcomeMsg.textContent = outcome.message;
                        card.appendChild(outcomeMsg);
                    }

                    container.appendChild(card);
                });
            }

            function isReasonRequiredForTab(docType) {
                const tabData = batchPoDialogState.dataByDocType[docType];
                const target = tabData.targetStatus;
                if (!target) return false;
                const targetRank = statusRanks[target] ?? 0;
                for (const row of tabData.rows) {
                    if (!row.eligible || !tabData.selected.has(row.shipment)) continue;
                    const currentRank = statusRanks[row.status] ?? 0;
                    if (targetRank <= currentRank && row.status !== target) {
                        return true;
                    }
                }
                return false;
            }

            function syncBatchPoDialogControls() {
                let totalSelectedAll = 0;
                let anyTabConfigured = false;
                let allConfiguredTabsValid = true;

                batchPoDialogState.docTypes.forEach((docType) => {
                    const tabData = batchPoDialogState.dataByDocType[docType];
                    const selectedCount = tabData.selected.size;
                    totalSelectedAll += selectedCount;

                    const summaryEl = document.getElementById(`batchPoDocSelectionSummary_${docType}`);
                    if (summaryEl) {
                        summaryEl.textContent = shipmentDocCopy.selection_summary.replace(':count', String(selectedCount));
                    }
                    const badgeEl = document.getElementById(`batchPoTabBadge_${docType}`);
                    if (badgeEl) {
                        badgeEl.textContent = String(selectedCount);
                    }

                    const target = tabData.targetStatus;
                    const reason = (tabData.reason || '').trim();
                    const needsReason = isReasonRequiredForTab(docType);

                    const reasonHint = document.getElementById(`batchPoTabReasonHint_${docType}`);
                    if (reasonHint) {
                        if (needsReason) {
                            reasonHint.classList.remove('tw:hidden');
                            reasonHint.classList.add('tw:text-warning', 'tw:font-semibold');
                        } else {
                            reasonHint.classList.add('tw:hidden');
                            reasonHint.classList.remove('tw:text-warning', 'tw:font-semibold');
                        }
                    }

                    if (selectedCount > 0) {
                        anyTabConfigured = true;
                        if (!target || (needsReason && reason.length === 0)) {
                            allConfiguredTabsValid = false;
                        }
                    }
                });

                const globalCounter = document.getElementById('batchPoGlobalCounterSummary');
                if (globalCounter) {
                    globalCounter.textContent = shipmentDocCopy.selection_summary.replace(':count', String(totalSelectedAll));
                }

                const canSubmit = anyTabConfigured && allConfiguredTabsValid;
                const submitBtn = document.getElementById('btnSubmitBatchPoDocStatus');
                if (submitBtn) {
                    submitBtn.disabled = !canSubmit;
                }
            }

            function initBatchPoDocDialog() {
                const feedback = document.getElementById('batchPoDocDialogFeedback');
                if (feedback) {
                    feedback.classList.add('tw:hidden');
                    feedback.replaceChildren();
                }
                const globalTarget = document.getElementById('batchPoGlobalTargetStatus');
                if (globalTarget) globalTarget.value = '';
                const globalReason = document.getElementById('batchPoGlobalReason');
                if (globalReason) globalReason.value = '';

                batchPoDialogState.docTypes.forEach((docType) => {
                    const docProgress = (customsProgressData.documents && customsProgressData.documents[docType]) || { rows: [] };
                    const tabData = batchPoDialogState.dataByDocType[docType];
                    tabData.rows = Array.isArray(docProgress.rows) ? docProgress.rows.map((r) => ({ ...r })) : [];
                    tabData.selected = new Set();
                    tabData.targetStatus = '';
                    tabData.reason = '';
                    tabData.rowOutcomes = {};

                    const targetInput = document.getElementById(`batchPoTabTarget_${docType}`);
                    if (targetInput) targetInput.value = '';
                    const reasonInput = document.getElementById(`batchPoTabReason_${docType}`);
                    if (reasonInput) reasonInput.value = '';
                    document.getElementById(`batchPoTabReasonHint_${docType}`)?.classList.add('tw:hidden');

                    renderBatchPoTabRows(docType);
                });

                syncBatchPoDialogControls();

                const firstTabBtn = document.querySelector('#batchPoDocNavTabs button:first-child');
                if (firstTabBtn) {
                    window.AdasiTabs?.show?.(firstTabBtn);
                }
            }

            function handleBatchPoError(payload = {}) {
                const message = payload.message || shipmentDocCopy.update_failed;

                batchPoDialogState.docTypes.forEach((docType) => {
                    batchPoDialogState.dataByDocType[docType].rowOutcomes = {};
                });

                if (Array.isArray(payload.rows)) {
                    payload.rows.forEach((r) => {
                        if (r && r.doc_type && r.shipment && batchPoDialogState.dataByDocType[r.doc_type]) {
                            batchPoDialogState.dataByDocType[r.doc_type].rowOutcomes[r.shipment] = r;
                        }
                    });
                }

                if (payload.projection && payload.projection.documents) {
                    Object.assign(customsProgressData.documents, payload.projection.documents);
                    batchPoDialogState.docTypes.forEach((docType) => {
                        const docProgress = customsProgressData.documents[docType] || { rows: [] };
                        const tabData = batchPoDialogState.dataByDocType[docType];
                        tabData.rows = Array.isArray(docProgress.rows) ? docProgress.rows.map((r) => ({ ...r })) : [];
                        const eligibleSet = new Set(tabData.rows.filter((r) => r.eligible).map((r) => r.shipment));
                        for (const s of Array.from(tabData.selected)) {
                            if (!eligibleSet.has(s)) {
                                tabData.selected.delete(s);
                            }
                        }
                    });
                }

                const feedback = document.getElementById('batchPoDocDialogFeedback');
                if (feedback) {
                    feedback.className = 'tw:rounded-lg tw:border tw:border-error/40 tw:bg-error-container/20 tw:p-3 tw:text-ui-xs tw:text-error';
                    feedback.textContent = message;
                }

                batchPoDialogState.docTypes.forEach((docType) => {
                    renderBatchPoTabRows(docType);
                });
                syncBatchPoDialogControls();
            }

            // Delegated click handlers across PO page and dialogs
            document.addEventListener('click', async (event) => {
                const target = event.target instanceof Element ? event.target : null;
                if (!target) return;

                const updateDocBtn = target.closest('.btn-update-doc');
                if (updateDocBtn) {
                    const docId = updateDocBtn.dataset.docId;
                    const docLabel = updateDocBtn.dataset.docLabel || '';
                    const currentStatus = updateDocBtn.dataset.docStatus || '';
                    const currentStatusLabel = updateDocBtn.dataset.docStatusLabel || '';
                    const statuses = parseJsonAttr(updateDocBtn, 'data-doc-statuses', {});

                    const modalDocId = document.getElementById('modalDocId');
                    if (modalDocId) modalDocId.value = docId || '';
                    const modalDocTitle = document.getElementById('modalDocTitle');
                    if (modalDocTitle) {
                        modalDocTitle.textContent = @js(__('common.final_review.update_document')).replace(':document', docLabel);
                    }

                    const select = document.getElementById('modalDocStatus');
                    if (select) {
                        select.replaceChildren();
                        if (!Object.prototype.hasOwnProperty.call(statuses, currentStatus)) {
                            // Historical status outside the current list: show it, never preselect another one.
                            const historicalOpt = document.createElement('option');
                            historicalOpt.value = '';
                            historicalOpt.disabled = true;
                            historicalOpt.selected = true;
                            historicalOpt.textContent = currentStatusLabel;
                            select.appendChild(historicalOpt);
                        }
                        for (const [key, label] of Object.entries(statuses)) {
                            const opt = document.createElement('option');
                            opt.value = key;
                            opt.selected = key === currentStatus;
                            opt.textContent = label;
                            select.appendChild(opt);
                        }
                    }

                    const updateModalEl = document.getElementById('updateDocModal');
                    if (updateModalEl) {
                        window.AdasiModal?.getOrCreateInstance?.(updateModalEl)?.show(updateBtn);
                    }
                    return;
                }

                const openShipmentDocBtn = target.closest('.btn-open-shipment-doc-dialog');
                if (openShipmentDocBtn) {
                    const docType = openShipmentDocBtn.dataset.docType;
                    const docLabel = openShipmentDocBtn.dataset.docLabel || '';
                    const updateUrl = openShipmentDocBtn.dataset.updateUrl || '';
                    const statuses = parseJsonAttr(openShipmentDocBtn, 'data-doc-statuses', {});
                    const docProgress = (customsProgressData.documents && customsProgressData.documents[docType]) || { rows: [] };

                    shipmentDocDialogState.docType = docType;
                    shipmentDocDialogState.docLabel = docLabel;
                    shipmentDocDialogState.updateUrl = updateUrl;
                    shipmentDocDialogState.statuses = statuses;
                    shipmentDocDialogState.rows = Array.isArray(docProgress.rows) ? docProgress.rows.map((r) => ({ ...r })) : [];
                    shipmentDocDialogState.selectedShipments = new Set();
                    shipmentDocDialogState.rowOutcomes = {};

                    const titleEl = document.getElementById('update-shipment-docs-title');
                    if (titleEl) titleEl.textContent = shipmentDocCopy.dialog_title.replace(':document', docLabel);
                    const descEl = document.getElementById('update-shipment-docs-description');
                    if (descEl) descEl.textContent = shipmentDocCopy.dialog_subtitle.replace(':document', docLabel);

                    const feedback = document.getElementById('shipmentDocDialogFeedback');
                    if (feedback) {
                        feedback.classList.add('tw:hidden');
                        feedback.replaceChildren();
                    }

                    const select = document.getElementById('shipmentDocTargetStatus');
                    if (select) {
                        select.replaceChildren();
                        const placeholderOption = document.createElement('option');
                        placeholderOption.value = '';
                        placeholderOption.disabled = true;
                        placeholderOption.selected = true;
                        placeholderOption.textContent = shipmentDocCopy.select_target;
                        select.appendChild(placeholderOption);

                        for (const [key, label] of Object.entries(statuses)) {
                            const opt = document.createElement('option');
                            opt.value = key;
                            opt.textContent = label;
                            select.appendChild(opt);
                        }
                    }

                    const reasonEl = document.getElementById('shipmentDocReason');
                    if (reasonEl) reasonEl.value = '';
                    renderShipmentDocDialogRows();
                    syncShipmentDocDialogControls();

                    window.dispatchEvent(new CustomEvent('open-ui-dialog', { detail: 'update-shipment-docs' }));
                    return;
                }

                const historyBtn = target.closest('.btn-view-purchasing-history');
                if (historyBtn) {
                    const materialName = historyBtn.dataset.materialName || '';
                    const historyUrl = historyBtn.dataset.historyUrl;
                    const modalEl = document.getElementById('progressHistoryModal');
                    const titleEl = document.getElementById('progressHistoryModalLabel');
                    const bodyEl = document.getElementById('progressHistoryModalBody');
                    if (!modalEl || !titleEl || !bodyEl || !historyUrl) return;

                    titleEl.textContent = window.AdasiI18n.t('purchasing.js.progress_history', { material: materialName });
                    const loadingLabel = window.AdasiI18n.t('purchasing.js.loading_history');
                    const inlineLoader = window.AdasiLoader?.createInlineLoader?.(loadingLabel);
                    if (inlineLoader) {
                        bodyEl.replaceChildren(inlineLoader);
                    } else {
                        bodyEl.innerHTML = '<div class="ui-inline-loading" role="status" aria-live="polite"><div class="ui-inline-loading__pill"><span class="ui-spinner" aria-hidden="true"></span><span class="ui-inline-loading__text"></span></div></div>';
                        bodyEl.querySelector('.ui-inline-loading__text').textContent = loadingLabel;
                    }

                    window.AdasiModal?.getOrCreateInstance?.(modalEl)?.show(historyBtn);

                    fetch(historyUrl, {
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                    })
                        .then((res) => {
                            if (!res.ok) throw new Error('Failed to load history');
                            return res.json();
                        })
                        .then((data) => {
                            if (!data.history || data.history.length === 0) {
                                const emptyEl = document.createElement('div');
                                emptyEl.className = 'tw:text-center tw:py-4 tw:text-on-surface-variant';
                                emptyEl.textContent = window.AdasiI18n.t('purchasing.js.no_progress');
                                bodyEl.replaceChildren(emptyEl);
                                return;
                            }
                            bodyEl.replaceChildren(renderProgressHistoryRows(data.history));
                        })
                        .catch(() => {
                            const errEl = document.createElement('div');
                            errEl.className = 'tw:rounded-ui-sm tw:border tw:border-error/40 tw:bg-error-container tw:text-error-container-foreground tw:py-2 tw:px-3';
                            errEl.textContent = window.AdasiI18n.t('purchasing.js.history_failed');
                            bodyEl.replaceChildren(errEl);
                        });
                    return;
                }

                const selectAllBtn = target.closest('.btn-select-all-tab');
                if (selectAllBtn) {
                    const docType = selectAllBtn.dataset.docType;
                    const tabData = batchPoDialogState.dataByDocType[docType];
                    if (!tabData) return;
                    tabData.rows.forEach((r) => {
                        if (r.eligible) {
                            tabData.selected.add(r.shipment);
                        }
                    });
                    renderBatchPoTabRows(docType);
                    syncBatchPoDialogControls();
                    return;
                }

                const clearTabBtn = target.closest('.btn-clear-tab');
                if (clearTabBtn) {
                    const docType = clearTabBtn.dataset.docType;
                    const tabData = batchPoDialogState.dataByDocType[docType];
                    if (!tabData) return;
                    tabData.selected.clear();
                    renderBatchPoTabRows(docType);
                    syncBatchPoDialogControls();
                }
            });

            document.addEventListener('change', (event) => {
                const target = event.target instanceof Element ? event.target : null;
                if (!target) return;

                if (target.id === 'shipmentDocTargetStatus') {
                    syncShipmentDocDialogControls();
                    return;
                }

                const tabTargetEl = target.closest('.batch-po-tab-target');
                if (tabTargetEl) {
                    const docType = tabTargetEl.dataset.docType;
                    const tabData = batchPoDialogState.dataByDocType[docType];
                    if (!tabData) return;
                    tabData.targetStatus = tabTargetEl.value;
                    syncBatchPoDialogControls();
                }
            });

            document.addEventListener('input', (event) => {
                const target = event.target instanceof Element ? event.target : null;
                if (!target) return;

                if (target.id === 'shipmentDocTargetStatus' || target.id === 'shipmentDocReason') {
                    syncShipmentDocDialogControls();
                    return;
                }

                const tabReasonEl = target.closest('.batch-po-tab-reason');
                if (tabReasonEl) {
                    const docType = tabReasonEl.dataset.docType;
                    const tabData = batchPoDialogState.dataByDocType[docType];
                    if (!tabData) return;
                    tabData.reason = tabReasonEl.value;
                    syncBatchPoDialogControls();
                }
            });

            document.getElementById('btnSaveDocStatus')?.addEventListener('click', async function() {
                const saveBtn = this;
                const docId = document.getElementById('modalDocId')?.value || '';
                const newStatus = document.getElementById('modalDocStatus')?.value || '';
                const spinner = document.getElementById('docSpinner');

                spinner?.classList.remove('tw:hidden');
                saveBtn.disabled = true;

                try {
                    const url = @js(route('purchasing.po-documents.update', '__DOCUMENT_ID__')).replace('__DOCUMENT_ID__', encodeURIComponent(docId));
                    const response = await fetch(url, {
                        method: 'PUT',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                            'X-CSRF-TOKEN': csrfToken,
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json'
                        },
                        body: new URLSearchParams({
                            _token: '{{ csrf_token() }}',
                            status: newStatus
                        })
                    });

                    let res = {};
                    try {
                        res = await response.json();
                    } catch {
                        res = {};
                    }

                    if (!response.ok || !res.success) {
                        throw new Error(res.message || 'Update failed');
                    }

                    const statusLabels = {
                        'pending': @json(__('purchasing.copy.not_available')), 'received': @json(\App\Support\StatusHelper::shipmentDocLabel('received')), 'verified': @json(\App\Support\StatusHelper::shipmentDocLabel('verified')),
                        'issued': @json(\App\Support\StatusHelper::shipmentDocLabel('issued')), 'processing': @json(\App\Support\StatusHelper::shipmentDocLabel('processing')), 'done': @json(\App\Support\StatusHelper::shipmentDocLabel('done'))
                    };
                    const statusClasses = {
                        'pending': 'ui-status-chip--neutral', 'received': 'ui-status-chip--info', 'verified': 'ui-status-chip--success',
                        'issued': 'ui-status-chip--info', 'processing': 'ui-status-chip--info', 'done': 'ui-status-chip--success'
                    };

                    const badge = document.getElementById('doc-badge-' + docId);
                    if (badge) {
                        badge.textContent = statusLabels[res.doc.status] || res.doc.status;
                        badge.className = 'ui-status-chip tw:mb-1.5 doc-status-badge ' + (statusClasses[res.doc.status] || 'ui-status-chip--neutral');
                        badge.setAttribute('data-status', res.doc.status);
                    }
                    const dateEl = document.getElementById('doc-date-' + docId);
                    if (dateEl) {
                        dateEl.textContent = res.doc.updated_at;
                    }

                    document.querySelectorAll(`.btn-update-doc[data-doc-id="${CSS.escape(String(docId))}"]`).forEach((btnEl) => {
                        btnEl.dataset.docStatus = res.doc.status;
                        btnEl.dataset.docStatusLabel = statusLabels[res.doc.status] || res.doc.status;
                    });

                    const completedStatuses = ['received', 'verified', 'done'];
                    const total = {{ $totalDocs }};
                    const completed = Array.from(document.querySelectorAll('.doc-status-badge'))
                        .filter((el) => completedStatuses.includes(el.getAttribute('data-status')))
                        .length;

                    const pct = total > 0 ? (completed / total * 100) : 0;
                    const roundedPct = Math.round(pct);
                    const progressBar = document.getElementById('docProgressBar');
                    if (progressBar) {
                        progressBar.value = roundedPct;
                        progressBar.setAttribute('value', String(roundedPct));
                    }
                    const docsComplete = completed >= total;
                    const docProgressBadge = document.getElementById('docProgressBadge');
                    if (docProgressBadge) {
                        docProgressBadge.textContent = completed + '/' + total + ' complete';
                        docProgressBadge.className = 'ui-status-chip ' + (docsComplete ? 'ui-status-chip--success' : 'ui-status-chip--warning');
                        docProgressBadge.setAttribute('data-ui-tooltip', docsComplete
                            ? @json(__('purchasing.copy.all_import_documents_are_complete'))
                            : @json(__('purchasing.copy.some_import_documents_still_need_to_be_completed_or_verified')));
                    }
                    window.initAdasiTooltips?.(document);

                    if (docsComplete) {
                        if (!document.getElementById('allDocsAlert') && progressBar) {
                            const alertTemplate = document.getElementById('allDocsAlertTemplate');
                            if (alertTemplate?.content) {
                                progressBar.after(alertTemplate.content.cloneNode(true));
                            }
                        }
                    } else {
                        document.getElementById('allDocsAlert')?.remove();
                    }

                    const updateModalEl = document.getElementById('updateDocModal');
                    if (updateModalEl) {
                        window.AdasiModal?.getInstance?.(updateModalEl)?.hide();
                    }

                    AdasiToast.show({
                        type: 'success',
                        title: @json(__('common.feedback.success')),
                        message: res.message,
                        autoClose: 1500
                    });
                } catch {
                    AdasiToast.show({
                        type: 'error',
                        title: @json(__('purchasing.copy.update_failed')),
                        message: @json(__('purchasing.copy.the_document_status_could_not_be_updated')),
                        autoClose: 4000
                    });
                } finally {
                    spinner?.classList.add('tw:hidden');
                    saveBtn.disabled = false;
                }
            });

            document.getElementById('btnSubmitShipmentDocStatus')?.addEventListener('click', async function() {
                const submitBtn = this;
                const targetStatus = document.getElementById('shipmentDocTargetStatus')?.value || '';
                const reason = (document.getElementById('shipmentDocReason')?.value || '').trim();
                const selectedRowsPayload = [];

                shipmentDocDialogState.rows.forEach((row) => {
                    if (row.eligible && shipmentDocDialogState.selectedShipments.has(row.shipment)) {
                        selectedRowsPayload.push({
                            shipment: row.shipment,
                            expected_status: row.status,
                            expected_attachment: row.attachment_hash || null
                        });
                    }
                });

                if (!targetStatus || selectedRowsPayload.length === 0) {
                    return;
                }

                const spinner = document.getElementById('shipmentDocSpinner');
                spinner?.classList.remove('tw:hidden');
                submitBtn.disabled = true;

                try {
                    const response = await fetch(shipmentDocDialogState.updateUrl, {
                        method: 'PUT',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json'
                        },
                        body: JSON.stringify({
                            target_status: targetStatus,
                            reason: reason !== '' ? reason : null,
                            rows: selectedRowsPayload
                        })
                    });

                    let payload = {};
                    try {
                        payload = await response.json();
                    } catch {
                        payload = {};
                    }

                    if (response.ok && payload && payload.success) {
                        AdasiToast.show({
                            type: 'success',
                            title: @json(__('common.feedback.success')),
                            message: payload.message,
                            autoClose: 1500
                        });
                        window.location.reload();
                        return;
                    }

                    handleShipmentDocError(payload);
                } catch {
                    handleShipmentDocError({});
                } finally {
                    spinner?.classList.add('tw:hidden');
                    syncShipmentDocDialogControls();
                }
            });

            document.getElementById('btnConfirmArrival')?.addEventListener('click', () => {
                AdasiAlert.confirm({
                    title: @json(__('purchasing.copy.confirm_material_arrival')),
                    text: @json(__('purchasing.copy.the_arrival_date_will_be_set_to_today_and_qc_will_be_notified')),
                    confirmTone: 'success',
                    confirmText: @json(__('purchasing.audit_ui.confirm_arrival')),
                    cancelText: @json(__('purchasing.copy.cancel'))
                }).then((result) => {
                    if (result.isConfirmed) {
                        document.getElementById('arrivalForm')?.submit();
                    }
                });
            });

            document.getElementById('btnOpenBatchPoDocDialog')?.addEventListener('click', () => {
                initBatchPoDocDialog();
                window.dispatchEvent(new CustomEvent('open-ui-dialog', { detail: 'batch-update-po-docs' }));
            });

            document.getElementById('btnApplyGlobalToAllTabs')?.addEventListener('click', () => {
                const globalTarget = document.getElementById('batchPoGlobalTargetStatus')?.value || '';
                const globalReason = (document.getElementById('batchPoGlobalReason')?.value || '').trim();

                if (!globalTarget) {
                    return;
                }

                batchPoDialogState.docTypes.forEach((docType) => {
                    const tabData = batchPoDialogState.dataByDocType[docType];
                    tabData.targetStatus = globalTarget;
                    tabData.reason = globalReason;
                    const targetInput = document.getElementById(`batchPoTabTarget_${docType}`);
                    if (targetInput) targetInput.value = globalTarget;
                    const reasonInput = document.getElementById(`batchPoTabReason_${docType}`);
                    if (reasonInput) reasonInput.value = globalReason;

                    if (tabData.selected.size === 0) {
                        tabData.rows.forEach((r) => {
                            if (r.eligible) {
                                tabData.selected.add(r.shipment);
                            }
                        });
                        renderBatchPoTabRows(docType);
                    }
                });

                syncBatchPoDialogControls();
            });

            document.getElementById('btnSubmitBatchPoDocStatus')?.addEventListener('click', async function() {
                const submitBtn = this;
                const documentsPayload = {};

                batchPoDialogState.docTypes.forEach((docType) => {
                    const tabData = batchPoDialogState.dataByDocType[docType];
                    if (tabData.selected.size > 0 && tabData.targetStatus) {
                        const rowsPayload = [];
                        tabData.rows.forEach((row) => {
                            if (row.eligible && tabData.selected.has(row.shipment)) {
                                rowsPayload.push({
                                    shipment: row.shipment,
                                    expected_status: row.status,
                                    expected_attachment: row.attachment_hash || null
                                });
                            }
                        });

                        if (rowsPayload.length > 0) {
                            documentsPayload[docType] = {
                                target_status: tabData.targetStatus,
                                reason: (tabData.reason || '').trim() || null,
                                rows: rowsPayload
                            };
                        }
                    }
                });

                if (Object.keys(documentsPayload).length === 0) {
                    return;
                }

                const spinner = document.getElementById('batchPoDocSpinner');
                spinner?.classList.remove('tw:hidden');
                submitBtn.disabled = true;

                try {
                    const response = await fetch(batchPoDialogState.updateUrl, {
                        method: 'PUT',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json'
                        },
                        body: JSON.stringify({
                            documents: documentsPayload
                        })
                    });

                    let payload = {};
                    try {
                        payload = await response.json();
                    } catch {
                        payload = {};
                    }

                    if (response.ok && payload && payload.success) {
                        AdasiToast.show({
                            type: 'success',
                            title: @json(__('common.feedback.success')),
                            message: payload.message,
                            autoClose: 1500
                        });
                        window.location.reload();
                        return;
                    }

                    handleBatchPoError(payload);
                } catch {
                    handleBatchPoError({});
                } finally {
                    spinner?.classList.add('tw:hidden');
                    syncBatchPoDialogControls();
                }
            });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', bootPoShow);
        } else {
            bootPoShow();
        }
    })();
</script>
@endpush
