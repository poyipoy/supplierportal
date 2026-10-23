@extends('layouts.app')

@section('title', __('supplier.titles.quotation', ['number' => $quotation->purchaseRequisition->pr_number ?? '-']))
@section('page-title', __('supplier.copy.quotation_details'))

@section('content')
<div class="tw:grid tw:gap-4">
    {{-- Breadcrumb & Compact Page Header --}}
    <x-ui.breadcrumb :items="[
        __('purchasing.breadcrumbs.dashboard') => route('supplier.dashboard'),
        __('purchasing.breadcrumbs.quotation_periods') => route('supplier.quotations.index'),
        ($quotation->purchaseRequisition->period->display_label ?? __('purchasing.breadcrumbs.requisitions')) => route('supplier.quotations.period', $quotation->purchaseRequisition->period_id),
        __('purchasing.breadcrumbs.quotation_details') => null,
    ]" />

    <x-ui.page-header
        :title="__('purchasing.closure.quotation_title', ['number' => $quotation->purchaseRequisition->pr_number ?? '-'])"
        :eyebrow="__('supplier.copy.submitted_quotation')"
        :description="__('supplier.copy.review_your_submitted_prices_availability_parameters_supporting_mtc_files_and_purchasing_evaluation')"
    >
        <x-slot:actions>
            <x-status-badge type="quotation" :status="$quotation->status" size="lg" />
            <x-ui.button :href="route('supplier.export.quotations.detail', $quotation)" variant="outline" size="sm" data-async-export data-export-source-singular="{{ __('exports.sources.quotation') }}" data-export-source-plural="{{ __('exports.sources.quotations') }}" data-export-source-count="1" data-export-filtered="false" data-export-row-label="{{ __('supplier.copy.quotation_item_rows') }}" data-export-row-explanation="{{ __('supplier.copy.each_quotation_item_will_be_written_as_a_separate_excel_row') }}">
                <x-ui.icon name="file-spreadsheet" />
                <span>{{ __('supplier.copy.export_excel') }}</span>
            </x-ui.button>
            <x-ui.button :href="route('supplier.quotations.period', $quotation->purchaseRequisition->period_id)" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" />
                <span>{{ __('supplier.copy.back_to_requisitions') }}</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Decision 24: Procurement Context → Commercial Terms & Logistics → Status & Follow-up → Detail Quotation --}}

    {{-- 1. Procurement Context --}}
    <x-ui.card :title="__('procurement.quotation.procurement_context')" data-quotation-section="context">
        <div class="tw:grid tw:gap-3 tw:sm:grid-cols-2 tw:lg:grid-cols-3">
            <div class="tw:p-2.5 tw:bg-surface-low border rounded">
                <div class="tw:text-on-surface-variant tw:text-ui-xs fw-semibold tw:uppercase">{{ __('supplier.copy.procurement_period') }}</div>
                <div class="fw-bold tw:text-on-surface tw:text-ui-sm tw:mt-0.5">{{ $quotation->purchaseRequisition->period->display_label ?? '-' }}</div>
            </div>
            <div class="tw:p-2.5 tw:bg-surface-low border rounded">
                <div class="tw:text-on-surface-variant tw:text-ui-xs fw-semibold tw:uppercase">{{ __('supplier.copy.requisition_reference') }}</div>
                <div class="fw-bold text-primary tw:text-ui-sm tw:mt-0.5">{{ $quotation->purchaseRequisition->pr_number ?? '-' }}</div>
            </div>
            <div class="tw:sm:col-span-2 tw:lg:col-span-1 tw:p-2.5 tw:bg-surface-low border rounded">
                <div class="tw:text-on-surface-variant tw:text-ui-xs fw-semibold tw:uppercase">{{ __('supplier.copy.purchasing_instructions') }}</div>
                <div class="tw:text-on-surface tw:text-ui-xs tw:mt-0.5">{{ $quotation->purchaseRequisition->notes ?: __('supplier.copy.no_additional_notes_provided') }}</div>
            </div>
        </div>
        <div class="tw:mt-3">
            @include('partials.procurement-logistics', ['port' => $quotation->purchaseRequisition->port_of_discharge, 'incoterm' => $quotation->purchaseRequisition->incoterm])
        </div>
    </x-ui.card>

    {{-- 2. Commercial Terms & Logistics --}}
    <x-ui.card :title="__('procurement.quotation.commercial_terms')" data-quotation-section="terms">
        <div class="tw:grid tw:gap-3 tw:sm:grid-cols-2 tw:lg:grid-cols-3">
            <div class="tw:p-2.5 tw:bg-surface-low border rounded">
                <div class="tw:text-on-surface-variant tw:text-ui-xs fw-semibold tw:uppercase">{{ __('supplier.copy.date_submitted') }}</div>
                <div class="fw-semibold tw:text-on-surface tw:text-ui-sm tw:mt-0.5">
                    {{ $quotation->submitted_at ? $regionalFormatter->timestamp($quotation->submitted_at, 'datetime_comma') : '-' }}
                </div>
            </div>
            <div class="tw:p-2.5 tw:bg-surface-low border rounded">
                <div class="tw:text-on-surface-variant tw:text-ui-xs fw-semibold tw:uppercase">{{ __('supplier.copy.exchange_rate_snapshot') }}</div>
                <div class="fw-bold tw:text-on-surface tw:text-ui-sm tw:mt-0.5">
                    @if($quotation->exchange_rate)
                        1 {{ $quotation->currency }} = Rp {{ $regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($quotation->exchange_rate->rate_to_idr), 'decimal') }}
                    @else
                        -
                    @endif
                </div>
            </div>
            <div class="tw:p-2.5 tw:bg-surface-low border rounded">
                <div class="tw:text-on-surface-variant tw:text-ui-xs fw-semibold tw:uppercase">{{ __('supplier.copy.supplier_estimate_delivery') }}</div>
                <div class="fw-semibold tw:text-on-surface tw:text-ui-sm tw:mt-0.5">
                    {{ $quotation->estimated_delivery ? $regionalFormatter->date(\Carbon\Carbon::parse($quotation->estimated_delivery), 'full_human') : '-' }}
                </div>
            </div>
            <div class="tw:p-2.5 tw:bg-surface-low border rounded">
                <div class="tw:text-on-surface-variant tw:text-ui-xs fw-semibold tw:uppercase">{{ __('supplier.copy.valid_until') }}</div>
                <div class="fw-semibold tw:text-on-surface tw:text-ui-sm tw:mt-0.5">
                    {{ $quotation->validity_period ? $regionalFormatter->date(\Carbon\Carbon::parse($quotation->validity_period), 'full_human') : '-' }}
                </div>
            </div>
            <div class="tw:sm:col-span-2 tw:p-2.5 tw:bg-surface-low border rounded">
                <div class="tw:text-on-surface-variant tw:text-ui-xs fw-semibold tw:uppercase">{{ __('supplier.copy.payment_terms') }}</div>
                <div class="tw:text-on-surface tw:text-ui-xs tw:mt-0.5">{{ $quotation->payment_terms ?: __('supplier.copy.standard_terms') }}</div>
            </div>
            @if($quotation->general_notes)
                <div class="tw:sm:col-span-2 tw:lg:col-span-3 tw:p-2.5 tw:bg-surface-low border rounded">
                    <div class="tw:text-on-surface-variant tw:text-ui-xs fw-semibold tw:uppercase">{{ __('supplier.copy.general_notes') }}</div>
                    <div class="tw:text-on-surface tw:text-ui-xs tw:mt-0.5">{{ $quotation->general_notes }}</div>
                </div>
            @endif
        </div>
    </x-ui.card>

    {{-- 3. Status & Follow-up --}}
    <x-ui.card :title="__('procurement.quotation.status_follow_up')" data-quotation-section="status">
        @if($quotation->reviewer_notes)
            <div class="tw:mb-3 tw:p-2.5 bg-warning-subtle border border-warning-subtle rounded text-warning-emphasis">
                <div class="fw-bold tw:text-ui-xs tw:uppercase tw:mb-0.5"><x-ui.icon name="square-pen" size="sm" class="me-1" />{{ __('supplier.copy.purchasing_reviewer_notes') }}</div>
                <div class="tw:text-ui-xs">{{ $quotation->reviewer_notes }}</div>
            </div>
        @endif

        @if($quotation->status === 'revision_requested')
            <x-ui.alert tone="warning" :title="__('supplier.copy.revision_requested')" class="tw:mb-3">{{ __('supplier.copy.purchasing_requested_a_revision_update_unit_prices_estimated_delivery_and_validity_date_before_resub') }}</x-ui.alert>
            <div class="d-flex flex-wrap tw:gap-2">
                <x-ui.button :href="route('supplier.quotations.create', $quotation->purchaseRequisition)" variant="primary" size="sm">
                    <x-ui.icon name="square-pen" size="sm" />
                    <span>{{ __('supplier.copy.revise_quotation') }}</span>
                </x-ui.button>
                @if($conversation)
                    <x-ui.button :href="route('supplier.conversations.show', $conversation)" variant="outline" size="sm" data-open-chat-conversation="{{ $conversation->getRouteKey() }}">
                        <x-ui.icon name="message-square" size="sm" />
                        <span>{{ __('supplier.copy.open_revision_chat') }}</span>
                    </x-ui.button>
                @endif
            </div>
        @elseif($quotation->status === 'rejected')
            <x-ui.alert tone="error" :title="__('supplier.copy.quotation_not_selected')">{{ __('supplier.copy.this_quotation_was_not_selected_for_an_order_by_adasi_purchasing') }}</x-ui.alert>
        @elseif($quotation->status === 'accepted')
            @php
                $awardedCount = $quotation->items->filter(fn ($i) => $i->award && $i->award->purchase_order_id)->count();
                $isPartial = $awardedCount > 0 && $awardedCount < $quotation->items->count();
            @endphp
            @if($isPartial)
                <x-ui.alert tone="warning" :title="__('supplier.copy.partial_award')">
                    {{ trans_choice('common.final_review.awarded_items', $quotation->items->count(), ['awarded' => $awardedCount, 'total' => $quotation->items->count()]) }}
                </x-ui.alert>
            @else
                <x-ui.alert tone="success" :title="__('supplier.copy.quotation_selected')">{{ __('supplier.copy.an_official_po_will_be_issued_through_the_procurement_workflow') }}</x-ui.alert>
            @endif
            <ul class="list-unstyled tw:m-0 tw:mt-3 tw:grid tw:gap-1.5 tw:text-ui-xs">
                @foreach($quotation->items as $item)
                    <li class="d-flex flex-wrap align-items-center tw:gap-2">
                        <span class="fw-semibold tw:text-on-surface">{{ $item->prItem->material_name ?? '-' }}</span>
                        @if($item->award && $item->award->purchase_order_id)
                            <span class="ui-status-chip ui-status-chip--success">
                                <x-ui.icon name="check-circle" size="sm" class="me-1" /> {{ __('supplier.copy.awarded') }} ({{ $item->award->purchaseOrder?->po_number ?? 'PO' }})
                            </span>
                        @else
                            <span class="ui-status-chip ui-status-chip--neutral">
                                <x-ui.icon name="minus-circle" size="sm" class="me-1" /> {{ __('supplier.copy.not_awarded') }}
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @else
            <x-ui.alert tone="info" :title="__('supplier.copy.commercial_evaluation_pending')">{{ __('supplier.copy.your_quotation_has_been_recorded_and_is_waiting_for_evaluation_by_purchasing') }}</x-ui.alert>
        @endif
    </x-ui.card>

    {{-- 4. Detail Quotation --}}
    <div class="tw:min-w-0" data-quotation-section="detail">
        @include('quotations._items_readonly_table', ['quotation' => $quotation])
    </div>
</div>
@endsection
