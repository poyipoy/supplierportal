<div class="table-responsive">
    <table class="table table-hover align-middle tw-m-0 tw-text-ui-sm w-100">
        <thead class="table-light">
            @if(($portal ?? '') === 'finance')
                <tr>
                    <th scope="col" class="tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant">{{ __('local_invoice.labels.documents') }}</th>
                    <th scope="col" class="tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant">{{ __('local_invoice.labels.supplier') }}</th>
                    <th scope="col" class="tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant">{{ __('ga.labels.submission_date') }}</th>
                    <th scope="col" class="text-end tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant">{{ __('local_invoice.table.amount_ppn') }}</th>
                    <th scope="col" class="tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant">{{ __('local_invoice.labels.due_date') }}</th>
                    <th scope="col" class="tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant" style="min-width: 240px;">{{ __('local_invoice.table.status_actions') }}</th>
                </tr>
            @else
                <tr>
                    <th scope="col" class="tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant">{{ __('local_invoice.table.submission_receipt') }}</th>
                    <th scope="col" class="tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant">{{ __('local_invoice.table.invoice_po') }}</th>
                    @if(($portal ?? '') === 'accounting' || !auth()->user()?->isSupplier())
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant">{{ __('local_invoice.labels.supplier') }}</th>
                    @endif
                    <th scope="col" class="tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant">{{ __('ga.labels.submission_date') }}</th>
                    <th scope="col" class="text-end tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant">{{ __('local_invoice.table.amount_ppn') }}</th>
                    <th scope="col" class="tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant">{{ __('local_invoice.labels.status') }}</th>
                    @if(!auth()->user()?->isSupplier())
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant">{{ __('local_invoice.labels.due_date') }}</th>
                    @endif
                    @if($payments ?? false)
                        @if(!auth()->user()?->isSupplier() && ($portal ?? '') !== 'local-supplier')
                            <th scope="col" class="tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant">{{ __('local_invoice.labels.term_days') }}</th>
                        @endif
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant">{{ __('local_invoice.labels.payment_date') }}</th>
                    @endif
                    <th scope="col" class="text-end tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant" style="min-width: 130px;">{{ __('local_invoice.labels.actions') }}</th>
                </tr>
            @endif
        </thead>
        <tbody>
            @forelse($invoices as $row)
                @if(($portal ?? '') === 'finance')
                    <tr class="tw-transition-colors">
                        <td>
                            <span class="tw-font-semibold tw-text-on-surface">{{ $row->invoice_number }}</span>
                            <span class="tw-block tw-text-ui-xs tw-text-on-surface-variant tw-mt-0.5">
                                PO: {{ $row->po_number }} &middot; {{ $row->submission_number }}
                            </span>
                            @if($row->receipt?->receipt_number)
                                <span class="tw-inline-flex tw-items-center tw-gap-1 tw-text-[11px] tw-text-primary tw-mt-0.5">
                                    <x-ui.icon name="receipt" size="xs" class="tw-inline" /> {{ $row->receipt->receipt_number }}
                                </span>
                            @endif
                        </td>
                        <td>
                            <span class="tw-font-medium tw-text-on-surface">
                                {{ $row->supplier->supplier?->company_name ?: $row->supplier->name }}
                            </span>
                        </td>
                        <td>
                            <span class="tw-text-ui-xs tw-font-medium tw-text-on-surface">
                                {{ $row->submitted_at ? $regionalFormatter->timestamp($row->submitted_at, 'date') : '-' }}
                            </span>
                            <span class="tw-block tw-text-[11px] tw-text-on-surface-variant">
                                {{ $row->submitted_at ? $regionalFormatter->time($row->submitted_at) : '-' }}
                            </span>
                        </td>
                        <td class="text-end">
                            <span class="tw-font-semibold tw-font-mono tw-text-on-surface">
                                Rp {{ $regionalFormatter->number(number_format($row->invoice_amount, 0, ',', '.'), 'indonesian') }}
                            </span>
                            <span class="tw-block tw-text-ui-xs tw-font-mono tw-text-on-surface-variant">
                                PPN: Rp {{ $regionalFormatter->number(number_format($row->tax_amount, 0, ',', '.'), 'indonesian') }}
                            </span>
                        </td>
                        <td>
                            @if($row->due_date)
                                <span class="tw-text-ui-xs tw-font-medium tw-text-on-surface">
                                    {{ $regionalFormatter->date($row->due_date, 'human') }}
                                </span>
                                @php $rem = $row->remainingDays(); @endphp
                                @if($rem !== null)
                                    @if($rem < 0)
                                        <span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-1.5 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-medium tw-bg-error/10 tw-text-error tw-mt-0.5">
                                            <x-ui.icon name="alert-triangle" size="xs" /> {{ trans_choice('local_invoice.table.overdue_days', abs($rem)) }}
                                        </span>
                                    @elseif($rem <= 3)
                                        <span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-1.5 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-medium tw-bg-warning-container tw-text-warning-container-foreground tw-mt-0.5">
                                            <x-ui.icon name="clock" size="xs" /> {{ trans_choice('local_invoice.table.remaining_days', $rem) }}
                                        </span>
                                    @else
                                        <span class="tw-block tw-text-[11px] tw-text-on-surface-variant">
                                            {{ trans_choice('local_invoice.table.remaining_days', $rem) }}
                                        </span>
                                    @endif
                                @endif
                                @if($row->payment_term_days_snapshot)
                                    <span class="tw-block tw-text-[11px] tw-text-on-surface-variant tw-mt-0.5">
                                        {{ trans_choice('local_invoice.table.term_days', $row->payment_term_days_snapshot) }}
                                    </span>
                                @endif
                            @else
                                <span class="tw-text-ui-xs tw-text-on-surface-variant">&mdash;</span>
                            @endif
                        </td>
                        <td>
                            <div class="tw-flex tw-items-center tw-justify-between tw-gap-3">
                                <div class="tw-flex tw-flex-col tw-gap-1">
                                    <x-ui.status-chip :tone="\App\Support\StatusHelper::localInvoiceTone($row->status)">
                                        {{ \App\Support\StatusHelper::localInvoiceLabel($row->status) }}
                                    </x-ui.status-chip>
                                    @if($row->overpaymentRefund)
                                        @if($row->overpaymentRefund->status === \App\Models\SupplierOverpaymentRefund::STATUS_OPEN)
                                            <span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-semibold tw-bg-amber-100 tw-text-amber-800 dark:tw-bg-amber-950 dark:tw-text-amber-300 tw-w-max">
                                                <x-ui.icon name="alert-circle" size="xs" />
                                                {{ __('local_invoice.closure.overpayment_refund', ['amount' => $regionalFormatter->number(number_format((float) $row->overpaymentRefund->overpayment_amount, 0, ',', '.'), 'indonesian')]) }}
                                            </span>
                                        @elseif($row->overpaymentRefund->status === \App\Models\SupplierOverpaymentRefund::STATUS_SETTLED)
                                            <span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-semibold tw-bg-emerald-100 tw-text-emerald-800 dark:tw-bg-emerald-950 dark:tw-text-emerald-300 tw-w-max">
                                                <x-ui.icon name="check-circle" size="xs" />
                                                {{ __('finance.refund.completed') }}
                                            </span>
                                        @endif
                                    @endif
                                </div>
                                <x-ui.button
                                    :href="route($portal.'.invoices.show', $row)"
                                    size="sm"
                                    variant="outline"
                                    class="tw-shrink-0 tw-shadow-xs hover:tw-border-primary hover:tw-bg-primary/5"
                                >
                                    <x-ui.icon name="eye" size="sm" class="tw-text-primary" />
                                    <span>{{ __('local_invoice.table.view_detail') }}</span>
                                </x-ui.button>
                            </div>
                        </td>
                    </tr>
                @else
                    <tr class="tw-transition-colors">
                        <td>
                            <span class="tw-font-semibold tw-text-on-surface">{{ $row->submission_number }}</span>
                            @if($row->receipt?->receipt_number)
                                <span class="tw-block tw-text-ui-xs tw-text-on-surface-variant tw-mt-0.5">
                                    <x-ui.icon name="receipt" size="sm" class="tw-inline" /> {{ $row->receipt->receipt_number }}
                                </span>
                            @endif
                        </td>
                        <td>
                            <span class="tw-font-medium tw-text-on-surface">{{ $row->invoice_number }}</span>
                            <span class="tw-block tw-text-ui-xs tw-text-on-surface-variant tw-mt-0.5">
                                PO: {{ $row->po_number }}
                            </span>
                        </td>
                        @if(($portal ?? '') === 'accounting' || !auth()->user()?->isSupplier())
                            <td>
                                <span class="tw-font-medium tw-text-on-surface">
                                    {{ $row->supplier->supplier?->company_name ?: $row->supplier->name }}
                                </span>
                            </td>
                        @endif
                        <td>
                            <span class="tw-text-ui-xs tw-font-medium tw-text-on-surface">
                                {{ $row->submitted_at ? $regionalFormatter->timestamp($row->submitted_at, 'date') : '-' }}
                            </span>
                            <span class="tw-block tw-text-[11px] tw-text-on-surface-variant">
                                {{ $row->submitted_at ? $regionalFormatter->time($row->submitted_at) : '-' }}
                            </span>
                        </td>
                        <td class="text-end">
                            <span class="tw-font-semibold tw-font-mono tw-text-on-surface">
                                Rp {{ $regionalFormatter->number(number_format($row->invoice_amount, 0, ',', '.'), 'indonesian') }}
                            </span>
                            <span class="tw-block tw-text-ui-xs tw-font-mono tw-text-on-surface-variant">
                                PPN: Rp {{ $regionalFormatter->number(number_format($row->tax_amount, 0, ',', '.'), 'indonesian') }}
                            </span>
                        </td>
                        <td>
                            <x-ui.status-chip :tone="\App\Support\StatusHelper::localInvoiceTone($row->status)">
                                {{ \App\Support\StatusHelper::localInvoiceLabel($row->status) }}
                            </x-ui.status-chip>
                            @if($row->overpaymentRefund)
                                @if($row->overpaymentRefund->status === \App\Models\SupplierOverpaymentRefund::STATUS_OPEN)
                                    <span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-semibold tw-bg-amber-100 tw-text-amber-800 dark:tw-bg-amber-950 dark:tw-text-amber-300 tw-mt-1 tw-block tw-w-max">
                                        <x-ui.icon name="alert-circle" size="xs" />
                                        {{ __('local_invoice.closure.overpayment_refund', ['amount' => $regionalFormatter->number(number_format((float) $row->overpaymentRefund->overpayment_amount, 0, ',', '.'), 'indonesian')]) }}
                                    </span>
                                @elseif($row->overpaymentRefund->status === \App\Models\SupplierOverpaymentRefund::STATUS_SETTLED)
                                    <span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-semibold tw-bg-emerald-100 tw-text-emerald-800 dark:tw-bg-emerald-950 dark:tw-text-emerald-300 tw-mt-1 tw-block tw-w-max">
                                        <x-ui.icon name="check-circle" size="xs" />
                                        {{ __('finance.refund.completed') }}
                                    </span>
                                @endif
                            @endif
                        </td>
                        @if(!auth()->user()?->isSupplier())
                        <td>
                            @if($row->due_date)
                                <span class="tw-text-ui-xs tw-font-medium tw-text-on-surface">
                                    {{ $regionalFormatter->date($row->due_date, 'human') }}
                                </span>
                                @php $rem = $row->remainingDays(); @endphp
                                @if($rem !== null)
                                    @if($rem < 0)
                                        <span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-1.5 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-medium tw-bg-error/10 tw-text-error tw-mt-0.5">
                                            <x-ui.icon name="alert-triangle" size="sm" /> {{ trans_choice('common.final_review.overdue_days', abs($rem), ['count' => abs($rem)]) }}
                                        </span>
                                    @elseif($rem <= 3)
                                        <span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-1.5 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-medium tw-bg-warning-container tw-text-warning-container-foreground tw-mt-0.5">
                                            <x-ui.icon name="clock" size="sm" /> {{ trans_choice('common.final_review.remaining_days', $rem, ['count' => $rem]) }}
                                        </span>
                                    @else
                                        <span class="tw-block tw-text-ui-xs tw-text-on-surface-variant">
                                            {{ trans_choice('common.final_review.remaining_days', $rem, ['count' => $rem]) }}
                                        </span>
                                    @endif
                                @endif
                            @else
                                <span class="tw-text-ui-xs tw-text-on-surface-variant">—</span>
                            @endif
                        </td>
                        @endif
                        @if($payments ?? false)
                            @if(!auth()->user()?->isSupplier() && ($portal ?? '') !== 'local-supplier')
                                <td>
                                    <span class="tw-text-ui-xs tw-font-medium tw-text-on-surface">
                                        {{ trans_choice('common.final_review.net_days', $row->payment_term_days_snapshot, ['count' => $row->payment_term_days_snapshot]) }}
                                    </span>
                                </td>
                            @endif
                            <td>
                                <span class="tw-text-ui-xs tw-font-medium tw-text-on-surface">
                                    {{ $row->scheduled_payment_date ? $regionalFormatter->date($row->scheduled_payment_date, 'human') : '—' }}
                                </span>
                            </td>
                        @endif
                        <td class="text-end tw-whitespace-nowrap">
                            <x-ui.button
                                :href="route($portal.'.invoices.show', $row)"
                                size="sm"
                                variant="outline"
                                class="tw-shadow-sm hover:tw-border-primary hover:tw-bg-primary/5"
                            >
                                <x-ui.icon name="eye" size="sm" class="tw-text-primary" />
                                <span>{{ __('local_invoice.table.view_detail') }}</span>
                            </x-ui.button>
                        </td>
                    </tr>
                @endif
            @empty
                <tr>
                    <td colspan="{{ ($portal ?? '') === 'finance' ? 6 : ((in_array(($portal ?? ''), ['accounting', 'finance', 'purchasing']) ? 1 : 0) + ((($payments ?? false) && !auth()->user()?->isSupplier() && ($portal ?? '') !== 'local-supplier') ? 1 : 0) + (($payments ?? false) ? 1 : 0) + (!auth()->user()?->isSupplier() ? 1 : 0) + 6) }}">
                        <x-ui.empty-state
                            icon="inbox"
                            :title="__('local_invoice.empty.invoice')"
                            :description="__('local_invoice.empty.search_hint')"
                        />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
