@extends('layouts.app')

@section('title', 'Shipment: ' . $shipment->shipment_number . ' - ADASI Portal')
@section('page-title', 'Shipment Details')

@section('content')
<div class="tw-grid tw-gap-4">
    {{-- Breadcrumb & Page Header --}}
    <x-ui.breadcrumb :items="[
        'Dashboard' => route('supplier.dashboard'),
        'Shipments' => route('supplier.shipments.index'),
        $shipment->shipment_number => null,
    ]" />

    @php
        $statusMeta = match($shipment->status) {
            'draft' => ['tone' => 'neutral', 'label' => 'Draft (Unreserved)'],
            'submitted' => ['tone' => 'info', 'label' => 'Submitted / Active Reservation'],
            'arrived' => ['tone' => 'success', 'label' => 'Arrived at Plant'],
            'cancelled' => ['tone' => 'error', 'label' => 'Cancelled'],
            default => ['tone' => 'neutral', 'label' => ucfirst($shipment->status)],
        };
        $pos = $shipment->purchaseOrders();
        $totalShipped = $shipment->items->sum('shipped_quantity');
    @endphp

    <x-ui.page-header
        :title="'Shipment ' . $shipment->shipment_number"
        eyebrow="Delivery Package"
        description="Physical shipment batch and multi-PO delivery details."
    >
        <x-slot:actions>
            <div class="tw-flex tw-flex-wrap tw-gap-2">
                <x-ui.status-chip :tone="$statusMeta['tone']" size="md">
                    {{ $statusMeta['label'] }}
                </x-ui.status-chip>

                @if($shipment->status === 'draft')
                    <x-ui.button :href="route('supplier.shipments.edit', $shipment)" variant="outline" size="sm">
                        <x-slot:leading><x-ui.icon name="pencil" size="sm" /></x-slot:leading>
                        Edit Draft
                    </x-ui.button>
                    <form method="POST" action="{{ route('supplier.shipments.submit', $shipment) }}" class="d-inline">
                        @csrf
                        <x-ui.button type="submit" variant="primary" size="sm">
                            <x-slot:leading><x-ui.icon name="truck" size="sm" /></x-slot:leading>
                            Submit Shipment
                        </x-ui.button>
                    </form>
                @endif

                @if(in_array($shipment->status, ['draft', 'submitted'], true))
                    <form method="POST" action="{{ route('supplier.shipments.cancel', $shipment) }}" class="d-inline" onsubmit="return confirm('Are you sure you want to cancel this shipment? Reserved allocations will be released back to the PO.');">
                        @csrf
                        <x-ui.button type="submit" variant="danger" size="sm">
                            <x-slot:leading><x-ui.icon name="x-circle" size="sm" /></x-slot:leading>
                            Cancel Shipment
                        </x-ui.button>
                    </form>
                @endif
            </div>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Shipment Overview Card --}}
    <x-ui.card title="Shipment Overview">
        <div class="tw-grid tw-gap-3 sm:tw-grid-cols-2 lg:tw-grid-cols-4">
            <div class="p-3 tw-bg-surface-low border rounded">
                <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">Consolidated POs</div>
                <div class="fw-bold tw-text-on-surface fs-6 mt-1">
                    @foreach($pos as $po)
                        <a href="{{ route('supplier.purchase-orders.show', $po) }}" class="text-primary text-decoration-none me-1">
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
                <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">Shipment / Dispatch Date</div>
                <div class="fw-semibold tw-text-on-surface fs-6 mt-1">
                    {{ $shipment->shipment_date ? $shipment->shipment_date->format('d M Y') : '-' }}
                </div>
            </div>
            <div class="p-3 tw-bg-surface-low border rounded">
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
            <div class="tw-mt-3 tw-p-3 tw-bg-surface-container tw-rounded tw-text-ui-xs">
                <span class="fw-semibold">Notes:</span> {{ $shipment->notes }}
            </div>
        @endif
    </x-ui.card>

    {{-- Shipped Line Items --}}
    <x-ui.data-table
        title="Included Material Items"
        description="Line-item breakdown of quantities delivered in this shipment."
    >
        <table class="table table-hover align-middle mb-0 tw-text-ui-xs w-100">
            <thead class="table-light">
                <tr>
                    <th scope="col" style="width: 40px;">No</th>
                    <th scope="col">PO Reference</th>
                    <th scope="col">Material Name</th>
                    <th scope="col" class="text-end">Shipped Quantity (Kg)</th>
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
                            {{ \App\Support\NumberFormat::maxDecimals($item->shipped_quantity) }}
                        </td>
                        <td class="tw-text-on-surface-variant">
                            {{ $item->notes ?: '-' }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-ui.data-table>

    {{-- Shared Shipment Documents (Phase 7) --}}
    <x-ui.card title="Shared Shipping &amp; Import Documents" description="One shared set of documents covers all POs delivered in this shipment batch.">
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
                    $docStatusMeta = match($doc->status) {
                        'verified', 'done' => ['tone' => 'success', 'label' => ucfirst($doc->status)],
                        'received', 'processing' => ['tone' => 'info', 'label' => ucfirst($doc->status)],
                        default => ['tone' => 'neutral', 'label' => ucfirst($doc->status)],
                    };
                @endphp
                <div class="tw-p-3 tw-border tw-border-outline-variant tw-rounded tw-bg-surface-low tw-flex tw-flex-col tw-justify-between tw-gap-2">
                    <div>
                        <div class="tw-flex tw-items-center tw-justify-between tw-gap-1">
                            <div class="fw-bold tw-text-ui-xs tw-text-on-surface">{{ $docLabel }}</div>
                            <x-ui.status-chip :tone="$docStatusMeta['tone']" size="xs">
                                {{ $docStatusMeta['label'] }}
                            </x-ui.status-chip>
                        </div>

                        <div class="tw-mt-2 tw-text-ui-xs">
                            @if($latestAtt)
                                <a href="{{ route('attachments.show', $latestAtt) }}" target="_blank" class="tw-inline-flex tw-items-center tw-gap-1 text-primary text-decoration-none fw-medium">
                                    <x-ui.icon name="file-text" size="xs" />
                                    <span>{{ Str::limit($latestAtt->file_name, 20) }}</span>
                                </a>
                                @if($doc->document_number)
                                    <div class="tw-text-on-surface-variant tw-mt-0.5">Ref: {{ $doc->document_number }}</div>
                                @endif
                            @else
                                <span class="tw-text-outline">No file uploaded</span>
                            @endif
                        </div>
                    </div>

                    @if($shipment->status !== 'cancelled')
                        <form method="POST" action="{{ route('supplier.shipments.documents.upload', [$shipment, $doc]) }}" enctype="multipart/form-data" class="tw-mt-2 tw-pt-2 tw-border-t tw-border-outline-variant">
                            @csrf
                            <input type="text" name="document_number" value="{{ $doc->document_number }}" class="form-control form-control-sm tw-mb-1.5 tw-text-ui-xs" placeholder="Document Ref No.">
                            <div class="input-group input-group-sm">
                                <input type="file" name="file" class="form-control form-control-sm" required>
                                <button type="submit" class="btn btn-outline-primary btn-sm">
                                    <x-ui.icon name="upload" size="xs" />
                                </button>
                            </div>
                        </form>
                    @endif
                </div>
            @endforeach
        </div>
    </x-ui.card>
</div>
@endsection
