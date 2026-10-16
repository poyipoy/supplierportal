@extends('layouts.app')

@section('title', __('claims.copy.create_material_claim_adasi_portal'))
@section('page-title', __('claims.copy.submit_material_claim'))

@section('content')
<div class="tw-grid tw-gap-4">
    {{-- Breadcrumb & Compact Page Header --}}
    <x-ui.breadcrumb :items="[
        __('purchasing.breadcrumbs.material_claims') => \App\Support\PurchasingNavigation::backUrl('purchasing.claims.index'),
        __('purchasing.breadcrumbs.submit_claim') => null,
    ]" />

    <x-ui.page-header
        :title="__('claims.copy.submit_material_claim')"
        :eyebrow="__('claims.copy.quality_discrepancy_action')"
        :description="__('claims.page.submission_help', ['po' => $inspection->purchaseOrder->po_number])"
    >
        <x-slot:actions>
            <x-ui.button :href="\App\Support\PurchasingNavigation::backUrl('purchasing.claims.index')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>{{ __('claims.copy.back_to_claim_list') }}</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <form action="{{ route('purchasing.claims.store') }}" method="POST" id="claimForm">
        @csrf
        <input type="hidden" name="return_url" value="{{ request('return_url') }}">
        <input type="hidden" name="inspection_id" value="{{ $inspection->id }}">

        <div class="tw-grid tw-items-start tw-gap-4 lg:tw-grid-cols-[minmax(0,2fr)_minmax(19rem,1fr)]">
            {{-- Form Column --}}
            <div class="tw-grid tw-gap-4">
                <x-ui.form-section
                    :title="__('claims.copy.claim_particulars')"
                    :description="__('claims.copy.detail_the_material_defect_the_desired_compensatory_resolution_and_the_deadline_for_supplier_respons')"
                >
                    <div class="tw-grid tw-gap-4">
                        <x-ui.textarea
                            name="description"
                    :label="__('claims.copy.problem_description_and_discrepancy')"
                            :rows="4"
                            required
                            :placeholder="__('claims.copy.e_g_thickness_deviation_actual_34_2mm_vs_spec_35_0mm_surface_crack_on_2_pcs')"
                        />
                        <x-ui.textarea
                            name="resolution_expected"
                            :label="__('claims.copy.expected_resolution_remedy')"
                            :rows="3"
                            required
                            :placeholder="__('claims.copy.e_g_full_lot_replacement_within_14_days_or_credit_note_on_next_invoice')"
                        />
                        <div class="tw-grid tw-gap-3 sm:tw-grid-cols-2">
                            <x-ui.date-picker
                                name="deadline"
                                :label="__('claims.copy.supplier_response_deadline')"
                                :min="date('Y-m-d', strtotime('+1 day'))"
                                :helper="__('claims.copy.provide_reasonable_business_days_for_supplier_investigation_and_response')"
                                required
                            />
                        </div>
                    </div>
                </x-ui.form-section>

                <x-ui.action-bar class="tw-mt-2">
                    <x-slot:left>
                        <x-ui.button :href="\App\Support\PurchasingNavigation::backUrl('purchasing.claims.index')" variant="ghost" size="sm">
                            <x-ui.icon name="arrow-left" size="sm" />
                            <span>{{ __('claims.copy.cancel') }}</span>
                        </x-ui.button>
                    </x-slot:left>
                    <x-slot:right>
                        <x-ui.button type="submit" variant="danger" size="sm">
                            <x-ui.icon name="send" size="sm" />
                            <span>{{ __('claims.copy.send_claim_to_supplier') }}</span>
                        </x-ui.button>
                    </x-slot:right>
                </x-ui.action-bar>
            </div>

            {{-- QC Reference Column --}}
            <aside class="tw-grid tw-gap-4">
                <x-ui.card :title="__('claims.copy.qc_inspection_reference')">
                    <x-slot:actions>
                        <x-ui.icon-button :href="\App\Support\PurchasingNavigation::toRoute('qc.inspections.show', $inspection)" icon="external-link" :label="__('claims.copy.view_full_qc_inspection_report')" variant="outline" size="sm" />
                    </x-slot:actions>

                    <div class="tw-grid tw-gap-2.5 mb-3">
                        <div class="p-2 tw-bg-surface-low border rounded">
                            <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('claims.copy.po_number') }}</div>
                            <div class="fw-bold tw-text-on-surface tw-text-ui-sm tw-mt-0.5">{{ $inspection->purchaseOrder->po_number }}</div>
                        </div>
                        <div class="p-2 tw-bg-surface-low border rounded">
                            <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('claims.copy.supplier') }}</div>
                            <div class="fw-semibold tw-text-on-surface tw-text-ui-sm tw-mt-0.5">{{ $inspection->purchaseOrder->supplier->name }}</div>
                        </div>
                        <div class="p-2 tw-bg-surface-low border rounded">
                            <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('claims.copy.inspection_date') }}</div>
                            <div class="fw-semibold tw-text-on-surface tw-text-ui-sm tw-mt-0.5">{{ app(\App\Services\RegionalDisplayFormatter::class)->date($inspection->inspected_at, 'human') }}</div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <div class="fw-bold text-danger tw-text-ui-xs tw-uppercase mb-1">{{ __('claims.copy.defective_items_ng') }}</div>
                        <ul class="list-group list-group-flush border rounded overflow-hidden">
                            @foreach($inspection->items->where('status', 'ng') as $item)
                                <li class="list-group-item py-2 tw-px-2.5 tw-text-ui-xs">
                                    <span class="fw-bold tw-text-on-surface d-block">{{ $item->prItem->material_name }}</span>
                                    @if($item->notes)
                                        <span class="tw-text-on-surface-variant fst-italic">{{ __('common.final_copy.qc_remarks') }} {{ $item->notes }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>

                    @if($inspection->attachments->count() > 0)
                        <div>
                            <div class="fw-bold tw-text-on-surface-variant tw-text-ui-xs tw-uppercase mb-1">{{ __('claims.copy.qc_evidence_photos') }}</div>
                            <div class="row g-2">
                                @foreach($inspection->attachments as $att)
                                    <div class="col-4">
                                        <a href="{{ route('attachments.show', $att) }}" class="d-block border rounded overflow-hidden tw-h-20 tw-bg-surface-low image-preview-trigger" title="{{ $att->file_name }}">
                                            <img src="{{ route('attachments.show', $att) }}" alt="{{ $att->file_name }}" class="w-100 h-100 tw-object-cover">
                                        </a>
                                    </div>
                                @endforeach
                            </div>
                            <div class="tw-text-outline tw-text-ui-xs tw-mt-1.5">{{ __('claims.copy.click_photo_to_view_with_interactive_zoom_photos_are_automatically_attached_to_supplier_claim_notice') }}</div>
                        </div>
                    @endif
                </x-ui.card>
            </aside>
        </div>
    </form>
</div>
@endsection
