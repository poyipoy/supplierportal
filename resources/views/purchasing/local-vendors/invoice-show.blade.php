@extends('layouts.app')
@section('title', 'Audit Invoice '.$invoice->invoice_number.' - Purchasing (Read-Only)')
@section('page-title', 'Detail & Audit Tagihan Invoice (Read-Only)')

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        :title="'Invoice #'.$invoice->invoice_number.' (Read-Only)'"
        :description="'Pengajuan: '.$invoice->submission_number.' — Supplier: '.($invoice->supplier->supplier?->company_name ?: $invoice->supplier->name)"
        eyebrow="Purchasing Audit View"
    >
        <x-slot:actions>
            <x-ui.button :href="route('purchasing.local-vendors.index')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>Kembali ke Master Vendor</span>
            </x-ui.button>
            @if($invoice->receipt)
                <x-ui.button :href="route('finance.invoices.receipt', $invoice)" variant="outline" size="sm" target="_blank">
                    <x-ui.icon name="printer" size="sm" />
                    <span>Lihat Tanda Terima</span>
                </x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if($invoice->has_po_discrepancy)
        <x-ui.alert tone="warning" title="Peringatan Discrepancy PO!">
            Nilai tagihan DPP invoice ini (Rp {{ number_format($invoice->invoice_amount, 0, ',', '.') }}) melebihi sisa nilai PO (Rp {{ number_format($invoice->po_remaining_snapshot ?? 0, 0, ',', '.') }}).
        </x-ui.alert>
    @endif

    <div class="tw-grid tw-grid-cols-1 lg:tw-grid-cols-12 tw-gap-6">
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
                        <span class="tw-text-on-surface-variant tw-block">Tanggal Kirim Fisik:</span>
                        <strong class="tw-text-ui-sm tw-text-on-surface">
                            {{ $invoice->physical_delivery_date?->format('d M Y') ?? '—' }}
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

            {{-- Section A Checklist Results (Read-Only) --}}
            <x-ui.card title="Hasil Verifikasi Section A (Dokumen & Referensi)">
                @if($verification)
                    <div class="tw-space-y-3 tw-text-ui-xs">
                        <div class="tw-flex tw-justify-between tw-p-2 tw-rounded tw-bg-surface-container">
                            <span>1. Berkas Fisik Invoice</span>
                            <strong>{{ $verification->invoice_check }}</strong>
                        </div>
                        <div class="tw-flex tw-justify-between tw-p-2 tw-rounded tw-bg-surface-container">
                            <span>2. Faktur Pajak</span>
                            <strong>{{ $verification->tax_invoice_check }}</strong>
                        </div>
                        <div class="tw-flex tw-justify-between tw-p-2 tw-rounded tw-bg-surface-container">
                            <span>3. Purchase Order (PO)</span>
                            <strong>{{ $verification->po_check }}</strong>
                        </div>
                        <div class="tw-flex tw-justify-between tw-p-2 tw-rounded tw-bg-surface-container">
                            <span>4. Surat Jalan (Delivery Note)</span>
                            <strong>{{ $verification->delivery_note_check }}</strong>
                        </div>
                        <div class="tw-flex tw-justify-between tw-p-2 tw-rounded tw-bg-surface-container">
                            <span>5. Good Receipt (GR)</span>
                            <strong>{{ $verification->gr_check }}</strong>
                        </div>
                    </div>
                @else
                    <div class="tw-text-center tw-py-4 tw-text-ui-xs tw-text-on-surface-variant">
                        Belum ada data verifikasi Section A dari Finance.
                    </div>
                @endif
            </x-ui.card>

            {{-- Section B Tax Results (Read-Only) --}}
            <x-ui.card title="Hasil Verifikasi Section B (Perpajakan)">
                @if($verification)
                    <div class="tw-space-y-3 tw-text-ui-xs">
                        <div class="tw-flex tw-justify-between tw-p-2 tw-rounded tw-bg-surface-container">
                            <span>Status PPN:</span>
                            <strong>{{ $verification->ppn_status }}</strong>
                        </div>
                        <div class="tw-flex tw-justify-between tw-p-2 tw-rounded tw-bg-surface-container">
                            <span>PPN Terverifikasi:</span>
                            <strong class="tw-font-mono">Rp {{ number_format($verification->verified_ppn ?? $invoice->tax_amount, 0, ',', '.') }}</strong>
                        </div>
                        <div class="tw-flex tw-justify-between tw-p-2 tw-rounded tw-bg-surface-container">
                            <span>Pemotongan PPh 23:</span>
                            <strong class="tw-font-mono">{{ $verification->pph_23_applicable ? 'Rp '.number_format($verification->pph_23_amount, 0, ',', '.').' (Tarif '.$verification->pph_23_rate.'%)' : 'Tidak Ada' }}</strong>
                        </div>
                        @if($verification->pph_4_2_applicable)
                            <div class="tw-flex tw-justify-between tw-p-2 tw-rounded tw-bg-surface-container">
                                <span>PPh 4(2):</span>
                                <strong class="tw-font-mono">Rp {{ number_format($verification->pph_4_2_amount, 0, ',', '.') }}</strong>
                            </div>
                        @endif
                        @if($verification->pph_21_applicable)
                            <div class="tw-flex tw-justify-between tw-p-2 tw-rounded tw-bg-surface-container">
                                <span>PPh 21:</span>
                                <strong class="tw-font-mono">Rp {{ number_format($verification->pph_21_amount, 0, ',', '.') }}</strong>
                            </div>
                        @endif
                        @if($verification->tax_notes)
                            <div class="tw-p-2 tw-rounded tw-bg-surface-container">
                                <span>Catatan Pajak:</span>
                                <em>{{ $verification->tax_notes }}</em>
                            </div>
                        @endif
                    </div>
                @else
                    <div class="tw-text-center tw-py-4 tw-text-ui-xs tw-text-on-surface-variant">
                        Belum ada data verifikasi Section B dari Finance.
                    </div>
                @endif
            </x-ui.card>
        </div>

        <div class="lg:tw-col-span-4 tw-space-y-6">
            {{-- Jadwal Pembayaran --}}
            <x-ui.card title="Jadwal Pembayaran & Jatuh Tempo">
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

            {{-- Audit Timeline --}}
            <x-ui.card title="Jejak Audit Status">
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
@endsection
