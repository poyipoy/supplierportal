@extends('layouts.app')

@section('title', __('qc.titles.start', ['number' => $po->po_number]))
@section('page-title', __('qc.copy.material_qc_inspection'))

@push('styles')
<style>
    .qc-spec-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
        gap: 0.5rem;
    }

    .qc-spec-box {
        background: var(--md-surface-container-low);
        border: 1px solid var(--md-outline-variant);
        border-radius: var(--md-shape-sm);
        padding: 0.5rem 0.65rem;
    }

    .qc-dimension-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
        gap: 0.5rem;
    }

    .item-status-switch:checked {
        background-color: var(--md-error);
        border-color: var(--md-error);
    }
</style>
@endpush

@section('content')
<div class="tw-grid tw-gap-4">
    {{-- Breadcrumb & Compact Page Header --}}
    <x-ui.breadcrumb :items="[
        __('purchasing.breadcrumbs.dashboard') => route('qc.dashboard'),
        __('purchasing.breadcrumbs.qc_inspections') => route('qc.inspections.index'),
        (__('qc.closure.inspect_title', ['number' => $po->po_number])) => null,
    ]" />

    <x-ui.page-header
        :title="__('qc.closure.inspect_title', ['number' => $po->po_number])"
        :eyebrow="__('qc.copy.quality_control_inspection')"
        :description="__('qc.copy.verify_dimensions_weight_and_visual_appearance_against_purchase_specifications_mark_ok_or_ng_per_ite')"
    >
        <x-slot:actions>
            <x-ui.button :href="route('qc.inspections.index')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" />
                <span>{{ __('qc.copy.back_to_inspection_list') }}</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Dynamic Overall Outcome Banners --}}
    <x-ui.alert id="bannerOk" tone="success" :title="__('qc.copy.overall_inspection_status_ok')" class="d-none tw-mb-0">
        {{ __('qc.copy.all_inspected_material_lines_satisfy_tolerance_specifications') }}
    </x-ui.alert>

    <x-ui.alert id="bannerNg" tone="error" :title="__('qc.copy.overall_inspection_status_ng_defective')" class="d-none tw-mb-0">
        {{ __('qc.copy.one_or_more_material_items_do_not_meet_specifications_photographic_evidence_is_mandatory_for_every_n') }}
    </x-ui.alert>

    <form action="{{ route('qc.inspections.store', $po) }}" method="POST" enctype="multipart/form-data" id="inspectionForm" class="tw-grid tw-gap-4">
        @csrf
        @if(isset($shipment) && $shipment)
            <input type="hidden" name="shipment_id" value="{{ $shipment->getRouteKey() }}">
        @endif

        {{-- Section 1: Order & Arrival Context --}}
        <x-ui.form-section
            :title="__('qc.copy.purchase_order_and_shipment_arrival_context')"
            :description="__('qc.copy.verified_arrival_and_commercial_reference_information_for_this_shipment')"
        >
            <div class="tw-grid tw-gap-3 sm:tw-grid-cols-4">
                <div class="tw-p-2.5 tw-bg-surface-low border rounded">
                    <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('qc.copy.po_number') }}</div>
                    <div class="fw-bold tw-text-on-surface tw-text-ui-sm tw-mt-0.5">{{ $po->po_number }}</div>
                </div>
                <div class="tw-p-2.5 tw-bg-surface-low border rounded">
                    <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('qc.copy.supplier_company') }}</div>
                    <div class="fw-bold tw-text-on-surface tw-text-ui-sm tw-mt-0.5">{{ $po->supplier->company_name ?? $po->supplier->name }}</div>
                </div>
                <div class="tw-p-2.5 tw-bg-surface-low border rounded">
                    <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('qc.copy.shipment_reference') }}</div>
                    <div class="fw-bold text-primary tw-text-ui-sm tw-mt-0.5">
                        {{ isset($shipment) && $shipment ? $shipment->shipment_number : __('qc.copy.direct_po_delivery') }}
                    </div>
                </div>
                <div class="tw-p-2.5 tw-bg-surface-low border rounded">
                    <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('qc.copy.material_arrival_date') }}</div>
                    <div class="fw-bold tw-text-on-surface tw-text-ui-sm tw-mt-0.5">{{ $po->actual_arrival ? $regionalFormatter->date($po->actual_arrival, 'full_human') : '-' }}</div>
                </div>
            </div>
        </x-ui.form-section>

        {{-- Section 2: Line Items Physical Inspection --}}
        @php
            if (isset($shipment) && $shipment) {
                $allItems = $shipment->items->where('purchase_order_id', $po->id)->map(function ($si) {
                    $item = $si->quotationItem;
                    $item->shipped_delivery_qty = $si->shipped_qty;
                    $item->shipment_actual_weight_kg = $si->actual_weight_kg;
                    $item->shipment_item_id = $si->id;
                    return $item;
                });
            } else {
                $allItems = $po->quotations->flatMap(fn($q) => $q->items);
            }
        @endphp
        @php
            $qcDimensionLabels = [
                'thickness' => __('qc.copy.thickness_mm'),
                'd_inner' => __('qc.copy.inner_dia_mm'),
                'd_outer' => __('qc.copy.outer_dia_mm'),
                'width' => __('qc.copy.width_mm'),
                'length' => __('qc.copy.length_mm'),
                'weight' => __('qc.copy.weight_unit_kg'),
            ];
            $qcSpecFields = [
                'thickness' => 'thickness',
                'd_inner' => 'd_inner',
                'd_outer' => 'd_outer',
                'width' => 'width',
                'length' => 'length',
                'weight' => 'weight_needed',
            ];
            $qcActualFields = [
                'thickness' => 'actual_thickness',
                'd_inner' => 'actual_d_inner',
                'd_outer' => 'actual_d_outer',
                'width' => 'actual_width',
                'length' => 'actual_length',
                'weight' => 'actual_weight',
            ];
        @endphp

        <x-ui.form-section
            :title="__('qc.copy.material_quality_inspection')"
            :description="__('qc.copy.measure_each_parameter_against_requested_tolerances_mark_individual_outcome_as_ok_or_ng')"
        >
            <div class="tw-grid tw-gap-4">
                @foreach($allItems as $index => $item)
                    @php
                        $prItem = $item->prItem;
                        $relevantDimensions = \App\Models\PrItem::relevantDimensionFields($prItem?->shape);
                        $visibleDimensions = array_merge($relevantDimensions, ['weight']);
                        $itemStatus = old('items.' . $index . '.status', 'ok');
                    @endphp
                    <div class="item-card tw-overflow-hidden tw-rounded-ui-sm tw-border tw-border-outline tw-bg-surface">
                        <div class="tw-flex tw-items-center tw-justify-between tw-border-b tw-border-outline-variant tw-bg-surface-low tw-px-3 tw-py-2.5">
                            <div class="fw-bold tw-text-on-surface tw-text-ui-xs d-flex align-items-center tw-gap-1.5">
                                <span class="tw-rounded-ui-xs tw-bg-primary-container tw-px-2 tw-py-0.5 tw-text-primary-container-foreground">{{ __('common.final_copy.item_number', ['number' => $index + 1]) }}</span>
                                <span>{{ $prItem->material_name }}</span>
                            </div>
                            <span class="item-status-badge tw-rounded-ui-xs tw-px-2.5 tw-py-1 tw-text-ui-xs tw-font-semibold {{ $itemStatus === 'ng' ? 'tw-bg-error-container tw-text-error-container-foreground' : 'tw-bg-success-container tw-text-success-container-foreground' }}" id="badge-status-{{ $index }}">{{ $itemStatus === 'ng' ? 'NG' : 'OK' }}</span>
                        </div>

                        <div class="tw-p-3.5">
                            <input type="hidden" name="items[{{ $index }}][pr_item_id]" value="{{ $prItem->id }}">

                            <div class="row g-3">
                                {{-- Left Column: Read-Only Requested Specifications --}}
                                <div class="col-lg-5 border-lg-end pe-lg-3">
                                    <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase mb-2">{{ __('qc.copy.requested_specifications') }}</div>
                                    <div class="qc-spec-grid">
                                        <div class="qc-spec-box">
                                            <div class="tw-text-on-surface-variant tw-text-ui-xs">{{ __('qc.copy.shape') }}</div>
                                            <div class="fw-bold tw-text-on-surface tw-text-ui-xs tw-mt-0.5">{{ $prItem->shape ?? '-' }}</div>
                                        </div>
                                        <div class="qc-spec-box">
                                            <div class="tw-text-on-surface-variant tw-text-ui-xs">{{ __('qc.copy.quantity') }}</div>
                                            <div class="fw-bold tw-text-on-surface tw-text-ui-xs tw-mt-0.5 ui-tabular-nums">{{ number_format($prItem->quantity_value, 0) }}</div>
                                        </div>
                                        @if(isset($item->shipped_delivery_qty))
                                            <div class="qc-spec-box tw-bg-primary-container/20 border-primary">
                                                <div class="tw-text-primary tw-text-ui-xs fw-semibold">{{ __('qc.copy.consignment_qty') }}</div>
                                                <div class="fw-bold text-primary tw-text-ui-xs tw-mt-0.5 ui-tabular-nums">{{ number_format($item->shipped_delivery_qty, 0) }} pcs</div>
                                            </div>
                                            @if(isset($item->shipment_actual_weight_kg))
                                                <div class="qc-spec-box tw-bg-surface-low border-outline-variant">
                                                    <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold">{{ __('qc.copy.shipment_actual_weight') }}</div>
                                                    <div class="fw-bold tw-text-on-surface tw-text-ui-xs tw-mt-0.5 ui-tabular-nums">{{ \App\Support\NumberFormat::maxDecimals($item->shipment_actual_weight_kg) }} Kg</div>
                                                </div>
                                            @endif
                                        @endif
                                        @foreach($visibleDimensions as $dimension)
                                            @php
                                                $specField = $qcSpecFields[$dimension];
                                                $specValue = $prItem?->{$specField};
                                            @endphp
                                            <div class="qc-spec-box">
                                                <div class="tw-text-on-surface-variant tw-text-ui-xs">{{ $qcDimensionLabels[$dimension] }}</div>
                                                <div class="fw-bold tw-text-on-surface tw-text-ui-xs tw-mt-0.5 spec-val ui-tabular-nums" data-spec-type="{{ $dimension }}" data-val="{{ $specValue }}">{{ $specValue ?? '-' }}</div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>

                                {{-- Right Column: Actual Measurement Inputs & Status --}}
                                <div class="col-lg-7 ps-lg-3">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <div class="text-primary tw-text-ui-xs fw-semibold tw-uppercase">{{ __('qc.copy.actual_inspection_measurements') }}</div>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold">{{ __('qc.copy.outcome') }}</span>
                                            <div class="form-check form-switch d-inline-flex align-items-center tw-gap-1.5 mb-0">
                                                <input type="hidden" name="items[{{ $index }}][status]" id="input-status-{{ $index }}" class="item-status-value" value="{{ $itemStatus === 'ng' ? 'ng' : 'ok' }}">
                                                <input class="form-check-input item-status-switch m-0" type="checkbox" role="switch" id="switch-status-{{ $index }}" data-index="{{ $index }}" @checked($itemStatus === 'ng')>
                                                <label class="form-check-label tw-text-ui-xs fw-bold {{ $itemStatus === 'ng' ? 'tw-text-error' : 'tw-text-success' }}" for="switch-status-{{ $index }}" id="status-label-{{ $index }}">
                                                    {{ $itemStatus === 'ng' ? 'NG' : 'OK' }}
                                                </label>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="input-row" data-index="{{ $index }}">
                                        <div class="qc-dimension-grid">
                                            @foreach($visibleDimensions as $dimension)
                                                <div>
                                                    <label class="form-label tw-text-ui-xs tw-text-on-surface-variant tw-mb-0.5" for="actual-{{ $index }}-{{ $dimension }}">{{ $qcDimensionLabels[$dimension] }}</label>
                                                    <input
                                                        type="number"
                                                        step="any"
                                                        id="actual-{{ $index }}-{{ $dimension }}"
                                                        name="items[{{ $index }}][{{ $qcActualFields[$dimension] }}]"
                                                        class="form-control form-control-sm actual-input ui-tabular-nums"
                                                        data-spec-type="{{ $dimension }}"
                                                        value="{{ old('items.' . $index . '.' . $qcActualFields[$dimension]) }}"
                                                        placeholder="0.00"
                                                    >
                                                </div>
                                            @endforeach
                                        </div>

                                        <div class="tw-mt-2.5">
                                            <label class="form-label tw-text-ui-xs tw-text-on-surface-variant tw-mb-0.5" for="item-notes-{{ $index }}">{{ __('qc.copy.item_inspection_notes_defects_description') }}</label>
                                            <textarea
                                                id="item-notes-{{ $index }}"
                                                name="items[{{ $index }}][notes]"
                                                class="form-control form-control-sm tw-text-ui-xs"
                                                rows="1"
                                                placeholder="{{ __('qc.copy.optional_visual_or_dimensional_remarks') }}"
                                            >{{ old('items.' . $index . '.notes') }}</textarea>
                                        </div>
                                    </div>

                                    {{-- NG Photo Upload (Mandatory only when NG) --}}
                                    <div class="ng-photo-section tw-mt-2.5 p-3 bg-danger-subtle border border-danger-subtle rounded d-none" id="photo-section-{{ $index }}">
                                        <label for="photo-input-{{ $index }}" class="form-label fw-bold text-danger tw-text-ui-xs mb-1 d-flex align-items-center gap-1">
                        <x-ui.icon name="camera" size="sm" />
                                            <span>{{ __('qc.copy.ng_photographic_evidence_mandatory') }}</span>
                                        </label>
                                        <input
                                            type="file"
                                            id="photo-input-{{ $index }}"
                                            name="attachments[{{ $index }}][]"
                                            class="form-control form-control-sm photo-input tw-bg-surface"
                                            accept=".jpg,.jpeg,.png"
                                            multiple
                                            disabled
                                            aria-describedby="photo-input-help-{{ $index }}"
                                        >
                                        <div class="text-danger tw-text-ui-xs mt-1" id="photo-input-help-{{ $index }}">
                                            {{ __('qc.copy.upload_at_least_1_photo_of_the_defective_material_jpg_jpeg_png_max_10mb_per_file') }}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-ui.form-section>

        {{-- Sticky Action Bar --}}
        <x-ui.action-bar class="tw-mt-4">
            <x-slot:left>
                <x-ui.button :href="route('qc.inspections.index')" variant="ghost" size="sm">
                    <x-ui.icon name="arrow-left" size="sm" />
                    <span>{{ __('qc.copy.cancel') }}</span>
                </x-ui.button>
            </x-slot:left>

            <x-slot:right>
                <x-ui.button type="button" size="sm" id="btnSubmit">
                    <x-ui.icon name="save" size="sm" />
                    <span>{{ __('qc.copy.save_inspection_results') }}</span>
                </x-ui.button>
            </x-slot:right>
        </x-ui.action-bar>
    </form>
