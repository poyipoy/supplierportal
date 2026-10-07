{{-- Finance DRP Paid — Table content partial (rendered standalone for AJAX, @included in paid.blade.php for SSR) --}}
<x-ui.data-table
    :title="__('finance.drp.paid_monitor')"
    :description="trans_choice('finance.closure.batch_export_count', $batches->total())"
>
    <x-slot:toolbar>
        <x-ui.button
            type="button"
            variant="primary"
            size="sm"
            id="btnExportTransferPaid"
            disabled
            :title="__('finance.drp_surface.choose_supplier_batch')"
        >
            <x-ui.icon name="download" size="sm" />
            <span>{{ __('exports.actions.transfer') }}</span>
            <span id="exportTransferCountPaid" class="tw-hidden tw-ml-1.5 tw-inline-flex tw-items-center tw-justify-center tw-rounded-full tw-bg-white/20 tw-text-white tw-text-[10px] tw-font-bold tw-px-1.5 tw-py-0.5"></span>
        </x-ui.button>
    </x-slot:toolbar>
    <div class="table-responsive">
        <table class="table table-hover align-middle tw-m-0 tw-text-ui-sm w-100">
            <thead class="table-light">
                <tr>
                    <th scope="col" style="width: 40px;">
                        <input type="checkbox" class="form-check-input" id="selectAllBatchesPaid" title="{{ __('finance.drp_surface.all_supplier_batches') }}">
                    </th>
                    <th scope="col" class="tw-text-ui-xs tw-font-semibold">{{ __('finance.drp_surface.batch_number') }}</th>
                    <th scope="col" class="tw-text-ui-xs tw-font-semibold">{{ __('common.labels_review.type_date') }}</th>
                    <th scope="col" class="tw-text-ui-xs tw-font-semibold text-center">{{ __('finance.drp.group_items') }}</th>
                    <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">{{ __('local_invoice.labels.total_amount') }}</th>
                    <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">{{ __('common.final_copy.net_payment_rp') }}</th>
                    <th scope="col" class="tw-text-ui-xs tw-font-semibold text-center">{{ __('local_invoice.labels.status') }}</th>
                    <th scope="col" class="tw-text-ui-xs tw-font-semibold">{{ __('local_invoice.labels.payment_info') }}</th>
                    <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">{{ __('local_invoice.labels.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($batches as $batch)
                    <tr>
                        <td>
                            @if($batch->batch_type === \App\Models\PaymentBatch::TYPE_SUPPLIER && $batch->status !== \App\Models\PaymentBatch::STATUS_CANCELLED)
                                <input type="checkbox" class="form-check-input batch-checkbox-paid" value="{{ $batch->hash }}" data-batch-number="{{ $batch->batch_number }}">
                            @endif
                        </td>
                        <td>
                            <a href="{{ route('finance.drp.show', $batch) }}" class="tw-font-mono tw-font-semibold tw-text-primary hover:tw-text-primary-hover tw-no-underline hover:tw-underline">
                                {{ $batch->batch_number }}
                            </a>
                        </td>
                        <td>
                            <div class="tw-flex tw-items-center tw-gap-1.5">
                                <span class="tw-inline-flex tw-items-center tw-px-1.5 tw-py-0.5 tw-rounded tw-text-[10px] tw-font-semibold {{ $batch->batch_type === 'SUPPLIER' ? 'tw-bg-primary/10 tw-text-primary' : 'tw-bg-secondary/10 tw-text-secondary' }}">
                                    {{ __('finance.closure.batch_type_'.strtolower($batch->batch_type)) }}
                                </span>
                                <span class="tw-text-ui-xs tw-text-on-surface-variant">{{ $regionalFormatter->date($batch->created_at, 'human') }}</span>
                            </div>
                            <div class="tw-text-[11px] tw-text-on-surface-variant tw-mt-0.5">
                                {{ __('ga.detail.actor', ['name' => $batch->creator?->name ?? __('ga.detail.system')]) }}
                            </div>
                        </td>
                        <td class="text-center">
                            <span class="tw-font-semibold">{{ $batch->groups_count }}</span>
                            <span class="tw-text-ui-xs tw-text-on-surface-variant">{{ __('finance.paid_ui.accounts') }}</span>
                        </td>
                        <td class="text-end tw-font-mono">
                            Rp {{ number_format($batch->total_subtotal, 0, ',', '.') }}
                        </td>
                        <td class="text-end tw-font-mono">
                            <div class="tw-font-bold tw-text-on-surface">
                                Rp {{ number_format($batch->total_net_amount, 0, ',', '.') }}
                            </div>
                            @if($batch->hasOverpayment())
                                <div class="tw-text-[11px] tw-text-amber-700 dark:tw-text-amber-400 tw-font-semibold tw-mt-0.5" title="{{ __('finance.drp_surface.exceeds_net') }}">
                                    {{ __('finance.paid_ui.transferred', ['amount' => number_format($batch->actual_transferred_amount, 0, ',', '.')]) }}
                                </div>
                            @endif
                            @if($batch->status === \App\Models\PaymentBatch::STATUS_PARTIALLY_PAID)
                                <div class="tw-text-[11px] tw-text-warning-container-foreground tw-font-semibold tw-mt-0.5" title="{{ __('finance.drp_surface.remaining_unpaid') }}">
                                    {{ __('finance.paid_ui.remaining', ['amount' => number_format($batch->remaining_amount, 0, ',', '.')]) }}
                                </div>
                            @endif
                        </td>
                        <td class="text-center">
                            <x-ui.status-chip :tone="\App\Support\StatusHelper::paymentBatchTone($batch->status)">
                                {{ \App\Support\StatusHelper::localFinanceLabel($batch->status) }}
                            </x-ui.status-chip>
                        </td>
                        <td>
                            @if($batch->status === \App\Models\PaymentBatch::STATUS_PAID)
                                @php
                                    $sampleGroup = $batch->groups->firstWhere('status', 'PAID');
                                @endphp
                                <div class="tw-text-ui-xs tw-text-success tw-font-semibold tw-flex tw-items-center tw-gap-1">
                                    <x-ui.icon name="check-circle" size="sm" />
                                    <span>{{ __('finance.paid_ui.paid_date', ['date' => $regionalFormatter->date($batch->paid_at, 'human') ?? '-']) }}</span>
                                </div>
                                @if($sampleGroup?->transfer_reference)
                                    <div class="tw-text-[11px] tw-font-mono tw-text-on-surface-variant">
                                        {{ __('finance.drp_ui.transfer_reference_short', ['reference' => $sampleGroup->transfer_reference]) }}
                                    </div>
                                @endif
                                @if($batch->hasOverpayment())
                                    @if($batch->hasOpenOverpayment())
                                        <a href="{{ route('finance.overpayments.index', ['q' => $batch->batch_number]) }}"
                                           class="tw-inline-flex tw-items-center tw-gap-1 tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-semibold tw-bg-amber-100 tw-text-amber-800 dark:tw-bg-amber-950 dark:tw-text-amber-300 tw-mt-1 tw-no-underline hover:tw-underline"
                                           title="{{ __('finance.drp_surface.open_refund') }}">
                                            <x-ui.icon name="alert-circle" size="xs" />
                                            <span>{{ __('finance.paid_ui.overpayment', ['amount' => number_format($batch->total_overpayment_amount, 0, ',', '.')]) }}</span>
                                        </a>
                                    @else
                                        <a href="{{ route('finance.overpayments.index', ['q' => $batch->batch_number]) }}"
                                           class="tw-inline-flex tw-items-center tw-gap-1 tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-semibold tw-bg-emerald-100 tw-text-emerald-800 dark:tw-bg-emerald-950 dark:tw-text-emerald-300 tw-mt-1 tw-no-underline hover:tw-underline"
                                           title="{{ __('finance.drp_surface.refund_history') }}">
                                            <x-ui.icon name="check-circle" size="xs" />
                                            <span>{{ __('finance.paid_ui.overpayment_settled', ['amount' => number_format($batch->total_overpayment_amount, 0, ',', '.')]) }}</span>
                                        </a>
                                    @endif
                                @endif
                            @elseif($batch->status === \App\Models\PaymentBatch::STATUS_PARTIALLY_PAID)
                                <div class="tw-flex tw-flex-col tw-gap-0.5">
                                    <span class="tw-text-ui-xs tw-text-primary tw-font-semibold">{{ __('finance.paid_ui.partial') }}</span>
                                    <div class="tw-text-[11px] tw-font-mono tw-text-on-surface-variant">
                                        <span class="tw-text-success tw-font-medium">{{ __('finance.paid_ui.paid_amount', ['amount' => number_format($batch->actual_paid_amount, 0, ',', '.')]) }}</span>
                                        <span class="tw-text-on-surface-variant">·</span>
                                        <span class="tw-text-warning-container-foreground tw-font-bold">{{ __('finance.paid_ui.remaining', ['amount' => number_format($batch->remaining_amount, 0, ',', '.')]) }}</span>
                                    </div>
                                    @if($batch->hasOverpayment())
                                        @if($batch->hasOpenOverpayment())
                                            <a href="{{ route('finance.overpayments.index', ['q' => $batch->batch_number]) }}"
                                               class="tw-inline-flex tw-items-center tw-gap-1 tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-semibold tw-bg-amber-100 tw-text-amber-800 dark:tw-bg-amber-950 dark:tw-text-amber-300 tw-mt-0.5 tw-no-underline hover:tw-underline"
                                               title="{{ __('finance.drp_surface.open_refund') }}">
                                                <x-ui.icon name="alert-circle" size="xs" />
                                                <span>{{ __('finance.paid_ui.overpayment', ['amount' => number_format($batch->total_overpayment_amount, 0, ',', '.')]) }}</span>
                                            </a>
                                        @else
                                            <a href="{{ route('finance.overpayments.index', ['q' => $batch->batch_number]) }}"
                                               class="tw-inline-flex tw-items-center tw-gap-1 tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-semibold tw-bg-emerald-100 tw-text-emerald-800 dark:tw-bg-emerald-950 dark:tw-text-emerald-300 tw-mt-0.5 tw-no-underline hover:tw-underline"
                                               title="{{ __('finance.drp_surface.refund_history') }}">
                                                <x-ui.icon name="check-circle" size="xs" />
                                                <span>{{ __('finance.paid_ui.overpayment_settled', ['amount' => number_format($batch->total_overpayment_amount, 0, ',', '.')]) }}</span>
                                            </a>
                                        @endif
                                    @endif
                                    @if($batch->hasUnvoucheredSupplierItems())
                                        <span class="tw-text-[11px] tw-text-warning-container-foreground tw-font-semibold tw-flex tw-items-center tw-gap-1 tw-mt-0.5">
                                            <x-ui.icon name="alert-triangle" size="sm" />
                                            <span>{{ __('finance.voucher.pending') }}</span>
                                        </span>
                                    @endif
                                </div>
                            @elseif($batch->status === \App\Models\PaymentBatch::STATUS_FINALIZED)
                                @if($batch->hasUnvoucheredSupplierItems())
                                    <div class="tw-flex tw-flex-col tw-gap-0.5">
                                        <span class="tw-text-ui-xs tw-text-warning-container-foreground tw-font-semibold tw-flex tw-items-center tw-gap-1">
                                            <x-ui.icon name="alert-triangle" size="sm" />
                                            <span>{{ __('finance.voucher.incomplete') }}</span>
                                        </span>
                                        <span class="tw-text-[11px] tw-text-on-surface-variant">{{ __('finance.paid_ui.generate_detail') }}</span>
                                    </div>
                                @else
                                    <span class="tw-text-ui-xs tw-text-on-surface-variant tw-font-medium">{{ __('finance.paid_ui.ready') }}</span>
                                @endif
                            @elseif($batch->status === \App\Models\PaymentBatch::STATUS_CANCELLED)
                                <span class="tw-text-ui-xs tw-text-error tw-font-medium">{{ __('local_invoice.labels.cancelled') }}</span>
                            @elseif($batch->status === \App\Models\PaymentBatch::STATUS_DRAFT)
                                <span class="tw-text-ui-xs tw-text-warning-container-foreground tw-font-medium">{{ __('finance.paid_ui.finalize_required') }}</span>
                            @else
                                <span class="tw-text-ui-xs tw-text-on-surface-variant tw-font-medium">-</span>
                            @endif
                        </td>
                        <td class="text-end">
                            <div class="tw-inline-flex tw-items-center tw-gap-1.5">
                                <x-ui.button :href="route('finance.drp.show', $batch)" size="sm" variant="outline">
                                    <x-ui.icon name="eye" size="sm" />
                                    <span>{{ __('local_invoice.actions.detail') }}</span>
                                </x-ui.button>

                                @if(in_array($batch->status, [\App\Models\PaymentBatch::STATUS_FINALIZED, \App\Models\PaymentBatch::STATUS_PARTIALLY_PAID]))
                                    @php
                                        $unvoucheredCount = $batch->unvoucheredSupplierItemsCount();
                                    @endphp

                                    @if($unvoucheredCount > 0)
                                        <span class="tw-inline-block" tabindex="0" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="{{ __('finance.paid_ui.missing_vouchers', ['count' => $unvoucheredCount]) }}">
                                            <x-ui.button
                                                type="button"
                                                size="sm"
                                                variant="secondary"
                                                disabled
                                                class="tw-opacity-50 tw-cursor-not-allowed"
                                            >
                                                <x-ui.icon name="badge-check" size="sm" />
                                                <span>{{ __('finance.payment.mark_paid') }}</span>
                                            </x-ui.button>
                                        </span>
                                    @else
                                        <x-ui.button
                                            type="button"
                                            size="sm"
                                            variant="primary"
                                            data-bs-toggle="modal"
                                            data-bs-target="#markPaidModal-{{ $batch->id }}"
                                        >
                                            <x-ui.icon name="badge-check" size="sm" />
                                            <span>{{ __('finance.payment.mark_paid') }}</span>
                                        </x-ui.button>
                                    @endif
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="text-center tw-py-8 tw-text-on-surface-variant tw-text-ui-sm">
                            {{ __('finance.drp.empty_filtered') }}
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
