@extends('layouts.app')
@section('title', 'Profil & Legalitas Vendor - ADASI Portal')
@section('page-title', 'Profil & Legalitas Vendor')

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        title="Profil & Legalitas Vendor"
        description="Kelola informasi master rekanan, rekening bank terdaftar, riwayat pengajuan perubahan data, serta dokumen legalitas perusahaan."
        eyebrow="Rekanan Lokal"
    >
        <x-slot:actions>
            <x-ui.button :href="route('local-supplier.dashboard')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>Kembali ke Dashboard</span>
            </x-ui.button>
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
            <div class="tw-font-semibold tw-flex tw-items-center tw-gap-2">
                <x-ui.icon name="alert-circle" size="sm" />
                <span>Terjadi kesalahan pada input:</span>
            </div>
            <ul class="tw-list-disc tw-list-inside tw-text-ui-xs">
                @foreach($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="tw-grid tw-grid-cols-1 lg:tw-grid-cols-12 tw-gap-6">
        {{-- Kolom Kiri: Profil Master & Form Pengajuan --}}
        <div class="lg:tw-col-span-8 tw-space-y-6">
            {{-- Data Master Aktif --}}
            <x-ui.card title="Informasi Master Rekanan (Aktif)">
                <div class="tw-grid tw-grid-cols-2 sm:tw-grid-cols-3 tw-gap-4 tw-text-ui-xs">
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">Nama Perusahaan:</span>
                        <strong class="tw-text-ui-sm tw-text-on-surface">{{ $supplier?->company_name ?: $user->name }}</strong>
                    </div>
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">Kategori Vendor:</span>
                        <strong class="tw-text-ui-sm tw-text-primary">{{ $supplier?->vendor_category ?? 'Barang' }}</strong>
                    </div>
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">Status PKP:</span>
                        <strong class="tw-text-ui-sm {{ $supplier?->is_pkp ? 'tw-text-success' : 'tw-text-on-surface' }}">
                            {{ $supplier?->is_pkp ? 'PKP' : 'Non-PKP' }}
                        </strong>
                    </div>
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">NPWP:</span>
                        <span class="tw-font-mono">{{ $supplier?->npwp ?? '—' }}</span>
                    </div>
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">Telepon Perusahaan:</span>
                        <span>{{ $supplier?->phone ?? '—' }}</span>
                    </div>
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">Payment Term:</span>
                        <div class="tw-flex tw-items-center tw-gap-1.5">
                            <strong>Net {{ $supplier?->payment_term_days ?? 30 }} Hari</strong>
                            <span class="tw-text-[10px] tw-bg-surface-container tw-text-on-surface-variant tw-px-1.5 tw-py-0.5 tw-rounded" title="Payment term ditentukan oleh internal ADASI">Internal</span>
                        </div>
                    </div>
                    <div class="tw-col-span-full">
                        <span class="tw-text-on-surface-variant tw-block">Alamat:</span>
                        <span>{{ $supplier?->address ?? '—' }}</span>
                    </div>
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">Nama PIC:</span>
                        <span>{{ $supplier?->pic_name ?? '—' }}</span>
                    </div>
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">Email PIC:</span>
                        <span>{{ $supplier?->pic_email ?? '—' }}</span>
                    </div>
                    <div>
                        <span class="tw-text-on-surface-variant tw-block">No. HP/WA PIC:</span>
                        <span>{{ $supplier?->pic_phone ?? '—' }}</span>
                    </div>
                </div>
            </x-ui.card>

            {{-- Rekening Bank Terdaftar --}}
            <x-ui.card title="Rekening Bank Terdaftar">
                <div class="table-responsive">
                    <table class="table table-hover align-middle tw-m-0 tw-text-ui-xs w-100">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Nama Bank</th>
                                <th scope="col">Nomor Rekening</th>
                                <th scope="col">Atas Nama</th>
                                <th scope="col">Status Verifikasi</th>
                                <th scope="col">Penggunaan</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($bankAccounts as $b)
                                <tr>
                                    <td><strong class="tw-text-on-surface">{{ $b->bank_name }}</strong></td>
                                    <td><span class="tw-font-mono">{{ $b->account_number }}</span></td>
                                    <td>{{ $b->account_holder_name }}</td>
                                    <td>
                                        <span class="tw-inline-flex tw-px-2 tw-py-0.5 tw-rounded tw-text-[10px] {{ $b->status === 'VERIFIED' ? 'tw-bg-success/10 tw-text-success' : 'tw-bg-warning/10 tw-text-warning' }}">
                                            {{ $b->status }}
                                        </span>
                                    </td>
                                    <td>
                                        @if($b->status === 'VERIFIED')
                                            <span class="tw-inline-flex tw-px-2 tw-py-0.5 tw-rounded tw-text-[10px] tw-bg-primary/10 tw-text-primary tw-font-bold">Rekening Aktif</span>
                                        @else
                                            <span class="tw-text-on-surface-variant">Non-aktif / Riwayat</span>
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

            {{-- Form Pengajuan Perubahan Data (Change Request) --}}
            <x-ui.card title="Ajukan Perubahan Data Profil / Rekening">
                <div class="tw-p-3 tw-mb-4 tw-rounded tw-bg-info/10 tw-border tw-border-info/20 tw-text-info tw-text-ui-xs tw-flex tw-items-start tw-gap-2">
                    <x-ui.icon name="info" size="sm" class="tw-shrink-0 tw-mt-0.5" />
                    <div>
                        Perubahan data master (nama perusahaan, NPWP, PIC, atau rekening bank) memerlukan verifikasi dan persetujuan dari tim Purchasing / Finance ADASI. Data saat ini akan tetap berlaku sampai pengajuan disetujui.
                    </div>
                </div>

                <form action="{{ route('local-supplier.vendor-profile.change-requests.store') }}" method="POST" class="tw-space-y-4">
                    @csrf

                    <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-2 tw-gap-4">
                        <div>
                            <label for="company_name" class="form-label tw-text-ui-xs tw-font-medium">Nama Perusahaan</label>
                            <input type="text" id="company_name" name="company_name" class="form-control form-control-sm" value="{{ old('company_name', $supplier?->company_name) }}">
                        </div>
                        <div>
                            <label for="vendor_category" class="form-label tw-text-ui-xs tw-font-medium">Kategori Vendor</label>
                            <select id="vendor_category" name="vendor_category" class="form-select form-select-sm">
                                <option value="Barang" @selected(old('vendor_category', $supplier?->vendor_category) === 'Barang')>Barang</option>
                                <option value="Jasa" @selected(old('vendor_category', $supplier?->vendor_category) === 'Jasa')>Jasa</option>
                                <option value="Lainnya" @selected(old('vendor_category', $supplier?->vendor_category) === 'Lainnya')>Lainnya</option>
                            </select>
                        </div>
                        <div>
                            <label for="npwp" class="form-label tw-text-ui-xs tw-font-medium">NPWP</label>
                            <input type="text" id="npwp" name="npwp" class="form-control form-control-sm" value="{{ old('npwp', $supplier?->npwp) }}">
                        </div>
                        <div>
                            <label for="is_pkp" class="form-label tw-text-ui-xs tw-font-medium">Status PKP</label>
                            <select id="is_pkp" name="is_pkp" class="form-select form-select-sm">
                                <option value="1" @selected(old('is_pkp', $supplier?->is_pkp) == 1)>PKP (Wajib Faktur Pajak)</option>
                                <option value="0" @selected(old('is_pkp', $supplier?->is_pkp) == 0)>Non-PKP</option>
                            </select>
                        </div>
                        <div>
                            <label for="phone" class="form-label tw-text-ui-xs tw-font-medium">Telepon Perusahaan</label>
                            <input type="text" id="phone" name="phone" class="form-control form-control-sm" value="{{ old('phone', $supplier?->phone) }}">
                        </div>
                        <div class="sm:tw-col-span-2">
                            <label for="address" class="form-label tw-text-ui-xs tw-font-medium">Alamat Perusahaan</label>
                            <textarea id="address" name="address" rows="2" class="form-control form-control-sm">{{ old('address', $supplier?->address) }}</textarea>
                        </div>
                    </div>

                    <hr class="tw-border-outline-variant tw-my-4">
                    <h4 class="tw-text-ui-xs tw-font-semibold tw-text-on-surface tw-mb-3">Informasi PIC (Person in Charge)</h4>

                    <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-4">
                        <div>
                            <label for="pic_name" class="form-label tw-text-ui-xs tw-font-medium">Nama PIC</label>
                            <input type="text" id="pic_name" name="pic_name" class="form-control form-control-sm" value="{{ old('pic_name', $supplier?->pic_name) }}">
                        </div>
                        <div>
                            <label for="pic_email" class="form-label tw-text-ui-xs tw-font-medium">Email PIC</label>
                            <input type="email" id="pic_email" name="pic_email" class="form-control form-control-sm" value="{{ old('pic_email', $supplier?->pic_email) }}">
                        </div>
                        <div>
                            <label for="pic_phone" class="form-label tw-text-ui-xs tw-font-medium">No. HP/WA PIC</label>
                            <input type="text" id="pic_phone" name="pic_phone" class="form-control form-control-sm" value="{{ old('pic_phone', $supplier?->pic_phone) }}">
                        </div>
                    </div>

                    <hr class="tw-border-outline-variant tw-my-4">
                    <h4 class="tw-text-ui-xs tw-font-semibold tw-text-on-surface tw-mb-3">Usulan Rekening Bank Baru (Opsional)</h4>
                    <p class="tw-text-[11px] tw-text-on-surface-variant tw-mb-3">Isi bagian ini hanya jika Anda ingin mengajukan perubahan rekening pembayaran.</p>

                    <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-4">
                        <div>
                            <label for="bank_name" class="form-label tw-text-ui-xs tw-font-medium">Nama Bank</label>
                            <input type="text" id="bank_name" name="bank_name" class="form-control form-control-sm" placeholder="Contoh: BCA / Mandiri / BRI" value="{{ old('bank_name') }}">
                        </div>
                        <div>
                            <label for="account_number" class="form-label tw-text-ui-xs tw-font-medium">Nomor Rekening</label>
                            <input type="text" id="account_number" name="account_number" class="form-control form-control-sm" placeholder="Nomor rekening" value="{{ old('account_number') }}">
                        </div>
                        <div>
                            <label for="account_holder_name" class="form-label tw-text-ui-xs tw-font-medium">Nama Pemilik Rekening</label>
                            <input type="text" id="account_holder_name" name="account_holder_name" class="form-control form-control-sm" placeholder="Atas nama sesuai buku tabungan" value="{{ old('account_holder_name') }}">
                        </div>
                    </div>

                    <div class="tw-pt-2 tw-flex tw-justify-end">
                        <x-ui.button type="submit" variant="primary" size="sm">
                            <x-ui.icon name="send" size="sm" />
                            <span>Kirim Pengajuan Perubahan</span>
                        </x-ui.button>
                    </div>
                </form>
            </x-ui.card>

            {{-- Riwayat Pengajuan Perubahan --}}
            <x-ui.card title="Riwayat Pengajuan Perubahan Data">
                <div class="table-responsive">
                    <table class="table table-hover align-middle tw-m-0 tw-text-ui-xs w-100">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Tanggal</th>
                                <th scope="col">Tipe</th>
                                <th scope="col">Status</th>
                                <th scope="col">Data Diajukan</th>
                                <th scope="col">Catatan Review</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($changeRequests as $cr)
                                <tr>
                                    <td class="tw-whitespace-nowrap">{{ $cr->requested_at?->format('d/m/Y H:i') ?: $cr->created_at->format('d/m/Y H:i') }}</td>
                                    <td><span class="tw-capitalize">{{ $cr->change_type }}</span></td>
                                    <td>
                                        @if($cr->status === 'APPROVED')
                                            <span class="tw-inline-flex tw-px-2 tw-py-0.5 tw-rounded tw-text-[10px] tw-bg-success/10 tw-text-success tw-font-bold">APPROVED</span>
                                        @elseif($cr->status === 'REJECTED')
                                            <span class="tw-inline-flex tw-px-2 tw-py-0.5 tw-rounded tw-text-[10px] tw-bg-error/10 tw-text-error tw-font-bold">REJECTED</span>
                                        @else
                                            <span class="tw-inline-flex tw-px-2 tw-py-0.5 tw-rounded tw-text-[10px] tw-bg-warning/10 tw-text-warning tw-font-bold">PENDING</span>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="tw-text-[11px] tw-space-y-0.5 tw-max-w-xs">
                                            @foreach($cr->proposed_data ?? [] as $key => $val)
                                                <div><span class="tw-text-on-surface-variant">{{ ucwords(str_replace('_', ' ', $key)) }}:</span> <strong>{{ is_bool($val) ? ($val ? 'Ya' : 'Tidak') : $val }}</strong></div>
                                            @endforeach
                                        </div>
                                    </td>
                                    <td>
                                        @if($cr->review_notes)
                                            <span class="tw-text-ui-xs tw-text-on-surface">{{ $cr->review_notes }}</span>
                                        @else
                                            <span class="tw-text-on-surface-variant">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center tw-py-4 tw-text-on-surface-variant">Belum ada riwayat pengajuan perubahan.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        </div>

        {{-- Kolom Kanan: Dokumen Legalitas Master --}}
        <div class="lg:tw-col-span-4 tw-space-y-6">
            {{-- Form Upload Dokumen Legalitas --}}
            <x-ui.card title="Unggah Dokumen Legalitas">
                <form action="{{ route('local-supplier.vendor-profile.documents.upload') }}" method="POST" enctype="multipart/form-data" class="tw-space-y-3">
                    @csrf
                    <div>
                        <label for="document_type" class="form-label tw-text-ui-xs tw-font-medium">Jenis Dokumen</label>
                        <select id="document_type" name="document_type" class="form-select form-select-sm" required>
                            <option value="">Pilih Jenis Dokumen...</option>
                            <option value="NIB">NIB (Nomor Induk Berusaha)</option>
                            <option value="NPWP">NPWP Perusahaan</option>
                            <option value="SPPKP">SPPKP (Surat Pengukuhan PKP)</option>
                            <option value="SURAT_PERNYATAAN_REKENING">Surat Pernyataan Rekening</option>
                            <option value="OTHER">Dokumen Legalitas Lainnya</option>
                        </select>
                    </div>

                    <div>
                        <label for="document" class="form-label tw-text-ui-xs tw-font-medium">Berkas Dokumen (PDF/JPG/PNG max 10MB)</label>
                        <input type="file" id="document" name="document" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png" required>
                    </div>

                    <div class="tw-pt-1">
                        <x-ui.button type="submit" variant="primary" size="sm" class="w-100">
                            <x-ui.icon name="upload" size="sm" />
                            <span>Unggah Dokumen</span>
                        </x-ui.button>
                    </div>
                </form>
            </x-ui.card>

            {{-- Daftar Dokumen Tersimpan --}}
            <x-ui.card title="Dokumen Legalitas Terdaftar">
                <div class="tw-space-y-3">
                    @forelse($documents as $doc)
                        <div class="tw-p-3 tw-rounded tw-border tw-border-outline-variant tw-bg-surface-container tw-flex tw-items-center tw-justify-between">
                            <div class="tw-overflow-hidden tw-pr-2">
                                <strong class="tw-text-ui-xs tw-text-on-surface tw-block">{{ ucwords(str_replace('_', ' ', $doc->document_type)) }}</strong>
                                <span class="tw-text-[11px] tw-text-on-surface-variant tw-truncate tw-block" title="{{ $doc->original_filename }}">{{ $doc->original_filename }}</span>
                                <span class="tw-text-[10px] tw-text-on-surface-variant">{{ number_format($doc->file_size / 1024, 1) }} KB · {{ $doc->created_at->format('d/m/Y') }}</span>
                            </div>
                            <a href="{{ route('supplier-master-documents.show', $doc) }}" target="_blank" class="btn btn-xs btn-outline-primary tw-shrink-0">
                                Unduh
                            </a>
                        </div>
                    @empty
                        <div class="tw-text-center tw-py-6 tw-text-ui-xs tw-text-on-surface-variant">
                            Belum ada dokumen legalitas terdaftar.
                        </div>
                    @endforelse
                </div>
            </x-ui.card>
        </div>
    </div>
</div>
@endsection
