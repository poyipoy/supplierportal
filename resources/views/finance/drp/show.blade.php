@extends('layouts.app')
@section('title', 'Detail Batch DRP '.$batch->batch_number.' - Finance AP')
@section('page-title', 'Detail Batch Rencana Pembayaran')

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        :title="'Batch DRP #'.$batch->batch_number"
        :description="'Tipe: '.$batch->batch_type.' — Dibuat: '.$batch->created_at->format('d M Y H:i').' oleh '.($batch->creator?->name ?? 'System')"
        eyebrow="Unified Payment Engine"
    >
        <x-slot:actions>
            <x-ui.button :href="$batch->batch_type === 'SUPPLIER' ? route('finance.drp.supplier') : route('finance.drp.ga')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>Kembali ke Daftar</span>
            </x-ui.button>
            @if($batch->status === \App\Models\PaymentBatch::STATUS_DRAFT)
                <form method="POST" action="{{ route('finance.drp.finalize', $batch) }}" class="tw-inline" onsubmit="return confirm('Apakah Anda yakin ingin memfinalisasi batch DRP ini? Setelah difinalisasi, keanggotaan invoice dan biaya transfer akan dikunci!');">
                    @csrf
                    <x-ui.button type="submit" variant="primary" size="sm">
                        <x-ui.icon name="lock" size="xs" />
                        <span>Finalisasi Batch (Lock DRP)</span>
                    </x-ui.button>
                </form>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Batch Summary Cards --}}
    <div class="tw-grid tw-grid-cols-2 md:tw-grid-cols-4 tw-gap-4">
        <x-ui.card>
            <span class="tw-text-ui-xs tw-text-on-surface-variant tw-block">Status Batch</span>
            <div class="tw-mt-1">
                <x-ui.status-chip :tone="match($batch->status) { 'PAID' => 'success', 'PARTIALLY_PAID' => 'info', 'FINALIZED' => 'primary', default => 'warning' }">
                    {{ $batch->status }}
                </x-ui.status-chip>
            </div>
        </x-ui.card>
        <x-ui.card>
            <span class="tw-text-ui-xs tw-text-on-surface-variant tw-block">Total Bruto</span>
            <span class="tw-font-mono tw-font-bold tw-text-ui-base tw-text-on-surface tw-block tw-mt-1">
                Rp {{ number_format($batch->total_subtotal, 0, ',', '.') }}
            </span>
        </x-ui.card>
        <x-ui.card>
            <span class="tw-text-ui-xs tw-text-on-surface-variant tw-block">Total Biaya Bank</span>
            <span class="tw-font-mono tw-font-bold tw-text-ui-base tw-text-error tw-block tw-mt-1">
                Rp {{ number_format($batch->total_bank_fee, 0, ',', '.') }}
            </span>
        </x-ui.card>
        <x-ui.card>
            <span class="tw-text-ui-xs tw-text-on-surface-variant tw-block">Total Net Transfer</span>
            <span class="tw-font-mono tw-font-bold tw-text-ui-base tw-text-success tw-block tw-mt-1">
                Rp {{ number_format($batch->total_net_amount, 0, ',', '.') }}
            </span>
        </x-ui.card>
    </div>

    {{-- Groups & Items --}}
    <div class="tw-space-y-6">
        @foreach($batch->groups as $groupIndex => $group)
            <x-ui.card>
                <div class="tw-flex tw-flex-col md:tw-flex-row md:tw-items-center md:tw-justify-between tw-gap-4 tw-border-b tw-border-outline-variant tw-pb-4 tw-mb-4">
                    <div>
                        <div class="tw-flex tw-items-center tw-gap-2.5">
                            <h3 class="tw-text-ui-base tw-font-bold tw-text-on-surface tw-m-0">
                                {{ $group->payee_name }}
                            </h3>
                            <x-ui.status-chip :tone="$group->status === 'PAID' ? 'success' : ($group->status === 'CANCELLED' ? 'neutral' : 'warning')">
                                {{ $group->status }}
                            </x-ui.status-chip>
                        </div>
                        <span class="tw-text-ui-xs tw-text-on-surface-variant tw-block tw-mt-1">
                            Rekening: <strong>{{ $group->bank_name }}</strong> — <strong class="tw-font-mono">{{ $group->account_number }}</strong> a.n <strong>{{ $group->account_holder_name }}</strong>
                        </span>
                        @if($group->voucher_number)
                            <span class="tw-text-[11px] tw-text-primary tw-block tw-mt-0.5">
                                Voucher: <strong>{{ $group->voucher_number }}</strong> (Tgl: {{ $group->voucher_date?->format('d M Y') }})
                            </span>
                        @endif
                    </div>

                    <div class="tw-flex tw-flex-wrap tw-items-center tw-gap-4 text-end">
                        <div>
                            <span class="tw-text-[11px] tw-text-on-surface-variant tw-block">Net Transfer</span>
                            <span class="tw-font-mono tw-font-bold tw-text-ui-base tw-text-primary">
                                Rp {{ number_format($group->net_payment_amount, 0, ',', '.') }}
                            </span>
                            <span class="tw-text-[10px] tw-text-on-surface-variant tw-block">Fee: Rp {{ number_format($group->bank_fee, 0, ',', '.') }}</span>
                        </div>

                        {{-- Action Buttons per Group --}}
                        @if($group->status !== \App\Models\PaymentGroup::STATUS_CANCELLED)
                            <div class="tw-flex tw-items-center tw-gap-2">
                                {{-- Assign Voucher Button --}}
                                <button
                                    type="button"
                                    class="btn btn-outline-secondary btn-sm"
                                    data-bs-toggle="modal"
                                    data-bs-target="#voucherModal-{{ $group->id }}"
                                >
                                    <x-ui.icon name="receipt" size="xs" />
                                    <span>Voucher</span>
                                </button>

                                {{-- Mark Paid Button --}}
                                @if(in_array($batch->status, [\App\Models\PaymentBatch::STATUS_FINALIZED, \App\Models\PaymentBatch::STATUS_PARTIALLY_PAID]) && $group->status === \App\Models\PaymentGroup::STATUS_UNPAID)
                                    <button
                                        type="button"
                                        class="btn btn-success btn-sm"
                                        data-bs-toggle="modal"
                                        data-bs-target="#payModal-{{ $group->id }}"
                                    >
                                        <x-ui.icon name="check-circle" size="xs" />
                                        <span>Konfirmasi Bayar</span>
                                    </button>
                                @endif

                                {{-- Fee Override (Draft only) --}}
                                @if($batch->status === \App\Models\PaymentBatch::STATUS_DRAFT && $batch->batch_type === \App\Models\PaymentBatch::TYPE_SUPPLIER)
                                    <button
                                        type="button"
                                        class="btn btn-outline-warning btn-sm"
                                        data-bs-toggle="modal"
                                        data-bs-target="#feeModal-{{ $group->id }}"
                                    >
                                        <x-ui.icon name="edit-3" size="xs" />
                                        <span>Ubah Fee</span>
                                    </button>
                                @endif
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Terbilang info --}}
                <div class="tw-bg-surface-container tw-p-2.5 tw-rounded tw-text-ui-xs tw-text-on-surface tw-mb-4 tw-italic">
                    Terbilang: "{{ $voucherService->terbilang($group->net_payment_amount) }} Rupiah"
                </div>

                @if($group->status === 'PAID')
                    <div class="tw-bg-success/10 tw-border tw-border-success/20 tw-p-3 tw-rounded tw-text-ui-xs tw-mb-4">
                        <strong class="tw-text-success">Pembayaran Selesai:</strong>
                        No. Ref Transfer: <strong class="tw-font-mono">{{ $group->transfer_reference }}</strong>
                        — Tanggal: <strong>{{ $group->transfer_date?->format('d M Y') }}</strong>
                        @if($group->payment_notes)
                            — Catatan: <em>{{ $group->payment_notes }}</em>
                        @endif
                    </div>
                @endif

                {{-- Group Items Table --}}
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle tw-m-0 tw-text-ui-xs w-100">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">No. Referensi Dokumen</th>
                                <th scope="col">Deskripsi / PO</th>
                                <th scope="col" class="text-end">Nominal DPP (Rp)</th>
                                <th scope="col" class="text-end">PPN (Rp)</th>
                                <th scope="col" class="text-end">Total Tagihan (Rp)</th>
                                <th scope="col">Status Item</th>
                                @if($batch->status === \App\Models\PaymentBatch::STATUS_DRAFT)
                                    <th scope="col" class="text-end">Aksi</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($group->items as $item)
                                <tr>
                                    <td>
                                        <strong class="tw-font-mono">{{ $item->item_reference }}</strong>
                                    </td>
                                    <td>
                                        @if($batch->batch_type === 'SUPPLIER' && $item->payable)
                                            PO: {{ $item->payable->po_number }} ({{ $item->payable->invoice_number }})
                                        @elseif($batch->batch_type === 'GA' && $item->payable)
                                            Klaim {{ $item->payable->claim_type }} ({{ $item->payable->employee?->name }})
                                        @else
                                            {{ $item->item_reference }}
                                        @endif
                                    </td>
                                    <td class="text-end tw-font-mono">Rp {{ number_format($item->subtotal_amount, 0, ',', '.') }}</td>
                                    <td class="text-end tw-font-mono">Rp {{ number_format($item->tax_amount, 0, ',', '.') }}</td>
                                    <td class="text-end tw-font-mono tw-font-bold tw-text-on-surface">
                                        Rp {{ number_format($item->total_amount, 0, ',', '.') }}
                                    </td>
                                    <td>
                                        <span class="tw-inline-flex tw-px-1.5 tw-py-0.5 tw-rounded tw-text-[10px] {{ $item->status === 'ACTIVE' ? 'tw-bg-success/10 tw-text-success' : 'tw-bg-error/10 tw-text-error' }}">
                                            {{ $item->status }}
                                        </span>
                                    </td>
                                    @if($batch->status === \App\Models\PaymentBatch::STATUS_DRAFT)
                                        <td class="text-end">
                                            @if($item->status === 'ACTIVE')
                                                <button
                                                    type="button"
                                                    class="btn btn-outline-danger btn-xs"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#removeItemModal-{{ $item->id }}"
                                                >
                                                    Hapus
                                                </button>
                                            @endif
                                        </td>
                                    @endif
                                </tr>

                                {{-- Remove Item Modal --}}
                                @if($batch->status === \App\Models\PaymentBatch::STATUS_DRAFT)
                                    <div class="modal fade" id="removeItemModal-{{ $item->id }}" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog">
                                            <form method="POST" action="{{ route('finance.drp.remove-item', $item) }}">
                                                @csrf
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title tw-text-ui-sm tw-font-bold">Keluarkan Tagihan dari DRP</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <p class="tw-text-ui-xs tw-text-on-surface-variant">
                                                            Tagihan <strong>{{ $item->item_reference }}</strong> akan dikeluarkan dari batch ini dan dikembalikan ke antrean Ready to Pay.
                                                        </p>
                                                        <div class="mb-3">
                                                            <label class="form-label tw-text-ui-xs tw-font-semibold">Alasan Pengeluaran (Wajib) <span class="text-danger">*</span></label>
                                                            <textarea name="reason" class="form-control form-control-sm" rows="2" required placeholder="Contoh: Menunggu konfirmasi kelengkapan fisik..."></textarea>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                                                        <button type="submit" class="btn btn-danger btn-sm">Keluarkan dari Batch</button>
                                                    </div>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- Modals for this Group: Assign Voucher, Mark Paid, Override Fee --}}
                {{-- 1. Voucher Modal --}}
                <div class="modal fade" id="voucherModal-{{ $group->id }}" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog">
                        <form method="POST" action="{{ route('finance.drp.assign-voucher', $group) }}">
                            @csrf
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title tw-text-ui-sm tw-font-bold">Penerbitan Voucher Bayar</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <label class="form-label tw-text-ui-xs tw-font-semibold">Nomor Voucher Bayar <span class="text-danger">*</span></label>
                                        <input type="text" name="voucher_number" class="form-control form-control-sm" value="{{ $group->voucher_number ?? 'VC/'.now()->format('ym').'/'.str_pad($group->id, 4, '0', STR_PAD_LEFT) }}" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label tw-text-ui-xs tw-font-semibold">Tanggal Voucher <span class="text-danger">*</span></label>
                                        <x-ui.date-picker
                                            id="voucher_date_{{ $group->id }}"
                                            name="voucher_date"
                                            :value="$group->voucher_date?->format('Y-m-d') ?? now()->format('Y-m-d')"
                                            required
                                        />
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Tutup</button>
                                    <button type="submit" class="btn btn-primary btn-sm">Simpan Data Voucher</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                {{-- 2. Mark Paid Modal --}}
                <div class="modal fade" id="payModal-{{ $group->id }}" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog">
                        <form method="POST" action="{{ route('finance.drp.mark-paid', $group) }}">
                            @csrf
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title tw-text-ui-sm tw-font-bold">Konfirmasi Eksekusi Transfer Bank</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="tw-p-2 tw-rounded tw-bg-surface-container tw-mb-3 tw-text-ui-xs">
                                        Penerima: <strong>{{ $group->payee_name }}</strong><br>
                                        Nominal Net: <strong class="tw-font-mono tw-text-primary">Rp {{ number_format($group->net_payment_amount, 0, ',', '.') }}</strong><br>
                                        Rekening: {{ $group->bank_name }} - {{ $group->account_number }} a.n {{ $group->account_holder_name }}
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label tw-text-ui-xs tw-font-semibold">Nomor Referensi Bank / Transfer <span class="text-danger">*</span></label>
                                        <input type="text" name="transfer_reference" class="form-control form-control-sm" placeholder="Contoh: TRF-BCA-9812739" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label tw-text-ui-xs tw-font-semibold">Tanggal Transfer <span class="text-danger">*</span></label>
                                        <x-ui.date-picker
                                            id="transfer_date_{{ $group->id }}"
                                            name="transfer_date"
                                            :value="now()->format('Y-m-d')"
                                            required
                                        />
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label tw-text-ui-xs tw-font-semibold">Catatan Pembayaran (Opsional)</label>
                                        <input type="text" name="payment_notes" class="form-control form-control-sm" placeholder="Catatan selisih / administrasi...">
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                                    <button type="submit" class="btn btn-success btn-sm">Konfirmasi Telah Dibayar (PAID)</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                {{-- 3. Override Fee Modal --}}
                @if($batch->status === \App\Models\PaymentBatch::STATUS_DRAFT && $batch->batch_type === \App\Models\PaymentBatch::TYPE_SUPPLIER)
                    <div class="modal fade" id="feeModal-{{ $group->id }}" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog">
                            <form method="POST" action="{{ route('finance.drp.override-fee', $group) }}">
                                @csrf
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title tw-text-ui-sm tw-font-bold">Penyesuaian Biaya Transfer Bank</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="mb-3">
                                            <label class="form-label tw-text-ui-xs tw-font-semibold">Nominal Biaya Bank (Rp) <span class="text-danger">*</span></label>
                                            <input type="number" step="0.01" name="bank_fee" class="form-control form-control-sm" value="{{ $group->bank_fee }}" required>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label tw-text-ui-xs tw-font-semibold">Alasan Penyesuaian (Wajib) <span class="text-danger">*</span></label>
                                            <textarea name="reason" class="form-control form-control-sm" rows="2" required placeholder="Contoh: Bebas biaya sesuai kesepakatan kerjasama..."></textarea>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                                        <button type="submit" class="btn btn-warning btn-sm">Simpan Perubahan Biaya</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                @endif
            </x-ui.card>
        @endforeach
    </div>
</div>
@endsection
