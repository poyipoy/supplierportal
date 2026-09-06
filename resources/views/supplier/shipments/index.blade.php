@extends('layouts.app')

@section('title', 'Shipments & Deliveries - ADASI Portal')
@section('page-title', 'Shipments & Deliveries')

@section('content')
<div class="tw-grid tw-gap-4">
    {{-- Breadcrumb & Page Header --}}
    <x-ui.breadcrumb :items="[
        'Dashboard' => route('supplier.dashboard'),
        'Shipments' => null,
    ]" />

    <x-ui.page-header
        title="My Shipments &amp; Deliveries"
        eyebrow="Logistics &amp; Fulfillment"
        description="Create and manage physical shipments, consolidate deliveries across multiple POs, and track shipping documents."
    >
        <x-slot:actions>
            <x-ui.button :href="route('supplier.shipments.create')" variant="primary" size="sm">
                <x-slot:leading><x-ui.icon name="plus" size="sm" /></x-slot:leading>
                <span>New Shipment</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Shipments Table --}}
    <x-ui.data-table
        title="Shipment History"
        description="All deliveries registered for your company."
    >
        <table class="table table-hover align-middle mb-0 tw-text-ui-xs w-100">
            <thead class="table-light">
                <tr>
                    <th scope="col">Shipment No.</th>
                    <th scope="col">PO References</th>
                    <th scope="col" class="text-center">Items Count</th>
                    <th scope="col" class="text-center">Total Shipped (Kg)</th>
                    <th scope="col">Shipment Date</th>
                    <th scope="col">Est. Arrival</th>
                    <th scope="col" class="text-center">Status</th>
                    <th scope="col" class="text-end" style="width: 100px;">Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse($shipments as $shp)
                    @php
                        $pos = $shp->purchaseOrders();
                        $totalKg = $shp->items->sum('shipped_quantity');
                        $statusMeta = match($shp->status) {
                            'draft' => ['class' => 'neutral', 'label' => 'Draft'],
                            'submitted' => ['class' => 'info', 'label' => 'Submitted / In Transit'],
                            'arrived' => ['class' => 'success', 'label' => 'Arrived at Plant'],
                            'cancelled' => ['class' => 'error', 'label' => 'Cancelled'],
                            default => ['class' => 'neutral', 'label' => ucfirst($shp->status)],
                        };
                    @endphp
                    <tr>
                        <td class="fw-bold tw-text-on-surface">
                            <a href="{{ route('supplier.shipments.show', $shp) }}" class="text-primary text-decoration-none">
                                {{ $shp->shipment_number }}
                            </a>
                        </td>
                        <td>
                            @if($pos->isEmpty())
                                <span class="tw-text-outline">-</span>
                            @else
                                @foreach($pos as $po)
                                    <span class="ui-status-chip ui-status-chip--neutral me-1">{{ $po->po_number }}</span>
                                @endforeach
                            @endif
                        </td>
                        <td class="text-center ui-tabular-nums">{{ $shp->items->count() }}</td>
                        <td class="text-center fw-bold text-primary ui-tabular-nums">
                            {{ \App\Support\NumberFormat::maxDecimals($totalKg) }}
                        </td>
                        <td class="ui-tabular-nums tw-text-on-surface-variant">
                            {{ $shp->shipment_date ? $shp->shipment_date->format('d M Y') : '-' }}
                        </td>
                        <td class="ui-tabular-nums tw-text-on-surface-variant">
                            {{ $shp->estimated_arrival_date ? $shp->estimated_arrival_date->format('d M Y') : '-' }}
                        </td>
                        <td class="text-center">
                            <x-ui.status-chip :tone="$statusMeta['class']">{{ $statusMeta['label'] }}</x-ui.status-chip>
                        </td>
                        <td class="text-end">
                            <x-ui.button :href="route('supplier.shipments.show', $shp)" variant="ghost" size="xs">
                                Details
                            </x-ui.button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-center py-4 tw-text-on-surface-variant">
                            No shipments created yet. Click "New Shipment" to initiate a delivery.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if($shipments->hasPages())
            <div class="tw-p-3 tw-border-t tw-border-outline-variant">
                {{ $shipments->links() }}
            </div>
        @endif
    </x-ui.data-table>
</div>
@endsection
