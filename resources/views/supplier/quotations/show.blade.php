@extends('layouts.app')

@section('title', __('supplier.titles.quotation', ['number' => $quotation->purchaseRequisition->pr_number ?? '-']))
@section('page-title', __('supplier.copy.quotation_details'))

@section('content')
@php
    $requisition = $quotation->purchaseRequisition;
    // Compact summary: plain label/value pairs so the item table starts in the first viewport.
    $dtClass = 'tw:text-ui-xs tw:font-semibold tw:uppercase tw:text-on-surface-variant';
    $ddClass = 'tw:m-0 tw:mt-0.5 tw:text-ui-sm tw:font-semibold tw:text-on-surface';
    $longText = static fn (?string $text): bool => mb_strlen((string) $text) > 140;
@endphp
<div class="tw:grid tw:gap-4">
    {{-- Breadcrumb & Compact Page Header --}}
    <x-ui.breadcrumb :items="[
        __('purchasing.breadcrumbs.dashboard') => route('supplier.dashboard'),
        __('purchasing.breadcrumbs.quotation_periods') => route('supplier.quotations.index'),
        ($requisition->period->display_label ?? __('purchasing.breadcrumbs.requisitions')) => route('supplier.quotations.period', $requisition->period_id),
        __('purchasing.breadcrumbs.quotation_details') => null,
    ]" />

    <x-ui.page-header
        :title="__('purchasing.closure.quotation_title', ['number' => $requisition->pr_number ?? '-'])"
        :eyebrow="__('supplier.copy.submitted_quotation')"
        :description="__('supplier.copy.review_your_submitted_prices_availability_parameters_supporting_mtc_files_and_purchasing_evaluation')"
    >
        <x-slot:actions>
            <x-status-badge type="quotation" :status="$quotation->status" size="lg" />
            <x-ui.button href="#quotation-items" variant="outline" size="sm" data-jump-to-items>
                <x-ui.icon name="arrow-down" />
                <span>{{ __('supplier.copy.view_items', ['count' => $quotation->items->count()]) }}</span>
            </x-ui.button>
            <x-ui.button :href="route('supplier.export.quotations.detail', $quotation)" variant="outline" size="sm" data-async-export data-export-source-singular="{{ __('exports.sources.quotation') }}" data-export-source-plural="{{ __('exports.sources.quotations') }}" data-export-source-count="1" data-export-filtered="false" data-export-row-label="{{ __('supplier.copy.quotation_item_rows') }}" data-export-row-explanation="{{ __('supplier.copy.each_quotation_item_will_be_written_as_a_separate_excel_row') }}">
                <x-ui.icon name="file-spreadsheet" />
                <span>{{ __('supplier.copy.export_excel') }}</span>
            </x-ui.button>
            <x-ui.button :href="route('supplier.quotations.period', $requisition->period_id)" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" />
                <span>{{ __('supplier.copy.back_to_requisitions') }}</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Decision 24: Procurement Context → Commercial Terms & Logistics → Status & Follow-up → Detail Quotation --}}

    {{-- 1. Procurement Context --}}
    <x-ui.card :title="__('procurement.quotation.procurement_context')" padding="sm" data-quotation-section="context">
        <dl class="tw:m-0 tw:grid tw:grid-cols-2 tw:gap-x-6 tw:gap-y-3 tw:lg:grid-cols-4">
            <div>
                <dt class="{{ $dtClass }}">{{ __('supplier.copy.procurement_period') }}</dt>
                <dd class="{{ $ddClass }}">{{ $requisition->period->display_label ?? '-' }}</dd>
            </div>
            <div>
                <dt class="{{ $dtClass }}">{{ __('supplier.copy.requisition_reference') }}</dt>
                <dd class="{{ $ddClass }} text-primary">{{ $requisition->pr_number ?? '-' }}</dd>
            </div>
            <div>
                <dt class="{{ $dtClass }}">{{ __('procurement.logistics.port_of_discharge') }}</dt>
                <dd class="{{ $ddClass }}">{{ \App\Support\Procurement\LogisticsTerms::portLabel($requisition->port_of_discharge) }}</dd>
            </div>
            <div>
                <dt class="{{ $dtClass }}">{{ __('procurement.logistics.incoterm') }}</dt>
                <dd class="{{ $ddClass }}">{{ \App\Support\Procurement\LogisticsTerms::incotermLabel($requisition->incoterm) }}</dd>
            </div>
            @include('supplier.quotations._summary_note', [
                'label' => __('supplier.copy.purchasing_instructions'),
                'text' => $requisition->notes ?: __('supplier.copy.no_additional_notes_provided'),
                'clamp' => $longText($requisition->notes),
            ])
        </dl>
    </x-ui.card>

    {{-- 2. Commercial Terms & Logistics --}}
    <x-ui.card :title="__('procurement.quotation.commercial_terms')" padding="sm" data-quotation-section="terms">
        <dl class="tw:m-0 tw:grid tw:grid-cols-2 tw:gap-x-6 tw:gap-y-3 tw:sm:grid-cols-3 tw:lg:grid-cols-5">
            <div>
                <dt class="{{ $dtClass }}">{{ __('supplier.copy.date_submitted') }}</dt>
                <dd class="{{ $ddClass }}">{{ $quotation->submitted_at ? $regionalFormatter->timestamp($quotation->submitted_at, 'datetime_comma') : '-' }}</dd>
            </div>
            <div>
                <dt class="{{ $dtClass }}">{{ __('supplier.copy.exchange_rate_snapshot') }}</dt>
                <dd class="{{ $ddClass }} ui-tabular-nums">
                    @if($quotation->exchange_rate)
                        1 {{ $quotation->currency }} = Rp {{ $regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($quotation->exchange_rate->rate_to_idr), 'decimal') }}
                    @else
                        -
                    @endif
                </dd>
            </div>
            <div>
                <dt class="{{ $dtClass }}">{{ __('supplier.copy.supplier_estimate_delivery') }}</dt>
                <dd class="{{ $ddClass }}">{{ $quotation->estimated_delivery ? $regionalFormatter->date(\Carbon\Carbon::parse($quotation->estimated_delivery), 'full_human') : '-' }}</dd>
            </div>
            <div>
                <dt class="{{ $dtClass }}">{{ __('supplier.copy.valid_until') }}</dt>
                <dd class="{{ $ddClass }}">{{ $quotation->validity_period ? $regionalFormatter->date(\Carbon\Carbon::parse($quotation->validity_period), 'full_human') : '-' }}</dd>
            </div>
            <div>
                <dt class="{{ $dtClass }}">{{ __('supplier.copy.payment_terms') }}</dt>
                <dd class="{{ $ddClass }}">{{ $quotation->payment_terms ?: __('supplier.copy.standard_terms') }}</dd>
            </div>
            @if($quotation->general_notes)
                @include('supplier.quotations._summary_note', [
                    'label' => __('supplier.copy.general_notes'),
                    'text' => $quotation->general_notes,
                    'clamp' => $longText($quotation->general_notes),
                ])
            @endif
        </dl>
    </x-ui.card>

    {{-- 3. Status & Follow-up: a single banner; per-item award moved into the item table. --}}
    <section class="tw:grid tw:gap-2" data-quotation-section="status" aria-labelledby="quotation-status-heading">
        <h2 id="quotation-status-heading" class="visually-hidden">{{ __('procurement.quotation.status_follow_up') }}</h2>
        @if($quotation->reviewer_notes)
            <div class="tw:p-2.5 bg-warning-subtle border border-warning-subtle rounded text-warning-emphasis">
                <div class="fw-bold tw:text-ui-xs tw:uppercase tw:mb-0.5"><x-ui.icon name="square-pen" size="sm" class="me-1" />{{ __('supplier.copy.purchasing_reviewer_notes') }}</div>
                <div class="tw:text-ui-xs">{{ $quotation->reviewer_notes }}</div>
            </div>
        @endif

        @if($quotation->status === 'revision_requested')
            <x-ui.alert tone="warning" :title="__('supplier.copy.revision_requested')">{{ __('supplier.copy.purchasing_requested_a_revision_update_unit_prices_estimated_delivery_and_validity_date_before_resub') }}</x-ui.alert>
            <div class="d-flex flex-wrap tw:gap-2">
                <x-ui.button :href="route('supplier.quotations.create', $requisition)" variant="primary" size="sm">
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
        @else
            <x-ui.alert tone="info" :title="__('supplier.copy.commercial_evaluation_pending')">{{ __('supplier.copy.your_quotation_has_been_recorded_and_is_waiting_for_evaluation_by_purchasing') }}</x-ui.alert>
        @endif
    </section>

    {{-- 4. Detail Quotation --}}
    <div id="quotation-items" class="tw:min-w-0 tw:scroll-mt-20" data-quotation-section="detail">
        @include('quotations._items_readonly_table', ['quotation' => $quotation, 'showAward' => $quotation->status === 'accepted'])
    </div>
</div>
@endsection
