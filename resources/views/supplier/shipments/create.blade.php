@extends('layouts.app')

@php
    $isEditing = isset($shipment) && $shipment;
@endphp
@section('title', ($isEditing ? __('shipments.copy.edit_shipment') : __('shipments.copy.create_new_shipment')) . ' - ADASI Portal')
@section('page-title', $isEditing ? __('shipments.copy.edit_shipment') : __('shipments.copy.create_shipment'))

@push('styles')
<style>
    .sticky-summary-bar {
        position: sticky;
        bottom: 0;
        z-index: 1020;
        background-color: var(--md-surface);
        border-top: 1px solid var(--md-outline-variant);
        box-shadow: 0 -4px 12px rgba(0, 0, 0, 0.06);
        padding: 0.85rem 1.25rem;
    }

    .po-group-card {
        border: 1px solid var(--md-outline-variant);
        border-radius: var(--md-shape-sm);
        transition: border-color 0.15s ease;
        background: var(--md-surface);
    }

    .po-group-card:hover {
        border-color: var(--md-outline-strong);
    }

    .po-group-header {
        background-color: var(--md-surface-container-low);
        border-bottom: 1px solid var(--md-outline-variant);
        padding: 0.65rem 1rem;
    }
</style>
@endpush

@section('content')
<div class="tw-grid tw-gap-4">
    {{-- Breadcrumb & Page Header --}}
    <x-ui.breadcrumb :items="[
        __('purchasing.breadcrumbs.dashboard') => route('supplier.dashboard'),
        __('purchasing.breadcrumbs.shipments') => route('supplier.shipments.index'),
        ($isEditing ? __('purchasing.breadcrumbs.edit') : __('purchasing.breadcrumbs.create')) => null,
    ]" />

    <x-ui.page-header
        :title="$isEditing ? __('shipments.audit_ui.edit_draft') : __('shipments.copy.create_new_shipment')"
        :eyebrow="__('shipments.copy.logistics_fulfillment')"
        :description="__('shipments.copy.consolidate_and_allocate_deliveries_across_your_active_purchase_orders_you_may_ship_full_or_partial')"
    >
        <x-slot:actions>
            <x-ui.button :href="route('supplier.shipments.index')" variant="ghost" size="sm">
                <x-slot:leading><x-ui.icon name="arrow-left" size="sm" /></x-slot:leading>
                <span>{{ __('shipments.copy.back_to_shipments') }}</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if($poItems->isEmpty())
        <x-ui.card padding="none">
            <x-ui.empty-state
                icon="package-check"
                :title="__('shipments.copy.no_pending_deliveries')"
                :description="__('shipments.copy.all_your_active_purchase_orders_have_been_fully_delivered_or_no_active_pos_are_currently_awaiting_de')"
            />
        </x-ui.card>
    @else
        <form method="POST" action="{{ $isEditing ? route('supplier.shipments.update', $shipment) : route('supplier.shipments.store') }}" id="shipmentCreateForm">
            @csrf
            @if($isEditing) @method('PUT') @endif
            <input type="hidden" name="action" id="shipmentFormAction" value="draft">

            {{-- 1. General Logistics Information --}}
            <x-ui.card :title="__('shipments.copy.shipment_logistics_information')" :description="__('shipments.copy.set_key_dispatch_details_and_container_or_forwarding_notes_for_this_consignment')">
                <div class="tw-grid tw-gap-3 sm:tw-grid-cols-3">
                    <div>
                        <x-ui.date-picker
                            name="shipment_date"
                            id="shipmentDate"
                            :label="__('shipments.copy.planned_dispatch_date')"
                            value="{{ old('shipment_date', $shipment?->shipment_date?->format('Y-m-d') ?? now()->format('Y-m-d')) }}"
                            required
                        />
                    </div>

                    <div>
                        <x-ui.date-picker
                            name="estimated_arrival_date"
                            id="estimatedArrivalDate"
                            :label="__('shipments.copy.shipment_eta')"
                            value="{{ old('estimated_arrival_date', $shipment?->estimated_arrival_date?->format('Y-m-d') ?? now()->addDays(14)->format('Y-m-d')) }}"
                            required
                        />
                    </div>

                    <div>
                        <label for="shipmentNotes" class="form-label tw-text-ui-xs fw-semibold">
                            {{ __('shipments.copy.logistics_notes_remarks') }}
                        </label>
                        <input
                            type="text"
                            name="notes"
                            id="shipmentNotes"
                            class="form-control form-control-sm @error('notes') is-invalid @enderror"
                            value="{{ old('notes', $shipment?->notes) }}"
                            placeholder="{{ __('shipments.copy.e_g_container_no_vessel_name_forwarder') }}"
                        >
                        @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
            </x-ui.card>

            {{-- 2. Allocation Filter Toolbar --}}
            @php
                $groupedByPo = $poItems->groupBy(fn($item) => $item['po']->id);
                $globalIndex = 0;
            @endphp

            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 p-3 tw-bg-surface-low border tw-border-outline-variant rounded">
                <div class="d-flex align-items-center gap-2">
                    <span class="fw-bold tw-text-ui-sm tw-text-on-surface">{{ __('shipments.copy.material_allocation') }}</span>
                    <span class="ui-status-chip ui-status-chip--info ui-tabular-nums">
                        {{ __('shipments.page.allocation_counts', ['pos' => $groupedByPo->count(), 'items' => $poItems->count()]) }}
                    </span>
                </div>

                <div class="d-flex align-items-center gap-2" style="min-width: 260px;">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text tw-bg-surface border-end-0 tw-text-outline">
                            <x-ui.icon name="search" size="sm" />
                        </span>
                        <input
                            type="text"
                            id="quickSearchItems"
                            class="form-control form-control-sm border-start-0 ps-0"
                            placeholder="{{ __('shipments.copy.filter_po_or_material_name') }}"
                            autocomplete="off"
                        >
                    </div>
                </div>
            </div>

            {{-- 3. PO-Grouped Allocation Cards --}}
            <div class="tw-grid tw-gap-4 mt-2" id="poGroupsContainer">
                @foreach($groupedByPo as $poId => $itemsInPo)
                    @php
                        $firstItem = $itemsInPo->first();
                        $po = $firstItem['po'];
                        $isPoPreselected = $preselectedPoId && (int)$po->id === (int)$preselectedPoId;
                    @endphp
                    <div class="po-group-card" data-po-number="{{ strtolower($po->po_number) }}">
                        <div class="po-group-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                            <div class="d-flex align-items-center gap-2">
                                <span class="fw-bold tw-text-ui-sm text-primary">{{ $po->po_number }}</span>
                                @if($po->pr_reference)
                                    <span class="ui-status-chip ui-status-chip--neutral tw-text-ui-xs">
                                        {{ __('purchasing.copy.pr_no') }}: {{ $po->pr_reference }}
                                    </span>
                                @endif
                                <span class="tw-text-on-surface-variant tw-text-ui-xs">
                                    ({{ trans_choice('shipments.audit_ui.pending_items', $itemsInPo->count(), ['count' => $itemsInPo->count()]) }})
                                </span>
                            </div>

                            <div class="d-flex align-items-center gap-2">
                                <button type="button" class="btn btn-outline-primary btn-sm py-0 px-2 tw-text-ui-xs po-select-all-btn">
                                    <x-ui.icon name="check-check" size="sm" />
                                    <span>{{ __('shipments.copy.select_all_in_po') }}</span>
                                </button>
                                <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2 tw-text-ui-xs po-clear-btn">
                                    <span>{{ __('shipments.copy.clear') }}</span>
                                </button>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0 tw-text-ui-xs w-100">
                                <thead class="table-light">
                                    <tr>
                                        <th scope="col" style="width: 44px;" class="text-center">{{ __('shipments.copy.select') }}</th>
                                        <th scope="col">{{ __('shipments.copy.material_specifications') }}</th>
                                        <th scope="col" class="text-end">{{ __('shipments.copy.ordered_qty') }}</th>
                                        <th scope="col" class="text-end">{{ __('shipments.copy.already_shipped') }}</th>
                                        <th scope="col" class="text-end">{{ __('shipments.copy.remaining_qty') }}</th>
                                        <th scope="col" class="text-end" style="width: 170px;">{{ __('shipments.copy.this_shipment_qty') }}</th>
                                        <th scope="col" class="text-end" style="width: 150px;">{{ __('shipments.copy.actual_kg') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($itemsInPo as $itemData)
                                        @php
                                            $currentIndex = $globalIndex++;
                                            $currentShippedQty = $itemData['current_shipped_qty'] ?? null;
                                            $currentActualWeightKg = $itemData['current_actual_weight_kg'] ?? null;
                                            $isPreselected = $currentShippedQty !== null || ($isPoPreselected && $currentShippedQty === null);
                                            $prItem = $itemData['pr_item'];
                                        @endphp
                                        <tr class="item-alloc-row" data-material-name="{{ strtolower($prItem->material_name ?? '') }}">
                                            <td class="text-center">
                                                <input
                                                    type="checkbox"
                                                    class="form-check-input alloc-toggle"
                                                    id="toggle_{{ $currentIndex }}"
                                                    {{ $isPreselected ? 'checked' : '' }}
                                                    aria-label="{{ __('shipments.a11y.select_item', ['material' => $prItem->material_name]) }}"
                                                >
                                            </td>
                                            <td>
                                                <div class="fw-semibold tw-text-on-surface">
                                                    {{ $prItem->material_name }}
                                                </div>
                                                <div class="tw-text-on-surface-variant tw-text-ui-xs">
                                                    {{ __('purchasing.copy.shape') }}: {{ $prItem->shape ?? '-' }}
                                                    @if($prItem?->thickness) | T: {{ $prItem->thickness }}mm @endif
                                                    @if($prItem?->width) | W: {{ $prItem->width }}mm @endif
                                                    @if($prItem?->length) | L: {{ $prItem->length }}mm @endif
                                                    @if($prItem?->d_outer) | OD: {{ $prItem->d_outer }}mm @endif
                                                </div>
                                            </td>
                                            <td class="text-end ui-tabular-nums tw-text-on-surface-variant">
                                                {{ number_format($itemData['ordered_qty'] ?? $itemData['ordered']) }} pcs
                                            </td>
                                            <td class="text-end ui-tabular-nums tw-text-on-surface-variant">
                                                {{ number_format($itemData['allocated_qty'] ?? $itemData['allocated']) }} pcs
                                            </td>
                                            <td class="text-end fw-bold text-primary ui-tabular-nums">
                                                {{ number_format($itemData['remaining_qty'] ?? $itemData['remaining']) }} pcs
                                            </td>
                                            <td class="text-end">
                                                <input type="hidden" name="items[{{ $currentIndex }}][purchase_order_id]" value="{{ $itemData['po']->id }}">
                                                <input type="hidden" name="items[{{ $currentIndex }}][quotation_item_id]" value="{{ $itemData['quotation_item']->id }}">
                                                <div class="input-group input-group-sm">
                                                    <input
                                                        type="number"
                                                        step="1"
                                                        min="1"
                                                        max="{{ $itemData['remaining_qty'] ?? $itemData['remaining'] }}"
                                                        name="items[{{ $currentIndex }}][shipped_qty]"
                                                        value="{{ old("items.{$currentIndex}.shipped_qty", $currentShippedQty ?? ($isPreselected ? ($itemData['remaining_qty'] ?? $itemData['remaining']) : '')) }}"
                                                        class="form-control form-control-sm text-end shipped-qty-input"
                                                        placeholder="0"
                                                        data-remaining="{{ $itemData['remaining_qty'] ?? $itemData['remaining'] }}"
                                                        aria-label="{{ __('shipments.a11y.ship_quantity', ['material' => $prItem->material_name]) }}"
                                                    >
                                                    <button type="button" class="btn btn-outline-secondary btn-sm fill-all-btn" title="{{ __('shipments.copy.ship_all_remaining_quantity') }}">
                                                        {{ __('shipments.copy.all') }}
                                                    </button>
                                                </div>
                                            </td>
                                            <td class="text-end">
                                                <input
                                                    type="number"
                                                    step="0.0001"
                                                    min="0.0001"
                                                    name="items[{{ $currentIndex }}][actual_weight_kg]"
                                                    value="{{ old("items.{$currentIndex}.actual_weight_kg", $currentActualWeightKg ?? '') }}"
                                                    class="form-control form-control-sm text-end actual-weight-input"
                                                    placeholder="0.0000"
                                                    aria-label="{{ __('shipments.a11y.actual_weight', ['material' => $prItem->material_name]) }}"
                                                >
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- 4. Sticky Bottom Submission Summary Bar --}}
            <div class="sticky-summary-bar mt-4 rounded d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="d-inline-flex align-items-center justify-content-center tw-h-9 tw-w-9 rounded-circle tw-bg-primary-container text-primary">
                        <x-ui.icon name="truck" size="sm" />
                    </div>
                    <div>
                        <div class="fw-bold tw-text-ui-sm tw-text-on-surface" id="shipmentSummaryTitle">
                            {{ __('shipments.copy.consignment_allocation_summary') }}
                        </div>
                        <div class="tw-text-ui-xs tw-text-on-surface-variant" id="shipmentSummaryText">
                            {{ __('shipments.copy.0_item_s_allocated_for_delivery') }}
                        </div>
                    </div>
                </div>

                <div class="d-flex flex-wrap align-items-center gap-2">
                    <x-ui.button type="submit" name="action" value="draft" variant="outline" size="sm" id="btnSaveDraft">
                        <x-slot:leading><x-ui.icon name="save" size="sm" /></x-slot:leading>
                        {{ $isEditing ? __('shipments.copy.save_draft_changes') : __('shipments.copy.save_as_draft') }}
                    </x-ui.button>

                    @unless($isEditing)
                        <x-ui.button type="button" variant="primary" size="sm" id="btnSubmitShipment">
                            <x-slot:leading><x-ui.icon name="send" size="sm" /></x-slot:leading>
                            {{ __('shipments.copy.submit_shipment_delivery') }}
                        </x-ui.button>
                    @endunless
                </div>
            </div>
        </form>
    @endif
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const rows = document.querySelectorAll('.item-alloc-row');
    const summaryText = document.getElementById('shipmentSummaryText');
    const summaryTitle = document.getElementById('shipmentSummaryTitle');
    const searchInput = document.getElementById('quickSearchItems');

    // 1. Calculate & update allocation summary
    const updateSummary = () => {
        let activeCount = 0;
        let totalQty = 0;
        let totalWeight = 0;
        let hasOverAllocation = false;

        rows.forEach(row => {
            const toggle = row.querySelector('.alloc-toggle');
            const qtyInput = row.querySelector('.shipped-qty-input');
            const weightInput = row.querySelector('.actual-weight-input');
            const remaining = parseInt(qtyInput.dataset.remaining, 10) || 0;
            const qty = parseInt(qtyInput.value, 10) || 0;
            const weight = parseFloat(weightInput.value) || 0;

            if (toggle.checked && (qty > 0 || weight > 0)) {
                activeCount++;
                totalQty += qty;
                totalWeight += weight;
                if (qty > remaining) {
                    hasOverAllocation = true;
                    qtyInput.classList.add('is-invalid');
                } else {
                    qtyInput.classList.remove('is-invalid');
                }
            } else {
                qtyInput.classList.remove('is-invalid');
            }
        });

        if (summaryText) {
            if (hasOverAllocation) {
                const warning = document.createElement('span');
                warning.className = 'text-danger fw-semibold';
                warning.textContent = window.AdasiI18n.t('js.shipments.remaining_balance_warning');
                summaryText.replaceChildren(warning);
            } else {
                summaryText.textContent = window.AdasiI18n.t('shipments.js.allocation_summary', { count: activeCount, qty: totalQty.toLocaleString('id-ID'), weight: totalWeight.toLocaleString('id-ID', { minimumFractionDigits: 0, maximumFractionDigits: 4 }) });
            }
        }

        return { activeCount, totalQty, totalWeight, hasOverAllocation };
    };

    // 2. Setup row-level interactions
    rows.forEach(row => {
        const toggle = row.querySelector('.alloc-toggle');
        const qtyInput = row.querySelector('.shipped-qty-input');
        const weightInput = row.querySelector('.actual-weight-input');
        const fillAllBtn = row.querySelector('.fill-all-btn');
        const remaining = parseInt(qtyInput.dataset.remaining, 10) || 0;

        const syncRowState = () => {
            const enabled = toggle.checked;
            row.querySelectorAll('input[name^="items["]').forEach(field => {
                field.disabled = !enabled;
            });
            if (enabled) {
                row.classList.add('table-active');
            } else {
                row.classList.remove('table-active');
            }
        };

        if (!toggle.checked && (parseInt(qtyInput.value, 10) > 0 || parseFloat(weightInput.value) > 0)) {
            toggle.checked = true;
        }
        syncRowState();

        fillAllBtn?.addEventListener('click', () => {
            qtyInput.value = remaining;
            toggle.checked = true;
            syncRowState();
            updateSummary();
        });

        qtyInput?.addEventListener('input', () => {
            const val = parseInt(qtyInput.value, 10) || 0;
            if (val > 0) {
                toggle.checked = true;
            }
            syncRowState();
            updateSummary();
        });

        weightInput?.addEventListener('input', () => {
            const val = parseFloat(weightInput.value) || 0;
            if (val > 0) {
                toggle.checked = true;
            }
            syncRowState();
            updateSummary();
        });

        toggle?.addEventListener('change', () => {
            if (toggle.checked && (!qtyInput.value || parseInt(qtyInput.value, 10) <= 0)) {
                qtyInput.value = remaining;
            } else if (!toggle.checked) {
                qtyInput.value = '';
                weightInput.value = '';
            }
            syncRowState();
            updateSummary();
        });
    });

    // 3. PO Card-level actions (Select All in PO / Clear PO)
    document.querySelectorAll('.po-group-card').forEach(card => {
        const selectAllBtn = card.querySelector('.po-select-all-btn');
        const clearBtn = card.querySelector('.po-clear-btn');
        const cardRows = card.querySelectorAll('.item-alloc-row');

        selectAllBtn?.addEventListener('click', () => {
            cardRows.forEach(row => {
                const toggle = row.querySelector('.alloc-toggle');
                const qtyInput = row.querySelector('.shipped-qty-input');
                const remaining = parseInt(qtyInput.dataset.remaining, 10) || 0;
                toggle.checked = true;
                qtyInput.value = remaining;
                row.querySelectorAll('input[name^="items["]').forEach(f => f.disabled = false);
                row.classList.add('table-active');
            });
            updateSummary();
        });

        clearBtn?.addEventListener('click', () => {
            cardRows.forEach(row => {
                const toggle = row.querySelector('.alloc-toggle');
                const qtyInput = row.querySelector('.shipped-qty-input');
                const weightInput = row.querySelector('.actual-weight-input');
                toggle.checked = false;
                qtyInput.value = '';
                weightInput.value = '';
                row.querySelectorAll('input[name^="items["]').forEach(f => f.disabled = true);
                row.classList.remove('table-active');
            });
            updateSummary();
        });
    });

    // 4. Quick search filter across POs and materials
    searchInput?.addEventListener('input', () => {
        const query = searchInput.value.trim().toLowerCase();

        document.querySelectorAll('.po-group-card').forEach(card => {
            const poNum = card.dataset.poNumber || '';
            let cardHasVisibleRow = false;

            card.querySelectorAll('.item-alloc-row').forEach(row => {
                const matName = row.dataset.materialName || '';
                const matches = query === '' || poNum.includes(query) || matName.includes(query);

                row.style.display = matches ? '' : 'none';
                if (matches) {
                    cardHasVisibleRow = true;
                }
            });

            card.style.display = cardHasVisibleRow ? '' : 'none';
        });
    });

    // 5. Submit Shipment Confirmation via AdasiAlert
    const submitShipmentBtn = document.getElementById('btnSubmitShipment');
    const createForm = document.getElementById('shipmentCreateForm');
    const btnSaveDraft = document.getElementById('btnSaveDraft');

    btnSaveDraft?.addEventListener('click', () => {
        const actionInput = document.getElementById('shipmentFormAction');
        if (actionInput) {
            actionInput.value = 'draft';
        }
    });

    submitShipmentBtn?.addEventListener('click', (e) => {
        e.preventDefault();

        if (createForm && !createForm.checkValidity()) {
            createForm.reportValidity();
            return;
        }

        const summary = updateSummary();

        if (summary.hasOverAllocation) {
            const warningTitle = @json(__('shipments.copy.allocation_exceeds_balance'));
            const warningText = @json(__('shipments.copy.some_allocated_quantities_exceed_the_remaining_ordered_balance_on_the_po_please_adjust_the_quantitie'));
            if (window.AdasiAlert) {
                window.AdasiAlert.warning({
                    title: warningTitle,
                    text: warningText
                });
            } else {
                alert(`${warningTitle}\n\n${warningText}`);
            }
            return;
        }

        if (summary.activeCount === 0) {
            const warningTitle = @json(__('shipments.copy.no_items_allocated'));
            const warningText = @json(__('shipments.copy.please_select_and_allocate_at_least_one_po_item_for_delivery_before_submitting'));
            if (window.AdasiAlert) {
                window.AdasiAlert.warning({
                    title: warningTitle,
                    text: warningText
                });
            } else {
                alert(`${warningTitle}\n\n${warningText}`);
            }
            return;
        }

        const title = @js(__('shipments.confirmations.submit.title'));
        const text = @js(__('shipments.confirmations.submit.body'));

        const doSubmit = () => {
            let actionInput = document.getElementById('shipmentFormAction');
            if (!actionInput) {
                actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.id = 'shipmentFormAction';
                createForm.appendChild(actionInput);
            }
            actionInput.value = 'submit';

            window.AdasiButton?.startLoading(submitShipmentBtn);
            createForm.submit();
        };

        if (window.AdasiAlert) {
            window.AdasiAlert.confirm({
                title: title,
                text: text,
                confirmText: @json(__('shipments.copy.yes_submit_delivery')),
                cancelText: @json(__('shipments.copy.cancel')),
                confirmTone: 'primary'
            }).then(result => {
                if (result.isConfirmed) {
                    doSubmit();
                }
            });
        } else if (confirm(`${title}\n\n${text}`)) {
            doSubmit();
        }
    });

    // Initial summary
    updateSummary();
});
</script>
@endpush
