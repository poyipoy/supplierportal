@extends('layouts.app')

@section('title', __('purchasing.copy.detail_purchase_requisition').': '.($pr->pr_number ?? __('purchasing.copy.draft')).' - ADASI Portal')
@section('page-title', __('purchasing.copy.detail_purchase_requisition').': '.($pr->pr_number ?? __('purchasing.copy.draft')))

@push('styles')
<style>
    .pr-best-quotation > * {
        background: var(--md-success-container) !important;
        color: var(--md-on-success-container) !important;
    }

    .pr-timeline {
        list-style: none;
        margin: 0;
        padding: 0;
        position: relative;
    }

    .pr-timeline::before {
        background: var(--md-outline-variant);
        content: '';
        inset-block: 0.6rem 1.25rem;
        inset-inline-start: 0.6rem;
        position: absolute;
        width: 2px;
    }

    .pr-timeline-item {
        min-height: 3.25rem;
        padding-inline-start: 2rem;
        position: relative;
    }

    .pr-timeline-marker {
        background: var(--md-surface);
        border: 2px solid var(--md-outline-strong);
        border-radius: var(--md-shape-full);
        height: 1.15rem;
        inset-block-start: 0.15rem;
        inset-inline-start: 0.05rem;
        position: absolute;
        width: 1.15rem;
        z-index: 1;
    }

    .pr-timeline-item.is-complete .pr-timeline-marker {
        background: var(--md-primary);
        border-color: var(--md-primary);
        box-shadow: inset 0 0 0 2px var(--md-surface);
    }

    .pr-timeline-item.is-current .pr-timeline-marker {
        background: var(--md-warning);
        border-color: var(--md-warning);
        box-shadow: inset 0 0 0 2px var(--md-surface);
    }
</style>
@endpush

@section('content')
@php
    $hasReachedSubmitted = in_array($pr->status, ['submitted', 'rejected', 'bidding', 'completed'], true);
    $hasReachedBidding = in_array($pr->status, ['bidding', 'completed'], true);
@endphp

