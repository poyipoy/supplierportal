@extends('layouts.app')
@section('title', 'Verifikasi Invoice '.$invoice->invoice_number.' - Finance AP')
@section('page-title', 'Detail & Verifikasi Invoice')

@section('content')
@php
    $isLocked = (bool) ($verification?->is_locked || in_array($invoice->status, [
        \App\Models\LocalInvoice::STATUS_READY_TO_PAY,
        \App\Models\LocalInvoice::STATUS_PAID,
        \App\Models\LocalInvoice::STATUS_REJECTED,
        \App\Models\LocalInvoice::STATUS_CANCELLED,
    ], true));
@endphp
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        :title="'Invoice '.$invoice->invoice_number"
        :description="'Pengajuan: '.$invoice->submission_number.' — Supplier: '.($invoice->supplier->supplier?->company_name ?: $invoice->supplier->name)"
        eyebrow="Finance Verification Workflow"
    >
        <x-slot:actions>
            <x-ui.button :href="route('finance.invoices.index')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>Kembali ke Register</span>
            </x-ui.button>
            @if($invoice->receipt)
                <x-ui.button :href="route('finance.invoices.receipt', $invoice)" variant="outline" size="sm" target="_blank">
                    <x-ui.icon name="printer" size="sm" />
                    <span>Cetak Tanda Terima</span>
                </x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if($invoice->has_po_discrepancy)
        <x-ui.alert tone="warning" title="Peringatan Discrepancy PO!">
            Nilai tagihan DPP invoice ini (Rp {{ number_format($invoice->invoice_amount, 0, ',', '.') }}) melebihi sisa nilai PO (Rp {{ number_format($invoice->po_remaining_snapshot ?? 0, 0, ',', '.') }}). Harap verifikasi lebih teliti sebelum menyetujui.
        </x-ui.alert>
    @endif

    <div class="tw-grid tw-grid-cols-1 lg:tw-grid-cols-12 tw-gap-6">
        {{-- Left Column: Details, Documents, Verification Sections --}}
        <div class="lg:tw-col-span-8 tw-space-y-6">
            {{-- Overview Card --}}
            <x-ui.card title="Informasi Tagihan & PO">
                <div class="tw-grid tw-grid-cols-2 sm:tw-grid-cols-3 tw-gap-4 tw-text-ui-xs">
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">Nomor Invoice:</span>
                        <strong class="tw-text-ui-sm tw-text-on-surface">{{ $invoice->invoice_number }}</strong>
                    </div>
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">Tanggal Invoice:</span>
                        <strong class="tw-text-ui-sm tw-text-on-surface">{{ $invoice->invoice_date?->format('d M Y') ?? '—' }}</strong>
                    </div>
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">Status Tagihan:</span>
                        <x-ui.status-chip :tone="\App\Support\StatusHelper::localInvoiceTone($invoice->status)">
                            {{ \App\Support\StatusHelper::localInvoiceLabel($invoice->status) }}
                        </x-ui.status-chip>
                    </div>
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">Nomor PO ({{ $invoice->po_source }}):</span>
                        <strong class="tw-text-ui-sm tw-text-on-surface">{{ $invoice->po_number }}</strong>
                    </div>
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">Referensi GR:</span>
                        <strong class="tw-text-ui-sm tw-text-on-surface">{{ $invoice->gr_reference ?? '—' }}</strong>
                    </div>
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">Jadwal Kirim Fisik:</span>
                        <strong class="tw-text-ui-sm tw-text-on-surface">
                            {{ $invoice->scheduled_physical_delivery_date?->format('d M Y (l)') ?? '—' }}
                            @if($invoice->missed_delivery_count > 0)
                                <span class="tw-text-error">({{ $invoice->missed_delivery_count }}x missed)</span>
                            @endif
                        </strong>
                    </div>
                    @if($invoice->tax_invoice_number)
                        <div>
                            <span class="tw-text-on-surface-variant tw-block">Nomor Faktur Pajak (NSFP):</span>
                            <strong class="tw-text-ui-sm tw-font-mono tw-text-primary">{{ $invoice->tax_invoice_number }}</strong>
                        </div>
                    @endif
                    <div class="tw-col-span-full tw-border-t tw-border-outline-variant tw-pt-3 tw-grid tw-grid-cols-3 tw-gap-4">
                        <div>
                            <span class="tw-text-on-surface-variant tw-block">DPP (Nilai Tagihan):</span>
                            <span class="tw-font-mono tw-font-bold tw-text-ui-sm tw-text-on-surface">
                                Rp {{ number_format($invoice->invoice_amount, 0, ',', '.') }}
                            </span>
                        </div>
                        <div>
                            <span class="tw-text-on-surface-variant tw-block">PPN ({{ $invoice->ppn_scheme ?? '11%' }}):</span>
                            <span class="tw-font-mono tw-font-bold tw-text-ui-sm tw-text-primary">
                                Rp {{ number_format($invoice->tax_amount, 0, ',', '.') }}
                            </span>
                        </div>
                        <div>
                            <span class="tw-text-on-surface-variant tw-block">Total Tagihan:</span>
                            <span class="tw-font-mono tw-font-bold tw-text-ui-base tw-text-success">
                                Rp {{ number_format($invoice->invoice_amount + $invoice->tax_amount, 0, ',', '.') }}
                            </span>
                        </div>
                    </div>
                </div>
            </x-ui.card>

            @if($invoice->local_purchase_order_id)
                <x-ui.card title="Referensi PO & Whole Goods Receipt" description="Referensi ini berasal dari master PO/GR dan tidak dapat diganti dari halaman verifikasi.">
                    <div class="tw-grid tw-grid-cols-2 sm:tw-grid-cols-3 tw-gap-4 tw-text-ui-xs tw-mb-4">
                        <div><span class="tw-text-on-surface-variant tw-block">PO Number</span><strong class="tw-font-mono tw-text-on-surface">{{ $invoice->localPurchaseOrder?->po_number ?? $invoice->po_number }}</strong></div>
                        <div><span class="tw-text-on-surface-variant tw-block">PO Date</span><strong class="tw-text-on-surface">{{ $invoice->localPurchaseOrder?->po_date?->format('d M Y') ?? '—' }}</strong></div>
                        <div><span class="tw-text-on-surface-variant tw-block">PO Amount</span><strong class="tw-font-mono tw-text-on-surface">Rp {{ number_format($invoice->localPurchaseOrder?->total_amount ?? $invoice->po_value_snapshot ?? 0, 2, ',', '.') }}</strong></div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle tw-m-0 tw-text-ui-xs">
                            <thead><tr><th>GR Number</th><th>Date</th><th class="text-end">Whole Amount</th><th>Status</th></tr></thead>
                            <tbody>
                            @forelse($invoice->goodsReceiptHistories->sortBy('id') as $history)
                                <tr>
                                    <td class="tw-font-mono">{{ $history->gr_number_snapshot }}</td>
                                    <td>{{ $history->goodsReceipt?->gr_date?->format('d M Y') ?? '—' }}</td>
                                    <td class="text-end tw-font-mono">Rp {{ number_format($history->gr_amount_snapshot, 2, ',', '.') }}</td>
                                    <td><x-ui.status-chip :tone="$history->state === 'CONSUMED' ? 'success' : ($history->state === 'RELEASED' ? 'neutral' : 'warning')">{{ $history->state }}</x-ui.status-chip></td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="tw-text-center tw-text-on-surface-variant">Belum ada riwayat GR authoritative.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-ui.card>
            @endif

            {{-- Dokumen Upload Supplier --}}
            <x-ui.card
                title="Berkas Dokumen Lampiran (Upload Supplier)"
                description="Dokumen digital resmi yang diunggah oleh rekanan supplier untuk invoice ini."
            >
                @php $latestRev = $invoice->revisions->last(); @endphp
                @include('local-invoices.partials._documents_grid', [
                    'documents' => $latestRev ? $latestRev->documents : collect()
                ])
            </x-ui.card>

            {{-- Step 1: Cashier Receipt Section --}}
            @if($invoice->status === \App\Models\LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT)
                <x-ui.card
                    title="Penerimaan Berkas Fisik (Kasir)"
                    description="Catat saat berkas invoice fisik diterima oleh loket kasir/finance. Tanggal jatuh tempo akan dihitung otomatis saat ini."
                >
                    <form method="POST" action="{{ route('finance.invoices.receive-physical', $invoice) }}">
                        @csrf
                        <div class="tw-space-y-3">
                            <div>
                                <label for="cashier-notes" class="form-label tw-text-ui-xs tw-font-semibold">Catatan Loket / Kasir (Opsional)</label>
                                <input type="text" name="notes" id="cashier-notes" class="form-control form-control-sm" placeholder="Contoh: Diterima lengkap beserta materai 10.000">
                            </div>
                            <x-ui.button type="submit" variant="primary" size="sm">
                                <x-ui.icon name="inbox" size="sm" />
                                <span>Konfirmasi Terima Berkas Fisik Sekarang</span>
                            </x-ui.button>
                        </div>
                    </form>
                </x-ui.card>
            @endif

            {{-- Step 2: SECTION A — Document & Reference Checklist --}}
            @if(in_array($invoice->status, [\App\Models\LocalInvoice::STATUS_UNDER_VERIFICATION, \App\Models\LocalInvoice::STATUS_READY_TO_PAY, \App\Models\LocalInvoice::STATUS_PAID]))
                <x-ui.card
                    id="section-a"
                    class="tw-scroll-mt-20"
                    title="Section A: Pemeriksaan Dokumen & Referensi"
                    description="Periksa kelengkapan berkas fisik Invoice, Faktur Pajak, PO, Surat Jalan, dan GR."
                >
                    <x-slot:actions>
                        @if($isLocked)
                            <x-ui.status-chip tone="neutral">
                                <x-ui.icon name="lock" size="xs" />
                                <span>Terkunci (Read-Only)</span>
                            </x-ui.status-chip>
                        @elseif($verification?->is_section_a_passed)
                            <x-ui.status-chip tone="success">
                                <x-ui.icon name="check" size="xs" />
                                <span>Lolos (Passed)</span>
                            </x-ui.status-chip>
                        @endif
                    </x-slot:actions>

                    @if($isLocked)
                        <div class="tw-mb-4 tw-p-3 tw-rounded-lg tw-bg-surface-container tw-border tw-border-outline-variant tw-flex tw-items-center tw-gap-2.5 tw-text-ui-xs tw-text-on-surface-variant">
                            <x-ui.icon name="lock" size="sm" class="tw-text-primary tw-shrink-0" />
                            <span>
                                Section A telah terkunci dan disetujui
                                @if($verification?->verifier)
                                    oleh <strong class="tw-text-on-surface">{{ $verification->verifier->name }}</strong>
                                @endif
                                @if($verification?->verified_at)
                                    pada <span class="tw-font-medium tw-text-on-surface">{{ $verification->verified_at->format('d M Y H:i') }}</span>
                                @endif
                                . Seluruh data pemeriksaan fisik dan referensi bersifat <em>read-only</em>.
                            </span>
                        </div>
                    @endif

                    <form id="form-verify-section-a" method="POST" action="{{ route('finance.invoices.verify-section-a', $invoice) }}">
                        @csrf
                        <fieldset @disabled($isLocked) class="tw-m-0 tw-p-0 tw-border-0 tw-space-y-4">
                            {{-- 1. Invoice Check --}}
                            <div class="tw-p-3 tw-rounded tw-bg-surface-container tw-border tw-border-outline-variant">
                                <div class="tw-flex tw-items-center tw-justify-between tw-mb-2">
                                    <span class="tw-font-semibold tw-text-ui-xs">1. Berkas Fisik Invoice & Materai</span>
                                    <div class="tw-flex tw-gap-3">
                                        <label class="tw-inline-flex tw-items-center tw-gap-1 tw-text-ui-xs">
                                            <input type="radio" name="invoice_check" value="OK" @checked(($verification->invoice_check ?? '') === 'OK') required> OK
                                        </label>
                                        <label class="tw-inline-flex tw-items-center tw-gap-1 tw-text-ui-xs">
                                            <input type="radio" name="invoice_check" value="NOT_OK" @checked(($verification->invoice_check ?? '') === 'NOT_OK')> Not OK
                                        </label>
                                    </div>
                                </div>
                                <input type="text" name="invoice_notes" class="form-control form-control-sm" placeholder="Catatan bila Not OK (Wajib jika bermasalah)" value="{{ $verification->invoice_notes ?? '' }}">
                            </div>

                            {{-- 2. Faktur Pajak Check --}}
                            <div class="tw-p-3 tw-rounded tw-bg-surface-container tw-border tw-border-outline-variant">
                                <div class="tw-flex tw-items-center tw-justify-between tw-mb-2">
                                    <div>
                                        <span class="tw-font-semibold tw-text-ui-xs tw-block">2. Faktur Pajak (Wajib jika PKP)</span>
                                        @if($invoice->tax_invoice_number)
                                            <span class="tw-text-[11px] tw-font-mono tw-text-primary tw-block tw-mt-0.5">
                                                NSFP: {{ $invoice->tax_invoice_number }}
                                            </span>
                                        @endif
                                    </div>
                                    <div class="tw-flex tw-gap-3">
                                        <label class="tw-inline-flex tw-items-center tw-gap-1 tw-text-ui-xs">
                                            <input type="radio" name="tax_invoice_check" value="OK" @checked(($verification->tax_invoice_check ?? '') === 'OK') required> OK
                                        </label>
                                        <label class="tw-inline-flex tw-items-center tw-gap-1 tw-text-ui-xs">
                                            <input type="radio" name="tax_invoice_check" value="NOT_OK" @checked(($verification->tax_invoice_check ?? '') === 'NOT_OK')> Not OK
                                        </label>
                                        <label class="tw-inline-flex tw-items-center tw-gap-1 tw-text-ui-xs">
                                            <input type="radio" name="tax_invoice_check" value="NOT_APPLICABLE" @checked(($verification->tax_invoice_check ?? '') === 'NOT_APPLICABLE' || !$invoice->supplier->supplier?->is_pkp)> N/A (Non-PKP)
                                        </label>
                                    </div>
                                </div>
                                <input type="text" name="tax_invoice_notes" class="form-control form-control-sm" placeholder="Catatan faktur pajak" value="{{ $verification->tax_invoice_notes ?? '' }}">
                            </div>

                            {{-- 3. PO Check --}}
                            <div class="tw-p-3 tw-rounded tw-bg-surface-container tw-border tw-border-outline-variant">
                                <div class="tw-flex tw-items-center tw-justify-between tw-mb-2">
                                    <span class="tw-font-semibold tw-text-ui-xs">3. Kesesuaian Purchase Order (PO)</span>
                                    <div class="tw-flex tw-gap-3">
                                        <label class="tw-inline-flex tw-items-center tw-gap-1 tw-text-ui-xs">
                                            <input type="radio" name="po_check" value="OK" @checked(($verification->po_check ?? '') === 'OK') required> OK
                                        </label>
                                        <label class="tw-inline-flex tw-items-center tw-gap-1 tw-text-ui-xs">
                                            <input type="radio" name="po_check" value="NOT_OK" @checked(($verification->po_check ?? '') === 'NOT_OK')> Not OK
                                        </label>
                                    </div>
                                </div>
                                <input type="text" name="po_notes" class="form-control form-control-sm" placeholder="Catatan PO" value="{{ $verification->po_notes ?? '' }}">
                            </div>

                            {{-- 4. Surat Jalan Check --}}
                            <div class="tw-p-3 tw-rounded tw-bg-surface-container tw-border tw-border-outline-variant">
                                <div class="tw-flex tw-items-center tw-justify-between tw-mb-2">
                                    <span class="tw-font-semibold tw-text-ui-xs">4. Surat Jalan / Delivery Note (Wajib jika Kategori Barang)</span>
                                    <div class="tw-flex tw-gap-3">
                                        <label class="tw-inline-flex tw-items-center tw-gap-1 tw-text-ui-xs">
                                            <input type="radio" name="delivery_note_check" value="OK" @checked(($verification->delivery_note_check ?? '') === 'OK') required> OK
                                        </label>
                                        <label class="tw-inline-flex tw-items-center tw-gap-1 tw-text-ui-xs">
                                            <input type="radio" name="delivery_note_check" value="NOT_OK" @checked(($verification->delivery_note_check ?? '') === 'NOT_OK')> Not OK
                                        </label>
                                        <label class="tw-inline-flex tw-items-center tw-gap-1 tw-text-ui-xs">
                                            <input type="radio" name="delivery_note_check" value="NOT_APPLICABLE" @checked(($verification->delivery_note_check ?? '') === 'NOT_APPLICABLE' || $invoice->supplier->supplier?->vendor_category === 'Jasa')> N/A (Jasa)
                                        </label>
                                    </div>
                                </div>
                                <input type="text" name="delivery_note_notes" class="form-control form-control-sm" placeholder="Catatan Surat Jalan" value="{{ $verification->delivery_note_notes ?? '' }}">
                            </div>

                            {{-- 5. GR Check --}}
                            <div class="tw-p-3 tw-rounded tw-bg-surface-container tw-border tw-border-outline-variant">
                                <div class="tw-flex tw-items-center tw-justify-between tw-mb-2">
                                    <span class="tw-font-semibold tw-text-ui-xs">5. Konfirmasi Good Receipt (GR)</span>
                                    <div class="tw-flex tw-gap-3">
                                        <label class="tw-inline-flex tw-items-center tw-gap-1 tw-text-ui-xs">
                                            <input type="radio" name="gr_check" value="OK" @checked(($verification->gr_check ?? '') === 'OK') required> OK
                                        </label>
                                        <label class="tw-inline-flex tw-items-center tw-gap-1 tw-text-ui-xs">
                                            <input type="radio" name="gr_check" value="NOT_OK" @checked(($verification->gr_check ?? '') === 'NOT_OK')> Not OK
                                        </label>
                                    </div>
                                </div>
                                <input type="text" name="gr_notes" class="form-control form-control-sm" placeholder="Catatan GR" value="{{ $verification->gr_notes ?? '' }}">
                            </div>

                            @if(!$isLocked)
                                <div class="tw-flex tw-justify-end">
                                    <x-ui.button id="btn-submit-section-a" type="submit" variant="primary" size="sm">
                                        <x-ui.icon name="save" size="sm" />
                                        <span>Simpan Section A</span>
                                    </x-ui.button>
                                </div>
                            @endif
                        </fieldset>
                    </form>
                </x-ui.card>

                {{-- Step 3: SECTION B — Tax Verification --}}
                <x-ui.card
                    id="section-b"
                    class="tw-scroll-mt-20"
                    title="Section B: Verifikasi Perpajakan"
                    description="Validasi PPN dan tentukan tarif PPh 23 / PPh 4(2) / PPh 21."
                >
                    <x-slot:actions>
                        @if($isLocked)
                            <x-ui.status-chip tone="neutral">
                                <x-ui.icon name="lock" size="xs" />
                                <span>Terkunci (Read-Only)</span>
                            </x-ui.status-chip>
                        @elseif($verification?->is_section_b_passed)
                            <x-ui.status-chip tone="success">
                                <x-ui.icon name="check" size="xs" />
                                <span>Lolos (Passed)</span>
                            </x-ui.status-chip>
                        @endif
                    </x-slot:actions>

                    @if($isLocked)
                        <div class="tw-mb-4 tw-p-3 tw-rounded-lg tw-bg-surface-container tw-border tw-border-outline-variant tw-flex tw-items-center tw-gap-2.5 tw-text-ui-xs tw-text-on-surface-variant">
                            <x-ui.icon name="lock" size="sm" class="tw-text-primary tw-shrink-0" />
                            <span>
                                Section B telah diverifikasi dan dikunci. Perhitungan dasar pengenaan pajak, PPN, dan pemotongan PPh bersifat final.
                            </span>
                        </div>
                    @endif

                    <form id="form-verify-section-b" method="POST" action="{{ route('finance.invoices.verify-section-b', $invoice) }}">
                        @csrf
                        <fieldset @disabled($isLocked) class="tw-m-0 tw-p-0 tw-border-0 tw-space-y-4">
                            @if($invoice->tax_invoice_number)
                                <div class="tw-p-2.5 tw-rounded tw-bg-surface-container tw-border tw-border-outline-variant tw-flex tw-items-center tw-justify-between">
                                    <span class="tw-text-ui-xs tw-text-on-surface-variant">Nomor Faktur Pajak (NSFP) Supplier:</span>
                                    <span class="tw-font-mono tw-font-bold tw-text-ui-xs tw-text-primary">{{ $invoice->tax_invoice_number }}</span>
                                </div>
                            @endif
                            <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-2 tw-gap-4">
                                <div>
                                    <label class="form-label tw-text-ui-xs tw-font-semibold">Kesesuaian PPN</label>
                                    <select name="ppn_status" class="form-select form-select-sm" required>
                                        <option value="SESUAI" @selected(($verification->ppn_status ?? 'SESUAI') === 'SESUAI')>Sesuai Pengajuan</option>
                                        <option value="TIDAK_SESUAI" @selected(($verification->ppn_status ?? '') === 'TIDAK_SESUAI')>Ada Koreksi / Tidak Sesuai</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="form-label tw-text-ui-xs tw-font-semibold">PPN Hasil Verifikasi (Rp)</label>
                                    <input type="number" step="0.01" name="verified_ppn" class="form-control form-control-sm" value="{{ $verification->verified_ppn ?? $invoice->tax_amount }}">
                                </div>
                            </div>

                            <div class="tw-border-t tw-border-outline-variant tw-pt-3">
                                <span class="tw-text-ui-xs tw-font-bold tw-text-on-surface tw-block tw-mb-2">Pemotongan PPh 23 (Jika Ada)</span>
                                <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-4 tw-gap-3">
                                    <div class="tw-flex tw-items-center tw-gap-2">
                                        <input type="checkbox" name="pph_23_applicable" id="pph23-app" value="1" @checked($verification->pph_23_applicable ?? false)>
                                        <label for="pph23-app" class="tw-text-ui-xs">Potong PPh 23</label>
                                    </div>
                                    <div>
                                        <label for="pph23-base" class="form-label tw-text-[11px]">DPP PPh 23</label>
                                        <input type="number" step="0.01" id="pph23-base" name="pph_23_base" class="form-control form-control-sm" value="{{ $verification->pph_23_base ?? $invoice->invoice_amount }}">
                                    </div>
                                    <div>
                                        <label for="pph23-rate" class="form-label tw-text-[11px]">Tarif PPh 23 (%)</label>
                                        <input type="number" step="0.01" id="pph23-rate" name="pph_23_rate" class="form-control form-control-sm" value="{{ $verification->pph_23_rate ?? 2 }}">
                                    </div>
                                    <div>
                                        <label for="pph23-amount" class="form-label tw-text-[11px]">Nominal PPh 23 (Rp)</label>
                                        <input type="number" step="0.01" id="pph23-amount" name="pph_23_amount" class="form-control form-control-sm" value="{{ $verification->pph_23_amount ?? 0 }}">
                                        <span id="pph23-calc-hint" class="tw-text-[11px] tw-text-primary tw-font-mono tw-mt-1 tw-block"></span>
                                    </div>
                                </div>
                            </div>

                            <div class="tw-border-t tw-border-outline-variant tw-pt-3">
                                <span class="tw-text-ui-xs tw-font-bold tw-text-on-surface tw-block tw-mb-2">Pemotongan PPh Lainnya</span>
                                <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-2 tw-gap-4">
                                    <div class="tw-flex tw-items-center tw-gap-3">
                                        <input type="checkbox" name="pph_4_2_applicable" id="pph42-app" value="1" @checked($verification->pph_4_2_applicable ?? false)>
                                        <label for="pph42-app" class="tw-text-ui-xs tw-shrink-0">PPh 4(2)</label>
                                        <input type="number" step="0.01" name="pph_4_2_amount" class="form-control form-control-sm" placeholder="Nominal Rp" value="{{ $verification->pph_4_2_amount ?? 0 }}">
                                    </div>
                                    <div class="tw-flex tw-items-center tw-gap-3">
                                        <input type="checkbox" name="pph_21_applicable" id="pph21-app" value="1" @checked($verification->pph_21_applicable ?? false)>
                                        <label for="pph21-app" class="tw-text-ui-xs tw-shrink-0">PPh 21</label>
                                        <input type="number" step="0.01" name="pph_21_amount" class="form-control form-control-sm" placeholder="Nominal Rp" value="{{ $verification->pph_21_amount ?? 0 }}">
                                    </div>
                                </div>
                            </div>

                            <div>
                                <label class="form-label tw-text-ui-xs tw-font-semibold">Catatan Pajak</label>
                                <textarea name="tax_notes" class="form-control form-control-sm" rows="2" placeholder="Keterangan dasar pengenaan pajak / tarif khusus...">{{ $verification->tax_notes ?? '' }}</textarea>
                            </div>

                            @if(!$isLocked)
                                <div class="tw-flex tw-justify-end">
                                    <x-ui.button id="btn-submit-section-b" type="submit" variant="primary" size="sm">
                                        <x-ui.icon name="save" size="sm" />
                                        <span>Simpan Section B</span>
                                    </x-ui.button>
                                </div>
                            @endif
                        </fieldset>
                    </form>
                </x-ui.card>
            @endif
        </div>

        {{-- Right Column: Payment, Status Timeline & Final Actions --}}
        <div class="lg:tw-col-span-4 tw-space-y-6">
            {{-- Payment Schedule & Term Card --}}
            <x-ui.card title="Jadwal Pembayaran Kasir">
                <div class="tw-space-y-3 tw-text-ui-xs">
                    <div class="tw-flex tw-justify-between">
                        <span class="tw-text-on-surface-variant">Tgl Terima Kasir:</span>
                        <strong class="tw-text-on-surface">{{ $invoice->cashier_received_at?->format('d M Y H:i') ?? 'Belum Diterima' }}</strong>
                    </div>
                    <div class="tw-flex tw-justify-between">
                        <span class="tw-text-on-surface-variant">Payment Term:</span>
                        <strong class="tw-text-on-surface">Net {{ $invoice->payment_term_days_snapshot ?? 30 }} Hari</strong>
                    </div>
                    <div class="tw-flex tw-justify-between">
                        <span class="tw-text-on-surface-variant">Jatuh Tempo:</span>
                        <strong class="tw-text-on-surface tw-text-ui-sm {{ $invoice->isOverdue() ? 'tw-text-error' : 'tw-text-primary' }}">
                            {{ $invoice->due_date?->format('d M Y') ?? 'Menunggu Kasir' }}
                        </strong>
                    </div>
                    @if($invoice->due_date)
                        <div class="tw-p-2 tw-rounded tw-text-center {{ $invoice->isOverdue() ? 'tw-bg-error/10 tw-text-error' : 'tw-bg-success/10 tw-text-success' }}">
                            <strong>{{ $invoice->remainingDays() < 0 ? 'Overdue '.abs($invoice->remainingDays()).' hari' : $invoice->remainingDays().' hari tersisa' }}</strong>
                        </div>
                    @endif
                </div>
            </x-ui.card>

            {{-- Bank Account Snapshot / Destination --}}
            <x-ui.card title="Rekening Pembayaran Supplier">
                @php $bank = $invoice->supplier->supplier?->activeBankAccount; @endphp
                <div class="tw-space-y-2 tw-text-ui-xs">
                    @if($bank)
                        <div class="tw-flex tw-justify-between">
                            <span class="tw-text-on-surface-variant">Bank:</span>
                            <strong class="tw-text-on-surface">{{ $bank->bank_name }}</strong>
                        </div>
                        <div class="tw-flex tw-justify-between">
                            <span class="tw-text-on-surface-variant">Nomor Rekening:</span>
                            <strong class="tw-font-mono tw-text-on-surface">{{ $bank->account_number }}</strong>
                        </div>
                        <div class="tw-flex tw-justify-between">
                            <span class="tw-text-on-surface-variant">Atas Nama:</span>
                            <strong class="tw-text-on-surface">{{ $bank->account_holder_name }}</strong>
                        </div>
                    @else
                        <div class="tw-text-error">Supplier belum memiliki rekening bank terverifikasi!</div>
                    @endif
                </div>
            </x-ui.card>

            @if($invoice->voucher)
                <x-ui.card title="Voucher Bayar & Settlement" description="Voucher memakai snapshot final; transfer koreksi tetap tercatat pada settlement yang sama.">
                    <div class="tw-flex tw-items-center tw-justify-between tw-gap-3 tw-text-ui-xs">
                        <div><span class="tw-text-on-surface-variant tw-block">Voucher</span><strong class="tw-font-mono tw-text-primary">{{ $invoice->voucher->voucher_number }}</strong></div>
                        <x-ui.button :href="route('finance.vouchers.show', $invoice->voucher)" variant="outline" size="sm">Buka Voucher</x-ui.button>
                    </div>
                    @if($invoice->voucher->payment)
                        <div class="tw-mt-3 tw-border-t tw-border-outline-variant tw-pt-3 tw-text-ui-xs">
                            <div class="tw-flex tw-justify-between"><span class="tw-text-on-surface-variant">Settlement</span><x-ui.status-chip :tone="\App\Support\StatusHelper::localFinanceTone($invoice->voucher->payment->status)">{{ $invoice->voucher->payment->status }}</x-ui.status-chip></div>
                            <div class="tw-mt-1 tw-flex tw-justify-between"><span class="tw-text-on-surface-variant">Expected / Actual</span><span class="tw-font-mono">Rp {{ number_format($invoice->voucher->payment->expected_amount, 2, ',', '.') }} / Rp {{ number_format($invoice->voucher->payment->actual_paid_total, 2, ',', '.') }}</span></div>
                        </div>
                    @endif
                </x-ui.card>
            @endif

            {{-- Final Approval Actions --}}
            @if(!$isLocked && $invoice->status === \App\Models\LocalInvoice::STATUS_UNDER_VERIFICATION)
                <x-ui.card title="Aksi Persetujuan Final">
                    <div class="tw-space-y-3">
                        <form method="POST" action="{{ route('finance.invoices.approve-ready-to-pay', $invoice) }}">
                            @csrf
                            <x-ui.button
                                id="btn-approve-ready-to-pay"
                                type="submit"
                                variant="primary"
                                size="sm"
                                class="w-100"
                                :disabled="!$verification || !$verification->is_section_a_passed || !$verification->is_section_b_passed"
                            >
                                <x-ui.icon name="check-circle" size="sm" />
                                <span>Kunci & Setujui Ready to Pay</span>
                            </x-ui.button>
                        </form>

                        <button
                            type="button"
                            class="btn btn-outline-danger btn-sm w-100"
                            data-bs-toggle="modal"
                            data-bs-target="#revisionModal"
                        >
                            <x-ui.icon name="rotate-ccw" size="sm" />
                            <span>Minta Revisi Dokumen</span>
                        </button>
                    </div>
                </x-ui.card>
            @endif

            @if(in_array($invoice->status, [\App\Models\LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT, \App\Models\LocalInvoice::STATUS_UNDER_VERIFICATION, \App\Models\LocalInvoice::STATUS_NEED_REVISION], true))
                <x-ui.card title="Tolak Invoice" description="Penolakan melepaskan reservasi GR authoritative dan menyimpan alasan pada riwayat.">
                    <button type="button" class="btn btn-outline-danger btn-sm w-100" data-bs-toggle="modal" data-bs-target="#rejectModal">
                        <x-ui.icon name="x-circle" size="sm" /> Tolak Invoice
                    </button>
                </x-ui.card>
            @endif

            {{-- Status Timeline --}}
            <x-ui.card title="Jejak Aktivitas">
                <div class="tw-space-y-3 tw-text-ui-xs">
                    @forelse($invoice->statusHistories as $hist)
                        <div class="tw-p-2 tw-rounded tw-bg-surface-container">
                            <div class="tw-flex tw-justify-between">
                                <strong>{{ ucwords(str_replace('_', ' ', $hist->event)) }}</strong>
                                <span class="tw-text-on-surface-variant">{{ $hist->created_at->format('d M H:i') }}</span>
                            </div>
                            <div class="tw-text-on-surface-variant">Oleh: {{ $hist->actor?->name ?? 'System' }}</div>
                            @if($hist->notes)
                                <div class="tw-mt-1 tw-text-[11px] tw-italic">{{ $hist->notes }}</div>
                            @endif
                        </div>
                    @empty
                        <div class="tw-text-on-surface-variant">Belum ada jejak status.</div>
                    @endforelse
                </div>
            </x-ui.card>
        </div>
    </div>
