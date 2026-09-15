@extends('layouts.app')
@section('title', $invoice->receipt->receipt_number)
@section('page-title', 'Invoice Receipt')
@section('content')
<div class="local-invoice-receipt tw-max-w-2xl tw-mx-auto tw-my-6">
    <x-ui.page-header
        title="Tanda Terima Pengajuan Invoice Lokal"
        :description="$invoice->receipt->receipt_number"
        :breadcrumbs="[
            ['label' => 'Daftar Invoice', 'url' => route('local-supplier.invoices.index')],
            ['label' => $invoice->invoice_number, 'url' => route('local-supplier.invoices.show', $invoice)],
            ['label' => 'Tanda Terima'],
        ]"
    >
        <x-slot:actions>
            <x-ui.button :href="route('local-supplier.invoices.show', $invoice)" variant="outline" size="sm" class="d-print-none">
                <x-ui.icon name="arrow-left" size="xs" />
                <span>Lihat Detail Invoice</span>
            </x-ui.button>
            <x-ui.button type="button" data-print-receipt variant="primary" size="sm" class="d-print-none">
                <x-ui.icon name="printer" size="xs" />
                <span>Cetak Tanda Terima</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if(session('success'))
        <div class="alert alert-success d-flex align-items-center mb-4 d-print-none" role="alert">
            <x-ui.icon name="circle-check" size="sm" class="me-2 tw-text-success tw-shrink-0" />
            <div>
                <strong>Pengajuan Berhasil Tercatat!</strong>
                <div class="tw-text-ui-xs tw-mt-0.5">{{ session('success') }}</div>
            </div>
        </div>
    @endif

    <x-ui.card title="PT Astra Daido Steel Indonesia — Supplier Portal">
        <div class="tw-flex tw-flex-col sm:tw-flex-row tw-gap-6 tw-items-start">
            <dl class="row tw-text-ui-xs tw-flex-1 tw-m-0">
                <dt class="col-sm-4 text-muted">Nomor Tanda Terima</dt>
                <dd class="col-sm-8"><strong class="tw-font-mono tw-text-primary">{{ $invoice->receipt->receipt_number }}</strong></dd>

                <dt class="col-sm-4 text-muted">Nomor Submission</dt>
                <dd class="col-sm-8"><strong class="tw-font-mono">{{ $invoice->submission_number }}</strong></dd>

                <dt class="col-sm-4 text-muted">Nomor Invoice</dt>
                <dd class="col-sm-8"><strong class="tw-font-mono">{{ $invoice->invoice_number }}</strong></dd>

                <dt class="col-sm-4 text-muted">Nomor PO</dt>
                <dd class="col-sm-8"><span class="tw-font-mono">{{ $invoice->po_number }}</span></dd>

                <dt class="col-sm-4 text-muted">Nama Rekanan</dt>
                <dd class="col-sm-8"><strong>{{ $invoice->supplier->supplier?->company_name ?: $invoice->supplier->name }}</strong></dd>

                <dt class="col-sm-4 text-muted">Nilai Tagihan / PPN</dt>
                <dd class="col-sm-8"><strong class="tw-font-mono">Rp {{ number_format($invoice->invoice_amount, 0, ',', '.') }}</strong> (PPN: Rp {{ number_format($invoice->tax_amount, 0, ',', '.') }})</dd>

                <dt class="col-sm-4 text-muted">Waktu Pengajuan</dt>
                <dd class="col-sm-8">{{ $invoice->submitted_at->format('d M Y H:i') }}</dd>

                <dt class="col-sm-4 text-muted">Revisi ke-</dt>
                <dd class="col-sm-8">{{ $invoice->revision_number }}</dd>
            </dl>

            @if(isset($qrCode))
                <div class="tw-text-center tw-shrink-0 tw-p-3 tw-bg-surface-container tw-rounded-lg tw-border tw-border-outline-variant">
                    <img src="{{ $qrCode }}" alt="QR Verifikasi" class="tw-w-28 tw-h-28 tw-mx-auto">
                    <span class="tw-block tw-text-[10px] tw-text-on-surface-variant tw-mt-1.5 tw-font-mono">Scan Verifikasi Sah</span>
                </div>
            @endif
        </div>

        <div class="tw-mt-4 tw-p-3 tw-rounded tw-bg-surface-container tw-text-ui-xs tw-text-on-surface-variant">
            <p class="tw-m-0">
                <strong>Catatan Penting:</strong> Lampirkan lembar tanda terima ini bersama dengan berkas fisik Invoice asli dan Faktur Pajak untuk diserahkan ke Finance ADASI pada hari Rabu jadwal penyerahan. Tanda terima ini mengonfirmasi pengajuan digital tercatat dalam sistem dan bukan merupakan bukti persetujuan atau pelunasan tagihan.
            </p>
        </div>

        <div class="tw-mt-5 tw-pt-4 tw-border-t tw-border-outline-variant tw-flex tw-items-center tw-justify-between d-print-none">
            <x-ui.button :href="route('local-supplier.invoices.show', $invoice)" variant="outline" size="sm">
                <x-ui.icon name="arrow-left" size="xs" />
                <span>Lihat Detail Invoice</span>
            </x-ui.button>
            <x-ui.button type="button" data-print-receipt variant="primary" size="sm">
                <x-ui.icon name="printer" size="xs" />
                <span>Cetak Tanda Terima</span>
            </x-ui.button>
        </div>
    </x-ui.card>
</div>
@include('local-invoices.scripts')
@endsection
