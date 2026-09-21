@extends('layouts.app')
@section('title', 'Verifikasi Klaim GA '.$claim->claim_number.' - Finance AP')
@section('page-title', 'Verifikasi Klaim GA')

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        :title="'Klaim '.$claim->claim_number"
        :description="'Karyawan: '.$claim->employee?->name.' ('.$claim->employee?->department.') — Tipe: '.$claim->claim_type"
        eyebrow="Finance Verification — GA Claim"
    >
        <x-slot:actions>
            <x-ui.button :href="route('finance.ga-claims.index')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>Kembali ke Register</span>
            </x-ui.button>
            @if($claim->receipt)
                <x-ui.button :href="route('ga.claims.receipt', $claim)" variant="outline" size="sm" target="_blank">
                    <x-ui.icon name="printer" size="sm" />
                    <span>Cetak Tanda Terima (TT-GA)</span>
                </x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if(session('success'))
        <div class="tw-p-4 tw-rounded-lg tw-bg-success/10 tw-border tw-border-success/20 tw-text-success tw-text-ui-sm tw-flex tw-items-center tw-gap-3">
            <x-ui.icon name="check-circle" size="md" />
            <span>{{ session('success') }}</span>
        </div>
    @endif

    @if($errors->any())
        <div class="tw-p-4 tw-rounded-lg tw-bg-error/10 tw-border tw-border-error/20 tw-text-error tw-text-ui-sm tw-space-y-1">
            @foreach($errors->all() as $err)
                <div>{{ $err }}</div>
            @endforeach
        </div>
    @endif

    <div class="tw-grid tw-grid-cols-1 lg:tw-grid-cols-12 tw-gap-6">
        {{-- Left: Details & Documents --}}
        <div class="lg:tw-col-span-8 tw-space-y-6">
            <x-ui.card title="Rincian Pengajuan Klaim Karyawan">
                <div class="tw-grid tw-grid-cols-2 sm:tw-grid-cols-3 tw-gap-4 tw-text-ui-xs">
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">Nomor Klaim:</span>
                        <strong class="tw-font-mono tw-text-ui-sm tw-text-on-surface">{{ $claim->claim_number }}</strong>
                    </div>
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">Tanggal Pengajuan:</span>
                        <strong class="tw-text-ui-sm tw-text-on-surface">{{ $claim->claim_date?->format('d M Y') }}</strong>
                    </div>
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">Status Klaim:</span>
                        <x-ui.status-chip :tone="match($claim->status) { 'PAID' => 'success', 'READY_TO_PAY' => 'success', 'NEED_REVISION' => 'error', 'BASIC_VERIFIED' => 'info', default => 'warning' }">
                            {{ $claim->status }}
                        </x-ui.status-chip>
                    </div>
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">Karyawan Penerima:</span>
                        <strong class="tw-text-on-surface">{{ $claim->employee?->name }}</strong>
                    </div>
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">Departemen:</span>
                        <span>{{ $claim->employee?->department }}</span>
                    </div>
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">Tipe Klaim:</span>
                        <span class="tw-inline-flex tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-semibold tw-bg-primary/10 tw-text-primary">
                            {{ $claim->claim_type }}
                        </span>
                    </div>
                    <div class="tw-col-span-full tw-border-t tw-border-outline-variant tw-pt-3">
                        <span class="tw-text-on-surface-variant tw-block">Nominal Pengajuan:</span>
                        <span class="tw-font-mono tw-font-bold tw-text-ui-lg tw-text-primary">
                            Rp {{ number_format($claim->amount, 0, ',', '.') }}
                        </span>
                    </div>
                    @if($claim->description)
                        <div class="tw-col-span-full tw-bg-surface-container tw-p-3 tw-rounded">
                            <span class="tw-text-on-surface-variant tw-block tw-font-semibold">Keterangan / Keperluan:</span>
                            <span class="tw-text-on-surface">{{ $claim->description }}</span>
                        </div>
                    @endif
                    @if($claim->revision_reason)
                        <div class="tw-col-span-full tw-bg-error/10 tw-border tw-border-error/20 tw-p-3 tw-rounded tw-text-error">
                            <span class="tw-block tw-font-semibold">Catatan Revisi:</span>
                            <span>{{ $claim->revision_reason }}</span>
                        </div>
                    @endif
                </div>
            </x-ui.card>

            {{-- Dokumen Pendukung --}}
            <x-ui.card title="Dokumen Pendukung Klaim (Private Storage)">
                <div class="tw-space-y-3">
                    @forelse($claim->documents as $doc)
                        <div class="tw-flex tw-items-center tw-justify-between tw-p-3 tw-rounded tw-border tw-border-outline-variant tw-bg-surface-container">
                            <div class="tw-flex tw-items-center tw-gap-2.5">
                                <x-ui.icon name="file-text" size="md" class="tw-text-primary" />
                                <div>
                                    <span class="tw-font-semibold tw-text-ui-xs tw-text-on-surface tw-block">{{ $doc->original_filename }}</span>
                                    <span class="tw-text-[11px] tw-text-on-surface-variant">{{ ucwords(str_replace('_', ' ', $doc->document_type)) }} · Rev {{ $doc->revision_number }} · {{ number_format($doc->file_size / 1024, 1) }} KB</span>
                                </div>
                            </div>
                            <a href="{{ route('ga-claim-documents.show', $doc) }}" target="_blank" class="btn btn-sm btn-outline-primary tw-text-xs">
                                Unduh
                            </a>
                        </div>
                    @empty
                        <div class="tw-text-center tw-py-4 tw-text-ui-xs tw-text-on-surface-variant">
                            Tidak ada dokumen pendukung yang diunggah.
                        </div>
                    @endforelse
                </div>
            </x-ui.card>
        </div>

        {{-- Right: Destination Bank & Finance Verification --}}
        <div class="lg:tw-col-span-4 tw-space-y-6">
            {{-- Destination Bank --}}
            <x-ui.card title="Rekening Tujuan Transfer Otoritatif">
                <div class="tw-space-y-2 tw-text-ui-xs">
                    <div class="tw-flex tw-justify-between">
                        <span class="tw-text-on-surface-variant">Bank:</span>
                        <strong class="tw-text-on-surface">{{ $claim->employee?->bank_name }}</strong>
                    </div>
                    <div class="tw-flex tw-justify-between">
                        <span class="tw-text-on-surface-variant">Nomor Rekening:</span>
                        <strong class="tw-font-mono tw-text-on-surface">{{ $claim->employee?->account_number }}</strong>
                    </div>
                    <div class="tw-flex tw-justify-between">
                        <span class="tw-text-on-surface-variant">Atas Nama:</span>
                        <strong class="tw-text-on-surface">{{ $claim->employee?->account_holder_name }}</strong>
                    </div>
                    <div class="tw-pt-2 tw-text-[11px] tw-text-on-surface-variant">
                        *Data rekening di-snapshot dari Employee Master aktif. Biaya transfer bank untuk seluruh klaim GA adalah Rp 0 (sesuai aturan internal).
                    </div>
                </div>
            </x-ui.card>

            {{-- Verification Action --}}
            <x-ui.card title="Aksi Verifikasi Finance AP">
                @if($claim->status === \App\Models\GaClaim::STATUS_BASIC_VERIFIED)
                    <div class="tw-space-y-4">
                        <p class="tw-text-ui-xs tw-text-on-surface-variant">
                            Klaim telah melalui Verifikasi Dasar oleh GA. Pastikan bukti nota/struk fisik dan peruntukan biaya telah sesuai SOP sebelum menyetujui.
                        </p>

                        {{-- Approve Button Form --}}
                        <form method="POST" action="{{ route('finance.ga-claims.verify', $claim) }}" onsubmit="event.preventDefault(); window.AdasiAlert.confirm({title: 'Setujui Klaim GA?', text: 'Setujui klaim GA ini dan jadikan Ready to Pay?', confirmText: 'Ya, Setujui', cancelText: 'Batal'}).then(r => { if (r.isConfirmed) this.submit(); });">
                            @csrf
                            <input type="hidden" name="approve" value="1">
                            <x-ui.button type="submit" variant="primary" size="sm" class="w-100">
                                <x-ui.icon name="check-circle" size="sm" />
                                <span>Setujui (Ready to Pay)</span>
                            </x-ui.button>
                        </form>

                        <hr class="tw-border-outline-variant tw-my-2">

                        {{-- Request Revision Form --}}
                        <div>
                            <button
                                type="button"
                                class="btn btn-outline-danger btn-sm w-100"
                                data-bs-toggle="collapse"
                                data-bs-target="#revisionCollapse"
                            >
                                <x-ui.icon name="alert-circle" size="sm" />
                                <span>Minta Revisi ke GA</span>
                            </button>

                            <div class="collapse tw-mt-3" id="revisionCollapse">
                                <form method="POST" action="{{ route('finance.ga-claims.verify', $claim) }}" class="tw-space-y-2">
                                    @csrf
                                    <input type="hidden" name="approve" value="0">
                                    <label class="form-label tw-text-ui-xs tw-font-semibold">Alasan Permintaan Revisi (Wajib) <span class="text-danger">*</span></label>
                                    <textarea name="reason" rows="3" class="form-control form-control-sm" required placeholder="Contoh: Bukti struk tidak jelas atau nominal form berbeda dengan lampiran..."></textarea>
                                    <button type="submit" class="btn btn-danger btn-sm w-100">
                                        Kirim Permintaan Revisi
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                @elseif($claim->status === \App\Models\GaClaim::STATUS_SUBMITTED)
                    <div class="tw-p-3 tw-rounded tw-bg-warning/10 tw-border tw-border-warning/20 tw-text-warning tw-text-ui-xs">
                        <strong>Menunggu Verifikasi Dasar GA</strong>
                        <p class="tw-m-0 tw-mt-1">Klaim ini belum melalui Verifikasi Dasar oleh GA. Verifikasi Finance hanya dapat dilakukan setelah status <code>BASIC_VERIFIED</code>.</p>
                    </div>
                @elseif($claim->status === \App\Models\GaClaim::STATUS_READY_TO_PAY)
                    <div class="tw-p-3 tw-rounded tw-bg-success/10 tw-border tw-border-success/20 tw-text-success tw-text-ui-xs">
                        <strong>Ready to Pay</strong>
                        <p class="tw-m-0 tw-mt-1">Klaim telah disetujui Finance dan siap diikutsertakan dalam DRP GA.</p>
                    </div>
                @elseif($claim->status === \App\Models\GaClaim::STATUS_PAID)
                    <div class="tw-p-3 tw-rounded tw-bg-primary/10 tw-border tw-border-primary/20 tw-text-primary tw-text-ui-xs">
                        <strong>Lunas (Paid)</strong>
                        <p class="tw-m-0 tw-mt-1">Pembayaran untuk klaim ini telah dieksekusi via DRP GA.</p>
                    </div>
                @elseif($claim->status === \App\Models\GaClaim::STATUS_NEED_REVISION)
                    <div class="tw-p-3 tw-rounded tw-bg-error/10 tw-border tw-border-error/20 tw-text-error tw-text-ui-xs">
                        <strong>Menunggu Revisi GA</strong>
                        <p class="tw-m-0 tw-mt-1">Permintaan revisi telah dikirim ke bagian General Affairs.</p>
                    </div>
                @endif
            </x-ui.card>

            {{-- Timeline --}}
            <x-ui.card title="Jejak Aktivitas Status">
                <div class="tw-space-y-3 tw-text-ui-xs">
                    @forelse($claim->statusHistories as $hist)
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
                        <div class="tw-text-on-surface-variant">Belum ada riwayat aktivitas.</div>
                    @endforelse
                </div>
            </x-ui.card>
        </div>
    </div>
</div>
@endsection
