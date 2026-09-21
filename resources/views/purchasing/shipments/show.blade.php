@extends('layouts.app')

@section('title', 'Shipment: ' . $shipment->shipment_number . ' - ADASI Portal')
@section('page-title', 'Shipment Details')

@push('styles')
<style>
    .shipment-tracking-strip {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 0.75rem;
    }

    .shipment-tracking-step {
        padding: 0.75rem 1rem;
        background: var(--md-surface);
        border: 1px solid var(--md-outline-variant);
        border-radius: var(--md-shape-sm);
        position: relative;
    }

    .shipment-tracking-step.is-completed {
        border-color: var(--md-success);
        background: var(--md-surface-container-low);
    }

    .shipment-tracking-step.is-active {
        border-color: var(--md-primary);
        background: var(--md-primary-container);
    }

    .shipment-tracking-step.is-cancelled {
        border-color: var(--md-error);
        background: var(--md-error-container);
    }
</style>
@endpush

@section('content')
<div class="tw-grid tw-gap-4">
    {{-- 1. Breadcrumb & Page Header --}}
    <x-ui.breadcrumb :items="[
        'Dashboard' => route('purchasing.dashboard'),
        'Shipments' => route('purchasing.shipments.index'),
        $shipment->shipment_number => null,
    ]" />

    @php
        $pos = $shipment->purchaseOrders();
        $totalQty = (int) $shipment->items->sum('shipped_qty');
        $totalWeight = (float) $shipment->items->sum('actual_weight_kg');
        $hasQc = $shipment->qcInspections->isNotEmpty();
        $isCancelled = $shipment->status === 'cancelled';
        $isArrived = $shipment->status === 'arrived';
        $isSubmitted = $shipment->status === 'submitted';
        $isDraft = $shipment->status === 'draft';
    @endphp

    <x-ui.page-header
        :title="'Shipment ' . $shipment->shipment_number"
        eyebrow="Consignment Logistics & Receiving"
        description="Physical delivery verification, consolidated shipping documents, and receiving status."
    >
        <x-slot:actions>
            <div class="tw-flex tw-flex-wrap tw-gap-2 align-items-center">
                <x-ui.status-chip :tone="\App\Support\StatusHelper::shipmentTone($shipment->status)" size="md">
                    {{ \App\Support\StatusHelper::shipmentLabel($shipment->status) }}
                </x-ui.status-chip>

                @if($isSubmitted)
                    <button type="button" class="btn btn-primary btn-sm d-inline-flex align-items-center gap-1.5" data-bs-toggle="modal" data-bs-target="#confirmArrivalModal">
                        <x-ui.icon name="check-circle" size="sm" />
                        <span>Confirm Physical Arrival</span>
                    </button>
                @endif
            </div>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- 2. Visual Lifecycle Tracking Stepper --}}
    <div class="shipment-tracking-strip" aria-label="Shipment fulfillment progress">
        @if($isCancelled)
            <div class="shipment-tracking-step is-cancelled">
                <div class="tw-text-ui-xs fw-semibold text-danger">STATUS</div>
                <div class="fw-bold fs-6 text-danger">Cancelled</div>
                <div class="tw-text-ui-xs text-danger mt-1">Delivery batch was revoked</div>
            </div>
        @else
            {{-- Step 1: Draft --}}
            <div class="shipment-tracking-step {{ $isDraft ? 'is-active' : 'is-completed' }}">
                <div class="d-flex align-items-center justify-content-between">
                    <span class="tw-text-ui-xs fw-semibold tw-text-on-surface-variant">STEP 1</span>
                    <x-ui.icon :name="$isDraft ? 'circle-dot' : 'check'" size="sm" :class="$isDraft ? 'text-primary' : 'text-success'" />
                </div>
                <div class="fw-bold fs-6 tw-text-on-surface">Draft Allocation</div>
                <div class="tw-text-ui-xs tw-text-on-surface-variant mt-1">
                    {{ $shipment->created_at->format('d M Y') }}
                </div>
            </div>

            {{-- Step 2: Dispatched / In Transit --}}
            <div class="shipment-tracking-step {{ $isSubmitted ? 'is-active' : ($isArrived ? 'is-completed' : '') }}">
                <div class="d-flex align-items-center justify-content-between">
                    <span class="tw-text-ui-xs fw-semibold tw-text-on-surface-variant">STEP 2</span>
                    @if($isArrived)
                        <x-ui.icon name="check" size="sm" class="text-success" />
                    @elseif($isSubmitted)
                        <x-ui.icon name="truck" size="sm" class="text-primary" />
                    @else
                        <x-ui.icon name="circle" size="sm" class="tw-text-outline" />
                    @endif
                </div>
                <div class="fw-bold fs-6 tw-text-on-surface">In Transit</div>
                <div class="tw-text-ui-xs tw-text-on-surface-variant mt-1">
                    @if($shipment->shipment_date)
                        Dispatched: {{ $shipment->shipment_date->format('d M Y') }}
                    @else
                        Awaiting dispatch
                    @endif
                </div>
            </div>

            {{-- Step 3: Arrived at Plant --}}
            <div class="shipment-tracking-step {{ $isArrived && !$hasQc ? 'is-active' : ($isArrived && $hasQc ? 'is-completed' : '') }}">
                <div class="d-flex align-items-center justify-content-between">
                    <span class="tw-text-ui-xs fw-semibold tw-text-on-surface-variant">STEP 3</span>
                    @if($isArrived && $hasQc)
                        <x-ui.icon name="check" size="sm" class="text-success" />
                    @elseif($isArrived)
                        <x-ui.icon name="package-check" size="sm" class="text-primary" />
                    @else
                        <x-ui.icon name="circle" size="sm" class="tw-text-outline" />
                    @endif
                </div>
                <div class="fw-bold fs-6 tw-text-on-surface">Arrived at Plant</div>
                <div class="tw-text-ui-xs tw-text-on-surface-variant mt-1">
                    @if($shipment->actual_arrival_date)
                        Arrived: {{ $shipment->actual_arrival_date->format('d M Y') }}
                    @elseif($shipment->estimated_arrival_date)
                        ETA: {{ $shipment->estimated_arrival_date->format('d M Y') }}
                    @else
                        ETA pending
                    @endif
                </div>
            </div>

            {{-- Step 4: Quality Control --}}
            <div class="shipment-tracking-step {{ $hasQc ? 'is-completed' : '' }}">
                <div class="d-flex align-items-center justify-content-between">
                    <span class="tw-text-ui-xs fw-semibold tw-text-on-surface-variant">STEP 4</span>
                    @if($hasQc)
                        @php $allOk = $shipment->qcInspections->every(fn($i) => $i->status === 'ok'); @endphp
                        <x-ui.icon :name="$allOk ? 'check-circle' : 'alert-circle'" size="sm" :class="$allOk ? 'text-success' : 'text-danger'" />
                    @else
                        <x-ui.icon name="circle" size="sm" class="tw-text-outline" />
                    @endif
                </div>
                <div class="fw-bold fs-6 tw-text-on-surface">QC Inspection</div>
                <div class="tw-text-ui-xs tw-text-on-surface-variant mt-1">
                    @if($hasQc)
                        {{ $shipment->qcInspections->count() }} inspection report(s)
                    @elseif($isArrived)
                        Pending QC inspection
                    @else
                        Awaiting delivery
                    @endif
                </div>
            </div>
        @endif
    </div>

    {{-- 3. Delivery Package Overview Card --}}
    <x-ui.card title="Delivery Package Overview">
        <div class="tw-grid tw-gap-3 sm:tw-grid-cols-2 lg:tw-grid-cols-5">
            <div class="p-3 tw-bg-surface-low border tw-border-outline-variant rounded">
                <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">Supplier</div>
                <div class="fw-bold tw-text-on-surface fs-6 mt-1">
                    {{ $shipment->supplier->company_name ?? $shipment->supplier->name }}
                </div>
            </div>
            <div class="p-3 tw-bg-surface-low border tw-border-outline-variant rounded">
                <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">Consolidated POs</div>
                <div class="fw-bold tw-text-on-surface fs-6 mt-1 d-flex flex-wrap gap-1">
                    @forelse($pos as $po)
                        <a href="{{ route('purchasing.purchase-orders.show', $po) }}" class="text-primary text-decoration-none">
                            <span class="ui-status-chip ui-status-chip--neutral">{{ $po->po_number }}</span>
                        </a>
                    @empty
                        <span class="tw-text-outline">-</span>
                    @endforelse
                </div>
            </div>
            <div class="p-3 tw-bg-surface-low border tw-border-outline-variant rounded">
                <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">Total Consignment Qty</div>
                <div class="fw-bold text-primary fs-6 mt-1 ui-tabular-nums">
                    {{ number_format($totalQty) }} pcs
                </div>
            </div>
            <div class="p-3 tw-bg-surface-low border tw-border-outline-variant rounded">
                <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">Actual Weight</div>
                <div class="fw-semibold tw-text-on-surface fs-6 mt-1 ui-tabular-nums">
                    {{ \App\Support\NumberFormat::maxDecimals($totalWeight) }} Kg
                </div>
            </div>
            <div class="p-3 tw-bg-surface-low border tw-border-outline-variant rounded">
                <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">Logistics Timeline</div>
                <div class="fw-semibold tw-text-on-surface fs-6 mt-1">
                    @if($shipment->actual_arrival_date)
                        <span class="text-success fw-bold">Arrived: {{ $shipment->actual_arrival_date->format('d M Y') }}</span>
                    @elseif($shipment->estimated_arrival_date)
                        <span>ETA: {{ $shipment->estimated_arrival_date->format('d M Y') }}</span>
                    @else
                        <span>Departed: {{ $shipment->shipment_date ? $shipment->shipment_date->format('d M Y') : '-' }}</span>
                    @endif
                </div>
            </div>
        </div>

        @if($shipment->notes)
            <div class="mt-3 p-3 tw-bg-surface-container tw-border tw-border-outline-variant rounded tw-text-ui-xs">
                <strong>Logistics Notes:</strong> {{ $shipment->notes }}
            </div>
        @endif
    </x-ui.card>

    {{-- 4. Shared Shipping Documents Hub --}}
    <x-ui.card
        title="Shipping & Import Documents"
        description="Verify and update shipping documentation (Commercial Invoice, Packing List, Bill of Lading, Form E) for this shipment."
    >
        <div class="tw-grid tw-gap-3 sm:tw-grid-cols-2 lg:tw-grid-cols-4">
            @foreach($shipment->documents as $doc)
                @php
                    $attachment = $doc->latestAttachment;
                    $docLabel = match($doc->doc_type) {
                        'invoice' => 'Commercial Invoice',
                        'packing_list' => 'Packing List',
                        'bl' => 'Bill of Lading (BL)',
                        'form_e' => 'Form E / COO',
                        default => strtoupper($doc->doc_type),
                    };
                @endphp
                <div class="p-3 border tw-border-outline-variant rounded tw-bg-surface-low d-flex flex-column justify-content-between gap-2">
                    <div>
                        <div class="d-flex align-items-center justify-content-between gap-1">
                            <span class="fw-bold tw-text-ui-xs tw-text-on-surface">{{ $docLabel }}</span>
                            <x-ui.status-chip :tone="\App\Support\StatusHelper::shipmentDocTone($doc->status)" size="sm">
                                {{ \App\Support\StatusHelper::shipmentDocLabel($doc->status) }}
                            </x-ui.status-chip>
                        </div>

                        <div class="mt-2 tw-text-ui-xs">
                            @if($doc->document_number)
                                <div class="tw-text-on-surface-variant mb-1">
                                    <span class="fw-semibold">Ref:</span> {{ $doc->document_number }}
                                </div>
                            @endif

                            @if($attachment)
                                <a href="{{ route('attachments.show', $attachment->id) }}" target="_blank" class="text-primary text-decoration-none d-inline-flex align-items-center gap-1 fw-medium">
                                    <x-ui.icon name="paperclip" size="sm" />
                                    <span class="text-truncate" style="max-width: 170px;" title="{{ $attachment->file_name }}">
                                        {{ $attachment->file_name }}
                                    </span>
                                </a>
                            @else
                                <span class="tw-text-outline fst-italic">No file uploaded yet</span>
                            @endif
                        </div>
                    </div>

                    {{-- Status Update Form --}}
                    <form method="POST" action="{{ route('purchasing.shipments.documents.status', ['id' => $shipment, 'document_id' => $doc->id]) }}" class="pt-2 border-top tw-border-outline-variant">
                        @csrf
                        @method('PUT')
                        <div class="input-group input-group-sm">
                            <select name="status" class="form-select form-select-sm" aria-label="Change {{ $docLabel }} status">
                                @foreach(\App\Models\ShipmentDocument::STATUSES as $st)
                                    <option value="{{ $st }}" @selected($doc->status === $st)>
                                        {{ \App\Support\StatusHelper::shipmentDocLabel($st) }}
                                    </option>
                                @endforeach
                            </select>
                            <button type="submit" class="btn btn-outline-secondary btn-sm" title="Save status">
                                Update
                            </button>
                        </div>
                    </form>
                </div>
            @endforeach
        </div>
    </x-ui.card>

    {{-- 5. Consignment Line Items Table --}}
    <x-ui.card
        title="Consignment Line Items"
        description="Material batches delivered in this physical consignment."
    >
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 tw-text-ui-xs">
                <thead class="table-light">
                    <tr>
                        <th scope="col">PO Number</th>
                        <th scope="col">PR Reference</th>
                        <th scope="col">Material Name</th>
                        <th scope="col">Shape & Specs</th>
                        <th scope="col" class="text-end">Shipped Qty</th>
                        <th scope="col" class="text-end">Actual Weight</th>
                        <th scope="col">Item Notes</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($shipment->items as $item)
                        @php
                            $po = $item->purchaseOrder;
                            $prItem = $item->quotationItem?->prItem;
                            $pr = $prItem?->purchaseRequisition;
                        @endphp
                        <tr>
                            <td class="fw-bold">
                                <a href="{{ route('purchasing.purchase-orders.show', $po) }}" class="text-primary text-decoration-none">
                                    {{ $po->po_number }}
                                </a>
                            </td>
                            <td>
                                @if($pr)
                                    <span class="ui-status-chip ui-status-chip--neutral">{{ $pr->pr_number }}</span>
                                @else
                                    <span class="tw-text-outline">-</span>
                                @endif
                            </td>
                            <td class="fw-semibold tw-text-on-surface">
                                {{ $prItem->material_name ?? 'Material Item' }}
                            </td>
                            <td class="tw-text-on-surface-variant">
                                Shape: {{ $prItem->shape ?? '-' }}
                                @if($prItem?->thickness) | T: {{ $prItem->thickness }}mm @endif
                                @if($prItem?->width) | W: {{ $prItem->width }}mm @endif
                                @if($prItem?->length) | L: {{ $prItem->length }}mm @endif
                                @if($prItem?->d_outer) | OD: {{ $prItem->d_outer }}mm @endif
                            </td>
                            <td class="text-end fw-bold text-primary ui-tabular-nums">
                                {{ number_format($item->shipped_qty) }} pcs
                            </td>
                            <td class="text-end ui-tabular-nums tw-text-on-surface-variant">
                                {{ \App\Support\NumberFormat::maxDecimals($item->actual_weight_kg) }} Kg
                            </td>
                            <td class="tw-text-on-surface-variant">
                                {{ $item->notes ?? '-' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-ui.card>

    {{-- 6. Quality Control (QC) Status --}}
    @if($isArrived)
        <x-ui.card
            title="Quality Control (QC) Inspections"
            description="Incoming quality inspection events recorded for this shipment consignment."
        >
            @if($shipment->qcInspections->isEmpty())
                <div class="alert alert-warning d-flex align-items-center gap-2 mb-0 tw-text-ui-xs">
                    <x-ui.icon name="triangle-alert" size="sm" />
                    <div>
                        Material arrival has been confirmed. Inspection is currently queued and awaiting action from the Quality Control team.
                    </div>
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 tw-text-ui-xs">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Inspection Date</th>
                                <th scope="col">Inspector</th>
                                <th scope="col" class="text-center">QC Result</th>
                                <th scope="col" class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($shipment->qcInspections as $insp)
                                <tr>
                                    <td class="ui-tabular-nums">
                                        {{ $insp->inspected_at ? $insp->inspected_at->format('d M Y, H:i') : '-' }}
                                    </td>
                                    <td class="fw-semibold">
                                        {{ $insp->inspector->name ?? '-' }}
                                    </td>
                                    <td class="text-center">
                                        <x-ui.status-chip :tone="$insp->status === 'ok' ? 'success' : 'error'">
                                            {{ strtoupper($insp->status) }}
                                        </x-ui.status-chip>
                                    </td>
                                    <td class="text-end">
                                        <x-ui.button :href="route('qc.inspections.show', $insp)" variant="outline" size="sm">
                                            View Report
                                        </x-ui.button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui.card>
    @endif
</div>

{{-- Confirm Arrival Modal --}}
@if($isSubmitted)
<div class="modal fade" id="confirmArrivalModal" tabindex="-1" aria-labelledby="confirmArrivalModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('purchasing.shipments.confirm-arrival', $shipment) }}">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title tw-text-ui-base fw-bold" id="confirmArrivalModalLabel">Confirm Physical Material Arrival</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="tw-text-ui-sm tw-text-on-surface-variant mb-3">
                        Recording physical arrival confirms that consignment <strong>{{ $shipment->shipment_number }}</strong> has physically reached the plant/warehouse.
                        This will notify the QC department to conduct incoming material inspection.
                    </p>
                    <div class="mb-3">
                        <x-ui.date-picker
                            name="actual_arrival_date"
                            id="actual_arrival_date"
                            label="Arrival Date"
                            value="{{ now()->toDateString() }}"
                            max="{{ now()->toDateString() }}"
                            required
                        />
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm">Confirm Arrival & Notify QC</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif
@endsection
