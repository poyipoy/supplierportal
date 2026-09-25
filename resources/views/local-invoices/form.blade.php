@php
    $invoice = $invoice ?? null;
    $supplier = auth()->user()->supplier;
    $companyName = $supplier?->company_name ?: auth()->user()->name;
    $isPkp = (bool) ($supplier?->is_pkp ?? false);
    $vendorCategory = $supplier?->vendor_category ?: $supplier?->category ?: 'Barang';
    $requiresDeliveryNote = $vendorCategory === 'Barang';
    $selectedPo = old('local_purchase_order_id', $invoice->local_purchase_order_id ?? '');
    $selectedGrs = collect(old('goods_receipt_ids', $invoice?->goodsReceiptHistories?->whereIn('state', ['RESERVED', 'CONSUMED'])->pluck('local_goods_receipt_id')->all() ?? []))->map(fn ($v) => (string) $v)->all();

    $latestRevisionDocs = $invoice?->latestRevision?->documents ?? $invoice?->documents ?? collect();
    $existingInvoiceDocs = $latestRevisionDocs->where('document_type', 'invoice')->map(fn($d) => [
        'id' => $d->id,
        'name' => $d->original_filename,
        'size' => '',
        'url' => route('local-invoice-documents.show', $d),
    ])->values()->all();

    $existingTaxDocs = $latestRevisionDocs->where('document_type', 'tax_invoice')->map(fn($d) => [
        'id' => $d->id,
        'name' => $d->original_filename,
        'size' => '',
        'url' => route('local-invoice-documents.show', $d),
    ])->values()->all();

    $existingDnDocs = $latestRevisionDocs->where('document_type', 'delivery_note')->map(fn($d) => [
        'id' => $d->id,
        'name' => $d->original_filename,
        'size' => '',
        'url' => route('local-invoice-documents.show', $d),
    ])->values()->all();

    $existingSuppDocs = $latestRevisionDocs->where('document_type', 'supporting')->map(fn($d) => [
        'id' => $d->id,
        'name' => $d->original_filename,
        'size' => '',
        'url' => route('local-invoice-documents.show', $d),
    ])->values()->all();

    $hasStep2Errors = $errors->hasAny([
        'invoice_number', 'invoice_date', 'local_purchase_order_id', 'goods_receipt_ids',
        'invoice_amount', 'ppn_scheme', 'tax_amount', 'tax_invoice_number', 'scheduled_physical_delivery_date'
    ]);
    $hasStep1Errors = $errors->hasAny([
        'invoice', 'invoice.*', 'tax_invoice', 'tax_invoice.*', 'delivery_note', 'delivery_note.*', 'supporting', 'supporting.*'
    ]);
    $initialStep = ($hasStep2Errors && !$hasStep1Errors) ? 2 : 1;

    $localPoGrMap = collect($purchaseOrders ?? [])->mapWithKeys(function ($po) use ($invoice) {
        $remainingCeiling = $po->remainingCeiling($invoice?->id);

        return [
            (string) $po->id => [
                'po_id' => $po->id,
                'po_number' => $po->po_number,
                'total_amount' => (float) $po->total_amount,
                'remaining_ceiling' => $remainingCeiling,
                'grs' => $po->goodsReceipts->map(function ($gr) {
                    $description = $gr->description ?: $gr->notes ?: null;
                    $dateFormatted = $gr->gr_date ? $gr->gr_date->format('d M Y') : null;

                    return [
                        'value' => (string) $gr->id,
                        'label' => $gr->gr_number . ($gr->qty ? ' · ' . rtrim(rtrim(number_format((float) $gr->qty, 4, ',', '.'), '0'), ',') . ' pcs' : ''),
                        'description' => $description,
                        'sublabel' => $dateFormatted ? 'Tanggal: ' . $dateFormatted : null,
                        'qty' => (float) $gr->qty,
                        'date' => $gr->gr_date?->format('Y-m-d'),
                        'dateFormatted' => $dateFormatted,
                        'searchKeywords' => $gr->gr_number . ' ' . ($gr->qty ?? '') . ' ' . ($description ?? '') . ' ' . ($dateFormatted ?? ''),
                    ];
                })->values()->all(),
            ],
        ];
    });

    $poOptions = collect($purchaseOrders ?? [])->map(function ($po) {
        $sublabelParts = [];
        if ($po->po_date) {
            $sublabelParts[] = 'Tanggal: ' . $po->po_date->format('d M Y');
        }
        if ($po->notes) {
            $sublabelParts[] = Str::limit($po->notes, 35);
        }

        $nominalFormatted = number_format((float) $po->total_amount, 0, ',', '.');

        return [
            'value' => (string) $po->id,
            'label' => $po->po_number . ' · Rp ' . $nominalFormatted,
            'sublabel' => !empty($sublabelParts) ? implode(' · ', $sublabelParts) : null,
            'badge' => $po->status,
            'badgeTone' => $po->status === 'OPEN' ? 'success' : 'neutral',
            'searchKeywords' => $po->po_number . ' ' . $po->total_amount . ' ' . $nominalFormatted . ' ' . $po->status,
        ];
    })->all();

    $initialGrOptions = [];
    if (!empty($selectedPo)) {
        $initialGrOptions = ($localPoGrMap[(string) $selectedPo]['grs'] ?? []);
    }
@endphp

@if($errors->any())
    <x-ui.alert tone="error" title="Harap periksa dan perbaiki kesalahan pengajuan berikut:" class="tw-mb-6">
        <ul class="tw-mb-0 tw-mt-1.5 tw-list-disc tw-ps-4 tw-space-y-0.5 tw-text-ui-xs">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </x-ui.alert>
@endif

<form
    method="POST"
    enctype="multipart/form-data"
    action="{{ isset($invoice) ? route('local-supplier.invoices.resubmit', $invoice) : route('local-supplier.invoices.store') }}"
    id="localInvoiceForm"
    x-data="localInvoiceWizard({
        initialStep: {{ $initialStep }},
        isPkp: @js($isPkp),
        requiresDeliveryNote: @js($requiresDeliveryNote),
        isRevision: @js(isset($invoice)),
        existingInvoiceCount: {{ count($existingInvoiceDocs) }},
        existingTaxCount: {{ count($existingTaxDocs) }},
        existingDnCount: {{ count($existingDnDocs) }},
        existingSuppCount: {{ count($existingSuppDocs) }},
    })"
