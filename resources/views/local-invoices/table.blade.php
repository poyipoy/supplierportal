<div class="table-responsive">
    <table class="table table-hover align-middle tw-m-0 tw-text-ui-sm w-100">
        <thead class="table-light">
            <tr>
                <th scope="col" class="tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant">Submission / Receipt</th>
                <th scope="col" class="tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant">Invoice / PO</th>
                @if(($portal ?? '') === 'accounting')
                    <th scope="col" class="tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant">Supplier</th>
                @endif
                <th scope="col" class="tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant">Submitted</th>
                <th scope="col" class="text-end tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant">Amount / PPN (IDR)</th>
                <th scope="col" class="tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant">Status</th>
                <th scope="col" class="tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant">Due Date</th>
                @if($payments ?? false)
                    <th scope="col" class="tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant">Term (Days)</th>
                    <th scope="col" class="tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant">Scheduled Pay</th>
                @endif
                <th scope="col" class="text-end tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant" style="min-width: 130px;">Action</th>
            </tr>
        </thead>
        <tbody>
            @forelse($invoices as $row)
                <tr class="tw-transition-colors">
                    <td>
                        <span class="tw-font-semibold tw-text-on-surface">{{ $row->submission_number }}</span>
                        @if($row->receipt?->receipt_number)
                            <span class="tw-block tw-text-ui-xs tw-text-on-surface-variant tw-mt-0.5">
                                <x-ui.icon name="receipt" size="xs" class="tw-inline" /> {{ $row->receipt->receipt_number }}
                            </span>
                        @endif
                    </td>
                    <td>
                        <span class="tw-font-medium tw-text-on-surface">{{ $row->invoice_number }}</span>
                        <span class="tw-block tw-text-ui-xs tw-text-on-surface-variant tw-mt-0.5">
                            PO: {{ $row->po_number }}
                        </span>
                    </td>
                    @if(($portal ?? '') === 'accounting')
                        <td>
                            <span class="tw-font-medium tw-text-on-surface">
                                {{ $row->supplier->supplier?->company_name ?: $row->supplier->name }}
                            </span>
                        </td>
                    @endif
                    <td>
                        <span class="tw-text-ui-xs tw-font-medium tw-text-on-surface">
                            {{ $row->submitted_at?->format('d M Y') }}
                        </span>
                        <span class="tw-block tw-text-[11px] tw-text-on-surface-variant">
                            {{ $row->submitted_at?->format('H:i') }}
                        </span>
                    </td>
                    <td class="text-end">
                        <span class="tw-font-semibold tw-font-mono tw-text-on-surface">
                            Rp {{ number_format($row->invoice_amount, 0, ',', '.') }}
                        </span>
                        <span class="tw-block tw-text-ui-xs tw-font-mono tw-text-on-surface-variant">
                            PPN: Rp {{ number_format($row->tax_amount, 0, ',', '.') }}
                        </span>
                    </td>
                    <td>
                        <x-ui.status-chip :tone="\App\Support\StatusHelper::localInvoiceTone($row->status)">
                            {{ \App\Support\StatusHelper::localInvoiceLabel($row->status) }}
                        </x-ui.status-chip>
                    </td>
                    <td>
                        @if($row->due_date)
                            <span class="tw-text-ui-xs tw-font-medium tw-text-on-surface">
                                {{ $row->due_date->format('d M Y') }}
                            </span>
                            @php $rem = $row->remainingDays(); @endphp
                            @if($rem !== null)
                                @if($rem < 0)
                                    <span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-1.5 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-medium tw-bg-error/10 tw-text-error tw-mt-0.5">
                                        <x-ui.icon name="alert-triangle" size="xs" /> Overdue {{ abs($rem) }}d
                                    </span>
                                @elseif($rem <= 3)
                                    <span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-1.5 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-medium tw-bg-warning-container tw-text-warning-container-foreground tw-mt-0.5">
                                        <x-ui.icon name="clock" size="xs" /> {{ $rem }}d left
                                    </span>
                                @else
                                    <span class="tw-block tw-text-ui-xs tw-text-on-surface-variant">
                                        {{ $rem }} days left
                                    </span>
                                @endif
                            @endif
                        @else
                            <span class="tw-text-ui-xs tw-text-on-surface-variant">—</span>
                        @endif
                    </td>
                    @if($payments ?? false)
                        <td>
                            <span class="tw-text-ui-xs tw-font-medium tw-text-on-surface">
                                Net {{ $row->payment_term_days_snapshot }}d
                            </span>
                        </td>
                        <td>
                            <span class="tw-text-ui-xs tw-font-medium tw-text-on-surface">
                                {{ $row->scheduled_payment_date?->format('d M Y') ?? '—' }}
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
                            <span>Lihat Detail</span>
                        </x-ui.button>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ (($portal ?? '') === 'accounting' ? 1 : 0) + (($payments ?? false) ? 9 : 7) }}" class="text-center tw-py-12">
                        <div class="tw-flex tw-flex-col tw-items-center tw-justify-center tw-gap-2">
                            <div class="tw-w-12 tw-h-12 tw-rounded-full tw-bg-surface-container tw-flex tw-items-center tw-justify-center tw-text-on-surface-variant">
                                <x-ui.icon name="inbox" size="lg" />
                            </div>
                            <span class="tw-text-ui-sm tw-font-medium tw-text-on-surface">No invoices found</span>
                            <span class="tw-text-ui-xs tw-text-on-surface-variant">Try adjusting your filters or search keywords.</span>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
