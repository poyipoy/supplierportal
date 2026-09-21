@extends('layouts.app')

@php
    $isEdit = $purchaseOrder->exists;
    $isLocked = $isEdit && ($purchaseOrder->goodsReceipts()->exists() || $purchaseOrder->invoices()->exists());
    $activeGrTotal = $isEdit ? (float) $purchaseOrder->goodsReceipts()->where('status', '!=', \App\Models\LocalGoodsReceipt::STATUS_CANCELLED)->sum('received_amount') : 0;
    $initialAmount = old('total_amount', $purchaseOrder->total_amount ?? '');
@endphp

@section('title', $isEdit ? 'Edit Purchase Order (PO)' : 'Buat Purchase Order (PO) Baru')
@section('page-title', $isEdit ? 'Edit Purchase Order (PO)' : 'Buat Purchase Order (PO) Baru')

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        :title="$isEdit ? 'Edit Purchase Order (PO)' : 'Buat Purchase Order (PO) Baru'"
        description="Mata uang PO lokal dikunci pada IDR. Identitas nomor PO dan supplier menjadi terkunci secara audit setelah memiliki Goods Receipt (GR) atau invoice."
        eyebrow="Pengadaan Lokal"
    >
        <x-slot:actions>
            <x-ui.button :href="route($routePrefix.'.index')" variant="outline" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>Kembali ke Master PO</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if($errors->any())
        <x-ui.alert tone="error" title="Harap periksa dan perbaiki kesalahan berikut:" class="tw-mb-2">
            <ul class="tw-mb-0 tw-mt-1.5 tw-list-disc tw-ps-4 tw-space-y-0.5 tw-text-ui-xs">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-ui.alert>
    @endif

    <form method="POST" action="{{ $isEdit ? route($routePrefix.'.update', $purchaseOrder) : route($routePrefix.'.store') }}" id="localPoForm">
        @csrf
        @if($isEdit)
            @method('PUT')
        @endif

        <div class="tw-grid tw-gap-6 lg:tw-grid-cols-12 tw-items-start">
            {{-- Kolom Kiri: Form Sections --}}
            <div class="lg:tw-col-span-8">
                {{-- 1. Identitas Dokumen & Supplier --}}
                <x-ui.form-section
                    title="Identitas Dokumen & Supplier"
                    description="Informasi otoritatif nomor PO, rekanan supplier lokal terdaftar, dan tanggal penetapan dokumen."
                >
                    <div class="tw-grid tw-gap-4 sm:tw-grid-cols-2">
                        {{-- Nomor PO --}}
                        <div>
                            <div class="tw-flex tw-items-center tw-justify-between tw-mb-1">
                                <label for="po_number" class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface tw-mb-0">
                                    Nomor PO <span class="tw-text-error">*</span>
                                </label>
                                @if($isLocked)
                                    <span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-semibold tw-bg-warning-container tw-text-warning-container-foreground">
                                        <x-ui.icon name="lock" size="sm" />
                                        <span>Terkunci Audit</span>
                                    </span>
                                @endif
                            </div>
                            <input
                                type="text"
                                name="po_number"
                                id="po_number"
                                class="form-control form-control-sm tw-font-mono @error('po_number') is-invalid @enderror"
                                value="{{ old('po_number', $purchaseOrder->po_number) }}"
                                placeholder="Contoh: PO/2026/09/001"
                                @if($isLocked) readonly @endif
                                required
                            >
                            @error('po_number')
                                <div class="invalid-feedback tw-text-ui-xs">{{ $message }}</div>
                            @enderror
                            @if($isLocked)
                                <div class="form-text tw-text-[11px] tw-text-on-surface-variant">
                                    Nomor PO tidak dapat diubah karena sudah memiliki relasi GR atau invoice tercatat.
                                </div>
                            @endif
                        </div>

                        {{-- Supplier Lokal --}}
                        <div>
                            <div class="tw-flex tw-items-center tw-justify-between tw-mb-1">
                                <label for="supplier_id" class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface tw-mb-0">
                                    Supplier Lokal <span class="tw-text-error">*</span>
                                </label>
                                @if($isLocked)
                                    <span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-semibold tw-bg-warning-container tw-text-warning-container-foreground">
                                        <x-ui.icon name="lock" size="sm" />
                                        <span>Terkunci</span>
                                    </span>
                                @endif
                            </div>
                            @if($isLocked)
                                <input type="hidden" name="supplier_id" value="{{ $purchaseOrder->supplier_id }}">
                                <select id="supplier_id_disabled" class="form-select form-select-sm" disabled>
                                    @foreach($suppliers as $supplier)
                                        @if((string)$purchaseOrder->supplier_id === (string)$supplier->id)
                                            <option value="{{ $supplier->id }}" selected>
                                                {{ $supplier->supplier?->company_name ?: $supplier->name }}
                                            </option>
                                        @endif
                                    @endforeach
                                </select>
                                <div class="form-text tw-text-[11px] tw-text-on-surface-variant">
                                    Entitas supplier lokal tidak dapat dialihkan pada PO yang telah aktif berjalan.
                                </div>
                            @else
                                <select
                                    name="supplier_id"
                                    id="supplier_id"
                                    class="form-select form-select-sm @error('supplier_id') is-invalid @enderror"
                                    required
                                >
                                    <option value="">Pilih Supplier Lokal...</option>
                                    @foreach($suppliers as $supplier)
                                        <option
                                            value="{{ $supplier->id }}"
                                            @selected((string)old('supplier_id', $purchaseOrder->supplier_id) === (string)$supplier->id)
                                        >
                                            {{ $supplier->supplier?->company_name ?: $supplier->name }}
                                            @if($supplier->supplier?->vendor_category)
                                                ({{ $supplier->supplier->vendor_category }})
                                            @endif
                                        </option>
                                    @endforeach
                                </select>
                                @error('supplier_id')
                                    <div class="invalid-feedback tw-text-ui-xs">{{ $message }}</div>
                                @enderror
                            @endif
                        </div>

                        {{-- Tanggal PO --}}
                        <div>
                            <label for="local_po_date" class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface tw-mb-1">
                                Tanggal Dokumen PO <span class="tw-text-error">*</span>
                            </label>
                            <x-ui.date-picker
                                id="local_po_date"
                                name="po_date"
                                :value="old('po_date', $purchaseOrder->po_date?->format('Y-m-d') ?? now()->format('Y-m-d'))"
                                required="true"
                            />
                            @error('po_date')
                                <div class="text-danger tw-text-ui-xs tw-mt-1">{{ $message }}</div>
                            @enderror
                        </div>

                        {{-- Plafon Nilai PO (IDR) --}}
                        <div>
                            <label for="total_amount" class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface tw-mb-1">
                                Plafon Nilai PO (IDR) <span class="tw-text-error">*</span>
                            </label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text tw-font-semibold tw-text-on-surface-variant">Rp</span>
                                <input
                                    type="number"
                                    name="total_amount"
                                    id="total_amount"
                                    min="{{ $isLocked && $activeGrTotal > 0 ? $activeGrTotal : '0.01' }}"
                                    step="0.01"
                                    class="form-control form-control-sm tw-font-mono @error('total_amount') is-invalid @enderror"
                                    value="{{ $initialAmount }}"
                                    placeholder="0.00"
                                    required
                                >
                            </div>
                            @error('total_amount')
                                <div class="text-danger tw-text-ui-xs tw-mt-1">{{ $message }}</div>
                            @enderror
                            @if($isLocked && $activeGrTotal > 0)
                                <div class="form-text tw-text-[11px] tw-text-warning tw-mt-1">
                                    Batas minimum: Rp {{ number_format($activeGrTotal, 2, ',', '.') }} (total akumulasi GR aktif).
                                </div>
                            @endif
                        </div>
                    </div>
                </x-ui.form-section>

                {{-- 2. Keterangan & Catatan Operasional --}}
                <x-ui.form-section
                    title="Keterangan & Catatan Operasional"
                    description="Instruksi pengadaan, catatan spesifikasi material, atau referensi penugasan internal."
                >
                    <div>
                        <label for="description" class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface tw-mb-1">
                            Catatan / Deskripsi PO
                        </label>
                        <textarea
                            name="description"
                            id="description"
                            class="form-control form-control-sm @error('description') is-invalid @enderror"
                            rows="4"
                            placeholder="Tuliskan catatan tambahan mengenai pengadaan barang, kontak pemesan, atau ketentuan khusus..."
                        >{{ old('description', $purchaseOrder->description) }}</textarea>
                        @error('description')
                            <div class="invalid-feedback tw-text-ui-xs">{{ $message }}</div>
                        @enderror
                    </div>
                </x-ui.form-section>
            </div>

            {{-- Kolom Kanan: Sticky Sidebar Ringkasan Dokumen PO --}}
            <div class="lg:tw-col-span-4 tw-sticky" style="top: calc(var(--topbar-height, 56px) + 1.25rem)">
                <x-ui.card title="Ringkasan Dokumen PO">
                    <div class="tw-space-y-4">
                        {{-- Kartu Highlight Plafon Nilai PO --}}
                        <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container tw-p-3.5 tw-space-y-1">
                            <div class="tw-flex tw-items-center tw-justify-between tw-text-ui-xs">
                                <span class="tw-font-medium tw-text-on-surface-variant">Plafon Nilai PO</span>
                                <span class="tw-inline-flex tw-items-center tw-px-2 tw-py-0.5 tw-rounded tw-text-[10px] tw-font-bold tw-bg-primary/10 tw-text-primary">
                                    IDR
                                </span>
                            </div>
                            <div id="sidebar-total-amount" class="tw-font-mono tw-font-bold tw-text-primary tw-text-lg sm:tw-text-xl tw-tracking-tight tw-break-words">
                                Rp {{ number_format((float) ($initialAmount ?: 0), 0, ',', '.') }}
                            </div>
                        </div>

                        {{-- Metadata Dokumen --}}
                        <div class="tw-space-y-2.5 tw-text-ui-xs">
                            <div class="tw-flex tw-items-center tw-justify-between tw-text-on-surface-variant">
                                <span>Mata Uang:</span>
                                <span class="tw-font-semibold tw-text-on-surface">IDR (Rupiah Indonesia)</span>
                            </div>

                            <div class="tw-flex tw-items-center tw-justify-between tw-text-on-surface-variant">
                                <span>Sumber Pembuatan:</span>
                                <span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-medium tw-bg-surface-container-high tw-text-on-surface">
                                    {{ $purchaseOrder->source ?: 'MANUAL' }}
                                </span>
                            </div>

                            @if($isEdit)
                                <div class="tw-flex tw-items-center tw-justify-between tw-text-on-surface-variant">
                                    <span>Status PO:</span>
                                    <x-ui.status-chip :tone="$purchaseOrder->status === 'OPEN' ? 'success' : ($purchaseOrder->status === 'CLOSED' ? 'neutral' : 'error')">
                                        {{ $purchaseOrder->status }}
                                    </x-ui.status-chip>
                                </div>

                                @if($activeGrTotal > 0)
                                    <div class="tw-flex tw-items-center tw-justify-between tw-text-on-surface-variant">
                                        <span>Realisasi GR Aktif:</span>
                                        <span class="tw-font-mono tw-font-semibold tw-text-on-surface">
                                            Rp {{ number_format($activeGrTotal, 0, ',', '.') }}
                                        </span>
                                    </div>
                                @endif
                            @endif
                        </div>

                        {{-- Panel Kepatuhan & Audit --}}
                        <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container-low tw-p-3 tw-space-y-1.5">
                            <div class="tw-flex tw-items-center tw-gap-1.5 tw-text-ui-xs tw-font-semibold tw-text-on-surface">
                                <x-ui.icon name="shield-alert" size="sm" class="tw-text-primary" />
                                <span>Integritas Audit Data</span>
                            </div>
                            <p class="tw-text-[11px] tw-text-on-surface-variant tw-leading-relaxed tw-mb-0">
                                Setelah dokumen PO memiliki catatan Goods Receipt (GR) atau tagihan invoice, nomor PO dan supplier terkunci permanen demi kepatuhan audit pengadaan.
                            </p>
                        </div>

                        {{-- Tombol Aksi --}}
                        <div class="tw-space-y-2 tw-pt-1">
                            <x-ui.button type="submit" variant="primary" class="tw-w-full tw-justify-center">
                                <x-ui.icon name="check" size="sm" class="me-1" />
                                <span>{{ $isEdit ? 'Perbarui Dokumen PO' : 'Simpan Dokumen PO' }}</span>
                            </x-ui.button>

                            <x-ui.button :href="route($routePrefix.'.index')" variant="outline" class="tw-w-full tw-justify-center">
                                <x-ui.icon name="x" size="sm" class="me-1" />
                                <span>Batalkan</span>
                            </x-ui.button>
                        </div>
                    </div>
                </x-ui.card>
            </div>
        </div>
    </form>
</div>

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const amountInput = document.getElementById('total_amount');
        const sidebarAmount = document.getElementById('sidebar-total-amount');

        function formatRupiah(value) {
            if (!value || String(value).trim() === '') {
                return 'Rp 0';
            }
            const trimmed = String(value).trim();
            const parts = trimmed.split('.');
            let intPart = parts[0].replace(/\D/g, '');
            if (!intPart) return 'Rp 0';
            if (intPart.length > 18) {
                intPart = intPart.slice(0, 18);
            }
            const formattedInt = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
            return 'Rp ' + formattedInt;
        }

        function updateAmountDisplay() {
            if (!amountInput || !sidebarAmount) return;
            sidebarAmount.textContent = formatRupiah(amountInput.value);
        }

        if (amountInput) {
            amountInput.addEventListener('input', updateAmountDisplay);
            updateAmountDisplay();
        }
    });
</script>
@endpush
@endsection
