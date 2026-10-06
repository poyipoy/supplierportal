@extends('layouts.app')
@section('title', __('finance.closure.paid_title', ['audience' => 'Finance AP']))
@section('page-title', __('finance.drp.paid_monitor_title'))

@section('content')
<div id="drpPaidContainer" class="tw-grid tw-gap-6 tw-pb-16" data-server-tabs-container>
    <x-ui.page-header
        :title="__('finance.drp.paid_title')"
        :description="__('common.final_review.paid_help')"
        :eyebrow="__('finance.labels.finance_ap')"
    >
        <x-slot:actions>
            <x-ui.button :href="route('finance.dashboard')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>{{ __('common.labels_review.dashboard') }}</span>
            </x-ui.button>
            <x-ui.button :href="route('finance.drp.supplier')" variant="outline" size="sm">
                <x-ui.icon name="wallet" size="sm" />
                <span>{{ __('navigation.drp_supplier') }}</span>
            </x-ui.button>
            <x-ui.button :href="route('finance.drp.ga')" variant="outline" size="sm">
                <x-ui.icon name="credit-card" size="sm" />
                <span>DRP GA</span>
            </x-ui.button>
            <x-ui.button :href="route('finance.overpayments.index')" variant="outline" size="sm" class="tw-relative">
                <x-ui.icon name="alert-circle" size="sm" class="{{ ($openOverpaymentsCount ?? 0) > 0 ? 'tw-text-amber-500' : '' }}" />
                <span>{{ __('finance.refund.title') }}</span>
                @if(($openOverpaymentsCount ?? 0) > 0)
                    <span class="tw-inline-flex tw-items-center tw-justify-center tw-rounded-full tw-bg-amber-500 tw-text-white tw-text-[10px] tw-font-bold tw-px-1.5 tw-py-0.2" data-overpayment-count>
                        {{ $openOverpaymentsCount }}
                    </span>
                @endif
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- KPI Metric Cards Grid --}}
    <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-4">
        <x-ui.metric-card
            :label="__('finance.labels.total_batches')"
            :value="number_format($metrics['total_batches'])"
            icon="layers"
            tone="neutral"
            :meta="__('finance.drp_ui.all_registered_batches')"
            value-metric="total_batches"
        />

        <x-ui.metric-card
            :label="__('finance.drp.unpaid_pending')"
            :value="'Rp ' . number_format($metrics['unpaid_amount'], 0, ',', '.')"
            icon="clock"
            tone="warning"
            :meta="trans_choice('finance.drp_ui.unpaid_batches', $metrics['unpaid_count'], ['count' => number_format($metrics['unpaid_count'])])"
            :href="route('finance.drp.paid.index', array_merge(request()->query(), ['tab' => 'unpaid']))"
            data-server-tab
            data-server-tab-card
            data-tab-name="unpaid"
            :active="$tab === 'unpaid'"
            value-metric="unpaid_amount"
            meta-metric="unpaid_count"
        />

        <x-ui.metric-card
            :label="__('finance.drp.paid_complete')"
            :value="'Rp ' . number_format($metrics['paid_amount'], 0, ',', '.')"
            icon="badge-check"
            tone="success"
            :meta="trans_choice('finance.drp_ui.paid_batches', $metrics['paid_count'], ['count' => number_format($metrics['paid_count'])])"
            :href="route('finance.drp.paid.index', array_merge(request()->query(), ['tab' => 'paid']))"
            data-server-tab
            data-server-tab-card
            data-tab-name="paid"
            :active="$tab === 'paid'"
            value-metric="paid_amount"
            meta-metric="paid_count"
        />
    </div>

    {{-- Status Tabs Navigation (Segmented Pill Bar) --}}
    <div class="tw-flex tw-flex-wrap tw-items-center tw-justify-between tw-gap-3">
        <nav class="tw-inline-flex tw-items-center tw-gap-1.5 tw-rounded-ui-md tw-border tw-border-outline tw-bg-surface-container tw-p-1.5 tw-shadow-none" aria-label="{{ __('finance.drp_surface.settlement_tabs') }}">
            <a
                href="{{ route('finance.drp.paid.index', array_merge(request()->query(), ['tab' => 'unpaid'])) }}"
                class="ui-focus-ring ui-motion tw-inline-flex tw-items-center tw-gap-2 tw-rounded-ui-sm tw-px-3.5 tw-py-1.5 tw-text-ui-xs tw-font-semibold tw-no-underline {{ $tab === 'unpaid' ? 'tw-bg-primary tw-text-primary-foreground tw-shadow-xs' : 'tw-text-on-surface-variant hover:tw-bg-surface hover:tw-text-on-surface' }}"
                data-server-tab
                data-tab-name="unpaid"
            >
                <x-ui.icon name="clock" size="sm" />
                <span>{{ __('finance.drp.unpaid') }}</span>
                <span class="tw-inline-flex tw-items-center tw-justify-center tw-rounded-full tw-px-2 tw-py-0.5 tw-text-[11px] tw-font-bold {{ $tab === 'unpaid' ? 'tw-bg-primary-foreground/20 tw-text-primary-foreground' : 'tw-bg-surface tw-text-on-surface-variant' }}" data-tab-count="unpaid">
                    {{ $metrics['unpaid_count'] }}
                </span>
            </a>
            <a
                href="{{ route('finance.drp.paid.index', array_merge(request()->query(), ['tab' => 'paid'])) }}"
                class="ui-focus-ring ui-motion tw-inline-flex tw-items-center tw-gap-2 tw-rounded-ui-sm tw-px-3.5 tw-py-1.5 tw-text-ui-xs tw-font-semibold tw-no-underline {{ $tab === 'paid' ? 'tw-bg-primary tw-text-primary-foreground tw-shadow-xs' : 'tw-text-on-surface-variant hover:tw-bg-surface hover:tw-text-on-surface' }}"
                data-server-tab
                data-tab-name="paid"
            >
                <x-ui.icon name="badge-check" size="sm" />
                <span>{{ __('finance.drp.paid') }}</span>
                <span class="tw-inline-flex tw-items-center tw-justify-center tw-rounded-full tw-px-2 tw-py-0.5 tw-text-[11px] tw-font-bold {{ $tab === 'paid' ? 'tw-bg-primary-foreground/20 tw-text-primary-foreground' : 'tw-bg-surface tw-text-on-surface-variant' }}" data-tab-count="paid">
                    {{ $metrics['paid_count'] }}
                </span>
            </a>
            <a
                href="{{ route('finance.drp.paid.index', array_merge(request()->query(), ['tab' => 'all'])) }}"
                class="ui-focus-ring ui-motion tw-inline-flex tw-items-center tw-gap-2 tw-rounded-ui-sm tw-px-3.5 tw-py-1.5 tw-text-ui-xs tw-font-semibold tw-no-underline {{ $tab === 'all' ? 'tw-bg-primary tw-text-primary-foreground tw-shadow-xs' : 'tw-text-on-surface-variant hover:tw-bg-surface hover:tw-text-on-surface' }}"
                data-server-tab
                data-tab-name="all"
            >
                <x-ui.icon name="layers" size="sm" />
                <span>{{ __('finance.drp.all_batches') }}</span>
                <span class="tw-inline-flex tw-items-center tw-justify-center tw-rounded-full tw-px-2 tw-py-0.5 tw-text-[11px] tw-font-bold {{ $tab === 'all' ? 'tw-bg-primary-foreground/20 tw-text-primary-foreground' : 'tw-bg-surface tw-text-on-surface-variant' }}" data-tab-count="all">
                    {{ $metrics['total_batches'] }}
                </span>
            </a>
        </nav>
    </div>

    {{-- Search & Filter Toolbar --}}
    <form method="GET" action="{{ route('finance.drp.paid.index') }}" id="drpPaidFilterForm" class="tw-m-0" data-server-tabs-form>
        <input type="hidden" name="tab" value="{{ $tab }}">

        <x-ui.toolbar aria-label="{{ __('finance.drp_surface.batch_filters') }}">
            <x-slot:search>
                <div class="tw-relative tw-w-full">
                    <input
                        type="search"
                        name="q"
                        id="drp-paid-search"
                        value="{{ $q }}"
                        placeholder="{{ __('finance.drp.filter_placeholder') }}"
                        class="form-control form-control-sm tw-text-ui-xs"
                        maxlength="100"
                        autocomplete="off"
                    >
                </div>
            </x-slot:search>

            <x-slot:filters>
                <div class="tw-flex tw-flex-wrap tw-items-center tw-gap-2.5">
                    <div class="tw-w-36">
                        <label for="drp-type" class="visually-hidden">{{ __('finance.drp.type') }}</label>
                        <select id="drp-type" name="type" class="form-select form-select-sm tw-text-ui-xs">
                            <option value="">{{ __('local_invoice.labels.all_types') }}</option>
                            <option value="SUPPLIER" @selected($type === 'SUPPLIER')>{{ __('local_invoice.labels.supplier') }}</option>
                            <option value="GA" @selected($type === 'GA')>{{ __('navigation.general_affairs') }}</option>
                        </select>
                    </div>

                    <div class="tw-w-44">
                        <label for="drp-overpayment-status" class="visually-hidden">{{ __('finance.drp_surface.overpayment_status') }}</label>
                        <select id="drp-overpayment-status" name="overpayment_status" class="form-select form-select-sm tw-text-ui-xs">
                            <option value="all" @selected(($overpaymentStatus ?? 'all') === 'all')>{{ __('finance.refund.all') }}</option>
                            <option value="has_overpayment" @selected(($overpaymentStatus ?? '') === 'has_overpayment')>{{ __('local_invoice.labels.overpayment') }}</option>
                            <option value="open" @selected(($overpaymentStatus ?? '') === 'open')>{{ __('local_invoice.filters.refund_open') }}</option>
                            <option value="settled" @selected(($overpaymentStatus ?? '') === 'settled')>{{ __('finance.refund.completed_status') }}</option>
                        </select>
                    </div>

                    <div class="tw-w-64">
                        <x-ui.date-range-picker
                            id="paid-date-range"
                            start-name="date_from"
                            end-name="date_to"
                            :start-label="__('local_invoice.labels.from_date')"
                            :end-label="__('local_invoice.labels.to_date')"
                            :start-value="$dateFrom"
                            :end-value="$dateTo"
                            :compact="true"
                        />
                    </div>
                </div>
            </x-slot:filters>

            <x-slot:actions>
                <x-ui.button type="submit" size="sm" variant="primary">
                    <x-ui.icon name="filter" size="sm" />
                    <span>{{ __('finance.drp_ui.filter') }}</span>
                </x-ui.button>
                @if($q || $type || $dateFrom || $dateTo || ($overpaymentStatus && $overpaymentStatus !== 'all') || $tab !== 'unpaid')
                    <x-ui.button :href="route('finance.drp.paid.index', ['tab' => $tab])" size="sm" variant="ghost">
                        <x-ui.icon name="rotate-ccw" size="sm" />
                        <span>{{ __('common.actions.reset') }}</span>
                    </x-ui.button>
                @endif
            </x-slot:actions>
        </x-ui.toolbar>
    </form>

    {{-- Data Table Section (AJAX-swappable container) --}}
    <div id="drpPaidTableContent" data-server-tabs-content>
        @include('finance.drp._paid_table_content', ['batches' => $batches])
    </div>

    {{-- Modal Konfirmasi Bayar Batch (Ditempatkan di luar tabel agar HTML5 DOM & Alpine.js valid) --}}
    @foreach($batches as $batch)
        @if(in_array($batch->status, [\App\Models\PaymentBatch::STATUS_FINALIZED, \App\Models\PaymentBatch::STATUS_PARTIALLY_PAID]) && ! $batch->hasUnvoucheredSupplierItems())
            <div class="modal fade" id="markPaidModal-{{ $batch->id }}" tabindex="-1" aria-hidden="true"
                 x-data="{
                     hasAdjustment: false,
                     adjustments: {},
                     updateItem(id, expected, actualVal) {
                         const actual = parseFloat(actualVal) || 0;
                         const diff = Math.round((actual - expected) * 100) / 100;
                         this.adjustments[id] = { expected, actual, diff };
                     },
                     get totalOverpayment() {
                         if (!this.hasAdjustment) return 0;
                         let total = 0;
                         for (const key in this.adjustments) {
                             if (this.adjustments[key] && this.adjustments[key].diff > 0.009) {
                                 total += this.adjustments[key].diff;
                             }
                         }
                         return Math.round(total * 100) / 100;
                     },
                     get overpaidCount() {
                         if (!this.hasAdjustment) return 0;
                         let count = 0;
                         for (const key in this.adjustments) {
                             if (this.adjustments[key] && this.adjustments[key].diff > 0.009) count++;
                         }
                         return count;
                     }
                 }">
                <div class="modal-dialog modal-dialog-centered {{ $batch->batch_type === \App\Models\PaymentBatch::TYPE_SUPPLIER ? 'modal-lg' : '' }}">
                    <form method="POST" action="{{ route('finance.drp.paid.mark-paid', $batch) }}">
                        @csrf
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title tw-text-ui-sm tw-font-bold tw-text-on-surface tw-flex tw-items-center tw-gap-2">
                                    <x-ui.icon name="badge-check" size="sm" class="tw-text-success" />
                                    <span>{{ __('finance.drp_ui.mark_paid_title', ['number' => $batch->batch_number]) }}</span>
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('local_invoice.actions.close') }}"></button>
                            </div>
                            <div class="modal-body tw-space-y-3">
                                <div class="tw-rounded-lg tw-bg-surface-container tw-p-3 tw-text-ui-xs tw-space-y-1">
                                    <div class="tw-flex tw-justify-between">
                                        <span class="tw-text-on-surface-variant">{{ __('finance.drp.batch_type') }}:</span>
                                        <span class="tw-font-semibold">{{ __('finance.closure.batch_type_'.strtolower($batch->batch_type)) }}</span>
                                    </div>
                                    <div class="tw-flex tw-justify-between">
                                        <span class="tw-text-on-surface-variant">{{ __('finance.drp_ui.account_count_label') }}</span>
                                        <span class="tw-font-semibold">{{ trans_choice('finance.drp_ui.account_count', $batch->groups_count) }}</span>
                                    </div>
                                    <div class="tw-flex tw-justify-between tw-border-t tw-border-outline-variant tw-pt-1">
                                        <span class="tw-text-on-surface-variant">{{ __('finance.drp.total_net') }}:</span>
                                        <span class="tw-font-bold tw-font-mono tw-text-on-surface">
                                            Rp {{ number_format($batch->total_net_amount, 0, ',', '.') }}
                                        </span>
                                    </div>
                                    @if($batch->status === \App\Models\PaymentBatch::STATUS_PARTIALLY_PAID)
                                        <div class="tw-flex tw-justify-between">
                                            <span class="tw-text-on-surface-variant">{{ __('local_invoice.labels.paid') }}:</span>
                                            <span class="tw-font-bold tw-font-mono tw-text-success">
                                                Rp {{ number_format($batch->actual_paid_amount, 0, ',', '.') }}
                                            </span>
                                        </div>
                                        <div class="tw-flex tw-justify-between tw-border-t tw-border-outline-variant tw-pt-1">
                                            <span class="tw-text-warning-container-foreground tw-font-semibold">{{ __('local_invoice.labels.remaining') }}:</span>
                                            <span class="tw-font-bold tw-font-mono tw-text-warning-container-foreground">
                                                Rp {{ number_format($batch->remaining_amount, 0, ',', '.') }}
                                            </span>
                                        </div>
                                    @endif
                                </div>

                                <p class="tw-text-ui-xs tw-text-on-surface-variant">
                                    {{ __('finance.drp_ui.confirm_payment_help') }}
                                </p>

                                <div class="tw-space-y-3">
                                    <div>
                                        <x-ui.date-picker
                                            id="transfer_date_{{ $batch->id }}"
                                            name="transfer_date"
                                            :value="now()->format('Y-m-d')"
                                            :label="__('finance.payment.bank_date')"
                                            required="true"
                                        />
                                    </div>

                                    <div>
                                        <label class="form-label tw-text-ui-xs tw-font-semibold">
                                            {{ __('finance.drp_ui.reference_label') }} <span class="text-danger">*</span>
                                        </label>
                                        <input
                                            type="text"
                                            name="transfer_reference"
                                            class="form-control form-control-sm"
                                            placeholder="{{ __('finance.drp_ui.reference_example') }}"
                                            required
                                            maxlength="100"
                                        >
                                    </div>

                                    <div>
                                        <label class="form-label tw-text-ui-xs tw-font-semibold">
                                            {{ __('finance.payment.settlement_notes') }}
                                        </label>
                                        <textarea
                                            name="payment_notes"
                                            class="form-control form-control-sm"
                                            rows="2"
                                            placeholder="{{ __('finance.drp_ui.notes_example') }}"
                                            maxlength="1000"
                                        ></textarea>
                                    </div>

                                    @if($batch->batch_type === \App\Models\PaymentBatch::TYPE_SUPPLIER)
                                        <div class="tw-pt-3 tw-border-t tw-border-outline-variant">
                                            <div class="form-check tw-mb-2">
                                                <input class="form-check-input"
                                                       type="checkbox"
                                                       id="has_adjustment_{{ $batch->id }}"
                                                       x-model="hasAdjustment"
                                                >
                                                <label class="form-check-label tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="has_adjustment_{{ $batch->id }}">
                                                    {{ __('finance.payment.actual_adjustment') }}
                                                </label>
                                            </div>

                                            <div x-show="hasAdjustment"
                                                 x-transition
                                                 style="display: none;"
                                                 class="tw-space-y-3 tw-bg-surface-container/50 tw-p-3 tw-rounded-lg tw-border tw-border-outline-variant">
                                                <p class="tw-text-[11px] tw-text-on-surface-variant tw-m-0">
                                                    {{ __('finance.drp_ui.adjustment_help') }}
                                                </p>

                                                <template x-if="hasAdjustment && totalOverpayment > 0.009">
                                                    <div class="tw-rounded-lg tw-border tw-border-amber-300 tw-bg-amber-50 dark:tw-bg-amber-950/40 dark:tw-border-amber-700 tw-p-3 tw-text-ui-xs">
                                                        <div class="tw-flex tw-items-center tw-gap-2 tw-text-amber-800 dark:tw-text-amber-300 tw-font-bold">
                                                            <x-ui.icon name="alert-circle" size="sm" />
                                                            <span>{{ __('finance.drp_ui.overpayment_warning') }}</span>
                                                        </div>
                                                        <div class="tw-mt-1 tw-text-on-surface">
                                                            <span x-text="{{ json_encode(__('finance.drp_ui.overpayment_summary'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) }}.replace(':amount', () => totalOverpayment.toLocaleString('id-ID')).replace(':count', () => String(overpaidCount))"></span>
                                                        </div>
                                                        <p class="tw-text-[11px] tw-text-on-surface-variant tw-mt-1 tw-mb-0">
                                                            {{ __('finance.drp_ui.overpayment_notice') }}
                                                        </p>
                                                    </div>
                                                </template>

                                                <div class="tw-space-y-2.5">
                                                    @php $hasActiveItem = false; @endphp
                                                    @foreach($batch->groups as $group)
                                                        @foreach($group->items as $item)
                                                            @if($item->status === \App\Models\PaymentItem::STATUS_ACTIVE)
                                                                @php
                                                                    $hasActiveItem = true;
                                                                    $voucher = $item->localInvoiceVoucher;
                                                                    $payment = $item->localInvoicePayment;
                                                                    $expectedAmount = (float) ($voucher ? $voucher->amount : ($item->amount ?? 0));
                                                                    $alreadyPaid = $payment ? (float) $payment->actual_paid_total : 0.0;
                                                                    $itemRemaining = $payment ? max(0.0, (float) $payment->expected_amount - $alreadyPaid) : $expectedAmount;
                                                                    $isCorrectionRequired = $payment && $payment->status === \App\Models\LocalInvoicePayment::STATUS_CORRECTION_REQUIRED;
                                                                    $isAlreadyFinal = $payment && $payment->status === \App\Models\LocalInvoicePayment::STATUS_FINALIZED;
                                                                    $defaultPayAmount = $isCorrectionRequired ? $itemRemaining : $expectedAmount;
                                                                @endphp
                                                                @if($isAlreadyFinal)
                                                                    <div class="tw-bg-surface tw-p-2.5 tw-rounded tw-border tw-border-outline-variant tw-opacity-80">
                                                                        <div class="tw-flex tw-justify-between tw-items-center">
                                                                            <div>
                                                                                <span class="tw-font-semibold tw-text-ui-xs tw-text-on-surface">{{ $group->payee_name }}</span>
                                                                                <span class="tw-text-[11px] tw-text-on-surface-variant tw-block">
                                                                                    {{ __('finance.drp_ui.invoice_reference', ['invoice' => $item->payable?->invoice_number ?? $item->item_reference, 'po' => $item->payable?->po_number ?? '-']) }}
                                                                                </span>
                                                                            </div>
                                                                            <div class="tw-text-end">
                                                                                <span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-2 tw-py-0.5 tw-rounded-full tw-text-[11px] tw-font-semibold tw-bg-success/10 tw-text-success">
                                                                                    <x-ui.icon name="check-circle" size="sm" /> {{ __('finance.drp_ui.settled_amount', ['amount' => number_format($alreadyPaid, 0, ',', '.')]) }}
                                                                                </span>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                @else
                                                                    <div class="tw-bg-surface tw-p-2.5 tw-rounded tw-border tw-border-outline-variant"
                                                                         x-data="{
                                                                             expected: {{ (float) $defaultPayAmount }},
                                                                             actual: '{{ (float) $defaultPayAmount }}',
                                                                             get diff() {
                                                                                 return Math.round(((parseFloat(this.actual) || 0) - this.expected) * 100) / 100;
                                                                             },
                                                                             init() {
                                                                                 updateItem('{{ $item->id }}', this.expected, this.actual);
                                                                                 this.$watch('actual', val => updateItem('{{ $item->id }}', this.expected, val));
                                                                             }
                                                                         }">
                                                                        <div class="tw-flex tw-justify-between tw-items-start tw-mb-1.5">
                                                                            <div>
                                                                                <div class="tw-flex tw-items-center tw-gap-1.5">
                                                                                    <span class="tw-font-semibold tw-text-ui-xs tw-text-on-surface">{{ $group->payee_name }}</span>
                                                                                    @if($isCorrectionRequired)
                                                                                        <span class="tw-inline-flex tw-items-center tw-px-1.5 tw-py-0.2 tw-rounded tw-text-[10px] tw-font-semibold tw-bg-warning/10 tw-text-warning-container-foreground">
                                                                                            {{ __('finance.drp_ui.underpaid') }}
                                                                                        </span>
                                                                                    @endif
                                                                                </div>
                                                                                <span class="tw-text-[11px] tw-text-on-surface-variant tw-block">
                                                                                    {{ __('finance.drp_ui.invoice_reference', ['invoice' => $item->payable?->invoice_number ?? $item->item_reference, 'po' => $item->payable?->po_number ?? '-']) }}
                                                                                </span>
                                                                            </div>
                                                                            <div class="tw-text-end">
                                                                                @if($isCorrectionRequired)
                                                                                    <div class="tw-text-[11px] tw-text-on-surface-variant">
                                                                                        {{ __('common.labels_review.voucher_label') }} <span class="tw-font-mono tw-font-semibold tw-text-on-surface">Rp {{ number_format($expectedAmount, 0, ',', '.') }}</span>
                                                                                    </div>
                                                                                    <div class="tw-text-[11px] tw-font-mono">
                                                                                        <span class="tw-text-success tw-font-medium">{{ __('finance.paid_ui.paid_amount', ['amount' => number_format($alreadyPaid, 0, ',', '.')]) }}</span>
                                                                                        <span class="tw-text-on-surface-variant">·</span>
                                                                                        <span class="tw-text-warning-container-foreground tw-font-bold">{{ __('finance.paid_ui.remaining', ['amount' => number_format($itemRemaining, 0, ',', '.')]) }}</span>
                                                                                    </div>
                                                                                @else
                                                                                    <span class="tw-text-[11px] tw-text-on-surface-variant">{{ __('common.labels_review.voucher_label') }}</span>
                                                                                    @if($voucher)
                                                                                        <span class="tw-font-mono tw-text-ui-xs tw-font-bold tw-text-primary">
                                                                                            Rp {{ number_format($expectedAmount, 0, ',', '.') }}
                                                                                        </span>
                                                                                    @else
                                                                                        <span class="tw-font-mono tw-text-ui-xs tw-font-bold tw-text-warning" title="{{ __('finance.voucher.no_voucher') }}">
                                                                                            {{ __('finance.drp_ui.estimated_amount', ['amount' => number_format($expectedAmount, 0, ',', '.')]) }}
                                                                                        </span>
                                                                                    @endif
                                                                                @endif
                                                                            </div>
                                                                        </div>

                                                                        <div class="row g-2 align-items-end">
                                                                            <div class="col-12 col-md-6">
                                                                                <label class="form-label tw-text-[11px] tw-font-semibold mb-1">
                                                                                    {{ $isCorrectionRequired ? __('finance.drp_ui.remaining_transfer') : __('finance.drp_ui.actual_transfer') }}
                                                                                </label>
                                                                                <input
                                                                                    type="number"
                                                                                    step="0.01"
                                                                                    min="0.01"
                                                                                    name="custom_amounts[{{ $item->id }}]"
                                                                                    x-model="actual"
                                                                                    class="form-control form-control-sm tw-font-mono"
                                                                                    :disabled="!hasAdjustment"
                                                                                    :required="hasAdjustment"
                                                                                >
                                                                            </div>
                                                                            <div class="col-12 col-md-6">
                                                                                <template x-if="diff > 0.009">
                                                                                    <div class="tw-text-[11px] tw-font-semibold tw-text-primary tw-py-1">
                                                                                        <x-ui.icon name="arrow-up-right" size="sm" /> <span x-text="{{ json_encode(__('finance.drp_ui.overpayment_diff'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) }}.replace(':amount', () => Math.abs(diff).toLocaleString('id-ID'))"></span>
                                                                                    </div>
                                                                                </template>
                                                                                <template x-if="diff < -0.009">
                                                                                    <div>
                                                                                        <div class="tw-text-[11px] tw-font-semibold tw-text-warning tw-py-1">
                                                                                            <x-ui.icon name="arrow-down-right" size="sm" /> <span x-text="{{ json_encode(__('finance.drp_ui.underpayment_diff'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) }}.replace(':amount', () => Math.abs(diff).toLocaleString('id-ID'))"></span>
                                                                                        </div>
                                                                                        <input
                                                                                            type="text"
                                                                                            name="correction_reasons[{{ $item->id }}]"
                                                                                            placeholder="{{ __('finance.payment.underpayment_reason') }}"
                                                                                            class="form-control form-control-sm tw-text-[11px] mt-1"
                                                                                            :disabled="!hasAdjustment"
                                                                                        >
                                                                                    </div>
                                                                                </template>
                                                                                <template x-if="Math.abs(diff) <= 0.009">
                                                                                    <div class="tw-text-[11px] tw-text-success tw-py-1">
                                                                                        <x-ui.icon name="check" size="sm" /> {{ __($isCorrectionRequired ? 'finance.closure.remaining_settlement' : 'finance.closure.matches_voucher') }}
                                                                                    </div>
                                                                                </template>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                @endif
                                                            @endif
                                                        @endforeach
                                                    @endforeach

                                                    @if(! $hasActiveItem)
                                                        <div class="tw-p-2 tw-rounded tw-bg-surface tw-border tw-border-outline-variant tw-text-ui-xs tw-text-on-surface-variant text-center">
                                                            {{ __('finance.drp.empty_items') }}
                                                        </div>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            </div>
                            <div class="modal-footer">
                                <x-ui.button type="button" variant="ghost" size="sm" data-bs-dismiss="modal">{{ __('local_invoice.actions.cancel') }}</x-ui.button>
                                <x-ui.button type="submit" variant="primary" size="sm">
                                    <x-ui.icon name="badge-check" size="sm" />
                                    <span>{{ __('local_invoice.interaction.confirm') }}</span>
                                </x-ui.button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        @endif
    @endforeach
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    // --- Export Transfer Checkbox Logic (re-bindable after AJAX swap) ---
    function initBatchSelection() {
        const selectAll = document.getElementById('selectAllBatchesPaid');
        if (selectAll) {
            // Remove prior listeners by cloning
            const fresh = selectAll.cloneNode(true);
            selectAll.parentNode.replaceChild(fresh, selectAll);
            fresh.addEventListener('change', function () {
                document.querySelectorAll('.batch-checkbox-paid').forEach(cb => cb.checked = fresh.checked);
                syncExportButton();
            });
        }
        syncExportButton();
    }

    function syncExportButton() {
        const btnExport = document.getElementById('btnExportTransferPaid');
        const countBadge = document.getElementById('exportTransferCountPaid');
        const selectAll = document.getElementById('selectAllBatchesPaid');
        if (!btnExport) return;

        const checked = document.querySelectorAll('.batch-checkbox-paid:checked');
        const allBoxes = document.querySelectorAll('.batch-checkbox-paid');
        const count = checked.length;

        btnExport.disabled = count === 0;
        if (count > 0) {
            countBadge.textContent = count;
            countBadge.classList.remove('tw-hidden');
        } else {
            countBadge.classList.add('tw-hidden');
        }
        if (allBoxes.length > 0 && selectAll) {
            selectAll.checked = count === allBoxes.length;
            selectAll.indeterminate = count > 0 && count < allBoxes.length;
        }
    }

    // Delegated change handler (survives DOM swaps)
    document.addEventListener('change', function (e) {
        if (e.target.classList.contains('batch-checkbox-paid')) {
            syncExportButton();
        }
    });

    // Delegated export button click (survives DOM swaps)
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('#btnExportTransferPaid');
        if (!btn || btn.disabled) return;

        const selected = document.querySelectorAll('.batch-checkbox-paid:checked');
        if (selected.length === 0) return;

        const batchIds = Array.from(selected).map(cb => cb.value);

        const confirmMsg = @js(__('finance.async_copy.export_confirm')).replace(':count', String(batchIds.length));
        if (window.AdasiAlert && typeof window.AdasiAlert.confirm === 'function') {
            AdasiAlert.confirm({
                title: @js(__('finance.async_copy.export_title')),
                message: confirmMsg,
                confirmText: @js(__('finance.async_copy.export_yes')),
                cancelText: @js(__('finance.async_copy.cancel')),
            }).then(confirmed => {
                if (confirmed) {
                    dispatchExport(batchIds);
                }
            });
        } else {
            if (confirm(confirmMsg)) {
                dispatchExport(batchIds);
            }
        }
    });

    function dispatchExport(batchIds) {
        const btnExport = document.getElementById('btnExportTransferPaid');
        if (btnExport) btnExport.disabled = true;

        fetch("{{ route('finance.drp.export-transfer') }}", {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '{{ csrf_token() }}',
            },
            body: JSON.stringify({ batch_ids: batchIds }),
        })
        .then(response => {
            if (!response.ok) {
                return response.json().then(data => { throw data; });
            }
            return response.json();
        })
        .then(data => {
            if (window.AdasiToast && typeof window.AdasiToast.success === 'function') {
                AdasiToast.success(data.message || @js(__('finance.async_copy.dispatched_transfer')));
            } else {
                alert(data.message || @js(__('finance.async_copy.dispatched')));
            }

            try {
                const existing = JSON.parse(localStorage.getItem('adasi:pending-export-jobs:v1') || '[]');
                existing.push({
                    exportJobId: String(data.export_job_id),
                    statusUrl: data.status_url,
                    startedAt: Date.now(),
                    exportsUrl: data.exports_url || null,
                    cancelUrl: data.cancel_url || null,
                    status: 'queued',
                    stage: 'queued',
                    progress: 0,
                    processedRows: 0,
                    totalRows: 0,
                });
                localStorage.setItem('adasi:pending-export-jobs:v1', JSON.stringify(existing.slice(-25)));
            } catch (e) {}

            if (data.status_url) {
                pollExportStatusPaid(data.status_url);
            }

            document.querySelectorAll('.batch-checkbox-paid').forEach(cb => cb.checked = false);
            initBatchSelection();
        })
        .catch(err => {
            const msg = err.message || err.error || @js(__('finance.async_copy.export_error'));
            if (window.AdasiToast && typeof window.AdasiToast.error === 'function') {
                AdasiToast.error(msg);
            } else {
                alert(msg);
            }
            syncExportButton();
        });
    }

    function pollExportStatusPaid(statusUrl) {
        let attempts = 0;
        const maxAttempts = 120;

        const check = () => {
            attempts++;
            if (attempts > maxAttempts) return;

            fetch(statusUrl, {
                headers: { 'Accept': 'application/json' }
            })
            .then(res => res.json())
            .then(job => {
                if (job.status === 'completed' && job.download_url) {
                    if (typeof AdasiToast !== 'undefined') {
                        AdasiToast.success(@js(__('finance.async_copy.export_complete')));
                    }
                    const a = document.createElement('a');
                    a.href = job.download_url;
                    a.download = job.file_name || 'DRP_TRANSFER.xlsx';
                    document.body.appendChild(a);
                    a.click();
                    a.remove();
                } else if (job.status === 'failed') {
                    const failMsg = job.message || @js(__('finance.async_copy.export_failed'));
                    if (typeof AdasiToast !== 'undefined') {
                        AdasiToast.error(failMsg);
                    }
                } else if (job.status === 'queued' || job.status === 'processing') {
                    setTimeout(check, 1500);
                }
            })
            .catch(() => {});
        };

        setTimeout(check, 1500);
    }

    // Initial bind
    initBatchSelection();

    // --- Initialize AdasiServerTabs for zero-reload tab/filter/pagination ---
    if (typeof AdasiServerTabs !== 'undefined') {
        AdasiServerTabs.init('#drpPaidContainer', {
            onUpdated: function () {
                initBatchSelection();
            },
        });
    }
});
</script>
@endpush
