@extends('layouts.app')
@section('title', 'Pengajuan Klaim GA Baru - ADASI')
@section('page-title', 'Form Pengajuan Klaim GA')

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        title="Pengajuan Klaim Karyawan (GA)"
        description="Pilih karyawan penerima dari Employee Master, masukkan nominal klaim, dan lampirkan bukti dokumen pendukung fisik/digital."
        eyebrow="General Affairs Operations"
    >
        <x-slot:actions>
            <x-ui.button :href="route('ga.claims.index')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>Kembali ke Register</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="tw-max-w-3xl tw-mx-auto tw-w-full">
        <x-ui.card title="Form Input Klaim Karyawan">
            <form method="POST" action="{{ route('ga.claims.store') }}" enctype="multipart/form-data">
                @csrf
                <div class="tw-space-y-4">
                    {{-- Karyawan Dropdown (Searchable Select) --}}
                    @php
                        $resolvedEmployeeOptions = $employeeOptions ?? ($employees ?? collect())->map(function ($emp) {
                            $accountDetail = $emp->bank_name . ' (' . $emp->account_number . ($emp->account_holder_name ? ' a.n ' . $emp->account_holder_name : '') . ')';

                            return [
                                'value' => (string) $emp->id,
                                'label' => $emp->name,
                                'sublabel' => $emp->department . ' · ' . $accountDetail,
                                'badge' => $emp->bank_name,
                                'badgeTone' => method_exists($emp, 'isBca') && $emp->isBca() ? 'neutral' : 'warning',
                                'searchKeywords' => strtolower(implode(' ', array_filter([
                                    $emp->name,
                                    $emp->department,
                                    $emp->bank_name,
                                    $emp->account_number,
                                    $emp->account_holder_name,
                                ]))),
                            ];
                        })->values()->all();
                    @endphp

                    <div>
                        <x-ui.searchable-select
                            name="employee_id"
                            id="employee_id"
                            label="Karyawan Penerima Reimbursement / Klaim"
                            placeholder="-- Pilih Karyawan (Master Data) --"
                            search-placeholder="Ketik nama karyawan, departemen, bank, atau no. rekening..."
                            :options="$resolvedEmployeeOptions"
                            :value="old('employee_id')"
                            required
                            helper="*Rekening tujuan transfer diambil otomatis secara otoritatif dari Employee Master demi keamanan dan audit."
                            empty-message="Tidak ditemukan karyawan yang sesuai dengan pencarian"
                        />
                    </div>


                    {{-- Tipe Klaim & Tanggal --}}
                    <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-2 tw-gap-4">
                        <div>
                            <label for="claim_type" class="form-label tw-text-ui-xs tw-font-semibold">
                                Tipe Klaim <span class="text-danger">*</span>
                            </label>
                            <select name="claim_type" id="claim_type" class="form-select form-select-sm" required>
                                <option value="">-- Pilih Tipe Klaim --</option>
                                @foreach($claimTypes as $type)
                                    <option value="{{ $type }}" @selected(old('claim_type') === $type)>
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
                                :value="old('claim_date', now()->format('Y-m-d'))"
                                required
                            />
                        </div>
                    </div>

                    {{-- Nominal --}}
                    <div>
                        <label for="amount" class="form-label tw-text-ui-xs tw-font-semibold">
                            Nominal Klaim (Rp) <span class="text-danger">*</span>
                        </label>
                        <input type="number" step="0.01" name="amount" id="amount" class="form-control form-control-sm" placeholder="Contoh: 350000" value="{{ old('amount') }}" required min="1">
                    </div>

                    {{-- Deskripsi --}}
                    <div>
                        <label for="description" class="form-label tw-text-ui-xs tw-font-semibold">
                            Keterangan / Keperluan Klaim (Opsional)
                        </label>
                        <textarea name="description" id="description" class="form-control form-control-sm" rows="3" placeholder="Jelaskan rincian agenda, peserta entertain, atau keperluan operasional...">{{ old('description') }}</textarea>
                    </div>

                    {{-- Dokumen Pendukung --}}
                    <div class="tw-border-t tw-border-outline-variant tw-pt-3">
                        <label for="supporting" class="form-label tw-text-ui-xs tw-font-semibold">
                            Unggah Dokumen Pendukung (Struk, Bukti Nota, Form UPD) <span class="tw-text-on-surface-variant font-normal">(Opsional)</span>
                        </label>
                        <input type="file" name="supporting" id="supporting" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png,.xlsx,.xls,.doc,.docx">
                        <span class="tw-text-[11px] tw-text-on-surface-variant tw-block tw-mt-1">
                            Format diizinkan: PDF, JPG, PNG, Excel, Word (Maks 10 MB). File disimpan pada storage privat terenkripsi.
                        </span>
                    </div>

                    {{-- Submit Button --}}
                    <div class="tw-flex tw-justify-end tw-gap-2 tw-pt-4">
                        <x-ui.button :href="route('ga.claims.index')" variant="ghost" size="sm">
                            <span>Batal</span>
                        </x-ui.button>
                        <x-ui.button type="submit" variant="primary" size="sm">
                            <x-ui.icon name="check" size="sm" />
                            <span>Kirim Pengajuan Klaim & Terbitkan Tanda Terima</span>
                        </x-ui.button>
                    </div>
                </div>
            </form>
        </x-ui.card>
    </div>
</div>
@endsection
