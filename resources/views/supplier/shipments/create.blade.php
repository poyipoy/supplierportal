@extends('layouts.app')

@php
    $isEditing = isset($shipment) && $shipment;
    $notReadyCount = $notReadyCount ?? 0;
    $preshipment = $preshipment ?? ['values' => [], 'reused' => false];
    $invoiceRequired = \App\Support\Procurement\ProcurementFeatures::requireCommercialInvoiceForShipment();
    $psiHasErrors = collect($errors->keys())->contains(fn ($key) => str_starts_with((string) $key, 'preshipment'));
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
<div class="tw:grid tw:gap-4">
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
            <x-ui.button :href="route('supplier.shipments.index')" variant="outline" size="sm">
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
                :description="$notReadyCount > 0
                    ? __('shipments.audit_ui.not_ready_empty')
                    : __('shipments.copy.all_your_active_purchase_orders_have_been_fully_delivered_or_no_active_pos_are_currently_awaiting_de')"
            />
        </x-ui.card>
    @else
        <form method="POST" action="{{ $isEditing ? route('supplier.shipments.update', $shipment) : route('supplier.shipments.store') }}" id="shipmentCreateForm" enctype="multipart/form-data" data-async-submit class="tw:grid tw:gap-4">
            @csrf
            @if($isEditing) @method('PUT') @endif
            <input type="hidden" name="action" id="shipmentFormAction" value="draft">
            {{-- E5: Commercial Invoice is the first delivery requirement; MTC follows it. --}}
            @if($invoiceRequired)
                <x-ui.alert tone="warning">{{ __('shipments.copy.commercial_invoice_required_body') }}</x-ui.alert>
            @elseif(\App\Support\Procurement\ProcurementFeatures::shipmentMtcEnabled())
                <x-ui.alert tone="info">{{ __('shipments.mtc.create_hint') }}</x-ui.alert>
            @endif
            @if($notReadyCount > 0)
                <x-ui.alert tone="warning">
                    {{ trans_choice('shipments.audit_ui.not_ready_hidden', $notReadyCount, ['count' => $notReadyCount]) }}
                    <a href="{{ route('supplier.purchase-orders.index') }}" class="tw:font-semibold">{{ __('shipments.audit_ui.not_ready_link') }}</a>
                </x-ui.alert>
            @endif

            {{-- 1. General Logistics Information --}}
            <x-ui.card :title="__('shipments.copy.shipment_logistics_information')" :description="__('shipments.copy.set_key_dispatch_details_and_container_or_forwarding_notes_for_this_consignment')">
                <div class="tw:grid tw:gap-3 tw:sm:grid-cols-3">
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
                        <label for="shipmentNotes" class="ui-label tw:text-ui-xs tw:font-semibold">
                            {{ __('shipments.copy.logistics_notes_remarks') }}
                        </label>
                        <input
                            type="text"
                            name="notes"
                            id="shipmentNotes"
                            class="ui-input ui-input--sm @error('notes') is-invalid @enderror"
                            value="{{ old('notes', $shipment?->notes) }}"
                            placeholder="{{ __('shipments.copy.e_g_container_no_vessel_name_forwarder') }}"
                        >
                        @error('notes')<div class="ui-field-error">{{ $message }}</div>@enderror
                    </div>

                    <div>
                        <label for="shipmentInvoiceNumber" class="ui-label tw:text-ui-xs tw:font-semibold">
                            {{ __('shipments.copy.invoice_number') }} <span class="tw:text-error" aria-hidden="true">*</span>
                        </label>
                        <input
                            type="text"
                            name="invoice_number"
                            id="shipmentInvoiceNumber"
                            class="ui-input ui-input--sm @error('invoice_number') is-invalid @enderror"
                            value="{{ old('invoice_number', $shipment?->invoice_number) }}"
                            placeholder="{{ __('shipments.copy.invoice_number_placeholder') }}"
                            maxlength="100"
                            required
                            aria-describedby="shipmentInvoiceNumberHelp"
                        >
                        <div id="shipmentInvoiceNumberHelp" class="ui-field-help">{{ __('shipments.copy.invoice_number_help') }}</div>
                        @error('invoice_number')<div class="ui-field-error">{{ $message }}</div>@enderror
                    </div>

                    <div>
                        <label for="shipmentSupplierReference" class="ui-label tw:text-ui-xs tw:font-semibold">
                            {{ __('shipments.copy.supplier_reference') }}
                        </label>
                        <input
                            type="text"
                            name="supplier_reference"
                            id="shipmentSupplierReference"
                            class="ui-input ui-input--sm @error('supplier_reference') is-invalid @enderror"
                            value="{{ old('supplier_reference', $shipment?->supplier_reference) }}"
                            placeholder="{{ __('shipments.copy.supplier_reference_placeholder') }}"
                            maxlength="120"
                            aria-describedby="shipmentSupplierReferenceHelp"
                        >
                        <div id="shipmentSupplierReferenceHelp" class="ui-field-help">{{ __('shipments.copy.supplier_reference_help') }}</div>
                        @error('supplier_reference')<div class="ui-field-error">{{ $message }}</div>@enderror
                    </div>

                    <div>
                        <label for="shipmentCommercialInvoiceFile" class="ui-label tw:text-ui-xs tw:font-semibold">
                            {{ __('shipments.copy.commercial_invoice') }}
                        </label>
                        <input
                            type="file"
                            name="commercial_invoice_file"
                            id="shipmentCommercialInvoiceFile"
                            class="ui-input ui-input--sm @error('commercial_invoice_file') is-invalid @enderror"
                            accept=".pdf,.jpg,.jpeg,.png,.xlsx,.doc,.docx"
                        >
                        <div class="ui-field-help">{{ __('shipments.copy.commercial_invoice_required_before_submit') }}</div>
                        @error('commercial_invoice_file')<div class="ui-field-error">{{ $message }}</div>@enderror
                    </div>
                </div>
            </x-ui.card>

            {{-- E9: Pre-Shipment Inspection (reused from the supplier's last shipment) --}}
            @php
                $psiValues = $preshipment['values'] ?? [];
                $psiSummaryVal = fn (string $key) => trim((string) old('preshipment.'.$key, $psiValues[$key] ?? ''));
                $psiSameAsExporter = filter_var(old('preshipment.same_as_exporter', $preshipment['same_as_exporter'] ?? false), FILTER_VALIDATE_BOOLEAN);
                $psiIsComplete = $psiSummaryVal('exporter_name') !== ''
                    && $psiSummaryVal('exporter_address') !== ''
                    && $psiSummaryVal('exporter_city') !== ''
                    && $psiSummaryVal('exporter_country') !== ''
                    && $psiSummaryVal('exporter_port_of_loading') !== ''
                    && (
                        $psiSameAsExporter
                        || (
                            $psiSummaryVal('supplier_name') !== ''
                            && $psiSummaryVal('supplier_address') !== ''
                            && $psiSummaryVal('supplier_city') !== ''
                            && $psiSummaryVal('supplier_country') !== ''
                        )
                    );
                $psiStatusTone = $psiHasErrors ? 'error' : ($psiIsComplete ? 'success' : 'warning');
                $psiStatusLabel = $psiHasErrors
                    ? __('shipments.preshipment.status_attention')
                    : ($psiIsComplete ? __('shipments.preshipment.status_ready') : __('shipments.preshipment.status_incomplete'));
            @endphp
            <x-ui.card :title="__('shipments.preshipment.title')" :description="__('shipments.preshipment.description')" id="shipmentPreshipment">
                <div class="tw:flex tw:flex-wrap tw:items-start tw:justify-between tw:gap-3" id="psiSummary" aria-live="polite">
                    <div class="tw:grid tw:gap-2 tw:flex-1 tw:min-w-0">
                        <div class="tw:flex tw:flex-wrap tw:items-center tw:gap-2">
                            <span data-psi-status-badge class="ui-status-chip ui-status-chip--{{ $psiStatusTone }}">{{ $psiStatusLabel }}</span>
                            <span
                                data-psi-mirror-badge
                                class="ui-status-chip ui-status-chip--info"
                                @unless($psiSameAsExporter) hidden @endunless
                            >{{ __('shipments.preshipment.same_as_exporter_badge') }}</span>
                        </div>
                        <dl class="tw:m-0 tw:grid tw:gap-x-6 tw:gap-y-2 tw:text-ui-xs tw:sm:grid-cols-2">
                            <div>
                                <dt class="tw:text-on-surface-variant tw:font-semibold">{{ __('shipments.preshipment.exporter_title') }}</dt>
                                <dd class="tw:m-0 tw:text-on-surface tw:font-semibold" data-psi-summary="exporter_name">{{ $psiSummaryVal('exporter_name') ?: __('shipments.preshipment.summary_empty') }}</dd>
                                <dd class="tw:m-0 tw:text-on-surface-variant">
                                    <span>{{ __('shipments.preshipment.port_of_loading') }}:</span>
                                    <span data-psi-summary="exporter_port_of_loading">{{ $psiSummaryVal('exporter_port_of_loading') ?: __('shipments.preshipment.summary_empty') }}</span>
                                </dd>
                            </div>
                            <div>
                                <dt class="tw:text-on-surface-variant tw:font-semibold">{{ __('shipments.preshipment.supplier_title') }}</dt>
                                <dd class="tw:m-0 tw:text-on-surface tw:font-semibold" data-psi-summary="supplier_name">{{ $psiSummaryVal('supplier_name') ?: __('shipments.preshipment.summary_empty') }}</dd>
                                <dd class="tw:m-0 tw:text-on-surface-variant">
                                    <span>{{ __('shipments.preshipment.city') }}:</span>
                                    <span data-psi-summary="supplier_city">{{ $psiSummaryVal('supplier_city') ?: __('shipments.preshipment.summary_empty') }}</span>
                                </dd>
                            </div>
                        </dl>
                    </div>
                    <x-ui.button type="button" variant="outline" size="sm" x-data @click="$dispatch('open-ui-dialog', 'shipment-psi-form')" class="tw:min-h-[44px] tw:sm:min-h-0">
                        <x-slot:leading><x-ui.icon name="pencil" size="sm" /></x-slot:leading>
                        <span>{{ __('shipments.preshipment.fill_psi') }}</span>
                    </x-ui.button>
                </div>
            </x-ui.card>

            {{-- 2. Allocation Filter Toolbar --}}
            @php
                $groupedByPo = $poItems->groupBy(fn($item) => $item['po']->id);
                $globalIndex = 0;
            @endphp

            <div class="tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-2 tw:p-3 tw:bg-surface-low tw:border tw:border-outline-variant tw:rounded-ui-md">
                <div class="tw:flex tw:items-center tw:gap-2">
                    <span class="tw:font-bold tw:text-ui-sm tw:text-on-surface">{{ __('shipments.copy.material_allocation') }}</span>
                    <span class="ui-status-chip ui-status-chip--info ui-tabular-nums">
                        {{ __('shipments.page.allocation_counts', ['pos' => $groupedByPo->count(), 'items' => $poItems->count()]) }}
                    </span>
                </div>

                <div class="tw:flex tw:items-center tw:gap-2" style="min-width: 260px;">
                    <div class="ui-input-group ui-input-group--sm">
                        <span class="ui-input-group-text tw:bg-surface tw:border-e-0 tw:text-on-surface-variant">
                            <x-ui.icon name="search" size="sm" />
                        </span>
                        <input
                            type="text"
                            id="quickSearchItems"
                            class="ui-input ui-input--sm tw:border-s-0 tw:ps-0"
                            placeholder="{{ __('shipments.copy.filter_po_or_material_name') }}"
                            autocomplete="off"
                        >
                    </div>
                </div>
            </div>

            {{-- 3. PO-Grouped Allocation Cards --}}
            <div class="tw:grid tw:gap-4" id="poGroupsContainer">
                @foreach($groupedByPo as $poId => $itemsInPo)
                    @php
                        $firstItem = $itemsInPo->first();
                        $po = $firstItem['po'];
                        $isPoPreselected = $preselectedPoId && (int)$po->id === (int)$preselectedPoId;
                    @endphp
                    <div class="po-group-card" data-po-number="{{ strtolower($po->po_number) }}">
                        <div class="po-group-header tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-2">
                            <div class="tw:flex tw:items-center tw:gap-2">
                                <span class="tw:font-bold tw:text-ui-sm tw:text-primary">{{ $po->po_number }}</span>
                                @if($po->pr_reference)
                                    <span class="ui-status-chip ui-status-chip--neutral tw:text-ui-xs">
                                        {{ __('purchasing.copy.pr_no') }}: {{ $po->pr_reference }}
                                    </span>
                                @endif
                                <span class="tw:text-on-surface-variant tw:text-ui-xs">
                                    ({{ trans_choice('shipments.audit_ui.pending_items', $itemsInPo->count(), ['count' => $itemsInPo->count()]) }})
                                </span>
                            </div>

                            <div class="tw:flex tw:items-center tw:gap-2">
                                <x-ui.button type="button" variant="outline-primary" size="sm" class="po-select-all-btn">
                                    <x-ui.icon name="check-check" size="sm" />
                                    <span>{{ __('shipments.copy.select_all_in_po') }}</span>
                                </x-ui.button>
                                <x-ui.button type="button" variant="outline" size="sm" class="po-clear-btn">
                                    <span>{{ __('shipments.copy.clear') }}</span>
                                </x-ui.button>
                            </div>
                        </div>

                        <div class="ui-table-responsive">
                            <table class="ui-table ui-table--hover tw:align-middle tw:m-0 tw:text-ui-xs tw:w-full">
                                <thead>
                                    <tr>
                                        <th scope="col" style="width: 44px;" class="tw:text-center">{{ __('shipments.copy.select') }}</th>
                                        <th scope="col">{{ __('shipments.copy.material_specifications') }}</th>
                                        <th scope="col" class="tw:text-end">{{ __('shipments.copy.ordered_qty') }}</th>
                                        <th scope="col" class="tw:text-end">{{ __('shipments.copy.already_shipped') }}</th>
                                        <th scope="col" class="tw:text-end">{{ __('shipments.copy.remaining_qty') }}</th>
                                        <th scope="col" class="tw:text-end" style="width: 170px;">{{ __('shipments.copy.this_shipment_qty') }}</th>
                                        <th scope="col" class="tw:text-end" style="width: 150px;">{{ __('shipments.copy.actual_kg') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($itemsInPo as $itemData)
                                        @php
                                            $currentIndex = $globalIndex++;
                                            $currentShippedQty = $itemData['current_qty'] ?? null;
                                            $currentActualWeightKg = $itemData['current_actual_weight_kg'] ?? null;
                                            $isPreselected = $currentShippedQty !== null || ($isPoPreselected && $currentShippedQty === null);
                                            $prItem = $itemData['pr_item'];
                                        @endphp
                                        <tr class="item-alloc-row" data-material-name="{{ strtolower($prItem->material_name ?? '') }}">
                                            <td class="tw:text-center">
                                                <input
                                                    type="checkbox"
                                                    class="ui-check alloc-toggle"
                                                    id="toggle_{{ $currentIndex }}"
                                                    {{ $isPreselected ? 'checked' : '' }}
                                                    aria-label="{{ __('shipments.a11y.select_item', ['material' => $prItem->material_name]) }}"
                                                >
                                            </td>
                                            <td>
                                                <div class="tw:font-semibold tw:text-on-surface">
                                                    {{ $prItem->material_name }}
                                                    @if($itemData['not_ready'] ?? false)
                                                        <span class="ui-status-chip ui-status-chip--warning tw:text-ui-xs">{{ __('shipments.audit_ui.not_ready_chip') }}</span>
                                                    @endif
                                                </div>
                                                <div class="tw:text-on-surface-variant tw:text-ui-xs">
                                                    {{ __('purchasing.copy.shape') }}: {{ $prItem->shape ?? '-' }}
                                                    @if($prItem?->thickness) | T: {{ $prItem->thickness }}mm @endif
                                                    @if($prItem?->width) | W: {{ $prItem->width }}mm @endif
                                                    @if($prItem?->d_outer) | OD: {{ $prItem->d_outer }}mm @endif
                                                    @if($prItem?->length) | L: {{ $prItem->length }}mm @endif
                                                </div>
                                            </td>
                                            <td class="tw:text-end ui-tabular-nums tw:text-on-surface-variant">
                                                {{ number_format($itemData['ordered_qty'] ?? $itemData['ordered']) }} pcs
                                            </td>
                                            <td class="tw:text-end ui-tabular-nums tw:text-on-surface-variant">
                                                {{ number_format($itemData['allocated_qty'] ?? $itemData['allocated']) }} pcs
                                            </td>
                                            <td class="tw:text-end tw:font-bold tw:text-primary ui-tabular-nums">
                                                {{ number_format($itemData['remaining_qty'] ?? $itemData['remaining']) }} pcs
                                            </td>
                                            <td class="tw:text-end">
                                                <input type="hidden" name="items[{{ $currentIndex }}][purchase_order_id]" value="{{ $itemData['po']->id }}">
                                                <input type="hidden" name="items[{{ $currentIndex }}][quotation_item_id]" value="{{ $itemData['quotation_item']->id }}">
                                                <div class="ui-input-group ui-input-group--sm">
                                                    <input
                                                        type="number"
                                                        step="1"
                                                        min="1"
                                                        max="{{ $itemData['remaining_qty'] ?? $itemData['remaining'] }}"
                                                        name="items[{{ $currentIndex }}][shipped_qty]"
                                                        value="{{ old("items.{$currentIndex}.shipped_qty", $currentShippedQty ?? ($isPreselected ? ($itemData['remaining_qty'] ?? $itemData['remaining']) : '')) }}"
                                                        class="ui-input ui-input--sm tw:text-end shipped-qty-input"
                                                        placeholder="0"
                                                        data-remaining="{{ $itemData['remaining_qty'] ?? $itemData['remaining'] }}"
                                                        aria-label="{{ __('shipments.a11y.ship_quantity', ['material' => $prItem->material_name]) }}"
                                                    >
                                                    <button type="button" class="ui-button ui-button--outline ui-button--sm fill-all-btn" title="{{ __('shipments.copy.ship_all_remaining_quantity') }}">
                                                        {{ __('shipments.copy.all') }}
                                                    </button>
                                                </div>
                                            </td>
                                            <td class="tw:text-end">
                                                <input
                                                    type="number"
                                                    step="0.0001"
                                                    min="0.0001"
                                                    name="items[{{ $currentIndex }}][actual_weight_kg]"
                                                    value="{{ old("items.{$currentIndex}.actual_weight_kg", $currentActualWeightKg ?? '') }}"
                                                    class="ui-input ui-input--sm tw:text-end actual-weight-input"
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
            <div class="sticky-summary-bar tw:mt-4 tw:rounded-ui-md tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-3">
                <div class="tw:flex tw:items-center tw:gap-3">
                    <div class="tw:inline-flex tw:items-center tw:justify-center tw:h-9 tw:w-9 tw:rounded-full tw:bg-primary-container tw:text-primary">
                        <x-ui.icon name="truck" size="sm" />
                    </div>
                    <div>
                        <div class="tw:font-bold tw:text-ui-sm tw:text-on-surface" id="shipmentSummaryTitle">
                            {{ __('shipments.copy.consignment_allocation_summary') }}
                        </div>
                        <div class="tw:text-ui-xs tw:text-on-surface-variant" id="shipmentSummaryText">
                            {{ __('shipments.copy.0_item_s_allocated_for_delivery') }}
                        </div>
                    </div>
                </div>

                <div class="tw:flex tw:flex-wrap tw:items-center tw:gap-2">
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

            <x-ui.dialog
                name="shipment-psi-form"
                :title="__('shipments.preshipment.title')"
                :description="__('shipments.preshipment.description')"
                max-width="4xl"
                :show="$psiHasErrors"
                id="shipmentPsiDialog"
                x-on:psi:panel-mode.window="if ($event.detail?.idPrefix === 'dialog_psi') $dispatch('ui-dialog-actions', { name: 'shipment-psi-form', hidden: $event.detail.mode === 'manage' })"
            >
                <div id="shipmentPsiFields">
                    @include('supplier.shipments._preshipment_form', [
                        'preshipment' => $preshipment,
                        'embedded' => true,
                        'idPrefix' => 'dialog_psi',
                    ])
                </div>
                <x-slot:actions>
                    <x-ui.button type="button" variant="primary" size="sm" x-data @click="$dispatch('close-ui-dialog', 'shipment-psi-form')">
                        {{ __('shipments.preshipment.done') }}
                    </x-ui.button>
                </x-slot:actions>
            </x-ui.dialog>
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
                warning.className = 'tw:text-error tw:font-semibold';
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
                row.classList.add('tw:bg-primary-container/40');
            } else {
                row.classList.remove('tw:bg-primary-container/40');
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
                row.classList.add('tw:bg-primary-container/40');
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
                row.classList.remove('tw:bg-primary-container/40');
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

    // PSI lives in its own dialog: keep the summary card in sync, avoid hidden-modal
    // constraint-validation hangs, and open the dialog on validation failures.
    const psiEmptyText = @js(__('shipments.preshipment.summary_empty'));
    const psiReadyLabel = @js(__('shipments.preshipment.status_ready'));
    const psiIncompleteLabel = @js(__('shipments.preshipment.status_incomplete'));
    const psiAttentionLabel = @js(__('shipments.preshipment.status_attention'));
    const psiFieldVal = (key) => (createForm?.querySelector(`[name="preshipment[${key}]"]`)?.value || '').trim();
    const isSameAsExporter = () => Boolean(createForm?.querySelector('[type="checkbox"][name="preshipment[same_as_exporter]"]')?.checked);
    const isPsiComplete = () => {
        const exporterOk = ['exporter_name', 'exporter_address', 'exporter_city', 'exporter_country', 'exporter_port_of_loading']
            .every((key) => psiFieldVal(key) !== '');
        if (!exporterOk) return false;
        if (isSameAsExporter()) return true;

        return ['supplier_name', 'supplier_address', 'supplier_city', 'supplier_country']
            .every((key) => psiFieldVal(key) !== '');
    };
    const refreshPsiSummary = (hasAttention = false) => {
        const same = isSameAsExporter();
        document.querySelectorAll('[data-psi-summary]').forEach((node) => {
            const key = node.dataset.psiSummary;
            const resolvedKey = same && key.startsWith('supplier_')
                ? `exporter_${key.slice('supplier_'.length)}`
                : key;
            node.textContent = psiFieldVal(resolvedKey) || psiEmptyText;
        });

        const mirrorBadge = document.querySelector('[data-psi-mirror-badge]');
        if (mirrorBadge) {
            mirrorBadge.hidden = !same;
        }

        const badge = document.querySelector('[data-psi-status-badge]');
        if (badge) {
            badge.classList.remove('ui-status-chip--success', 'ui-status-chip--warning', 'ui-status-chip--error');
            if (hasAttention) {
                badge.classList.add('ui-status-chip--error');
                badge.textContent = psiAttentionLabel;
            } else if (isPsiComplete()) {
                badge.classList.add('ui-status-chip--success');
                badge.textContent = psiReadyLabel;
            } else {
                badge.classList.add('ui-status-chip--warning');
                badge.textContent = psiIncompleteLabel;
            }
        }
    };
    const revealInvalidPsiControl = () => {
        const invalidInPsi = [...(createForm?.querySelectorAll('#shipmentPsiDialog :invalid') || [])][0];
        if (!invalidInPsi) return false;
        window.dispatchEvent(new CustomEvent('open-ui-dialog', { detail: 'shipment-psi-form' }));
        window.requestAnimationFrame(() => window.requestAnimationFrame(() => {
            const focusTarget = invalidInPsi.closest?.('[x-data^="searchableSelect"]')?.querySelector?.('[role="combobox"]') || invalidInPsi;
            focusTarget.focus?.();
            invalidInPsi.reportValidity?.();
        }));
        return true;
    };
    window.addEventListener('close-ui-dialog', (event) => {
        const detail = event?.detail;
        if (!detail || detail === 'shipment-psi-form' || detail?.name === 'shipment-psi-form') {
            refreshPsiSummary(false);
        }
    });
    createForm?.addEventListener('input', (event) => {
        if (event.target?.name?.startsWith?.('preshipment[')) {
            refreshPsiSummary(false);
        }
    });
    createForm?.addEventListener('change', (event) => {
        if (event.target?.name?.startsWith?.('preshipment[')) {
            refreshPsiSummary(false);
        }
    });
    createForm?.addEventListener('adasi:form-errors', (event) => {
        const errors = event.detail?.errors || {};
        const hasPsiErrors = Object.keys(errors).some((k) => k === 'preshipment' || k.startsWith('preshipment.'));
        refreshPsiSummary(hasPsiErrors);
    });
    createForm?.addEventListener('adasi:reveal-field', (event) => {
        if (event.detail?.element?.closest?.('#shipmentPsiDialog')) {
            window.dispatchEvent(new CustomEvent('open-ui-dialog', { detail: 'shipment-psi-form' }));
        }
    });
    refreshPsiSummary(@js($psiHasErrors));

    btnSaveDraft?.addEventListener('click', (e) => {
        const actionInput = document.getElementById('shipmentFormAction');
        if (actionInput) {
            actionInput.value = 'draft';
        }
        if (revealInvalidPsiControl()) {
            e.preventDefault();
        }
    });

    submitShipmentBtn?.addEventListener('click', (e) => {
        e.preventDefault();

        if (revealInvalidPsiControl()) {
            return;
        }

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

        // E5: a new shipment without a Commercial Invoice file cannot be submitted directly.
        const invoiceFileInput = document.getElementById('shipmentCommercialInvoiceFile');
        const hasSelectedInvoiceFile = Boolean(invoiceFileInput && invoiceFileInput.files && invoiceFileInput.files.length > 0);
        if (@js($invoiceRequired) && !hasSelectedInvoiceFile) {
            const invoiceTitle = @js(__('shipments.copy.commercial_invoice_required_title'));
            const invoiceText = @js(__('shipments.copy.commercial_invoice_required_body'));
            const saveDraft = () => {
                const actionInput = document.getElementById('shipmentFormAction');
                if (actionInput) {
                    actionInput.value = 'draft';
                }
                window.AdasiButton?.startLoading(submitShipmentBtn);
                if (typeof createForm.requestSubmit === 'function') {
                    createForm.requestSubmit();
                } else {
                    createForm.submit();
                }
            };

            if (window.AdasiAlert) {
                window.AdasiAlert.confirm({
                    title: invoiceTitle,
                    text: invoiceText,
                    confirmText: @js(__('shipments.copy.save_as_draft')),
                    cancelText: @js(__('shipments.copy.cancel')),
                    confirmTone: 'primary'
                }).then(result => {
                    if (result.isConfirmed) {
                        saveDraft();
                    }
                });
            } else if (confirm(`${invoiceTitle}\n\n${invoiceText}`)) {
                saveDraft();
            }
            return;
        }

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
            if (typeof createForm.requestSubmit === 'function') {
                createForm.requestSubmit();
            } else {
                createForm.submit();
            }
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
