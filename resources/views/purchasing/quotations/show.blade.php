@extends('layouts.app')
@section('title', __('supplier.titles.quotation', ['number' => $quotation->purchaseRequisition->pr_number ?? '-']))
@section('page-title', __('purchasing.copy.quotation_details'))

@section('content')
@php
    $relatedPrBaseUrl = route('purchasing.requisitions.show', $quotation->purchaseRequisition);
    $relatedPrPath = parse_url($relatedPrBaseUrl, PHP_URL_PATH);
    $returnUrl = request(\App\Support\PurchasingNavigation::RETURN_URL_KEY);
    $returnPath = is_string($returnUrl) ? parse_url($returnUrl, PHP_URL_PATH) : null;
    $relatedPrUrl = (
        $returnPath === $relatedPrPath
        && \App\Support\PurchasingNavigation::isSafeUrl($returnUrl)
    )
        ? $returnUrl
        : route('purchasing.requisitions.show', [
            $quotation->purchaseRequisition,
            \App\Support\PurchasingNavigation::RETURN_URL_KEY => \App\Support\PurchasingNavigation::backUrl('purchasing.quotations.index'),
        ]);
    $validityMeta = \App\Support\StatusHelper::quotationValidityMeta($quotation->validity_period, $quotation->status);
@endphp

