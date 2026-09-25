@extends('layouts.app')
@section('title', 'Purchase Orders - Supplier Lokal')
@section('page-title', 'Purchase Orders')

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        title="Daftar Purchase Order"
        description="Pantau dokumen resmi Purchase Order (PO) lokal dan realisasi penerimaan barang (Goods Receipt) dari PT Astra Daido Steel Indonesia."
        eyebrow="Pengadaan Lokal"
    >
        <x-slot:actions>
            <x-ui.button :href="route('local-supplier.dashboard')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>Dashboard</span>
            </x-ui.button>
            <x-ui.button :href="route('local-supplier.invoices.create')" variant="primary" size="sm">
                <x-ui.icon name="plus" size="sm" />
                <span>Ajukan Invoice</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Filter Toolbar --}}
    <form method="GET" action="{{ route('local-supplier.purchase-orders.index') }}" id="supplierPoFilterForm" class="tw-m-0">
        <x-ui.toolbar aria-label="Kontrol filter purchase order">
            <x-slot:search>
                <div class="tw-relative tw-w-full">
                    <input
                        type="search"
                        name="q"
                        id="supplier-po-search"
                        value="{{ request('q') }}"
                        placeholder="Cari nomor PO..."
                        class="form-control form-control-sm tw-text-ui-xs"
                        maxlength="100"
                        autocomplete="off"
                    >
                </div>
            </x-slot:search>

            <x-slot:filters>
                <div class="tw-w-full sm:tw-w-44">
                    <select
                        name="status"
                        id="supplier-po-status"
                        class="form-select form-select-sm tw-text-ui-xs"
                        onchange="this.form.submit()"
                    >
                        <option value="">Semua Status</option>
                        <option value="OPEN" {{ request('status') === 'OPEN' ? 'selected' : '' }}>OPEN</option>
                        <option value="CLOSED" {{ request('status') === 'CLOSED' ? 'selected' : '' }}>CLOSED</option>
                        <option value="CANCELLED" {{ request('status') === 'CANCELLED' ? 'selected' : '' }}>CANCELLED</option>
                    </select>
                </div>

                <div class="tw-w-full sm:tw-w-40">
                    <x-ui.date-picker
                        name="date_from"
                        id="supplier_po_date_from"
                        value="{{ request('date_from') }}"
                        placeholder="Dari tanggal"
                    />
                </div>

                <div class="tw-w-full sm:tw-w-40">
                    <x-ui.date-picker
                        name="date_to"
                        id="supplier_po_date_to"
                        value="{{ request('date_to') }}"
                        placeholder="Sampai tanggal"
                    />
                </div>

                <x-ui.button type="submit" size="sm" variant="secondary">
                    <x-ui.icon name="filter" size="sm" />
                    <span>Filter</span>
                </x-ui.button>

                @if(request()->hasAny(['q', 'status', 'date_from', 'date_to']))
                    <x-ui.button :href="route('local-supplier.purchase-orders.index')" size="sm" variant="ghost">
                        <x-ui.icon name="rotate-ccw" size="sm" />
                        <span>Reset</span>
                    </x-ui.button>
                @endif
            </x-slot:filters>
        </x-ui.toolbar>
    </form>

    {{-- PO Data Table --}}
    <x-ui.data-table
        title="Purchase Orders Terdaftar"
        :description="'Menampilkan total ' . $purchaseOrders->total() . ' dokumen Purchase Order.'"
    >
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 tw-text-ui-xs">
                <thead class="table-light">
                    <tr>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">Nomor PO</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">Tanggal PO</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">Plafon PO</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">Realisasi GR</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold text-center">Status</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold text-center">Dokumen PO</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($purchaseOrders as $po)
                        <tr>
                            <td>
                                <a href="{{ route('local-supplier.purchase-orders.show', $po) }}" class="tw-font-mono tw-font-bold tw-text-primary hover:tw-text-primary-hover tw-no-underline hover:tw-underline">
                                    {{ $po->po_number }}
                                </a>
                            </td>
                            <td>
                                <span class="tw-text-ui-xs tw-text-on-surface-variant">
                                    {{ $po->po_date?->format('d M Y') ?? '—' }}
                                </span>
                            </td>
                            <td class="text-end tw-font-mono tw-font-bold tw-text-on-surface">
                                Rp {{ number_format($po->total_amount, 2, ',', '.') }}
                            </td>
                            <td class="text-end">
                                <div class="tw-font-mono tw-font-semibold tw-text-on-surface">
                                    {{ number_format((float) ($po->active_gr_qty ?? 0), 4, ',', '.') }} pcs
                                </div>
                                <div class="tw-text-[11px] tw-text-on-surface-variant">
                                    {{ $po->active_gr_count }} berkas GR
                                </div>
                            </td>
                            <td class="text-center">
                                <x-ui.status-chip :tone="\App\Support\StatusHelper::localFinanceTone($po->status)">
                                    {{ $po->status }}
                                </x-ui.status-chip>
                            </td>
                            <td class="text-center">
                                @if($po->latestPoDocument())
                                    <x-ui.button :href="route('attachments.show', $po->latestPoDocument()->id)" target="_blank" size="sm" variant="outline">
                                        <x-ui.icon name="file-text" size="xs" />
                                        <span>PDF</span>
                                    </x-ui.button>
                                @else
                                    <span class="tw-text-on-surface-variant tw-text-[11px] tw-italic">Belum diunggah</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <x-ui.button :href="route('local-supplier.purchase-orders.show', $po)" size="sm" variant="outline">
                                    <x-ui.icon name="eye" size="sm" />
                                    <span>Detail</span>
                                </x-ui.button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="tw-py-8 tw-text-center tw-text-on-surface-variant tw-text-ui-sm">
                                Tidak ada data Purchase Order (PO) yang ditemukan.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($purchaseOrders->hasPages())
            <x-slot:pagination>
                {{ $purchaseOrders->links() }}
            </x-slot:pagination>
        @endif
    </x-ui.data-table>
</div>
@endsection
