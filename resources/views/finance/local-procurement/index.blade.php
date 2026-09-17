@extends('layouts.app')
@section('title', 'Master PO & GR')
@section('page-title', 'Master PO & GR')

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        title="Master PO & GR Lokal"
        description="Kelola data authoritative Purchase Order (PO) lokal dan realisasi Goods Receipt (GR) utuh untuk verifikasi tagihan supplier."
        eyebrow="Pengadaan Lokal"
    >
        <x-slot:actions>
            <x-ui.button type="button" variant="outline" size="sm" data-bs-toggle="modal" data-bs-target="#localPoGrImportModal">
                <x-ui.icon name="file-up" size="xs" />
                <span>Import XLSX</span>
            </x-ui.button>
            <x-ui.button :href="route($routePrefix.'.create')" size="sm" variant="primary">
                <x-ui.icon name="plus" size="xs" />
                <span>Buat PO Baru</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- KPI Metric Cards Grid --}}
    <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-2 lg:tw-grid-cols-4 tw-gap-4">
        <x-ui.metric-card
            label="Total PO Terdaftar"
            :value="number_format($metrics['total_pos'] ?? 0)"
            icon="layers"
            tone="neutral"
            meta="Seluruh PO lokal aktif"
        />

        <x-ui.metric-card
            label="Total Nilai PO"
            :value="'Rp ' . number_format($metrics['total_po_amount'] ?? 0, 0, ',', '.')"
            icon="banknote"
            tone="primary"
            meta="Akumulasi komitmen anggaran"
        />

        <x-ui.metric-card
            label="PO Berstatus OPEN"
            :value="number_format($metrics['open_pos_count'] ?? 0)"
            icon="clock"
            tone="warning"
            meta="Dapat diterbitkan GR"
            :href="route($routePrefix.'.index', ['status' => 'OPEN'])"
        />

        <x-ui.metric-card
            label="Total Nilai GR Aktif"
            :value="'Rp ' . number_format($metrics['active_gr_amount'] ?? 0, 0, ',', '.')"
            icon="package-check"
            tone="success"
            meta="Akumulasi penerimaan barang"
        />
    </div>

    {{-- Filter Toolbar --}}
    <form method="GET" action="{{ route($routePrefix.'.index') }}" id="localPoFilterForm" class="tw-m-0">
        <x-ui.toolbar aria-label="Kontrol filter master PO & GR">
            <x-slot:search>
                <div class="tw-relative tw-w-full">
                    <input
                        type="search"
                        name="q"
                        id="local-po-search"
                        value="{{ request('q') }}"
                        placeholder="Cari nomor PO..."
                        class="form-control form-control-sm tw-text-ui-xs"
                        maxlength="100"
                        autocomplete="off"
                    >
                </div>
            </x-slot:search>

            <x-slot:filters>
                <div class="tw-flex tw-flex-wrap tw-items-center tw-gap-2.5">
                    <div class="tw-w-48">
                        <label for="po-supplier" class="visually-hidden">Supplier</label>
                        <select id="po-supplier" name="supplier_id" class="form-select form-select-sm tw-text-ui-xs">
                            <option value="">Semua Supplier</option>
                            @foreach($suppliers as $supplier)
                                <option value="{{ $supplier->hash }}" @selected((string) request('supplier_id') === (string) $supplier->hash)>
                                    {{ $supplier->supplier?->company_name ?: $supplier->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="tw-w-36">
                        <label for="po-status" class="visually-hidden">Status PO</label>
                        <select id="po-status" name="status" class="form-select form-select-sm tw-text-ui-xs">
                            <option value="">Semua Status</option>
                            @foreach(['OPEN', 'CLOSED', 'CANCELLED'] as $status)
                                <option value="{{ $status }}" @selected(request('status') === $status)>{{ $status }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="tw-w-64">
                        <x-ui.date-range-picker
                            id="local-po-date-range"
                            start-name="date_from"
                            end-name="date_to"
                            start-label="Dari Tanggal"
                            end-label="Sampai Tanggal"
                            :start-value="request('date_from')"
                            :end-value="request('date_to')"
                            :compact="true"
                        />
                    </div>
                </div>
            </x-slot:filters>

            <x-slot:actions>
                <x-ui.button type="submit" size="sm" variant="primary">
                    <x-ui.icon name="filter" size="xs" />
                    <span>Filter</span>
                </x-ui.button>
                @if(request()->hasAny(['q', 'supplier_id', 'status', 'date_from', 'date_to']))
                    <x-ui.button :href="route($routePrefix.'.index')" size="sm" variant="ghost">
                        <x-ui.icon name="rotate-ccw" size="xs" />
                        <span>Reset</span>
                    </x-ui.button>
                @endif
            </x-slot:actions>
        </x-ui.toolbar>
    </form>

    {{-- Data Table --}}
    <x-ui.data-table
        title="Daftar Purchase Order (PO) Lokal"
        :description="'Menampilkan ' . $purchaseOrders->total() . ' data PO lokal.'"
    >
        <div class="table-responsive">
            <table class="table table-hover align-middle tw-m-0 tw-text-ui-sm w-100">
                <thead class="table-light">
                    <tr>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">Nomor PO</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">Supplier</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">Tanggal PO</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">Nilai PO (Rp)</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">Realisasi GR</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold text-center">Status</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold text-center">Sumber</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($purchaseOrders as $po)
                        <tr>
                            <td>
                                <a href="{{ route($routePrefix.'.show', $po) }}" class="tw-font-mono tw-font-semibold tw-text-primary hover:tw-text-primary-hover tw-no-underline hover:tw-underline">
                                    {{ $po->po_number }}
                                </a>
                            </td>
                            <td>
                                <div class="tw-font-medium tw-text-on-surface">
                                    {{ $po->supplier->supplier?->company_name ?: $po->supplier->name }}
                                </div>
                                <div class="tw-text-[11px] tw-text-on-surface-variant">
                                    {{ $po->supplier->supplier?->vendor_category ?: $po->supplier->supplier?->category ?: 'Vendor' }}
                                </div>
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
                                    Rp {{ number_format($po->active_gr_amount ?? 0, 2, ',', '.') }}
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
                                <span class="tw-inline-flex tw-items-center tw-px-2 tw-py-0.5 tw-rounded tw-text-[10px] tw-font-semibold {{ $po->source === 'IMPORT' ? 'tw-bg-secondary/10 tw-text-secondary' : 'tw-bg-surface-high tw-text-on-surface' }}">
                                    {{ $po->source ?: 'MANUAL' }}
                                </span>
                            </td>
                            <td class="text-end">
                                <x-ui.button :href="route($routePrefix.'.show', $po)" size="sm" variant="outline">
                                    <x-ui.icon name="eye" size="xs" />
                                    <span>Detail</span>
                                </x-ui.button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="tw-py-8 tw-text-center tw-text-on-surface-variant tw-text-ui-sm">
                                Tidak ada data Purchase Order (PO) yang sesuai dengan filter.
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

@include('finance.local-procurement._import_modal')
@endsection