<div class="tw-grid tw-gap-4">
    {{-- Breadcrumb & Compact Page Header --}}
    <x-ui.breadcrumb :items="[
        __('purchasing.breadcrumbs.dashboard') => route('purchasing.dashboard'),
        __('purchasing.breadcrumbs.quotation_list') => route('purchasing.quotations.index'),
        __('purchasing.breadcrumbs.quotation_details') => null,
    ]" />

    <x-ui.page-header
        :title="__('purchasing.closure.quotation_title', ['number' => $quotation->purchaseRequisition->pr_number ?? '-'])"
        :eyebrow="__('purchasing.copy.commercial_evaluation')"
        :description="__('purchasing.page.quotation_help', ['reference' => $quotation->purchaseRequisition->pr_number ?? __('purchasing.copy.quotation'), 'supplier' => $supplierDisplayName])"
    >
        <x-slot:actions>
            <x-status-badge type="quotation" :status="$quotation->status" size="lg" />
            <x-ui.button :href="route('purchasing.export.quotations.detail', $quotation)" variant="outline" size="sm" data-async-export data-export-source-singular="{{ __('exports.sources.quotation') }}" data-export-source-plural="{{ __('exports.sources.quotations') }}" data-export-source-count="1" data-export-filtered="false" data-export-row-label="{{ __('purchasing.copy.quotation_item_rows') }}" data-export-row-explanation="{{ __('purchasing.copy.each_quotation_item_will_be_written_as_a_separate_excel_row') }}">
                <x-ui.icon name="file-spreadsheet" />
                <span>{{ __('purchasing.copy.export_excel') }}</span>
            </x-ui.button>
            <x-ui.button :href="\App\Support\PurchasingNavigation::backUrl('purchasing.quotations.index')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" />
                <span>{{ __('purchasing.copy.back_to_quotations') }}</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="tw-grid tw-items-start tw-gap-4 xl:tw-grid-cols-[minmax(0,2fr)_minmax(19rem,1fr)]">
        {{-- Main Column --}}
        <div class="tw-grid tw-min-w-0 tw-gap-4">
            {{-- Info Quotation Card --}}
            <x-ui.card :title="__('purchasing.copy.commercial_summary')">
                <div class="tw-grid tw-gap-3 sm:tw-grid-cols-2 lg:tw-grid-cols-3">
                    <div class="tw-p-2.5 tw-bg-surface-low border rounded">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('purchasing.copy.pr_number') }}</div>
                        <div class="fw-bold text-primary tw-text-ui-sm tw-mt-0.5">{{ $quotation->purchaseRequisition->pr_number ?? '-' }}</div>
                    </div>
                    <div class="tw-p-2.5 tw-bg-surface-low border rounded">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('purchasing.copy.procurement_period') }}</div>
                        <div class="fw-semibold tw-text-on-surface tw-text-ui-sm tw-mt-0.5">{{ $quotation->purchaseRequisition->period->display_label ?? $quotation->purchaseRequisition->period->name ?? '-' }}</div>
                    </div>
                    <div class="tw-p-2.5 tw-bg-surface-low border rounded">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('purchasing.copy.currency') }}</div>
                        <div class="fw-bold tw-text-on-surface tw-text-ui-sm tw-mt-0.5"><span class="ui-status-chip ui-status-chip--neutral">{{ $quotation->currency }}</span></div>
                    </div>
                    <div class="tw-p-2.5 tw-bg-surface-low border rounded">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('purchasing.copy.date_submitted') }}</div>
                        <div class="fw-semibold tw-text-on-surface tw-text-ui-sm tw-mt-0.5">{{ $quotation->submitted_at ? $regionalFormatter->timestamp($quotation->submitted_at, 'datetime_comma') : '-' }}</div>
                    </div>
                    <div class="tw-p-2.5 tw-bg-surface-low border rounded">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('purchasing.copy.supplier_estimated_ready_dispatch_date') }}</div>
                        <div class="fw-semibold tw-text-on-surface tw-text-ui-sm tw-mt-0.5">{{ $quotation->estimated_delivery ? $regionalFormatter->date($quotation->estimated_delivery) : '-' }}</div>
                    </div>
                    <div class="tw-p-2.5 tw-bg-surface-low border rounded">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('purchasing.copy.quotation_valid_until') }}</div>
                        <div class="fw-semibold tw-text-on-surface tw-text-ui-sm tw-mt-0.5">
                            @if($quotation->validity_period)
                                {{ $regionalFormatter->date($quotation->validity_period) }}
                                {!! \App\Support\StatusHelper::badgeWithTooltip($validityMeta['class'] . ' ms-1', $validityMeta['label'], $validityMeta['description']) !!}
                            @else
                                {!! \App\Support\StatusHelper::badgeWithTooltip($validityMeta['class'], $validityMeta['label'], $validityMeta['description']) !!}
                            @endif
                        </div>
                    </div>
                    <div class="sm:tw-col-span-2 lg:tw-col-span-3 tw-p-2.5 tw-bg-surface-low border rounded">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('purchasing.copy.payment_terms_conditions') }}</div>
                        <div class="tw-text-on-surface tw-text-ui-sm tw-mt-0.5">{{ $quotation->payment_terms ?? '-' }}</div>
                    </div>
                </div>

                @if($quotation->status === 'revision_requested')
                    <x-ui.alert tone="warning" :title="__('purchasing.copy.revision_requested')" class="tw-mt-3">{{ __('purchasing.copy.the_supplier_must_resubmit_the_quotation_with_updated_pricing_and_validity') }}</x-ui.alert>
                @elseif($quotation->isExpired())
                    <x-ui.alert tone="error" :title="__('purchasing.copy.quotation_expired')" class="tw-mt-3">{{ __('purchasing.copy.the_supplier_must_resubmit_a_valid_offer_before_a_po_can_be_created') }}</x-ui.alert>
                @endif

                @if($quotation->general_notes)
                    <div class="mt-3 tw-p-2.5 tw-bg-surface-low border rounded tw-text-ui-xs tw-text-on-surface">
                        <span class="fw-bold d-block tw-mb-0.5">{{ __('purchasing.copy.supplier_notes') }}</span>
                        {{ $quotation->general_notes }}
                    </div>
                @endif

                @if($quotation->reviewer_notes)
                    <div class="mt-3 tw-p-2.5 bg-warning-subtle border border-warning-subtle rounded tw-text-ui-xs text-warning-emphasis">
                        <span class="fw-bold d-block tw-mb-0.5"><x-ui.icon name="square-pen" size="sm" class="me-1" />{{ __('purchasing.copy.purchasing_reviewer_notes') }}</span>
                        {{ $quotation->reviewer_notes }}
                    </div>
                @endif
            </x-ui.card>

            {{-- Material Price Breakdown Table --}}
            <x-ui.data-table
                :title="__('purchasing.page.material_price_count', ['count' => $quotation->items->count()])"
                :description="__('purchasing.copy.requested_specifications_supplier_availability_and_exchange_rate_snapshot_in_one_review_table')"
            >
                <table class="table table-hover align-middle mb-0 tw-text-ui-xs w-100">
                    <thead class="table-light text-center">
                        <tr>
                            <th scope="col" style="width: 35px;">{{ __('purchasing.copy.no') }}</th>
                            <th scope="col" class="text-start">{{ __('purchasing.copy.material') }}</th>
                            <th scope="col">{{ __('purchasing.copy.requested_vs_offered') }}</th>
                            <th scope="col">{{ __('purchasing.copy.qty') }}<br><span class="fw-normal">{{ __('purchasing.copy.requested_offer') }}</span></th>
                            <th scope="col" class="text-end">{{ __('purchasing.copy.weight_unit_kg_e8ab70') }}<br><span class="fw-normal">{{ __('purchasing.copy.requested_offer') }}</span></th>
                            <th scope="col" class="text-end">{{ __('purchasing.copy.total_kg') }}<br><span class="fw-normal">{{ __('purchasing.copy.requested_offer') }}</span></th>
                            <th scope="col" class="text-end">{{ __('purchasing.copy.price_kg') }} ({{ $quotation->currency }})</th>
                            <th scope="col" class="text-end">{{ __('purchasing.copy.requested_amount') }}</th>
                            <th scope="col" class="text-end">{{ __('purchasing.copy.offer_amount') }}</th>
                            <th scope="col" class="text-end">{{ __('purchasing.copy.offer_est_idr') }}</th>
                            <th scope="col">{{ __('purchasing.copy.notes') }}</th>
                            <th scope="col" class="text-center" style="width: 50px;">MTC</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php
                            $totalOriginal = 0;
                            $totalIdr = 0;
                            $rateValue = $quotationRate ? (float) $quotationRate->rate_to_idr : null;
                        @endphp
                        @foreach($quotation->items as $idx => $item)
                            @php
                                $quantity = $item->prItem ? $item->prItem->quantity_value : 1;
                                $weight = $item->prItem ? (float)$item->prItem->weight_needed : 0;
                                $totalWeight = $item->prItem ? (float)$item->prItem->total_weight : $weight;
                                $pricePerKg = $item->price_per_kg === null ? null : (float) $item->price_per_kg;
                                $amount = $item->resolved_amount;
                                $requestedAmount = $item->requested_amount;
                                $amountIdr = $rateValue !== null ? $amount * $rateValue : null;
                                $totalOriginal += $amount;
                                $totalIdr += $amountIdr ?? 0;
                                $availability = $item->availability_comparison;
                            @endphp
                            <tr>
                                <td class="text-center tw-text-on-surface-variant ui-tabular-nums">{{ $idx + 1 }}</td>
                                <td>
                                    <div class="fw-bold tw-text-on-surface">{{ $item->prItem->material_name ?? '-' }}</div>
                                    @if($item->prItem && $item->prItem->shape)
                                        <span class="ui-status-chip ui-status-chip--neutral tw-mt-0.5">{{ $item->prItem->shape }}</span>
                                        <div class="tw-text-on-surface-variant tw-text-ui-xs tw-mt-0.5">{{ $item->prItem->dimension_label }}</div>
                                    @endif
                                    @if($item->prItem?->remark)
                                        <div class="tw-text-on-surface-variant tw-text-ui-xs tw-mt-0.5">{{ __('common.final_copy.remark') }} {{ $item->prItem->remark }}</div>
                                    @endif
                                </td>
                                <td class="text-start tw-min-w-[210px]">
                                    <div class="border rounded tw-p-1.5 tw-bg-surface-low mb-1 tw-text-ui-xs">
                                        <span class="tw-text-on-surface-variant fw-semibold d-block">{{ __('purchasing.copy.requested') }}</span>
                                        <span class="fw-medium">{{ __('common.fields.qty') }} {{ $regionalFormatter->number(number_format($quantity, 0), 'international') }} &bull; {{ $item->prItem?->dimension_label ?? '-' }}</span>
                                    </div>
                                    <div class="border rounded tw-p-1.5 tw-bg-surface tw-text-ui-xs">
                                        <span class="text-primary fw-semibold d-block">{{ __('purchasing.copy.offered') }}</span>
                                        @if($availability['specification']['code'] === 'not_available')
                                            <span class="ui-status-chip ui-status-chip--error">{{ __('purchasing.copy.not_available') }}</span>
                                        @else
                                            <span class="fw-medium">{{ __('common.fields.qty') }} {{ $item->available_qty ?? '-' }} &bull; {{ $item->available_dimension_label }}</span>
                                            @if($item->is_estimated_weight)
                                                <div class="tw-mt-1"><span class="ui-status-chip ui-status-chip--warning">{{ __('purchasing.copy.est_weight') }}</span></div>
                                            @endif
                                        @endif
                                        <div class="d-flex flex-wrap gap-1 mt-1">
                                            <span @class([
                                                'badge',
                                                'bg-secondary' => in_array($availability['quantity']['code'], ['not_specified', 'not_available'], true),
                                                'bg-warning text-dark' => $availability['quantity']['code'] === 'shortage',
                                                'bg-success' => in_array($availability['quantity']['code'], ['match', 'surplus'], true),
                                            ]) style="font-size: var(--ui-font-size-xs);">{{ $availability['quantity']['label'] }}</span>
                                            <span @class([
                                                'badge',
                                                'bg-secondary' => in_array($availability['specification']['code'], ['not_specified', 'not_available'], true),
                                                'bg-warning text-dark' => $availability['specification']['code'] === 'different',
                                                'bg-success' => in_array($availability['specification']['code'], ['exact', 'within_range'], true),
                                            ]) style="font-size: var(--ui-font-size-xs);">{{ $availability['specification']['label'] }}</span>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-center ui-tabular-nums">
                                    <div>{{ $quantity }}</div>
                                    <div class="fw-bold text-primary">{{ $item->is_available ? ($item->available_qty ?? '-') : '—' }}</div>
                                </td>
                                <td class="text-end ui-tabular-nums">
                                    <div class="tw-text-on-surface-variant">{{ $regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($weight), 'decimal') }}</div>
                                    <div class="fw-bold text-primary">{{ $item->is_available ? $regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($item->offered_weight_per_unit ?? $weight), 'decimal') : '—' }}</div>
                                </td>
                                <td class="text-end ui-tabular-nums">
                                    <div class="tw-text-on-surface-variant">{{ $regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($totalWeight), 'decimal') }}</div>
                                    <div class="fw-bold text-primary">{{ $item->is_available ? $regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($item->offered_total_weight), 'decimal') : '—' }}</div>
                                </td>
                                <td class="text-end fw-bold ui-tabular-nums">{{ $regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($pricePerKg, 4), 'decimal') }}</td>
                                <td class="text-end ui-tabular-nums">{{ $item->is_available ? $regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($requestedAmount), 'decimal') : '—' }}</td>
                                <td class="text-end fw-semibold ui-tabular-nums" data-offer-amount="{{ $item->is_available ? \App\Support\NumberFormat::maxDecimals($amount) : '' }}">{{ $item->is_available ? $regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($amount), 'decimal') : '—' }}</td>
                                <td class="text-end fw-bold tw-text-on-surface ui-tabular-nums">
                                    {{ $amountIdr !== null && $item->is_available ? 'Rp '.$regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($amountIdr), 'decimal') : '—' }}
                                </td>
                                <td class="text-start tw-text-on-surface-variant">{{ $item->notes ?: '—' }}</td>
                                <td class="text-center">
                                    @if($item->attachments->isNotEmpty())
                                        @foreach($item->attachments as $attachment)
                                            <x-ui.icon-button :href="route('attachments.show', $attachment)" icon="paperclip" :label="__('common.closure.attachment_open', ['name' => $attachment->file_name])" size="sm" target="_blank" />
                                        @endforeach
                                    @else
                                        <span class="tw-text-outline">-</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="table-light fw-bold border-top">
                        <tr>
                            <td colspan="8" class="text-end tw-text-on-surface">{{ __('purchasing.page.offer_total', ['currency' => $quotation->currency]) }}</td>
                            <td class="text-end tw-text-on-surface ui-tabular-nums">{{ $regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($totalOriginal), 'decimal') }}</td>
                            <td class="text-end text-primary ui-tabular-nums fs-6">
                                {{ $rateValue !== null ? 'Rp '.$regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($totalIdr), 'decimal') : '—' }}
                            </td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </x-ui.data-table>
        </div>

        {{-- Sidebar Column --}}
        <aside class="tw-grid tw-gap-4">
            {{-- Supplier Details Card --}}
            <x-ui.card :title="__('purchasing.copy.supplier_profile')">
                <div class="fw-bold tw-text-on-surface tw-text-ui-sm">{{ $supplierDisplayName }}</div>
                <div class="tw-text-on-surface-variant tw-text-ui-xs tw-mt-0.5">{{ $quotation->supplier->email }}</div>
                @if($quotation->supplier->supplier)
                    <div class="tw-text-on-surface-variant tw-text-ui-xs mt-2 pt-2 border-top">
                        <div class="mb-1"><x-ui.icon name="map-pin" size="sm" class="me-1 tw-text-outline" />{{ $quotation->supplier->supplier->address ?? '-' }}</div>
                        <div><x-ui.icon name="phone" size="sm" class="me-1 tw-text-outline" />{{ $quotation->supplier->supplier->phone ?? '-' }}</div>
                    </div>
                @endif
            </x-ui.card>

            {{-- Chat Action Card --}}
            <x-ui.card :title="__('purchasing.copy.direct_negotiation')">
                @if($chatAvailable)
                    <form action="{{ route('purchasing.conversations.start.pr', ['pr_id' => $quotation->purchaseRequisition, 'supplier_id' => $quotation->supplier]) }}" method="POST" data-chat-start-form data-managed-submit>
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
                    <div class="tw-text-on-surface-variant tw-text-ui-xs mt-2">
                        {{ __('purchasing.copy.clarify_specifications_lead_times_mtc_certificates_or_price_before_creating_a_po') }}
                    </div>
                @else
                    <div class="tw-text-on-surface-variant tw-text-ui-xs">
                        {{ __('purchasing.copy.chat_is_accessible_once_quotation_is_submitted_by_supplier') }}
                    </div>
                @endif
            </x-ui.card>

            {{-- Conversion Rate Snapshot --}}
            <x-ui.card :title="__('purchasing.copy.conversion_rate_snapshot')" :description="__('purchasing.copy.applied_quotation_exchange_rate_snapshot')">
                @if($quotationRate)
                    <div class="p-3 tw-bg-surface-low border rounded text-center">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ $quotation->currency }} → IDR</div>
                        <div class="fw-bold text-primary fs-5 mt-1">Rp {{ $regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($quotationRate->rate_to_idr), 'decimal') }}</div>
                        <div class="tw-text-outline tw-text-ui-xs tw-mt-0.5">{{ __('common.final_review.snapshot_date', ['date' => $regionalFormatter->date($quotationRate->valid_from)]) }}</div>
                    </div>
                @else
                    <x-ui.alert tone="warning" :title="__('purchasing.copy.exchange_rate_unavailable')">{{ __('common.final_review.missing_snapshot', ['currency' => $quotation->currency]) }}</x-ui.alert>
                @endif
            </x-ui.card>

            {{-- Review Actions Card --}}
            <x-ui.card :title="__('purchasing.copy.commercial_review_actions')" :description="__('purchasing.copy.actions_based_on_quotation_state')">
                @if(in_array($quotation->status, ['submitted', 'all_unavailable'], true) && $quotation->purchaseOrders->isEmpty() && !$quotation->isExpired())
                    @if($hasAvailableItems && $quotation->status === 'submitted')
                        <form action="{{ route('purchasing.quotations.accept', $quotation) }}" method="POST" class="tw-mb-2.5">
                            @csrf
                            <x-ui.button type="submit" size="sm" class="tw-w-full">
                                <x-slot:leading><x-ui.icon name="circle-check" /></x-slot:leading>
                                {{ __('purchasing.copy.accept_quotation') }}
                            </x-ui.button>
                        </form>
                    @else
                        <x-ui.alert tone="warning" :title="__('purchasing.copy.no_available_materials')" class="tw-mb-2.5">
                            {{ __('purchasing.copy.all_requested_materials_in_this_quotation_are_marked_as_not_available') }}
                        </x-ui.alert>
                    @endif

                    <form action="{{ route('purchasing.quotations.request-revision', $quotation) }}" method="POST" class="tw-mb-2.5" id="requestRevisionForm" data-managed-submit>
                        @csrf
                        <input type="hidden" name="return_url" value="{{ request('return_url') }}">
                        <div class="mb-2">
                            <label class="form-label small fw-semibold tw-text-on-surface mb-1" for="revisionNote">{{ __('purchasing.copy.revision_notes') }}</label>
                            <textarea name="revision_note" id="revisionNote" class="form-control form-control-sm" rows="2" maxlength="1000" required placeholder="{{ __('purchasing.copy.specify_what_needs_to_be_revised_price_validity_lead_time') }}"></textarea>
                        </div>
                        <x-ui.button type="submit" variant="outline" size="sm" class="tw-w-full">
                            <x-slot:leading><x-ui.icon name="rotate-ccw" /></x-slot:leading>
                            {{ __('purchasing.copy.request_revision') }}
                        </x-ui.button>
                    </form>

                    @if($hasAvailableItems)
                        <form action="{{ route('purchasing.quotations.reject', $quotation) }}" method="POST" class="tw-mb-2.5">
                            @csrf
                            <div class="mb-2">
                                <label class="form-label small fw-semibold tw-text-on-surface mb-1" for="reviewerNotes">{{ __('purchasing.copy.rejection_reason') }}</label>
                                <textarea name="reviewer_notes" id="reviewerNotes" class="form-control form-control-sm" rows="2" maxlength="1000" required placeholder="{{ __('purchasing.copy.state_reason_for_rejecting_offer') }}"></textarea>
                            </div>
                            <x-ui.button type="submit" variant="danger" size="sm" class="tw-w-full">
                                <x-slot:leading><x-ui.icon name="circle-x" /></x-slot:leading>
                                {{ __('purchasing.copy.reject_quotation') }}
                            </x-ui.button>
                        </form>
                    @endif
                @endif

                @if($canCreatePo)
                    <x-ui.button type="button" size="sm" class="tw-w-full tw-mb-2" data-bs-toggle="modal" data-bs-target="#generatePoModal">
                        <x-slot:leading><x-ui.icon name="receipt" /></x-slot:leading>
                        {{ __('purchasing.copy.generate_purchase_order') }}
                    </x-ui.button>
                    <x-ui.button :href="\App\Support\PurchasingNavigation::toRoute('purchasing.comparison.show', $quotation->purchaseRequisition)" variant="outline" size="sm" class="tw-w-full tw-mb-2.5">
                        <x-ui.icon name="chart-column" size="sm" />
                        <span>{{ __('purchasing.copy.open_inter_supplier_comparison') }}</span>
                    </x-ui.button>
                @elseif(! $hasAvailableItems && in_array($quotation->status, ['submitted', 'accepted'], true) && $quotation->purchaseOrders->isEmpty() && $quotation->status === 'accepted')
                    <x-ui.alert tone="warning" :title="__('purchasing.copy.no_available_materials')" class="tw-mb-2.5">
                        {{ __('purchasing.copy.all_requested_materials_in_this_quotation_are_marked_as_not_available_a_purchase_order_cannot_be_cre') }}
                    </x-ui.alert>
                    <x-ui.button variant="secondary" size="sm" class="tw-mb-2.5 tw-w-full" disabled :title="__('purchasing.copy.supplier_marked_all_items_as_not_available')">
                        <x-slot:leading><x-ui.icon name="ban" /></x-slot:leading>
                        {{ __('purchasing.copy.cannot_create_po_not_available') }}
                    </x-ui.button>
                @elseif($quotation->status === 'submitted' && $quotation->isExpired())
                    @if($canRequestRevision)
                        <form action="{{ route('purchasing.quotations.request-revision', $quotation) }}" method="POST" class="tw-mb-2.5" id="requestRevisionForm" data-managed-submit>
                            @csrf
                            <input type="hidden" name="return_url" value="{{ request('return_url') }}">
                            <x-ui.alert tone="warning" :title="__('purchasing.copy.validity_update_required')" class="tw-mb-2">{{ __('purchasing.copy.request_the_supplier_to_extend_the_expired_offer') }}</x-ui.alert>
                            <div class="mb-2">
                                <textarea name="revision_note" id="revisionNote" class="form-control form-control-sm" rows="2" maxlength="1000" placeholder="{{ __('purchasing.copy.please_update_validity_date_and_re_confirm_pricing') }}"></textarea>
                            </div>
                            <x-ui.button type="submit" variant="outline" size="sm" class="tw-w-full">
                                <x-slot:leading><x-ui.icon name="rotate-ccw" /></x-slot:leading>
                                {{ __('purchasing.copy.request_validity_update') }}
                            </x-ui.button>
                        </form>
                    @else
                        <x-ui.button variant="danger" size="sm" class="tw-mb-2.5 tw-w-full" disabled>
                            <x-slot:leading><x-ui.icon name="lock" /></x-slot:leading>
                            {{ __('purchasing.copy.quotation_expired') }}
                        </x-ui.button>
                    @endif
                @elseif($quotation->status === 'revision_requested')
                    <x-ui.alert tone="warning" :title="__('purchasing.copy.revised_quotation_pending')" class="tw-mb-2.5">{{ __('purchasing.copy.waiting_for_the_supplier_to_resubmit') }}</x-ui.alert>
                @elseif($quotation->first_purchase_order)
                    <x-ui.alert tone="success" :title="__('purchasing.copy.po_created_ba9049')" class="tw-mb-2.5"><a href="{{ \App\Support\PurchasingNavigation::toRoute('purchasing.purchase-orders.show', $quotation->first_purchase_order) }}" class="tw-font-semibold tw-underline">{{ $quotation->first_purchase_order->po_number }}</a></x-ui.alert>
                @endif

                <x-ui.button :href="$relatedPrUrl" variant="outline" size="sm" class="tw-w-full">
                    <x-ui.icon name="clipboard-list" size="sm" />
                    <span>{{ __('purchasing.copy.view_related_pr') }}</span>
                </x-ui.button>
            </x-ui.card>

            {{-- Attachments List --}}
            @if($quotation->attachments->count() > 0)
                <x-ui.card :title="trans_choice('common.closure.attachment_count', $quotation->attachments->count())" padding="none">
                    <div class="list-group list-group-flush">
                        @foreach($quotation->attachments as $att)
                            <a href="{{ route('attachments.show', $att) }}" class="list-group-item list-group-item-action py-2 px-3 tw-text-ui-xs d-flex justify-content-between align-items-center" target="_blank">
                                <span class="text-truncate"><x-ui.icon name="paperclip" size="sm" class="tw-me-1.5 tw-text-outline" />{{ $att->file_name }}</span>
                                <x-ui.icon name="download" size="sm" class="tw-text-outline" />
                            </a>
                        @endforeach
                    </div>
                </x-ui.card>
            @endif
        </aside>
    </div>
