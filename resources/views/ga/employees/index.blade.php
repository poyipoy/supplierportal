@extends('layouts.app')
@section('title', 'Employee Master - General Affairs')
@section('page-title', 'Employee Master')

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        title="Employee Master (Data Rekening Karyawan)"
        description="Kelola master data karyawan, departemen, dan rekening transfer otoritatif untuk keperluan klaim dan reimbursement GA."
        eyebrow="General Affairs Operations"
    >
        <x-slot:actions>
            <x-ui.button :href="route('ga.claims.index')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>Register Klaim</span>
            </x-ui.button>
            <x-ui.button type="button" variant="primary" size="sm" data-bs-toggle="modal" data-bs-target="#addEmployeeModal">
                <x-ui.icon name="plus" size="sm" />
                <span>Tambah Karyawan</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if(session('success'))
        <div class="tw-p-4 tw-rounded-lg tw-bg-success/10 tw-border tw-border-success/20 tw-text-success tw-text-ui-sm tw-flex tw-items-center tw-gap-3">
            <x-ui.icon name="check-circle" size="md" />
            <span>{{ session('success') }}</span>
        </div>
    @endif

    {{-- Filter Card --}}
    <x-ui.card>
        <form method="GET" action="{{ route('ga.employees.index') }}" class="tw-flex tw-flex-col sm:tw-flex-row sm:tw-items-center tw-gap-3">
            <div class="tw-flex-1">
                <input
                    type="text"
                    name="search"
                    class="form-control form-control-sm"
                    placeholder="Cari nama, departemen, atau no. rekening..."
                    value="{{ request('search') }}"
                >
            </div>
            <div class="tw-w-40">
                <select name="status" class="form-select form-select-sm">
                    <option value="">Semua Status</option>
                    <option value="active" @selected(request('status') === 'active')>Aktif</option>
                    <option value="inactive" @selected(request('status') === 'inactive')>Non-aktif</option>
                </select>
            </div>
            <div class="tw-flex tw-gap-2">
                <x-ui.button type="submit" variant="primary" size="sm">
                    <x-ui.icon name="search" size="sm" />
                    <span>Cari</span>
                </x-ui.button>
                @if(request()->hasAny(['search', 'status']))
                    <x-ui.button :href="route('ga.employees.index')" variant="ghost" size="sm">
                        <span>Reset</span>
                    </x-ui.button>
                @endif
            </div>
        </form>
    </x-ui.card>

    {{-- Data Table --}}
    <x-ui.data-table
        title="Daftar Karyawan Terdaftar"
        description="Data rekening digunakan secara otomatis saat membuat pengajuan klaim karyawan baru."
    >
        <div class="table-responsive">
            <table class="table table-hover align-middle tw-m-0 tw-text-ui-xs w-100">
                <thead class="table-light">
                    <tr>
                        <th scope="col">Nama Karyawan</th>
                        <th scope="col">Departemen</th>
                        <th scope="col">Bank</th>
                        <th scope="col">Nomor Rekening</th>
                        <th scope="col">Atas Nama</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-end tw-whitespace-nowrap">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($employees as $emp)
                        <tr>
                            <td><strong class="tw-text-ui-sm tw-text-on-surface">{{ $emp->name }}</strong></td>
                            <td><span class="tw-font-medium">{{ $emp->department }}</span></td>
                            <td>
                                <span class="tw-inline-flex tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-bold {{ $emp->isBca() ? 'tw-bg-primary/10 tw-text-primary' : 'tw-bg-surface-container tw-text-on-surface' }}">
                                    {{ $emp->bank_name }}
                                </span>
                            </td>
                            <td><span class="tw-font-mono tw-text-ui-sm">{{ $emp->account_number }}</span></td>
                            <td>{{ $emp->account_holder_name }}</td>
                            <td>
                                <x-ui.status-chip :tone="$emp->is_active ? 'success' : 'neutral'">
                                    {{ $emp->is_active ? 'Aktif' : 'Non-aktif' }}
                                </x-ui.status-chip>
                            </td>
                            <td class="text-end tw-whitespace-nowrap">
                                <div class="tw-inline-flex tw-items-center tw-justify-end tw-gap-1.5">
                                    <button
                                        type="button"
                                        class="ui-data-action ui-data-action--primary ui-motion ui-focus-ring tw-gap-1.5 active:tw-scale-[0.98]"
                                        data-bs-toggle="modal"
                                        data-bs-target="#editEmployeeModal-{{ $emp->id }}"
                                        aria-label="Edit data {{ $emp->name }}"
                                    >
                                        <x-ui.icon name="pencil" size="sm" class="tw-shrink-0" />
                                        <span>Edit</span>
                                    </button>
                                    <form
                                        method="POST"
                                        action="{{ route('ga.employees.toggle-status', $emp) }}"
                                        class="tw-inline"
                                        onsubmit="event.preventDefault(); {{ $emp->is_active ? 'window.AdasiAlert.confirmDanger' : 'window.AdasiAlert.confirm' }}({
                                            title: '{{ $emp->is_active ? 'Nonaktifkan Karyawan?' : 'Aktifkan Karyawan?' }}',
                                            text: '{{ $emp->is_active ? 'Karyawan ' . addslashes(e($emp->name)) . ' tidak akan dapat dipilih pada pengajuan klaim baru.' : 'Karyawan ' . addslashes(e($emp->name)) . ' akan diaktifkan kembali untuk pengajuan klaim.' }}',
                                            confirmText: '{{ $emp->is_active ? 'Ya, Nonaktifkan' : 'Ya, Aktifkan' }}',
                                            cancelText: 'Batal'
                                        }).then(r => { if (r.isConfirmed) this.submit(); });"
                                    >
                                        @csrf
                                        <button
                                            type="submit"
                                            class="ui-data-action {{ $emp->is_active ? 'ui-data-action--danger' : 'ui-data-action--success' }} ui-motion ui-focus-ring tw-gap-1.5 active:tw-scale-[0.98]"
                                            aria-label="{{ $emp->is_active ? 'Nonaktifkan ' : 'Aktifkan ' }}{{ $emp->name }}"
                                        >
                                            <x-ui.icon name="{{ $emp->is_active ? 'user-x' : 'user-check' }}" size="sm" class="tw-shrink-0" />
                                            <span>{{ $emp->is_active ? 'Nonaktifkan' : 'Aktifkan' }}</span>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>

                        {{-- Edit Modal --}}
                        <div class="modal fade" id="editEmployeeModal-{{ $emp->id }}" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog">
                                <form method="POST" action="{{ route('ga.employees.update', $emp) }}">
                                    @csrf
                                    @method('PUT')
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title tw-text-ui-sm tw-font-bold">Edit Data Karyawan</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body tw-space-y-3 tw-text-ui-xs">
                                            <div>
                                                <label class="form-label tw-font-semibold">Nama Lengkap</label>
                                                <input type="text" name="name" class="form-control form-control-sm" value="{{ $emp->name }}" required>
                                            </div>
                                            <div>
                                                <label class="form-label tw-font-semibold">Departemen</label>
                                                <input type="text" name="department" class="form-control form-control-sm" value="{{ $emp->department }}" required>
                                            </div>
                                            <div>
                                                <x-ui.searchable-select
                                                    name="bank_name"
                                                    id="bank_name_edit_{{ $emp->id }}"
                                                    label="Nama Bank"
                                                    placeholder="Pilih atau cari bank..."
                                                    search-placeholder="Ketik nama bank..."
                                                    :options="\App\Support\BankList::options(old('bank_name', $emp->bank_name))"
                                                    :value="old('bank_name', $emp->bank_name)"
                                                    required
                                                />
                                            </div>
                                            <div>
                                                <label class="form-label tw-font-semibold">Nomor Rekening</label>
                                                <input type="text" name="account_number" class="form-control form-control-sm" value="{{ $emp->account_number }}" required>
                                            </div>
                                            <div>
                                                <label class="form-label tw-font-semibold">Nama Pemilik Rekening</label>
                                                <input type="text" name="account_holder_name" class="form-control form-control-sm" value="{{ $emp->account_holder_name }}" required>
                                            </div>
                                            <div>
                                                <label class="form-label tw-font-semibold">Status Karyawan</label>
                                                <select name="is_active" class="form-select form-select-sm">
                                                    <option value="1" @selected($emp->is_active)>Aktif (Bisa dipilih untuk klaim)</option>
                                                    <option value="0" @selected(! $emp->is_active)>Non-aktif</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="modal-footer tw-gap-2">
                                            <x-ui.button type="button" variant="ghost" size="sm" data-bs-dismiss="modal">
                                                <span>Batal</span>
                                            </x-ui.button>
                                            <x-ui.button type="submit" variant="primary" size="sm">
                                                <span>Simpan Perubahan</span>
                                            </x-ui.button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center tw-py-8 tw-text-on-surface-variant">
                                Belum ada data karyawan terdaftar.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($employees->hasPages())
            <x-slot:pagination>
                {{ $employees->onEachSide(1)->links() }}
            </x-slot:pagination>
        @endif
    </x-ui.data-table>
