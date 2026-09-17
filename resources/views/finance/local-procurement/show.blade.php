@extends('layouts.app')
@section('title', 'PO ' . $purchaseOrder->po_number . ' - ADASI Portal')
@section('page-title', 'Detail Local Purchase Order')

@php
    $activeGrs = $purchaseOrder->goodsReceipts->where('status', '!=', \App\Models\LocalGoodsReceipt::STATUS_CANCELLED);
    $activeGrTotal = (float) $activeGrs->sum('received_amount');
    $remainingAmount = max(0, (float) $purchaseOrder->total_amount - $activeGrTotal);
    $fulfillmentPercent = $purchaseOrder->total_amount > 0 ? min(100, round(($activeGrTotal / $purchaseOrder->total_amount) * 100, 1)) : 0;
@endphp

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    {{-- Header --}}
    <x-ui.page-header
        :title="'PO ' . $purchaseOrder->po_number"
        :description="($purchaseOrder->supplier->supplier?->company_name ?: $purchaseOrder->supplier->name) . ' · IDR ' . number_format($purchaseOrder->total_amount, 2, ',', '.')"
        eyebrow="Pengadaan Lokal"
    >
        <x-slot:actions>
            <x-ui.button :href="route($routePrefix.'.index')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>Kembali ke Daftar</span>
            </x-ui.button>

            @if($purchaseOrder->status === 'OPEN')
                <x-ui.button type="button" size="sm" variant="primary" data-bs-toggle="modal" data-bs-target="#addGrModal">
                    <x-ui.icon name="plus" size="xs" />
                    <span>Tambah GR</span>
                </x-ui.button>

                <x-ui.button :href="route($routePrefix.'.edit', $purchaseOrder)" variant="outline" size="sm">
                    <x-ui.icon name="file-pen" size="xs" />
                    <span>Edit PO</span>
                </x-ui.button>

                <button type="button" class="btn btn-sm btn-outline-secondary tw-inline-flex tw-items-center tw-gap-1.5" data-bs-toggle="modal" data-bs-target="#closePoModal">
                    <x-ui.icon name="lock" size="xs" />
                    <span>Tutup PO</span>
                </button>

                <button type="button" class="btn btn-sm btn-outline-danger tw-inline-flex tw-items-center tw-gap-1.5" data-bs-toggle="modal" data-bs-target="#cancelPoModal">
                    <x-ui.icon name="x-circle" size="xs" />
                    <span>Batalkan PO</span>
                </button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    {{-- PO Information & Allocation Summary Card --}}
    <div class="tw-grid tw-grid-cols-1 lg:tw-grid-cols-3 tw-gap-4">
        <div class="lg:tw-col-span-2 tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container tw-p-4 tw-space-y-4">
            <div class="tw-flex tw-flex-col sm:tw-flex-row sm:tw-items-center sm:tw-justify-between tw-gap-3">
                <div class="tw-flex tw-items-center tw-gap-3">
                    <div class="tw-w-10 tw-h-10 tw-rounded-full tw-bg-primary/10 tw-text-primary tw-flex tw-items-center tw-justify-center tw-shrink-0">
                        <x-ui.icon name="file-text" size="md" />
                    </div>
                    <div>
                        <div class="tw-text-ui-base tw-font-bold tw-font-mono tw-text-on-surface">{{ $purchaseOrder->po_number }}</div>
                        <div class="tw-text-ui-xs tw-text-on-surface-variant">
                            Dibuat pada {{ $purchaseOrder->po_date?->format('d M Y') ?? '—' }} · Sumber: {{ $purchaseOrder->source ?: 'MANUAL' }}
                        </div>
                    </div>
                </div>
                <div class="tw-flex tw-items-center tw-gap-2">
                    <x-ui.status-chip :tone="\App\Support\StatusHelper::localFinanceTone($purchaseOrder->status)">
                        Status: {{ $purchaseOrder->status }}
                    </x-ui.status-chip>
                </div>
            </div>

            <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-2 tw-gap-3 tw-border-t tw-border-outline-variant tw-pt-3 tw-text-ui-xs">
                <div>
                    <span class="tw-text-on-surface-variant">Supplier:</span>
                    <strong class="tw-block tw-text-ui-sm tw-text-on-surface tw-mt-0.5">
                        {{ $purchaseOrder->supplier->supplier?->company_name ?: $purchaseOrder->supplier->name }}
                    </strong>
                    <span class="tw-text-on-surface-variant">
                        {{ $purchaseOrder->supplier->email }} · {{ $purchaseOrder->supplier->supplier?->vendor_category ?: $purchaseOrder->supplier->supplier?->category ?: 'Vendor' }}
                    </span>
                </div>
                <div>
                    <span class="tw-text-on-surface-variant">Catatan PO:</span>
                    <p class="tw-text-ui-xs tw-text-on-surface tw-mt-0.5 tw-mb-0">
                        {{ $purchaseOrder->description ?: 'Tidak ada catatan khusus pada dokumen PO ini.' }}
                    </p>
                </div>
            </div>
        </div>

        {{-- Financial & GR Progress Card --}}
        <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container tw-p-4 tw-space-y-3">
            <div class="tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant tw-uppercase tw-tracking-wider">
                Realisasi Penerimaan Barang (GR)
            </div>

            <div class="tw-space-y-2 tw-text-ui-xs">
                <div class="tw-flex tw-items-center tw-justify-between">
                    <span class="tw-text-on-surface-variant">Nilai Plafon PO:</span>
                    <strong class="tw-font-mono tw-text-on-surface">Rp {{ number_format($purchaseOrder->total_amount, 2, ',', '.') }}</strong>
                </div>
                <div class="tw-flex tw-items-center tw-justify-between">
                    <span class="tw-text-on-surface-variant">Total GR Aktif:</span>
                    <strong class="tw-font-mono tw-text-success">Rp {{ number_format($activeGrTotal, 2, ',', '.') }}</strong>
                </div>
                <div class="tw-flex tw-items-center tw-justify-between tw-border-t tw-border-outline-variant tw-pt-1.5">
                    <span class="tw-text-on-surface-variant">Sisa Saldo PO:</span>
                    <strong class="tw-font-mono {{ $remainingAmount > 0 ? 'tw-text-primary' : 'tw-text-on-surface-variant' }}">
                        Rp {{ number_format($remainingAmount, 2, ',', '.') }}
                    </strong>
                </div>
            </div>

            {{-- Progress bar --}}
            <div class="tw-space-y-1 tw-pt-1">
                <div class="tw-flex tw-items-center tw-justify-between tw-text-[11px] tw-text-on-surface-variant">
                    <span>Realisasi GR:</span>
                    <span class="tw-font-semibold tw-text-on-surface">{{ $fulfillmentPercent }}%</span>
                </div>
                <div class="tw-w-full tw-bg-surface-high tw-rounded-full tw-h-2 tw-overflow-hidden">
                    <div class="tw-bg-primary tw-h-2 tw-rounded-full tw-transition-all" style="width: {{ $fulfillmentPercent }}%"></div>
                </div>
            </div>
        </div>
    </div>

    {{-- Goods Receipts Data Table --}}
    <x-ui.data-table
        title="Daftar Penerimaan Barang (GR) Utuh"
        :description="'Total ' . $purchaseOrder->goodsReceipts->count() . ' berkas GR tercatat pada PO ini. Hanya GR berstatus AVAILABLE yang dapat diubah atau dibatalkan.'"
    >
        <div class="table-responsive">
            <table class="table table-hover align-middle tw-m-0 tw-text-ui-sm w-100">
                <thead class="table-light">
                    <tr>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">Nomor GR</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">Tanggal GR</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">Nilai Penerimaan (Rp)</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold text-center">Status</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">Terhubung ke Invoice</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold text-center">Sumber</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($purchaseOrder->goodsReceipts as $gr)
                        <tr>
                            <td>
                                <strong class="tw-font-mono tw-text-on-surface">{{ $gr->gr_number }}</strong>
                                @if($gr->notes)
                                    <span class="tw-block tw-text-[11px] tw-text-on-surface-variant">{{ $gr->notes }}</span>
                                @endif
                            </td>
                            <td>
                                <span class="tw-text-ui-xs tw-text-on-surface-variant">
                                    {{ $gr->gr_date?->format('d M Y') ?? '—' }}
                                </span>
                            </td>
                            <td class="text-end tw-font-mono tw-font-bold tw-text-on-surface">
                                Rp {{ number_format($gr->received_amount, 2, ',', '.') }}
                            </td>
                            <td class="text-center">
                                <x-ui.status-chip :tone="\App\Support\StatusHelper::localFinanceTone($gr->status)">
                                    {{ $gr->status }}
                                </x-ui.status-chip>
                            </td>
                            <td>
                                @if($gr->currentInvoice)
                                    <a href="{{ route('finance.invoices.show', $gr->currentInvoice) }}" class="tw-text-ui-xs tw-font-semibold tw-text-primary hover:tw-underline">
                                        {{ $gr->currentInvoice->invoice_number }}
                                    </a>
                                @else
                                    <span class="tw-text-ui-xs tw-text-on-surface-variant">—</span>
                                @endif
                            </td>
                            <td class="text-center">
                                <span class="tw-inline-flex tw-items-center tw-px-2 tw-py-0.5 tw-rounded tw-text-[10px] tw-font-semibold {{ $gr->source === 'IMPORT' ? 'tw-bg-secondary/10 tw-text-secondary' : 'tw-bg-surface-high tw-text-on-surface' }}">
                                    {{ $gr->source ?: 'MANUAL' }}
                                </span>
                            </td>
                            <td class="text-end">
                                @if($gr->status === 'AVAILABLE')
                                    <div class="tw-inline-flex tw-items-center tw-gap-1.5">
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-secondary tw-inline-flex tw-items-center tw-gap-1"
                                            data-bs-toggle="modal"
                                            data-bs-target="#editGrModal-{{ $gr->id }}"
                                        >
                                            <x-ui.icon name="file-pen" size="xs" />
                                            <span>Edit</span>
                                        </button>

                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-danger tw-inline-flex tw-items-center tw-gap-1"
                                            data-bs-toggle="modal"
                                            data-bs-target="#cancelGrModal-{{ $gr->id }}"
                                        >
                                            <x-ui.icon name="x" size="xs" />
                                            <span>Batal</span>
                                        </button>
                                    </div>
                                @else
                                    <span class="tw-text-ui-xs tw-text-on-surface-variant tw-italic">Terkunci ({{ $gr->status }})</span>
                                @endif
                            </td>
                        </tr>

                        {{-- Modal Edit GR --}}
                        @if($gr->status === 'AVAILABLE')
                            <div class="modal fade" id="editGrModal-{{ $gr->id }}" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog modal-dialog-centered">
                                    <form method="POST" action="{{ route($routePrefix.'.goods-receipts.update', $gr) }}">
                                        @csrf
                                        @method('PUT')
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title tw-text-ui-sm tw-font-bold tw-text-on-surface tw-flex tw-items-center tw-gap-2">
                                                    <x-ui.icon name="file-pen" size="sm" class="tw-text-primary" />
                                                    <span>Edit Goods Receipt: {{ $gr->gr_number }}</span>
                                                </h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                            </div>
                                            <div class="modal-body tw-space-y-3">
                                                <div>
                                                    <label class="form-label tw-text-ui-xs tw-font-semibold">Nomor GR <span class="text-danger">*</span></label>
                                                    <input name="gr_number" class="form-control form-control-sm" value="{{ $gr->gr_number }}" required>
                                                </div>

                                                <div>
                                                    <x-ui.date-picker
                                                        :id="'edit_gr_date_'.$gr->id"
                                                        name="gr_date"
                                                        label="Tanggal GR"
                                                        :value="$gr->gr_date?->format('Y-m-d')"
                                                        required="true"
                                                    />
                                                </div>

                                                <div>
                                                    <label class="form-label tw-text-ui-xs tw-font-semibold">Nilai Penerimaan (Rp) <span class="text-danger">*</span></label>
                                                    <input
                                                        name="received_amount"
                                                        type="number"
                                                        step="0.01"
                                                        min="0.01"
                                                        class="form-control form-control-sm tw-font-mono"
                                                        value="{{ $gr->received_amount }}"
                                                        required
                                                    >
                                                </div>

                                                <div>
                                                    <label class="form-label tw-text-ui-xs tw-font-semibold">Catatan (Opsional)</label>
                                                    <input name="notes" class="form-control form-control-sm" value="{{ $gr->notes }}" placeholder="Keterangan penerimaan barang...">
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <x-ui.button type="button" variant="ghost" size="sm" data-bs-dismiss="modal">Batal</x-ui.button>
                                                <x-ui.button type="submit" variant="primary" size="sm">
                                                    <x-ui.icon name="check" size="xs" />
                                                    <span>Simpan Perubahan</span>
                                                </x-ui.button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            {{-- Modal Konfirmasi Batal GR --}}
                            <div class="modal fade" id="cancelGrModal-{{ $gr->id }}" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog modal-dialog-centered">
                                    <form method="POST" action="{{ route($routePrefix.'.goods-receipts.cancel', $gr) }}">
                                        @csrf
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title tw-text-ui-sm tw-font-bold tw-text-error tw-flex tw-items-center tw-gap-2">
                                                    <x-ui.icon name="alert-triangle" size="sm" />
                                                    <span>Batalkan Goods Receipt</span>
                                                </h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                            </div>
                                            <div class="modal-body tw-space-y-2">
                                                <p class="tw-text-ui-sm tw-text-on-surface tw-mb-0">
                                                    Apakah Anda yakin ingin membatalkan Goods Receipt <strong>{{ $gr->gr_number }}</strong> senilai <strong>Rp {{ number_format($gr->received_amount, 2, ',', '.') }}</strong>?
                                                </p>
                                                <p class="tw-text-ui-xs tw-text-on-surface-variant tw-mb-0">
                                                    Setelah dibatalkan, alokasi GR ini tidak dapat digunakan lagi oleh invoice supplier.
                                                </p>
                                            </div>
                                            <div class="modal-footer">
                                                <x-ui.button type="button" variant="ghost" size="sm" data-bs-dismiss="modal">Batal</x-ui.button>
                                                <button type="submit" class="btn btn-sm btn-danger tw-inline-flex tw-items-center tw-gap-1">
                                                    <x-ui.icon name="x" size="xs" />
                                                    <span>Ya, Batalkan GR</span>
                                                </button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        @endif
                    @empty
                        <tr>
                            <td colspan="7" class="tw-py-8 tw-text-center tw-text-on-surface-variant tw-text-ui-sm">
                                Belum ada berkas Goods Receipt (GR) yang tercatat untuk Purchase Order ini.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.data-table>
