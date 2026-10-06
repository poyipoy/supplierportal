{{-- Purchasing DRP Paid — Table content partial (rendered standalone for AJAX, @included in paid.blade.php for SSR) --}}
<x-ui.data-table
    :title="__('finance.drp.paid_monitor')"
    :description="trans_choice('finance.closure.batch_count', $batches->total())"
>
    <div class="table-responsive">
        <table class="table table-hover align-middle tw-m-0 tw-text-ui-sm w-100">
            <thead class="table-light">
                <tr>
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
                            <strong class="tw-font-mono tw-text-on-surface">{{ $batch->batch_number }}</strong>
                            @if($batch->notes)
                                <div class="tw-text-[11px] tw-text-on-surface-variant tw-truncate tw-max-w-xs" title="{{ $batch->notes }}">
                                    {{ $batch->notes }}
                                </div>
                            @endif
                        </td>
                        <td>
                            <div class="tw-flex tw-items-center tw-gap-1.5">
                                <span class="tw-inline-flex tw-items-center tw-px-1.5 tw-py-0.5 tw-rounded tw-text-[10px] tw-font-semibold {{ $batch->batch_type === 'SUPPLIER' ? 'tw-bg-primary/10 tw-text-primary' : 'tw-bg-secondary/10 tw-text-secondary' }}">
                                    {{ __('finance.closure.batch_type_'.strtolower($batch->batch_type)) }}
                                </span>
                                <span class="tw-text-ui-xs tw-text-on-surface-variant">{{ $regionalFormatter->date($batch->created_at, 'human') }}</span>
                            </div>
                            <div class="tw-text-[11px] tw-text-on-surface-variant tw-mt-0.5">
                                {{ __('common.final_copy.created_by', ['name' => $batch->creator?->name ?? __('common.final_copy.system')]) }}
                            </div>
                        </td>
                        <td class="text-center">
                            <span class="tw-font-semibold">{{ $batch->groups_count }}</span>
                            <span class="tw-text-ui-xs tw-text-on-surface-variant">{{ __('common.labels_review.accounts') }}</span>
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
                            <x-ui.status-chip :tone="\App\Support\StatusHelper::localFinanceTone($batch->status)">
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
                                        <span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-semibold tw-bg-amber-100 tw-text-amber-800 dark:tw-bg-amber-950 dark:tw-text-amber-300 tw-mt-1"
                                              title="{{ __('finance.drp_surface.refund_outstanding') }}">
                                            <x-ui.icon name="alert-circle" size="xs" />
                                            <span>{{ __('finance.paid_ui.overpayment', ['amount' => number_format($batch->total_overpayment_amount, 0, ',', '.')]) }}</span>
                                        </span>
                                    @else
                                        <span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-semibold tw-bg-emerald-100 tw-text-emerald-800 dark:tw-bg-emerald-950 dark:tw-text-emerald-300 tw-mt-1"
                                              title="{{ __('finance.drp_surface.refund_completed') }}">
                                            <x-ui.icon name="check-circle" size="xs" />
                                            <span>{{ __('finance.paid_ui.overpayment_settled', ['amount' => number_format($batch->total_overpayment_amount, 0, ',', '.')]) }}</span>
                                        </span>
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
                                </div>
                            @elseif($batch->status === \App\Models\PaymentBatch::STATUS_FINALIZED)
                                <span class="tw-text-ui-xs tw-text-on-surface-variant tw-font-medium">{{ __('finance.drp_surface.ready_transfer') }}</span>
                            @elseif($batch->status === \App\Models\PaymentBatch::STATUS_CANCELLED)
                                <span class="tw-text-ui-xs tw-text-error tw-font-medium">{{ __('local_invoice.labels.cancelled') }}</span>
                            @elseif($batch->status === \App\Models\PaymentBatch::STATUS_DRAFT)
                                <span class="tw-text-ui-xs tw-text-warning-container-foreground tw-font-medium">{{ __('finance.drp_surface.draft_status') }}</span>
                            @else
                                <span class="tw-text-ui-xs tw-text-on-surface-variant tw-font-medium">-</span>
                            @endif
                        </td>
                        <td class="text-end">
                            <x-ui.button :href="route('purchasing.drp.show', $batch)" size="sm" variant="outline">
                                <x-ui.icon name="eye" size="sm" />
                                <span>{{ __('local_invoice.actions.detail') }}</span>
                            </x-ui.button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-center tw-py-8 tw-text-on-surface-variant tw-text-ui-sm">
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
