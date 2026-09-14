@extends('layouts.app')
@section('title', 'Verifikasi Invoice '.$invoice->invoice_number.' - Finance AP')
@section('page-title', 'Detail & Verifikasi Invoice')

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        :title="'Invoice #'.$invoice->invoice_number"
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
            Nilai tagihan DPP invoice ini (Rp {{ number_format($invoice->invoice_amount, 0, ',', '.') }}) melebihi sisa nilai PO (Rp {{ number_format($invoice->po_remaining_amount_snapshot ?? 0, 0, ',', '.') }}). Harap verifikasi lebih teliti sebelum menyetujui.
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
                            {{ $invoice->physical_delivery_date?->format('d M Y (l)') ?? '—' }}
                            @if($invoice->missed_deliveries_count > 0)
                                <span class="tw-text-error">({{ $invoice->missed_deliveries_count }}x missed)</span>
                            @endif
                        </strong>
                    </div>
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

            {{-- Dokumen Upload Supplier --}}
            <x-ui.card title="Berkas Dokumen Pendukung (Upload Supplier)">
                <div class="tw-space-y-3">
                    @php $latestRev = $invoice->revisions->last(); @endphp
                    @if($latestRev && $latestRev->documents->isNotEmpty())
                        <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-2 tw-gap-3">
                            @foreach($latestRev->documents as $doc)
                                <div class="tw-flex tw-items-center tw-justify-between tw-p-3 tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface-container">
                                    <div class="tw-flex tw-items-center tw-gap-2.5 tw-min-w-0">
                                        <x-ui.icon name="file-text" size="md" class="tw-text-primary tw-shrink-0" />
                                        <div class="tw-min-w-0">
                                            <span class="tw-font-semibold tw-text-ui-xs tw-text-on-surface tw-block tw-truncate">
                                                {{ ucwords(str_replace('_', ' ', $doc->document_type)) }}
                                            </span>
                                            <span class="tw-text-[11px] tw-text-on-surface-variant tw-block tw-truncate">
                                                {{ $doc->file_name }}
                                            </span>
                                        </div>
                                    </div>
                                    <a href="{{ Storage::disk('private')->url($doc->file_path) }}" target="_blank" class="tw-shrink-0 btn btn-sm btn-outline-primary tw-text-xs">
                                        Unduh
                                    </a>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="tw-text-center tw-py-4 tw-text-ui-xs tw-text-on-surface-variant">
                            Belum ada dokumen yang diunggah pada revisi aktif ini.
                        </div>
                    @endif
                </div>
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
                                <x-ui.icon name="inbox" size="xs" />
                                <span>Konfirmasi Terima Berkas Fisik Sekarang</span>
                            </x-ui.button>
                        </div>
                    </form>
                </x-ui.card>
            @endif

            {{-- Step 2: SECTION A — Document & Reference Checklist --}}
            @if(in_array($invoice->status, [\App\Models\LocalInvoice::STATUS_UNDER_VERIFICATION, \App\Models\LocalInvoice::STATUS_READY_TO_PAY, \App\Models\LocalInvoice::STATUS_PAID]))
                <x-ui.card
                    title="Section A: Pemeriksaan Dokumen &amp; Referensi"
                    description="Periksa kelengkapan berkas fisik Invoice, Faktur Pajak, PO, Surat Jalan, dan GR."
                >
                    <form method="POST" action="{{ route('finance.invoices.verify-section-a', $invoice) }}">
                        @csrf
                        <div class="tw-space-y-4">
                            {{-- 1. Invoice Check --}}
                            <div class="tw-p-3 tw-rounded tw-bg-surface-container tw-border tw-border-outline-variant">
                                <div class="tw-flex tw-items-center tw-justify-between tw-mb-2">
                                    <span class="tw-font-semibold tw-text-ui-xs">1. Berkas Fisik Invoice &amp; Materai</span>
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
                                    <span class="tw-font-semibold tw-text-ui-xs">2. Faktur Pajak (Wajib jika PKP)</span>
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

                            @if(!$verification?->is_locked)
                                <div class="tw-flex tw-justify-end">
                                    <x-ui.button type="submit" variant="primary" size="sm">
                                        <span>Simpan Section A</span>
                                    </x-ui.button>
                                </div>
                            @endif
                        </div>
                    </form>
                </x-ui.card>

                {{-- Step 3: SECTION B — Tax Verification --}}
                <x-ui.card
                    title="Section B: Verifikasi Perpajakan"
                    description="Validasi PPN dan tentukan tarif PPh 23 / PPh 4(2) / PPh 21."
                >
                    <form method="POST" action="{{ route('finance.invoices.verify-section-b', $invoice) }}">
                        @csrf
                        <div class="tw-space-y-4">
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
                                        <label class="form-label tw-text-[11px]">DPP PPh 23</label>
                                        <input type="number" step="0.01" name="pph_23_base" class="form-control form-control-sm" value="{{ $verification->pph_23_base ?? $invoice->invoice_amount }}">
                                    </div>
                                    <div>
                                        <label class="form-label tw-text-[11px]">Tarif PPh 23 (%)</label>
                                        <input type="number" step="0.01" name="pph_23_rate" class="form-control form-control-sm" value="{{ $verification->pph_23_rate ?? 2 }}">
                                    </div>
                                    <div>
                                        <label class="form-label tw-text-[11px]">Nominal PPh 23 (Rp)</label>
                                        <input type="number" step="0.01" name="pph_23_amount" class="form-control form-control-sm" value="{{ $verification->pph_23_amount ?? 0 }}">
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

                            @if(!$verification?->is_locked)
                                <div class="tw-flex tw-justify-end">
                                    <x-ui.button type="submit" variant="primary" size="sm">
                                        <span>Simpan Section B</span>
                                    </x-ui.button>
                                </div>
                            @endif
                        </div>
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
                            <strong class="tw-text-on-surface">{{ $bank->account_holder }}</strong>
                        </div>
                    @else
                        <div class="tw-text-error">Supplier belum memiliki rekening bank terverifikasi!</div>
                    @endif
                </div>
            </x-ui.card>

            {{-- Final Approval Actions --}}
            @if($invoice->status === \App\Models\LocalInvoice::STATUS_UNDER_VERIFICATION && !$verification?->is_locked)
                <x-ui.card title="Aksi Persetujuan Final">
                    <div class="tw-space-y-3">
                        <form method="POST" action="{{ route('finance.invoices.approve-ready-to-pay', $invoice) }}">
                            @csrf
                            <x-ui.button
                                type="submit"
                                variant="primary"
                                size="sm"
                                class="w-100"
                                :disabled="!$verification || !$verification->is_section_a_passed || !$verification->is_section_b_passed"
                            >
                                <x-ui.icon name="check-circle" size="xs" />
                                <span>Kunci &amp; Setujui Ready to Pay</span>
                            </x-ui.button>
                        </form>

                        <button
                            type="button"
                            class="btn btn-outline-danger btn-sm w-100"
                            data-bs-toggle="modal"
                            data-bs-target="#revisionModal"
                        >
                            <x-ui.icon name="rotate-ccw" size="xs" />
                            <span>Minta Revisi Dokumen</span>
                        </button>
                    </div>
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
@endsection