</div>

{{-- Add Employee Modal --}}
<div class="modal fade" id="addEmployeeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('ga.employees.store') }}">
            @csrf
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title tw-text-ui-sm tw-font-bold">Tambah Karyawan Baru (Employee Master)</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body tw-space-y-3 tw-text-ui-xs">
                    <div>
                        <label class="form-label tw-font-semibold">Nama Lengkap <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control form-control-sm" required placeholder="Contoh: Ahmad Fauzi">
                    </div>
                    <div>
                        <label class="form-label tw-font-semibold">Departemen <span class="text-danger">*</span></label>
                        <input type="text" name="department" class="form-control form-control-sm" required placeholder="Contoh: Sales / GA / IT">
                    </div>
                    <div>
                        <x-ui.searchable-select
                            name="bank_name"
                            id="bank_name_add"
                            label="Nama Bank"
                            placeholder="Pilih atau cari bank..."
                            search-placeholder="Ketik nama bank..."
                            :options="\App\Support\BankList::options(old('bank_name'))"
                            :value="old('bank_name')"
                            required
                        />
                    </div>
                    <div>
                        <label class="form-label tw-font-semibold">Nomor Rekening <span class="text-danger">*</span></label>
                        <input type="text" name="account_number" class="form-control form-control-sm" required placeholder="Nomor rekening">
                    </div>
                    <div>
                        <label class="form-label tw-font-semibold">Nama Pemilik Rekening <span class="text-danger">*</span></label>
                        <input type="text" name="account_holder_name" class="form-control form-control-sm" required placeholder="Sesuai buku tabungan">
                    </div>
                </div>
                <div class="modal-footer tw-gap-2">
                    <x-ui.button type="button" variant="ghost" size="sm" data-bs-dismiss="modal">
                        <span>Batal</span>
                    </x-ui.button>
                    <x-ui.button type="submit" variant="primary" size="sm">
                        <x-ui.icon name="plus" size="sm" />
                        <span>Simpan Karyawan</span>
                    </x-ui.button>
                </div>
            </div>
        </form>
    </div>
</div>
@endsection