</div>
@endsection

@push('scripts')
<script>
    $(document).ready(function() {
        function evaluateItem(index) {
            const row = $(`.input-row[data-index="${index}"]`);
            const selectedStatus = $(`#input-status-${index}`).val();

            row.find('.actual-input').each(function() {
                const specType = $(this).data('spec-type');
                const actualVal = parseFloat($(this).val());
                const specEl = row.closest('.item-card').find(`.spec-val[data-spec-type="${specType}"]`);
                const specValStr = specEl.data('val');

                $(this).removeClass('is-invalid');

                if (!isNaN(actualVal) && specValStr !== '' && specValStr !== undefined) {
                    const specVal = parseFloat(specValStr);
                    if (!isNaN(specVal) && specVal > 0) {
                        const diff = Math.abs(actualVal - specVal);
                        const ratio = diff / specVal;

                        if (ratio > 0.05) { // More than 5% tolerance variance
                            $(this).addClass('is-invalid');
                        }
                    }
                }
            });

            const isNg = selectedStatus === 'ng';
            const badge = $(`#badge-status-${index}`);
            const statusLabel = $(`#status-label-${index}`);
            const photoSection = $(`#photo-section-${index}`);
            const photoInput = photoSection.find('.photo-input');

            if (isNg) {
                badge.removeClass('tw-bg-success-container tw-text-success-container-foreground').addClass('tw-bg-error-container tw-text-error-container-foreground').text('NG');
                statusLabel.removeClass('tw-text-success').addClass('tw-text-error').text('NG');
                photoSection.removeClass('d-none');
                photoInput.prop('disabled', false);
                photoInput.prop('required', true);
            } else {
                badge.removeClass('tw-bg-error-container tw-text-error-container-foreground').addClass('tw-bg-success-container tw-text-success-container-foreground').text('OK');
                statusLabel.removeClass('tw-text-error').addClass('tw-text-success').text('OK');
                photoSection.addClass('d-none');
                photoInput.prop('required', false);
                photoInput.prop('disabled', true);
                photoInput.val('');
            }

            evaluateOverall();
        }

        function evaluateOverall() {
            let hasInspectionInput = false;
            let overallNg = false;

            $('.actual-input').each(function() {
                if ($(this).val() !== '') {
                    hasInspectionInput = true;
                    return false;
                }
            });

            if (!hasInspectionInput) {
                $('.item-status-value').each(function() {
                    if ($(this).val() === 'ng') {
                        hasInspectionInput = true;
                        return false;
                    }
                });
            }

            if (!hasInspectionInput) {
                $('#bannerNg').addClass('d-none');
                $('#bannerOk').addClass('d-none');
                return;
            }

            $('.item-status-value').each(function() {
                if ($(this).val() === 'ng') {
                    overallNg = true;
                }
            });

            if (overallNg) {
                $('#bannerOk').addClass('d-none');
                $('#bannerNg').removeClass('d-none');
            } else {
                $('#bannerNg').addClass('d-none');
                $('#bannerOk').removeClass('d-none');
            }
        }

        $('.actual-input').on('input', function() {
            const index = $(this).closest('.input-row').data('index');
            evaluateItem(index);
        });

        $('.item-status-switch').on('change', function() {
            const index = $(this).data('index');
            const status = $(this).is(':checked') ? 'ng' : 'ok';
            $(`#input-status-${index}`).val(status);
            evaluateItem(index);
        });

        $('.input-row').each(function() {
            evaluateItem($(this).data('index'));
        });
        evaluateOverall();

        $('#btnSubmit').on('click', function() {
            $('.input-row').each(function() {
                evaluateItem($(this).data('index'));
            });

            if (!$('#inspectionForm')[0].checkValidity()) {
                $('#inspectionForm')[0].reportValidity();
                return;
            }

            AdasiAlert.confirm({
                title: @json(__('qc.copy.save_inspection_results')),
                text: @js(__('qc.confirmations.save_body')),
                type: 'warning',
                confirmText: @json(__('qc.copy.yes_save_results')),
                cancelText: @json(__('qc.copy.cancel'))
            }).then((result) => {
                if (result.isConfirmed) {
                    window.AdasiButton?.startLoading('#btnSubmit');
                    $('#inspectionForm').submit();
                }
            });
        });
    });
</script>
@endpush
