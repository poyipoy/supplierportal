@extends('layouts.app')
@section('title', __('finance.closure.batch_title', ['number' => $batch->batch_number, 'audience' => 'Purchasing']))
@section('page-title', __('finance.drp.payment_plan_detail'))

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        :title="__('finance.closure.batch_heading', ['number' => $batch->batch_number])"
        :description="__('finance.closure.batch_description', ['type' => __('finance.closure.batch_type_'.strtolower($batch->batch_type)), 'date' => $regionalFormatter->timestamp($batch->created_at, 'datetime'), 'name' => $batch->creator?->name ?? __('common.final_copy.system')])"
        :eyebrow="__('finance.drp_surface.purchasing_audience')"
    >
        <x-slot:actions>
            <x-ui.button :href="\App\Support\PurchasingNavigation::backUrl($batch->batch_type === 'SUPPLIER' ? 'purchasing.drp.supplier' : 'purchasing.drp.ga')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>{{ __('common.labels_review.back_list') }}</span>
            </x-ui.button>
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
                            {{ __('common.labels_review.account_label') }} <strong>{{ $group->bank_name }}</strong> — <strong class="tw-font-mono">{{ $group->account_number }}</strong> {{ __('finance.drp_surface.account_holder') }} <strong>{{ $group->account_holder_name }}</strong>
                        </span>
                        @if($group->voucher_number)
                            <span class="tw-text-[11px] tw-text-primary tw-block tw-mt-0.5">
                                {{ __('common.labels_review.voucher_label') }} <strong>{{ $group->voucher_number }}</strong> ({{ __('local_invoice.labels.date') }}: {{ $regionalFormatter->date($group->voucher_date, 'human') }})
                            </span>
                        @endif
                    </div>

                    <div class="tw-flex tw-flex-wrap tw-items-center tw-gap-4 text-end">
                        <div>
                            <span class="tw-text-[11px] tw-text-on-surface-variant tw-block">{{ __('finance.drp_surface.net_transfer') }}</span>
                            <span class="tw-font-mono tw-font-bold tw-text-ui-base tw-text-primary">
                                Rp {{ number_format($group->net_payment_amount, 0, ',', '.') }}
                            </span>
                            <span class="tw-text-[10px] tw-text-on-surface-variant tw-block">{{ __('common.final_copy.bank_fee', ['amount' => number_format($group->bank_fee, 0, ',', '.')]) }}</span>
                        </div>
                    </div>
                </div>

                {{-- Terbilang info --}}
                <div class="tw-bg-surface-container tw-p-2.5 tw-rounded tw-text-ui-xs tw-text-on-surface tw-mb-4 tw-italic">
                    {{ __('finance.drp_ui.amount_words', ['words' => $voucherService->terbilang($group->net_payment_amount)]) }}
                </div>

                @if($group->status === 'PAID')
                    <div class="tw-bg-success/10 tw-border tw-border-success/20 tw-p-3 tw-rounded tw-text-ui-xs tw-mb-4">
                        <strong class="tw-text-success">{{ __('finance.drp_surface.completed') }}</strong>{{ __('finance.drp_surface.transfer_reference') }}<strong class="tw-font-mono">{{ $group->transfer_reference }}</strong>
                        — {{ __('common.final_copy.transfer_date') }} <strong>{{ $regionalFormatter->date($group->transfer_date, 'human') }}</strong>
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
                                <th scope="col">{{ __('finance.drp_surface.document_reference') }}</th>
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
                                                                <span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-1.5 tw-py-0.5 tw-rounded tw-text-[10px] tw-font-semibold tw-bg-amber-100 tw-text-amber-800 dark:tw-bg-amber-950 dark:tw-text-amber-300 tw-w-fit"
                                                                      title="{{ __('finance.drp_surface.not_refunded') }}">
                                                                    <x-ui.icon name="alert-circle" size="xs" />
                                                                    <span>{{ __('finance.drp_ui.overpayment_status', ['amount' => number_format($item->localInvoicePayment->overpayment->overpayment_amount, 0, ',', '.'), 'status' => \App\Support\StatusHelper::localFinanceLabel($item->localInvoicePayment->overpayment->status)]) }}</span>
                                                                </span>
                                                            @elseif($item->localInvoicePayment->overpayment->status === \App\Models\SupplierOverpaymentRefund::STATUS_SETTLED)
                                                                <span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-1.5 tw-py-0.5 tw-rounded tw-text-[10px] tw-font-semibold tw-bg-emerald-100 tw-text-emerald-800 dark:tw-bg-emerald-950 dark:tw-text-emerald-300 tw-w-fit"
                                                                      title="{{ __('finance.drp_surface.refund_completed') }}">
                                                                    <x-ui.icon name="check-circle" size="xs" />
                                                                    <span>{{ __('finance.paid_ui.overpayment_settled', ['amount' => number_format($item->localInvoicePayment->overpayment->overpayment_amount, 0, ',', '.')]) }}</span>
                                                                </span>
                                                            @endif
                                                        @endif
                                                    </div>
                                                @elseif($item->localInvoicePayment->status === \App\Models\LocalInvoicePayment::STATUS_CORRECTION_REQUIRED)
                                                    <div class="tw-flex tw-flex-col tw-gap-0.5">
                                                        <span class="tw-inline-flex tw-px-1.5 tw-py-0.5 tw-rounded tw-text-[10px] tw-font-semibold tw-bg-warning/10 tw-text-warning-container-foreground tw-w-fit">{{ __('finance.drp_surface.underpaid') }}</span>
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
                                        @if($item->localInvoiceVoucher)
                                            <x-ui.button :href="route('purchasing.vouchers.print', $item->localInvoiceVoucher)" variant="outline" size="sm" target="_blank" :title="__('finance.voucher.print')">
                                                <x-ui.icon name="printer" size="sm" />
                                                <span>{{ __('finance.voucher.print_pdf') }}</span>
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
