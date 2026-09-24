@extends('layouts.app')
@section('title', 'DRP Supplier - Purchasing')
@section('page-title', 'Daftar Rencana Pembayaran (DRP) Supplier')

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        title="DRP Supplier (Daftar Rencana Pembayaran)"
        description="Monitoring batch rencana pembayaran supplier, pengelompokan rekening tujuan, dan status penyelesaian transaksi."
        eyebrow="Purchasing & Procurement"
    >
        <x-slot:actions>
            <x-ui.button :href="\App\Support\PurchasingNavigation::listUrl('purchasing.dashboard')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>Dashboard</span>
            </x-ui.button>
            <x-ui.button :href="\App\Support\PurchasingNavigation::listUrl('purchasing.drp.ga')" variant="outline" size="sm">
                <x-ui.icon name="credit-card" size="sm" />
                <span>Buka DRP GA</span>
            </x-ui.button>
            <x-ui.button :href="\App\Support\PurchasingNavigation::listUrl('purchasing.drp.paid.index')" variant="outline" size="sm">
                <x-ui.icon name="badge-check" size="sm" />
                <span>Buka DRP Paid</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Daftar Batch DRP Supplier (Read-Only) --}}
    <x-ui.data-table
        title="Daftar Batch DRP Supplier"
        description="Monitoring riwayat dan status seluruh batch DRP Supplier yang terdaftar."
    >
        <div class="table-responsive">
            <table class="table table-hover align-middle tw-m-0 tw-text-ui-sm w-100">
                <thead class="table-light">
                    <tr>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">Batch Number</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">Tanggal</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">Grup Penerima</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">Total Nominal (Rp)</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">Total Fee Bank (Rp)</th>
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
                            <td>{{ $batch->groups_count }} rekening tujuan</td>
                            <td class="text-end tw-font-mono tw-font-semibold">
                                Rp {{ number_format($batch->total_subtotal, 0, ',', '.') }}
                            </td>
                            <td class="text-end tw-font-mono tw-text-on-surface-variant">
                                Rp {{ number_format($batch->total_bank_fee, 0, ',', '.') }}
                            </td>
                            <td>
                                <x-ui.status-chip :tone="\App\Support\StatusHelper::localFinanceTone($batch->status)">
                                    {{ $batch->status }}
                                </x-ui.status-chip>
                            </td>
                            <td class="text-end">
                                <x-ui.button :href="route('purchasing.drp.show', $batch)" size="sm" variant="outline">
                                    <x-ui.icon name="eye" size="sm" />
                                    <span>Buka Batch</span>
                                </x-ui.button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center tw-py-8 tw-text-on-surface-variant tw-text-ui-sm">
                                Belum ada batch DRP Supplier.
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