</div>

@if($canCreatePo)
    <div class="modal fade" id="generatePoModal" tabindex="-1" aria-labelledby="generatePoModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
            <div class="modal-content">
                <form action="{{ route('purchasing.quotations.generate-po', $quotation) }}" method="POST" id="generatePoForm">
                    @csrf
                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title fw-bold" id="generatePoModalTitle">{{ __('purchasing.copy.generate_purchase_order') }}</h5>
                            <div class="tw-text-ui-xs tw-text-on-surface-variant tw-mt-0.5">
                                {{ $quotation->purchaseRequisition->pr_number ?? '-' }} &middot; {{ $supplierDisplayName }}
                            </div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('purchasing.copy.close') }}"></button>
                    </div>

                    <div class="modal-body tw-grid tw-gap-4">
                        <div class="tw-grid tw-gap-2 sm:tw-grid-cols-2 lg:tw-grid-cols-4">
                            <div class="tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface-low tw-p-2.5">
                                <div class="tw-text-ui-xs tw-font-semibold tw-uppercase tw-text-on-surface-variant">{{ __('purchasing.copy.pr_number') }}</div>
                                <div class="tw-mt-0.5 tw-text-ui-sm tw-font-semibold tw-text-on-surface">{{ $quotation->purchaseRequisition->pr_number ?? '-' }}</div>
                            </div>
                            <div class="tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface-low tw-p-2.5">
                                <div class="tw-text-ui-xs tw-font-semibold tw-uppercase tw-text-on-surface-variant">{{ __('purchasing.copy.supplier') }}</div>
                                <div class="tw-mt-0.5 tw-text-ui-sm tw-font-semibold tw-text-on-surface">{{ $supplierDisplayName }}</div>
                            </div>
                            <div class="tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface-low tw-p-2.5">
                                <div class="tw-text-ui-xs tw-font-semibold tw-uppercase tw-text-on-surface-variant">{{ __('purchasing.copy.currency') }}</div>
                                <div class="tw-mt-0.5 tw-text-ui-sm tw-font-semibold tw-text-on-surface">{{ $quotation->currency }}</div>
                            </div>
                            <div class="tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface-low tw-p-2.5">
                                <div class="tw-text-ui-xs tw-font-semibold tw-uppercase tw-text-on-surface-variant">{{ __('purchasing.copy.idr_snapshot') }}</div>
                                <div class="tw-mt-0.5 tw-text-ui-sm tw-font-semibold tw-text-on-surface">
                                    {{ $quotationRate ? 'Rp '.\App\Support\NumberFormat::maxDecimals($quotationRate->rate_to_idr) : __('purchasing.copy.exchange_rate_unavailable') }}
                                </div>
                            </div>
                        </div>

                        <div class="table-responsive tw-rounded-ui-sm tw-border tw-border-outline-variant">
                            <table class="table table-sm align-middle mb-0 tw-text-ui-xs">
                                <thead class="table-light">
                                    <tr>
                                        <th scope="col">{{ __('purchasing.copy.material') }}</th>
                                        <th scope="col" class="text-end">{{ __('purchasing.copy.quantity') }}</th>
                                        <th scope="col" class="text-end">{{ __('purchasing.copy.total_weight_kg_c45d9d') }}</th>
                                        <th scope="col" class="text-end">{{ __('purchasing.copy.price_kg_5a0171') }}</th>
                                        <th scope="col" class="text-end">{{ __('purchasing.copy.amount') }}</th>
                                        <th scope="col">{{ __('purchasing.copy.po_scope') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($directPoItems as $preview)
                                        @php($previewItem = $preview['item'])
                                        <tr @class(['table-light' => ! $preview['eligible']])>
                                            <td class="fw-semibold">{{ $previewItem->prItem->material_name ?? '-' }}</td>
                                            <td class="text-end ui-tabular-nums">{{ $previewItem->prItem?->quantity_value ?? '-' }}</td>
                                            <td class="text-end ui-tabular-nums">
                                                {{ \App\Support\NumberFormat::maxDecimals($previewItem->offered_total_weight ?? $previewItem->prItem?->total_weight ?? 0) }}
                                            </td>
                                            <td class="text-end ui-tabular-nums">
                                                {{ $previewItem->price_per_kg !== null ? \App\Support\NumberFormat::maxDecimals($previewItem->price_per_kg, 4) : '-' }}
                                            </td>
                                            <td class="text-end ui-tabular-nums">
                                                {{ $preview['eligible'] ? \App\Support\NumberFormat::maxDecimals($preview['amount']) : '-' }}
                                            </td>
                                            <td>
                                                @if($preview['eligible'])
                                                    <span class="ui-status-chip ui-status-chip--success">{{ __('purchasing.copy.included_in_po') }}</span>
                                                @else
                                                    <span class="ui-status-chip ui-status-chip--error">{{ $preview['skip_reason'] }}</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot class="table-light fw-bold">
                                    <tr>
                                        <td colspan="4" class="text-end">{{ __('purchasing.copy.po_total') }} ({{ $quotation->currency }})</td>
                                        <td class="text-end ui-tabular-nums">{{ \App\Support\NumberFormat::maxDecimals($directPoTotalAmount) }}</td>
                                        <td></td>
                                    </tr>
                                    <tr>
                                        <td colspan="4" class="text-end">{{ __('purchasing.copy.estimated_total_idr') }}</td>
                                        <td class="text-end text-primary ui-tabular-nums">
                                            {{ $directPoTotalIdr !== null ? 'Rp '.\App\Support\NumberFormat::maxDecimals($directPoTotalIdr) : '-' }}
                                        </td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

                        <x-ui.alert tone="warning" :title="__('purchasing.copy.final_item_selection')">
                            {{ __('purchasing.copy.confirming_will_select_every_eligible_line_shown_above_for_this_supplier_and_create_one_purchase_ord') }}
                        </x-ui.alert>

                        <div class="tw-grid tw-gap-3 md:tw-grid-cols-2">
                            <div>
                                <x-ui.date-picker
                                    id="directPoEstimatedArrival"
                                    name="estimated_arrival"
                                    :label="__('purchasing.copy.target_estimated_arrival_date')"
                                    :value="old('estimated_arrival', now()->addDays(14)->format('Y-m-d'))"
                                    required
                                />
                            </div>
                            <div>
                                <label for="directPoNotes" class="form-label small fw-semibold tw-text-on-surface">{{ __('purchasing.copy.po_notes_remarks') }}</label>
                                <textarea
                                    class="form-control form-control-sm"
                                    id="directPoNotes"
                                    name="notes"
                                    rows="2"
                                    maxlength="1000"
                                    placeholder="{{ __('purchasing.copy.optional_delivery_or_commercial_instructions') }}"
                                >{{ old('notes') }}</textarea>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer tw-bg-surface-low">
                        <x-ui.button type="button" variant="ghost" size="sm" data-bs-dismiss="modal">{{ __('purchasing.copy.cancel') }}</x-ui.button>
                        <x-ui.button type="submit" size="sm" id="generatePoSubmit" data-no-auto-spinner>
                            <span id="generatePoSpinner" class="ui-spinner" hidden aria-hidden="true"></span>
                            <span id="generatePoSubmitLabel">{{ __('purchasing.copy.confirm_generate_po') }}</span>
                        </x-ui.button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endif
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const revisionForm = document.getElementById('requestRevisionForm');
        if (revisionForm) {
            revisionForm.addEventListener('submit', (event) => {
                event.preventDefault();

                AdasiAlert.confirm({
                    title: @json(__('purchasing.copy.request_quotation_revision')),
                    text: @json(__('purchasing.copy.the_supplier_will_be_notified_and_the_quotation_will_be_reopened_for_resubmission')),
                    type: 'warning',
                    confirmText: @json(__('purchasing.copy.yes_request_revision')),
                    cancelText: @json(__('purchasing.copy.cancel'))
                }).then((result) => {
                    if (result.isConfirmed) {
                        const btn = revisionForm.querySelector('button[type="submit"]');
                        window.AdasiButton?.startLoading(btn);
                        revisionForm.submit();
                    }
                });
            });
        }

        const generatePoForm = document.getElementById('generatePoForm');
        generatePoForm?.addEventListener('submit', () => {
            const submitButton = document.getElementById('generatePoSubmit');
            const spinner = document.getElementById('generatePoSpinner');
            const label = document.getElementById('generatePoSubmitLabel');

            if (submitButton) submitButton.disabled = true;
            if (spinner) spinner.hidden = false;
            if (label) label.textContent = @json(__('purchasing.copy.generating_po'));
        });
    });
</script>
@endpush
