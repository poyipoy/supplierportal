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
                    $dateFormatted = $gr->gr_date ? app(\App\Services\RegionalDisplayFormatter::class)->date($gr->gr_date, 'human') : null;

                    return [
                        'value' => (string) $gr->id,
                        'label' => $gr->gr_number . ($gr->qty ? ' Â· ' . rtrim(rtrim(number_format((float) $gr->qty, 4, ',', '.'), '0'), ',') . ' pcs' : ''),
                        'description' => $description,
                        'sublabel' => $dateFormatted ? __('local_invoice.labels.date') . ': ' . $dateFormatted : null,
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
            $sublabelParts[] = __('local_invoice.labels.date') . ': ' . app(\App\Services\RegionalDisplayFormatter::class)->date($po->po_date, 'human');
        }
        if ($po->notes) {
            $sublabelParts[] = Str::limit($po->notes, 35);
        }

        $nominalFormatted = number_format((float) $po->total_amount, 0, ',', '.');

        return [
            'value' => (string) $po->id,
            'label' => $po->po_number . ' Â· Rp ' . $nominalFormatted,
            'sublabel' => !empty($sublabelParts) ? implode(' Â· ', $sublabelParts) : null,
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
    <x-ui.alert tone="error" title="{{ __('local_invoice.labels.submission_errors') }}:" class="tw-mb-6">
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
    <div
        class="tw-mb-6 tw-bg-surface-container tw-rounded-ui-md tw-border tw-border-outline-variant tw-p-1.5 tw-grid tw-grid-cols-1 sm:tw-grid-cols-2 tw-gap-2"
        role="tablist"
        aria-label="{{ __('local_invoice.form.steps') }}"
    >
        {{-- Tab Tahap 1: Upload Dokumen --}}
        <button
            type="button"
            @click="goToStep(1)"
            class="tw-w-full tw-flex tw-items-center tw-justify-between tw-gap-3 tw-p-3.5 tw-rounded-ui-sm tw-text-start tw-border tw-transition-all tw-duration-200 active:tw-scale-[0.99] ui-focus-ring"
            :class="step === 1
                ? 'tw-bg-surface tw-border-primary/30 tw-shadow-sm tw-ring-1 tw-ring-primary/10'
                : 'tw-border-transparent hover:tw-bg-surface/80 hover:tw-border-outline-variant/60 hover:tw-shadow-xs'"
            role="tab"
            :aria-selected="step === 1"
            aria-label="{{ __('local_invoice.form.documents_step') }}"
        >
            <div class="tw-flex tw-items-center tw-gap-3.5 tw-min-w-0 tw-flex-1">
                <div
                    class="tw-w-8 tw-h-8 tw-rounded-full tw-flex tw-items-center tw-justify-center tw-shrink-0 tw-text-ui-xs tw-font-bold tw-transition-all tw-duration-200"
                    :class="{
                        'tw-bg-primary tw-text-white tw-shadow-xs tw-ring-4 tw-ring-primary/15': step === 1,
                        'tw-bg-emerald-600 tw-text-white tw-shadow-xs': isStep1Complete && step !== 1,
                        'tw-bg-surface-high tw-text-on-surface-variant': !isStep1Complete && step !== 1
                    }"
                >
                    <template x-if="isStep1Complete && step !== 1">
                        <x-ui.icon name="check" size="sm" class="tw-w-4 tw-h-4 tw-stroke-[2.5]" />
                    </template>
                    <template x-if="!isStep1Complete || step === 1">
                        <span class="tw-leading-none">1</span>
                    </template>
                </div>
                <div class="tw-min-w-0 tw-flex-1">
                    <div
                        class="tw-text-ui-xs tw-font-bold tw-truncate tw-transition-colors"
                        :class="step === 1 ? 'tw-text-primary' : 'tw-text-on-surface'"
                    >
                        {{ __('local_invoice.form.documents_step') }}
                    </div>
                    <div class="tw-text-[11px] tw-text-on-surface-variant tw-truncate tw-mt-0.5">
                        {{ __('local_invoice.form.documents_hint') }}
                    </div>
                </div>
            </div>

            {{-- Contextual Status Badge --}}
            <div class="tw-shrink-0 tw-flex tw-items-center">
                <template x-if="step === 1 && !isStep1Complete">
                    <span class="tw-inline-flex tw-items-center tw-gap-1.5 tw-px-2.5 tw-py-0.5 tw-rounded-full tw-text-[10px] tw-font-semibold tw-bg-primary/10 tw-text-primary">
                        <span class="tw-w-1.5 tw-h-1.5 tw-rounded-full tw-bg-primary tw-animate-pulse"></span>
                        <span>{{ __('local_invoice.form.in_progress') }}</span>
                    </span>
                </template>
                <template x-if="isStep1Complete">
                    <span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-2.5 tw-py-0.5 tw-rounded-full tw-text-[10px] tw-font-semibold tw-bg-emerald-500/15 tw-text-emerald-700 dark:tw-text-emerald-300">
                        <x-ui.icon name="check" size="xs" class="tw-w-3 tw-h-3 tw-stroke-[2.5]" />
                        <span>{{ __('local_invoice.form.complete') }}</span>
                    </span>
                </template>
                <template x-if="step !== 1 && !isStep1Complete">
                    <span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-2.5 tw-py-0.5 tw-rounded-full tw-text-[10px] tw-font-medium tw-bg-warning/15 tw-text-warning">
                        <x-ui.icon name="alert-circle" size="xs" class="tw-w-3 tw-h-3" />
                        <span>{{ __('local_invoice.form.incomplete') }}</span>
                    </span>
                </template>
            </div>
        </button>

        {{-- Tab Tahap 2: Informasi Tagihan --}}
        <button
            type="button"
            @click="goToStep(2)"
            class="tw-w-full tw-flex tw-items-center tw-justify-between tw-gap-3 tw-p-3.5 tw-rounded-ui-sm tw-text-start tw-border tw-transition-all tw-duration-200 active:tw-scale-[0.99] ui-focus-ring"
            :class="{
                'tw-bg-surface tw-border-primary/30 tw-shadow-sm tw-ring-1 tw-ring-primary/10': step === 2,
                'tw-border-transparent hover:tw-bg-surface/80 hover:tw-border-outline-variant/60 hover:tw-shadow-xs': step !== 2 && isStep1Complete,
                'tw-border-transparent tw-opacity-80 hover:tw-bg-surface/50 hover:tw-border-outline-variant/40': step !== 2 && !isStep1Complete
            }"
            role="tab"
            :aria-selected="step === 2"
            aria-label="{{ __('local_invoice.form.invoice_step') }}"
        >
            <div class="tw-flex tw-items-center tw-gap-3.5 tw-min-w-0 tw-flex-1">
                <div
                    class="tw-w-8 tw-h-8 tw-rounded-full tw-flex tw-items-center tw-justify-center tw-shrink-0 tw-text-ui-xs tw-font-bold tw-transition-all tw-duration-200"
                    :class="{
                        'tw-bg-primary tw-text-white tw-shadow-xs tw-ring-4 tw-ring-primary/15': step === 2,
                        'tw-bg-primary/15 tw-text-primary tw-border tw-border-primary/30': isStep1Complete && step !== 2,
                        'tw-bg-surface-high tw-text-on-surface-variant': !isStep1Complete && step !== 2
                    }"
                >
                    <span class="tw-leading-none">2</span>
                </div>
                <div class="tw-min-w-0 tw-flex-1">
                    <div
                        class="tw-text-ui-xs tw-font-bold tw-truncate tw-transition-colors"
                        :class="step === 2 ? 'tw-text-primary' : 'tw-text-on-surface'"
                    >
                        {{ __('local_invoice.form.invoice_step') }}
                    </div>
                    <div class="tw-text-[11px] tw-text-on-surface-variant tw-truncate tw-mt-0.5">
                        {{ __('local_invoice.form.invoice_hint') }}
                    </div>
                </div>
            </div>

            {{-- Contextual Status Badge --}}
            <div class="tw-shrink-0 tw-flex tw-items-center">
                <template x-if="step === 2">
                    <span class="tw-inline-flex tw-items-center tw-gap-1.5 tw-px-2.5 tw-py-0.5 tw-rounded-full tw-text-[10px] tw-font-semibold tw-bg-primary/10 tw-text-primary">
                        <span class="tw-w-1.5 tw-h-1.5 tw-rounded-full tw-bg-primary tw-animate-pulse"></span>
                        <span>{{ __('local_invoice.form.in_progress') }}</span>
                    </span>
                </template>
                <template x-if="step !== 2 && isStep1Complete">
                    <span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-2.5 tw-py-0.5 tw-rounded-full tw-text-[10px] tw-font-semibold tw-bg-primary/10 tw-text-primary">
                        <x-ui.icon name="arrow-right" size="xs" class="tw-w-3 tw-h-3" />
                        <span>{{ __('local_invoice.form.ready') }}</span>
                    </span>
                </template>
                <template x-if="step !== 2 && !isStep1Complete">
                    <span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-2.5 tw-py-0.5 tw-rounded-full tw-text-[10px] tw-font-medium tw-bg-surface-high tw-text-on-surface-variant">
                        <x-ui.icon name="lock" size="xs" class="tw-w-3 tw-h-3" />
                        <span>{{ __('local_invoice.form.await_first_step') }}</span>
                    </span>
                </template>
            </div>
        </button>
    </div>

    {{-- Error Banner untuk Validasi Antar-Tahap --}}
    <div x-show="errorMessage" x-cloak class="tw-mb-6">
        <x-ui.alert tone="error" :title="__('common.final_review.file_warning')">
            <span x-text="errorMessage"></span>
        </x-ui.alert>
    </div>

    {{-- ========================================================================= --}}
    {{-- TAHAP 1: UPLOAD DOKUMEN BERKAS LAMPIRAN                                     --}}
    {{-- ========================================================================= --}}
    <div x-show="step === 1" x-cloak class="tw-grid tw-gap-6 lg:tw-grid-cols-12 tw-items-start">
        <div class="lg:tw-col-span-8 tw-space-y-6">
            <x-ui.form-section :title="__('local_invoice.labels.documents_grid')" :description="__('common.final_review.private_uploads')">
                <div class="tw-grid tw-gap-4 sm:tw-grid-cols-2">
                    <x-ui.file-upload
                        name="invoice"
                        id="file_invoice"
                        :label="__('common.final_review.original_invoice')"
                        :helper="__('common.final_copy.physical_invoice_help')"
                        accept=".pdf,.jpg,.jpeg,.png"
                        :multiple="true"
                        :max-files="5"
                        :required="!isset($invoice)"
                        :existing-files="$existingInvoiceDocs"
                    />

                    <x-ui.file-upload
                        name="tax_invoice"
                        id="file_tax_invoice"
                        :label="__('local_invoice.labels.tax_invoice')"
                        :helper="$isPkp ? __('local_invoice.closure.tax_invoice_help') : __('local_invoice.closure.tax_exempt_help')"
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
                            :label="__('local_invoice.labels.delivery_note')"
                            :helper="__('common.final_copy.delivery_note_help')"
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
                        :label="__('common.final_review.supporting_docs')"
                        :helper="__('local_invoice.closure.supporting_documents')"
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
                        <strong class="tw-text-on-surface tw-font-semibold">{{ __('local_invoice.form.extraction_title') }}</strong> {{ __('local_invoice.form.extraction_help', ['filename' => 'INV-2026-001.pdf']) }}
                    </div>
                </div>
            </x-ui.form-section>
        </div>

        {{-- Sidebar Tahap 1: Checklist Kelengkapan Berkas & Tombol Lanjut --}}
        <div class="lg:tw-col-span-4 tw-sticky" style="top: calc(var(--topbar-height, 56px) + 1.25rem)">
            <x-ui.card :title="__('common.final_review.document_completeness')">
                <div class="tw-space-y-4">
                    <p class="tw-text-ui-xs tw-text-on-surface-variant tw-m-0">
                        {{ __('local_invoice.form.documents_required_help') }}
                    </p>

                    <div class="tw-space-y-2.5 tw-text-ui-xs">
                        {{-- Item Invoice Fisik --}}
                        <div class="tw-flex tw-items-center tw-justify-between tw-p-2.5 tw-rounded-ui-sm tw-border ui-motion" :class="hasInvoice ? 'tw-border-success/30 tw-bg-success/5' : 'tw-border-outline-variant tw-bg-surface-container'">
                            <div class="tw-flex tw-items-center tw-gap-2.5 tw-min-w-0">
                                <div class="tw-w-5 tw-h-5 tw-rounded-full tw-flex tw-items-center tw-justify-center tw-shrink-0 ui-motion" :class="hasInvoice ? 'tw-bg-success tw-text-white' : 'tw-bg-error/20 tw-text-error'">
                                    <template x-if="hasInvoice"><x-ui.icon name="check" size="sm" class="tw-w-3 tw-h-3" /></template>
                                    <template x-if="!hasInvoice"><x-ui.icon name="x" size="sm" class="tw-w-3 tw-h-3" /></template>
                                </div>
                                <span class="tw-font-medium tw-text-on-surface tw-truncate">{{ __('local_invoice.labels.physical_invoice') }}</span>
                            </div>
                            <span class="tw-text-[10px] tw-font-bold tw-uppercase" :class="hasInvoice ? 'tw-text-success' : 'tw-text-error'" x-text="hasInvoice ? readyLabel : requiredLabel"></span>
                        </div>

                        {{-- Item Faktur Pajak --}}
                        <div class="tw-flex tw-items-center tw-justify-between tw-p-2.5 tw-rounded-ui-sm tw-border ui-motion" :class="hasTax ? 'tw-border-success/30 tw-bg-success/5' : 'tw-border-outline-variant tw-bg-surface-container'">
                            <div class="tw-flex tw-items-center tw-gap-2.5 tw-min-w-0">
                                <div class="tw-w-5 tw-h-5 tw-rounded-full tw-flex tw-items-center tw-justify-center tw-shrink-0 ui-motion" :class="hasTax ? 'tw-bg-success tw-text-white' : (isPkp ? 'tw-bg-error/20 tw-text-error' : 'tw-bg-surface-high tw-text-on-surface-variant')">
                                    <template x-if="hasTax"><x-ui.icon name="check" size="sm" class="tw-w-3 tw-h-3" /></template>
                                    <template x-if="!hasTax"><x-ui.icon name="minus" size="sm" class="tw-w-3 tw-h-3" /></template>
                                </div>
                                <span class="tw-font-medium tw-text-on-surface tw-truncate">{{ __('local_invoice.labels.tax_invoice') }}</span>
                            </div>
                            <span class="tw-text-[10px] tw-font-bold tw-uppercase" :class="hasTax ? 'tw-text-success' : (isPkp ? 'tw-text-error' : 'tw-text-on-surface-variant')" x-text="hasTax ? readyLabel : (isPkp ? requiredLabel : optionalLabel)"></span>
                        </div>

                        {{-- Item Surat Jalan --}}
                        <div class="tw-flex tw-items-center tw-justify-between tw-p-2.5 tw-rounded-ui-sm tw-border ui-motion" :class="hasDn ? 'tw-border-success/30 tw-bg-success/5' : 'tw-border-outline-variant tw-bg-surface-container'">
                            <div class="tw-flex tw-items-center tw-gap-2.5 tw-min-w-0">
                                <div class="tw-w-5 tw-h-5 tw-rounded-full tw-flex tw-items-center tw-justify-center tw-shrink-0 ui-motion" :class="hasDn ? 'tw-bg-success tw-text-white' : (requiresDeliveryNote ? 'tw-bg-error/20 tw-text-error' : 'tw-bg-surface-high tw-text-on-surface-variant')">
                                    <template x-if="hasDn"><x-ui.icon name="check" size="sm" class="tw-w-3 tw-h-3" /></template>
                                    <template x-if="!hasDn"><x-ui.icon name="minus" size="sm" class="tw-w-3 tw-h-3" /></template>
                                </div>
                                <span class="tw-font-medium tw-text-on-surface tw-truncate">{{ __('local_invoice.labels.delivery_note') }} (DN)</span>
                            </div>
                            <span class="tw-text-[10px] tw-font-bold tw-uppercase" :class="hasDn ? 'tw-text-success' : (requiresDeliveryNote ? 'tw-text-error' : 'tw-text-on-surface-variant')" x-text="hasDn ? readyLabel : (requiresDeliveryNote ? requiredLabel : optionalLabel)"></span>
                        </div>

                        {{-- Item Dokumen Pendukung --}}
                        <div class="tw-flex tw-items-center tw-justify-between tw-p-2.5 tw-rounded-ui-sm tw-border ui-motion" :class="hasSupporting ? 'tw-border-success/30 tw-bg-success/5' : 'tw-border-outline-variant tw-bg-surface-container'">
                            <div class="tw-flex tw-items-center tw-gap-2.5 tw-min-w-0">
                                <div class="tw-w-5 tw-h-5 tw-rounded-full tw-flex tw-items-center tw-justify-center tw-shrink-0 ui-motion" :class="hasSupporting ? 'tw-bg-success tw-text-white' : 'tw-bg-surface-high tw-text-on-surface-variant'">
                                    <template x-if="hasSupporting"><x-ui.icon name="check" size="sm" class="tw-w-3 tw-h-3" /></template>
                                    <template x-if="!hasSupporting"><x-ui.icon name="minus" size="sm" class="tw-w-3 tw-h-3" /></template>
                                </div>
                                <span class="tw-font-medium tw-text-on-surface tw-truncate">{{ __('local_invoice.labels.supporting_documents') }}</span>
                            </div>
                            <span class="tw-text-[10px] tw-font-medium tw-text-on-surface-variant" x-text="hasSupporting ? uploadedLabel : optionalLabel"></span>
                        </div>
                    </div>

                    <div class="tw-pt-3 tw-border-t tw-border-outline-variant">
                        <x-ui.button
                            type="button"
                            variant="primary"
                            class="tw-w-full tw-justify-center"
                            @click="goToStep(2)"
                        >
                            <span>{{ __('local_invoice.form.next_step') }}</span>
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
            <x-ui.form-section :title="__('local_invoice.labels.po_gr_information')" :description="__('local_invoice.form.gr_requirement')">
                <div class="tw-grid tw-gap-4 sm:tw-grid-cols-2">
                    <div>
                        <div class="tw-flex tw-items-center tw-justify-between tw-mb-1">
                            <label for="invoice-number" class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface tw-mb-0">
                                {{ __('local_invoice.receipt.invoice_number') }} <span class="tw-text-error">*</span>
                            </label>
                            <span id="invoice-autofill-badge" class="tw-hidden tw-items-center tw-gap-1 tw-text-[11px] tw-text-primary tw-font-semibold tw-bg-primary/10 tw-px-2 tw-py-0.5 tw-rounded">
                                <x-ui.icon name="sparkles" size="sm" class="tw-w-3 tw-h-3" />
                                <span>{{ __('local_invoice.form.from_filename') }}</span>
                            </span>
                        </div>
                        <input
                            name="invoice_number"
                            id="invoice-number"
                            class="form-control form-control-sm"
                            value="{{ old('invoice_number', $invoice->invoice_number ?? '') }}"
                            placeholder="{{ __('common.reference_example', ['reference' => 'INV/2026/09/001']) }}"
                            @readonly(isset($invoice))
                            required
                        >
                        <div id="invoice-autofill-filename" class="tw-text-[11px] tw-text-on-surface-variant tw-mt-1 tw-hidden">
                            {{ __('local_invoice.form.detected_number') }} <strong class="tw-font-mono tw-text-primary"></strong>
                        </div>
                    </div>

                    <div>
                        <x-ui.date-picker
                            id="local_invoice_date"
                            name="invoice_date"
                            :label="__('local_invoice.labels.invoice_date')"
                            :value="old('invoice_date', $invoice?->invoice_date?->format('Y-m-d') ?? \App\Support\BusinessTime::today()->toDateString())"
                            required="true"
                        />
                    </div>

                    <div>
                        <x-ui.searchable-select
                            name="local_purchase_order_id"
                            id="local_purchase_order_id"
                            :label="__('common.final_review.local_po')"
                            :placeholder="__('common.final_review.choose_open_po')"
                            :search-placeholder="__('common.final_review.search_po_status')"
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
                            :label="__('common.final_review.whole_gr')"
                            :placeholder="__('common.final_review.choose_gr')"
                            disabled-placeholder="{{ __('local_invoice.interaction.select_po_first') }}"
                            :search-placeholder="__('common.final_review.search_gr')"
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
                                {{ __('local_invoice.form.dpp_idr') }} <span class="tw-text-error">*</span>
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
                                {{ __('local_invoice.form.dpp_help') }}
                            </div>
                        </div>

                        <div>
                            <label for="ppn-scheme" class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface">
                                {{ __('finance.labels.ppn_scheme') }} <span class="tw-text-error">*</span>
                            </label>
                            <select name="ppn_scheme" id="ppn-scheme" class="form-select form-select-sm">
                                <option value="11%" @selected(old('ppn_scheme', $invoice->ppn_scheme ?? '11%') === '11%')>{{ __('local_invoice.closure.ppn_standard') }}</option>
                                <option value="1.1%" @selected(old('ppn_scheme', $invoice->ppn_scheme ?? '') === '1.1%')>{{ __('local_invoice.closure.ppn_certain') }}</option>
                                <option value="0%" @selected(old('ppn_scheme', $invoice->ppn_scheme ?? '') === '0%')>{{ __('local_invoice.closure.ppn_exempt') }}</option>
                            </select>
                            <div class="tw-text-[11px] tw-text-on-surface-variant tw-mt-1">
                                {{ __('local_invoice.form.ppn_scheme_help') }}
                            </div>
                        </div>

                        <div>
                            <label for="tax-amount" class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface">
                                {{ __('finance.labels.ppn_estimate') }}
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
                                {{ __('local_invoice.form.tax_calculation_help') }}
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
                                    {{ __('local_invoice.form.tax_number') }} @if($isPkp) <span class="tw-text-error">*</span> @else <span class="tw-text-on-surface-variant tw-font-normal">{{ __('local_invoice.form.non_pkp_optional') }}</span> @endif
                                </label>
                                <span id="tax-autofill-badge" class="tw-hidden tw-items-center tw-gap-1 tw-text-[11px] tw-text-primary tw-font-semibold tw-bg-primary/10 tw-px-2 tw-py-0.5 tw-rounded">
                                    <x-ui.icon name="sparkles" size="sm" class="tw-w-3 tw-h-3" />
                                    <span>{{ __('local_invoice.form.from_filename') }}</span>
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
                                            <span>{{ __('local_invoice.form.coretax_digits') }}</span>
                                        </span>
                                    </template>
                                    <template x-if="rawDigits.length === 16">
                                        <span class="tw-inline-flex tw-items-center tw-gap-1">
                                            <x-ui.icon name="check" size="sm" class="tw-w-3 tw-h-3 tw-stroke-[3]" />
                                            <span>{{ __('local_invoice.form.efaktur_digits') }}</span>
                                        </span>
                                    </template>
                                    <template x-if="rawDigits.length < 16">
                                        <span x-text="window.AdasiI18n.t('js.validation.digit_progress', { count: rawDigits.length, max: 17 })"></span>
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
                                {{ __('local_invoice.form.coretax_format', ['format' => '01.00.XX.XXXXXXXXXXX']) }}
                            </span>
                            <template x-if="rawDigits.length === 17">
                                <span class="tw-text-success tw-font-semibold">{{ __('local_invoice.form.coretax_valid') }}</span>
                            </template>
                            <template x-if="rawDigits.length === 16">
                                <span class="tw-text-success tw-font-semibold">{{ __('local_invoice.form.efaktur_valid') }}</span>
                            </template>
                        </div>
                    </div>

                    {{-- Jadwal Penyerahan Dokumen Fisik (Wajib Hari Rabu) --}}
                    <div class="sm:tw-col-span-2">
                        <x-ui.date-picker
                            id="local_invoice_delivery_date"
                            name="scheduled_physical_delivery_date"
                            :label="__('local_invoice.form.delivery_label')"
                            :value="old('scheduled_physical_delivery_date', $invoice?->scheduled_physical_delivery_date?->format('Y-m-d'))"
                            :min="\App\Support\BusinessTime::today()->toDateString()"
                            :allowed-days-of-week="[3]"
                            allowed-days-message="{{ __('local_invoice.form.delivery_rule') }}"
                            :helper="__('local_invoice.closure.counter_schedule', ['timezone' => \App\Support\BusinessTime::label()])"
                        />
                    </div>
                </div>
            </x-ui.form-section>
        </div>

        {{-- Sidebar Tahap 2: Ringkasan Finansial & Tombol Submit --}}
        <div class="lg:tw-col-span-4 tw-sticky" style="top: calc(var(--topbar-height, 56px) + 1.25rem)">
            <x-ui.card :title="__('ga.labels.claim_summary')">
                <div class="tw-space-y-4">
                    {{-- Financial Breakdown --}}
                    <div class="tw-space-y-2.5 tw-text-ui-xs">
                        <div class="tw-flex tw-items-center tw-justify-between tw-text-on-surface-variant">
                            <span>{{ __('local_procurement.labels.remaining_ceiling') }}:</span>
                            <span id="summary-po-ceiling" class="tw-font-mono tw-font-semibold tw-text-on-surface">Rp 0</span>
                        </div>
                        <div class="tw-flex tw-items-center tw-justify-between tw-text-on-surface-variant">
                            <span>{{ __('local_invoice.form.dpp_summary') }}</span>
                            <span id="summary-dpp" class="tw-font-mono tw-font-semibold tw-text-on-surface">Rp 0</span>
                        </div>
                        <div class="tw-flex tw-items-center tw-justify-between tw-text-on-surface-variant">
                            <span>{{ __('finance.labels.ppn_short') }}:</span>
                            <span id="summary-tax" class="tw-font-mono tw-font-semibold tw-text-on-surface">Rp 0</span>
                        </div>
                        <div class="tw-flex tw-items-center tw-justify-between tw-border-t tw-border-outline-variant tw-pt-2.5 tw-text-ui-sm">
                            <span class="tw-font-bold tw-text-on-surface">{{ __('local_invoice.form.total_summary') }}</span>
                            <span id="summary-grand-total" class="tw-font-mono tw-font-bold tw-text-primary tw-text-ui-base">Rp 0</span>
                        </div>
                    </div>

                    {{-- Allocation & Validation Status Card --}}
                    <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container tw-p-3.5 tw-space-y-2.5">
                        <div class="tw-flex tw-items-center tw-justify-between tw-text-ui-xs">
                            <span class="tw-text-on-surface-variant">{{ __('terms.goods_receipt') }}:</span>
                            <span id="summary-gr-count" class="tw-font-medium tw-text-on-surface">{{ __('local_invoice.interaction.document_count', ['count' => 0]) }}</span>
                        </div>
                        <div class="tw-flex tw-items-center tw-justify-between tw-text-ui-xs">
                            <span class="tw-text-on-surface-variant">{{ __('local_procurement.labels.gr_qty') }}:</span>
                            <span id="summary-gr-qty" class="tw-font-mono tw-font-semibold tw-text-on-surface">0 pcs</span>
                        </div>
                        <div class="tw-flex tw-items-center tw-justify-between tw-text-ui-xs tw-border-t tw-border-outline-variant tw-pt-2">
                            <span class="tw-text-on-surface-variant">{{ __('local_procurement.labels.ceiling_status') }}:</span>
                            <span id="summary-match-chip">
                                <span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-medium tw-bg-surface-high tw-text-on-surface-variant">
                                    {{ __('local_invoice.form.await_po') }}
                                </span>
                            </span>
                        </div>
                        <div id="summary-diff-container" class="tw-hidden tw-text-[11px] tw-text-error tw-pt-1.5 tw-border-t tw-border-outline-variant">
                            <div class="tw-flex tw-items-center tw-justify-between">
                                <span>{{ __('local_invoice.interaction.ceiling_exceeded') }}:</span>
                                <strong id="summary-diff-amount" class="tw-font-mono">Rp 0</strong>
                            </div>
                        </div>
                    </div>

                    {{-- Petunjuk Pengajuan --}}
                    <div class="tw-text-ui-xs tw-text-on-surface-variant tw-pt-1">
                        <p class="tw-text-[11px] tw-text-on-surface-variant tw-m-0 tw-leading-relaxed">
                            {{ __('local_invoice.form.ceiling_help') }}
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
                            <span>{{ isset($invoice) ? __('local_invoice.form.submit_revision') : __('local_invoice.actions.submit') }}</span>
                        </x-ui.button>

                        <x-ui.button
                            type="button"
                            variant="ghost"
                            class="tw-w-full tw-justify-center tw-text-on-surface-variant hover:tw-text-on-surface"
                            @click="goToStep(1)"
                        >
                            <x-ui.icon name="arrow-left" size="sm" />
                            <span>{{ __('local_invoice.form.back_documents') }}</span>
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
        readyLabel: @js(__('local_invoice.form.ready')),
        requiredLabel: @js(__('local_invoice.form.required')),
        optionalLabel: @js(__('local_invoice.form.optional_short')),
        uploadedLabel: @js(__('local_invoice.form.uploaded')),
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
                    if (!this.hasInvoice) missing.push(@js(__('local_invoice.interaction.physical_invoice')));
                    if (!this.hasTax) missing.push(@js(__('local_invoice.interaction.tax_invoice')));
                    if (!this.hasDn) missing.push(@js(__('local_invoice.interaction.delivery_note')));
                    this.errorMessage = @js(__('local_invoice.interaction.missing_files')).replace(':files', () => missing.join(', '));
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

    if (summaryPoCeiling) summaryPoCeiling.textContent = poData ? rupiah(poCeiling) : 'â€”';
    if (summaryGrCount) summaryGrCount.textContent = @js(__('local_invoice.interaction.document_count')).replace(':count', () => String(selectedGrCount));
    if (summaryGrQty) summaryGrQty.textContent = selectedGrQty.toLocaleString('id-ID', { minimumFractionDigits: 0, maximumFractionDigits: 4 }) + ' pcs';
    if (summaryDpp) summaryDpp.textContent = rupiah(dpp);
    if (summaryTax) summaryTax.textContent = rupiah(tax);
    if (summaryGrandTotal) summaryGrandTotal.textContent = rupiah(grandTotal);

    if (!summaryMatchChip) return;

    if (!poVal) {
        summaryMatchChip.innerHTML = '<span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-medium tw-bg-surface-high tw-text-on-surface-variant"></span>';
        summaryMatchChip.firstElementChild.textContent = @js(__('local_invoice.interaction.select_po'));
        if (summaryDiffContainer) summaryDiffContainer.classList.add('tw-hidden');
    } else if (dpp <= 0) {
        summaryMatchChip.innerHTML = '<span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-medium tw-bg-surface-high tw-text-on-surface-variant"></span>';
        summaryMatchChip.firstElementChild.textContent = @js(__('local_invoice.interaction.input_dpp'));
        if (summaryDiffContainer) summaryDiffContainer.classList.add('tw-hidden');
    } else if (dpp > poCeiling + 0.005) {
        const excess = dpp - poCeiling;
        summaryMatchChip.innerHTML = '<span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-semibold tw-bg-error/10 tw-text-error"><svg class="tw-w-3 tw-h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path></svg></span>';
        summaryMatchChip.firstElementChild.append(document.createTextNode(@js(__('local_invoice.interaction.ceiling_exceeded'))));
        if (summaryDiffContainer) {
            summaryDiffContainer.classList.remove('tw-hidden');
            if (summaryDiffAmount) summaryDiffAmount.textContent = rupiah(excess);
        }
    } else {
        summaryMatchChip.innerHTML = '<span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-semibold tw-bg-success/10 tw-text-success"><svg class="tw-w-3 tw-h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path></svg></span>';
        summaryMatchChip.firstElementChild.append(document.createTextNode(@js(__('local_invoice.interaction.ceiling_valid'))));
        if (summaryDiffContainer) summaryDiffContainer.classList.add('tw-hidden');
    }
}

poSelect?.addEventListener('change', () => {
    const poVal = poSelect.value;
    const poData = (poVal && localPoGr[String(poVal)]) ? localPoGr[String(poVal)] : null;
    const rows = poData ? poData.grs : [];
    const emptyMsg = poVal ? @js(__('local_invoice.interaction.empty_gr')) : @js(__('local_invoice.interaction.select_po_first'));

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
            const warning = document.createElement('span');
            warning.className = 'tw-text-error';
            warning.textContent = @js(__('local_invoice.interaction.different_filenames')).replace(':files', () => uniqueCandidates.join(', '));
            invoiceAutofillFilename.replaceChildren(warning);
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
        alert(@js(__('local_invoice.interaction.dpp_exceeded')).replace(':amount', () => rupiah(dpp)).replace(':ceiling', () => rupiah(poCeiling)));
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
