@extends('layouts.app')
@section('title', 'Register Klaim GA - Finance AP')
@section('page-title', 'Register Klaim General Affairs')

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        title="Register Klaim General Affairs"
        description="Verifikasi dokumen dan kelayakan pembayaran pengajuan klaim operasional serta reimbursement dari General Affairs."
        eyebrow="Finance & Accounts Payable"
    >
        <x-slot:actions>
            <x-ui.button :href="route('finance.dashboard')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>Dashboard</span>
            </x-ui.button>
            <x-ui.button :href="route('finance.drp.ga')" variant="outline" size="sm">
                <x-ui.icon name="credit-card" size="sm" />
                <span>Kelola DRP GA</span>
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
        <form method="GET" action="{{ route('finance.ga-claims.index') }}" class="tw-flex tw-flex-col sm:tw-flex-row sm:tw-items-center tw-gap-3">
            <div class="tw-flex-1">
                <input
                    type="text"
                    name="search"
                    class="form-control form-control-sm"
                    placeholder="Cari no. klaim, nama karyawan, departemen..."
                    value="{{ request('search') }}"
                >
            </div>
            <div class="tw-w-44">
                <select name="status" class="form-select form-select-sm">
                    <option value="">Semua Status</option>
                    <option value="SUBMITTED" @selected(request('status') === 'SUBMITTED')>SUBMITTED</option>
                    <option value="BASIC_VERIFIED" @selected(request('status') === 'BASIC_VERIFIED')>BASIC_VERIFIED</option>
                    <option value="NEED_REVISION" @selected(request('status') === 'NEED_REVISION')>NEED_REVISION</option>
                    <option value="READY_TO_PAY" @selected(request('status') === 'READY_TO_PAY')>READY_TO_PAY</option>
                    <option value="PAID" @selected(request('status') === 'PAID')>PAID</option>
                </select>
            </div>
            <div class="tw-w-48">
                <select name="employee_id" class="form-select form-select-sm">
                    <option value="">Semua Karyawan</option>
                    @foreach($employees as $emp)
                        <option value="{{ $emp->id }}" @selected(request('employee_id') == $emp->id)>
                            {{ $emp->name }} ({{ $emp->department }})
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="tw-flex tw-gap-2">
                <x-ui.button type="submit" variant="primary" size="sm">
                    <x-ui.icon name="search" size="sm" />
                    <span>Filter</span>
                </x-ui.button>
                @if(request()->hasAny(['search', 'status', 'employee_id']))
                    <x-ui.button :href="route('finance.ga-claims.index')" variant="ghost" size="sm">
                        <span>Reset</span>
                    </x-ui.button>
                @endif
            </div>
        </form>
    </x-ui.card>

    {{-- Data Table --}}
    <x-ui.data-table
        title="Daftar Pengajuan Klaim GA"
        description="Klaim berstatus BASIC_VERIFIED siap diverifikasi Finance untuk diteruskan ke tahap pembayaran (DRP GA)."
    >
        <div class="table-responsive">
            <table class="table table-hover align-middle tw-m-0 tw-text-ui-xs w-100">
                <thead class="table-light">
                    <tr>
                        <th scope="col">No. Klaim</th>
                        <th scope="col">Tanggal</th>
                        <th scope="col">Karyawan / Dept</th>
                        <th scope="col">Tipe Klaim</th>
                        <th scope="col">Nominal (Rp)</th>
                        <th scope="col">Tujuan Transfer</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($claims as $claim)
                        <tr>
                            <td><strong class="tw-font-mono tw-text-primary">{{ $claim->claim_number }}</strong></td>
                            <td>{{ $claim->claim_date?->format('d/m/Y') }}</td>
                            <td>
                                <strong class="tw-text-on-surface tw-block">{{ $claim->employee?->name }}</strong>
                                <span class="tw-text-on-surface-variant tw-text-[11px]">{{ $claim->employee?->department }}</span>
                            </td>
                            <td>
                                <span class="tw-inline-flex tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-semibold tw-bg-primary/10 tw-text-primary">
                                    {{ $claim->claim_type }}
                                </span>
                            </td>
                            <td><strong class="tw-font-mono tw-text-ui-sm">Rp {{ number_format($claim->amount, 0, ',', '.') }}</strong></td>
                            <td>
                                <span class="tw-font-semibold">{{ $claim->employee?->bank_name }}</span> ·
                                <span class="tw-font-mono">{{ $claim->employee?->account_number }}</span>
                                <span class="tw-block tw-text-[11px] tw-text-on-surface-variant">a.n {{ $claim->employee?->account_holder_name }}</span>
                            </td>
                            <td>
                                <x-ui.status-chip :tone="match($claim->status) { 'PAID' => 'success', 'READY_TO_PAY' => 'success', 'NEED_REVISION' => 'error', 'BASIC_VERIFIED' => 'info', default => 'warning' }">
                                    {{ $claim->status }}
                                </x-ui.status-chip>
                            </td>
                            <td class="text-end">
                                <x-ui.button :href="route('finance.ga-claims.show', $claim)" size="sm" variant="outline">
                                    <x-ui.icon name="eye" size="sm" />
                                    <span>Detail & Verifikasi</span>
                                </x-ui.button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center tw-py-8 tw-text-on-surface-variant">
                                Tidak ada pengajuan klaim GA yang sesuai.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($claims->hasPages())
            <x-slot:pagination>
                {{ $claims->links() }}
            </x-slot:pagination>
        @endif
    </x-ui.data-table>
</div>
@endsection
