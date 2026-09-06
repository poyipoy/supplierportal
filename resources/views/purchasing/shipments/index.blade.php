@extends('layouts.app')

@section('title', 'Shipments & Logistics - ADASI Portal')
@section('page-title', 'Shipments & Logistics')

@section('content')
<div class="tw-grid tw-gap-4">
    {{-- Breadcrumb & Compact Page Header --}}
    <x-ui.breadcrumb :items="[
        'Dashboard' => route('purchasing.dashboard'),
        'Shipments' => null,
    ]" />

    <x-ui.page-header
        title="Physical Shipments &amp; Deliveries"
        eyebrow="Logistics Management"
        description="Monitor physical deliveries across suppliers, verify shipping documentation sets, and confirm port/warehouse arrivals."
    >
        <x-slot:actions>
            <span class="ui-status-chip ui-status-chip--neutral ui-tabular-nums">
                {{ $shipments->total() }} Shipments
            </span>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Operational Toolbar / Filters --}}
    <x-ui.toolbar :sticky="true">
        <x-slot:filters>
            <form method="GET" action="{{ route('purchasing.shipments.index') }}" class="d-flex flex-wrap align-items-center gap-2 w-100">
                <div style="min-width: 180px;">
                    <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All Statuses</option>
                        <option value="draft" {{ request('status') === 'draft' ? 'selected' : '' }}>Draft</option>
                        <option value="submitted" {{ request('status') === 'submitted' ? 'selected' : '' }}>Submitted / In Transit</option>
                        <option value="arrived" {{ request('status') === 'arrived' ? 'selected' : '' }}>Arrived at Plant</option>
                        <option value="cancelled" {{ request('status') === 'cancelled' ? 'selected' : '' }}>Cancelled</option>
                    </select>
                </div>

                <div style="min-width: 220px;">
                    <select name="supplier_id" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All Suppliers</option>
                        @foreach($suppliers as $supplier)
                            <option value="{{ $supplier->id }}" {{ (string) request('supplier_id') === (string) $supplier->id ? 'selected' : '' }}>
                                {{ $supplier->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                @if(request()->hasAny(['status', 'supplier_id']))
                    <x-ui.button :href="route('purchasing.shipments.index')" variant="ghost" size="sm">
                        <x-ui.icon name="rotate-ccw" />
                        <span>Reset Filters</span>
                    </x-ui.button>
                @endif
            </form>
        </x-slot:filters>
    </x-ui.toolbar>

    {{-- Shipments Table --}}
    <x-ui.data-table
        title="Shipment Registry"
        description="Physical consignments dispatched by international suppliers."
    >
        <table class="table table-hover align-middle mb-0 tw-text-ui-xs w-100">
            <thead class="table-light">
                <tr>
                    <th scope="col">Shipment No.</th>
                    <th scope="col">Supplier</th>
                    <th scope="col">Consolidated POs</th>
                    <th scope="col" class="text-center">Items</th>
                    <th scope="col" class="text-center">Total Weight (Kg)</th>
                    <th scope="col">Shipment Date</th>
                    <th scope="col">Est. Arrival</th>
                    <th scope="col">Actual Arrival</th>
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
                            'submitted' => ['class' => 'info', 'label' => 'In Transit'],
                            'arrived' => ['class' => 'success', 'label' => 'Arrived'],
                            'cancelled' => ['class' => 'error', 'label' => 'Cancelled'],
                            default => ['class' => 'neutral', 'label' => ucfirst($shp->status)],
                        };
                    @endphp
                    <tr>
                        <td class="fw-bold tw-text-on-surface">
                            <a href="{{ route('purchasing.shipments.show', $shp) }}" class="text-primary text-decoration-none">
                                {{ $shp->shipment_number }}
                            </a>
                        </td>
                        <td class="fw-semibold">
                            {{ $shp->supplier->name ?? '-' }}
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
                        <td class="ui-tabular-nums">
                            @if($shp->actual_arrival_date)
                                <span class="text-success fw-bold">{{ $shp->actual_arrival_date->format('d M Y') }}</span>
                            @else
                                <span class="tw-text-outline">-</span>
                            @endif
                        </td>
                        <td class="text-center">
                            <x-ui.status-chip :tone="$statusMeta['class']">{{ $statusMeta['label'] }}</x-ui.status-chip>
                        </td>
                        <td class="text-end">
                            <x-ui.button :href="route('purchasing.shipments.show', $shp)" variant="ghost" size="xs">
                                Details
                            </x-ui.button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10" class="text-center py-4 tw-text-on-surface-variant">
                            No shipments matching filter criteria.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if($shipments->hasPages())
            <x-slot:pagination>
                <div class="p-3 border-top d-flex justify-content-end">
                    {{ $shipments->links() }}
                </div>
            </x-slot:pagination>
        @endif
    </x-ui.data-table>
</div>
@endsection
