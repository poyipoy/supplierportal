@extends('layouts.app')

@section('title', __('supplier.titles.quotation', ['number' => $quotation->purchaseRequisition->pr_number ?? '-']))
@section('page-title', __('supplier.copy.quotation_details'))

@section('content')
<div class="tw-grid tw-gap-4">
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

    <div class="tw-grid tw-items-start tw-gap-4 xl:tw-grid-cols-[minmax(0,2fr)_minmax(19rem,1fr)]">
        {{-- Main Column: Quoted Items Table --}}
        <div class="tw-grid tw-min-w-0 tw-gap-4">
            <x-ui.data-table
                :title="__('supplier.copy.material_price_breakdown')"
                :description="__('supplier.copy.commercial_values_reflect_your_quotation_s_locked_exchange_rate_snapshot')"
            >
                <table class="table table-hover align-middle mb-0 tw-text-ui-xs w-100" style="min-width: 1200px;">
                        <thead class="table-light align-middle text-center">
                            <tr class="border-bottom">
                                <th scope="col" rowspan="2" style="width: 40px;" class="text-center">#</th>
                                <th scope="col" rowspan="2" class="text-start" style="min-width: 200px;">{{ __('supplier.copy.material_requested_specs') }}</th>
                                <th scope="col" rowspan="2" class="text-start" style="min-width: 180px;">{{ __('supplier.copy.availability_offer_specs') }}</th>
                                <th scope="col" colspan="3" class="border-bottom text-center tw-bg-surface-low">{{ __('supplier.copy.quantity_weight') }}</th>
                                <th scope="col" colspan="3" class="border-bottom text-center tw-bg-surface-low">{{ __('supplier.copy.commercials') }} ({{ $quotation->currency }})</th>
                                <th scope="col" rowspan="2" class="text-end" style="min-width: 130px;">{{ __('supplier.copy.offer_est_idr') }}</th>
                                <th scope="col" rowspan="2" class="text-start" style="min-width: 130px;">{{ __('supplier.copy.notes') }}</th>
                                <th scope="col" rowspan="2" class="text-center" style="width: 54px;">MTC</th>
                            </tr>
                            <tr class="tw-text-[11px] tw-text-on-surface-variant">
                                <th scope="col" class="text-center" style="min-width: 85px;">{{ __('supplier.copy.qty') }}</th>
                                <th scope="col" class="text-end" style="min-width: 105px;">{{ __('supplier.copy.kg_per_unit') }}</th>
                                <th scope="col" class="text-end" style="min-width: 105px;">{{ __('supplier.copy.total_kg') }}</th>
                                <th scope="col" class="text-end" style="min-width: 95px;">{{ __('supplier.copy.price_kg_c55926') }}</th>
                                <th scope="col" class="text-end" style="min-width: 105px;">{{ __('supplier.copy.req_amount') }}</th>
                                <th scope="col" class="text-end" style="min-width: 110px;">{{ __('supplier.copy.offer_subtotal') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php
                                $totalAmount = 0;
                                $totalIdr = 0;
                                $rate = $quotation->exchange_rate ? (float) $quotation->exchange_rate->rate_to_idr : null;
                            @endphp
                            @foreach($quotation->items as $index => $item)
                                @php
                                    $amount = $item->resolved_amount;
                                    $requestedAmount = $item->requested_amount;
                                    $idr = $rate !== null ? $amount * $rate : null;
                                    $totalAmount += $amount;
                                    $totalIdr += $idr ?? 0;
                                    $availability = $item->availability_comparison;
                                @endphp
                                <tr>
                                    <td class="text-center tw-text-on-surface-variant ui-tabular-nums">{{ $index + 1 }}</td>
                                    <td class="text-start">
                                        <div class="fw-bold tw-text-on-surface">{{ $item->prItem->material_name }}</div>
                                        <div class="tw-text-on-surface-variant tw-text-ui-xs tw-mt-0.5">
                                            @if($item->prItem->shape)
                                                <span class="ui-status-chip ui-status-chip--neutral me-1">{{ $item->prItem->shape }}</span>
                                                <span>{{ $item->prItem->dimension_label }}</span>
                                            @else
                                                <span>-</span>
                                            @endif
                                        </div>
                                        @if($item->prItem?->remark)
                                            <div class="tw-text-on-surface-variant tw-text-[11px] tw-mt-1 tw-italic">
                                                <span class="fw-semibold">{{ __('supplier.copy.remark_a88f66') }}</span> {{ $item->prItem->remark }}
                                            </div>
                                        @endif
                                        @if($quotation->status === 'accepted')
                                            <div class="tw-mt-1.5">
                                                @if($item->award && $item->award->purchase_order_id)
                                                    <span class="ui-status-chip ui-status-chip--success" style="font-size: 11px;">
                                                        <x-ui.icon name="check-circle" size="sm" class="me-1" /> {{ __('supplier.copy.awarded') }} ({{ $item->award->purchaseOrder?->po_number ?? 'PO' }})
                                                    </span>
                                                @else
                                                    <span class="ui-status-chip ui-status-chip--neutral" style="font-size: 11px;">
                                                        <x-ui.icon name="minus-circle" size="sm" class="me-1" /> {{ __('supplier.copy.not_awarded') }}
                                                    </span>
                                                @endif
                                            </div>
                                        @endif
                                    </td>
                                    <td class="text-start">
                                        @if($availability['specification']['code'] === 'not_available')
                                            <span class="ui-status-chip ui-status-chip--error">
                                                <x-ui.icon name="x-circle" size="sm" class="me-1" /> {{ __('supplier.copy.not_available') }}
                                            </span>
                                        @elseif($availability['quantity']['code'] === 'not_specified' && $availability['specification']['code'] === 'not_specified')
                                            <span class="tw-text-outline">{{ __('supplier.copy.not_specified') }}</span>
                                        @else
                                            <div class="tw-text-ui-xs">
                                                <div class="fw-semibold tw-text-on-surface">{{ $item->available_dimension_label }}</div>
                                                <div class="d-flex flex-wrap gap-1 mt-1">
                                                    <span class="ui-status-chip ui-status-chip--neutral" style="font-size: 10px;">{{ $availability['quantity']['label'] }}</span>
                                                    <span class="ui-status-chip ui-status-chip--neutral" style="font-size: 10px;">{{ $availability['specification']['label'] }}</span>
                                                </div>
                                            </div>
                                        @endif
                                    </td>
                                    <td class="text-center ui-tabular-nums">
                                        <div class="tw-text-[11px] tw-text-on-surface-variant"><span class="tw-opacity-70">{{ __('common.final_copy.requested') }}</span> {{ $item->requested_quantity ?? '-' }}</div>
                                        <div class="fw-bold text-primary"><span class="tw-opacity-70">{{ __('supplier.copy.off') }}</span> {{ $item->is_available ? ($item->available_qty ?? '-') : '—' }}</div>
                                    </td>
                                    <td class="text-end ui-tabular-nums">
                                        <div class="tw-text-[11px] tw-text-on-surface-variant"><span class="tw-opacity-70">{{ __('common.final_copy.requested') }}</span> {{ $regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($item->requested_weight_per_unit), 'decimal') }}</div>
                                        <div class="fw-bold text-primary">
                                            <span class="tw-opacity-70">{{ __('supplier.copy.off') }}</span> {{ $item->is_available ? $regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($item->offered_weight_per_unit ?? $item->requested_weight_per_unit), 'decimal') : '—' }}
                                            @if($item->is_available && $item->is_estimated_weight)
                                                <span class="ui-status-chip ui-status-chip--warning ms-1" style="font-size: 10px;">{{ __('supplier.copy.est') }}</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="text-end ui-tabular-nums">
                                        <div class="tw-text-[11px] tw-text-on-surface-variant"><span class="tw-opacity-70">{{ __('common.final_copy.requested') }}</span> {{ $regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($item->requested_total_weight), 'decimal') }}</div>
                                        <div class="fw-bold text-primary"><span class="tw-opacity-70">{{ __('supplier.copy.off') }}</span> {{ $item->is_available ? $regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($item->offered_total_weight), 'decimal') : '—' }}</div>
                                    </td>
                                    <td class="text-end fw-bold ui-tabular-nums">
                                        {{ $regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($item->price_per_kg, 4), 'decimal') }}
                                    </td>
                                    <td class="text-end tw-text-on-surface-variant ui-tabular-nums">{{ $item->is_available ? $regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($requestedAmount), 'decimal') : '—' }}</td>
                                    <td class="text-end fw-semibold text-primary ui-tabular-nums" data-offer-amount="{{ $item->is_available ? \App\Support\NumberFormat::maxDecimals($amount) : '' }}">
                                        {{ $item->is_available ? $regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($amount), 'decimal') : '—' }}
                                    </td>
                                    <td class="text-end fw-bold tw-text-on-surface ui-tabular-nums">
                                        {{ $idr !== null && $item->is_available ? 'Rp '.$regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($idr), 'decimal') : '—' }}
                                    </td>
                                    <td class="text-start tw-text-on-surface-variant tw-text-ui-xs">
                                        @if($item->notes)
                                            <div class="text-truncate" style="max-width: 140px;" title="{{ $item->notes }}">
                                                {{ $item->notes }}
                                            </div>
                                        @else
                                            <span class="tw-text-outline">—</span>
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        @if($item->attachments->isNotEmpty())
                                            @foreach($item->attachments as $attachment)
                                                <x-ui.icon-button :href="route('attachments.show', $attachment)" icon="paperclip" :label="__('supplier.page.open_attachment', ['file' => $attachment->file_name])" size="sm" target="_blank" />
                                            @endforeach
                                        @else
                                            <span class="tw-text-outline">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="table-light fw-bold border-top">
                            <tr>
                                <td colspan="7" class="text-end tw-text-on-surface tw-uppercase">{{ __('supplier.copy.total_offer_amount') }}</td>
                                <td class="text-end tw-text-on-surface-variant ui-tabular-nums">{{ $regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($quotation->items->sum('requested_amount')), 'decimal') }}</td>
                                <td class="text-end text-primary ui-tabular-nums">{{ $regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($totalAmount), 'decimal') }} {{ $quotation->currency }}</td>
                                <td class="text-end text-primary ui-tabular-nums fs-6">{{ $rate !== null ? 'Rp '.$regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($totalIdr), 'decimal') : '—' }}</td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    </table>
            </x-ui.data-table>
        </div>

        {{-- Sidebar Column: Commercial Info & Feedback --}}
        <aside class="tw-grid tw-gap-4">
            <x-ui.card :title="__('supplier.copy.quotation_parameters')">
                <div class="tw-grid tw-gap-2.5">
                    <div class="tw-p-2.5 tw-bg-surface-low border rounded">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('supplier.copy.date_submitted') }}</div>
                        <div class="fw-semibold tw-text-on-surface tw-text-ui-sm tw-mt-0.5">
                            {{ $quotation->submitted_at ? $regionalFormatter->timestamp($quotation->submitted_at, 'datetime_comma') : '-' }}
                        </div>
                    </div>
                    <div class="tw-p-2.5 tw-bg-surface-low border rounded">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('supplier.copy.exchange_rate_snapshot') }}</div>
                        <div class="fw-bold tw-text-on-surface tw-text-ui-sm tw-mt-0.5">
                            @if($quotation->exchange_rate)
                                1 {{ $quotation->currency }} = Rp {{ $regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($quotation->exchange_rate->rate_to_idr), 'decimal') }}
                            @else
                                -
                            @endif
                        </div>
                    </div>
                    <div class="tw-p-2.5 tw-bg-surface-low border rounded">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('supplier.copy.supplier_estimated_ready_dispatch_date') }}</div>
                        <div class="fw-semibold tw-text-on-surface tw-text-ui-sm tw-mt-0.5">
                            {{ $quotation->estimated_delivery ? $regionalFormatter->date(\Carbon\Carbon::parse($quotation->estimated_delivery), 'full_human') : '-' }}
                        </div>
                    </div>
                    <div class="tw-p-2.5 tw-bg-surface-low border rounded">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('supplier.copy.valid_until') }}</div>
                        <div class="fw-semibold tw-text-on-surface tw-text-ui-sm tw-mt-0.5">
                            {{ $quotation->validity_period ? $regionalFormatter->date(\Carbon\Carbon::parse($quotation->validity_period), 'full_human') : '-' }}
                        </div>
                    </div>
                    <div class="tw-p-2.5 tw-bg-surface-low border rounded">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('supplier.copy.payment_terms') }}</div>
                        <div class="tw-text-on-surface tw-text-ui-xs tw-mt-0.5">{{ $quotation->payment_terms ?: __('supplier.copy.standard_terms') }}</div>
                    </div>
                    @if($quotation->general_notes)
                        <div class="tw-p-2.5 tw-bg-surface-low border rounded">
                            <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('supplier.copy.general_notes') }}</div>
                            <div class="tw-text-on-surface tw-text-ui-xs tw-mt-0.5">{{ $quotation->general_notes }}</div>
                        </div>
                    @endif
                    @if($quotation->reviewer_notes)
                        <div class="tw-p-2.5 bg-warning-subtle border border-warning-subtle rounded text-warning-emphasis">
                                    <div class="fw-bold tw-text-ui-xs tw-uppercase tw-mb-0.5"><x-ui.icon name="square-pen" size="sm" class="me-1" />{{ __('supplier.copy.purchasing_reviewer_notes') }}</div>
                            <div class="tw-text-ui-xs">{{ $quotation->reviewer_notes }}</div>
                        </div>
                    @endif
                </div>
            </x-ui.card>

            {{-- Workflow Status Card --}}
            <x-ui.card :title="__('supplier.copy.status_and_follow_up')">
                @if($quotation->status === 'revision_requested')
                    <x-ui.alert tone="warning" :title="__('supplier.copy.revision_requested')" class="tw-mb-3">{{ __('supplier.copy.purchasing_requested_a_revision_update_unit_prices_estimated_delivery_and_validity_date_before_resub') }}</x-ui.alert>
                    <div class="tw-grid tw-gap-2">
                        <x-ui.button :href="route('supplier.quotations.create', $quotation->purchaseRequisition)" variant="primary" size="sm" class="tw-w-full">
                            <x-ui.icon name="square-pen" size="sm" />
                            <span>{{ __('supplier.copy.revise_quotation') }}</span>
                        </x-ui.button>
                        @if($conversation)
                            <x-ui.button :href="route('supplier.conversations.show', $conversation)" variant="outline" size="sm" class="tw-w-full" data-open-chat-conversation="{{ $conversation->getRouteKey() }}">
                                <x-ui.icon name="message-square" size="sm" />
                                <span>{{ __('supplier.copy.open_revision_chat') }}</span>
                            </x-ui.button>
                        @endif
                    </div>
                @elseif($quotation->status === 'rejected')
                    <x-ui.alert tone="error" :title="__('supplier.copy.quotation_not_selected')">{{ __('supplier.copy.this_quotation_was_not_selected_for_an_order_by_adasi_purchasing') }}</x-ui.alert>
                @elseif($quotation->status === 'accepted')
                    @php
                        $awardedCount = $quotation->items->filter(fn($i) => $i->award && $i->award->purchase_order_id)->count();
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
            </x-ui.card>
        </aside>
    </div>
</div>
@endsection