>
    @csrf

    {{-- Banner Profil Vendor (Global Header) --}}
    <div class="tw-mb-6 tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container tw-p-4">
        <div class="tw-flex tw-flex-col sm:tw-flex-row sm:tw-items-center sm:tw-justify-between tw-gap-3">
            <div class="tw-flex tw-items-center tw-gap-3">
                <div class="tw-w-10 tw-h-10 tw-rounded-full tw-bg-primary/10 tw-text-primary tw-flex tw-items-center tw-justify-center tw-shrink-0">
                    <x-ui.icon name="building-2" size="md" />
                </div>
                <div>
                    <div class="tw-text-ui-sm tw-font-bold tw-text-on-surface">{{ $companyName }}</div>
                    <div class="tw-text-ui-xs tw-text-on-surface-variant">{{ auth()->user()->email }}</div>
                </div>
            </div>
            <div class="tw-flex tw-flex-wrap tw-items-center tw-gap-2">
                <x-ui.status-chip :tone="$isPkp ? 'success' : 'neutral'">
                    {{ $isPkp ? 'PKP' : 'Non-PKP' }}
                </x-ui.status-chip>
                <span class="tw-inline-flex tw-items-center tw-gap-1.5 tw-px-2.5 tw-py-1 tw-rounded-full tw-text-ui-xs tw-font-medium tw-bg-surface-high tw-text-on-surface">
                    <x-ui.icon name="tag" size="sm" class="tw-text-on-surface-variant" />
                    <span>{{ $vendorCategory }}</span>
                </span>
            </div>
        </div>
    </div>

    {{-- Stepper Progress Bar (Tahap 1 & Tahap 2) --}}
    <div class="tw-mb-6 tw-bg-surface-container tw-rounded-ui-md tw-border tw-border-outline-variant tw-p-1.5 tw-grid tw-grid-cols-1 sm:tw-grid-cols-2 tw-gap-2">
        {{-- Tab Tahap 1: Upload Dokumen --}}
        <button
            type="button"
            @click="goToStep(1)"
            class="tw-flex tw-items-center tw-gap-3 tw-p-3 tw-rounded-ui-sm tw-text-start ui-motion"
            :class="step === 1 ? 'tw-bg-surface tw-shadow-sm tw-border tw-border-outline-variant' : 'hover:tw-bg-surface-container-high'"
        >
            <div
                class="tw-w-8 tw-h-8 tw-rounded-full tw-flex tw-items-center tw-justify-center tw-shrink-0 tw-text-ui-xs tw-font-bold ui-motion"
                :class="step === 1 ? 'tw-bg-primary tw-text-on-primary' : (isStep1Complete ? 'tw-bg-emerald-500/20 tw-text-emerald-700 dark:tw-text-emerald-300' : 'tw-bg-surface-high tw-text-on-surface-variant')"
            >
                <template x-if="isStep1Complete && step !== 1">
                    <x-ui.icon name="check" size="sm" class="tw-w-4 tw-h-4 tw-stroke-[2.5]" />
                </template>
                <template x-if="!isStep1Complete || step === 1">
                    <span>1</span>
                </template>
            </div>
            <div class="tw-min-w-0 tw-flex-1">
                <div class="tw-text-ui-xs tw-font-bold" :class="step === 1 ? 'tw-text-primary' : 'tw-text-on-surface'">
                    Tahap 1: Upload Dokumen Berkas
                </div>
                <div class="tw-text-[11px] tw-text-on-surface-variant tw-truncate">
                    Invoice fisik, faktur pajak & surat jalan
                </div>
            </div>
        </button>

        {{-- Tab Tahap 2: Informasi Tagihan --}}
        <button
            type="button"
            @click="goToStep(2)"
            class="tw-flex tw-items-center tw-gap-3 tw-p-3 tw-rounded-ui-sm tw-text-start ui-motion"
            :class="step === 2 ? 'tw-bg-surface tw-shadow-sm tw-border tw-border-outline-variant' : 'hover:tw-bg-surface-container-high'"
        >
            <div
                class="tw-w-8 tw-h-8 tw-rounded-full tw-flex tw-items-center tw-justify-center tw-shrink-0 tw-text-ui-xs tw-font-bold ui-motion"
                :class="step === 2 ? 'tw-bg-primary tw-text-on-primary' : 'tw-bg-surface-high tw-text-on-surface-variant'"
            >
                <span>2</span>
            </div>
            <div class="tw-min-w-0 tw-flex-1">
                <div class="tw-text-ui-xs tw-font-bold" :class="step === 2 ? 'tw-text-primary' : 'tw-text-on-surface'">
                    Tahap 2: Informasi Tagihan & Alokasi PO
                </div>
                <div class="tw-text-[11px] tw-text-on-surface-variant tw-truncate">
                    Nomor invoice, alokasi PO/GR & nilai DPP
                </div>
            </div>
        </button>
    </div>

    {{-- Error Banner untuk Validasi Antar-Tahap --}}
    <div x-show="errorMessage" x-cloak class="tw-mb-6">
        <x-ui.alert tone="error" title="Peringatan Kelengkapan Berkas">
            <span x-text="errorMessage"></span>
        </x-ui.alert>
    </div>

    {{-- ========================================================================= --}}
    {{-- TAHAP 1: UPLOAD DOKUMEN BERKAS LAMPIRAN                                     --}}
    {{-- ========================================================================= --}}
    <div x-show="step === 1" x-cloak class="tw-grid tw-gap-6 lg:tw-grid-cols-12 tw-items-start">
        <div class="lg:tw-col-span-8 tw-space-y-6">
            <x-ui.form-section title="Dokumen Lampiran Berkas" description="Penyimpanan privat terenkripsi di server; format berkas PDF, JPG, JPEG, atau PNG, maks. 5 MB per berkas (maks. 5 berkas per kategori).">
                <div class="tw-grid tw-gap-4 sm:tw-grid-cols-2">
                    <x-ui.file-upload
                        name="invoice"
                        id="file_invoice"
                        label="Berkas Invoice Fisik / Asli"
                        helper="Dokumen fisik invoice asli yang telah dicap & ditandatangani."
                        accept=".pdf,.jpg,.jpeg,.png"
                        :multiple="true"
                        :max-files="5"
                        :required="!isset($invoice)"
                        :existing-files="$existingInvoiceDocs"
                    />

                    <x-ui.file-upload
                        name="tax_invoice"
                        id="file_tax_invoice"
                        label="Faktur Pajak"
                        :helper="$isPkp ? 'Faktur pajak resmi Coretax / e-Faktur sesuai PPN tagihan.' : 'Dikecualikan bagi rekanan vendor berkategori Non-PKP.'"
                        accept=".pdf,.jpg,.jpeg,.png"
                        :multiple="true"
                        :max-files="5"
                        :required="$isPkp && !isset($invoice)"
                        :existing-files="$existingTaxDocs"
                    />

                    @if($requiresDeliveryNote)
                        <x-ui.file-upload
                            name="delivery_note"
                            id="file_delivery_note"
                            label="Surat Jalan (Delivery Note)"
                            helper="Surat jalan pengiriman fisik yang telah divalidasi oleh penerima."
                            accept=".pdf,.jpg,.jpeg,.png"
                            :multiple="true"
                            :max-files="5"
                            :required="!isset($invoice)"
                            :existing-files="$existingDnDocs"
                        />
                    @endif

                    <x-ui.file-upload
                        name="supporting"
                        id="file_supporting"
                        label="Dokumen Pendukung Tambahan"
                        helper="Lampiran pendukung seperti Berita Acara (BAP), PO copy, dsb."
                        accept=".pdf,.jpg,.jpeg,.png"
                        :multiple="true"
                        :max-files="5"
                        :existing-files="$existingSuppDocs"
                    />
                </div>

                {{-- Tips Ekstraksi Otomatis --}}
                <div class="tw-mt-4 tw-p-3.5 tw-rounded-ui-sm tw-bg-primary/5 tw-border tw-border-primary/20 tw-flex tw-items-start tw-gap-2.5">
                    <x-ui.icon name="sparkles" size="sm" class="tw-text-primary tw-mt-0.5 tw-shrink-0" />
                    <div class="tw-text-[11px] tw-text-on-surface-variant tw-leading-relaxed">
                        <strong class="tw-text-on-surface tw-font-semibold">Tips Ekstraksi Otomatis:</strong> Beri nama berkas invoice sesuai nomor invoice Anda (contoh: <code class="tw-font-mono tw-text-primary">INV-2026-001.pdf</code>) dan faktur pajak sesuai nomor faktur. Sistem akan mendeteksi dan mengisinya otomatis pada Tahap 2.
                    </div>
                </div>
            </x-ui.form-section>
        </div>

        {{-- Sidebar Tahap 1: Checklist Kelengkapan Berkas & Tombol Lanjut --}}
        <div class="lg:tw-col-span-4 tw-sticky" style="top: calc(var(--topbar-height, 56px) + 1.25rem)">
            <x-ui.card title="Kelengkapan Dokumen">
                <div class="tw-space-y-4">
                    <p class="tw-text-ui-xs tw-text-on-surface-variant tw-m-0">
                        Pastikan seluruh berkas wajib telah dipilih sebelum melanjutkan ke pengisian rincian tagihan.
                    </p>

                    <div class="tw-space-y-2.5 tw-text-ui-xs">
                        {{-- Item Invoice Fisik --}}
                        <div class="tw-flex tw-items-center tw-justify-between tw-p-2.5 tw-rounded-ui-sm tw-border ui-motion" :class="hasInvoice ? 'tw-border-success/30 tw-bg-success/5' : 'tw-border-outline-variant tw-bg-surface-container'">
                            <div class="tw-flex tw-items-center tw-gap-2.5 tw-min-w-0">
                                <div class="tw-w-5 tw-h-5 tw-rounded-full tw-flex tw-items-center tw-justify-center tw-shrink-0 ui-motion" :class="hasInvoice ? 'tw-bg-success tw-text-white' : 'tw-bg-error/20 tw-text-error'">
                                    <template x-if="hasInvoice"><x-ui.icon name="check" size="sm" class="tw-w-3 tw-h-3" /></template>
                                    <template x-if="!hasInvoice"><x-ui.icon name="x" size="sm" class="tw-w-3 tw-h-3" /></template>
                                </div>
                                <span class="tw-font-medium tw-text-on-surface tw-truncate">Invoice Fisik / Asli</span>
                            </div>
                            <span class="tw-text-[10px] tw-font-bold tw-uppercase" :class="hasInvoice ? 'tw-text-success' : 'tw-text-error'" x-text="hasInvoice ? 'Siap' : 'Wajib'"></span>
                        </div>

                        {{-- Item Faktur Pajak --}}
                        <div class="tw-flex tw-items-center tw-justify-between tw-p-2.5 tw-rounded-ui-sm tw-border ui-motion" :class="hasTax ? 'tw-border-success/30 tw-bg-success/5' : 'tw-border-outline-variant tw-bg-surface-container'">
                            <div class="tw-flex tw-items-center tw-gap-2.5 tw-min-w-0">
                                <div class="tw-w-5 tw-h-5 tw-rounded-full tw-flex tw-items-center tw-justify-center tw-shrink-0 ui-motion" :class="hasTax ? 'tw-bg-success tw-text-white' : (isPkp ? 'tw-bg-error/20 tw-text-error' : 'tw-bg-surface-high tw-text-on-surface-variant')">
                                    <template x-if="hasTax"><x-ui.icon name="check" size="sm" class="tw-w-3 tw-h-3" /></template>
                                    <template x-if="!hasTax"><x-ui.icon name="minus" size="sm" class="tw-w-3 tw-h-3" /></template>
                                </div>
                                <span class="tw-font-medium tw-text-on-surface tw-truncate">Faktur Pajak</span>
                            </div>
                            <span class="tw-text-[10px] tw-font-bold tw-uppercase" :class="hasTax ? 'tw-text-success' : (isPkp ? 'tw-text-error' : 'tw-text-on-surface-variant')" x-text="hasTax ? 'Siap' : (isPkp ? 'Wajib' : 'Opsional')"></span>
                        </div>

                        {{-- Item Surat Jalan --}}
                        <div class="tw-flex tw-items-center tw-justify-between tw-p-2.5 tw-rounded-ui-sm tw-border ui-motion" :class="hasDn ? 'tw-border-success/30 tw-bg-success/5' : 'tw-border-outline-variant tw-bg-surface-container'">
                            <div class="tw-flex tw-items-center tw-gap-2.5 tw-min-w-0">
                                <div class="tw-w-5 tw-h-5 tw-rounded-full tw-flex tw-items-center tw-justify-center tw-shrink-0 ui-motion" :class="hasDn ? 'tw-bg-success tw-text-white' : (requiresDeliveryNote ? 'tw-bg-error/20 tw-text-error' : 'tw-bg-surface-high tw-text-on-surface-variant')">
                                    <template x-if="hasDn"><x-ui.icon name="check" size="sm" class="tw-w-3 tw-h-3" /></template>
                                    <template x-if="!hasDn"><x-ui.icon name="minus" size="sm" class="tw-w-3 tw-h-3" /></template>
                                </div>
                                <span class="tw-font-medium tw-text-on-surface tw-truncate">Surat Jalan (DN)</span>
                            </div>
                            <span class="tw-text-[10px] tw-font-bold tw-uppercase" :class="hasDn ? 'tw-text-success' : (requiresDeliveryNote ? 'tw-text-error' : 'tw-text-on-surface-variant')" x-text="hasDn ? 'Siap' : (requiresDeliveryNote ? 'Wajib' : 'Opsional')"></span>
                        </div>

                        {{-- Item Dokumen Pendukung --}}
                        <div class="tw-flex tw-items-center tw-justify-between tw-p-2.5 tw-rounded-ui-sm tw-border ui-motion" :class="hasSupporting ? 'tw-border-success/30 tw-bg-success/5' : 'tw-border-outline-variant tw-bg-surface-container'">
                            <div class="tw-flex tw-items-center tw-gap-2.5 tw-min-w-0">
                                <div class="tw-w-5 tw-h-5 tw-rounded-full tw-flex tw-items-center tw-justify-center tw-shrink-0 ui-motion" :class="hasSupporting ? 'tw-bg-success tw-text-white' : 'tw-bg-surface-high tw-text-on-surface-variant'">
                                    <template x-if="hasSupporting"><x-ui.icon name="check" size="sm" class="tw-w-3 tw-h-3" /></template>
                                    <template x-if="!hasSupporting"><x-ui.icon name="minus" size="sm" class="tw-w-3 tw-h-3" /></template>
                                </div>
                                <span class="tw-font-medium tw-text-on-surface tw-truncate">Dokumen Pendukung</span>
                            </div>
                            <span class="tw-text-[10px] tw-font-medium tw-text-on-surface-variant" x-text="hasSupporting ? 'Terunggah' : 'Opsional'"></span>
                        </div>
                    </div>

                    <div class="tw-pt-3 tw-border-t tw-border-outline-variant">
                        <x-ui.button
                            type="button"
                            variant="primary"
                            class="tw-w-full tw-justify-center"
                            @click="goToStep(2)"
                        >
                            <span>Lanjut ke Informasi Tagihan</span>
                            <x-ui.icon name="arrow-right" size="sm" />
                        </x-ui.button>
                    </div>
                </div>
            </x-ui.card>
        </div>
    </div>

    {{-- ========================================================================= --}}
    {{-- TAHAP 2: INFORMASI INVOICE & ALOKASI PENERIMAAN BARANG (GR)               --}}
    {{-- ========================================================================= --}}
    <div x-show="step === 2" x-cloak class="tw-grid tw-gap-6 lg:tw-grid-cols-12 tw-items-start">
        <div class="lg:tw-col-span-8 tw-space-y-6">
            <x-ui.form-section title="Informasi Invoice & Alokasi Penerimaan Barang (GR)" description="Satu invoice terhubung ke satu PO dan satu atau lebih berkas Penerimaan Barang (GR) utuh. Nilai DPP Invoice tidak boleh melebihi sisa plafon PO.">
                <div class="tw-grid tw-gap-4 sm:tw-grid-cols-2">
                    <div>
                        <div class="tw-flex tw-items-center tw-justify-between tw-mb-1">
                            <label for="invoice-number" class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface tw-mb-0">
                                Nomor Invoice <span class="tw-text-error">*</span>
                            </label>
                            <span id="invoice-autofill-badge" class="tw-hidden tw-items-center tw-gap-1 tw-text-[11px] tw-text-primary tw-font-semibold tw-bg-primary/10 tw-px-2 tw-py-0.5 tw-rounded">
                                <x-ui.icon name="sparkles" size="sm" class="tw-w-3 tw-h-3" />
                                <span>Dari Nama Berkas</span>
                            </span>
                        </div>
                        <input
                            name="invoice_number"
                            id="invoice-number"
                            class="form-control form-control-sm"
                            value="{{ old('invoice_number', $invoice->invoice_number ?? '') }}"
                            placeholder="Contoh: INV/2026/09/001"
                            @readonly(isset($invoice))
                            required
                        >
                        <div id="invoice-autofill-filename" class="tw-text-[11px] tw-text-on-surface-variant tw-mt-1 tw-hidden">
                            Nomor terdeteksi: <strong class="tw-font-mono tw-text-primary"></strong>
                        </div>
                    </div>

                    <div>
                        <x-ui.date-picker
                            id="local_invoice_date"
                            name="invoice_date"
                            label="Tanggal Invoice"
                            :value="old('invoice_date', $invoice?->invoice_date?->format('Y-m-d') ?? now()->format('Y-m-d'))"
                            required="true"
                        />
                    </div>

                    <div>
                        <x-ui.searchable-select
                            name="local_purchase_order_id"
                            id="local_purchase_order_id"
                            label="Purchase Order (PO) Lokal"
                            placeholder="Pilih PO berstatus OPEN..."
                            search-placeholder="Cari nomor PO atau status..."
                            :options="$poOptions"
                            :value="$selectedPo"
                            :show-sublabel-on-trigger="false"
                            required
                        />
                    </div>

                    <div>
                        <x-ui.multi-select
                            name="goods_receipt_ids[]"
                            id="goods_receipt_ids"
                            label="Penerimaan Barang (GR) Utuh"
                            placeholder="Pilih GR yang sesuai..."
                            disabled-placeholder="Pilih PO terlebih dahulu..."
                            search-placeholder="Cari nomor atau deskripsi GR..."
                            :options="$initialGrOptions"
                            :value="$selectedGrs"
                            :disabled="empty($selectedPo)"
                            required
                        />
                    </div>

                    {{-- Baris Finansial 3 Kolom Berjajar: DPP, Skema PPN, dan Estimasi Nilai PPN --}}
                    <div class="sm:tw-col-span-2 tw-grid tw-grid-cols-1 md:tw-grid-cols-3 tw-gap-3 tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container tw-p-3.5">
                        <div>
                            <label for="invoice-amount" class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface">
                                DPP Invoice (IDR) <span class="tw-text-error">*</span>
                            </label>
                            <div class="tw-relative">
                                <input
                                    name="invoice_amount"
                                    id="invoice-amount"
                                    type="number"
                                    step="0.01"
                                    min="0.01"
                                    class="form-control form-control-sm tw-font-mono"
                                    value="{{ old('invoice_amount', $invoice->invoice_amount ?? '') }}"
                                    placeholder="0.00"
                                    required
                                >
                            </div>
                            <div class="tw-text-[11px] tw-text-on-surface-variant tw-mt-1">
                                Dasar Pengenaan Pajak (tidak boleh melebihi sisa plafon PO).
                            </div>
                        </div>

                        <div>
                            <label for="ppn-scheme" class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface">
                                Skema Tarif PPN <span class="tw-text-error">*</span>
                            </label>
                            <select name="ppn_scheme" id="ppn-scheme" class="form-select form-select-sm">
                                <option value="11%" @selected(old('ppn_scheme', $invoice->ppn_scheme ?? '11%') === '11%')>11% (Tarif Standar PPN)</option>
                                <option value="1.1%" @selected(old('ppn_scheme', $invoice->ppn_scheme ?? '') === '1.1%')>1.1% (Tarif Besaran Tertentu)</option>
                                <option value="0%" @selected(old('ppn_scheme', $invoice->ppn_scheme ?? '') === '0%')>0% (Non-PPN / Bebas Pajak)</option>
                            </select>
                            <div class="tw-text-[11px] tw-text-on-surface-variant tw-mt-1">
                                Pilih skema tarif yang tertera pada Faktur Pajak.
                            </div>
                        </div>

                        <div>
                            <label for="tax-amount" class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface">
                                Estimasi Nilai PPN (IDR)
                            </label>
                            <input
                                name="tax_amount"
                                id="tax-amount"
                                type="number"
                                step="0.01"
                                min="0"
                                class="form-control form-control-sm tw-font-mono"
                                value="{{ old('tax_amount', $invoice->tax_amount ?? '') }}"
                                placeholder="0.00"
                            >
                            <div class="tw-text-[11px] tw-text-on-surface-variant tw-mt-1">
                                Dihitung otomatis (DPP &times; Skema). Dapat disesuaikan bila ada selisih pembulatan.
                            </div>
                        </div>
                    </div>

                    {{-- Form Auto-Format Nomor Faktur Pajak (Coretax 17 Digit / e-Faktur 16 Digit) --}}
                    <div
                        class="sm:tw-col-span-2"
                        x-data="{
                            value: @js(old('tax_invoice_number', $invoice->tax_invoice_number ?? '')),
                            rawDigits: '',

                            init() {
                                this.rawDigits = (this.value || '').replace(/\D/g, '').slice(0, 17);
                                if (this.rawDigits.length > 0) {
                                    this.value = this.formatDigits(this.rawDigits);
                                }
                            },

                            formatDigits(digits) {
                                const d = (digits || '').replace(/\D/g, '').slice(0, 17);
                                if (!d) return '';
                                if (d.length === 16 && (this.value && this.value.includes('-'))) {
                                    return d.slice(0, 3) + '.' + d.slice(3, 6) + '-' + d.slice(6, 8) + '.' + d.slice(8);
                                }
                                if (d.length <= 2) return d;
                                if (d.length <= 4) return d.slice(0, 2) + '.' + d.slice(2);
                                if (d.length <= 6) return d.slice(0, 2) + '.' + d.slice(2, 4) + '.' + d.slice(4);
                                return d.slice(0, 2) + '.' + d.slice(2, 4) + '.' + d.slice(4, 6) + '.' + d.slice(6);
                            },

                            onInput(e) {
                                const input = e.target;
                                const oldCursor = input.selectionStart || 0;
                                const oldVal = input.value;
                                const digits = oldVal.replace(/\D/g, '').slice(0, 17);
                                this.rawDigits = digits;
                                const formatted = this.formatDigits(digits);
                                this.value = formatted;

                                const digitsBeforeCursor = oldVal.slice(0, oldCursor).replace(/\D/g, '').length;
                                this.$nextTick(() => {
                                    input.value = formatted;
                                    let newCursor = formatted.length;
                                    if (digitsBeforeCursor === 0) {
                                        newCursor = 0;
                                    } else if (digitsBeforeCursor < digits.length) {
                                        let dCount = 0;
                                        for (let i = 0; i < formatted.length; i++) {
                                            if (/\d/.test(formatted[i])) dCount++;
                                            if (dCount === digitsBeforeCursor) {
                                                newCursor = i + 1;
                                                break;
                                            }
                                        }
                                    }
                                    input.setSelectionRange(newCursor, newCursor);
                                });
                            },

                            onPaste(e) {
                                e.preventDefault();
                                const text = (e.clipboardData || window.clipboardData).getData('text') || '';
                                const digits = text.replace(/\D/g, '').slice(0, 17);
                                this.rawDigits = digits;
                                this.value = this.formatDigits(digits);
                                this.$nextTick(() => {
                                    const input = this.$refs.taxInput;
                                    if (input) {
                                        input.value = this.value;
                                        input.setSelectionRange(this.value.length, this.value.length);
                                    }
                                });
                            }
                        }"
                    >
                        <div class="tw-flex tw-items-center tw-justify-between tw-mb-1">
                            <div class="tw-flex tw-items-center tw-gap-2">
                                <label for="tax_invoice_number" class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface tw-mb-0">
                                    Nomor Faktur Pajak @if($isPkp) <span class="tw-text-error">*</span> @else <span class="tw-text-on-surface-variant tw-font-normal">(Opsional untuk Non-PKP)</span> @endif
                                </label>
                                <span id="tax-autofill-badge" class="tw-hidden tw-items-center tw-gap-1 tw-text-[11px] tw-text-primary tw-font-semibold tw-bg-primary/10 tw-px-2 tw-py-0.5 tw-rounded">
                                    <x-ui.icon name="sparkles" size="sm" class="tw-w-3 tw-h-3" />
                                    <span>Dari Nama Berkas</span>
                                </span>
                            </div>
                            <template x-if="rawDigits.length > 0">
                                <span
                                    class="tw-text-ui-xs tw-font-mono tw-transition-colors"
                                    :class="rawDigits.length === 17 || rawDigits.length === 16 ? 'tw-text-success tw-font-semibold' : 'tw-text-on-surface-variant'"
                                >
                                    <template x-if="rawDigits.length === 17">
                                        <span class="tw-inline-flex tw-items-center tw-gap-1">
                                            <x-ui.icon name="check" size="sm" class="tw-w-3 tw-h-3 tw-stroke-[3]" />
                                            <span>17/17 Digit Coretax</span>
                                        </span>
                                    </template>
                                    <template x-if="rawDigits.length === 16">
                                        <span class="tw-inline-flex tw-items-center tw-gap-1">
                                            <x-ui.icon name="check" size="sm" class="tw-w-3 tw-h-3 tw-stroke-[3]" />
                                            <span>16/16 Digit e-Faktur</span>
                                        </span>
                                    </template>
                                    <template x-if="rawDigits.length < 16">
                                        <span x-text="rawDigits.length + '/17 digit'"></span>
                                    </template>
                                </span>
                            </template>
                        </div>
                        <input
                            type="text"
                            name="tax_invoice_number"
                            id="tax_invoice_number"
                            x-ref="taxInput"
                            class="form-control form-control-sm tw-font-mono"
                            x-model="value"
                            @input="onInput($event)"
                            @paste="onPaste($event)"
                            placeholder="01.00.26.00000000001"
                            maxlength="20"
                            autocomplete="off"
                            @required($isPkp)
                        >
                        <div class="tw-mt-1.5 tw-flex tw-items-center tw-justify-between tw-text-ui-xs">
                            <span class="tw-text-on-surface-variant">
                                Format Coretax DJP: <strong class="tw-font-mono">01.00.XX.XXXXXXXXXXX</strong> (17 digit)
                            </span>
                            <template x-if="rawDigits.length === 17">
                                <span class="tw-text-success tw-font-semibold">Format Coretax Valid</span>
                            </template>
                            <template x-if="rawDigits.length === 16">
                                <span class="tw-text-success tw-font-semibold">Format e-Faktur Valid</span>
                            </template>
                        </div>
                    </div>

                    {{-- Jadwal Penyerahan Dokumen Fisik (Wajib Hari Rabu) --}}
                    <div class="sm:tw-col-span-2">
                        <x-ui.date-picker
                            id="local_invoice_delivery_date"
                            name="scheduled_physical_delivery_date"
                            label="Jadwal Penyerahan Dokumen Fisik (Loket Kasir)"
                            :value="old('scheduled_physical_delivery_date', $invoice?->scheduled_physical_delivery_date?->format('Y-m-d'))"
                            :min="now()->format('Y-m-d')"
                            :allowed-days-of-week="[3]"
                            allowed-days-message="Jadwal penyerahan berkas fisik hanya dilayani pada hari Rabu di loket Kasir PT ADASI."
                            helper="Loket kasir hanya melayani penerimaan berkas fisik setiap hari Rabu (pukul 08:30 - 16:00 WIB)."
                        />
                    </div>
                </div>
            </x-ui.form-section>
        </div>

        {{-- Sidebar Tahap 2: Ringkasan Finansial & Tombol Submit --}}
        <div class="lg:tw-col-span-4 tw-sticky" style="top: calc(var(--topbar-height, 56px) + 1.25rem)">
            <x-ui.card title="Ringkasan Pengajuan">
                <div class="tw-space-y-4">
                    {{-- Financial Breakdown --}}
                    <div class="tw-space-y-2.5 tw-text-ui-xs">
                        <div class="tw-flex tw-items-center tw-justify-between tw-text-on-surface-variant">
                            <span>Sisa Plafon PO:</span>
                            <span id="summary-po-ceiling" class="tw-font-mono tw-font-semibold tw-text-on-surface">Rp 0</span>
                        </div>
                        <div class="tw-flex tw-items-center tw-justify-between tw-text-on-surface-variant">
                            <span>DPP Invoice:</span>
                            <span id="summary-dpp" class="tw-font-mono tw-font-semibold tw-text-on-surface">Rp 0</span>
                        </div>
                        <div class="tw-flex tw-items-center tw-justify-between tw-text-on-surface-variant">
                            <span>Estimasi PPN:</span>
                            <span id="summary-tax" class="tw-font-mono tw-font-semibold tw-text-on-surface">Rp 0</span>
                        </div>
                        <div class="tw-flex tw-items-center tw-justify-between tw-border-t tw-border-outline-variant tw-pt-2.5 tw-text-ui-sm">
                            <span class="tw-font-bold tw-text-on-surface">Total Tagihan:</span>
                            <span id="summary-grand-total" class="tw-font-mono tw-font-bold tw-text-primary tw-text-ui-base">Rp 0</span>
                        </div>
                    </div>

                    {{-- Allocation & Validation Status Card --}}
                    <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container tw-p-3.5 tw-space-y-2.5">
                        <div class="tw-flex tw-items-center tw-justify-between tw-text-ui-xs">
                            <span class="tw-text-on-surface-variant">Penerimaan Barang:</span>
                            <span id="summary-gr-count" class="tw-font-medium tw-text-on-surface">0 Dokumen</span>
                        </div>
                        <div class="tw-flex tw-items-center tw-justify-between tw-text-ui-xs">
                            <span class="tw-text-on-surface-variant">Total Qty GR:</span>
                            <span id="summary-gr-qty" class="tw-font-mono tw-font-semibold tw-text-on-surface">0 pcs</span>
                        </div>
                        <div class="tw-flex tw-items-center tw-justify-between tw-text-ui-xs tw-border-t tw-border-outline-variant tw-pt-2">
                            <span class="tw-text-on-surface-variant">Status Plafon:</span>
                            <span id="summary-match-chip">
                                <span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-medium tw-bg-surface-high tw-text-on-surface-variant">
                                    Menunggu Pilihan PO
                                </span>
                            </span>
                        </div>
                        <div id="summary-diff-container" class="tw-hidden tw-text-[11px] tw-text-error tw-pt-1.5 tw-border-t tw-border-outline-variant">
                            <div class="tw-flex tw-items-center tw-justify-between">
                                <span>Melebihi Plafon PO:</span>
                                <strong id="summary-diff-amount" class="tw-font-mono">Rp 0</strong>
                            </div>
                        </div>
                    </div>

                    {{-- Petunjuk Pengajuan --}}
                    <div class="tw-text-ui-xs tw-text-on-surface-variant tw-pt-1">
                        <p class="tw-text-[11px] tw-text-on-surface-variant tw-m-0 tw-leading-relaxed">
                            Pastikan nominal DPP tidak melebihi sisa plafon PO dan minimal satu berkas GR terpilih agar verifikasi Finance dapat diproses.
                        </p>
                    </div>

                    {{-- Tombol Aksi Tahap 2 --}}
                    <div class="tw-pt-2 tw-space-y-2">
                        <x-ui.button
                            type="submit"
                            variant="primary"
                            class="tw-w-full tw-justify-center"
                            id="btnSubmitInvoice"
                        >
                            <x-ui.icon name="send" size="sm" />
                            <span>{{ isset($invoice) ? 'Kirim Revisi Invoice' : 'Ajukan Invoice' }}</span>
                        </x-ui.button>

                        <x-ui.button
                            type="button"
                            variant="ghost"
                            class="tw-w-full tw-justify-center tw-text-on-surface-variant hover:tw-text-on-surface"
                            @click="goToStep(1)"
                        >
                            <x-ui.icon name="arrow-left" size="sm" />
                            <span>Kembali ke Berkas</span>
                        </x-ui.button>
                    </div>
                </div>
            </x-ui.card>
        </div>
    </div>
