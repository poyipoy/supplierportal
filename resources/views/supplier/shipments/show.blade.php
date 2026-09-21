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
        'Dashboard' => route('supplier.dashboard'),
        'Shipments' => route('supplier.shipments.index'),
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
        eyebrow="Delivery Package & Logistics"
        description="Consolidated shipment batch details, shipping documentation upload, and receiving status."
    >
        <x-slot:actions>
            <div class="tw-flex tw-flex-wrap tw-gap-2 align-items-center">
                <x-ui.status-chip :tone="\App\Support\StatusHelper::shipmentTone($shipment->status)" size="md">
                    {{ \App\Support\StatusHelper::shipmentLabel($shipment->status) }}
                </x-ui.status-chip>

                @if($isDraft)
                    <x-ui.button :href="route('supplier.shipments.edit', $shipment)" variant="outline" size="sm">
                        <x-slot:leading><x-ui.icon name="pencil" size="sm" /></x-slot:leading>
                        Edit Draft
                    </x-ui.button>

                    <form method="POST" action="{{ route('supplier.shipments.submit', $shipment) }}" class="d-inline" id="submitShipmentForm">
                        @csrf
                        <x-ui.button type="button" variant="primary" size="sm" id="btnConfirmSubmit">
                            <x-slot:leading><x-ui.icon name="truck" size="sm" /></x-slot:leading>
                            Submit Shipment
                        </x-ui.button>
                    </form>
                @endif

                @if(in_array($shipment->status, ['draft', 'submitted'], true))
                    <form method="POST" action="{{ route('supplier.shipments.cancel', $shipment) }}" class="d-inline" id="cancelShipmentForm">
                        @csrf
                        <x-ui.button type="button" variant="danger" size="sm" id="btnConfirmCancel">
                            <x-slot:leading><x-ui.icon name="x-circle" size="sm" /></x-slot:leading>
                            Cancel Shipment
                        </x-ui.button>
                    </form>
                @endif
            </div>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- 2. Visual Lifecycle Tracking Stepper --}}
    <div class="shipment-tracking-strip" aria-label="Shipment delivery progress">
        @if($isCancelled)
            <div class="shipment-tracking-step is-cancelled">
                <div class="tw-text-ui-xs fw-semibold text-danger">STATUS</div>
                <div class="fw-bold fs-6 text-danger">Cancelled</div>
                <div class="tw-text-ui-xs text-danger mt-1">Delivery batch was cancelled and balances released</div>
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

            {{-- Step 2: In Transit --}}
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
                        {{ $shipment->qcInspections->count() }} report(s) filed
                    @elseif($isArrived)
                        Inspection pending
                    @else
                        Awaiting arrival
                    @endif
                </div>
            </div>
        @endif
    </div>

    {{-- 3. Shipment Overview Card --}}
    <x-ui.card title="Shipment Logistics Overview">
        <div class="tw-grid tw-gap-3 sm:tw-grid-cols-2 lg:tw-grid-cols-5">
            <div class="p-3 tw-bg-surface-low border tw-border-outline-variant rounded">
                <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">Consolidated POs</div>
                <div class="fw-bold tw-text-on-surface fs-6 mt-1 d-flex flex-wrap gap-1">
                    @forelse($pos as $po)
                        <a href="{{ route('supplier.purchase-orders.show', $po) }}" class="text-primary text-decoration-none">
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
                <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">Shipment / Dispatch Date</div>
                <div class="fw-semibold tw-text-on-surface fs-6 mt-1">
                    {{ $shipment->shipment_date ? $shipment->shipment_date->format('d M Y') : '-' }}
                </div>
            </div>
            <div class="p-3 tw-bg-surface-low border tw-border-outline-variant rounded">
                <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">Target / Actual Arrival</div>
                <div class="fw-semibold tw-text-on-surface fs-6 mt-1">
                    @if($shipment->actual_arrival_date)
                        <span class="text-success fw-bold">Arrived: {{ $shipment->actual_arrival_date->format('d M Y') }}</span>
                    @elseif($shipment->estimated_arrival_date)
                        <span>ETA: {{ $shipment->estimated_arrival_date->format('d M Y') }}</span>
                    @else
                        -
                    @endif
                </div>
            </div>
        </div>

        @if($shipment->notes)
            <div class="mt-3 p-3 tw-bg-surface-container tw-border tw-border-outline-variant rounded tw-text-ui-xs">
                <span class="fw-semibold">Logistics Notes:</span> {{ $shipment->notes }}
            </div>
        @endif
    </x-ui.card>

    {{-- 4. Shipped Line Items Table --}}
    <x-ui.card
        title="Included Material Items"
        description="Line-item breakdown of quantities dispatched in this consignment."
    >
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 tw-text-ui-xs">
                <thead class="table-light">
                    <tr>
                        <th scope="col" style="width: 44px;" class="text-center">No</th>
                        <th scope="col">PO Reference</th>
                        <th scope="col">Material Name</th>
                        <th scope="col" class="text-end">Shipped Qty</th>
                        <th scope="col" class="text-end">Actual Weight</th>
                        <th scope="col">Item Notes</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($shipment->items as $idx => $item)
                        <tr>
                            <td class="text-center tw-text-on-surface-variant">{{ $idx + 1 }}</td>
                            <td class="fw-bold">
                                <a href="{{ route('supplier.purchase-orders.show', $item->purchaseOrder) }}" class="text-primary text-decoration-none">
                                    {{ $item->purchaseOrder->po_number }}
                                </a>
                            </td>
                            <td class="fw-medium">
                                {{ $item->quotationItem->prItem->material_name ?? 'Material' }}
                            </td>
                            <td class="text-end fw-bold text-primary ui-tabular-nums">
                                {{ number_format($item->shipped_qty) }} pcs
                            </td>
                            <td class="text-end ui-tabular-nums tw-text-on-surface-variant">
                                {{ \App\Support\NumberFormat::maxDecimals($item->actual_weight_kg) }} Kg
                            </td>
                            <td class="tw-text-on-surface-variant">
                                {{ $item->notes ?: '-' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-ui.card>

    {{-- 5. Shared Shipping Documents Hub --}}
    <x-ui.card
        title="Shared Shipping & Import Documents"
        description="One shared set of documents (Invoice, Packing List, BL, Form E) covers all purchase orders consolidated in this delivery."
    >
        <div class="tw-grid tw-gap-3 sm:tw-grid-cols-2 lg:tw-grid-cols-4">
            @foreach($shipment->documents as $doc)
                @php
                    $docLabel = match($doc->doc_type) {
                        'invoice' => 'Commercial Invoice',
                        'packing_list' => 'Packing List',
                        'bl' => 'Bill of Lading (BL)',
                        'form_e' => 'Form E / Certificate of Origin',
                        default => strtoupper($doc->doc_type),
                    };
                    $latestAtt = $doc->latestAttachment;
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

                            @if($latestAtt)
                                <a href="{{ route('attachments.show', $latestAtt) }}" target="_blank" class="text-primary text-decoration-none d-inline-flex align-items-center gap-1 fw-medium">
                                    <x-ui.icon name="file-text" size="sm" />
                                    <span class="text-truncate" style="max-width: 170px;" title="{{ $latestAtt->file_name }}">
                                        {{ $latestAtt->file_name }}
                                    </span>
                                </a>
                            @else
                                <span class="tw-text-outline fst-italic">No file uploaded</span>
                            @endif
                        </div>
                    </div>

                    @if($shipment->status !== 'cancelled')
                        <form method="POST" action="{{ route('supplier.shipments.documents.upload', [$shipment, $doc]) }}" enctype="multipart/form-data" class="pt-2 border-top tw-border-outline-variant">
                            @csrf
                            <input
                                type="text"
                                name="document_number"
                                value="{{ old('document_number', $doc->document_number) }}"
                                class="form-control form-control-sm mb-1.5 tw-text-ui-xs"
                                placeholder="Doc Ref No. (optional)"
                            >
                            <div class="input-group input-group-sm">
                                <input type="file" name="file" class="form-control form-control-sm" required accept=".pdf,.jpg,.jpeg,.png,.xlsx,.doc,.docx">
                                <button type="submit" class="btn btn-outline-primary btn-sm" title="Upload file">
                                    <x-ui.icon name="upload" size="sm" />
                                </button>
                            </div>
                            <div class="tw-text-outline" style="font-size: 10px; margin-top: 3px;">
                                Max 10MB (PDF, Image, Excel, Word)
                            </div>
                        </form>
                    @endif
                </div>
            @endforeach
        </div>
    </x-ui.card>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    // Submit Confirmation via AdasiAlert
    const submitBtn = document.getElementById('btnConfirmSubmit');
    const submitForm = document.getElementById('submitShipmentForm');
    submitBtn?.addEventListener('click', () => {
        const title = 'Submit Shipment Delivery?';
        const text = 'Submitting this shipment locks the allocated quantities and notifies Purchasing and QC that the goods are in transit.';

        if (window.AdasiAlert) {
            window.AdasiAlert.confirm({
                title: title,
                text: text,
                confirmText: 'Yes, Submit Delivery',
                confirmTone: 'primary'
            }).then(res => {
                if (res.isConfirmed) {
                    window.AdasiButton?.startLoading(submitBtn);
                    submitForm.submit();
                }
            });
        } else if (confirm(`${title}\n\n${text}`)) {
            window.AdasiButton?.startLoading(submitBtn);
            submitForm.submit();
        }
    });

    // Cancel Confirmation via AdasiAlert
    const cancelBtn = document.getElementById('btnConfirmCancel');
    const cancelForm = document.getElementById('cancelShipmentForm');
    cancelBtn?.addEventListener('click', () => {
        const title = 'Cancel this Shipment?';
        const text = 'Are you sure you want to cancel this consignment? Any reserved quantities will be returned to the purchase order balance.';

        if (window.AdasiAlert) {
            window.AdasiAlert.confirm({
                title: title,
                text: text,
                confirmText: 'Yes, Cancel Shipment',
                confirmTone: 'danger'
            }, true).then(res => {
                if (res.isConfirmed) {
                    window.AdasiButton?.startLoading(cancelBtn);
                    cancelForm.submit();
                }
            });
        } else if (confirm(`${title}\n\n${text}`)) {
            window.AdasiButton?.startLoading(cancelBtn);
            cancelForm.submit();
        }
    });
});
</script>
@endpush
