@extends('layouts.guest')
@section('title', 'Verifikasi Tanda Terima - ADASI Portal')

@section('content')
<div class="tw-min-h-screen tw-flex tw-items-center tw-justify-center tw-p-4 tw-bg-surface-dim">
    <div class="tw-max-w-md tw-w-full tw-bg-surface tw-rounded-xl tw-shadow-lg tw-border tw-border-outline-variant tw-overflow-hidden">
        {{-- Header Card --}}
        <div class="tw-bg-primary tw-text-on-primary tw-p-6 tw-text-center">
            <div class="tw-w-12 tw-h-12 tw-mx-auto tw-mb-3 tw-rounded-full tw-bg-white/20 tw-flex tw-items-center tw-justify-center">
                <x-ui.icon name="check-circle" size="lg" class="tw-text-white" />
            </div>
            <h1 class="tw-text-ui-base tw-font-bold tw-m-0">Tanda Terima Sah &amp; Terverifikasi</h1>
            <p class="tw-text-xs tw-text-white/80 tw-m-0 tw-mt-1">PT Astra Daido Steel Indonesia — Portal Supplier</p>
        </div>

        {{-- Body --}}
        <div class="tw-p-6 tw-space-y-4 tw-text-ui-xs">
            <div class="tw-space-y-2">
                <div class="tw-flex tw-justify-between tw-border-b tw-border-outline-variant tw-pb-2">
                    <span class="tw-text-on-surface-variant">No. Tanda Terima:</span>
                    <strong class="tw-font-mono tw-text-primary">{{ $receipt->receipt_number }}</strong>
                </div>
                <div class="tw-flex tw-justify-between tw-border-b tw-border-outline-variant tw-pb-2">
                    <span class="tw-text-on-surface-variant">No. Submission:</span>
                    <strong class="tw-font-mono">{{ $invoice->submission_number }}</strong>
                </div>
                <div class="tw-flex tw-justify-between tw-border-b tw-border-outline-variant tw-pb-2">
                    <span class="tw-text-on-surface-variant">No. Invoice:</span>
                    <strong class="tw-font-mono">{{ $invoice->invoice_number }}</strong>
                </div>
                <div class="tw-flex tw-justify-between tw-border-b tw-border-outline-variant tw-pb-2">
                    <span class="tw-text-on-surface-variant">No. PO:</span>
                    <span class="tw-font-mono">{{ $invoice->po_number }}</span>
                </div>
                <div class="tw-flex tw-justify-between tw-border-b tw-border-outline-variant tw-pb-2">
                    <span class="tw-text-on-surface-variant">Nama Rekanan:</span>
                    <strong>{{ $invoice->supplier->supplier?->company_name ?: $invoice->supplier->name }}</strong>
                </div>
                <div class="tw-flex tw-justify-between tw-border-b tw-border-outline-variant tw-pb-2">
                    <span class="tw-text-on-surface-variant">Total Nilai Tagihan:</span>
                    <strong class="tw-font-mono tw-text-ui-sm">Rp {{ number_format($invoice->invoice_amount, 0, ',', '.') }}</strong>
                </div>
                <div class="tw-flex tw-justify-between tw-border-b tw-border-outline-variant tw-pb-2">
                    <span class="tw-text-on-surface-variant">Waktu Pengajuan:</span>
                    <span>{{ $invoice->submitted_at?->format('d/m/Y H:i') }}</span>
                </div>
                <div class="tw-flex tw-justify-between tw-pt-1">
                    <span class="tw-text-on-surface-variant">Status Sistem:</span>
                    <span class="tw-inline-flex tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-semibold tw-bg-success/10 tw-text-success">
                        Dokumen Tercatat di Sistem
                    </span>
                </div>
            </div>

            <div class="tw-p-3 tw-rounded tw-bg-surface-container tw-text-[11px] tw-text-on-surface-variant">
                Tanda terima ini mengonfirmasi bahwa pengajuan invoice telah dicatat secara digital dalam sistem ADASI. Dokumen ini sah dan ditandatangani secara kriptografis oleh server.
            </div>
        </div>
    </div>
</div>
@endsection
