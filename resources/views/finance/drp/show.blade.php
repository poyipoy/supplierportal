@extends('layouts.app')
@section('title', 'Detail Batch DRP '.$batch->batch_number.' - Finance AP')
@section('page-title', 'Detail Batch Rencana Pembayaran')

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        :title="'Batch DRP '.$batch->batch_number"
        :description="'Tipe: '.$batch->batch_type.' — Dibuat: '.$batch->created_at->format('d M Y H:i').' oleh '.($batch->creator?->name ?? 'System')"
        eyebrow="Unified Payment Engine"
    >
        <x-slot:actions>
            <x-ui.button :href="$batch->batch_type === 'SUPPLIER' ? route('finance.drp.supplier') : route('finance.drp.ga')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>Kembali ke Daftar</span>
            </x-ui.button>
            @if($batch->batch_type === \App\Models\PaymentBatch::TYPE_SUPPLIER && $batch->status !== \App\Models\PaymentBatch::STATUS_CANCELLED)
                <x-ui.button
                    :href="route('finance.drp.export', $batch)"
                    variant="outline"
                    size="sm"
                    data-async-export
                >
                    <x-ui.icon name="download" size="sm" />
                    <span>Export Excel</span>
                </x-ui.button>
            @endif
            @if($batch->status === \App\Models\PaymentBatch::STATUS_DRAFT)
                <button
                    type="button"
                    class="btn btn-outline-danger btn-sm"
                    data-bs-toggle="modal"
                    data-bs-target="#cancelBatchModal"
                >
                    <x-ui.icon name="x-circle" size="sm" />
                    <span>Batalkan Batch</span>
                </button>

                <form id="finalizeBatchForm" method="POST" action="{{ route('finance.drp.finalize', $batch) }}" class="tw-inline">
                    @csrf
                    <x-ui.button type="button" id="btnFinalizeBatch" variant="primary" size="sm">
                        <x-ui.icon name="lock" size="sm" />
                        <span>Finalisasi Batch (Lock DRP)</span>
                    </x-ui.button>
                </form>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Batch Summary Cards --}}
    <div class="tw-grid tw-grid-cols-2 sm:tw-grid-cols-3 {{ $batch->status === \App\Models\PaymentBatch::STATUS_PARTIALLY_PAID ? 'lg:tw-grid-cols-6' : 'md:tw-grid-cols-4' }} tw-gap-4">
        <x-ui.card>
            <span class="tw-text-ui-xs tw-text-on-surface-variant tw-block">Status Batch</span>
            <div class="tw-mt-1">
                <x-ui.status-chip :tone="\App\Support\StatusHelper::localFinanceTone($batch->status)">
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
            <span class="tw-text-ui-xs tw-text-on-surface-variant tw-block">Total Net DRP</span>
            <span class="tw-font-mono tw-font-bold tw-text-ui-base tw-text-on-surface tw-block tw-mt-1">
                Rp {{ number_format($batch->total_net_amount, 0, ',', '.') }}
            </span>
        </x-ui.card>
        @if($batch->status === \App\Models\PaymentBatch::STATUS_PARTIALLY_PAID)
            <x-ui.card class="tw-border-success/30 tw-bg-success/5">
                <span class="tw-text-ui-xs tw-text-success tw-font-semibold tw-block">Sudah Terbayar</span>
                <span class="tw-font-mono tw-font-bold tw-text-ui-base tw-text-success tw-block tw-mt-1">
                    Rp {{ number_format($batch->actual_paid_amount, 0, ',', '.') }}
                </span>
            </x-ui.card>
            <x-ui.card class="tw-border-warning/30 tw-bg-warning/5">
                <span class="tw-text-ui-xs tw-text-warning-container-foreground tw-font-semibold tw-block">Sisa Belum Lunas</span>
                <span class="tw-font-mono tw-font-bold tw-text-ui-base tw-text-warning-container-foreground tw-block tw-mt-1">
                    Rp {{ number_format($batch->remaining_amount, 0, ',', '.') }}
                </span>
            </x-ui.card>
        @endif
    </div>

    {{-- Centralized Payment Callout --}}
    @if(in_array($batch->status, [\App\Models\PaymentBatch::STATUS_FINALIZED, \App\Models\PaymentBatch::STATUS_PARTIALLY_PAID]))
        @php
            $unvoucheredCount = $batch->unvoucheredSupplierItemsCount();
        @endphp
        @if($unvoucheredCount > 0)
            <div class="tw-rounded-xl tw-border tw-border-warning/30 tw-bg-warning/10 tw-p-4">
                <div class="tw-flex tw-flex-col sm:tw-flex-row tw-items-start sm:tw-items-center tw-gap-3">
                    <div class="tw-flex tw-items-center tw-gap-2 tw-flex-1">
                        <x-ui.icon name="alert-triangle" size="sm" class="tw-text-warning-container-foreground tw-flex-shrink-0" />
                        <span class="tw-text-ui-xs tw-text-on-surface">
                            Terdapat <strong>{{ $unvoucheredCount }} tagihan</strong> yang belum memiliki Voucher Bayar. Harap klik tombol <strong>"Generate Voucher"</strong> pada setiap tagihan di bawah sebelum melakukan pelunasan pada menu DRP Paid.
                        </span>
                    </div>
                </div>
            </div>
        @else
            <div class="tw-rounded-xl tw-border tw-border-primary/20 tw-bg-primary/5 tw-p-4">
                <div class="tw-flex tw-flex-col sm:tw-flex-row tw-items-start sm:tw-items-center tw-gap-3">
                    <div class="tw-flex tw-items-center tw-gap-2 tw-flex-1">
                        <x-ui.icon name="info" size="sm" class="tw-text-primary tw-flex-shrink-0" />
                        <span class="tw-text-ui-xs tw-text-on-surface">Seluruh voucher telah diterbitkan. Pelunasan DRP diproses terpusat pada menu <strong>DRP Paid</strong>.</span>
                    </div>
                    <x-ui.button :href="route('finance.drp.paid.index', ['q' => $batch->batch_number])" size="sm" variant="primary">
                        <x-ui.icon name="external-link" size="sm" /> Buka DRP Paid
                    </x-ui.button>
                </div>
            </div>
        @endif
    @endif

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
                                @if($batch->batch_type === \App\Models\PaymentBatch::TYPE_GA)
                                    {{-- Assign Voucher Button --}}
                                    <button
                                        type="button"
                                        class="btn btn-outline-secondary btn-sm"
                                        data-bs-toggle="modal"
                                        data-bs-target="#voucherModal-{{ $group->id }}"
                                    >
                                        <x-ui.icon name="receipt" size="sm" />
                                        <span>Voucher</span>
                                    </button>

                                    {{-- Mark Paid Button (disabled — settlement centralized via DRP Paid) --}}
                                @endif

                                {{-- Fee Override (Draft only) --}}
                                @if($batch->status === \App\Models\PaymentBatch::STATUS_DRAFT && $batch->batch_type === \App\Models\PaymentBatch::TYPE_SUPPLIER)
                                    <button
                                        type="button"
                                        class="btn btn-outline-warning btn-sm"
                                        data-bs-toggle="modal"
                                        data-bs-target="#feeModal-{{ $group->id }}"
                                    >
                                        <x-ui.icon name="edit-3" size="sm" />
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
                                <th scope="col" class="text-end">Aksi</th>
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
                                        @if($item->status === 'ACTIVE')
                                            @if($item->localInvoicePayment)
                                                @if($item->localInvoicePayment->status === \App\Models\LocalInvoicePayment::STATUS_FINALIZED)
                                                    <div class="tw-flex tw-flex-col tw-gap-1">
                                                        <span class="tw-inline-flex tw-px-1.5 tw-py-0.5 tw-rounded tw-text-[10px] tw-font-semibold tw-bg-success/10 tw-text-success tw-w-fit">
                                                            Lunas (Rp {{ number_format($item->localInvoicePayment->actual_paid_total, 0, ',', '.') }})
                                                        </span>
                                                        @if($item->localInvoicePayment->overpayment)
                                                            @if($item->localInvoicePayment->overpayment->status === \App\Models\SupplierOverpaymentRefund::STATUS_OPEN)
                                                                <a href="{{ route('finance.overpayments.index', ['q' => $item->payable?->invoice_number ?? $item->item_reference]) }}"
                                                                   class="tw-inline-flex tw-items-center tw-gap-1 tw-px-1.5 tw-py-0.5 tw-rounded tw-text-[10px] tw-font-semibold tw-bg-amber-100 tw-text-amber-800 dark:tw-bg-amber-950 dark:tw-text-amber-300 tw-w-fit tw-no-underline hover:tw-underline"
                                                                   title="Kelebihan bayar belum direfund oleh supplier">
                                                                    <x-ui.icon name="alert-circle" size="xs" />
                                                                    <span>Overpayment: Rp {{ number_format($item->localInvoicePayment->overpayment->overpayment_amount, 0, ',', '.') }} (Open)</span>
                                                                </a>
                                                            @elseif($item->localInvoicePayment->overpayment->status === \App\Models\SupplierOverpaymentRefund::STATUS_SETTLED)
                                                                <a href="{{ route('finance.overpayments.index', ['q' => $item->payable?->invoice_number ?? $item->item_reference]) }}"
                                                                   class="tw-inline-flex tw-items-center tw-gap-1 tw-px-1.5 tw-py-0.5 tw-rounded tw-text-[10px] tw-font-semibold tw-bg-emerald-100 tw-text-emerald-800 dark:tw-bg-emerald-950 dark:tw-text-emerald-300 tw-w-fit tw-no-underline hover:tw-underline"
                                                                   title="Kelebihan bayar telah diselesaikan">
                                                                    <x-ui.icon name="check-circle" size="xs" />
                                                                    <span>Overpayment Selesai (Rp {{ number_format($item->localInvoicePayment->overpayment->overpayment_amount, 0, ',', '.') }})</span>
                                                                </a>
                                                            @endif
                                                        @endif
                                                    </div>
                                                @elseif($item->localInvoicePayment->status === \App\Models\LocalInvoicePayment::STATUS_CORRECTION_REQUIRED)
                                                    <div class="tw-flex tw-flex-col tw-gap-0.5">
                                                        <span class="tw-inline-flex tw-px-1.5 tw-py-0.5 tw-rounded tw-text-[10px] tw-font-semibold tw-bg-warning/10 tw-text-warning-container-foreground tw-w-fit">
                                                            Kurang Bayar
                                                        </span>
                                                        <span class="tw-text-[10px] tw-font-mono tw-text-on-surface-variant">
                                                            Terbayar: Rp {{ number_format($item->localInvoicePayment->actual_paid_total, 0, ',', '.') }}
                                                        </span>
                                                        <span class="tw-text-[10px] tw-font-mono tw-text-warning-container-foreground tw-font-semibold">
                                                            Sisa: Rp {{ number_format(max(0, (float)$item->localInvoicePayment->expected_amount - (float)$item->localInvoicePayment->actual_paid_total), 0, ',', '.') }}
                                                        </span>
                                                    </div>
                                                @else
                                                    <span class="tw-inline-flex tw-px-1.5 tw-py-0.5 tw-rounded tw-text-[10px] tw-bg-primary/10 tw-text-primary">
                                                        {{ $item->localInvoicePayment->status }}
                                                    </span>
                                                @endif
                                            @else
                                                <span class="tw-inline-flex tw-px-1.5 tw-py-0.5 tw-rounded tw-text-[10px] tw-bg-success/10 tw-text-success">
                                                    ACTIVE
                                                </span>
                                            @endif
                                        @else
                                            <span class="tw-inline-flex tw-px-1.5 tw-py-0.5 tw-rounded tw-text-[10px] tw-bg-error/10 tw-text-error">
                                                {{ $item->status }}
                                            </span>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        @if($batch->batch_type === \App\Models\PaymentBatch::TYPE_SUPPLIER && $item->status === \App\Models\PaymentItem::STATUS_ACTIVE)
                                            @if($item->localInvoiceVoucher)
                                                <div class="tw-inline-flex tw-items-center tw-gap-1.5">
                                                    @if($item->localInvoicePayment?->overpayment)
                                                        <x-ui.button :href="route('finance.overpayments.index', ['q' => $item->payable?->invoice_number ?? $item->item_reference])" variant="outline" size="sm" title="Buka Refund Overpayment">
                                                            <x-ui.icon name="corner-up-left" size="sm" class="tw-text-amber-600" />
                                                            <span>Refund</span>
                                                        </x-ui.button>
                                                    @endif
                                                    <x-ui.button :href="route('finance.vouchers.print', $item->localInvoiceVoucher)" variant="outline" size="sm" target="_blank" title="Cetak / Download PDF Voucher">
                                                        <x-ui.icon name="printer" size="sm" />
                                                        <span>Cetak PDF</span>
                                                    </x-ui.button>
                                                    <x-ui.button :href="route('finance.vouchers.show', $item->localInvoiceVoucher)" variant="outline" size="sm" title="Lihat Detail Voucher & Settlement">
                                                        <x-ui.icon name="receipt" size="sm" />
                                                        <span>Settlement</span>
                                                    </x-ui.button>
                                                </div>
                                            @elseif(in_array($batch->status, [\App\Models\PaymentBatch::STATUS_FINALIZED, \App\Models\PaymentBatch::STATUS_PARTIALLY_PAID]))
                                                <form method="POST" action="{{ route('finance.vouchers.generate', $item) }}" class="tw-inline-flex tw-items-center tw-gap-2">
                                                    @csrf
                                                    <input type="hidden" name="voucher_date" value="{{ now()->format('Y-m-d') }}">
                                                    <input type="hidden" name="payment_method" value="BANK">
                                                    <x-ui.button type="submit" size="sm" variant="primary">
                                                        <x-ui.icon name="file-text" size="sm" />
                                                        <span>Generate Voucher</span>
                                                    </x-ui.button>
                                                </form>
                                            @endif
                                        @elseif($batch->status === \App\Models\PaymentBatch::STATUS_DRAFT)
                                            <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#removeItemModal-{{ $item->id }}">Remove</button>
                                        @endif
                                    </td>
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
                @if($batch->batch_type === \App\Models\PaymentBatch::TYPE_GA)
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

                {{-- Mark Paid Modal removed — settlement centralized via DRP Paid --}}
                @endif

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

    {{-- Cancel Batch Modal --}}
    @if($batch->status === \App\Models\PaymentBatch::STATUS_DRAFT)
        <div class="modal fade" id="cancelBatchModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <form method="POST" action="{{ route('finance.drp.cancel', $batch) }}">
                    @csrf
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title tw-text-ui-sm tw-font-bold text-danger">Batalkan Batch DRP</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <p class="tw-text-ui-xs tw-text-on-surface-variant">
                                Apakah Anda yakin ingin membatalkan batch DRP <strong>#{{ $batch->batch_number }}</strong>?
                                Seluruh tagihan di dalam batch ini akan dikeluarkan dan dikembalikan ke antrean Ready to Pay.
                            </p>
                            <div class="mb-3">
                                <label class="form-label tw-text-ui-xs tw-font-semibold">Alasan Pembatalan Batch (Wajib) <span class="text-danger">*</span></label>
                                <textarea name="reason" class="form-control form-control-sm" rows="3" required placeholder="Contoh: Kesalahan pemilihan tagihan atau perubahan jadwal pembayaran..."></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                            <button type="submit" class="btn btn-danger btn-sm">Ya, Batalkan Batch</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const btnFinalize = document.getElementById('btnFinalizeBatch');
    const formFinalize = document.getElementById('finalizeBatchForm');

    if (btnFinalize && formFinalize) {
        btnFinalize.addEventListener('click', function (e) {
            e.preventDefault();

            const title = 'Finalisasi Batch DRP?';
            const text = 'Apakah Anda yakin ingin memfinalisasi batch DRP ini? Setelah difinalisasi, keanggotaan invoice dan biaya transfer akan dikunci!';
            const confirmText = 'Ya, Finalisasi Batch';
            const cancelText = 'Batal';

            const proceedSubmit = () => {
                if (window.AdasiButton && typeof window.AdasiButton.startLoading === 'function') {
                    window.AdasiButton.startLoading(btnFinalize, { text: 'Memfinalisasi...' });
                } else {
                    btnFinalize.disabled = true;
                    btnFinalize.classList.add('disabled', 'tw-opacity-75');
                    btnFinalize.innerHTML = '<span class="spinner-border spinner-border-sm me-1.5" role="status" aria-hidden="true"></span><span>Memfinalisasi...</span>';
                }
                formFinalize.submit();
            };

            if (window.AdasiAlert && typeof window.AdasiAlert.confirm === 'function') {
                window.AdasiAlert.confirm({
                    title: title,
                    text: text,
                    confirmTone: 'primary',
                    confirmText: confirmText,
                    cancelText: cancelText,
                    type: 'warning',
                }).then((result) => {
                    if (result.isConfirmed) {
                        proceedSubmit();
                    }
                });
            } else if (confirm(`${title}\n\n${text}`)) {
                proceedSubmit();
            }
        });
    }
});
</script>
@endpush