</div>

{{-- Modal Tambah GR Baru --}}
@if($purchaseOrder->status === 'OPEN')
    <div class="modal fade" id="addGrModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form method="POST" action="{{ route($routePrefix.'.goods-receipts.store', $purchaseOrder) }}">
                @csrf
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title tw-text-ui-sm tw-font-bold tw-text-on-surface tw-flex tw-items-center tw-gap-2">
                            <x-ui.icon name="plus-circle" size="sm" class="tw-text-primary" />
                            <span>Tambah Goods Receipt (GR) Baru</span>
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body tw-space-y-3">
                        <div class="tw-rounded-lg tw-bg-surface-container tw-p-3 tw-text-ui-xs tw-space-y-1">
                            <div class="tw-flex tw-justify-between">
                                <span class="tw-text-on-surface-variant">Purchase Order:</span>
                                <strong class="tw-font-mono">{{ $purchaseOrder->po_number }}</strong>
                            </div>
                            <div class="tw-flex tw-justify-between">
                                <span class="tw-text-on-surface-variant">Sisa Saldo PO:</span>
                                <strong class="tw-font-mono tw-text-primary">Rp {{ number_format($remainingAmount, 2, ',', '.') }}</strong>
                            </div>
                        </div>

                        <div>
                            <label class="form-label tw-text-ui-xs tw-font-semibold">Nomor GR <span class="text-danger">*</span></label>
                            <input name="gr_number" class="form-control form-control-sm" placeholder="Contoh: GR-2026-09-001" required>
                        </div>

                        <div>
                            <x-ui.date-picker
                                id="add_new_gr_date"
                                name="gr_date"
                                label="Tanggal GR"
                                :value="now()->format('Y-m-d')"
                                required="true"
                            />
                        </div>

                        <div>
                            <label class="form-label tw-text-ui-xs tw-font-semibold">Nilai Penerimaan Barang (IDR) <span class="text-danger">*</span></label>
                            <input
                                name="received_amount"
                                type="number"
                                step="0.01"
                                min="0.01"
                                class="form-control form-control-sm tw-font-mono"
                                placeholder="0.00"
                                required
                            >
                            <div class="tw-text-[11px] tw-text-on-surface-variant tw-mt-1">
                                Akumulasi total seluruh GR aktif tidak boleh melebihi plafon PO (Rp {{ number_format($purchaseOrder->total_amount, 2, ',', '.') }}).
                            </div>
                        </div>

                        <div>
                            <label class="form-label tw-text-ui-xs tw-font-semibold">Catatan / Keterangan</label>
                            <input name="notes" class="form-control form-control-sm" placeholder="Catatan penerimaan fisik barang...">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <x-ui.button type="button" variant="ghost" size="sm" data-bs-dismiss="modal">Batal</x-ui.button>
                        <x-ui.button type="submit" variant="primary" size="sm">
                            <x-ui.icon name="plus" size="xs" />
                            <span>Simpan Goods Receipt</span>
                        </x-ui.button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    {{-- Modal Konfirmasi Tutup PO --}}
    <div class="modal fade" id="closePoModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form method="POST" action="{{ route($routePrefix.'.close', $purchaseOrder) }}">
                @csrf
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title tw-text-ui-sm tw-font-bold tw-text-on-surface tw-flex tw-items-center tw-gap-2">
                            <x-ui.icon name="lock" size="sm" class="tw-text-warning" />
                            <span>Tutup Purchase Order</span>
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body tw-space-y-2">
                        <p class="tw-text-ui-sm tw-text-on-surface tw-mb-0">
                            Apakah Anda yakin ingin menutup Purchase Order <strong>{{ $purchaseOrder->po_number }}</strong>?
                        </p>
                        <p class="tw-text-ui-xs tw-text-on-surface-variant tw-mb-0">
                            Setelah ditutup, PO tidak dapat lagi diterbitkan Goods Receipt baru.
                        </p>
                    </div>
                    <div class="modal-footer">
                        <x-ui.button type="button" variant="ghost" size="sm" data-bs-dismiss="modal">Batal</x-ui.button>
                        <x-ui.button type="submit" variant="primary" size="sm">
                            <x-ui.icon name="lock" size="xs" />
                            <span>Ya, Tutup PO</span>
                        </x-ui.button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    {{-- Modal Konfirmasi Batal PO --}}
    <div class="modal fade" id="cancelPoModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form method="POST" action="{{ route($routePrefix.'.cancel', $purchaseOrder) }}">
                @csrf
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title tw-text-ui-sm tw-font-bold tw-text-error tw-flex tw-items-center tw-gap-2">
                            <x-ui.icon name="alert-triangle" size="sm" />
                            <span>Batalkan Purchase Order</span>
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body tw-space-y-2">
                        <p class="tw-text-ui-sm tw-text-on-surface tw-mb-0">
                            Apakah Anda yakin ingin membatalkan Purchase Order <strong>{{ $purchaseOrder->po_number }}</strong>?
                        </p>
                        <p class="tw-text-ui-xs tw-text-on-surface-variant tw-mb-0">
                            Pembatalan PO hanya dapat dilakukan jika belum ada berkas invoice yang terhubung.
                        </p>
                    </div>
                    <div class="modal-footer">
                        <x-ui.button type="button" variant="ghost" size="sm" data-bs-dismiss="modal">Batal</x-ui.button>
                        <button type="submit" class="btn btn-sm btn-danger tw-inline-flex tw-items-center tw-gap-1">
                            <x-ui.icon name="trash-2" size="xs" />
                            <span>Ya, Batalkan PO</span>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
@endif
@endsection
