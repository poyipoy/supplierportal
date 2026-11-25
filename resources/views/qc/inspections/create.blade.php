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
<div class="tw:grid tw:gap-4">
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
            <x-ui.button :href="route('qc.inspections.index')" variant="outline" size="sm">
                <x-ui.icon name="arrow-left" />
                <span>{{ __('qc.copy.back_to_inspection_list') }}</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Dynamic Overall Outcome Banners --}}
    <x-ui.alert id="bannerOk" tone="success" :title="__('qc.copy.overall_inspection_status_ok')" class="tw:hidden tw:mb-0">
        {{ __('qc.copy.all_inspected_material_lines_satisfy_tolerance_specifications') }}
    </x-ui.alert>

    <x-ui.alert id="bannerNg" tone="error" :title="__('qc.copy.overall_inspection_status_ng_defective')" class="tw:hidden tw:mb-0">
        {{ __('qc.copy.one_or_more_material_items_do_not_meet_specifications_photographic_evidence_is_mandatory_for_every_n') }}
    </x-ui.alert>

    <form action="{{ route('qc.inspections.store', $po) }}" method="POST" enctype="multipart/form-data" data-async-submit id="inspectionForm" class="tw:grid tw:gap-4">
        @csrf
        @if(isset($shipment) && $shipment)
            <input type="hidden" name="shipment_id" value="{{ $shipment->getRouteKey() }}">
        @endif

        {{-- Section 1: Order & Arrival Context --}}
        <x-ui.form-section
            :title="__('qc.copy.purchase_order_and_shipment_arrival_context')"
            :description="__('qc.copy.verified_arrival_and_commercial_reference_information_for_this_shipment')"
        >
            <div class="tw:grid tw:gap-3 tw:sm:grid-cols-4">
                <div class="tw:p-2.5 tw:bg-surface-low tw:border tw:border-outline-variant tw:rounded-ui-sm">
                    <div class="tw:text-on-surface-variant tw:text-ui-xs tw:font-semibold tw:uppercase">{{ __('qc.copy.po_number') }}</div>
                    <div class="tw:font-bold tw:text-on-surface tw:text-ui-sm tw:mt-0.5">{{ $po->po_number }}</div>
                </div>
                <div class="tw:p-2.5 tw:bg-surface-low tw:border tw:border-outline-variant tw:rounded-ui-sm">
                    <div class="tw:text-on-surface-variant tw:text-ui-xs tw:font-semibold tw:uppercase">{{ __('qc.copy.supplier_company') }}</div>
                    <div class="tw:font-bold tw:text-on-surface tw:text-ui-sm tw:mt-0.5">{{ $po->supplier->company_name ?? $po->supplier->name }}</div>
                </div>
                <div class="tw:p-2.5 tw:bg-surface-low tw:border tw:border-outline-variant tw:rounded-ui-sm">
                    <div class="tw:text-on-surface-variant tw:text-ui-xs tw:font-semibold tw:uppercase">{{ __('qc.copy.shipment_reference') }}</div>
                    <div class="tw:font-bold tw:text-primary tw:text-ui-sm tw:mt-0.5">
                        {{ isset($shipment) && $shipment ? $shipment->shipment_number : __('qc.copy.direct_po_delivery') }}
                    </div>
                </div>
                <div class="tw:p-2.5 tw:bg-surface-low tw:border tw:border-outline-variant tw:rounded-ui-sm">
                    <div class="tw:text-on-surface-variant tw:text-ui-xs tw:font-semibold tw:uppercase">{{ __('qc.copy.material_arrival_date') }}</div>
                    <div class="tw:font-bold tw:text-on-surface tw:text-ui-sm tw:mt-0.5">{{ $po->actual_arrival ? $regionalFormatter->date($po->actual_arrival, 'full_human') : '-' }}</div>
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
            <div class="tw:grid tw:gap-4">
                @foreach($allItems as $index => $item)
                    @php
                        $prItem = $item->prItem;
                        // QC-01: QC-path canonical shape ('round' counts as Round) and the ordered spec.
                        $canonicalShape = \App\Models\PrItem::canonicalShape($prItem?->shape);
                        $relevantDimensions = \App\Models\PrItem::relevantDimensionFields($canonicalShape);
                        $visibleDimensions = array_merge($relevantDimensions, ['weight']);
                        $orderedSpec = $item->orderedSpecification();
                        $copyValues = collect($orderedSpec)->map(fn ($spec) => $spec['value'])->filter(fn ($value) => $value !== null)->all();
                        $itemStatus = old('items.' . $index . '.status', 'ok');
                    @endphp
                    <div class="item-card tw:overflow-hidden tw:rounded-ui-sm tw:border tw:border-outline tw:bg-surface">
                        <div class="tw:flex tw:items-center tw:justify-between tw:border-b tw:border-outline-variant tw:bg-surface-low tw:px-3 tw:py-2.5">
                            <div class="tw:font-bold tw:text-on-surface tw:text-ui-xs tw:flex tw:items-center tw:gap-1.5">
                                <span class="tw:rounded-ui-xs tw:bg-primary-container tw:px-2 tw:py-0.5 tw:text-primary-container-foreground">{{ __('common.final_copy.item_number', ['number' => $index + 1]) }}</span>
                                <span>{{ $prItem->material_name }}</span>
                            </div>
                            <span class="item-status-badge tw:rounded-ui-xs tw:px-2.5 tw:py-1 tw:text-ui-xs tw:font-semibold {{ $itemStatus === 'ng' ? 'tw:bg-error-container tw:text-error-container-foreground' : 'tw:bg-success-container tw:text-success-container-foreground' }}" id="badge-status-{{ $index }}">{{ $itemStatus === 'ng' ? 'NG' : 'OK' }}</span>
                        </div>

                        <div class="tw:p-3.5">
                            <input type="hidden" name="items[{{ $index }}][pr_item_id]" value="{{ $prItem->id }}">

                            <div class="tw:grid tw:grid-cols-12 tw:gap-3">
                                {{-- Left Column: Read-Only Requested Specifications --}}
                                <div class="tw:col-span-12 tw:lg:col-span-5 tw:lg:border-e tw:lg:border-outline-variant tw:lg:pe-3">
                                    <div class="tw:text-on-surface-variant tw:text-ui-xs tw:font-semibold tw:uppercase tw:mb-2">{{ __('qc.copy.ordered_specification') }}</div>
                                    @if($prItem && $canonicalShape === null)
                                        <p class="tw:m-0 tw:mb-2 tw:text-ui-xs tw:text-on-surface-variant" role="note">{{ __('qc.copy.shape_not_set_notice') }}</p>
                                    @endif
                                    <div class="qc-spec-grid">
                                        <div class="qc-spec-box">
                                            <div class="tw:text-on-surface-variant tw:text-ui-xs">{{ __('qc.copy.shape') }}</div>
                                            <div class="tw:font-bold tw:text-on-surface tw:text-ui-xs tw:mt-0.5">{{ $prItem->shape ?? '-' }}</div>
                                        </div>
                                        <div class="qc-spec-box">
                                            <div class="tw:text-on-surface-variant tw:text-ui-xs">{{ __('qc.copy.quantity') }}</div>
                                            <div class="tw:font-bold tw:text-on-surface tw:text-ui-xs tw:mt-0.5 ui-tabular-nums">{{ number_format($prItem->quantity_value, 0) }}</div>
                                        </div>
                                        @if(isset($item->shipped_delivery_qty))
                                            <div class="qc-spec-box tw:bg-primary-container/20 tw:border-primary">
                                                <div class="tw:text-primary tw:text-ui-xs tw:font-semibold">{{ __('qc.copy.consignment_qty') }}</div>
                                                <div class="tw:font-bold tw:text-primary tw:text-ui-xs tw:mt-0.5 ui-tabular-nums">{{ number_format($item->shipped_delivery_qty, 0) }} pcs</div>
                                            </div>
                                            @if(isset($item->shipment_actual_weight_kg))
                                                <div class="qc-spec-box tw:bg-surface-low tw:border-outline-variant">
                                                    <div class="tw:text-on-surface-variant tw:text-ui-xs tw:font-semibold">{{ __('qc.copy.shipment_actual_weight') }}</div>
                                                    <div class="tw:font-bold tw:text-on-surface tw:text-ui-xs tw:mt-0.5 ui-tabular-nums">{{ \App\Support\NumberFormat::maxDecimals($item->shipment_actual_weight_kg) }} Kg</div>
                                                </div>
                                            @endif
                                        @endif
                                        @foreach($visibleDimensions as $dimension)
                                            @php
                                                $ordered = $orderedSpec[$dimension] ?? null;
                                                // Weight keeps the PR unit weight; dimensions show the ordered (offer or requested) value.
                                                $specValue = $ordered ? $ordered['value'] : $prItem?->{$qcSpecFields[$dimension]};
                                            @endphp
                                            <div class="qc-spec-box">
                                                <div class="tw:text-on-surface-variant tw:text-ui-xs">{{ $qcDimensionLabels[$dimension] }}</div>
                                                <div class="tw:font-bold tw:text-on-surface tw:text-ui-xs tw:mt-0.5 spec-val ui-tabular-nums" data-spec-type="{{ $dimension }}" data-val="{{ $specValue }}">{{ $ordered['display'] ?? ($specValue ?? '-') }}</div>
                                                @if($ordered && $ordered['source'] !== 'requested' && $ordered['requested'] !== null && $ordered['requested'] !== $ordered['value'])
                                                    <div class="tw:text-ui-xs tw:text-on-surface-variant ui-tabular-nums">{{ __('qc.copy.requested_value', ['value' => $ordered['requested']]) }}</div>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                </div>

                                {{-- Right Column: Actual Measurement Inputs & Status --}}
                                <div class="tw:col-span-12 tw:lg:col-span-7 tw:lg:ps-3">
                                    <div class="tw:flex tw:justify-between tw:items-center tw:mb-2">
                                        <div class="tw:flex tw:items-center tw:gap-2">
                                            <div class="tw:text-primary tw:text-ui-xs tw:font-semibold tw:uppercase">{{ __('qc.copy.actual_inspection_measurements') }}</div>
                                            {{-- QC-01: fills the ordered dimensions only; never changes OK/NG and never submits. --}}
                                            @if($copyValues !== [])
                                                <x-ui.button type="button" variant="outline" size="sm" class="qc-copy-spec" data-index="{{ $index }}" :data-spec="json_encode($copyValues)">
                                                    <x-ui.icon name="copy" size="sm" /> {{ __('qc.copy.copy_from_po') }}
                                                </x-ui.button>
                                            @endif
                                            <span class="tw:sr-only" aria-live="polite" id="qc-copy-status-{{ $index }}"></span>
                                        </div>
                                        <div class="tw:flex tw:items-center tw:gap-2">
                                            <span class="tw:text-on-surface-variant tw:text-ui-xs tw:font-semibold">{{ __('qc.copy.outcome') }}</span>
                                            <div class="tw:inline-flex tw:items-center tw:gap-1.5 tw:mb-0">
                                                <input type="hidden" name="items[{{ $index }}][status]" id="input-status-{{ $index }}" class="item-status-value" value="{{ $itemStatus === 'ng' ? 'ng' : 'ok' }}">
                                                <input class="ui-switch item-status-switch tw:m-0" type="checkbox" role="switch" id="switch-status-{{ $index }}" data-index="{{ $index }}" @checked($itemStatus === 'ng')>
                                                <label class="tw:cursor-pointer tw:text-ui-xs tw:font-bold {{ $itemStatus === 'ng' ? 'tw:text-error' : 'tw:text-success' }}" for="switch-status-{{ $index }}" id="status-label-{{ $index }}">
                                                    {{ $itemStatus === 'ng' ? 'NG' : 'OK' }}
                                                </label>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="input-row" data-index="{{ $index }}">
                                        <div class="qc-dimension-grid">
                                            @foreach($visibleDimensions as $dimension)
                                                <div>
                                                    <label class="ui-label tw:text-ui-xs tw:text-on-surface-variant tw:mb-0.5" for="actual-{{ $index }}-{{ $dimension }}">{{ $qcDimensionLabels[$dimension] }}</label>
                                                    <input
                                                        type="number"
                                                        step="any"
                                                        id="actual-{{ $index }}-{{ $dimension }}"
                                                        name="items[{{ $index }}][{{ $qcActualFields[$dimension] }}]"
                                                        class="ui-input ui-input--sm actual-input ui-tabular-nums"
                                                        data-spec-type="{{ $dimension }}"
                                                        value="{{ old('items.' . $index . '.' . $qcActualFields[$dimension]) }}"
                                                        placeholder="0.00"
                                                        @if($dimension !== 'weight') required min="0.0001" @endif
                                                    >
                                                </div>
                                            @endforeach
                                        </div>

                                        <div class="tw:mt-2.5">
                                            <label class="ui-label tw:text-ui-xs tw:text-on-surface-variant tw:mb-0.5" for="item-notes-{{ $index }}">{{ __('qc.copy.item_inspection_notes_defects_description') }}</label>
                                            <textarea
                                                id="item-notes-{{ $index }}"
                                                name="items[{{ $index }}][notes]"
                                                class="ui-textarea ui-input--sm tw:text-ui-xs"
                                                rows="1"
                                                placeholder="{{ __('qc.copy.optional_visual_or_dimensional_remarks') }}"
                                            >{{ old('items.' . $index . '.notes') }}</textarea>
                                        </div>
                                    </div>

                                    {{-- NG Photo Upload (Mandatory only when NG) --}}
                                    <div class="ng-photo-section tw:mt-2.5 tw:p-3 tw:bg-error-container tw:border tw:border-error tw:rounded-ui-sm tw:hidden" id="photo-section-{{ $index }}">
                                        <label for="photo-input-{{ $index }}" class="ui-label tw:font-bold tw:text-error tw:text-ui-xs tw:mb-1 tw:flex tw:items-center tw:gap-1">
                                            <x-ui.icon name="camera" size="sm" />
                                            <span>{{ __('qc.copy.ng_photographic_evidence_mandatory') }}</span>
                                        </label>
                                        <input
                                            type="file"
                                            id="photo-input-{{ $index }}"
                                            name="attachments[{{ $index }}][]"
                                            class="ui-input ui-input--sm photo-input tw:bg-surface"
                                            accept=".jpg,.jpeg,.png"
                                            multiple
                                            disabled
                                            aria-describedby="photo-input-help-{{ $index }}"
                                        >
                                        <div class="tw:text-error tw:text-ui-xs tw:mt-1" id="photo-input-help-{{ $index }}">
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
        <x-ui.action-bar class="tw:mt-4">
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
    (function() {
        function evaluateItem(index) {
            const row = document.querySelector(`.input-row[data-index="${index}"]`);
            const statusInput = document.getElementById(`input-status-${index}`);
            const selectedStatus = statusInput ? statusInput.value : 'ok';
            const card = row?.closest('.item-card');

            if (row) {
                row.querySelectorAll('.actual-input').forEach((inputEl) => {
                    const specType = inputEl.dataset.specType;
                    const actualVal = parseFloat(inputEl.value);
                    const specEl = specType ? card?.querySelector(`.spec-val[data-spec-type="${specType}"]`) : null;
                    const specValStr = specEl?.dataset.val ?? '';

                    inputEl.classList.remove('is-invalid');

                    if (!Number.isNaN(actualVal) && specValStr !== '') {
                        const specVal = parseFloat(specValStr);
                        if (!Number.isNaN(specVal) && specVal > 0) {
                            const ratio = Math.abs(actualVal - specVal) / specVal;
                            if (ratio > 0.05) { // More than 5% tolerance variance
                                inputEl.classList.add('is-invalid');
                            }
                        }
                    }
                });
            }

            const isNg = selectedStatus === 'ng';
            const badge = document.getElementById(`badge-status-${index}`);
            const statusLabel = document.getElementById(`status-label-${index}`);
            const photoSection = document.getElementById(`photo-section-${index}`);
            const photoInput = photoSection?.querySelector('.photo-input');

            if (isNg) {
                if (badge) {
                    badge.classList.remove('tw:bg-success-container', 'tw:text-success-container-foreground');
                    badge.classList.add('tw:bg-error-container', 'tw:text-error-container-foreground');
                    badge.textContent = 'NG';
                }
                if (statusLabel) {
                    statusLabel.classList.remove('tw:text-success');
                    statusLabel.classList.add('tw:text-error');
                    statusLabel.textContent = 'NG';
                }
                photoSection?.classList.remove('tw:hidden');
                if (photoInput) {
                    photoInput.disabled = false;
                    photoInput.required = true;
                }
            } else {
                if (badge) {
                    badge.classList.remove('tw:bg-error-container', 'tw:text-error-container-foreground');
                    badge.classList.add('tw:bg-success-container', 'tw:text-success-container-foreground');
                    badge.textContent = 'OK';
                }
                if (statusLabel) {
                    statusLabel.classList.remove('tw:text-error');
                    statusLabel.classList.add('tw:text-success');
                    statusLabel.textContent = 'OK';
                }
                photoSection?.classList.add('tw:hidden');
                if (photoInput) {
                    photoInput.required = false;
                    photoInput.disabled = true;
                    photoInput.value = '';
                }
            }

            evaluateOverall();
        }

        function evaluateOverall() {
            const actualInputs = Array.from(document.querySelectorAll('.actual-input'));
            const statusValues = Array.from(document.querySelectorAll('.item-status-value'));

            let hasInspectionInput = actualInputs.some((el) => el.value !== '');
            if (!hasInspectionInput) {
                hasInspectionInput = statusValues.some((el) => el.value === 'ng');
            }

            const bannerNg = document.getElementById('bannerNg');
            const bannerOk = document.getElementById('bannerOk');

            if (!hasInspectionInput) {
                bannerNg?.classList.add('tw:hidden');
                bannerOk?.classList.add('tw:hidden');
                return;
            }

            const overallNg = statusValues.some((el) => el.value === 'ng');
            if (overallNg) {
                bannerOk?.classList.add('tw:hidden');
                bannerNg?.classList.remove('tw:hidden');
            } else {
                bannerNg?.classList.add('tw:hidden');
                bannerOk?.classList.remove('tw:hidden');
            }
        }

        function bootQcInspectionCreate() {
            // QC-01: Copy from PO fills only the ordered dimensions of this item. It never touches the
            // OK/NG switch and never submits; the inspector still measures and decides.
            document.addEventListener('click', (event) => {
                const copyBtn = event.target instanceof Element ? event.target.closest('.qc-copy-spec') : null;
                if (!copyBtn) return;

                const index = copyBtn.dataset.index;
                let spec = {};
                try {
                    spec = JSON.parse(copyBtn.dataset.spec || '{}');
                } catch {
                    spec = {};
                }
                Object.entries(spec).forEach(([dimension, value]) => {
                    const input = document.getElementById(`actual-${index}-${dimension}`);
                    if (!input || value === null || value === '') return;
                    input.value = value;
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                });
                const status = document.getElementById(`qc-copy-status-${index}`);
                if (status) status.textContent = @js(__('qc.copy.copy_from_po_done'));
            });

            document.addEventListener('input', (event) => {
                const inputEl = event.target instanceof Element ? event.target.closest('.actual-input') : null;
                if (!inputEl) return;
                const index = inputEl.closest('.input-row')?.dataset.index;
                if (index !== undefined) {
                    evaluateItem(index);
                }
            });

            document.addEventListener('change', (event) => {
                const switchEl = event.target instanceof Element ? event.target.closest('.item-status-switch') : null;
                if (!switchEl) return;
                const index = switchEl.dataset.index;
                if (index === undefined) return;
                const status = switchEl.checked ? 'ng' : 'ok';
                const statusInput = document.getElementById(`input-status-${index}`);
                if (statusInput) {
                    statusInput.value = status;
                }
                evaluateItem(index);
            });

            document.querySelectorAll('.input-row').forEach((row) => {
                evaluateItem(row.dataset.index);
            });
            evaluateOverall();

            document.getElementById('btnSubmit')?.addEventListener('click', () => {
                document.querySelectorAll('.input-row').forEach((row) => {
                    evaluateItem(row.dataset.index);
                });

                const form = document.getElementById('inspectionForm');
                if (!form) return;
                if (!form.checkValidity()) {
                    form.reportValidity();
                    return;
                }

                AdasiAlert.confirm({
                    title: @json(__('qc.copy.save_inspection_results')),
                    text: @json(__('qc.confirmations.save_body')),
                    type: 'warning',
                    confirmText: @json(__('qc.copy.yes_save_results')),
                    cancelText: @json(__('qc.copy.cancel'))
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.AdasiButton?.startLoading('#btnSubmit');
                        form.submit();
                    }
                });
            });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', bootQcInspectionCreate);
        } else {
            bootQcInspectionCreate();
        }
    })();
</script>
@endpush
