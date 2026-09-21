@extends('layouts.app')
@section('title', 'Master Rekanan Vendor Lokal - Purchasing')
@section('page-title', 'Vendor Lokal & Persetujuan Perubahan')

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        title="Master Rekanan Vendor Lokal"
        description="Daftar rekanan lokal ADASI, verifikasi pengajuan perubahan nomor rekening/profil supplier, dan audit invoice pengadaan lokal."
        eyebrow="Purchasing Procurement"
    >
        <x-slot:actions>
            <x-ui.button :href="\App\Support\PurchasingNavigation::listUrl('purchasing.dashboard')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>Dashboard</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Pending Change Requests --}}
    @if($pendingRequests->isNotEmpty())
        <x-ui.card
            title="Pengajuan Perubahan Data Vendor (Menunggu Persetujuan Purchasing / Finance)"
            description="Purchasing berwenang menyetujui atau menolak permohonan perubahan rekening atau profil vendor."
        >
            <div class="tw-space-y-4">
                @foreach($pendingRequests as $req)
                    <div class="tw-p-4 tw-rounded-ui-sm tw-border tw-border-warning/30 tw-bg-warning-container/20">
                        <div class="tw-flex tw-flex-col sm:tw-flex-row sm:tw-items-center sm:tw-justify-between tw-gap-3">
                            <div>
                                <div class="tw-flex tw-items-center tw-gap-2">
                                    <strong class="tw-text-ui-sm tw-text-on-surface">
                                        {{ $req->supplier->supplier?->company_name ?: $req->supplier->name }}
                                    </strong>
                                    <span class="tw-inline-flex tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-semibold tw-bg-warning tw-text-warning-foreground">
                                        Tipe: {{ ucwords(str_replace('_', ' ', $req->change_type)) }}
                                    </span>
                                </div>
                                <span class="tw-text-ui-xs tw-text-on-surface-variant tw-block tw-mt-1">
                                    Diajukan: {{ $req->created_at->format('d M Y H:i') }}
                                </span>
                            </div>

                            <div class="tw-flex tw-items-center tw-gap-2">
                                <form method="POST" action="{{ route('purchasing.local-vendors.change-requests.approve', $req) }}" onsubmit="event.preventDefault(); window.AdasiAlert.confirm({title: 'Setujui Perubahan Vendor?', text: 'Setujui perubahan profil/rekening vendor ini?', confirmText: 'Ya, Setujui', cancelText: 'Batal'}).then(r => { if (r.isConfirmed) this.submit(); });">
                                    @csrf
                                    <x-ui.button type="submit" variant="primary" size="sm">
                                        <x-ui.icon name="check" size="sm" />
                                        <span>Setujui</span>
                                    </x-ui.button>
                                </form>

                                <button
                                    type="button"
                                    class="btn btn-outline-danger btn-sm"
                                    data-bs-toggle="modal"
                                    data-bs-target="#rejectReqModal-{{ $req->id }}"
                                >
                                    <x-ui.icon name="x" size="sm" />
                                    <span>Tolak</span>
                                </button>
                            </div>
                        </div>

                        {{-- Proposed changes comparison summary --}}
                        @include('partials.vendor-change-request-details', ['changeRequest' => $req])
                    </div>

                    {{-- Reject Modal --}}
                    <div class="modal fade" id="rejectReqModal-{{ $req->id }}" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog">
                            <form method="POST" action="{{ route('purchasing.local-vendors.change-requests.reject', $req) }}">
                                @csrf
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title tw-text-ui-sm tw-font-bold">Tolak Pengajuan Perubahan Data</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="mb-3">
                                            <label class="form-label tw-text-ui-xs tw-font-semibold">Alasan Penolakan (Wajib) <span class="text-danger">*</span></label>
                                            <textarea name="notes" class="form-control form-control-sm" rows="3" required placeholder="Jelaskan alasan penolakan kepada vendor..."></textarea>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                                        <button type="submit" class="btn btn-danger btn-sm">Tolak Pengajuan</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-ui.card>
    @endif

    {{-- Vendors Table --}}
    <x-ui.data-table
        title="Daftar Rekanan Vendor Lokal"
        description="Vendor terdaftar yang melayani pengadaan lokal di ADASI."
    >
        <div class="table-responsive">
            <table class="table table-hover align-middle tw-m-0 tw-text-ui-sm w-100">
                <thead class="table-light">
                    <tr>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">Nama Vendor / Perusahaan</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">Kategori</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">Status PKP</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">Rekening Aktif</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">Payment Term</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($vendors as $vendorUser)
                        @php
                            $sup = $vendorUser->supplier;
                            $activeBank = $sup?->activeBankAccount;
                        @endphp
                        <tr>
                            <td>
                                <strong class="tw-text-on-surface">{{ $sup?->company_name ?: $vendorUser->name }}</strong>
                                <span class="tw-block tw-text-ui-xs tw-text-on-surface-variant">{{ $vendorUser->email }}</span>
                            </td>
                            <td>
                                <span class="tw-inline-flex tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-semibold {{ $sup?->vendor_category === 'Barang' ? 'tw-bg-primary/10 tw-text-primary' : 'tw-bg-info/10 tw-text-info' }}">
                                    {{ $sup?->vendor_category ?? 'Barang' }}
                                </span>
                            </td>
                            <td>
                                <span class="tw-inline-flex tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-semibold {{ $sup?->is_pkp ? 'tw-bg-success/10 tw-text-success' : 'tw-bg-surface-container tw-text-on-surface-variant' }}">
                                    {{ $sup?->is_pkp ? 'PKP' : 'Non-PKP' }}
                                </span>
                            </td>
                            <td>
                                @if($activeBank)
                                    <strong class="tw-text-ui-xs tw-text-on-surface">{{ $activeBank->bank_name }}</strong>
                                    <span class="tw-block tw-font-mono tw-text-ui-xs">{{ $activeBank->account_number }}</span>
                                    <span class="tw-block tw-text-[11px] tw-text-on-surface-variant">a.n {{ $activeBank->account_holder_name }}</span>
                                @else
                                    <span class="tw-text-ui-xs tw-text-error">Belum terverifikasi</span>
                                @endif
                            </td>
                            <td>
                                <span class="tw-font-semibold tw-text-ui-xs">Net {{ $sup?->payment_term_days ?? 30 }} Hari</span>
                            </td>
                            <td class="text-end">
                                <x-ui.button :href="route('purchasing.local-vendors.show', $vendorUser)" size="sm" variant="outline">
                                    <x-ui.icon name="eye" size="sm" />
                                    <span>Detail Master</span>
                                </x-ui.button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center tw-py-8 tw-text-on-surface-variant tw-text-ui-sm">
                                Tidak ada data vendor lokal.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($vendors->hasPages())
            <x-slot:pagination>
                {{ $vendors->links() }}
            </x-slot:pagination>
        @endif
    </x-ui.data-table>
</div>
@endsection
