@extends('layouts.app')
@section('title', 'Detail Batch DRP '.$batch->batch_number.' - Purchasing')
@section('page-title', 'Detail Batch Rencana Pembayaran')

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        :title="'Batch DRP '.$batch->batch_number"
        :description="'Tipe: '.$batch->batch_type.' — Dibuat: '.$batch->created_at->format('d M Y H:i').' oleh '.($batch->creator?->name ?? 'System')"
        eyebrow="Purchasing & Procurement"
    >
        <x-slot:actions>
            <x-ui.button :href="\App\Support\PurchasingNavigation::backUrl($batch->batch_type === 'SUPPLIER' ? 'purchasing.drp.supplier' : 'purchasing.drp.ga')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>Kembali ke Daftar</span>
            </x-ui.button>
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
                                                                <span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-1.5 tw-py-0.5 tw-rounded tw-text-[10px] tw-font-semibold tw-bg-amber-100 tw-text-amber-800 dark:tw-bg-amber-950 dark:tw-text-amber-300 tw-w-fit"
                                                                      title="Kelebihan bayar belum direfund oleh supplier">
                                                                    <x-ui.icon name="alert-circle" size="xs" />
                                                                    <span>Overpayment: Rp {{ number_format($item->localInvoicePayment->overpayment->overpayment_amount, 0, ',', '.') }} (Open)</span>
                                                                </span>
                                                            @elseif($item->localInvoicePayment->overpayment->status === \App\Models\SupplierOverpaymentRefund::STATUS_SETTLED)
                                                                <span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-1.5 tw-py-0.5 tw-rounded tw-text-[10px] tw-font-semibold tw-bg-emerald-100 tw-text-emerald-800 dark:tw-bg-emerald-950 dark:tw-text-emerald-300 tw-w-fit"
                                                                      title="Kelebihan bayar telah diselesaikan">
                                                                    <x-ui.icon name="check-circle" size="xs" />
                                                                    <span>Overpayment Selesai (Rp {{ number_format($item->localInvoicePayment->overpayment->overpayment_amount, 0, ',', '.') }})</span>
                                                                </span>
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
                                        @if($item->localInvoiceVoucher)
                                            <x-ui.button :href="route('purchasing.vouchers.print', $item->localInvoiceVoucher)" variant="outline" size="sm" target="_blank" title="Cetak / Download PDF Voucher">
                                                <x-ui.icon name="printer" size="sm" />
                                                <span>Cetak PDF</span>
                                            </x-ui.button>
                                        @else
                                            <span class="tw-text-ui-xs tw-text-on-surface-variant">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        @endforeach
    </div>
</div>
@endsection
