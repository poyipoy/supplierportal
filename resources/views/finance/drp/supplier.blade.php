@extends('layouts.app')
@section('title', 'DRP Supplier - Finance AP')
@section('page-title', 'Daftar Rencana Pembayaran (DRP) Supplier')

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        title="DRP Supplier (Daftar Rencana Pembayaran)"
        description="Kelola batch rencana pembayaran supplier, pengelompokan rekening tujuan, penyesuaian biaya transfer bank, dan penerbitan voucher bayar."
        eyebrow="Finance & Accounts Payable"
    >
        <x-slot:actions>
            <x-ui.button :href="route('finance.dashboard')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>Dashboard</span>
            </x-ui.button>
            <x-ui.button :href="route('finance.drp.ga')" variant="outline" size="sm">
                <x-ui.icon name="credit-card" size="sm" />
                <span>Buka DRP GA</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Form Buat Batch DRP Baru dari Tagihan Ready to Pay --}}
    <x-ui.card
        title="Buat Batch DRP Supplier Baru"
        description="Pilih tagihan invoice berstatus Ready to Pay yang belum masuk dalam batch aktif (Draft/Finalized)."
    >
        <form method="POST" action="{{ route('finance.drp.supplier.create') }}">
            @csrf
            <div class="tw-space-y-4">
                <div class="table-responsive">
                    <table class="table table-hover align-middle tw-m-0 tw-text-ui-sm w-100">
                        <thead class="table-light">
                            <tr>
                                <th scope="col" style="width: 40px;">Pilih</th>
                                <th scope="col" class="tw-text-ui-xs tw-font-semibold">Supplier / Rekening</th>
                                <th scope="col" class="tw-text-ui-xs tw-font-semibold">Nomor Invoice / PO</th>
                                <th scope="col" class="tw-text-ui-xs tw-font-semibold">Jatuh Tempo</th>
                                <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">DPP (Rp)</th>
                                <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">PPN (Rp)</th>
                                <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">Total Bayar (Rp)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($eligibleInvoices as $inv)
                                @php
                                    $bank = $inv->supplier->supplier?->activeBankAccount;
                                    $totalNominal = $inv->invoice_amount + $inv->tax_amount;
                                @endphp
                                <tr>
                                    <td>
                                        <input type="checkbox" name="invoice_ids[]" value="{{ $inv->id }}" class="form-check-input">
                                    </td>
                                    <td>
                                        <strong class="tw-text-on-surface">{{ $inv->supplier->supplier?->company_name ?: $inv->supplier->name }}</strong>
                                        <span class="tw-block tw-text-ui-xs tw-text-on-surface-variant">
                                            {{ $bank ? "{$bank->bank_name} - {$bank->account_number} a.n {$bank->account_holder}" : 'Belum ada rekening aktif' }}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="tw-font-medium">{{ $inv->invoice_number }}</span>
                                        <span class="tw-block tw-text-ui-xs tw-text-on-surface-variant">PO: {{ $inv->po_number }}</span>
                                    </td>
                                    <td>
                                        <span class="tw-text-ui-xs {{ $inv->isOverdue() ? 'tw-text-error tw-font-bold' : '' }}">
                                            {{ $inv->due_date?->format('d M Y') ?? '—' }}
                                        </span>
                                    </td>
                                    <td class="text-end tw-font-mono">Rp {{ number_format($inv->invoice_amount, 0, ',', '.') }}</td>
                                    <td class="text-end tw-font-mono">Rp {{ number_format($inv->tax_amount, 0, ',', '.') }}</td>
                                    <td class="text-end tw-font-mono tw-font-bold tw-text-primary">
                                        Rp {{ number_format($totalNominal, 0, ',', '.') }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center tw-py-8 tw-text-on-surface-variant tw-text-ui-sm">
                                        Tidak ada tagihan supplier Ready to Pay yang tersedia saat ini.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if($eligibleInvoices->isNotEmpty())
                    <div class="tw-flex tw-flex-col sm:tw-flex-row sm:tw-items-center sm:tw-justify-between tw-gap-3 tw-border-t tw-border-outline-variant tw-pt-3">
                        <div class="tw-flex-1">
                            <input type="text" name="notes" class="form-control form-control-sm" placeholder="Catatan batch DRP (opsional)...">
                        </div>
                        <x-ui.button type="submit" variant="primary" size="sm">
                            <x-ui.icon name="plus" size="xs" />
                            <span>Buat Batch DRP Draft</span>
                        </x-ui.button>
                    </div>
                @endif
            </div>
        </form>
    </x-ui.card>

    {{-- Daftar Batch DRP Supplier --}}
    <x-ui.data-table
        title="Daftar Batch DRP Supplier"
        description="Riwayat dan status seluruh batch DRP Supplier yang terdaftar."
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
                            <td>{{ $batch->batch_date?->format('d M Y') }}</td>
                            <td>{{ $batch->groups_count }} rekening tujuan</td>
                            <td class="text-end tw-font-mono tw-font-semibold">
                                Rp {{ number_format($batch->total_amount, 0, ',', '.') }}
                            </td>
                            <td class="text-end tw-font-mono tw-text-on-surface-variant">
                                Rp {{ number_format($batch->total_bank_fee, 0, ',', '.') }}
                            </td>
                            <td>
                                <x-ui.status-chip :tone="match($batch->status) { 'PAID' => 'success', 'PARTIALLY_PAID' => 'info', 'FINALIZED' => 'primary', default => 'warning' }">
                                    {{ $batch->status }}
                                </x-ui.status-chip>
                            </td>
                            <td class="text-end">
                                <x-ui.button :href="route('finance.drp.show', $batch)" size="sm" variant="outline">
                                    <x-ui.icon name="eye" size="xs" />
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
