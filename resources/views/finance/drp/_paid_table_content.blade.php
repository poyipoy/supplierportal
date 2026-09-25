{{-- Finance DRP Paid — Table content partial (rendered standalone for AJAX, @included in paid.blade.php for SSR) --}}
<x-ui.data-table
    title="Daftar Monitoring & Pelunasan Batch DRP"
    :description="'Menampilkan ' . $batches->total() . ' batch DRP terdaftar. Pilih batch Supplier untuk export transfer.'"
>
    <x-slot:toolbar>
        <x-ui.button
            type="button"
            variant="primary"
            size="sm"
            id="btnExportTransferPaid"
            disabled
            title="Pilih minimal satu batch DRP Supplier untuk melakukan export transfer"
        >
            <x-ui.icon name="download" size="sm" />
            <span>Export Transfer</span>
            <span id="exportTransferCountPaid" class="tw-hidden tw-ml-1.5 tw-inline-flex tw-items-center tw-justify-center tw-rounded-full tw-bg-white/20 tw-text-white tw-text-[10px] tw-font-bold tw-px-1.5 tw-py-0.5"></span>
        </x-ui.button>
    </x-slot:toolbar>
    <div class="table-responsive">
        <table class="table table-hover align-middle tw-m-0 tw-text-ui-sm w-100">
            <thead class="table-light">
                <tr>
                    <th scope="col" style="width: 40px;">
                        <input type="checkbox" class="form-check-input" id="selectAllBatchesPaid" title="Pilih semua batch Supplier">
                    </th>
                    <th scope="col" class="tw-text-ui-xs tw-font-semibold">Batch Number</th>
                    <th scope="col" class="tw-text-ui-xs tw-font-semibold">Tipe & Tanggal</th>
                    <th scope="col" class="tw-text-ui-xs tw-font-semibold text-center">Grup / Item</th>
                    <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">Total Tagihan (Rp)</th>
                    <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">Net Bayar (Rp)</th>
                    <th scope="col" class="tw-text-ui-xs tw-font-semibold text-center">Status</th>
                    <th scope="col" class="tw-text-ui-xs tw-font-semibold">Info Pembayaran</th>
                    <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">Aksi</th>
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
                                    {{ $batch->batch_type }}
                                </span>
                                <span class="tw-text-ui-xs tw-text-on-surface-variant">{{ $batch->created_at?->format('d M Y') }}</span>
                            </div>
                            <div class="tw-text-[11px] tw-text-on-surface-variant tw-mt-0.5">
                                Oleh: {{ $batch->creator?->name ?? 'System' }}
                            </div>
                        </td>
                        <td class="text-center">
                            <span class="tw-font-semibold">{{ $batch->groups_count }}</span>
                            <span class="tw-text-ui-xs tw-text-on-surface-variant">rekening</span>
                        </td>
                        <td class="text-end tw-font-mono">
                            Rp {{ number_format($batch->total_subtotal, 0, ',', '.') }}
                        </td>
                        <td class="text-end tw-font-mono">
                            <div class="tw-font-bold tw-text-on-surface">
                                Rp {{ number_format($batch->total_net_amount, 0, ',', '.') }}
                            </div>
                            @if($batch->hasOverpayment())
                                <div class="tw-text-[11px] tw-text-amber-700 dark:tw-text-amber-400 tw-font-semibold tw-mt-0.5" title="Nominal transfer riil melebihi net DRP">
                                    Transfer: Rp {{ number_format($batch->actual_transferred_amount, 0, ',', '.') }}
                                </div>
                            @endif
                            @if($batch->status === \App\Models\PaymentBatch::STATUS_PARTIALLY_PAID)
                                <div class="tw-text-[11px] tw-text-warning-container-foreground tw-font-semibold tw-mt-0.5" title="Sisa nominal yang belum lunas">
                                    Sisa: Rp {{ number_format($batch->remaining_amount, 0, ',', '.') }}
                                </div>
                            @endif
                        </td>
                        <td class="text-center">
                            <x-ui.status-chip :tone="\App\Support\StatusHelper::localFinanceTone($batch->status)">
                                {{ $batch->status }}
                            </x-ui.status-chip>
                        </td>
                        <td>
                            @if($batch->status === \App\Models\PaymentBatch::STATUS_PAID)
                                @php
                                    $sampleGroup = $batch->groups->firstWhere('status', 'PAID');
                                @endphp
                                <div class="tw-text-ui-xs tw-text-success tw-font-semibold tw-flex tw-items-center tw-gap-1">
                                    <x-ui.icon name="check-circle" size="sm" />
                                    <span>Lunas: {{ $batch->paid_at?->format('d M Y') ?? '-' }}</span>
                                </div>
                                @if($sampleGroup?->transfer_reference)
                                    <div class="tw-text-[11px] tw-font-mono tw-text-on-surface-variant">
                                        Ref: {{ $sampleGroup->transfer_reference }}
                                    </div>
                                @endif
                                @if($batch->hasOverpayment())
                                    @if($batch->hasOpenOverpayment())
                                        <a href="{{ route('finance.overpayments.index', ['q' => $batch->batch_number]) }}"
                                           class="tw-inline-flex tw-items-center tw-gap-1 tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-semibold tw-bg-amber-100 tw-text-amber-800 dark:tw-bg-amber-950 dark:tw-text-amber-300 tw-mt-1 tw-no-underline hover:tw-underline"
                                           title="Klik untuk membuka modul pengembalian dana overpayment">
                                            <x-ui.icon name="alert-circle" size="xs" />
                                            <span>Overpayment: Rp {{ number_format($batch->total_overpayment_amount, 0, ',', '.') }} (Open)</span>
                                        </a>
                                    @else
                                        <a href="{{ route('finance.overpayments.index', ['q' => $batch->batch_number]) }}"
                                           class="tw-inline-flex tw-items-center tw-gap-1 tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-semibold tw-bg-emerald-100 tw-text-emerald-800 dark:tw-bg-emerald-950 dark:tw-text-emerald-300 tw-mt-1 tw-no-underline hover:tw-underline"
                                           title="Klik untuk melihat riwayat penyelesaian overpayment">
                                            <x-ui.icon name="check-circle" size="xs" />
                                            <span>Overpayment Selesai (Rp {{ number_format($batch->total_overpayment_amount, 0, ',', '.') }})</span>
                                        </a>
                                    @endif
                                @endif
                            @elseif($batch->status === \App\Models\PaymentBatch::STATUS_PARTIALLY_PAID)
                                <div class="tw-flex tw-flex-col tw-gap-0.5">
                                    <span class="tw-text-ui-xs tw-text-primary tw-font-semibold">Sebagian Sudah Dibayar</span>
                                    <div class="tw-text-[11px] tw-font-mono tw-text-on-surface-variant">
                                        <span class="tw-text-success tw-font-medium">Terbayar: Rp {{ number_format($batch->actual_paid_amount, 0, ',', '.') }}</span>
                                        <span class="tw-text-on-surface-variant">·</span>
                                        <span class="tw-text-warning-container-foreground tw-font-bold">Sisa: Rp {{ number_format($batch->remaining_amount, 0, ',', '.') }}</span>
                                    </div>
                                    @if($batch->hasOverpayment())
                                        @if($batch->hasOpenOverpayment())
                                            <a href="{{ route('finance.overpayments.index', ['q' => $batch->batch_number]) }}"
                                               class="tw-inline-flex tw-items-center tw-gap-1 tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-semibold tw-bg-amber-100 tw-text-amber-800 dark:tw-bg-amber-950 dark:tw-text-amber-300 tw-mt-0.5 tw-no-underline hover:tw-underline"
                                               title="Klik untuk membuka modul pengembalian dana overpayment">
                                                <x-ui.icon name="alert-circle" size="xs" />
                                                <span>Overpayment: Rp {{ number_format($batch->total_overpayment_amount, 0, ',', '.') }} (Open)</span>
                                            </a>
                                        @else
                                            <a href="{{ route('finance.overpayments.index', ['q' => $batch->batch_number]) }}"
                                               class="tw-inline-flex tw-items-center tw-gap-1 tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-semibold tw-bg-emerald-100 tw-text-emerald-800 dark:tw-bg-emerald-950 dark:tw-text-emerald-300 tw-mt-0.5 tw-no-underline hover:tw-underline"
                                               title="Klik untuk melihat riwayat penyelesaian overpayment">
                                                <x-ui.icon name="check-circle" size="xs" />
                                                <span>Overpayment Selesai (Rp {{ number_format($batch->total_overpayment_amount, 0, ',', '.') }})</span>
                                            </a>
                                        @endif
                                    @endif
                                    @if($batch->hasUnvoucheredSupplierItems())
                                        <span class="tw-text-[11px] tw-text-warning-container-foreground tw-font-semibold tw-flex tw-items-center tw-gap-1 tw-mt-0.5">
                                            <x-ui.icon name="alert-triangle" size="sm" />
                                            <span>Ada voucher belum dibuat</span>
                                        </span>
                                    @endif
                                </div>
                            @elseif($batch->status === \App\Models\PaymentBatch::STATUS_FINALIZED)
                                @if($batch->hasUnvoucheredSupplierItems())
                                    <div class="tw-flex tw-flex-col tw-gap-0.5">
                                        <span class="tw-text-ui-xs tw-text-warning-container-foreground tw-font-semibold tw-flex tw-items-center tw-gap-1">
                                            <x-ui.icon name="alert-triangle" size="sm" />
                                            <span>Voucher Belum Lengkap</span>
                                        </span>
                                        <span class="tw-text-[11px] tw-text-on-surface-variant">Generate di Detail DRP</span>
                                    </div>
                                @else
                                    <span class="tw-text-ui-xs tw-text-on-surface-variant tw-font-medium">Siap Ditransfer / Dibayar</span>
                                @endif
                            @elseif($batch->status === \App\Models\PaymentBatch::STATUS_CANCELLED)
                                <span class="tw-text-ui-xs tw-text-error tw-font-medium">Dibatalkan</span>
                            @elseif($batch->status === \App\Models\PaymentBatch::STATUS_DRAFT)
                                <span class="tw-text-ui-xs tw-text-warning-container-foreground tw-font-medium">Perlu Difinalisasi</span>
                            @else
                                <span class="tw-text-ui-xs tw-text-on-surface-variant tw-font-medium">-</span>
                            @endif
                        </td>
                        <td class="text-end">
                            <div class="tw-inline-flex tw-items-center tw-gap-1.5">
                                <x-ui.button :href="route('finance.drp.show', $batch)" size="sm" variant="outline">
                                    <x-ui.icon name="eye" size="sm" />
                                    <span>Detail</span>
                                </x-ui.button>

                                @if(in_array($batch->status, [\App\Models\PaymentBatch::STATUS_FINALIZED, \App\Models\PaymentBatch::STATUS_PARTIALLY_PAID]))
                                    @php
                                        $unvoucheredCount = $batch->unvoucheredSupplierItemsCount();
                                    @endphp

                                    @if($unvoucheredCount > 0)
                                        <span class="tw-inline-block" tabindex="0" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Terdapat {{ $unvoucheredCount }} tagihan yang belum diterbitkan Voucher Bayar. Buka Detail DRP untuk generate voucher terlebih dahulu.">
                                            <x-ui.button
                                                type="button"
                                                size="sm"
                                                variant="secondary"
                                                disabled
                                                class="tw-opacity-50 tw-cursor-not-allowed"
                                            >
                                                <x-ui.icon name="badge-check" size="sm" />
                                                <span>Tandai Paid</span>
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
                                            <span>Tandai Paid</span>
                                        </x-ui.button>
                                    @endif
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="text-center tw-py-8 tw-text-on-surface-variant tw-text-ui-sm">
                            Tidak ada batch DRP yang sesuai dengan filter yang dipilih.
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
