@extends('layouts.app')
@section('title', 'GA Claims Dashboard - ADASI')
@section('page-title', 'General Affairs Dashboard')

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        title="General Affairs (GA) Dashboard"
        description="Kelola pengajuan klaim/reimbursement karyawan (Entertain Sales, UPD Sales, UPD GA, Reimburse), lakukan verifikasi berkas, dan siapkan draft DRP untuk Finance."
        eyebrow="Internal Operations"
    >
        <x-slot:actions>
            <x-ui.button :href="route('ga.claims.create')" variant="primary">
                <x-ui.icon name="plus" size="sm" />
                <span>Pengajuan Klaim Baru</span>
            </x-ui.button>
            <x-ui.button :href="route('ga.drp-draft')" variant="outline">
                <x-ui.icon name="wallet" size="sm" />
                <span>DRP GA Draft</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- KPI Cards --}}
    <div class="tw-grid tw-grid-cols-2 md:tw-grid-cols-5 tw-gap-4">
        <x-ui.metric-card
            label="Diajukan"
            :value="$kpis['submitted'] ?? 0"
            icon="file-text"
            tone="primary"
            :href="route('ga.claims.index', ['status' => 'SUBMITTED'])"
        />
        <x-ui.metric-card
            label="Verifikasi Dasar (GA)"
            :value="$kpis['basic_verified'] ?? 0"
            icon="clipboard-check"
            tone="info"
            :href="route('ga.claims.index', ['status' => 'BASIC_VERIFIED'])"
        />
        <x-ui.metric-card
            label="Ready to Pay"
            :value="$kpis['ready_to_pay'] ?? 0"
            icon="badge-check"
            tone="success"
            :href="route('ga.claims.index', ['status' => 'READY_TO_PAY'])"
        />
        <x-ui.metric-card
            label="Perlu Revisi"
            :value="$kpis['need_revision'] ?? 0"
            icon="file-edit"
            tone="error"
            :href="route('ga.claims.index', ['status' => 'NEED_REVISION'])"
        />
        <x-ui.metric-card
            label="Selesai Dibayar"
            :value="$kpis['paid'] ?? 0"
            icon="check-circle-2"
            tone="neutral"
            :href="route('ga.claims.index', ['status' => 'PAID'])"
        />
    </div>

    {{-- Recent Claims Table --}}
    <x-ui.data-table
        title="Daftar Klaim GA Terbaru"
        description="10 pengajuan klaim karyawan terakhir yang tercatat dalam sistem."
    >
        <x-slot:toolbar>
            <x-ui.button :href="route('ga.claims.index')" variant="ghost" size="sm">
                <span>Lihat Semua Klaim</span>
                <x-ui.icon name="arrow-right" size="sm" />
            </x-ui.button>
        </x-slot:toolbar>

        <div class="table-responsive">
            <table class="table table-hover align-middle tw-m-0 tw-text-ui-sm w-100">
                <thead class="table-light">
                    <tr>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">Nomor Klaim</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">Karyawan</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">Tipe Klaim</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">Tanggal</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">Nominal (Rp)</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">Status</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($recentClaims as $c)
                        <tr>
                            <td>
                                <strong class="tw-font-mono tw-text-on-surface">{{ $c->claim_number }}</strong>
                            </td>
                            <td>
                                <strong class="tw-text-on-surface">{{ $c->employee?->name }}</strong>
                                <span class="tw-block tw-text-ui-xs tw-text-on-surface-variant">{{ $c->employee?->department }}</span>
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
                            <td class="text-end">
                                <x-ui.button :href="route('ga.claims.show', $c)" size="sm" variant="outline">
                                    <x-ui.icon name="eye" size="xs" />
                                    <span>Detail</span>
                                </x-ui.button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center tw-py-8 tw-text-on-surface-variant tw-text-ui-sm">
                                Belum ada pengajuan klaim GA.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.data-table>
</div>
@endsection
