@extends('layouts.app')
@section('title', 'DRP GA - Purchasing')
@section('page-title', 'Daftar Rencana Pembayaran (DRP) General Affairs')

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        title="DRP General Affairs (GA)"
        description="Monitoring status pengajuan dan realisasi transfer batch klaim operasional General Affairs."
        eyebrow="Purchasing & Procurement"
    >
        <x-slot:actions>
            <x-ui.button :href="\App\Support\PurchasingNavigation::listUrl('purchasing.dashboard')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>Dashboard</span>
            </x-ui.button>
            <x-ui.button :href="\App\Support\PurchasingNavigation::listUrl('purchasing.drp.supplier')" variant="outline" size="sm">
                <x-ui.icon name="wallet" size="sm" />
                <span>Buka DRP Supplier</span>
            </x-ui.button>
            <x-ui.button :href="\App\Support\PurchasingNavigation::listUrl('purchasing.drp.paid.index')" variant="outline" size="sm">
                <x-ui.icon name="badge-check" size="sm" />
                <span>Buka DRP Paid</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.alert tone="info" title="Monitoring DRP GA">
        Halaman ini menampilkan riwayat dan status batch klaim General Affairs (GA) yang diajukan ke tim Finance. Biaya admin bank untuk DRP GA selalu <strong>Rp 0</strong>. Eksekusi dan penandaan lunas dikelola oleh Finance melalui DRP Paid.
    </x-ui.alert>

    <x-ui.data-table
        title="Daftar Batch DRP General Affairs"
        description="Seluruh pengajuan batch DRP GA yang tercatat di sistem."
    >
        <div class="table-responsive">
            <table class="table table-hover align-middle tw-m-0 tw-text-ui-sm w-100">
                <thead class="table-light">
                    <tr>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">Batch Number</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">Tanggal</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">Jumlah Karyawan</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">Total Nominal (Rp)</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">Status</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($batches as $batch)
                        <tr>
                            <td>
                                <strong class="tw-font-mono tw-text-on-surface">{{ $batch->batch_number }}</strong>
                            </td>
                            <td>{{ $batch->created_at?->format('d M Y') }}</td>
                            <td>{{ $batch->groups_count }} karyawan</td>
                            <td class="text-end tw-font-mono tw-font-semibold tw-text-primary">
                                Rp {{ number_format($batch->total_subtotal, 0, ',', '.') }}
                            </td>
                            <td>
                                <x-ui.status-chip :tone="\App\Support\StatusHelper::localFinanceTone($batch->status)">
                                    {{ $batch->status }}
                                </x-ui.status-chip>
                            </td>
                            <td class="text-end">
                                <x-ui.button :href="route('purchasing.drp.show', $batch)" size="sm" variant="outline">
                                    <x-ui.icon name="eye" size="sm" />
                                    <span>Detail Batch</span>
                                </x-ui.button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center tw-py-8 tw-text-on-surface-variant tw-text-ui-sm">
                                Belum ada batch DRP GA yang tercatat.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($batches->hasPages())
            <x-slot:pagination>
                {{ $batches->links() }}
            </x-slot:pagination>
        @endif
    </x-ui.data-table>
</div>
@endsection
