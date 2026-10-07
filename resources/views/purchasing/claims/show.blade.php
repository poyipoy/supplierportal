@extends('layouts.app')

@section('title', __('claims.titles.detail', ['number' => $claim->purchaseOrder?->po_number ?? $claim->claim_number]))
@section('page-title', __('claims.titles.page', ['number' => $claim->purchaseOrder?->po_number ?? $claim->claim_number]))

@section('content')
<div class="tw-grid tw-gap-4">
    {{-- Breadcrumb & Compact Page Header --}}
    <x-ui.breadcrumb :items="[
        __('purchasing.breadcrumbs.dashboard') => route('purchasing.dashboard'),
        __('purchasing.breadcrumbs.material_claims') => route('purchasing.claims.index'),
        __('claims.audit_ui.breadcrumb', ['number' => $claim->purchaseOrder?->po_number ?? $claim->claim_number]) => null,
    ]" />

    <x-ui.page-header
        :title="__('claims.titles.page', ['number' => $claim->purchaseOrder?->po_number ?? $claim->claim_number])"
        :eyebrow="__('claims.copy.material_claim_details')"
        :description="__('claims.audit_ui.detail_help', ['po' => $claim->purchaseOrder?->po_number ?? '-', 'supplier' => $claim->purchaseOrder?->supplier?->name ?? __('claims.copy.supplier')])"
    >
        <x-slot:actions>
            <x-status-badge type="claim" :status="$claim->status" size="lg" />
            <x-ui.button :href="\App\Support\PurchasingNavigation::backUrl('purchasing.claims.index')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>{{ __('claims.copy.back_to_claim_list') }}</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="tw-grid tw-items-start tw-gap-4 lg:tw-grid-cols-[minmax(0,2fr)_minmax(19rem,1fr)]">
        {{-- Main Column --}}
        <div class="tw-grid tw-min-w-0 tw-gap-4">
            {{-- Claim Details Card --}}
            <x-ui.card :title="__('claims.copy.claim_particulars')">
                <div class="tw-grid tw-gap-3 sm:tw-grid-cols-2 lg:tw-grid-cols-4 mb-4">
                    <div class="tw-p-2.5 tw-bg-surface-low border rounded">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('claims.copy.po_number') }}</div>
                        <div class="fw-bold tw-text-on-surface tw-text-ui-sm tw-mt-0.5">{{ $claim->purchaseOrder->po_number }}</div>
                    </div>
                    <div class="tw-p-2.5 tw-bg-surface-low border rounded">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('claims.copy.supplier') }}</div>
                        <div class="fw-semibold tw-text-on-surface tw-text-ui-sm tw-mt-0.5">{{ $claim->purchaseOrder->supplier->name }}</div>
                    </div>
                    <div class="tw-p-2.5 tw-bg-surface-low border rounded">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('claims.copy.submitted_by') }}</div>
                        <div class="fw-semibold tw-text-on-surface tw-text-ui-sm tw-mt-0.5">{{ $claim->submitter->name }}</div>
                        <div class="tw-text-outline tw-text-ui-xs">{{ $regionalFormatter->timestamp($claim->created_at, 'datetime_comma') }}</div>
                    </div>
                    <div class="tw-p-2.5 tw-bg-surface-low border rounded">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('claims.copy.response_deadline') }}</div>
                        <div class="fw-bold text-danger tw-text-ui-sm tw-mt-0.5">{{ $claim->deadline ? $regionalFormatter->date($claim->deadline, 'full_human') : '-' }}</div>
                    </div>
                </div>

                <div class="mb-4">
                    <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase mb-1">{{ __('claims.copy.problem_description') }}</div>
                    <div class="p-3 tw-bg-surface-low rounded border tw-text-on-surface tw-text-ui-sm tw-whitespace-pre-line">{{ $claim->description }}</div>
                </div>

                <div class="mb-4">
                    <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase mb-1">{{ __('claims.copy.expected_resolution') }}</div>
                    <div class="p-3 tw-bg-surface-low rounded border tw-text-on-surface tw-text-ui-sm tw-whitespace-pre-line">{{ $claim->resolution_expected }}</div>
                </div>

                @if($claim->inspection->attachments->count() > 0)
                    <div>
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase mb-2">{{ __('claims.copy.qc_evidence_attachments') }}</div>
                        <div class="row g-2">
                            @foreach($claim->inspection->attachments as $att)
                                <div class="col-4 col-md-3 col-lg-2">
                                    <a href="{{ route('attachments.show', $att) }}" class="d-block border rounded overflow-hidden tw-h-24 tw-bg-surface-low image-preview-trigger" title="{{ $att->file_name }}">
                                        <img src="{{ route('attachments.show', $att) }}" alt="{{ $att->file_name }}" class="w-100 h-100 tw-object-cover">
                                    </a>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </x-ui.card>

            {{-- Supplier Response --}}
            @if($claim->status !== 'pending')
            <x-ui.card :title="__('claims.copy.supplier_response_and_resolution')" :description="__('claims.copy.formal_reply_and_evidence_submitted_by_supplier')">
                    <div class="tw-text-on-surface-variant tw-text-ui-xs mb-2">{{ __('common.final_copy.responded') }} {{ $regionalFormatter->timestamp($claim->updated_at, 'datetime_comma') }}</div>
                    <div class="p-3 tw-bg-surface-low rounded border tw-text-on-surface tw-text-ui-sm tw-whitespace-pre-line mb-3">
                        {{ $claim->supplier_response ?? __('claims.copy.no_written_response_text_provided') }}
                    </div>

                    @if($claim->attachments && $claim->attachments->count() > 0)
                        <div>
                            <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase mb-2">{{ __('claims.copy.supplier_attachments') }}</div>
                            <div class="row g-2">
                                @foreach($claim->attachments as $att)
                                    @php
                                        $isImage = str_starts_with($att->file_type ?? '', 'image/') || preg_match('/\.(jpe?g|png|webp|gif|bmp|svg)$/i', $att->file_name);
                                    @endphp
                                    <div class="col-6 col-md-4">
                                        <a href="{{ route('attachments.show', $att) }}" {{ $isImage ? 'class=image-preview-trigger' : 'target=_blank' }} class="d-flex align-items-center gap-2 tw-p-2.5 border rounded text-decoration-none tw-bg-surface hover:tw-bg-surface-low" title="{{ $att->file_name }}">
                                            <x-ui.icon :name="$isImage ? 'image' : 'file-text'" size="sm" class="text-primary flex-shrink-0" />
                                            <span class="tw-text-ui-xs text-truncate tw-text-on-surface fw-medium">{{ $att->file_name }}</span>
                                        </a>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </x-ui.card>
            @endif
        </div>

        {{-- Sidebar Column --}}
        <aside class="tw-grid tw-gap-4">
            {{-- Action Card --}}
            <x-ui.card :title="__('claims.copy.claim_resolution_action')" :description="__('claims.copy.actions_based_on_supplier_response_state')">
                @if($claim->status === 'pending')
                    <x-ui.alert tone="warning" :title="__('claims.copy.supplier_response_pending')">{{ __('common.final_review.response_deadline', ['date' => $claim->deadline ? $regionalFormatter->date($claim->deadline, 'human') : '-']) }}</x-ui.alert>
                @elseif($claim->status === 'responded')
                    <x-ui.alert tone="info" :title="__('claims.copy.supplier_response_received')" class="tw-mb-3">{{ __('claims.copy.review_the_proposed_remedy_and_mark_the_claim_as_resolved_if_it_is_satisfactory') }}</x-ui.alert>
                    <form action="{{ route('purchasing.claims.resolve', $claim) }}" method="POST">
                        @csrf
                        <x-ui.button type="submit" size="sm" class="tw-mb-2 tw-w-full">
                            <x-slot:leading><x-ui.icon name="circle-check" /></x-slot:leading>
                            {{ __('claims.copy.mark_as_resolved') }}
                        </x-ui.button>
                    </form>
                @elseif($claim->status === 'resolved')
                    <x-ui.alert tone="success" :title="__('claims.copy.claim_resolved')">{{ __('claims.copy.this_claim_has_been_completed_and_marked_as_resolved') }}</x-ui.alert>
                @endif
            </x-ui.card>

            {{-- QC Reference NG Items --}}
            <x-ui.card :title="__('claims.copy.defective_items_ng')" padding="none">
                <ul class="list-group list-group-flush">
                    @foreach($claim->inspection->items->where('status', 'ng') as $item)
                        <li class="list-group-item py-2 px-3 tw-text-ui-xs">
                            <span class="fw-bold tw-text-on-surface d-block">{{ $item->prItem->material_name }}</span>
                            @if($item->notes)
                                <span class="tw-text-on-surface-variant fst-italic">{{ __('common.final_copy.qc_notes') }} {{ $item->notes }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
                <div class="tw-p-2.5 text-center border-top tw-bg-surface-low">
                    <x-ui.button :href="\App\Support\PurchasingNavigation::toRoute('qc.inspections.show', $claim->inspection)" variant="ghost" size="sm">
                        <x-ui.icon name="external-link" size="sm" class="me-1" />
                        {{ __('claims.copy.view_full_qc_inspection_report') }}
                    </x-ui.button>
                </div>
            </x-ui.card>
        </aside>
    </div>
</div>
@endsection
