@extends('layouts.app')
@section('title', 'Revisi Klaim GA '.$claim->claim_number.' - ADASI')
@section('page-title', 'Revisi Pengajuan Klaim GA')

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        :title="'Revisi Klaim #'.$claim->claim_number"
        :description="'Karyawan: '.$claim->employee?->name.' ('.$claim->employee?->department.') — Revisi ke-'.($claim->revision_number + 1)"
        eyebrow="General Affairs Operations"
    >
        <x-slot:actions>
            <x-ui.button :href="route('ga.claims.show', $claim)" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>Kembali ke Detail</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Revision Reason Alert --}}
    @if($claim->revision_reason)
        <div class="tw-p-4 tw-rounded-lg tw-bg-error/10 tw-border tw-border-error/20 tw-text-error tw-text-ui-sm tw-space-y-1">
            <div class="tw-font-semibold tw-flex tw-items-center tw-gap-2">
                <x-ui.icon name="alert-circle" size="sm" />
                <span>Catatan / Alasan Permintaan Revisi dari Finance:</span>
            </div>
            <p class="tw-m-0 tw-text-ui-xs tw-font-medium">{{ $claim->revision_reason }}</p>
        </div>
    @endif

    <div class="tw-max-w-3xl tw-mx-auto tw-w-full">
        <x-ui.card title="Form Revisi Klaim Karyawan">
            <form method="POST" action="{{ route('ga.claims.resubmit', $claim) }}" enctype="multipart/form-data">
                @csrf
                <div class="tw-space-y-4">
                    {{-- Karyawan (Read-Only) --}}
                    <div>
                        <label class="form-label tw-text-ui-xs tw-font-semibold">Karyawan Penerima</label>
                        <div class="tw-p-2.5 tw-rounded tw-bg-surface-container tw-text-ui-xs">
                            <strong>{{ $claim->employee?->name }}</strong> ({{ $claim->employee?->department }}) — Bank {{ $claim->employee?->bank_name }} - {{ $claim->employee?->account_number }} a.n {{ $claim->employee?->account_holder_name }}
                        </div>
                    </div>

                    {{-- Tipe Klaim & Tanggal --}}
                    <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-2 tw-gap-4">
                        <div>
                            <label for="claim_type" class="form-label tw-text-ui-xs tw-font-semibold">
                                Tipe Klaim <span class="text-danger">*</span>
                            </label>
                            <select name="claim_type" id="claim_type" class="form-select form-select-sm" required>
                                @foreach($claimTypes as $type)
                                    <option value="{{ $type }}" @selected(old('claim_type', $claim->claim_type) === $type)>
                                        {{ $type }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="claim_date" class="form-label tw-text-ui-xs tw-font-semibold">
                                Tanggal Pengajuan / Kejadian <span class="text-danger">*</span>
                            </label>
                            <x-ui.date-picker
                                name="claim_date"
                                id="claim_date"
                                :value="old('claim_date', $claim->claim_date?->format('Y-m-d'))"
                                required
                            />
                        </div>
                    </div>

                    {{-- Nominal --}}
                    <div>
                        <label for="amount" class="form-label tw-text-ui-xs tw-font-semibold">
                            Nominal Klaim (Rp) <span class="text-danger">*</span>
                        </label>
                        <input type="number" step="0.01" name="amount" id="amount" class="form-control form-control-sm" value="{{ old('amount', $claim->amount) }}" required min="1">
                    </div>

                    {{-- Deskripsi --}}
                    <div>
                        <label for="description" class="form-label tw-text-ui-xs tw-font-semibold">
                            Keterangan / Keperluan Klaim (Opsional)
                        </label>
                        <textarea name="description" id="description" class="form-control form-control-sm" rows="3">{{ old('description', $claim->description) }}</textarea>
                    </div>

                    {{-- Dokumen Pendukung Baru (Opsional) --}}
                    <div class="tw-border-t tw-border-outline-variant tw-pt-3">
                        <label for="supporting" class="form-label tw-text-ui-xs tw-font-semibold">
                            Unggah Dokumen Pendukung Tambahan / Revisi (Opsional)
                        </label>
                        <input type="file" name="supporting" id="supporting" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png,.xlsx,.xls,.doc,.docx">
                        <span class="tw-text-[11px] tw-text-on-surface-variant tw-block tw-mt-1">
                            Format diizinkan: PDF, JPG, PNG, Excel, Word (Maks 10 MB). File sebelumnya tetap tersimpan dalam riwayat revisi.
                        </span>
                    </div>

                    {{-- Submit Button --}}
                    <div class="tw-flex tw-justify-end tw-gap-2 tw-pt-4">
                        <x-ui.button :href="route('ga.claims.show', $claim)" variant="ghost" size="sm">
                            <span>Batal</span>
                        </x-ui.button>
                        <x-ui.button type="submit" variant="primary" size="sm">
                            <x-ui.icon name="send" size="xs" />
                            <span>Kirim Revisi Klaim (Rev {{ $claim->revision_number + 1 }})</span>
                        </x-ui.button>
                    </div>
                </div>
            </form>
        </x-ui.card>
    </div>
</div>
@endsection
