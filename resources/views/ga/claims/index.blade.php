@extends('layouts.app')
@section('title', 'Daftar Klaim GA - ADASI')
@section('page-title', 'Daftar Pengajuan Klaim GA')

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        title="Daftar Pengajuan Klaim General Affairs"
        description="Semua pengajuan klaim karyawan ADASI lintas tipe (Entertain Sales, UPD Sales, UPD GA, Reimburse) dan status verifikasi."
        eyebrow="General Affairs Operations"
    >
        <x-slot:actions>
            <x-ui.button :href="route('ga.dashboard')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>Dashboard</span>
            </x-ui.button>
            <x-ui.button :href="route('ga.claims.create')" variant="primary" size="sm">
                <x-ui.icon name="plus" size="sm" />
                <span>Ajukan Klaim Baru</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.data-table
        title="Register Klaim GA"
        :description="'Menampilkan '.$claims->total().' data klaim karyawan.'"
    >
        <div class="table-responsive">
            <table class="table table-hover align-middle tw-m-0 tw-text-ui-sm w-100">
                <thead class="table-light">
                    <tr>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">No. Klaim / Tanda Terima</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">Karyawan Penerima</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">Tipe Klaim</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">Tanggal</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">Nominal (Rp)</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">Status</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($claims as $c)
                        <tr>
                            <td>
                                <strong class="tw-font-mono tw-text-on-surface">{{ $c->claim_number }}</strong>
                                @if($c->receipt)
                                    <span class="tw-block tw-text-ui-xs tw-text-on-surface-variant">
                                        TT: {{ $c->receipt->receipt_number }}
                                    </span>
                                @endif
                            </td>
                            <td>
                                <strong class="tw-text-on-surface">{{ $c->employee?->name }}</strong>
                                <span class="tw-block tw-text-ui-xs tw-text-on-surface-variant">
                                    {{ $c->employee?->department }} — {{ $c->employee?->bank_name }} ({{ $c->employee?->account_number }})
                                </span>
                            </td>
                            <td>
                                <span class="tw-inline-flex tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-semibold tw-bg-primary/10 tw-text-primary">
                                    {{ $c->claim_type }}
                                </span>
                            </td>
                            <td>{{ $c->claim_date?->format('d M Y') }}</td>
                            <td class="text-end tw-font-mono tw-font-bold tw-text-on-surface">
                                Rp {{ number_format($c->amount, 0, ',', '.') }}
                            </td>
                            <td>
                                <x-ui.status-chip :tone="match($c->status) { 'PAID' => 'success', 'READY_TO_PAY' => 'success', 'NEED_REVISION' => 'error', 'BASIC_VERIFIED' => 'info', default => 'warning' }">
                                    {{ $c->status }}
                                </x-ui.status-chip>
                            </td>
                            <td class="text-end tw-whitespace-nowrap">
                                <x-ui.button :href="route('ga.claims.show', $c)" size="sm" variant="outline">
                                    <x-ui.icon name="eye" size="xs" />
                                    <span>Detail</span>
                                </x-ui.button>
                                @if($c->receipt)
                                    <a href="{{ route('ga.claims.receipt', $c) }}" target="_blank" class="btn btn-outline-secondary btn-sm" title="Cetak Tanda Terima">
                                        <x-ui.icon name="printer" size="xs" />
                                    </a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center tw-py-8 tw-text-on-surface-variant tw-text-ui-sm">
                                Tidak ada data klaim GA.
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
