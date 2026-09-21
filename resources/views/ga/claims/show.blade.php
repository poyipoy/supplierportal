@extends('layouts.app')
@section('title', 'Detail Klaim '.$claim->claim_number.' - GA')
@section('page-title', 'Detail Pengajuan Klaim GA')

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        :title="'Klaim '.$claim->claim_number"
        :description="'Karyawan: '.$claim->employee?->name.' ('.$claim->employee?->department.') — Tipe: '.$claim->claim_type"
        eyebrow="General Affairs Claim Detail"
    >
        <x-slot:actions>
            <x-ui.button :href="route('ga.claims.index')" variant="ghost" size="sm">
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

    <div class="tw-grid tw-grid-cols-1 lg:tw-grid-cols-12 tw-gap-6">
        {{-- Left: Information & Documents --}}
        <div class="lg:tw-col-span-8 tw-space-y-6">
            <x-ui.card title="Rincian Klaim Karyawan">
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
                        <span class="tw-text-on-surface-variant tw-block">Karyawan:</span>
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
                            <span class="tw-text-on-surface-variant tw-block tw-font-semibold">Keterangan:</span>
                            <span class="tw-text-on-surface">{{ $claim->description }}</span>
                        </div>
                    @endif
                </div>
            </x-ui.card>

            {{-- Dokumen Pendukung --}}
            <x-ui.card title="Dokumen Pendukung Klaim">
                <div class="tw-space-y-3">
                    @forelse($claim->documents as $doc)
                        <div class="tw-flex tw-items-center tw-justify-between tw-p-3 tw-rounded tw-border tw-border-outline-variant tw-bg-surface-container">
                            <div class="tw-flex tw-items-center tw-gap-2.5">
                                <x-ui.icon name="file-text" size="md" class="tw-text-primary" />
                                <div>
                                    <span class="tw-font-semibold tw-text-ui-xs tw-text-on-surface tw-block">{{ $doc->original_filename }}</span>
                                    <span class="tw-text-[11px] tw-text-on-surface-variant">{{ ucwords(str_replace('_', ' ', $doc->document_type)) }} · Rev {{ $doc->revision_number }}</span>
                                </div>
                            </div>
                            <a href="{{ route('ga-claim-documents.show', $doc) }}" target="_blank" class="btn btn-sm btn-outline-primary tw-text-xs">
                                Unduh
                            </a>
                        </div>
                    @empty
                        <div class="tw-text-center tw-py-4 tw-text-ui-xs tw-text-on-surface-variant">
                            Belum ada dokumen yang diunggah.
                        </div>
                    @endforelse
                </div>
            </x-ui.card>
        </div>

        {{-- Right: Destination Bank & Actions --}}
        <div class="lg:tw-col-span-4 tw-space-y-6">
            {{-- Rekening Tujuan --}}
            <x-ui.card title="Rekening Transfer Karyawan">
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
                </div>
            </x-ui.card>

            {{-- Action for GA: Need Revision Resubmit --}}
            @if($claim->status === \App\Models\GaClaim::STATUS_NEED_REVISION)
                <x-ui.card title="Permintaan Revisi dari Finance">
                    <div class="tw-space-y-3">
                        <div class="tw-p-3 tw-rounded tw-bg-error/10 tw-border tw-border-error/20 tw-text-error tw-text-ui-xs">
                            <strong>Alasan Revisi:</strong>
                            <p class="tw-m-0 tw-mt-1">{{ $claim->revision_reason ?: 'Harap perbaiki rincian klaim atau lampirkan dokumen pendukung.' }}</p>
                        </div>
                        <x-ui.button :href="route('ga.claims.revision', $claim)" variant="primary" size="sm" class="w-100">
                            <x-ui.icon name="edit" size="sm" />
                            <span>Perbaiki & Ajukan Ulang Klaim</span>
                        </x-ui.button>
                    </div>
                </x-ui.card>
            @endif

            {{-- Basic Verification Action for GA --}}
            @if($claim->status === \App\Models\GaClaim::STATUS_SUBMITTED)
                <x-ui.card title="Verifikasi Dasar GA">
                    <form method="POST" action="{{ route('ga.claims.basic-verify', $claim) }}">
                        @csrf
                        <div class="tw-space-y-3">
                            <p class="tw-text-ui-xs tw-text-on-surface-variant">
                                Lakukan konfirmasi verifikasi kelengkapan berkas fisik awal sebelum diajukan dalam draft DRP untuk Finance.
                            </p>
                            <div>
                                <input type="text" name="notes" class="form-control form-control-sm" placeholder="Catatan verifikasi (opsional)...">
                            </div>
                            <x-ui.button type="submit" variant="primary" size="sm" class="w-100">
                                <x-ui.icon name="check" size="sm" />
                                <span>Konfirmasi Verifikasi Dasar GA</span>
                            </x-ui.button>
                        </div>
                    </form>
                </x-ui.card>
            @endif

            {{-- Status Timeline --}}
            <x-ui.card title="Jejak Status Klaim">
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
