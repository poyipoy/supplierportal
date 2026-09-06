@extends('layouts.app')

@section('title', 'Shipment: ' . $shipment->shipment_number . ' - ADASI Portal')
@section('page-title', 'Shipment Details')

@section('content')
<div class="tw-grid tw-gap-4">
    {{-- Breadcrumb & Page Header --}}
    <x-ui.breadcrumb :items="[
        'Dashboard' => route('purchasing.dashboard'),
        'Shipments' => route('purchasing.shipments.index'),
        $shipment->shipment_number => null,
    ]" />

    @php
        $statusMeta = match($shipment->status) {
            'draft' => ['tone' => 'neutral', 'label' => 'Draft (Unreserved)'],
            'submitted' => ['tone' => 'info', 'label' => 'In Transit / Active Allocation'],
            'arrived' => ['tone' => 'success', 'label' => 'Arrived at Plant'],
            'cancelled' => ['tone' => 'error', 'label' => 'Cancelled'],
            default => ['tone' => 'neutral', 'label' => ucfirst($shipment->status)],
        };
        $pos = $shipment->purchaseOrders();
        $totalShipped = $shipment->items->sum('shipped_quantity');
    @endphp

    <x-ui.page-header
        :title="'Shipment ' . $shipment->shipment_number"
        eyebrow="Consignment Logistics &amp; Receiving"
        description="Physical delivery verification, consolidated shipping documents, and receiving status."
    >
        <x-slot:actions>
            <div class="tw-flex tw-flex-wrap tw-gap-2 align-items-center">
                <x-ui.status-chip :tone="$statusMeta['tone']" size="md">
                    {{ $statusMeta['label'] }}
                </x-ui.status-chip>

                @if($shipment->status === 'submitted')
                    <button type="button" class="btn btn-primary btn-sm d-inline-flex align-items-center gap-1.5" data-bs-toggle="modal" data-bs-target="#confirmArrivalModal">
                        <x-ui.icon name="check-circle" size="sm" />
                        <span>Confirm Physical Arrival</span>
                    </button>
                @endif
            </div>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Overview Details Card --}}
    <x-ui.card title="Delivery Package Overview">
        <div class="tw-grid tw-gap-3 sm:tw-grid-cols-2 lg:tw-grid-cols-4">
            <div class="p-3 tw-bg-surface-low border rounded">
                <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">Supplier</div>
                <div class="fw-bold tw-text-on-surface fs-6 mt-1">
                    {{ $shipment->supplier->company_name ?? $shipment->supplier->name }}
                </div>
            </div>
            <div class="p-3 tw-bg-surface-low border rounded">
                <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">Consolidated POs</div>
                <div class="fw-bold tw-text-on-surface fs-6 mt-1">
                    @foreach($pos as $po)
                        <a href="{{ route('purchasing.purchase-orders.show', $po) }}" class="text-primary text-decoration-none me-1">
                            {{ $po->po_number }}
                        </a>
                    @endforeach
                </div>
            </div>
            <div class="p-3 tw-bg-surface-low border rounded">
                <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">Total Quantity Shipped</div>
                <div class="fw-bold text-primary fs-6 mt-1">
                    {{ \App\Support\NumberFormat::maxDecimals($totalShipped) }} Kg
                </div>
            </div>
            <div class="p-3 tw-bg-surface-low border rounded">
                <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">Dispatch / Arrival Dates</div>
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
            <div class="mt-3 p-3 bg-light rounded border text-muted tw-text-ui-xs">
                <strong>Dispatch Notes:</strong> {{ $shipment->notes }}
            </div>
        @endif
    </x-ui.card>

    {{-- Line Items Card --}}
    <x-ui.card
        title="Consignment Line Items"
        description="Material batches allocated to this physical shipment."
    >
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 tw-text-ui-xs">
                <thead class="table-light">
                    <tr>
                        <th scope="col">PO Number</th>
                        <th scope="col">PR Reference</th>
                        <th scope="col">Material Name</th>
                        <th scope="col">Shape &amp; Specs</th>
                        <th scope="col" class="text-end">Shipped Weight (Kg)</th>
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
                                {{ \App\Support\NumberFormat::maxDecimals($item->shipped_quantity) }}
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

    {{-- Consolidated Shipping Documents Card --}}
    <x-ui.card
        title="Shared Shipping Documents"
        description="Review and verify shipping documents (Invoice, Packing List, BL, Form E) for this shipment."
    >
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 tw-text-ui-xs">
                <thead class="table-light">
                    <tr>
                        <th scope="col">Document Type</th>
                        <th scope="col">Document No. / Reference</th>
                        <th scope="col">Uploaded File</th>
                        <th scope="col" class="text-center">Current Status</th>
                        <th scope="col" class="text-end" style="width: 250px;">Update Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($shipment->documents as $doc)
                        @php
                            $attachment = $doc->latestAttachment;
                            $docStatusMeta = match($doc->status) {
                                'verified' => ['tone' => 'success', 'label' => 'Verified'],
                                'received' => ['tone' => 'info', 'label' => 'Received'],
                                'pending' => ['tone' => 'neutral', 'label' => 'Pending'],
                                'processing' => ['tone' => 'warning', 'label' => 'Processing'],
                                'issued' => ['tone' => 'info', 'label' => 'Issued'],
                                'done' => ['tone' => 'success', 'label' => 'Done'],
                                default => ['tone' => 'neutral', 'label' => ucfirst($doc->status)],
                            };
                        @endphp
                        <tr>
                            <td class="fw-bold tw-text-on-surface">
                                {{ $doc->label }}
                            </td>
                            <td class="tw-text-on-surface-variant ui-tabular-nums">
                                {{ $doc->document_number ?? '-' }}
                            </td>
                            <td>
                                @if($attachment)
                                    <a href="{{ route('attachments.show', $attachment->id) }}" target="_blank" class="text-primary text-decoration-none d-inline-flex align-items-center gap-1">
                                        <x-ui.icon name="paperclip" size="xs" />
                                        <span>{{ $attachment->file_name }}</span>
                                    </a>
                                @else
                                    <span class="tw-text-outline italic">No file uploaded</span>
                                @endif
                            </td>
                            <td class="text-center">
                                <x-ui.status-chip :tone="$docStatusMeta['tone']">
                                    {{ $docStatusMeta['label'] }}
                                </x-ui.status-chip>
                            </td>
                            <td class="text-end">
                                <form method="POST" action="{{ route('purchasing.shipments.documents.status', ['id' => $shipment, 'document_id' => $doc->id]) }}" class="d-flex align-items-center gap-1.5 justify-content-end">
                                    @csrf
                                    @method('PUT')
                                    <select name="status" class="form-select form-select-sm" style="width: 140px;">
                                        @foreach(\App\Models\ShipmentDocument::STATUSES as $st)
                                            <option value="{{ $st }}" {{ $doc->status === $st ? 'selected' : '' }}>
                                                {{ ucfirst($st) }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <x-ui.button type="submit" variant="ghost" size="xs">
                                        Update
                                    </x-ui.button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-ui.card>

    {{-- QC Inspections for this Shipment --}}
    @if($shipment->status === 'arrived')
        <x-ui.card
            title="Quality Control (QC) Status"
            description="Inspection events associated with this physical delivery."
        >
            @if($shipment->qcInspections->isEmpty())
                <div class="alert alert-warning d-flex align-items-center gap-2 mb-0">
                    <x-ui.icon name="alert-triangle" size="sm" />
                    <div>
                        Material arrival has been confirmed. Inspection is currently pending with the Quality Control team.
                    </div>
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 tw-text-ui-xs">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Inspection Date</th>
                                <th scope="col">Inspector</th>
                                <th scope="col" class="text-center">Result</th>
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
                                        <x-ui.button :href="route('qc.inspections.show', $insp)" variant="ghost" size="xs">
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
@if($shipment->status === 'submitted')
<div class="modal fade" id="confirmArrivalModal" tabindex="-1" aria-labelledby="confirmArrivalModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('purchasing.shipments.confirm-arrival', $shipment) }}">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title" id="confirmArrivalModalLabel">Confirm Physical Material Arrival</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="tw-text-ui-sm tw-text-on-surface-variant mb-3">
                        Confirming physical arrival records that shipment <strong>{{ $shipment->shipment_number }}</strong> has reached the factory/warehouse.
                        This will notify the QC team to perform incoming inspection on the delivered material.
                    </p>
                    <div class="mb-3">
                        <label for="actual_arrival_date" class="form-label tw-text-ui-xs fw-semibold">Arrival Date</label>
                        <input type="date" name="actual_arrival_date" id="actual_arrival_date" class="form-control" value="{{ now()->toDateString() }}" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm">Confirm Arrival &amp; Notify QC</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif
@endsection
