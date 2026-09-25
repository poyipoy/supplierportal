<div class="table-responsive">
    <table class="table table-hover align-middle tw-m-0 tw-text-ui-sm w-100">
        <thead class="table-light">
            @if(($portal ?? '') === 'finance')
                <tr>
                    <th scope="col" class="tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant">Dokumen (Invoice & PO)</th>
                    <th scope="col" class="tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant">Supplier</th>
                    <th scope="col" class="tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant">Tanggal Pengajuan</th>
                    <th scope="col" class="text-end tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant">Nominal / PPN (IDR)</th>
                    <th scope="col" class="tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant">Jatuh Tempo</th>
                    <th scope="col" class="tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant" style="min-width: 240px;">Status & Aksi</th>
                </tr>
            @else
                <tr>
                    <th scope="col" class="tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant">Pengajuan / Tanda Terima</th>
                    <th scope="col" class="tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant">Invoice / PO</th>
                    @if(($portal ?? '') === 'accounting' || !auth()->user()?->isSupplier())
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant">Supplier</th>
                    @endif
                    <th scope="col" class="tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant">Tanggal Pengajuan</th>
                    <th scope="col" class="text-end tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant">Nominal / PPN (IDR)</th>
                    <th scope="col" class="tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant">Status</th>
                    @if(!auth()->user()?->isSupplier())
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant">Jatuh Tempo</th>
                    @endif
                    @if($payments ?? false)
                        @if(!auth()->user()?->isSupplier() && ($portal ?? '') !== 'local-supplier')
                            <th scope="col" class="tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant">Termin (Hari)</th>
                        @endif
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant">Jadwal Bayar</th>
                    @endif
                    <th scope="col" class="text-end tw-text-ui-xs tw-font-semibold tw-text-on-surface-variant" style="min-width: 130px;">Aksi</th>
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
                                {{ $row->submitted_at?->format('d M Y') }}
                            </span>
                            <span class="tw-block tw-text-[11px] tw-text-on-surface-variant">
                                {{ $row->submitted_at?->format('H:i') }} WIB
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
                            @if($row->due_date)
                                <span class="tw-text-ui-xs tw-font-medium tw-text-on-surface">
                                    {{ $row->due_date->format('d M Y') }}
                                </span>
                                @php $rem = $row->remainingDays(); @endphp
                                @if($rem !== null)
                                    @if($rem < 0)
                                        <span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-1.5 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-medium tw-bg-error/10 tw-text-error tw-mt-0.5">
                                            <x-ui.icon name="alert-triangle" size="xs" /> Terlewat {{ abs($rem) }} hari
                                        </span>
                                    @elseif($rem <= 3)
                                        <span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-1.5 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-medium tw-bg-warning-container tw-text-warning-container-foreground tw-mt-0.5">
                                            <x-ui.icon name="clock" size="xs" /> Sisa {{ $rem }} hari
                                        </span>
                                    @else
                                        <span class="tw-block tw-text-[11px] tw-text-on-surface-variant">
                                            Sisa {{ $rem }} hari
                                        </span>
                                    @endif
                                @endif
                                @if($row->payment_term_days_snapshot)
                                    <span class="tw-block tw-text-[11px] tw-text-on-surface-variant tw-mt-0.5">
                                        Net {{ $row->payment_term_days_snapshot }} hari
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
                                                Kelebihan Bayar: Rp {{ number_format((float) $row->overpaymentRefund->overpayment_amount, 0, ',', '.') }} (Perlu Refund)
                                            </span>
                                        @elseif($row->overpaymentRefund->status === \App\Models\SupplierOverpaymentRefund::STATUS_SETTLED)
                                            <span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-semibold tw-bg-emerald-100 tw-text-emerald-800 dark:tw-bg-emerald-950 dark:tw-text-emerald-300 tw-w-max">
                                                <x-ui.icon name="check-circle" size="xs" />
                                                Refund Selesai
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
                                    <span>Lihat Detail</span>
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
                            @if($row->overpaymentRefund)
                                @if($row->overpaymentRefund->status === \App\Models\SupplierOverpaymentRefund::STATUS_OPEN)
                                    <span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-semibold tw-bg-amber-100 tw-text-amber-800 dark:tw-bg-amber-950 dark:tw-text-amber-300 tw-mt-1 tw-block tw-w-max">
                                        <x-ui.icon name="alert-circle" size="xs" />
                                        Kelebihan Bayar: Rp {{ number_format((float) $row->overpaymentRefund->overpayment_amount, 0, ',', '.') }} (Perlu Refund)
                                    </span>
                                @elseif($row->overpaymentRefund->status === \App\Models\SupplierOverpaymentRefund::STATUS_SETTLED)
                                    <span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-semibold tw-bg-emerald-100 tw-text-emerald-800 dark:tw-bg-emerald-950 dark:tw-text-emerald-300 tw-mt-1 tw-block tw-w-max">
                                        <x-ui.icon name="check-circle" size="xs" />
                                        Refund Selesai
                                    </span>
                                @endif
                            @endif
                        </td>
                        @if(!auth()->user()?->isSupplier())
                        <td>
                            @if($row->due_date)
                                <span class="tw-text-ui-xs tw-font-medium tw-text-on-surface">
                                    {{ $row->due_date->format('d M Y') }}
                                </span>
                                @php $rem = $row->remainingDays(); @endphp
                                @if($rem !== null)
                                    @if($rem < 0)
                                        <span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-1.5 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-medium tw-bg-error/10 tw-text-error tw-mt-0.5">
                                            <x-ui.icon name="alert-triangle" size="sm" /> Terlewat {{ abs($rem) }} hari
                                        </span>
                                    @elseif($rem <= 3)
                                        <span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-1.5 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-medium tw-bg-warning-container tw-text-warning-container-foreground tw-mt-0.5">
                                            <x-ui.icon name="clock" size="sm" /> Sisa {{ $rem }} hari
                                        </span>
                                    @else
                                        <span class="tw-block tw-text-ui-xs tw-text-on-surface-variant">
                                            Sisa {{ $rem }} hari
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
                                        Net {{ $row->payment_term_days_snapshot }} hari
                                    </span>
                                </td>
                            @endif
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
                @endif
            @empty
                <tr>
                    <td colspan="{{ ($portal ?? '') === 'finance' ? 6 : ((in_array(($portal ?? ''), ['accounting', 'finance', 'purchasing']) ? 1 : 0) + ((($payments ?? false) && !auth()->user()?->isSupplier() && ($portal ?? '') !== 'local-supplier') ? 1 : 0) + (($payments ?? false) ? 1 : 0) + (!auth()->user()?->isSupplier() ? 1 : 0) + 6) }}">
                        <x-ui.empty-state
                            icon="inbox"
                            title="Tidak ada invoice ditemukan"
                            description="Coba sesuaikan filter atau kata kunci pencarian Anda."
                        />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
