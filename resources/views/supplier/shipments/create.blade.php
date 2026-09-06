@extends('layouts.app')

@php
    $isEditing = isset($shipment) && $shipment;
@endphp
@section('title', ($isEditing ? 'Edit Shipment' : 'Create New Shipment') . ' - ADASI Portal')
@section('page-title', $isEditing ? 'Edit Shipment' : 'Create Shipment')

@section('content')
<div class="tw-grid tw-gap-4">
    {{-- Breadcrumb & Page Header --}}
    <x-ui.breadcrumb :items="[
        'Dashboard' => route('supplier.dashboard'),
        'Shipments' => route('supplier.shipments.index'),
        ($isEditing ? 'Edit' : 'Create') => null,
    ]" />

    <x-ui.page-header
        :title="$isEditing ? 'Edit Draft Shipment' : 'Create New Shipment'"
        eyebrow="Logistics &amp; Delivery"
        description="Allocate shipped quantities against your active Purchase Orders. You can combine items from multiple POs into one delivery batch."
    >
        <x-slot:actions>
            <x-ui.button :href="route('supplier.shipments.index')" variant="ghost" size="sm">
                <x-slot:leading><x-ui.icon name="arrow-left" size="sm" /></x-slot:leading>
                <span>Back to Shipments</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if($poItems->isEmpty())
        <x-ui.card padding="none">
            <x-ui.empty-state
                icon="package-check"
                title="No Pending Deliveries"
                description="All your active Purchase Orders have been fully delivered, or no active POs are currently awaiting delivery."
            />
        </x-ui.card>
    @else
        <form method="POST" action="{{ $isEditing ? route('supplier.shipments.update', $shipment) : route('supplier.shipments.store') }}" id="shipmentCreateForm">
            @csrf
            @if($isEditing) @method('PUT') @endif

            {{-- General Shipment Logistics Info --}}
            <x-ui.card title="Shipment Logistics Information" class="tw-mb-4">
                <div class="tw-grid tw-gap-3 sm:tw-grid-cols-3">
                    <div>
                        <label for="shipmentDate" class="form-label small fw-semibold">Shipment / Dispatch Date <span class="text-danger">*</span></label>
                        <input type="date" name="shipment_date" id="shipmentDate" class="form-control form-control-sm @error('shipment_date') is-invalid @enderror" value="{{ old('shipment_date', $shipment?->shipment_date?->format('Y-m-d') ?? now()->format('Y-m-d')) }}" required>
                        @error('shipment_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div>
                        <label for="estimatedArrivalDate" class="form-label small fw-semibold">Estimated Arrival Date <span class="text-danger">*</span></label>
                        <input type="date" name="estimated_arrival_date" id="estimatedArrivalDate" class="form-control form-control-sm @error('estimated_arrival_date') is-invalid @enderror" value="{{ old('estimated_arrival_date', $shipment?->estimated_arrival_date?->format('Y-m-d') ?? now()->addDays(14)->format('Y-m-d')) }}" required>
                        @error('estimated_arrival_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div>
                        <label for="shipmentNotes" class="form-label small fw-semibold">Shipment Remarks / Notes</label>
                        <input type="text" name="notes" id="shipmentNotes" class="form-control form-control-sm @error('notes') is-invalid @enderror" value="{{ old('notes', $shipment?->notes) }}" placeholder="e.g. Container number, forwarder name...">
                        @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
            </x-ui.card>

            {{-- PO Item Allocations Table --}}
            <x-ui.data-table
                title="Material Line-Item Allocations"
                description="Enter shipped quantity for the items you are delivering. You may deliver partial quantities."
            >
                <table class="table table-hover align-middle mb-0 tw-text-ui-xs w-100">
                    <thead class="table-light">
                        <tr>
                            <th scope="col" style="width: 40px;" class="text-center">Select</th>
                            <th scope="col">PO Number</th>
                            <th scope="col">Material Name</th>
                            <th scope="col" class="text-end">Ordered (Kg)</th>
                            <th scope="col" class="text-end">Already Shipped (Kg)</th>
                            <th scope="col" class="text-end">Remaining (Kg)</th>
                            <th scope="col" class="text-end" style="width: 180px;">This Shipment (Kg)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($poItems as $index => $itemData)
                            @php
                                $currentQuantity = $itemData['current_quantity'] ?? null;
                                $isPreselected = $currentQuantity !== null || ($preselectedPoId && (int)$itemData['po']->id === (int)$preselectedPoId);
                            @endphp
                            <tr class="item-alloc-row">
                                <td class="text-center">
                                    <input type="checkbox"
                                           class="form-check-input alloc-toggle"
                                           id="toggle_{{ $index }}"
                                           {{ $isPreselected ? 'checked' : '' }}
                                    >
                                </td>
                                <td class="fw-bold tw-text-on-surface">
                                    {{ $itemData['po']->po_number }}
                                    <div class="tw-text-on-surface-variant tw-text-ui-xs">
                                        {{ $itemData['po']->pr_reference }}
                                    </div>
                                </td>
                                <td class="fw-medium">
                                    {{ $itemData['pr_item']->material_name }}
                                </td>
                                <td class="text-end ui-tabular-nums tw-text-on-surface-variant">
                                    {{ \App\Support\NumberFormat::maxDecimals($itemData['ordered']) }}
                                </td>
                                <td class="text-end ui-tabular-nums tw-text-on-surface-variant">
                                    {{ \App\Support\NumberFormat::maxDecimals($itemData['allocated']) }}
                                </td>
                                <td class="text-end fw-bold text-primary ui-tabular-nums">
                                    {{ \App\Support\NumberFormat::maxDecimals($itemData['remaining']) }}
                                </td>
                                <td class="text-end">
                                    <input type="hidden" name="items[{{ $index }}][purchase_order_id]" value="{{ $itemData['po']->id }}">
                                    <input type="hidden" name="items[{{ $index }}][quotation_item_id]" value="{{ $itemData['quotation_item']->id }}">
                                    <div class="input-group input-group-sm">
                                        <input type="number"
                                               step="0.0001"
                                               min="0.0001"
                                               max="{{ $itemData['remaining'] }}"
                                               name="items[{{ $index }}][shipped_quantity]"
                                               value="{{ old("items.{$index}.shipped_quantity", $currentQuantity ?? ($isPreselected ? $itemData['remaining'] : '')) }}"
                                               class="form-control form-control-sm text-end shipped-input"
                                               placeholder="0.0000"
                                               data-remaining="{{ $itemData['remaining'] }}"
                                        >
                                        <button type="button" class="btn btn-outline-secondary btn-sm fill-all-btn" title="Ship full remaining balance">
                                            All
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-ui.data-table>

            {{-- Submission Action Bar --}}
            <div class="tw-mt-4 tw-p-4 tw-rounded tw-border tw-border-outline-variant tw-bg-surface tw-flex tw-flex-wrap tw-items-center tw-justify-between tw-gap-3">
                <div>
                    <div class="fw-bold tw-text-ui-sm tw-text-on-surface">Shipment Summary</div>
                    <div class="tw-text-ui-xs tw-text-on-surface-variant" id="shipmentSummaryText">
                        0 item(s) selected for delivery.
                    </div>
                </div>
                <div class="tw-flex tw-flex-wrap tw-gap-2">
                    <x-ui.button type="submit" name="action" value="draft" variant="outline" size="sm">
                        <x-slot:leading><x-ui.icon name="save" size="sm" /></x-slot:leading>
                        {{ $isEditing ? 'Save Draft Changes' : 'Save as Draft' }}
                    </x-ui.button>
                    @unless($isEditing)
                        <x-ui.button type="submit" name="action" value="submit" variant="primary" size="sm" id="btnSubmitShipment">
                            <x-slot:leading><x-ui.icon name="truck" size="sm" /></x-slot:leading>
                            Submit Shipment Delivery
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

    const updateSummary = () => {
        let activeCount = 0;
        let totalWeight = 0;

        rows.forEach(row => {
            const input = row.querySelector('.shipped-input');
            const qty = parseFloat(input.value) || 0;
            if (qty > 0) {
                activeCount++;
                totalWeight += qty;
            }
        });

        if (summaryText) {
            summaryText.textContent = `${activeCount} item(s) allocated · Total Shipped: ${totalWeight.toLocaleString('id-ID', { maximumFractionDigits: 4 })} Kg`;
        }
    };

    rows.forEach(row => {
        const toggle = row.querySelector('.alloc-toggle');
        const input = row.querySelector('.shipped-input');
        const fillAllBtn = row.querySelector('.fill-all-btn');
        const remaining = parseFloat(input.dataset.remaining) || 0;

        const syncRowState = () => {
            const enabled = toggle.checked;
            row.querySelectorAll('input[name^="items["]').forEach(field => {
                field.disabled = !enabled;
            });
        };

        if (!toggle.checked && parseFloat(input.value) > 0) {
            toggle.checked = true;
        }
        syncRowState();

        fillAllBtn?.addEventListener('click', () => {
            input.value = remaining;
            toggle.checked = true;
            syncRowState();
            updateSummary();
        });

        input?.addEventListener('input', () => {
            const val = parseFloat(input.value) || 0;
            toggle.checked = (val > 0);
            syncRowState();
            updateSummary();
        });

        toggle?.addEventListener('change', () => {
            if (toggle.checked && (!input.value || parseFloat(input.value) <= 0)) {
                input.value = remaining;
            } else if (!toggle.checked) {
                input.value = '';
            }
            syncRowState();
            updateSummary();
        });
    });

    updateSummary();
});
</script>
@endpush