</div>

{{-- Request Revision Modal --}}
<div class="modal fade" id="revisionModal" tabindex="-1" aria-labelledby="revisionModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('finance.invoices.request-revision', $invoice) }}">
            @csrf
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title tw-text-ui-sm tw-font-bold" id="revisionModalLabel">Minta Revisi Tagihan Invoice</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="tw-text-ui-xs tw-text-on-surface-variant tw-mb-3">
                        Tagihan akan dikembalikan ke Supplier untuk diperbaiki. Notifikasi email akan otomatis dikirimkan beserta alasan di bawah ini.
                    </p>
                    <div class="mb-3">
                        <label for="rev-notes" class="form-label tw-text-ui-xs tw-font-semibold">Alasan Revisi (Wajib) <span class="text-danger">*</span></label>
                        <textarea name="notes" id="rev-notes" class="form-control form-control-sm" rows="3" required placeholder="Contoh: Lampirkan faktur pajak pengganti, nomor PO tidak cocok..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-danger btn-sm">Kirim Permintaan Revisi</button>
                </div>
            </div>
        </form>
    </div>
</div>

@if(in_array($invoice->status, [\App\Models\LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT, \App\Models\LocalInvoice::STATUS_UNDER_VERIFICATION, \App\Models\LocalInvoice::STATUS_NEED_REVISION], true))
<div class="modal fade" id="rejectModal" tabindex="-1" aria-labelledby="rejectModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('finance.invoices.reject', $invoice) }}">
            @csrf
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title tw-text-ui-sm tw-font-bold" id="rejectModalLabel">Tolak Invoice</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                <div class="modal-body"><label for="reject-notes" class="form-label tw-text-ui-xs tw-font-semibold">Alasan Penolakan <span class="text-danger">*</span></label><textarea name="notes" id="reject-notes" class="form-control form-control-sm" rows="3" maxlength="2000" required></textarea></div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button><button type="submit" class="btn btn-danger btn-sm">Tolak & Lepaskan GR</button></div>
            </div>
        </form>
    </div>
