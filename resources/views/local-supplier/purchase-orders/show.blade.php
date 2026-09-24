@extends('layouts.app')
@section('title', 'PO ' . $purchaseOrder->po_number . ' - Supplier Lokal')
@section('page-title', 'Detail Purchase Order')

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        :title="$purchaseOrder->po_number"
        :description="'Dokumen Purchase Order diterbitkan pada ' . ($purchaseOrder->po_date?->format('d M Y') ?? '—') . ' dengan komitmen plafon sebesar Rp ' . number_format($purchaseOrder->total_amount, 2, ',', '.') . '.'"
        eyebrow="Detail Purchase Order"
    >
        <x-slot:actions>
            <x-ui.button :href="route('local-supplier.purchase-orders.index')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>Kembali ke Daftar</span>
            </x-ui.button>

            @if($purchaseOrder->latestPoDocument())
                <x-ui.button :href="route('attachments.show', $purchaseOrder->latestPoDocument()->id)" target="_blank" variant="outline" size="sm">
                    <x-ui.icon name="file-text" size="sm" />
                    <span>Unduh Dokumen PO (PDF)</span>
                </x-ui.button>
            @endif

            @if($purchaseOrder->status === 'OPEN')
                <x-ui.button :href="route('local-supplier.invoices.create')" variant="primary" size="sm">
                    <x-ui.icon name="plus" size="sm" />
                    <span>Ajukan Invoice</span>
                </x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Summary Cards Grid --}}
    @php
        $activeGrs = $purchaseOrder->goodsReceipts->where('status', '!=', \App\Models\LocalGoodsReceipt::STATUS_CANCELLED);
        $totalGrAmount = (float) $activeGrs->sum('received_amount');
        $remainingPoAllocation = max(0, (float) $purchaseOrder->total_amount - $totalGrAmount);
        $invoicedAmount = (float) $purchaseOrder->invoices->whereNotIn('status', [\App\Models\LocalInvoice::STATUS_REJECTED, \App\Models\LocalInvoice::STATUS_CANCELLED])->sum('invoice_amount');
    @endphp

    <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-2 lg:tw-grid-cols-4 tw-gap-4">
        <x-ui.metric-card
            label="Nilai Plafon PO"
            :value="'Rp ' . number_format($purchaseOrder->total_amount, 0, ',', '.')"
            icon="banknote"
            tone="primary"
            meta="Total komitmen anggaran resmi"
        />

        <x-ui.metric-card
            label="Total Realisasi GR"
            :value="'Rp ' . number_format($totalGrAmount, 0, ',', '.')"
            icon="package-check"
            tone="success"
            :meta="$activeGrs->count() . ' penerimaan barang tercatat'"
        />

        <x-ui.metric-card
            label="Sisa Alokasi PO"
            :value="'Rp ' . number_format($remainingPoAllocation, 0, ',', '.')"
            icon="layers"
            :tone="$remainingPoAllocation > 0 ? 'neutral' : 'warning'"
            meta="Kapasitas penerimaan tersisa"
        />

        <x-ui.metric-card
            label="Tagihan Diajukan"
            :value="'Rp ' . number_format($invoicedAmount, 0, ',', '.')"
            icon="receipt"
            tone="neutral"
            :meta="$purchaseOrder->invoices->count() . ' invoice terdata'"
        />
    </div>

    {{-- PO Meta Card --}}
    <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container tw-p-4 tw-space-y-4">
        <div class="tw-flex tw-flex-col sm:tw-flex-row sm:tw-items-center sm:tw-justify-between tw-gap-3">
            <div class="tw-flex tw-items-center tw-gap-3">
                <div class="tw-w-10 tw-h-10 tw-rounded-full tw-bg-primary/10 tw-text-primary tw-flex tw-items-center tw-justify-center tw-shrink-0">
                    <x-ui.icon name="file-text" size="md" />
                </div>
                <div>
                    <div class="tw-text-ui-base tw-font-bold tw-font-mono tw-text-on-surface">{{ $purchaseOrder->po_number }}</div>
                    <div class="tw-text-ui-xs tw-text-on-surface-variant">
                        Tanggal Dokumen: {{ $purchaseOrder->po_date?->format('d M Y') ?? '—' }}
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
                <span class="tw-text-on-surface-variant">Catatan / Deskripsi PO:</span>
                <p class="tw-text-ui-xs tw-text-on-surface tw-mt-0.5 tw-mb-0">
                    {{ $purchaseOrder->description ?: 'Tidak ada catatan khusus pada dokumen PO ini.' }}
                </p>
            </div>
            <div>
                <span class="tw-text-on-surface-variant">Dokumen Resmi Terlampir:</span>
                <div class="tw-mt-1">
                    @if($purchaseOrder->latestPoDocument())
                        <a href="{{ route('attachments.show', $purchaseOrder->latestPoDocument()->id) }}" target="_blank" class="tw-inline-flex tw-items-center tw-gap-1.5 tw-text-primary tw-font-semibold tw-no-underline hover:tw-underline">
                            <x-ui.icon name="file-text" size="sm" />
                            <span>{{ $purchaseOrder->latestPoDocument()->file_name }}</span>
                        </a>
                    @else
                        <span class="tw-text-on-surface-variant tw-italic">Belum ada dokumen PO yang diunggah oleh tim ADASI.</span>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Goods Receipts (GR) Table --}}
    <x-ui.data-table
        title="Daftar Penerimaan Barang (Goods Receipt)"
        :description="'Seluruh GR yang telah diverifikasi dan diterima untuk PO ' . $purchaseOrder->po_number . '.'"
    >
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 tw-text-ui-xs">
                <thead class="table-light">
                    <tr>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">Nomor GR</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">Tanggal GR</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">Qty</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">Deskripsi / Keterangan</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">Nilai Penerimaan</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold text-center">Status GR</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold text-center">Status Penagihan</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($purchaseOrder->goodsReceipts as $gr)
                        <tr>
                            <td class="tw-font-mono tw-font-bold tw-text-on-surface">
                                {{ $gr->gr_number }}
                            </td>
                            <td>
                                <span class="tw-text-ui-xs tw-text-on-surface-variant">
                                    {{ $gr->gr_date?->format('d M Y') ?? '—' }}
                                </span>
                            </td>
                            <td class="text-end tw-font-mono">
                                {{ $gr->qty ? rtrim(rtrim(number_format($gr->qty, 4, ',', '.'), '0'), ',') : '-' }}
                            </td>
                            <td>
                                <span class="tw-text-ui-xs tw-text-on-surface">
                                    {{ $gr->description ?: '-' }}
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
                            <td class="text-center">
                                @if($gr->currentInvoice)
                                    <a href="{{ route('local-supplier.invoices.show', $gr->currentInvoice) }}" class="badge bg-success-subtle text-success border border-success-subtle tw-no-underline hover:tw-underline">
                                        Ditagihkan ({{ $gr->currentInvoice->invoice_number }})
                                    </a>
                                @else
                                    <span class="badge bg-light text-secondary border">
                                        Belum Ditagihkan
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="tw-py-8 tw-text-center tw-text-on-surface-variant tw-text-ui-sm">
                                Belum ada berkas Goods Receipt (GR) yang diterbitkan untuk PO ini.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.data-table>

    {{-- Associated Invoices Table --}}
    @if($purchaseOrder->invoices->isNotEmpty())
        <x-ui.data-table
            title="Riwayat Tagihan Terkait (Invoices)"
            :description="'Daftar invoice yang diajukan dengan merujuk pada PO ' . $purchaseOrder->po_number . '.'"
        >
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 tw-text-ui-xs">
                    <thead class="table-light">
                        <tr>
                            <th scope="col" class="tw-text-ui-xs tw-font-semibold">Nomor Invoice</th>
                            <th scope="col" class="tw-text-ui-xs tw-font-semibold">Tanggal Invoice</th>
                            <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">DPP Tagihan</th>
                            <th scope="col" class="tw-text-ui-xs tw-font-semibold text-center">Status</th>
                            <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($purchaseOrder->invoices as $inv)
                            <tr>
                                <td class="tw-font-mono tw-font-bold tw-text-on-surface">
                                    {{ $inv->invoice_number }}
                                </td>
                                <td>
                                    <span class="tw-text-ui-xs tw-text-on-surface-variant">
                                        {{ $inv->invoice_date?->format('d M Y') ?? '—' }}
                                    </span>
                                </td>
                                <td class="text-end tw-font-mono tw-font-bold tw-text-on-surface">
                                    Rp {{ number_format($inv->invoice_amount, 2, ',', '.') }}
                                </td>
                                <td class="text-center">
                                    <x-ui.status-chip :tone="\App\Support\StatusHelper::localInvoiceTone($inv->status)">
                                        {{ $inv->status }}
                                    </x-ui.status-chip>
                                </td>
                                <td class="text-end">
                                    <x-ui.button :href="route('local-supplier.invoices.show', $inv)" size="sm" variant="outline">
                                        <x-ui.icon name="eye" size="sm" />
                                        <span>Detail</span>
                                    </x-ui.button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.data-table>
    @endif
</div>
@endsection
