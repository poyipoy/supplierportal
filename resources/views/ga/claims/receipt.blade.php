@extends('layouts.app')
@section('title', 'Tanda Terima GA - '.$claim->receipt?->receipt_number)
@section('page-title', 'Tanda Terima Klaim GA')

@section('content')
<div class="local-invoice-receipt tw-max-w-2xl tw-mx-auto tw-my-6">
    <x-ui.page-header
        title="Tanda Terima Pengajuan Klaim Karyawan (TT-GA)"
        :description="$claim->receipt?->receipt_number"
    >
        <x-slot:actions>
            <x-ui.button type="button" onclick="window.print()" variant="primary" size="sm" class="d-print-none">
                <x-ui.icon name="printer" size="sm" />
                <span>Cetak Tanda Terima</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card title="PT Astra Daido Steel Indonesia — General Affairs">
        <div class="tw-flex tw-flex-col sm:tw-flex-row tw-gap-6 tw-items-start">
            <dl class="row tw-text-ui-xs tw-flex-1 tw-m-0">
                <dt class="col-sm-4 text-muted">Nomor Tanda Terima</dt>
                <dd class="col-sm-8"><strong class="tw-font-mono tw-text-primary">{{ $claim->receipt?->receipt_number }}</strong></dd>

                <dt class="col-sm-4 text-muted">Nomor Klaim GA</dt>
                <dd class="col-sm-8"><strong class="tw-font-mono">{{ $claim->claim_number }}</strong></dd>

                <dt class="col-sm-4 text-muted">Nama Karyawan</dt>
                <dd class="col-sm-8"><strong>{{ $claim->employee?->name }}</strong> ({{ $claim->employee?->department }})</dd>

                <dt class="col-sm-4 text-muted">Tipe Klaim</dt>
                <dd class="col-sm-8">{{ $claim->claim_type }}</dd>

                <dt class="col-sm-4 text-muted">Tanggal Klaim</dt>
                <dd class="col-sm-8">{{ $claim->claim_date?->format('d M Y') }}</dd>

                <dt class="col-sm-4 text-muted">Nominal Pengajuan</dt>
                <dd class="col-sm-8"><strong class="tw-font-mono tw-text-ui-sm">Rp {{ number_format($claim->amount, 0, ',', '.') }}</strong></dd>

                <dt class="col-sm-4 text-muted">Rekening Transfer</dt>
                <dd class="col-sm-8">{{ $claim->employee?->bank_name }} - {{ $claim->employee?->account_number }} a.n {{ $claim->employee?->account_holder_name }}</dd>

                <dt class="col-sm-4 text-muted">Waktu Pengajuan</dt>
                <dd class="col-sm-8">{{ $claim->created_at->format('d M Y H:i:s') }}</dd>
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
                <strong>Catatan Penting:</strong> Lampirkan lembar tanda terima ini bersama dengan berkas fisik struk/nota asli untuk diserahkan ke bagian General Affairs / Finance. Tanda terima ini mengonfirmasi pengajuan digital tercatat dalam sistem dan bukan merupakan bukti pelunasan transfer.
            </p>
        </div>
    </x-ui.card>
</div>
@endsection