</div>
@endif
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    // 1. Restore scroll position if previously stored before full page reload/redirect
    const savedScroll = sessionStorage.getItem('finance_invoice_verify_scroll');
    if (savedScroll !== null) {
        sessionStorage.removeItem('finance_invoice_verify_scroll');
        window.scrollTo({
            top: parseInt(savedScroll, 10),
            behavior: 'instant'
        });
    } else if (window.location.hash) {
        const target = document.querySelector(window.location.hash);
        if (target) {
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    // Helper to toggle button loading state
    function setBtnLoading(btn, isLoading, loadingText) {
        if (!btn) return;
        if (isLoading) {
            btn.dataset.originalHtml = btn.innerHTML;
            btn.disabled = true;
            btn.classList.add('disabled', 'tw-opacity-75');
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1.5" role="status" aria-hidden="true"></span><span>' + loadingText + '</span>';
        } else {
            if (btn.dataset.originalHtml) {
                btn.innerHTML = btn.dataset.originalHtml;
            }
            btn.disabled = false;
            btn.classList.remove('disabled', 'tw-opacity-75');
        }
    }

    @if(!$isLocked)
    // Helper to update approval button state
    function updateApprovalButton(canApprove) {
        const approveBtn = document.getElementById('btn-approve-ready-to-pay');
        if (!approveBtn) return;
        if (canApprove) {
            approveBtn.removeAttribute('disabled');
            approveBtn.disabled = false;
            approveBtn.classList.remove('disabled', 'tw-cursor-not-allowed', 'tw-opacity-50');
        } else {
            approveBtn.setAttribute('disabled', 'disabled');
            approveBtn.disabled = true;
            approveBtn.classList.add('disabled', 'tw-cursor-not-allowed', 'tw-opacity-50');
        }
    }
    // 2. AJAX Submission for Section A
    const formSectionA = document.getElementById('form-verify-section-a');
    if (formSectionA) {
        formSectionA.addEventListener('submit', async function (e) {
            e.preventDefault();
            const submitBtn = document.getElementById('btn-submit-section-a') || formSectionA.querySelector('button[type="submit"]');
            setBtnLoading(submitBtn, true, 'Menyimpan Section A...');

            try {
                const response = await fetch(formSectionA.action, {
                    method: 'POST',
                    body: new FormData(formSectionA),
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    }
                });

                const data = await response.json();

                if (response.ok && data.success) {
                    if (window.AdasiToast) {
                        window.AdasiToast.success(data.message || 'Section A berhasil disimpan.');
                    }
                    updateApprovalButton(data.can_approve);

                    // Smoothly guide user to Section B if section A passed
                    if (data.is_section_a_passed) {
                        const secB = document.getElementById('section-b');
                        if (secB) {
                            secB.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        }
                    }
                } else {
                    let errMsg = data.message || 'Terjadi kesalahan validasi saat menyimpan Section A.';
                    if (data.errors) {
                        const firstKey = Object.keys(data.errors)[0];
                        if (firstKey && data.errors[firstKey][0]) {
                            errMsg = data.errors[firstKey][0];
                        }
                    }
                    if (window.AdasiToast) {
                        window.AdasiToast.error(errMsg);
                    } else {
                        alert(errMsg);
                    }
                }
            } catch (err) {
                console.error('Section A save error:', err);
                sessionStorage.setItem('finance_invoice_verify_scroll', window.scrollY.toString());
                formSectionA.submit();
                return;
            } finally {
                setBtnLoading(submitBtn, false);
            }
        });
    }

    // 3. AJAX Submission for Section B
    const formSectionB = document.getElementById('form-verify-section-b');
    if (formSectionB) {
        formSectionB.addEventListener('submit', async function (e) {
            e.preventDefault();
            const submitBtn = document.getElementById('btn-submit-section-b') || formSectionB.querySelector('button[type="submit"]');
            setBtnLoading(submitBtn, true, 'Menyimpan Section B...');

            try {
                const response = await fetch(formSectionB.action, {
                    method: 'POST',
                    body: new FormData(formSectionB),
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    }
                });

                const data = await response.json();

                if (response.ok && data.success) {
                    if (window.AdasiToast) {
                        window.AdasiToast.success(data.message || 'Section B berhasil disimpan.');
                    }
                    updateApprovalButton(data.can_approve);
                } else {
                    let errMsg = data.message || 'Terjadi kesalahan validasi saat menyimpan Section B.';
                    if (data.errors) {
                        const firstKey = Object.keys(data.errors)[0];
                        if (firstKey && data.errors[firstKey][0]) {
                            errMsg = data.errors[firstKey][0];
                        }
                    }
                    if (window.AdasiToast) {
                        window.AdasiToast.error(errMsg);
                    } else {
                        alert(errMsg);
                    }
                }
            } catch (err) {
                console.error('Section B save error:', err);
                sessionStorage.setItem('finance_invoice_verify_scroll', window.scrollY.toString());
                formSectionB.submit();
                return;
            } finally {
                setBtnLoading(submitBtn, false);
            }
        });
    }
    @endif

    // 4. Auto-calculation for PPh 23
    const pph23App = document.getElementById('pph23-app');
    const pph23Base = document.getElementById('pph23-base');
    const pph23Rate = document.getElementById('pph23-rate');
    const pph23Amount = document.getElementById('pph23-amount');
    const pph23Hint = document.getElementById('pph23-calc-hint');

    if (pph23App && pph23Base && pph23Rate && pph23Amount) {
        function formatRupiah(num) {
            return new Intl.NumberFormat('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(num);
        }

        @if(!$isLocked)
        function calculatePph23() {
            if (!pph23App.checked) {
                pph23Amount.value = '0.00';
                if (pph23Hint) pph23Hint.textContent = '';
                return;
            }

            const base = parseFloat(pph23Base.value) || 0;
            const rate = parseFloat(pph23Rate.value) || 0;
            const calculated = Math.round(base * (rate / 100) * 100) / 100;

            pph23Amount.value = calculated.toFixed(2);
        }

        pph23App.addEventListener('change', function () {
            if (pph23App.checked) {
                if (!parseFloat(pph23Base.value)) {
                    pph23Base.value = '{{ (float) $invoice->invoice_amount }}';
                }
                if (!parseFloat(pph23Rate.value)) {
                    pph23Rate.value = '2';
                }
            }
            calculatePph23();
        });

        pph23Base.addEventListener('input', calculatePph23);
        pph23Rate.addEventListener('input', calculatePph23);

        // Initial setup on load if checked
        if (pph23App.checked) {
            const currentAmt = parseFloat(pph23Amount.value) || 0;
            if (currentAmt === 0) {
                calculatePph23();
            } else {
                const base = parseFloat(pph23Base.value) || 0;
                const rate = parseFloat(pph23Rate.value) || 0;
                if (pph23Hint && base > 0 && rate > 0) {
                    pph23Hint.textContent = `Otomatis: ${rate}% × Rp ${formatRupiah(base)} = Rp ${formatRupiah(currentAmt)}`;
                }
            }
        }
        @else
        // Read-only calculation hint display
        if (pph23App.checked) {
            const currentAmt = parseFloat(pph23Amount.value) || 0;
            const base = parseFloat(pph23Base.value) || 0;
            const rate = parseFloat(pph23Rate.value) || 0;
            if (pph23Hint && base > 0 && rate > 0) {
                pph23Hint.textContent = `Dikenakan: ${rate}% × Rp ${formatRupiah(base)} = Rp ${formatRupiah(currentAmt)}`;
            }
        }
        @endif
    }
});
</script>
@endpush