</form>

<script>
function localInvoiceWizard(config) {
    return {
        step: config.initialStep || 1,
        isPkp: Boolean(config.isPkp),
        requiresDeliveryNote: Boolean(config.requiresDeliveryNote),
        isRevision: Boolean(config.isRevision),
        hasInvoice: (config.existingInvoiceCount > 0),
        hasTax: !config.isPkp || (config.existingTaxCount > 0),
        hasDn: !config.requiresDeliveryNote || (config.existingDnCount > 0),
        hasSupporting: (config.existingSuppCount > 0),
        errorMessage: '',

        init() {
            this.checkFiles();
            ['file_invoice', 'file_tax_invoice', 'file_delivery_note', 'file_supporting'].forEach(id => {
                const el = document.getElementById(id);
                el?.addEventListener('change', () => {
                    this.checkFiles();
                    if (this.isStep1Complete) {
                        this.errorMessage = '';
                    }
                });
            });
        },

        checkFiles() {
            const inv = document.getElementById('file_invoice');
            const tax = document.getElementById('file_tax_invoice');
            const dn = document.getElementById('file_delivery_note');
            const supp = document.getElementById('file_supporting');

            this.hasInvoice = (config.existingInvoiceCount > 0) || Boolean(inv?.files?.length > 0);
            this.hasTax = !this.isPkp || (config.existingTaxCount > 0) || Boolean(tax?.files?.length > 0);
            this.hasDn = !this.requiresDeliveryNote || (config.existingDnCount > 0) || Boolean(dn?.files?.length > 0);
            this.hasSupporting = (config.existingSuppCount > 0) || Boolean(supp?.files?.length > 0);
        },

        get isStep1Complete() {
            return this.hasInvoice && this.hasTax && this.hasDn;
        },

        goToStep(target) {
            if (target === 2) {
                this.checkFiles();
                if (!this.isStep1Complete) {
                    const missing = [];
                    if (!this.hasInvoice) missing.push('Berkas Invoice Fisik');
                    if (!this.hasTax) missing.push('Faktur Pajak');
                    if (!this.hasDn) missing.push('Surat Jalan (Delivery Note)');
                    this.errorMessage = 'Harap unggah seluruh berkas wajib berikut terlebih dahulu: ' + missing.join(', ') + '.';
                    return;
                }
                this.errorMessage = '';
                // Menghapus atribut required dari input file saat di step 2 agar browser tidak memblokir submit form karena input di-hidden
                ['file_invoice', 'file_tax_invoice', 'file_delivery_note'].forEach(id => {
                    document.getElementById(id)?.removeAttribute('required');
                });
            } else if (target === 1) {
                this.errorMessage = '';
            }
            this.step = target;
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    };
}

const localPoGr = @json($localPoGrMap);
const poSelect = document.getElementById('local_purchase_order_id');
const grSelect = document.getElementById('goods_receipt_ids');
const dppInput = document.getElementById('invoice-amount');
const taxInput = document.getElementById('tax-amount');
const schemeSelect = document.getElementById('ppn-scheme');
const summaryPoCeiling = document.getElementById('summary-po-ceiling');
const summaryGrCount = document.getElementById('summary-gr-count');
const summaryGrQty = document.getElementById('summary-gr-qty');
const summaryDpp = document.getElementById('summary-dpp');
const summaryTax = document.getElementById('summary-tax');
const summaryGrandTotal = document.getElementById('summary-grand-total');
const summaryMatchChip = document.getElementById('summary-match-chip');
const summaryDiffContainer = document.getElementById('summary-diff-container');
const summaryDiffAmount = document.getElementById('summary-diff-amount');
const submitBtn = document.getElementById('btnSubmitInvoice');

let selectedGrCount = 0;
let selectedGrQty = 0;

function rupiah(n) {
    return 'Rp ' + Number(n || 0).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function calculateTax() {
    if (!dppInput || !taxInput || !schemeSelect) return;
    const d = Number(dppInput.value || 0);
    const s = schemeSelect.value;
    const rate = s === '11%' ? 0.11 : (s === '1.1%' ? 0.011 : 0);
    taxInput.value = (d * rate).toFixed(2);
}

function updateValidation() {
    const poVal = poSelect?.value;
    const poData = (poVal && localPoGr[String(poVal)]) ? localPoGr[String(poVal)] : null;
    const poCeiling = poData ? Number(poData.remaining_ceiling || 0) : 0;
    const dpp = Number(dppInput?.value || 0);
    const tax = Number(taxInput?.value || 0);
    const grandTotal = dpp + tax;

    if (summaryPoCeiling) summaryPoCeiling.textContent = poData ? rupiah(poCeiling) : '—';
    if (summaryGrCount) summaryGrCount.textContent = selectedGrCount + ' Dokumen';
    if (summaryGrQty) summaryGrQty.textContent = selectedGrQty.toLocaleString('id-ID', { minimumFractionDigits: 0, maximumFractionDigits: 4 }) + ' pcs';
    if (summaryDpp) summaryDpp.textContent = rupiah(dpp);
    if (summaryTax) summaryTax.textContent = rupiah(tax);
    if (summaryGrandTotal) summaryGrandTotal.textContent = rupiah(grandTotal);

    if (!summaryMatchChip) return;

    if (!poVal) {
        summaryMatchChip.innerHTML = '<span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-medium tw-bg-surface-high tw-text-on-surface-variant">Pilih PO</span>';
        if (summaryDiffContainer) summaryDiffContainer.classList.add('tw-hidden');
    } else if (dpp <= 0) {
        summaryMatchChip.innerHTML = '<span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-medium tw-bg-surface-high tw-text-on-surface-variant">Input DPP</span>';
        if (summaryDiffContainer) summaryDiffContainer.classList.add('tw-hidden');
    } else if (dpp > poCeiling + 0.005) {
        const excess = dpp - poCeiling;
        summaryMatchChip.innerHTML = '<span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-semibold tw-bg-error/10 tw-text-error"><svg class="tw-w-3 tw-h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path></svg> Melebihi Plafon PO</span>';
        if (summaryDiffContainer) {
            summaryDiffContainer.classList.remove('tw-hidden');
            if (summaryDiffAmount) summaryDiffAmount.textContent = rupiah(excess);
        }
    } else {
        summaryMatchChip.innerHTML = '<span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-semibold tw-bg-success/10 tw-text-success"><svg class="tw-w-3 tw-h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path></svg> Memenuhi Plafon PO</span>';
        if (summaryDiffContainer) summaryDiffContainer.classList.add('tw-hidden');
    }
}

poSelect?.addEventListener('change', () => {
    const poVal = poSelect.value;
    const poData = (poVal && localPoGr[String(poVal)]) ? localPoGr[String(poVal)] : null;
    const rows = poData ? poData.grs : [];
    const emptyMsg = poVal ? 'Tidak ada GR TERSEDIA untuk PO ini.' : 'Pilih PO terlebih dahulu...';

    window.dispatchEvent(new CustomEvent('set-multi-options', {
        detail: {
            id: 'goods_receipt_ids',
            options: rows,
            autoSelect: true,
            emptyPlaceholder: emptyMsg,
        }
    }));

    updateValidation();
});

grSelect?.addEventListener('multi-select-change', (e) => {
    const options = e.detail?.options || [];
    selectedGrCount = options.length;
    selectedGrQty = options.reduce((sum, opt) => sum + (Number(opt.qty) || 0), 0);
    updateValidation();
});

dppInput?.addEventListener('input', () => {
    calculateTax();
    updateValidation();
});

taxInput?.addEventListener('input', () => {
    updateValidation();
});

schemeSelect?.addEventListener('change', () => {
    calculateTax();
    updateValidation();
});

// Initial state calculation
const initialDpp = Number(dppInput?.value || 0);
if (initialDpp > 0 && (!taxInput.value || Number(taxInput.value) === 0)) {
    calculateTax();
}

// Compute initial GR count and Qty if pre-selected
const initialPoVal = poSelect?.value;
if (initialPoVal && localPoGr[String(initialPoVal)]) {
    const preselectedGrs = @json($selectedGrs);
    const poGrs = localPoGr[String(initialPoVal)].grs;
    const matching = poGrs.filter(g => preselectedGrs.includes(String(g.value)));
    selectedGrCount = matching.length;
    selectedGrQty = matching.reduce((sum, g) => sum + (Number(g.qty) || 0), 0);
}
updateValidation();

// Filename Autofill for Invoice Number and Tax Invoice Number (R-02)
const fileInvoiceInput = document.getElementById('file_invoice');
const invoiceNumberInput = document.getElementById('invoice-number');
const invoiceAutofillBadge = document.getElementById('invoice-autofill-badge');
const invoiceAutofillFilename = document.getElementById('invoice-autofill-filename');

fileInvoiceInput?.addEventListener('change', function() {
    const files = Array.from(this.files || []);
    if (files.length === 0) return;

    const candidates = files.map(f => {
        const lastDot = f.name.lastIndexOf('.');
        return lastDot !== -1 ? f.name.substring(0, lastDot).trim() : f.name.trim();
    });

    const uniqueCandidates = [...new Set(candidates)];
    if (uniqueCandidates.length === 1 && uniqueCandidates[0] !== '') {
        const derived = uniqueCandidates[0];
        if (invoiceNumberInput && !invoiceNumberInput.hasAttribute('readonly')) {
            invoiceNumberInput.value = derived;
        }
        if (invoiceAutofillBadge) {
            invoiceAutofillBadge.classList.remove('tw-hidden');
            invoiceAutofillBadge.classList.add('tw-inline-flex');
        }
        if (invoiceAutofillFilename) {
            invoiceAutofillFilename.classList.remove('tw-hidden');
            const bold = invoiceAutofillFilename.querySelector('strong');
            if (bold) bold.textContent = derived;
        }
    } else if (uniqueCandidates.length > 1) {
        if (invoiceAutofillFilename) {
            invoiceAutofillFilename.classList.remove('tw-hidden');
            invoiceAutofillFilename.innerHTML = '<span class="tw-text-error">Peringatan: Berkas invoice yang dipilih memiliki nama berbeda (' + uniqueCandidates.join(', ') + ')</span>';
        }
    }
});

const fileTaxInput = document.getElementById('file_tax_invoice');
const taxInvoiceInput = document.getElementById('tax_invoice_number');
const taxAutofillBadge = document.getElementById('tax-autofill-badge');

fileTaxInput?.addEventListener('change', function() {
    const files = Array.from(this.files || []);
    if (files.length === 0) return;

    const candidates = files.map(f => {
        const lastDot = f.name.lastIndexOf('.');
        const base = lastDot !== -1 ? f.name.substring(0, lastDot).trim() : f.name.trim();
        const digits = base.replace(/\D/g, '');
        if (digits.length === 17) {
            return digits.slice(0, 2) + '.' + digits.slice(2, 4) + '.' + digits.slice(4, 6) + '.' + digits.slice(6);
        } else if (digits.length === 16) {
            return digits.slice(0, 3) + '.' + digits.slice(3, 6) + '-' + digits.slice(6, 8) + '.' + digits.slice(8);
        }
        return base;
    });

    const uniqueCandidates = [...new Set(candidates)];
    if (uniqueCandidates.length === 1 && uniqueCandidates[0] !== '') {
        taxInvoiceInput.value = uniqueCandidates[0];
        taxInvoiceInput.dispatchEvent(new Event('input', { bubbles: true }));
        if (taxAutofillBadge) {
            taxAutofillBadge.classList.remove('tw-hidden');
            taxAutofillBadge.classList.add('tw-inline-flex');
        }
    }
});

// Submit button loading state & pre-submit ceiling check
const invoiceForm = document.getElementById('localInvoiceForm');
invoiceForm?.addEventListener('submit', function(e) {
    const poVal = poSelect?.value;
    const poData = (poVal && localPoGr[String(poVal)]) ? localPoGr[String(poVal)] : null;
    const poCeiling = poData ? Number(poData.remaining_ceiling || 0) : 0;
    const dpp = Number(dppInput?.value || 0);

    if (poData && dpp > poCeiling + 0.005) {
        e.preventDefault();
        alert('Nominal DPP Invoice (' + rupiah(dpp) + ') melebihi sisa plafon PO (' + rupiah(poCeiling) + '). Harap sesuaikan nominal DPP.');
        dppInput?.focus();
        return false;
    }

    if (invoiceForm.checkValidity() && submitBtn) {
        submitBtn.setAttribute('disabled', 'true');
        submitBtn.classList.add('tw-opacity-80', 'tw-pointer-events-none');
        const iconSpan = submitBtn.querySelector('svg, .ui-icon');
        if (iconSpan) {
            iconSpan.classList.add('tw-animate-spin');
        }
    }
});
</script>
