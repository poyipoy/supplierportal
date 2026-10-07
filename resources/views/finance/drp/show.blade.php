@extends('layouts.app')
@section('title', __('finance.drp_ui.detail_title', ['number' => $batch->batch_number]))
@section('page-title', __('finance.drp.payment_plan_detail'))

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        :title="__('finance.drp_ui.batch_title', ['number' => $batch->batch_number])"
        :description="__('finance.closure.batch_description', ['type' => __('finance.closure.batch_type_'.strtolower($batch->batch_type)), 'date' => $regionalFormatter->timestamp($batch->created_at, 'datetime'), 'name' => $batch->creator?->name ?? __('common.final_copy.system')])"
        :eyebrow="__('finance.drp_ui.engine')"
    >
        <x-slot:actions>
            <x-ui.button :href="$batch->batch_type === 'SUPPLIER' ? route('finance.drp.supplier') : route('finance.drp.ga')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>{{ __('local_invoice.form.back_list') }}</span>
            </x-ui.button>
            @if($batch->batch_type === \App\Models\PaymentBatch::TYPE_SUPPLIER && $batch->status !== \App\Models\PaymentBatch::STATUS_CANCELLED)
                <x-ui.button
                    :href="route('finance.drp.export', $batch)"
                    variant="outline"
                    size="sm"
                    data-async-export
                    data-export-source-singular="{{ __('exports.sources.batch') }}"
                    data-export-source-plural="{{ __('exports.sources.batches') }}"
                    data-export-source-count="1"
                    data-export-filtered="false"
                    data-export-row-label="{{ __('finance.copy_review.batch_rows') }}"
                    data-export-row-explanation="{{ __('finance.copy_review.batch_rows_help') }}"
                >
                    <x-ui.icon name="download" size="sm" />
                    <span>{{ __('exports.actions.recap') }}</span>
                </x-ui.button>
                <x-ui.button
                    type="button"
                    id="btnExportTransferSingle"
                    variant="primary"
                    size="sm"
                >
                    <x-ui.icon name="file-spreadsheet" size="sm" />
                    <span>{{ __('exports.actions.transfer') }}</span>
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
                    <span>{{ __('finance.drp.cancel') }}</span>
                </button>

                <form id="finalizeBatchForm" method="POST" action="{{ route('finance.drp.finalize', $batch) }}" class="tw-inline">
                    @csrf
                    <x-ui.button type="button" id="btnFinalizeBatch" variant="primary" size="sm">
                        <x-ui.icon name="lock" size="sm" />
                        <span>{{ __('finance.drp.finalize') }}</span>
                    </x-ui.button>
                </form>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Batch Summary Cards --}}
    <div class="tw-grid tw-grid-cols-2 sm:tw-grid-cols-3 {{ $batch->status === \App\Models\PaymentBatch::STATUS_PARTIALLY_PAID ? 'lg:tw-grid-cols-6' : 'md:tw-grid-cols-4' }} tw-gap-4">
        <x-ui.card>
            <span class="tw-text-ui-xs tw-text-on-surface-variant tw-block">{{ __('finance.drp.batch_status') }}</span>
            <div class="tw-mt-1">
                <x-ui.status-chip :tone="\App\Support\StatusHelper::paymentBatchTone($batch->status)">
                    {{ \App\Support\StatusHelper::localFinanceLabel($batch->status) }}
                </x-ui.status-chip>
            </div>
        </x-ui.card>
        <x-ui.card>
            <span class="tw-text-ui-xs tw-text-on-surface-variant tw-block">{{ __('finance.drp.total_gross') }}</span>
            <span class="tw-font-mono tw-font-bold tw-text-ui-base tw-text-on-surface tw-block tw-mt-1">
                Rp {{ number_format($batch->total_subtotal, 0, ',', '.') }}
            </span>
        </x-ui.card>
        <x-ui.card>
            <span class="tw-text-ui-xs tw-text-on-surface-variant tw-block">{{ __('finance.drp.total_bank_fee') }}</span>
            <span class="tw-font-mono tw-font-bold tw-text-ui-base tw-text-error tw-block tw-mt-1">
                Rp {{ number_format($batch->total_bank_fee, 0, ',', '.') }}
            </span>
        </x-ui.card>
        <x-ui.card>
            <span class="tw-text-ui-xs tw-text-on-surface-variant tw-block">{{ __('finance.drp.total_net') }}</span>
            <span class="tw-font-mono tw-font-bold tw-text-ui-base tw-text-on-surface tw-block tw-mt-1">
                Rp {{ number_format($batch->total_net_amount, 0, ',', '.') }}
            </span>
        </x-ui.card>
        @if($batch->status === \App\Models\PaymentBatch::STATUS_PARTIALLY_PAID)
            <x-ui.card class="tw-border-success/30 tw-bg-success/5">
                <span class="tw-text-ui-xs tw-text-success tw-font-semibold tw-block">{{ __('local_invoice.labels.paid') }}</span>
                <span class="tw-font-mono tw-font-bold tw-text-ui-base tw-text-success tw-block tw-mt-1">
                    Rp {{ number_format($batch->actual_paid_amount, 0, ',', '.') }}
                </span>
            </x-ui.card>
            <x-ui.card class="tw-border-warning/30 tw-bg-warning/5">
                <span class="tw-text-ui-xs tw-text-warning-container-foreground tw-font-semibold tw-block">{{ __('local_invoice.labels.remaining') }}</span>
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
                            {{ __('finance.drp_ui.missing_vouchers_help', ['count' => $unvoucheredCount]) }}
                        </span>
                    </div>
                </div>
            </div>
        @else
            <div class="tw-rounded-xl tw-border tw-border-primary/20 tw-bg-primary/5 tw-p-4">
                <div class="tw-flex tw-flex-col sm:tw-flex-row tw-items-start sm:tw-items-center tw-gap-3">
                    <div class="tw-flex tw-items-center tw-gap-2 tw-flex-1">
                        <x-ui.icon name="info" size="sm" class="tw-text-primary tw-flex-shrink-0" />
                        <span class="tw-text-ui-xs tw-text-on-surface">{{ __('finance.drp_ui.vouchers_ready', ['menu' => __('navigation.drp_paid')]) }}</span>
                    </div>
                    <x-ui.button :href="route('finance.drp.paid.index', ['q' => $batch->batch_number])" size="sm" variant="primary">
                        <x-ui.icon name="external-link" size="sm" /> {{ __('finance.drp.open_paid') }}
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
                            <x-ui.status-chip :tone="\App\Support\StatusHelper::localFinanceTone($group->status)">
                                {{ \App\Support\StatusHelper::localFinanceLabel($group->status) }}
                            </x-ui.status-chip>
                        </div>
                        <span class="tw-text-ui-xs tw-text-on-surface-variant tw-block tw-mt-1">
                            {{ __('finance.drp_ui.bank_details', ['bank' => $group->bank_name, 'account' => $group->account_number, 'holder' => $group->account_holder_name]) }}
                        </span>
                        @if($group->voucher_number)
                            <span class="tw-text-[11px] tw-text-primary tw-block tw-mt-0.5">
                                {{ __('finance.drp_ui.voucher_details', ['number' => $group->voucher_number, 'date' => $regionalFormatter->date($group->voucher_date, 'human')]) }}
                            </span>
                        @endif
                    </div>

                    <div class="tw-flex tw-flex-wrap tw-items-center tw-gap-4 text-end">
                        <div>
                            <span class="tw-text-[11px] tw-text-on-surface-variant tw-block">{{ __('finance.drp_surface.net_transfer') }}</span>
                            <span class="tw-font-mono tw-font-bold tw-text-ui-base tw-text-primary">
                                Rp {{ number_format($group->net_payment_amount, 0, ',', '.') }}
                            </span>
                            <span class="tw-text-[10px] tw-text-on-surface-variant tw-block">{{ __('finance.drp_ui.fee_amount', ['amount' => number_format($group->bank_fee, 0, ',', '.')]) }}</span>
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
                                        <span>{{ __('common.labels_review.voucher') }}</span>
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
                                        <span>{{ __('finance.payment.review_fee') }}</span>
                                    </button>
                                @endif
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Terbilang info --}}
                <div class="tw-bg-surface-container tw-p-2.5 tw-rounded tw-text-ui-xs tw-text-on-surface tw-mb-4 tw-italic">
                    {{ __('finance.drp_ui.amount_words', ['words' => $voucherService->terbilang($group->net_payment_amount)]) }}
                </div>

                @if($group->status === 'PAID')
                    <div class="tw-bg-success/10 tw-border tw-border-success/20 tw-p-3 tw-rounded tw-text-ui-xs tw-mb-4">
                        <strong class="tw-text-success">{{ __('finance.drp_ui.payment_completed') }}</strong>
                        {{ __('finance.drp_ui.transfer_details', ['reference' => $group->transfer_reference, 'date' => $regionalFormatter->date($group->transfer_date, 'human')]) }}
                        @if($group->payment_notes)
                            <em>{{ __('finance.drp_ui.payment_note', ['notes' => $group->payment_notes]) }}</em>
                        @endif
                    </div>
                @endif

                {{-- Group Items Table --}}
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle tw-m-0 tw-text-ui-xs w-100">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">{{ __('finance.drp_ui.document_reference') }}</th>
                                <th scope="col">{{ __('local_procurement.labels.po_description') }}</th>
                                <th scope="col" class="text-end">{{ __('finance.drp_ui.dpp_amount') }}</th>
                                <th scope="col" class="text-end">{{ __('finance.drp_ui.ppn_amount') }}</th>
                                <th scope="col" class="text-end">{{ __('local_invoice.labels.total_amount') }}</th>
                                <th scope="col">{{ __('finance.drp.item_status') }}</th>
                                <th scope="col" class="text-end">{{ __('local_invoice.labels.actions') }}</th>
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
                                            {{ __('finance.drp_ui.invoice_reference', ['invoice' => $item->payable->invoice_number, 'po' => $item->payable->po_number]) }}
                                        @elseif($batch->batch_type === 'GA' && $item->payable)
                                            {{ __('finance.drp_ui.claim_reference', ['type' => \App\Models\GaClaim::claimTypeLabel($item->payable->claim_type), 'employee' => $item->payable->employee?->name]) }}
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
                                                            {{ __('finance.drp_ui.settled_amount', ['amount' => number_format($item->localInvoicePayment->actual_paid_total, 0, ',', '.')]) }}
                                                        </span>
                                                        @if($item->localInvoicePayment->overpayment)
                                                            @if($item->localInvoicePayment->overpayment->status === \App\Models\SupplierOverpaymentRefund::STATUS_OPEN)
                                                                <a href="{{ route('finance.overpayments.index', ['q' => $item->payable?->invoice_number ?? $item->item_reference]) }}"
                                                                   class="tw-inline-flex tw-items-center tw-gap-1 tw-px-1.5 tw-py-0.5 tw-rounded tw-text-[10px] tw-font-semibold tw-bg-amber-100 tw-text-amber-800 dark:tw-bg-amber-950 dark:tw-text-amber-300 tw-w-fit tw-no-underline hover:tw-underline"
                                                                   title="{{ __('finance.drp_surface.not_refunded') }}">
                                                                    <x-ui.icon name="alert-circle" size="xs" />
                                                                    <span>{{ __('finance.paid_ui.overpayment', ['amount' => number_format($item->localInvoicePayment->overpayment->overpayment_amount, 0, ',', '.')]) }}</span>
                                                                </a>
                                                            @elseif($item->localInvoicePayment->overpayment->status === \App\Models\SupplierOverpaymentRefund::STATUS_SETTLED)
                                                                <a href="{{ route('finance.overpayments.index', ['q' => $item->payable?->invoice_number ?? $item->item_reference]) }}"
                                                                   class="tw-inline-flex tw-items-center tw-gap-1 tw-px-1.5 tw-py-0.5 tw-rounded tw-text-[10px] tw-font-semibold tw-bg-emerald-100 tw-text-emerald-800 dark:tw-bg-emerald-950 dark:tw-text-emerald-300 tw-w-fit tw-no-underline hover:tw-underline"
                                                                   title="{{ __('finance.drp_surface.refund_completed') }}">
                                                                    <x-ui.icon name="check-circle" size="xs" />
                                                                    <span>{{ __('finance.paid_ui.overpayment_settled', ['amount' => number_format($item->localInvoicePayment->overpayment->overpayment_amount, 0, ',', '.')]) }}</span>
                                                                </a>
                                                            @endif
                                                        @endif
                                                    </div>
                                                @elseif($item->localInvoicePayment->status === \App\Models\LocalInvoicePayment::STATUS_CORRECTION_REQUIRED)
                                                    <div class="tw-flex tw-flex-col tw-gap-0.5">
                                                        <span class="tw-inline-flex tw-px-1.5 tw-py-0.5 tw-rounded tw-text-[10px] tw-font-semibold tw-bg-warning/10 tw-text-warning-container-foreground tw-w-fit">
                                                            {{ __('finance.drp_ui.underpaid') }}
                                                        </span>
                                                        <span class="tw-text-[10px] tw-font-mono tw-text-on-surface-variant">
                                                            {{ __('finance.paid_ui.paid_amount', ['amount' => number_format($item->localInvoicePayment->actual_paid_total, 0, ',', '.')]) }}
                                                        </span>
                                                        <span class="tw-text-[10px] tw-font-mono tw-text-warning-container-foreground tw-font-semibold">
                                                            {{ __('finance.paid_ui.remaining', ['amount' => number_format(max(0, (float)$item->localInvoicePayment->expected_amount - (float)$item->localInvoicePayment->actual_paid_total), 0, ',', '.')]) }}
                                                        </span>
                                                    </div>
                                                @else
                                                    <x-ui.status-chip :tone="\App\Support\StatusHelper::localFinanceTone($item->localInvoicePayment->status)">
                                                        {{ \App\Support\StatusHelper::localFinanceLabel($item->localInvoicePayment->status) }}
                                                    </x-ui.status-chip>
                                                @endif
                                            @else
                                                <x-ui.status-chip :tone="\App\Support\StatusHelper::localFinanceTone($item->status)">
                                                    {{ \App\Support\StatusHelper::localFinanceLabel($item->status) }}
                                                </x-ui.status-chip>
                                            @endif
                                        @else
                                            <x-ui.status-chip :tone="\App\Support\StatusHelper::localFinanceTone($item->status)">
                                                {{ \App\Support\StatusHelper::localFinanceLabel($item->status) }}
                                            </x-ui.status-chip>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        @if($batch->batch_type === \App\Models\PaymentBatch::TYPE_SUPPLIER && $item->status === \App\Models\PaymentItem::STATUS_ACTIVE)
                                            @if($item->localInvoiceVoucher)
                                                <div class="tw-inline-flex tw-items-center tw-gap-1.5">
                                                    @if($item->localInvoicePayment?->overpayment)
                                                        <x-ui.button :href="route('finance.overpayments.index', ['q' => $item->payable?->invoice_number ?? $item->item_reference])" variant="outline" size="sm" :title="__('finance.refund.open_refund')">
                                                            <x-ui.icon name="corner-up-left" size="sm" class="tw-text-amber-600" />
                                                            <span>{{ __('terms.refund') }}</span>
                                                        </x-ui.button>
                                                    @endif
                                                    <x-ui.button :href="route('finance.vouchers.print', $item->localInvoiceVoucher)" variant="outline" size="sm" target="_blank" :title="__('finance.voucher.print')">
                                                        <x-ui.icon name="printer" size="sm" />
                                                        <span>{{ __('finance.voucher.print_pdf') }}</span>
                                                    </x-ui.button>
                                                    <x-ui.button :href="route('finance.vouchers.show', $item->localInvoiceVoucher)" variant="outline" size="sm" :title="__('finance.drp_surface.voucher_detail')">
                                                        <x-ui.icon name="receipt" size="sm" />
                                                        <span>{{ __('common.labels_review.settlement') }}</span>
                                                    </x-ui.button>
                                                </div>
                                            @elseif(in_array($batch->status, [\App\Models\PaymentBatch::STATUS_FINALIZED, \App\Models\PaymentBatch::STATUS_PARTIALLY_PAID]))
                                                <form method="POST" action="{{ route('finance.vouchers.generate', $item) }}" class="tw-inline-flex tw-items-center tw-gap-2">
                                                    @csrf
                                                    <input type="hidden" name="voucher_date" value="{{ now()->format('Y-m-d') }}">
                                                    <input type="hidden" name="payment_method" value="BANK">
                                                    <x-ui.button type="submit" size="sm" variant="primary">
                                                        <x-ui.icon name="file-text" size="sm" />
                                                        <span>{{ __('finance.voucher.create') }}</span>
                                                    </x-ui.button>
                                                </form>
                                            @endif
                                        @elseif($batch->status === \App\Models\PaymentBatch::STATUS_DRAFT)
                                            <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#removeItemModal-{{ $item->id }}">{{ __('common.actions.remove') }}</button>
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
                                                        <h5 class="modal-title tw-text-ui-sm tw-font-bold">{{ __('finance.drp_ui.remove_title') }}</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('local_invoice.actions.close') }}"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <p class="tw-text-ui-xs tw-text-on-surface-variant">
                                                            {{ __('finance.drp_ui.remove_help', ['reference' => $item->item_reference]) }}
                                                        </p>
                                                        <div class="mb-3">
                                                            <label class="form-label tw-text-ui-xs tw-font-semibold">{{ __('finance.drp_ui.remove_reason') }} <span class="text-danger">*</span></label>
                                                            <textarea name="reason" class="form-control form-control-sm" rows="2" required placeholder="{{ __('finance.drp_ui.remove_example') }}"></textarea>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">{{ __('local_invoice.actions.cancel') }}</button>
                                                        <button type="submit" class="btn btn-danger btn-sm">{{ __('finance.drp_ui.remove') }}</button>
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
                                    <h5 class="modal-title tw-text-ui-sm tw-font-bold">{{ __('finance.drp_ui.voucher_issue') }}</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('local_invoice.actions.close') }}"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <label class="form-label tw-text-ui-xs tw-font-semibold">{{ __('finance.drp_ui.voucher_number') }} <span class="text-danger">*</span></label>
                                        <input type="text" name="voucher_number" class="form-control form-control-sm" value="{{ $group->voucher_number ?? 'VC/'.now()->format('ym').'/'.str_pad($group->id, 4, '0', STR_PAD_LEFT) }}" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label tw-text-ui-xs tw-font-semibold">{{ __('finance.voucher.date') }} <span class="text-danger">*</span></label>
                                        <x-ui.date-picker
                                            id="voucher_date_{{ $group->id }}"
                                            name="voucher_date"
                                            :value="$group->voucher_date?->format('Y-m-d') ?? now()->format('Y-m-d')"
                                            required
                                        />
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">{{ __('local_invoice.actions.close') }}</button>
                                    <button type="submit" class="btn btn-primary btn-sm">{{ __('finance.voucher.save') }}</button>
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
                                        <h5 class="modal-title tw-text-ui-sm tw-font-bold">{{ __('finance.drp_ui.fee_adjustment') }}</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('local_invoice.actions.close') }}"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="mb-3">
                                            <label class="form-label tw-text-ui-xs tw-font-semibold">{{ __('finance.drp_ui.fee_value') }} <span class="text-danger">*</span></label>
                                            <input type="number" step="0.01" name="bank_fee" class="form-control form-control-sm" value="{{ $group->bank_fee }}" required>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label tw-text-ui-xs tw-font-semibold">{{ __('finance.payment.actual_reason') }} <span class="text-danger">*</span></label>
                                            <textarea name="reason" class="form-control form-control-sm" rows="2" required placeholder="{{ __('finance.drp_surface.fee_example') }}"></textarea>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">{{ __('local_invoice.actions.cancel') }}</button>
                                        <button type="submit" class="btn btn-warning btn-sm">{{ __('finance.payment.save_fee') }}</button>
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
                            <h5 class="modal-title tw-text-ui-sm tw-font-bold text-danger">{{ __('finance.drp.cancel_full') }}</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('local_invoice.actions.close') }}"></button>
                        </div>
                        <div class="modal-body">
                            <p class="tw-text-ui-xs tw-text-on-surface-variant">
                                {{ __('finance.drp_ui.cancel_help', ['number' => $batch->batch_number]) }}
                            </p>
                            <div class="mb-3">
                                <label class="form-label tw-text-ui-xs tw-font-semibold">{{ __('finance.drp.cancel_reason') }} <span class="text-danger">*</span></label>
                                <textarea name="reason" class="form-control form-control-sm" rows="3" required placeholder="{{ __('finance.drp_ui.cancel_example') }}"></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">{{ __('local_invoice.actions.cancel') }}</button>
                            <button type="submit" class="btn btn-danger btn-sm">{{ __('finance.drp_ui.cancel_yes') }}</button>
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

            const title = @js(__('finance.drp_ui.finalize_title'));
            const text = @js(__('finance.drp_ui.finalize_help'));
            const confirmText = @js(__('finance.drp_ui.finalize_yes'));
            const cancelText = @js(__('local_invoice.actions.cancel'));

            const proceedSubmit = () => {
                if (window.AdasiButton && typeof window.AdasiButton.startLoading === 'function') {
                    window.AdasiButton.startLoading(btnFinalize, { text: @js(__('finance.drp_ui.finalizing')) });
                } else {
                    btnFinalize.disabled = true;
                    btnFinalize.classList.add('disabled', 'tw-opacity-75');
                    btnFinalize.innerHTML = '<span class="spinner-border spinner-border-sm me-1.5" role="status" aria-hidden="true"></span><span></span>';
                    btnFinalize.lastElementChild.textContent = @js(__('finance.drp_ui.finalizing'));
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

    const btnExportTransferSingle = document.getElementById('btnExportTransferSingle');
    if (btnExportTransferSingle) {
        btnExportTransferSingle.addEventListener('click', function () {
            const doExport = () => {
                btnExportTransferSingle.disabled = true;

                fetch("{{ route('finance.drp.export-transfer') }}", {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '{{ csrf_token() }}',
                    },
                    body: JSON.stringify({ batch_ids: ['{{ $batch->hash }}'] }),
                })
                .then(res => {
                    if (!res.ok) return res.json().then(e => { throw e; });
                    return res.json();
                })
                .then(data => {
                    if (window.AdasiToast && typeof window.AdasiToast.success === 'function') {
                        AdasiToast.success(data.message || @js(__('finance.drp_ui.export_started')));
                    }

                    if (data.status_url) {
                        let attempts = 0;
                        const poll = () => {
                            attempts++;
                            if (attempts > 120) return;
                            fetch(data.status_url, { headers: { 'Accept': 'application/json' } })
                                .then(r => r.json())
                                .then(job => {
                                    if (job.status === 'completed' && job.download_url) {
                                        if (window.AdasiToast && typeof window.AdasiToast.success === 'function') {
                                            AdasiToast.success(@js(__('finance.drp_ui.export_finished')));
                                        }
                                        const a = document.createElement('a');
                                        a.href = job.download_url;
                                        a.download = job.file_name || 'DRP_TRANSFER.xlsx';
                                        document.body.appendChild(a);
                                        a.click();
                                        a.remove();
                                        btnExportTransferSingle.disabled = false;
                                    } else if (job.status === 'failed') {
                                        if (window.AdasiToast && typeof window.AdasiToast.error === 'function') {
                                            AdasiToast.error(job.message || @js(__('finance.drp_ui.export_failed')));
                                        }
                                        btnExportTransferSingle.disabled = false;
                                    } else if (job.status === 'queued' || job.status === 'processing') {
                                        setTimeout(poll, 1500);
                                    }
                                })
                                .catch(() => { btnExportTransferSingle.disabled = false; });
                        };
                        setTimeout(poll, 1500);
                    } else {
                        btnExportTransferSingle.disabled = false;
                    }
                })
                .catch(err => {
                    const msg = err.message || err.error || @js(__('finance.drp_ui.transfer_error'));
                    if (window.AdasiToast && typeof window.AdasiToast.error === 'function') {
                        AdasiToast.error(msg);
                    } else {
                        alert(msg);
                    }
                    btnExportTransferSingle.disabled = false;
                });
            };

            const confirmMsg = @js(__('finance.drp_ui.transfer_confirm', ['number' => $batch->batch_number]));
            if (window.AdasiAlert && typeof window.AdasiAlert.confirm === 'function') {
                AdasiAlert.confirm({
                    title: @js(__('finance.drp_ui.transfer_title')),
                    message: confirmMsg,
                    confirmText: @js(__('finance.drp_ui.export_yes')),
                    cancelText: @js(__('local_invoice.actions.cancel')),
                }).then(confirmed => {
                    if (confirmed) doExport();
                });
            } else if (confirm(confirmMsg)) {
                doExport();
            }
        });
    }
});
</script>
@endpush
