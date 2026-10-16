@extends('layouts.app')

@section('title', __('claims.titles.detail', ['number' => $claim->purchaseOrder?->po_number ?? $claim->claim_number]))
@section('page-title', __('claims.titles.page', ['number' => $claim->purchaseOrder?->po_number ?? $claim->claim_number]))

@section('content')
<div class="tw-grid tw-gap-4">
    {{-- Breadcrumb & Compact Page Header --}}
    <x-ui.breadcrumb :items="[
        __('purchasing.breadcrumbs.dashboard') => route('supplier.dashboard'),
        __('purchasing.breadcrumbs.material_claims') => route('supplier.claims.index'),
        __('claims.audit_ui.breadcrumb', ['number' => $claim->purchaseOrder?->po_number ?? $claim->claim_number]) => null,
    ]" />

    <x-ui.page-header
        :title="__('claims.titles.page', ['number' => $claim->purchaseOrder?->po_number ?? $claim->claim_number])"
        :eyebrow="__('claims.copy.quality_discrepancy')"
        :description="__('claims.page.response_help', ['po' => $claim->purchaseOrder?->po_number ?? '-']) "
    >
        <x-slot:actions>
            <x-status-badge type="claim" :status="$claim->status" />
            <x-ui.button :href="route('supplier.claims.index')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" />
                <span>{{ __('claims.copy.back_to_claims') }}</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="tw-grid tw-items-start tw-gap-4 xl:tw-grid-cols-[minmax(0,2fr)_minmax(19rem,1fr)]">
        {{-- Main Column: Claim Demand & Supplier Response --}}
        <div class="tw-grid tw-min-w-0 tw-gap-4">
            {{-- ADASI Claim Demand Information --}}
            <x-ui.card :title="__('claims.copy.adasi_claim_request')" :description="__('claims.copy.defect_details_and_expected_resolution_recorded_by_quality_control')">
                <div class="tw-grid tw-gap-3 sm:tw-grid-cols-2 mb-3">
                    <div class="tw-p-2.5 tw-bg-surface-container border rounded">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('claims.copy.submitted_date') }}</div>
                        <div class="fw-semibold tw-text-on-surface tw-text-ui-sm tw-mt-0.5">{{ $regionalFormatter->timestamp($claim->created_at, 'date_full_human') }}</div>
                    </div>
                    <div class="tw-p-2.5 tw-bg-surface-container border rounded">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('claims.copy.response_deadline') }}</div>
                        <div class="fw-bold text-danger tw-text-ui-sm tw-mt-0.5 d-flex align-items-center gap-1">
                        <x-ui.icon name="clock" size="sm" />
                            <span>{{ $claim->deadline ? $regionalFormatter->date($claim->deadline, 'full_human') : '-' }}</span>
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase tw-mb-1.5">{{ __('claims.copy.problem_description_qc_report') }}</div>
                    <div class="p-3 tw-bg-surface-container border rounded tw-text-on-surface tw-text-ui-xs leading-relaxed">
                        {{ $claim->description }}
                    </div>
                </div>

                <div class="mb-3">
                    <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase tw-mb-1.5">{{ __('claims.copy.expected_resolution_from_adasi') }}</div>
                    <div class="p-3 tw-bg-surface-container border rounded tw-text-on-surface tw-text-ui-xs leading-relaxed">
                        {{ $claim->resolution_expected }}
                    </div>
                </div>

                @if($claim->inspection->attachments->count() > 0)
                    <div>
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase mb-2">{{ __('claims.copy.qc_photographic_evidence_from_adasi') }}</div>
                        <div class="tw-grid tw-grid-cols-2 sm:tw-grid-cols-3 md:tw-grid-cols-4 tw-gap-2">
                            @foreach($claim->inspection->attachments as $att)
                                <a href="{{ route('attachments.show', $att) }}" class="tw-block tw-h-24 tw-overflow-hidden tw-rounded-ui-sm tw-border tw-border-outline-variant tw-transition-opacity hover:tw-opacity-90 image-preview-trigger" title="{{ $att->file_name }}">
                                    <img src="{{ route('attachments.show', $att) }}" alt="{{ $att->file_name }}" class="w-100 h-100 tw-object-cover">
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif
            </x-ui.card>

            {{-- Supplier Response Card / Form --}}
            <x-ui.card :title="__('claims.copy.supplier_response_and_resolution')" :description="__('claims.copy.your_response_is_committed_upon_submission')">
                @if($claim->status === 'pending')
                    <form action="{{ route('supplier.claims.respond', $claim) }}" method="POST" enctype="multipart/form-data" data-async-submit id="respondForm" class="tw-grid tw-gap-3.5">
                        @csrf
                        <x-ui.textarea
                            name="supplier_response"
                            :label="__('claims.copy.official_explanation_and_proposed_action')"
                            :rows="4"
                            required
                            :placeholder="__('claims.copy.e_g_root_cause_cutting_line_calibration_drift_corrective_replacement_lot_scheduled_for_dispatch_on_1')"
                        />
                        <x-ui.input
                            type="file"
                            name="attachments[]"
                            :label="__('claims.copy.supporting_evidence_official_letter_optional')"
                            multiple
                            accept=".jpg,.jpeg,.png,.pdf,.doc,.docx,.xls,.xlsx"
                            :helper="__('claims.copy.official_correspondence_replacement_tracking_or_test_analysis_max_10mb_per_file')"
                            :error="$errors->first('attachments.*')"
                        />

                        <div class="tw-flex tw-justify-end tw-pt-2 border-top">
                            <x-ui.button type="button" id="btnSubmitRespond">
                                <x-ui.icon name="send" size="sm" />
                                <span>{{ __('claims.copy.submit_official_response') }}</span>
                            </x-ui.button>
                        </div>
                    </form>
                @else
                    <div class="tw-text-on-surface-variant tw-text-ui-xs mb-2">
                        {{ __('claims.copy.response_submitted_on') }} <strong>{{ $regionalFormatter->timestamp($claim->updated_at, 'datetime_comma') }}</strong>
                    </div>
                    <div class="p-3 tw-bg-surface-container border rounded mb-3 tw-text-on-surface tw-text-ui-xs leading-relaxed">
                        {{ $claim->supplier_response }}
                    </div>

                    @if($claim->attachments && $claim->attachments->count() > 0)
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase mb-2">{{ __('claims.copy.attached_supplier_documents') }}</div>
                        <div class="tw-grid tw-grid-cols-2 sm:tw-grid-cols-3 tw-gap-2">
                            @foreach($claim->attachments as $att)
                                @php
                                    $isImage = str_starts_with($att->file_type ?? '', 'image/') || preg_match('/\.(jpe?g|png|webp|gif|bmp|svg)$/i', $att->file_name);
                                @endphp
                                <a href="{{ route('attachments.show', $att) }}" {{ $isImage ? 'class=image-preview-trigger' : 'target=_blank' }} class="d-flex align-items-center gap-2 p-2 text-decoration-none tw-rounded-ui-sm tw-border tw-border-outline tw-bg-surface hover:tw-bg-surface-low tw-transition-colors" title="{{ $att->file_name }}">
                                    <x-ui.icon :name="$isImage ? 'image' : 'file-text'" size="sm" class="text-primary flex-shrink-0" />
                                    <span class="tw-text-ui-xs tw-text-on-surface text-truncate">{{ $att->file_name }}</span>
                                </a>
                            @endforeach
                        </div>
                    @endif
                @endif
            </x-ui.card>
        </div>

        {{-- Sidebar Column: Problem Materials & Support --}}
        <aside class="tw-grid tw-gap-4">
            <x-ui.card :title="__('claims.copy.defective_items_qc_ng')" padding="none">
                <div class="list-group list-group-flush">
                    @foreach($claim->inspection->items->where('status', 'ng') as $item)
                        <div class="list-group-item p-3">
                            <div class="fw-bold tw-text-on-surface tw-text-ui-xs">{{ $item->prItem->material_name }}</div>
                            @if($item->notes)
                                <div class="text-danger tw-text-ui-xs mt-1 d-flex align-items-start gap-1">
                <x-ui.icon name="circle-alert" size="sm" class="tw-mt-0.5 tw-shrink-0" />
                                    <span>{{ __('common.final_copy.qc_note') }} {{ $item->notes }}</span>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-ui.card>

            <x-ui.card :title="__('claims.copy.purchasing_support')">
                <p class="tw-text-on-surface-variant tw-text-ui-xs mb-3">
                    {{ __('claims.copy.if_you_require_clarification_regarding_actual_vs_nominal_dimensional_discrepancies_contact_the_adasi') }}
                </p>
                <x-ui.button :href="route('supplier.conversations.index')" variant="outline" size="sm" class="tw-w-full">
                    <x-ui.icon name="message-square" size="sm" />
                    <span>{{ __('claims.copy.open_negotiation_chat') }}</span>
                </x-ui.button>
            </x-ui.card>
        </aside>
    </div>
</div>
@endsection

@push('scripts')
<script>
    $('#btnSubmitRespond').on('click', function() {
        const form = $('#respondForm')[0];
        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }

        AdasiAlert.confirm({
            title: @json(__('claims.copy.send_official_response')),
            text: @json(__('claims.copy.ensure_your_response_and_proposed_resolution_are_accurate_responses_cannot_be_modified_once_sent')),
            type: 'warning',
            confirmText: @json(__('claims.copy.yes_submit_response')),
            cancelText: @json(__('claims.copy.cancel'))
        }).then((result) => {
            if (result.isConfirmed) {
                const btn = document.getElementById('btnSubmitRespond');
                window.AdasiButton?.startLoading(btn);
                if (window.AdasiAsyncForm?.submit) window.AdasiAsyncForm.submit(form, btn);
                else form.requestSubmit();
            }
        });
    });
</script>
@endpush