<div class="tw-grid tw-gap-4">
    {{-- Breadcrumb & Compact Page Header --}}
    <x-ui.breadcrumb :items="[
        __('purchasing.breadcrumbs.dashboard') => route('purchasing.dashboard'),
        __('purchasing.copy.purchase_requisition') => \App\Support\PurchasingNavigation::backUrl('purchasing.requisitions.index'),
        ($pr->pr_number ?? __('purchasing.copy.draft')) => null,
    ]" />

    <x-ui.page-header
        :title="$pr->pr_number ?? __('purchasing.closure.pr_draft')"
        :eyebrow="__('purchasing.copy.purchase_requisition_details')"
        :description="__('purchasing.copy.review_material_requirements_invited_suppliers_quotation_responses_and_workflow_progress')"
    >
        <x-slot:actions>
            <x-ui.button :href="route('purchasing.export.requisitions.detail', $pr)" variant="outline" size="sm" data-async-export data-export-source-singular="{{ __('exports.sources.requisition') }}" data-export-source-plural="{{ __('exports.sources.requisitions') }}" data-export-source-count="1" data-export-filtered="false" data-export-row-label="{{ __('purchasing.copy.material_rows') }}" data-export-row-explanation="{{ __('purchasing.copy.each_material_item_will_be_written_as_a_separate_excel_row') }}">
                <x-ui.icon name="file-spreadsheet" />
                <span>{{ __('purchasing.copy.export_excel') }}</span>
            </x-ui.button>
            <x-status-badge type="pr" :status="$pr->status" size="lg" />
            @if($pr->status === 'bidding')
                <span
                    class="ui-tabular-nums tw-inline-flex tw-items-center tw-rounded-ui-xs tw-border tw-border-primary tw-bg-primary-container tw-px-2.5 tw-py-1.5 tw-text-ui-xs tw-font-semibold tw-text-primary-container-foreground"
                    title="{{ __('purchasing.a11y.quotation_count', ['count' => $submittedQuotationCount]) }}"
                    aria-label="{{ __('purchasing.a11y.quotation_count', ['count' => $submittedQuotationCount]) }}"
                >
                    <x-ui.icon name="users" size="sm" class="me-1" />
                    {{ trans_choice('purchasing.summary.quotations', $submittedQuotationCount, ['count' => $submittedQuotationCount]) }}
                </span>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    {{-- 4-Column Key Metrics Strip --}}
    <div class="tw-grid tw-gap-px tw-overflow-hidden tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-outline-variant sm:tw-grid-cols-2 xl:tw-grid-cols-4">
        <x-ui.metric-card
            flat
            :label="__('purchasing.copy.procurement_period')"
            :value="$pr->period->display_label"
            icon="calendar"
            tone="neutral"
        />
        <x-ui.metric-card
            flat
            :label="__('purchasing.copy.total_requested_weight')"
            :value="\App\Support\NumberFormat::maxDecimals($totalKg) . ' kg'"
            icon="weight"
            tone="primary"
        />
        <x-ui.metric-card
            flat
            :label="__('purchasing.copy.created_by')"
            :value="$pr->creator->name ?? '-'"
            icon="user"
            tone="neutral"
        />
        <x-ui.metric-card
            flat
            :label="__('purchasing.copy.date_created')"
            :value="$regionalFormatter->timestamp($pr->created_at, 'datetime_comma')"
            icon="clock"
            tone="neutral"
        />
    </div>

    <div class="tw-grid tw-items-start tw-gap-4 xl:tw-grid-cols-[minmax(0,2fr)_minmax(19rem,1fr)]">
        {{-- Main Column --}}
        <div class="tw-grid tw-min-w-0 tw-gap-4">
            {{-- Audience & Notes Card --}}
        <x-ui.card :title="__('purchasing.copy.audience_and_instructions')">
                <div class="row g-3">
                    <div class="col-md-6">
                        <span class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase d-block mb-1">{{ __('purchasing.copy.invited_suppliers') }}</span>
                        @if($pr->invitedSuppliers->isEmpty())
                            <span class="ui-status-chip ui-status-chip--neutral">{{ __('purchasing.copy.all_registered_suppliers') }}</span>
                        @else
                            <div class="d-flex flex-wrap tw-gap-1.5">
                                @foreach($pr->invitedSuppliers as $supplier)
                                    <span class="ui-status-chip ui-status-chip--info">
                                        {{ $supplier->supplier->company_name ?? $supplier->name }}
                                    </span>
                                @endforeach
                            </div>
                        @endif
                    </div>
                    <div class="col-md-6">
                        <span class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase d-block mb-1">{{ __('purchasing.copy.requisition_notes') }}</span>
                        @if($pr->notes)
                            <div class="tw-text-on-surface tw-text-ui-sm tw-whitespace-pre-line">{{ $pr->notes }}</div>
                        @else
                            <span class="tw-text-outline tw-text-ui-sm fst-italic">{{ __('purchasing.copy.no_additional_instructions_provided') }}</span>
                        @endif
                    </div>
                </div>
            </x-ui.card>

            {{-- Material List Table --}}
            <x-ui.data-table
                :title="__('purchasing.page.material_count', ['count' => $pr->items->count()])"
                :description="__('purchasing.copy.required_specifications_shapes_dimensions_and_computed_weights')"
            >
                <table class="table table-hover align-middle mb-0 tw-text-ui-xs w-100">
                    <thead class="table-light text-center">
                        <tr>
                            <th scope="col" style="width: 40px;">{{ __('purchasing.copy.no') }}</th>
                            <th scope="col">HS Code</th>
                            <th scope="col">{{ __('purchasing.copy.hs_status') }}</th>
                            <th scope="col" class="text-start">{{ __('purchasing.copy.material') }}</th>
                            <th scope="col">{{ __('purchasing.copy.shape') }}</th>
                            <th scope="col" class="text-start">{{ __('purchasing.copy.dimensions_mm') }}</th>
                            <th scope="col">{{ __('purchasing.copy.qty') }}</th>
                            <th scope="col" class="text-end">{{ __('purchasing.copy.weight_unit_kg_e8ab70') }}</th>
                            <th scope="col" class="text-end">{{ __('purchasing.copy.total_weight') }}</th>
                            <th scope="col">{{ __('purchasing.copy.remark') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($pr->items as $index => $item)
                            @php
                                $hsStatusLabel = match (true) {
                                    $item->hs_code_source === 'manual' => __('purchasing.copy.manual_selection'),
                                    $item->hs_code_resolution_status === 'matched' => __('purchasing.copy.auto_matched'),
                                    $item->hs_code_source === 'legacy' => __('purchasing.copy.legacy_source'),
                                    $item->hs_code_resolution_status === 'no_rule' => __('purchasing.copy.no_rule'),
                                    $item->hs_code_resolution_status === 'ambiguous' => __('purchasing.copy.ambiguous'),
                                    $item->hs_code_resolution_status === 'unmapped_material' => __('purchasing.copy.unmapped_material'),
                                    default => __('purchasing.copy.unresolved'),
                                };
                                $hsStatusTone = match (true) {
                                    $item->hs_code_source === 'manual' => 'ui-status-chip--warning',
                                    $item->hs_code_resolution_status === 'matched' => 'ui-status-chip--success',
                                    default => 'ui-status-chip--neutral',
                                };
                            @endphp
                            <tr>
                                <td class="text-center tw-text-on-surface-variant ui-tabular-nums">{{ $index + 1 }}</td>
                                <td class="text-center fw-semibold tw-text-on-surface">
                                    {{ $item->hs_code ?? '-' }}
                                </td>
                                <td class="text-center">
                                    <span class="ui-status-chip {{ $hsStatusTone }}">{{ $hsStatusLabel }}</span>
                                </td>
                                <td class="text-start fw-bold tw-text-on-surface">{{ $item->material_name }}</td>
                                <td class="text-center">{{ $item->shape ?: '-' }}</td>
                                <td class="text-start tw-text-on-surface-variant">{{ $item->dimension_label }}</td>
                                <td class="text-center fw-bold ui-tabular-nums">{{ number_format($item->quantity_value, 0) }}</td>
                                <td class="text-end ui-tabular-nums tw-text-on-surface">{{ \App\Support\NumberFormat::maxDecimals($item->weight_needed) }}</td>
                                <td class="text-end fw-bold text-primary ui-tabular-nums">{{ \App\Support\NumberFormat::maxDecimals($item->total_weight) }}</td>
                                <td class="text-start tw-text-on-surface-variant">{{ $item->remark ?: '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-ui.data-table>

            {{-- Incoming Quotations Table --}}
            <x-ui.data-table
                :title="__('purchasing.copy.incoming_supplier_quotations')"
                :description="__('purchasing.page.quotation_count', ['count' => $quotations->count()])"
                :empty="$quotations->isEmpty()"
            >
                <x-slot:emptyState>
                    <x-ui.empty-state
                        icon="inbox"
                        :title="__('purchasing.copy.no_quotations_received_yet')"
                        :description="__('purchasing.copy.supplier_responses_will_appear_here_as_soon_as_they_submit_their_pricing')"
                    />
                </x-slot:emptyState>

                <table class="table table-hover align-middle mb-0 tw-text-ui-xs w-100">
                    <thead class="table-light text-center">
                        <tr>
                            <th scope="col">{{ __('purchasing.copy.supplier') }}</th>
                            <th scope="col">{{ __('purchasing.copy.curr') }}</th>
                            <th scope="col" class="text-end">{{ __('purchasing.copy.total_price') }}</th>
                            <th scope="col" class="text-end">{{ __('purchasing.copy.estimated_idr') }}</th>
                            <th scope="col">{{ __('common.labels_review.est_delivery') }}</th>
                            <th scope="col">{{ __('purchasing.copy.submitted') }}</th>
                            <th scope="col">{{ __('purchasing.copy.status') }}</th>
                            <th scope="col" class="text-end" style="width: 140px;">{{ __('purchasing.copy.action') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($quotations as $quotation)
                            @php
                                $isLowest = $lowestTotalIdr !== null
                                    && $quotation->total_idr !== null
                                    && abs((float) $quotation->total_idr - (float) $lowestTotalIdr) < 0.01;
                                $supplierName = $quotation->supplier->supplier->company_name ?? $quotation->supplier->name ?? '-';
                            @endphp
                            <tr class="{{ $isLowest ? 'pr-best-quotation' : '' }}">
                                <td class="fw-bold tw-text-on-surface">{{ $supplierName }}</td>
                                <td class="text-center"><span class="ui-status-chip ui-status-chip--neutral">{{ $quotation->currency }}</span></td>
                                <td class="text-end ui-tabular-nums fw-semibold">{{ \App\Support\NumberFormat::maxDecimals($quotation->total_amount) }}</td>
                                <td class="text-end fw-bold ui-tabular-nums">
                                    @if($quotation->total_idr !== null)
                                        Rp {{ \App\Support\NumberFormat::maxDecimals($quotation->total_idr) }}
                                        @if($isLowest)
                                            <x-ui.icon name="circle-check" class="ms-1 text-success" aria-label="{{ __('purchasing.copy.lowest_estimated_total') }}" />
                                        @endif
                                    @else
                                        <span class="tw-text-outline">-</span>
                                    @endif
                                </td>
                                <td class="text-center ui-tabular-nums tw-text-on-surface-variant">{{ $quotation->estimated_delivery ? date('d M Y', strtotime($quotation->estimated_delivery)) : '-' }}</td>
                                <td class="text-center ui-tabular-nums tw-text-on-surface-variant">{{ $quotation->submitted_at ? $regionalFormatter->timestamp($quotation->submitted_at, 'datetime_comma') : '-' }}</td>
                                <td class="text-center"><x-status-badge type="quotation" :status="$quotation->status" /></td>
                                <td class="text-end">
                                    <div class="d-inline-flex align-items-center tw-gap-1.5 justify-content-end">
                                        <x-ui.icon-button
                                            :href="route('purchasing.quotations.show', [$quotation, \App\Support\PurchasingNavigation::RETURN_URL_KEY => request()->fullUrl()])"
                                            icon="eye"
                                            :label="__('purchasing.copy.view_quotation_details')"
                                            size="sm"
                                        />
                                        @if($submittedQuotationCount >= 2)
                                            <x-ui.icon-button
                                                :href="\App\Support\PurchasingNavigation::toRoute('purchasing.comparison.inter-supplier', ['pr_id' => $pr])"
                                                icon="bar-chart-2"
                                                :label="__('purchasing.copy.launch_side_by_side_comparison')"
                                                variant="secondary"
                                                size="sm"
                                            />
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-ui.data-table>
        </div>

        {{-- Sidebar Column --}}
        <aside class="tw-grid tw-gap-4" aria-label="{{ __('purchasing.copy.requisition_actions_and_progress') }}">
            {{-- Action Card --}}
            <x-ui.card :title="__('purchasing.copy.workflow_actions')">
                <div class="tw-grid tw-gap-3">
                    @if($pr->created_by !== auth()->id())
                        <x-ui.alert tone="info" :title="__('purchasing.copy.read_only_access')">{{ __('common.final_review.created_by', ['name' => $pr->creator->name ?? __('purchasing.copy.another_purchasing_user')]) }}</x-ui.alert>
                    @elseif($pr->status === 'draft')
                        <x-ui.alert tone="warning" :title="__('purchasing.copy.draft_requisition')">{{ __('purchasing.copy.complete_the_material_list_before_submitting_this_requisition') }}</x-ui.alert>
                        <div class="tw-grid tw-gap-2">
                            <x-ui.button :href="\App\Support\PurchasingNavigation::toRoute('purchasing.requisitions.edit', $pr)" variant="outline" size="sm">
                                <x-ui.icon name="square-pen" size="sm" />
                                <span>{{ __('purchasing.copy.edit_draft') }}</span>
                            </x-ui.button>
                            <form action="{{ route('purchasing.requisitions.submit', $pr) }}" method="POST">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="return_url" value="{{ request('return_url') }}">
                                <x-ui.button type="button" class="btn-submit tw-w-full" size="sm">
                                    <x-ui.icon name="send" size="sm" />
                                    <span>{{ __('purchasing.copy.submit_requisition') }}</span>
                                </x-ui.button>
                            </form>
                        </div>
                    @elseif($pr->status === 'rejected')
                        <x-ui.alert tone="error" :title="__('purchasing.copy.requisition_rejected')">{{ __('purchasing.copy.review_the_recorded_notes_and_revise_the_requisition_before_resubmitting') }}</x-ui.alert>
                        <x-ui.button :href="\App\Support\PurchasingNavigation::toRoute('purchasing.requisitions.edit', $pr)" variant="danger" size="sm">
                            <x-ui.icon name="rotate-ccw" size="sm" />
                            <span>{{ __('purchasing.copy.revise_resubmit') }}</span>
                        </x-ui.button>
                    @else
                        <x-ui.alert tone="success" :title="__('purchasing.copy.requisition_active')">{{ __('purchasing.copy.this_requisition_has_been_submitted_and_is_active_in_procurement') }}</x-ui.alert>
                    @endif
                </div>
            </x-ui.card>

            {{-- Supplier Chat Channels --}}
            @if($pr->quotations && $pr->quotations->whereIn('status', ['submitted', 'revision_requested', 'accepted'])->count() > 0)
                <x-ui.card :title="__('purchasing.copy.supplier_discussions')" :description="__('purchasing.copy.open_direct_supplier_chat_threads_for_this_pr')">
                    <div class="tw-grid tw-gap-2">
                        @foreach($pr->quotations->whereIn('status', ['submitted', 'revision_requested', 'accepted'])->unique('supplier_id') as $quotation)
                            <form action="{{ route('purchasing.conversations.start.pr', ['pr_id' => $pr, 'supplier_id' => $quotation->supplier]) }}" method="POST" data-chat-start-form data-managed-submit>
                                @csrf
                                <input type="hidden" name="return_url" value="{{ \App\Support\PurchasingNavigation::currentUrlForReturn() }}">
                                <x-ui.button type="submit" variant="outline" size="sm" class="tw-w-full tw-justify-start">
                                    <x-ui.icon name="message-square" size="sm" class="text-primary flex-shrink-0" />
                                    <span class="text-truncate fw-medium">{{ $quotation->supplier->supplier->company_name ?? $quotation->supplier->name }}</span>
                                </x-ui.button>
                            </form>
                        @endforeach
                    </div>
                </x-ui.card>
            @endif

            {{-- Workflow Timeline --}}
            <x-ui.card :title="__('purchasing.copy.workflow_progress')">
                <ol class="pr-timeline">
                    <li class="pr-timeline-item is-complete">
                        <span class="pr-timeline-marker" aria-hidden="true"></span>
                        <div class="tw-text-ui-sm fw-bold text-primary">{{ __('purchasing.copy.created_draft') }}</div>
                        <time class="ui-tabular-nums tw-text-on-surface-variant tw-text-ui-xs" datetime="{{ $pr->created_at->toIso8601String() }}">{{ $regionalFormatter->timestamp($pr->created_at, 'datetime_comma') }}</time>
                    </li>
                    <li class="pr-timeline-item {{ $hasReachedSubmitted ? 'is-complete' : '' }}">
                        <span class="pr-timeline-marker" aria-hidden="true"></span>
                        <div class="tw-text-ui-sm fw-bold {{ $hasReachedSubmitted ? 'text-primary' : 'tw-text-outline' }}">{{ __('purchasing.copy.submitted') }}</div>
                        @if($hasReachedSubmitted)
                            <time class="ui-tabular-nums tw-text-on-surface-variant tw-text-ui-xs" datetime="{{ $pr->updated_at->toIso8601String() }}">{{ $regionalFormatter->timestamp($pr->updated_at, 'datetime_comma') }}</time>
                        @endif
                    </li>
                    <li class="pr-timeline-item {{ $hasReachedBidding ? 'is-current' : '' }}">
                        <span class="pr-timeline-marker" aria-hidden="true"></span>
                        <div class="tw-text-ui-sm fw-bold {{ $hasReachedBidding ? 'text-warning' : 'tw-text-outline' }}">{{ __('purchasing.copy.supplier_bidding') }}</div>
                    </li>
                </ol>
            </x-ui.card>
        </aside>
    </div>
</div>
@endsection

@push('scripts')
<script>
    $('.btn-submit').on('click', function() {
        const $btn = $(this);
        const form = $btn.closest('form');
        AdasiAlert.confirm({
            title: @json(__('purchasing.copy.submit_requisition')),
            text: @json(__('purchasing.copy.status_will_change_to_submitted_and_cannot_be_edited_anymore')),
            confirmText: @json(__('purchasing.copy.yes_submit')),
            cancelText: @json(__('purchasing.copy.cancel'))
        }).then((result) => {
            if (result.isConfirmed) {
                window.AdasiButton?.startLoading($btn[0]);
                form.submit();
            }
        });
    });
</script>
@endpush
