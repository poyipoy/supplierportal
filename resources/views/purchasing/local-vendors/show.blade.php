@extends('layouts.app')
@section('title', 'Detail Vendor '.($vendor->supplier?->company_name ?: $vendor->name).' - Purchasing')
@section('page-title', 'Detail Rekanan Vendor Lokal')

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        :title="$vendor->supplier?->company_name ?: $vendor->name"
        :description="'Email: '.$vendor->email.' — NPWP: '.($vendor->supplier?->npwp ?? '—')"
        eyebrow="Purchasing Procurement"
    >
        <x-slot:actions>
            <x-ui.button :href="route('purchasing.local-vendors.index')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>Kembali ke Daftar</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @php $sup = $vendor->supplier; @endphp

    <div class="tw-grid tw-grid-cols-1 lg:tw-grid-cols-12 tw-gap-6">
        <div class="lg:tw-col-span-8 tw-space-y-6">
            <x-ui.card title="Profil Perusahaan Rekanan">
                <div class="tw-grid tw-grid-cols-2 sm:tw-grid-cols-3 tw-gap-4 tw-text-ui-xs">
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">Nama Perusahaan:</span>
                        <strong class="tw-text-ui-sm tw-text-on-surface">{{ $sup?->company_name ?: $vendor->name }}</strong>
                    </div>
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">Kategori Vendor:</span>
                        <strong class="tw-text-ui-sm tw-text-primary">{{ $sup?->vendor_category ?? 'Barang' }}</strong>
                    </div>
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">Status PKP:</span>
                        <strong class="tw-text-ui-sm {{ $sup?->is_pkp ? 'tw-text-success' : 'tw-text-on-surface' }}">
                            {{ $sup?->is_pkp ? 'PKP' : 'Non-PKP' }}
                        </strong>
                    </div>
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">NPWP:</span>
                        <span class="tw-font-mono">{{ $sup?->npwp ?? '—' }}</span>
                    </div>
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">Telepon:</span>
                        <span>{{ $sup?->phone ?? '—' }}</span>
                    </div>
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">Payment Term:</span>
                        <strong>Net {{ $sup?->payment_term_days ?? 30 }} Hari</strong>
                    </div>
                    <div class="tw-col-span-full">
                        <span class="tw-text-on-surface-variant tw-block">Alamat:</span>
                        <span>{{ $sup?->address ?? '—' }}</span>
                    </div>
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">Nama PIC:</span>
                        <span>{{ $sup?->pic_name ?? '—' }}</span>
                    </div>
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">Email PIC:</span>
                        <span>{{ $sup?->pic_email ?? '—' }}</span>
                    </div>
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">No. HP/WA PIC:</span>
                        <span>{{ $sup?->pic_phone ?? '—' }}</span>
                    </div>
                </div>
            </x-ui.card>

            {{-- Bank Accounts History --}}
            <x-ui.card title="Rekening Bank Terdaftar">
                <div class="table-responsive">
                    <table class="table table-hover align-middle tw-m-0 tw-text-ui-xs w-100">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Nama Bank</th>
                                <th scope="col">Nomor Rekening</th>
                                <th scope="col">Atas Nama</th>
                                <th scope="col">Status</th>
                                <th scope="col">Status Aktif</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($sup?->bankAccounts ?? [] as $b)
                                <tr>
                                    <td><strong class="tw-text-on-surface">{{ $b->bank_name }}</strong></td>
                                    <td><span class="tw-font-mono">{{ $b->account_number }}</span></td>
                                    <td>{{ $b->account_holder }}</td>
                                    <td>
                                        <span class="tw-inline-flex tw-px-2 tw-py-0.5 tw-rounded tw-text-[10px] {{ $b->status === 'VERIFIED' ? 'tw-bg-success/10 tw-text-success' : 'tw-bg-warning/10 tw-text-warning' }}">
                                            {{ $b->status }}
                                        </span>
                                    </td>
                                    <td>
                                        @if($b->is_active)
                                            <span class="tw-inline-flex tw-px-2 tw-py-0.5 tw-rounded tw-text-[10px] tw-bg-primary/10 tw-text-primary tw-font-bold">Aktif</span>
                                        @else
                                            <span class="tw-text-on-surface-variant">Non-aktif</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center tw-py-4 tw-text-on-surface-variant">Belum ada rekening terdaftar.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        </div>

        <div class="lg:tw-col-span-4 tw-space-y-6">
            <x-ui.card title="Dokumen Legalitas Master">
                <div class="tw-space-y-3">
                    @forelse($sup?->masterDocuments ?? [] as $md)
                        <div class="tw-p-3 tw-rounded tw-border tw-border-outline-variant tw-bg-surface-container tw-flex tw-items-center tw-justify-between">
                            <div>
                                <strong class="tw-text-ui-xs tw-text-on-surface tw-block">{{ ucwords(str_replace('_', ' ', $md->document_type)) }}</strong>
                                <span class="tw-text-[11px] tw-text-on-surface-variant tw-block">{{ $md->file_name }}</span>
                            </div>
                            <a href="{{ Storage::disk('private')->url($md->file_path) }}" target="_blank" class="btn btn-xs btn-outline-primary">Unduh</a>
                        </div>
                    @empty
                        <div class="tw-text-center tw-py-6 tw-text-ui-xs tw-text-on-surface-variant">
                            Belum ada dokumen legalitas.
                        </div>
                    @endforelse
                </div>
            </x-ui.card>
        </div>
    </div>
</div>
@endsection
