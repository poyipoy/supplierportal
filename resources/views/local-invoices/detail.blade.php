@php
    $totalAmount = (float) $invoice->invoice_amount + (float) $invoice->tax_amount;
    $companyName = $invoice->supplier->supplier?->company_name ?: $invoice->supplier->name;
    $currentRevision = $invoice->revisions->where('revision_number', $invoice->revision_number)->first() ?? $invoice->revisions->first();
    $remDays = $invoice->remainingDays();
@endphp

@if($errors->any())
    <div class="alert alert-danger tw-mb-6" role="alert">
        <div class="tw-flex tw-items-center tw-gap-2 tw-font-semibold">
            <x-ui.icon name="alert-circle" size="sm" />
            <span>{{ $errors->first() }}</span>
        </div>
    </div>
@endif

<div class="tw-grid tw-gap-6 tw-pb-16">
    {{-- Top Page Header --}}
    <x-ui.page-header
        :title="'Invoice '.$invoice->invoice_number"
        :description="'Submission Ref: '.$invoice->submission_number.' · PO: '.$invoice->po_number"
        :eyebrow="'Vendor: '.$companyName"
    >
        <x-slot:meta>
            <x-ui.status-chip :tone="\App\Support\StatusHelper::localInvoiceTone($invoice->status)">
                {{ \App\Support\StatusHelper::localInvoiceLabel($invoice->status) }}
            </x-ui.status-chip>
            @if($invoice->physical_verified_at)
                <span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-2 tw-py-0.5 tw-rounded tw-text-ui-xs tw-bg-success/10 tw-text-success tw-font-medium">
                    <x-ui.icon name="check-circle" size="xs" /> Fisik Terverifikasi
                </span>
            @else
                <span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-2 tw-py-0.5 tw-rounded tw-text-ui-xs tw-bg-surface-container tw-text-on-surface-variant">
                    <x-ui.icon name="clock" size="xs" /> Menunggu Fisik Asli
                </span>
            @endif
        </x-slot:meta>

        <x-slot:actions>
            {{-- Quick action for Supplier on Revision --}}
            @if($invoice->status === 'NEED_REVISION' && auth()->user()->isSupplier())
                @can('resubmit', $invoice)
                    <x-ui.button :href="route('local-supplier.invoices.revision', $invoice)" variant="primary">
                        <x-ui.icon name="file-edit" size="sm" />
                        <span>Kirim Ulang Revisi</span>
                    </x-ui.button>
                @endcan
            @endif

            <x-ui.button :href="route($portal.'.invoices.receipt', $invoice)" variant="outline">
                <x-ui.icon name="receipt" size="sm" />
                <span>Kwitansi / Tanda Terima</span>
            </x-ui.button>

            <x-ui.button :href="route($portal.'.invoices.index')" variant="ghost">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>Kembali</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Supplier Need Revision Alert Banner --}}
    @if($invoice->status === 'NEED_REVISION')
        @php
            $revisionReason = $invoice->statusHistories->where('event', 'revision_requested')->last()?->notes;
        @endphp
        <div class="tw-rounded-ui-md tw-border tw-border-warning-container-foreground/30 tw-bg-warning-container/20 tw-p-4">
            <div class="tw-flex tw-items-start tw-gap-3">
                <div class="tw-w-8 tw-h-8 tw-rounded-full tw-bg-warning-container tw-text-warning-container-foreground tw-flex tw-items-center tw-justify-center tw-shrink-0">
                    <x-ui.icon name="alert-triangle" size="sm" />
                </div>
                <div class="tw-min-w-0 tw-flex-1">
                    <h4 class="tw-m-0 tw-text-ui-sm tw-font-bold tw-text-on-surface">Invoice Memerlukan Revisi</h4>
                    <p class="tw-m-0 tw-mt-1 tw-text-ui-xs tw-text-on-surface-variant">
                        <strong>Catatan Petugas Accounting:</strong> {{ $revisionReason ?: 'Harap perbaiki berkas sesuai permintaan tim verifikasi.' }}
                    </p>
                </div>
                @if(auth()->user()->isSupplier())
                    @can('resubmit', $invoice)
                        <div class="tw-shrink-0">
                            <x-ui.button :href="route('local-supplier.invoices.revision', $invoice)" variant="primary" size="sm">
                                <x-ui.icon name="file-edit" size="xs" /> Perbaiki Sekarang
                            </x-ui.button>
                        </div>
                    @endcan
                @endif
            </div>
        </div>
    @endif

    {{-- 2-Column Grid Layout --}}
    <div class="tw-grid tw-gap-6 lg:tw-grid-cols-12 tw-items-start">
        {{-- Left Column: Main Detail & Documents --}}
        <div class="lg:tw-col-span-8 tw-space-y-6">
            {{-- Financial & Reference Information --}}
            <x-ui.card title="Detail Tagihan & Pembayaran">
                <div class="tw-grid tw-gap-4 sm:tw-grid-cols-2">
                    <div>
                        <span class="tw-text-ui-xs tw-text-on-surface-variant tw-block">Nomor Invoice</span>
                        <span class="tw-font-semibold tw-text-ui-sm tw-text-on-surface">{{ $invoice->invoice_number }}</span>
                    </div>
                    <div>
                        <span class="tw-text-ui-xs tw-text-on-surface-variant tw-block">Tanggal Invoice</span>
                        <span class="tw-font-medium tw-text-ui-sm tw-text-on-surface">{{ $invoice->invoice_date->format('d M Y') }}</span>
                    </div>
                    <div>
                        <span class="tw-text-ui-xs tw-text-on-surface-variant tw-block">Nomor Purchase Order (PO)</span>
                        <span class="tw-font-semibold tw-text-ui-sm tw-text-on-surface">{{ $invoice->po_number }}</span>
                    </div>
                    <div>
                        <span class="tw-text-ui-xs tw-text-on-surface-variant tw-block">Vendor / Supplier</span>
                        <span class="tw-font-medium tw-text-ui-sm tw-text-on-surface">{{ $companyName }}</span>
                        <span class="tw-block tw-text-ui-xs tw-text-on-surface-variant">{{ $invoice->supplier->email }}</span>
                    </div>
                    @if($invoice->tax_invoice_number)
                        <div>
                            <span class="tw-text-ui-xs tw-text-on-surface-variant tw-block">Nomor Faktur Pajak (NSFP)</span>
                            <span class="tw-font-semibold tw-font-mono tw-text-ui-sm tw-text-primary">{{ $invoice->tax_invoice_number }}</span>
                        </div>
                    @endif
                </div>

                <div class="tw-border-t tw-border-outline-variant tw-mt-4 tw-pt-4">
                    <div class="tw-grid tw-gap-3 sm:tw-grid-cols-3 tw-p-3 tw-rounded-ui-sm tw-bg-surface-container-low">
                        <div>
                            <span class="tw-text-[11px] tw-text-on-surface-variant tw-uppercase tw-font-semibold tw-tracking-wider">Nilai DPP</span>
                            <span class="tw-block tw-font-mono tw-font-semibold tw-text-ui-sm tw-text-on-surface">
                                Rp {{ number_format((float) $invoice->invoice_amount, 0, ',', '.') }}
                            </span>
                            <span class="tw-text-[11px] tw-text-on-surface-variant tw-font-mono tw-block" title="Nilai Asli">
                                IDR {{ $invoice->invoice_amount }}
                            </span>
                        </div>
                        <div>
                            <span class="tw-text-[11px] tw-text-on-surface-variant tw-uppercase tw-font-semibold tw-tracking-wider">PPN</span>
                            <span class="tw-block tw-font-mono tw-font-semibold tw-text-ui-sm tw-text-on-surface">
                                Rp {{ number_format((float) $invoice->tax_amount, 0, ',', '.') }}
                            </span>
                            <span class="tw-text-[11px] tw-text-on-surface-variant tw-font-mono tw-block" title="Nilai Asli">
                                IDR {{ $invoice->tax_amount }}
                            </span>
                        </div>
                        <div>
                            <span class="tw-text-[11px] tw-text-primary tw-uppercase tw-font-semibold tw-tracking-wider">Total Pembayaran</span>
                            <span class="tw-block tw-font-mono tw-font-bold tw-text-ui-base tw-text-primary">
                                Rp {{ number_format((float) $totalAmount, 0, ',', '.') }}
                            </span>
                        </div>
                    </div>
                </div>
            </x-ui.card>

            {{-- Document Attachments (Current Revision) --}}
            <x-ui.card
                title="Dokumen Lampiran (Revisi {{ $invoice->revision_number }})"
                description="Dokumen digital resmi yang diunggah untuk pengajuan invoice ini."
            >
                @if($currentRevision && $currentRevision->documents->count())
                    <div class="tw-grid tw-gap-3 sm:tw-grid-cols-2">
                        @foreach($currentRevision->documents as $doc)
                            <div class="tw-flex tw-items-center tw-justify-between tw-gap-3 tw-p-3 tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface hover:tw-border-primary/50 tw-transition-colors">
                                <div class="tw-flex tw-items-center tw-gap-2.5 tw-min-w-0">
                                    <div class="tw-w-9 tw-h-9 tw-rounded tw-bg-primary/10 tw-text-primary tw-flex tw-items-center tw-justify-center tw-shrink-0">
                                        <x-ui.icon name="file-text" size="sm" />
                                    </div>
                                    <div class="tw-min-w-0">
                                        <span class="tw-font-semibold tw-text-ui-xs tw-text-on-surface tw-block tw-truncate">
                                            {{ ucwords(str_replace('_', ' ', $doc->document_type)) }}
                                        </span>
                                        <span class="tw-text-[11px] tw-text-on-surface-variant tw-truncate tw-block">
                                            {{ $doc->original_filename }}
                                        </span>
                                    </div>
                                </div>
                                <div class="tw-shrink-0">
                                    <x-ui.button
                                        :href="route('local-invoice-documents.show', $doc)"
                                        size="sm"
                                        variant="outline"
                                        target="_blank"
                                    >
                                        <x-ui.icon name="external-link" size="xs" />
                                        <span>Buka</span>
                                    </x-ui.button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="tw-text-center tw-py-6 tw-text-on-surface-variant tw-text-ui-xs">
                        Tidak ada dokumen lampiran untuk revisi ini.
                    </div>
                @endif
            </x-ui.card>

            {{-- Revision History (if multiple revisions exist) --}}
            @if($invoice->revisions->count() > 1)
                <x-ui.card title="Riwayat Versi / Revisi Dokumen">
                    <div class="tw-space-y-3">
                        @foreach($invoice->revisions->sortByDesc('revision_number') as $rev)
                            <div class="tw-border tw-border-outline-variant tw-rounded-ui-sm tw-p-3.5 {{ $rev->revision_number === $invoice->revision_number ? 'tw-bg-surface-container-low' : 'tw-bg-surface' }}">
                                <div class="tw-flex tw-items-center tw-justify-between tw-gap-2 tw-mb-2">
                                    <div class="tw-flex tw-items-center tw-gap-2">
                                        <span class="tw-font-bold tw-text-ui-xs tw-text-on-surface">Revisi {{ $rev->revision_number }}</span>
                                        @if($rev->revision_number === $invoice->revision_number)
                                            <span class="tw-px-2 tw-py-0.2 tw-rounded-full tw-text-[10px] tw-font-semibold tw-bg-primary/10 tw-text-primary">Aktif</span>
                                        @endif
                                    </div>
                                    <span class="tw-text-ui-xs tw-text-on-surface-variant">{{ $rev->created_at->format('d M Y, H:i') }}</span>
                                </div>
                                @if($rev->reason)
                                    <div class="tw-text-ui-xs tw-text-on-surface-variant tw-mb-2">
                                        <em>Alasan Revisi:</em> {{ $rev->reason }}
                                    </div>
                                @endif
                                <div class="tw-flex tw-flex-wrap tw-gap-2">
                                    @foreach($rev->documents as $revDoc)
                                        <a href="{{ route('local-invoice-documents.show', $revDoc) }}" target="_blank" class="tw-text-ui-xs tw-text-primary tw-underline hover:tw-text-primary/80">
                                            {{ ucwords(str_replace('_', ' ', $revDoc->document_type)) }}
                                        </a>
                                        @if(! $loop->last) <span class="tw-text-on-surface-variant">·</span> @endif
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </x-ui.card>
            @endif
        </div>

        {{-- Right Column: Sticky Workflow Actions, Status Stepper & Information (Offset accounts for 56px navbar) --}}
        <div class="lg:tw-col-span-4 tw-space-y-6 tw-sticky" style="top: calc(var(--topbar-height, 56px) + 1.25rem);">
            {{-- Payment & Due Date Card (Internal only, hidden from Supplier) --}}
            @if(!auth()->user()?->isSupplier())
            <x-ui.card title="Jadwal Pembayaran">
                <div class="tw-space-y-3">
                    <div class="tw-flex tw-justify-between tw-text-ui-xs">
                        <span class="tw-text-on-surface-variant">Payment Term:</span>
                        <span class="tw-font-semibold tw-text-on-surface">Net {{ $invoice->payment_term_days_snapshot }} Hari</span>
                    </div>

                    <div class="tw-flex tw-justify-between tw-items-center tw-text-ui-xs">
                        <span class="tw-text-on-surface-variant">Jatuh Tempo:</span>
                        <span class="tw-font-semibold tw-text-on-surface">{{ $invoice->due_date?->format('d M Y') ?? '—' }}</span>
                    </div>

                    @if($invoice->due_date && $remDays !== null)
                        <div class="tw-p-2.5 tw-rounded-ui-sm tw-text-center {{ $remDays < 0 ? 'tw-bg-error/10 tw-text-error' : ($remDays <= 3 ? 'tw-bg-warning-container tw-text-warning-container-foreground' : 'tw-bg-success/10 tw-text-success') }}">
                            <span class="tw-font-bold tw-text-ui-sm">
                                @if($remDays < 0)
                                    Overdue {{ abs($remDays) }} Hari
                                @elseif($remDays === 0)
                                    Jatuh Tempo Hari Ini
                                @else
                                    Tersisa {{ $remDays }} Hari
                                @endif
                            </span>
                        </div>
                    @endif

                    @if($invoice->scheduled_payment_date)
                        <div class="tw-flex tw-justify-between tw-text-ui-xs tw-border-t tw-border-outline-variant tw-pt-2">
                            <span class="tw-text-on-surface-variant">Jadwal Bayar ADASI:</span>
                            <span class="tw-font-bold tw-text-primary">{{ $invoice->scheduled_payment_date->format('d M Y') }}</span>
                        </div>
                    @endif

                    @if($invoice->completed_at)
                        <div class="tw-flex tw-justify-between tw-text-ui-xs tw-border-t tw-border-outline-variant tw-pt-2">
                            <span class="tw-text-on-surface-variant">Pembayaran Selesai:</span>
                            <span class="tw-font-bold tw-text-success">{{ $invoice->completed_at->format('d M Y, H:i') }}</span>
                        </div>
                    @endif
                </div>
            </x-ui.card>
            @endif

            {{-- Operator Workflow Actions Box (Only for Accounting/Finance) --}}
            @if(auth()->user()->isLocalOperator())
                <x-ui.card
                    title="Aksi Workflow Petugas"
                    description="Pilih tindakan sesuai tahapan verifikasi dokumen dan pembayaran."
                >
                    @if(!empty($workflowActions))
                        <div class="tw-space-y-4">
                            @foreach($workflowActions as $action => $label)
                                @php
                                    $isDestructive = in_array($action, ['reject']);
                                    $isWarning = in_array($action, ['request-revision']);
                                    $isPositive = in_array($action, ['approve', 'physical-verification', 'complete-payment']);
                                    $btnVariant = $isDestructive ? 'danger' : ($isWarning ? 'warning' : 'primary');
                                @endphp

                                <div class="tw-p-3 tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface-container-low">
                                    <form
                                        method="POST"
                                        action="{{ route('accounting.invoices.'.$action, $invoice) }}"
                                        class="local-workflow-form"
                                        data-confirm="{{ $label }}?"
                                    >
                                        @csrf

                                        <span class="tw-font-semibold tw-text-ui-xs tw-text-on-surface tw-block tw-mb-2">
                                            {{ $label }}
                                        </span>

                                        @if(in_array($action, ['request-revision', 'reject', 'physical-verification', 'schedule-payment', 'complete-payment']))
                                            <div class="tw-mb-2">
                                                <label for="notes-{{ $action }}" class="form-label tw-text-[11px] tw-text-on-surface-variant">
                                                    {{ in_array($action, ['request-revision', 'reject']) ? 'Alasan / Catatan (Wajib)' : 'Catatan Tambahan (Opsional)' }}
                                                    @if(in_array($action, ['request-revision', 'reject'])) <span class="text-danger">*</span> @endif
                                                </label>
                                                <textarea
                                                    class="form-control form-control-sm"
                                                    id="notes-{{ $action }}"
                                                    name="notes"
                                                    rows="2"
                                                    maxlength="5000"
                                                    placeholder="Tuliskan catatan verifikasi..."
                                                    @required(in_array($action, ['request-revision', 'reject']))
                                                >{{ old('notes') }}</textarea>
                                            </div>
                                        @endif

                                        @if($action === 'schedule-payment')
                                            <div class="tw-mb-3">
                                                <label for="scheduled-payment-date" class="form-label tw-text-[11px] tw-text-on-surface-variant">
                                                    Tanggal Jadwal Pembayaran <span class="text-danger">*</span>
                                                </label>
                                                <x-ui.date-picker
                                                    name="scheduled_payment_date"
                                                    id="scheduled-payment-date"
                                                    :value="old('scheduled_payment_date', $invoice->due_date?->format('Y-m-d'))"
                                                    required
                                                />
                                            </div>
                                        @endif

                                        <x-ui.button
                                            type="submit"
                                            :variant="$btnVariant"
                                            size="sm"
                                            class="w-100 tw-shadow-sm"
                                        >
                                            @if($isPositive) <x-ui.icon name="check" size="xs" />
                                            @elseif($isWarning) <x-ui.icon name="file-edit" size="xs" />
                                            @elseif($isDestructive) <x-ui.icon name="x-circle" size="xs" />
                                            @else <x-ui.icon name="play" size="xs" /> @endif
                                            <span>Konfirmasi {{ $label }}</span>
                                        </x-ui.button>
                                    </form>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="tw-text-center tw-py-4">
                            <span class="tw-text-ui-xs tw-text-on-surface-variant tw-block">
                                Tidak ada tindakan yang diperlukan untuk status saat ini.
                            </span>
                        </div>
                    @endif
                </x-ui.card>
            @endif

            {{-- Status & Verification Timeline Stepper (Balanced in Right Column) --}}
            <x-ui.card title="Jejak Status &amp; Verifikasi">
                <div class="tw-relative tw-ps-6 tw-space-y-6 before:tw-absolute before:tw-left-2.5 before:tw-top-2 before:tw-bottom-2 before:tw-w-0.5 before:tw-bg-outline-variant">
                    @forelse($invoice->statusHistories as $history)
                        <div class="tw-relative">
                            <div class="tw-absolute -tw-left-6 tw-top-0.5 tw-w-5 tw-h-5 tw-rounded-full tw-bg-surface tw-border-2 tw-border-primary tw-flex tw-items-center tw-justify-center">
                                <div class="tw-w-1.5 tw-h-1.5 tw-rounded-full tw-bg-primary"></div>
                            </div>
                            <div>
                                <div class="tw-flex tw-items-center tw-justify-between tw-gap-2">
                                    <span class="tw-font-semibold tw-text-ui-sm tw-text-on-surface">
                                        {{ ucwords(str_replace('_', ' ', $history->event)) }}
                                    </span>
                                    <span class="tw-text-ui-xs tw-text-on-surface-variant">
                                        {{ $history->created_at->format('d M Y, H:i') }}
                                    </span>
                                </div>
                                <div class="tw-text-ui-xs tw-text-on-surface-variant tw-mt-0.5">
                                    Oleh: <strong>{{ $history->actor->name }}</strong>
                                    @if($history->to_status)
                                        · Status: <x-ui.status-chip :tone="\App\Support\StatusHelper::localInvoiceTone($history->to_status)">
                                            {{ \App\Support\StatusHelper::localInvoiceLabel($history->to_status) }}
                                        </x-ui.status-chip>
                                    @endif
                                </div>
                                @if($history->notes)
                                    <div class="tw-mt-2 tw-p-2.5 tw-rounded tw-bg-surface-container tw-text-ui-xs tw-text-on-surface">
                                        {{ $history->notes }}
                                    </div>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="tw-text-ui-xs tw-text-on-surface-variant">Belum ada riwayat aktivitas.</div>
                    @endforelse
                </div>
            </x-ui.card>

            {{-- Physical Submission Guidelines Card --}}
            <x-ui.card title="Petunjuk Dokumen Fisik">
                <div class="tw-space-y-3 tw-text-ui-xs tw-text-on-surface-variant">
                    <div class="tw-flex tw-gap-2.5 tw-items-start">
                        <x-ui.icon name="map-pin" size="xs" class="tw-text-primary tw-mt-0.5 tw-shrink-0" />
                        <div>
                            <strong class="tw-text-on-surface tw-block">Loket Verifikasi:</strong>
                            Loket Accounting PT Astra Daido Steel Indonesia, Gd. Utama Lt. 1.
                        </div>
                    </div>
                    <div class="tw-flex tw-gap-2.5 tw-items-start">
                        <x-ui.icon name="file-badge" size="xs" class="tw-text-primary tw-mt-0.5 tw-shrink-0" />
                        <div>
                            <strong class="tw-text-on-surface tw-block">Ketentuan Materai:</strong>
                            Tagihan di atas Rp 5.000.000 wajib bermaterai Rp 10.000 dan dicap basah.
                        </div>
                    </div>
                    <div class="tw-flex tw-gap-2.5 tw-items-start">
                        <x-ui.icon name="receipt" size="xs" class="tw-text-primary tw-mt-0.5 tw-shrink-0" />
                        <div>
                            <strong class="tw-text-on-surface tw-block">Bukti Tanda Terima:</strong>
                            Gunakan tombol <em>Kwitansi / Tanda Terima</em> di atas saat menyerahkan berkas fisik.
                        </div>
                    </div>
                </div>
            </x-ui.card>
        </div>
    </div>
</div>

@include('local-invoices.scripts')
