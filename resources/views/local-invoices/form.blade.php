@php
    $supplier = auth()->user()->supplier;
    $termDays = isset($invoice) ? (int) $invoice->payment_term_days_snapshot : (int) ($supplier?->payment_term_days ?? 30);
    $companyName = $supplier?->company_name ?: auth()->user()->name;
    $isPkp = (bool) ($supplier?->is_pkp ?? false);
    $vendorCategory = $supplier?->vendor_category ?: ($supplier?->category ?? 'Barang');
    $isBarang = strcasecmp(trim((string) $vendorCategory), 'Barang') === 0;
    $poSource = old('po_source', $invoice->po_source ?? 'INTERNAL');
    $ppnScheme = old('ppn_scheme', $invoice->ppn_scheme ?? '11%');
@endphp

@if($errors->any())
    <div class="alert alert-danger tw-mb-6" role="alert">
        <div class="tw-flex tw-items-center tw-gap-2 tw-font-semibold tw-mb-1">
            <x-ui.icon name="alert-triangle" size="sm" />
            <span>Please correct the errors below:</span>
        </div>
        <ul class="tw-m-0 tw-ps-5 tw-text-ui-xs">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form
    method="POST"
    enctype="multipart/form-data"
    action="{{ isset($invoice) ? route('local-supplier.invoices.resubmit', $invoice) : route('local-supplier.invoices.store') }}"
    class="local-invoice-form"
    id="localInvoiceForm"
>
    @csrf

    <div class="tw-grid tw-gap-6 lg:tw-grid-cols-12 tw-items-start">
        {{-- Left Column: Form Inputs --}}
        <div class="lg:tw-col-span-8 tw-space-y-6">
            {{-- Vendor Context Card --}}
            <x-ui.form-section
                title="Vendor Organization"
                description="Your registered vendor profile and payment conditions."
            >
                <div class="tw-flex tw-flex-col sm:tw-flex-row sm:tw-items-center sm:tw-justify-between tw-gap-4 tw-p-3 tw-rounded-ui-sm tw-bg-surface-container-low tw-border tw-border-outline-variant">
                    <div class="tw-flex tw-items-center tw-gap-3">
                        <div class="tw-w-10 tw-h-10 tw-rounded-full tw-bg-primary/10 tw-text-primary tw-flex tw-items-center tw-justify-center tw-shrink-0">
                            <x-ui.icon name="building-2" size="md" />
                        </div>
                        <div>
                            <span class="tw-font-semibold tw-text-ui-sm tw-text-on-surface">{{ $companyName }}</span>
                            <span class="tw-block tw-text-ui-xs tw-text-on-surface-variant">{{ auth()->user()->email }} &bull; {{ $vendorCategory }} &bull; {{ $isPkp ? 'PKP' : 'Non-PKP' }}</span>
                        </div>
                    </div>
                    <div class="tw-text-start sm:tw-text-end">
                        <span class="tw-text-ui-xs tw-text-on-surface-variant tw-block">Agreed Payment Term</span>
                        <span class="tw-font-semibold tw-text-ui-sm tw-text-primary">Net {{ $termDays }} Days</span>
                    </div>
                </div>
            </x-ui.form-section>

            {{-- Invoice & Reference Numbers --}}
            <x-ui.form-section
                title="Invoice & Purchase Order Reference"
                description="Specify your official invoice details, PO source, and Goods Receipt reference."
            >
                <div class="tw-grid tw-gap-4 sm:tw-grid-cols-2">
                    <div>
                        <label for="invoice-number" class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface tw-mb-2">
                            Invoice Number <span class="text-danger">*</span>
                        </label>
                        <input
                            type="text"
                            class="form-control @error('invoice_number') is-invalid @enderror"
                            id="invoice-number"
                            name="invoice_number"
                            value="{{ old('invoice_number', $invoice->invoice_number ?? '') }}"
                            maxlength="100"
                            placeholder="e.g. INV/2026/09/001"
                            required
                            @readonly(isset($invoice))
                        >
                        @error('invoice_number')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div>
                        <label for="invoice-date" class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface tw-mb-2">
                            Invoice Date <span class="text-danger">*</span>
                        </label>
                        <x-ui.date-picker
                            name="invoice_date"
                            id="invoice-date"
                            :value="old('invoice_date', isset($invoice) ? $invoice->invoice_date->format('Y-m-d') : '')"
                            required
                        />
                        @error('invoice_date')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </div>

                    {{-- Unified Purchase Order & Search --}}
                    <div class="sm:tw-col-span-2 tw-relative">
                        <label for="po-number" class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface tw-mb-2">
                            Nomor Purchase Order (PO) <span class="text-danger">*</span>
                        </label>
                        <input type="hidden" name="po_source" id="po-source" value="{{ old('po_source', $poSource) }}">

                        <div class="tw-relative" id="po-input-container">
                            <div class="input-group">
                                <span class="input-group-text tw-bg-surface-container-low tw-text-on-surface-variant">
                                    <x-ui.icon name="search" size="xs" id="po-search-icon" />
                                    <span class="spinner-border spinner-border-sm tw-hidden" id="po-search-spinner" role="status" aria-hidden="true" style="width: 14px; height: 14px;"></span>
                                </span>
                                <input
                                    type="text"
                                    class="form-control @error('po_number') is-invalid @enderror @error('internal_po_reference') is-invalid @enderror @error('manual_po_number') is-invalid @enderror"
                                    id="po-number"
                                    name="po_number"
                                    value="{{ old('po_number', $invoice->po_number ?? ($invoice->internal_po_reference ?? ($invoice->manual_po_number ?? '')) ) }}"
                                    maxlength="100"
                                    placeholder="Ketik atau cari nomor PO (e.g. PO-LOCAL-2026-001)..."
                                    autocomplete="off"
                                >
                            </div>

                            {{-- Autocomplete Dropdown List --}}
                            <div
                                id="po-dropdown"
                                class="tw-hidden tw-absolute tw-left-0 tw-right-0 tw-top-full tw-mt-1.5 tw-bg-surface tw-border tw-border-outline-variant tw-rounded-ui tw-shadow-lg tw-z-50 tw-max-h-72 tw-overflow-y-auto"
                            >
                                <div class="tw-p-2 tw-border-b tw-border-outline-variant/60 tw-bg-surface-container-low tw-flex tw-items-center tw-justify-between">
                                    <span class="tw-text-[11px] tw-font-medium tw-text-on-surface-variant">
                                        <x-ui.icon name="database" size="xs" class="tw-inline tw-mr-1" />
                                        Pilih dari Database ADASI atau ketik manual
                                    </span>
                                    <span class="tw-text-[10px] tw-text-on-surface-variant/80">ESC untuk tutup</span>
                                </div>
                                <div id="po-dropdown-items" class="tw-divide-y tw-divide-outline-variant/40">
                                    {{-- Dynamic Items Injected Here --}}
                                </div>
                            </div>
                        </div>

                        {{-- Selected Internal PO Summary Card (Shown when selected from DB) --}}
                        <div id="selected-po-badge" class="tw-mt-2.5 tw-p-3 tw-rounded-ui tw-bg-primary/5 tw-border tw-border-primary/20 {{ $poSource === 'INTERNAL' && !empty(old('po_number', $invoice->po_number ?? ($invoice->internal_po_reference ?? ''))) ? '' : 'tw-hidden' }}">
                            <div class="tw-flex tw-items-start tw-justify-between tw-gap-3">
                                <div class="tw-flex tw-items-start tw-gap-2.5">
                                    <div class="tw-w-7 tw-h-7 tw-rounded-full tw-bg-primary/10 tw-text-primary tw-flex tw-items-center tw-justify-center tw-shrink-0 tw-mt-0.5">
                                        <x-ui.icon name="check-circle-2" size="xs" />
                                    </div>
                                    <div>
                                        <div class="tw-flex tw-items-center tw-gap-2 tw-flex-wrap">
                                            <span class="tw-font-semibold tw-text-ui-xs tw-text-on-surface" id="selected-po-number-text">
                                                {{ old('po_number', $invoice->po_number ?? ($invoice->internal_po_reference ?? '')) }}
                                            </span>
                                            <span class="badge bg-success-subtle text-success tw-text-[10px] tw-px-2 tw-py-0.5" id="selected-po-gr-badge">
                                                GR Terverifikasi Otomatis
                                            </span>
                                        </div>
                                        <div class="tw-text-[11px] tw-text-on-surface-variant tw-mt-0.5" id="selected-po-detail-text">
                                            PO terdaftar di database ADASI. Referensi GR dan plafon diverifikasi otomatis.
                                        </div>
                                    </div>
                                </div>
                                <div class="tw-flex tw-items-center tw-gap-2 tw-shrink-0">
                                    <button
                                        type="button"
                                        id="btn-copy-po-amount"
                                        class="btn btn-sm btn-outline-primary tw-text-[11px] tw-py-1 tw-px-2 tw-hidden"
                                        title="Salin nilai PO ke input DPP"
                                    >
                                        Gunakan Nilai PO
                                    </button>
                                    <button
                                        type="button"
                                        id="btn-reset-po"
                                        class="btn btn-sm btn-outline-secondary tw-text-[11px] tw-py-1 tw-px-2"
                                    >
                                        <x-ui.icon name="x" size="xs" class="tw-inline" /> Ubah / Manual
                                    </button>
                                </div>
                            </div>
                        </div>

                        @error('po_number')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                        @error('internal_po_reference')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                        @error('manual_po_number')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </div>

                    {{-- Manual GR / Surat Jalan Reference (Only revealed when Manual PO) --}}
                    <div id="section-manual-gr" class="sm:tw-col-span-2 {{ $poSource === 'MANUAL' ? '' : 'tw-hidden' }}">
                        <label for="manual-gr-reference" class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface tw-mb-2">
                            Referensi Surat Jalan / Manual GR <span class="text-danger">*</span>
                        </label>
                        <input
                            type="text"
                            class="form-control @error('manual_gr_reference') is-invalid @enderror"
                            id="manual-gr-reference"
                            name="manual_gr_reference"
                            value="{{ old('manual_gr_reference', $invoice->manual_gr_reference ?? '') }}"
                            maxlength="100"
                            placeholder="e.g. SJ-ADASI-2026-001 / GR-001"
                        >
                        <small class="tw-text-ui-xs tw-text-on-surface-variant tw-mt-1.5 tw-block">
                            Wajib diisi untuk PO manual/legacy sebagai bukti penerimaan barang atau surat jalan di ADASI.
                        </small>
                        @error('manual_gr_reference')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </div>

                    {{-- Wednesday Delivery Schedule --}}
                    <div class="sm:tw-col-span-2">
                        <label for="scheduled-delivery-date" class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface tw-mb-2">
                            Jadwal Penyerahan Berkas Fisik (Hari Rabu)
                        </label>
                        <x-ui.date-picker
                            name="scheduled_physical_delivery_date"
                            id="scheduled-delivery-date"
                            :value="old('scheduled_physical_delivery_date', isset($invoice) && $invoice->scheduled_physical_delivery_date ? $invoice->scheduled_physical_delivery_date->format('Y-m-d') : '')"
                        />
                        <small class="tw-text-ui-xs tw-text-on-surface-variant tw-mt-1.5 tw-block">
                            Penyerahan berkas fisik asli hanya dilayani setiap hari Rabu di loket Accounting / Finance ADASI.
                        </small>
                        @error('scheduled_physical_delivery_date')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </x-ui.form-section>

            {{-- Financial Values --}}
            <x-ui.form-section
                title="Financial Amounts (IDR)"
                description="Enter the DPP (Dasar Pengenaan Pajak) and select the applicable PPN scheme."
            >
                <div class="tw-grid tw-gap-4 sm:tw-grid-cols-2">
                    <div>
                        <div class="tw-flex tw-items-center tw-justify-between tw-mb-2" style="min-height: 22px;">
                            <label for="invoice-amount" class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface tw-m-0">
                                Nilai Tagihan DPP (IDR) <span class="text-danger">*</span>
                            </label>
                            <span class="tw-text-[11px] tw-text-on-surface-variant">Sebelum Pajak</span>
                        </div>
                        <div class="input-group">
                            <span class="input-group-text tw-font-mono tw-text-ui-xs">Rp</span>
                            <input
                                type="number"
                                step="0.01"
                                min="0"
                                class="form-control tw-font-mono @error('invoice_amount') is-invalid @enderror"
                                id="invoice-amount"
                                name="invoice_amount"
                                value="{{ old('invoice_amount', $invoice->invoice_amount ?? '') }}"
                                placeholder="0.00"
                                required
                            >
                        </div>
                        <span id="invoice-amount-preview" class="tw-text-ui-xs tw-text-primary tw-font-mono tw-mt-1.5 tw-block"></span>
                        @error('invoice_amount')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </div>

                    <div>
                        <div class="tw-flex tw-items-center tw-justify-between tw-mb-2" style="min-height: 22px;">
                            <label for="ppn-scheme" class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface tw-m-0">
                                Skema PPN <span class="text-danger">*</span>
                            </label>
                        </div>
                        <select
                            class="form-select @error('ppn_scheme') is-invalid @enderror"
                            id="ppn-scheme"
                            name="ppn_scheme"
                            onchange="updatePpnCalculation()"
                        >
                            <option value="11%" {{ $ppnScheme === '11%' ? 'selected' : '' }}>PPN 11% (Standar)</option>
                            <option value="1.1%" {{ $ppnScheme === '1.1%' ? 'selected' : '' }}>PPN 1.1% (Besaran Tertentu)</option>
                            <option value="0%" {{ $ppnScheme === '0%' ? 'selected' : '' }}>PPN 0% / Non-PPN / Dibebaskan</option>
                        </select>
                        <div class="input-group tw-mt-2">
                            <span class="input-group-text tw-font-mono tw-text-ui-xs">Rp</span>
                            <input
                                type="number"
                                step="0.01"
                                min="0"
                                class="form-control tw-font-mono @error('tax_amount') is-invalid @enderror"
                                id="tax-amount"
                                name="tax_amount"
                                value="{{ old('tax_amount', $invoice->tax_amount ?? '') }}"
                                placeholder="0.00"
                            >
                        </div>
                        <span id="tax-amount-preview" class="tw-text-ui-xs tw-text-primary tw-font-mono tw-mt-1.5 tw-block"></span>
                        @error('tax_amount')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </div>

                    {{-- Nomor Faktur Pajak (NSFP) --}}
                    <div class="sm:tw-col-span-2" id="section-tax-invoice-number">
                        <div class="tw-flex tw-items-center tw-justify-between tw-mb-2" style="min-height: 22px;">
                            <label for="tax-invoice-number" class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface tw-m-0">
                                Nomor Faktur Pajak (NSFP) @if($isPkp)<span class="text-danger">*</span>@endif
                            </label>
                            <span class="tw-text-[11px] tw-text-on-surface-variant">
                                {{ $isPkp ? 'Wajib untuk PKP (16 Digit)' : 'Opsional untuk Non-PKP' }}
                            </span>
                        </div>
                        <div class="input-group">
                            <span class="input-group-text tw-font-mono tw-text-ui-xs"><x-ui.icon name="file-text" size="xs" /></span>
                            <input
                                type="text"
                                class="form-control tw-font-mono @error('tax_invoice_number') is-invalid @enderror"
                                id="tax-invoice-number"
                                name="tax_invoice_number"
                                value="{{ old('tax_invoice_number', $invoice->tax_invoice_number ?? '') }}"
                                placeholder="010.000-26.12345678"
                                maxlength="19"
                                autocomplete="off"
                                {{ $isPkp ? 'required' : '' }}
                                oninput="maskTaxInvoiceNumber(this)"
                            >
                        </div>
                        <small class="tw-text-[11px] tw-text-on-surface-variant tw-mt-1.5 tw-block">
                            Format standar e-Faktur DJP 16 digit: <span class="tw-font-mono">010.000-26.12345678</span> (titik dan strip otomatis diformat).
                        </small>
                        @error('tax_invoice_number')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </x-ui.form-section>

            {{-- Document Uploads with Interactive Dropzone --}}
            <x-ui.form-section
                title="Document Attachments"
                description="Upload clean scans or digital files. Accepted formats: PDF, JPG, JPEG, PNG (max 10 MB each)."
            >
                <div class="tw-grid tw-gap-4">
                    {{-- Invoice File --}}
                    <div class="tw-border tw-border-outline-variant tw-rounded-ui-sm tw-p-3.5 tw-bg-surface-container-low hover:tw-border-primary/40 tw-transition-colors">
                        <div class="tw-flex tw-items-start tw-justify-between tw-gap-3 tw-mb-2">
                            <div>
                                <label for="file-invoice" class="tw-font-semibold tw-text-ui-sm tw-text-on-surface tw-block">
                                    Dokumen Invoice Resmi <span class="text-danger">*</span>
                                </label>
                                <span class="tw-text-ui-xs tw-text-on-surface-variant">Surat tagihan / invoice fisik yang telah dicap &amp; ditandatangani.</span>
                            </div>
                            <span class="tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-semibold tw-bg-primary/10 tw-text-primary tw-shrink-0">Required</span>
                        </div>

                        <label for="file-invoice" class="tw-relative tw-block tw-border-2 tw-border-dashed tw-border-outline-variant hover:tw-border-primary/60 tw-rounded-ui-sm tw-p-4 tw-text-center tw-bg-surface hover:tw-bg-primary/[0.02] tw-transition-all tw-cursor-pointer tw-m-0">
                            <input
                                type="file"
                                class="tw-sr-only @error('invoice') is-invalid @enderror"
                                id="file-invoice"
                                name="invoice"
                                accept=".pdf,.jpg,.jpeg,.png"
                                required
                                onchange="handleLocalFileChange(this, 'file-invoice-info', 'file-invoice-empty')"
                            >
                            <div id="file-invoice-empty" class="tw-space-y-1.5">
                                <div class="tw-w-9 tw-h-9 tw-rounded-full tw-bg-primary/10 tw-text-primary tw-mx-auto tw-flex tw-items-center tw-justify-center">
                                    <x-ui.icon name="upload-cloud" size="sm" />
                                </div>
                                <div class="tw-text-ui-xs tw-font-semibold tw-text-on-surface">
                                    Klik untuk pilih file <span class="tw-font-normal tw-text-on-surface-variant">atau seret berkas ke sini</span>
                                </div>
                                <div class="tw-text-[11px] tw-text-on-surface-variant">
                                    Format: <span class="tw-font-mono tw-font-medium">.PDF, .JPG, .PNG</span> (Maksimal 10 MB)
                                </div>
                            </div>

                            <div id="file-invoice-info" class="tw-hidden tw-flex tw-items-center tw-justify-between tw-gap-2 tw-p-2 tw-rounded tw-bg-success/10 tw-border tw-border-success/30">
                                <div class="tw-flex tw-items-center tw-gap-2 tw-min-w-0">
                                    <x-ui.icon name="file-check-2" size="sm" class="tw-text-success tw-shrink-0" />
                                    <span class="file-name tw-font-semibold tw-text-ui-xs tw-text-on-surface tw-truncate"></span>
                                    <span class="file-size tw-text-[11px] tw-text-on-surface-variant tw-shrink-0"></span>
                                </div>
                                <span class="tw-text-ui-xs tw-text-primary tw-font-medium tw-underline hover:tw-text-primary/80 tw-shrink-0">
                                    Ganti File
                                </span>
                            </div>
                        </label>
                        @error('invoice')
                            <div class="invalid-feedback d-block tw-mt-1.5">{{ $message }}</div>
                        @enderror
                    </div>

                    {{-- Faktur Pajak File --}}
                    <div class="tw-border tw-border-outline-variant tw-rounded-ui-sm tw-p-3.5 tw-bg-surface-container-low hover:tw-border-primary/40 tw-transition-colors">
                        <div class="tw-flex tw-items-start tw-justify-between tw-gap-3 tw-mb-2">
                            <div>
                                <label for="file-tax_invoice" class="tw-font-semibold tw-text-ui-sm tw-text-on-surface tw-block">
                                    Faktur Pajak Elektronik @if($isPkp)<span class="text-danger">*</span>@endif
                                </label>
                                <span class="tw-text-ui-xs tw-text-on-surface-variant">
                                    @if($isPkp)
                                        Faktur Pajak resmi dari DJP dengan QR Code valid (Wajib untuk PKP).
                                    @else
                                        Khusus vendor Non-PKP, Faktur Pajak tidak wajib diunggah.
                                    @endif
                                </span>
                            </div>
                            <span class="tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-semibold {{ $isPkp ? 'tw-bg-primary/10 tw-text-primary' : 'tw-bg-surface-container tw-text-on-surface-variant' }} tw-shrink-0">
                                {{ $isPkp ? 'Required (PKP)' : 'Optional (Non-PKP)' }}
                            </span>
                        </div>

                        <label for="file-tax_invoice" class="tw-relative tw-block tw-border-2 tw-border-dashed tw-border-outline-variant hover:tw-border-primary/60 tw-rounded-ui-sm tw-p-4 tw-text-center tw-bg-surface hover:tw-bg-primary/[0.02] tw-transition-all tw-cursor-pointer tw-m-0">
                            <input
                                type="file"
                                class="tw-sr-only @error('tax_invoice') is-invalid @enderror"
                                id="file-tax_invoice"
                                name="tax_invoice"
                                accept=".pdf,.jpg,.jpeg,.png"
                                @if($isPkp) required @endif
                                onchange="handleLocalFileChange(this, 'file-tax-info', 'file-tax-empty')"
                            >
                            <div id="file-tax-empty" class="tw-space-y-1.5">
                                <div class="tw-w-9 tw-h-9 tw-rounded-full tw-bg-primary/10 tw-text-primary tw-mx-auto tw-flex tw-items-center tw-justify-center">
                                    <x-ui.icon name="upload-cloud" size="sm" />
                                </div>
                                <div class="tw-text-ui-xs tw-font-semibold tw-text-on-surface">
                                    Klik untuk pilih file <span class="tw-font-normal tw-text-on-surface-variant">atau seret berkas ke sini</span>
                                </div>
                                <div class="tw-text-[11px] tw-text-on-surface-variant">
                                    Format: <span class="tw-font-mono tw-font-medium">.PDF, .JPG, .PNG</span> (Maksimal 10 MB)
                                </div>
                            </div>

                            <div id="file-tax-info" class="tw-hidden tw-flex tw-items-center tw-justify-between tw-gap-2 tw-p-2 tw-rounded tw-bg-success/10 tw-border tw-border-success/30">
                                <div class="tw-flex tw-items-center tw-gap-2 tw-min-w-0">
                                    <x-ui.icon name="file-check-2" size="sm" class="tw-text-success tw-shrink-0" />
                                    <span class="file-name tw-font-semibold tw-text-ui-xs tw-text-on-surface tw-truncate"></span>
                                    <span class="file-size tw-text-[11px] tw-text-on-surface-variant tw-shrink-0"></span>
                                </div>
                                <span class="tw-text-ui-xs tw-text-primary tw-font-medium tw-underline hover:tw-text-primary/80 tw-shrink-0">
                                    Ganti File
                                </span>
                            </div>
                        </label>
                        @error('tax_invoice')
                            <div class="invalid-feedback d-block tw-mt-1.5">{{ $message }}</div>
                        @enderror
                    </div>

                    {{-- Surat Jalan (Delivery Note) File --}}
                    <div class="tw-border tw-border-outline-variant tw-rounded-ui-sm tw-p-3.5 tw-bg-surface-container-low hover:tw-border-primary/40 tw-transition-colors">
                        <div class="tw-flex tw-items-start tw-justify-between tw-gap-3 tw-mb-2">
                            <div>
                                <label for="file-delivery_note" class="tw-font-semibold tw-text-ui-sm tw-text-on-surface tw-block">
                                    Surat Jalan / Bukti Penerimaan Barang @if($isBarang)<span class="text-danger">*</span>@endif
                                </label>
                                <span class="tw-text-ui-xs tw-text-on-surface-variant">
                                    @if($isBarang)
                                        Surat Jalan resmi bertanda tangan &amp; stempel penerima ADASI (Wajib untuk Vendor Barang).
                                    @else
                                        Khusus vendor Jasa / Non-Barang, Surat Jalan bersifat opsional.
                                    @endif
                                </span>
                            </div>
                            <span class="tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-semibold {{ $isBarang ? 'tw-bg-primary/10 tw-text-primary' : 'tw-bg-surface-container tw-text-on-surface-variant' }} tw-shrink-0">
                                {{ $isBarang ? 'Required (Barang)' : 'Optional (Jasa)' }}
                            </span>
                        </div>

                        <label for="file-delivery_note" class="tw-relative tw-block tw-border-2 tw-border-dashed tw-border-outline-variant hover:tw-border-primary/60 tw-rounded-ui-sm tw-p-4 tw-text-center tw-bg-surface hover:tw-bg-primary/[0.02] tw-transition-all tw-cursor-pointer tw-m-0">
                            <input
                                type="file"
                                class="tw-sr-only @error('delivery_note') is-invalid @enderror"
                                id="file-delivery_note"
                                name="delivery_note"
                                accept=".pdf,.jpg,.jpeg,.png"
                                @if($isBarang) required @endif
                                onchange="handleLocalFileChange(this, 'file-sj-info', 'file-sj-empty')"
                            >
                            <div id="file-sj-empty" class="tw-space-y-1.5">
                                <div class="tw-w-9 tw-h-9 tw-rounded-full tw-bg-primary/10 tw-text-primary tw-mx-auto tw-flex tw-items-center tw-justify-center">
                                    <x-ui.icon name="upload-cloud" size="sm" />
                                </div>
                                <div class="tw-text-ui-xs tw-font-semibold tw-text-on-surface">
                                    Klik untuk pilih file <span class="tw-font-normal tw-text-on-surface-variant">atau seret berkas ke sini</span>
                                </div>
                                <div class="tw-text-[11px] tw-text-on-surface-variant">
                                    Format: <span class="tw-font-mono tw-font-medium">.PDF, .JPG, .PNG</span> (Maksimal 10 MB)
                                </div>
                            </div>

                            <div id="file-sj-info" class="tw-hidden tw-flex tw-items-center tw-justify-between tw-gap-2 tw-p-2 tw-rounded tw-bg-success/10 tw-border tw-border-success/30">
                                <div class="tw-flex tw-items-center tw-gap-2 tw-min-w-0">
                                    <x-ui.icon name="file-check-2" size="sm" class="tw-text-success tw-shrink-0" />
                                    <span class="file-name tw-font-semibold tw-text-ui-xs tw-text-on-surface tw-truncate"></span>
                                    <span class="file-size tw-text-[11px] tw-text-on-surface-variant tw-shrink-0"></span>
                                </div>
                                <span class="tw-text-ui-xs tw-text-primary tw-font-medium tw-underline hover:tw-text-primary/80 tw-shrink-0">
                                    Ganti File
                                </span>
                            </div>
                        </label>
                        @error('delivery_note')
                            <div class="invalid-feedback d-block tw-mt-1.5">{{ $message }}</div>
                        @enderror
                    </div>

                    {{-- Supporting Document File --}}
                    <div class="tw-border tw-border-outline-variant tw-rounded-ui-sm tw-p-3.5 tw-bg-surface-container-low hover:tw-border-primary/40 tw-transition-colors">
                        <div class="tw-flex tw-items-start tw-justify-between tw-gap-3 tw-mb-2">
                            <div>
                                <label for="file-supporting" class="tw-font-semibold tw-text-ui-sm tw-text-on-surface tw-block">
                                    Dokumen Pendukung
                                </label>
                                <span class="tw-text-ui-xs tw-text-on-surface-variant">Berita Acara Serah Terima (BAST), PO Copy, atau lampiran tambahan (Opsional).</span>
                            </div>
                            <span class="tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-medium tw-bg-surface-container tw-text-on-surface-variant tw-shrink-0">Optional</span>
                        </div>

                        <label for="file-supporting" class="tw-relative tw-block tw-border-2 tw-border-dashed tw-border-outline-variant hover:tw-border-primary/60 tw-rounded-ui-sm tw-p-4 tw-text-center tw-bg-surface hover:tw-bg-primary/[0.02] tw-transition-all tw-cursor-pointer tw-m-0">
                            <input
                                type="file"
                                class="tw-sr-only @error('supporting') is-invalid @enderror"
                                id="file-supporting"
                                name="supporting"
                                accept=".pdf,.jpg,.jpeg,.png"
                                onchange="handleLocalFileChange(this, 'file-supporting-info', 'file-supporting-empty')"
                            >
                            <div id="file-supporting-empty" class="tw-space-y-1.5">
                                <div class="tw-w-9 tw-h-9 tw-rounded-full tw-bg-surface-container-high tw-text-on-surface-variant tw-mx-auto tw-flex tw-items-center tw-justify-center">
                                    <x-ui.icon name="upload-cloud" size="sm" />
                                </div>
                                <div class="tw-text-ui-xs tw-font-semibold tw-text-on-surface">
                                    Klik untuk pilih file <span class="tw-font-normal tw-text-on-surface-variant">atau seret berkas ke sini</span>
                                </div>
                                <div class="tw-text-[11px] tw-text-on-surface-variant">
                                    Format: <span class="tw-font-mono tw-font-medium">.PDF, .JPG, .PNG</span> (Maksimal 10 MB)
                                </div>
                            </div>

                            <div id="file-supporting-info" class="tw-hidden tw-flex tw-items-center tw-justify-between tw-gap-2 tw-p-2 tw-rounded tw-bg-success/10 tw-border tw-border-success/30">
                                <div class="tw-flex tw-items-center tw-gap-2 tw-min-w-0">
                                    <x-ui.icon name="file-check-2" size="sm" class="tw-text-success tw-shrink-0" />
                                    <span class="file-name tw-font-semibold tw-text-ui-xs tw-text-on-surface tw-truncate"></span>
                                    <span class="file-size tw-text-[11px] tw-text-on-surface-variant tw-shrink-0"></span>
                                </div>
                                <span class="tw-text-ui-xs tw-text-primary tw-font-medium tw-underline hover:tw-text-primary/80 tw-shrink-0">
                                    Ganti File
                                </span>
                            </div>
                        </label>
                        @error('supporting')
                            <div class="invalid-feedback d-block tw-mt-1.5">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </x-ui.form-section>
        </div>

        {{-- Right Column: Sticky Summary & Action Card (Offset accounts for 56px topbar) --}}
        <div class="lg:tw-col-span-4 tw-sticky tw-space-y-4" style="top: calc(var(--topbar-height, 56px) + 1.25rem);">
            <x-ui.card title="Ringkasan Tagihan">
                <div class="tw-space-y-3">
                    <div class="tw-flex tw-justify-between tw-text-ui-sm">
                        <span class="tw-text-on-surface-variant">Nilai DPP:</span>
                        <span id="summary-dpp" class="tw-font-mono tw-font-medium tw-text-on-surface">Rp 0</span>
                    </div>

                    <div class="tw-flex tw-justify-between tw-text-ui-sm">
                        <span class="tw-text-on-surface-variant">PPN:</span>
                        <span id="summary-tax" class="tw-font-mono tw-font-medium tw-text-on-surface">Rp 0</span>
                    </div>

                    <div class="tw-border-t tw-border-outline-variant tw-pt-3">
                        <div class="tw-flex tw-justify-between tw-items-baseline">
                            <span class="tw-font-semibold tw-text-ui-sm tw-text-on-surface">Total Tagihan:</span>
                            <span id="summary-total" class="tw-text-ui-xl tw-font-bold tw-font-mono tw-text-primary">Rp 0</span>
                        </div>
                    </div>

                    <div class="tw-border-t tw-border-outline-variant tw-pt-3 tw-space-y-2">
                        <div class="tw-flex tw-justify-between tw-text-ui-xs">
                            <span class="tw-text-on-surface-variant">Payment Term:</span>
                            <span class="tw-font-semibold tw-text-on-surface">Net {{ $termDays }} Hari</span>
                        </div>
                        <div class="tw-text-[11px] tw-text-on-surface-variant tw-leading-relaxed">
                            * Periode jatuh tempo resmi dihitung mulai dari tanggal penerimaan fisik berkas asli di loket Kasir ADASI.
                        </div>
                    </div>

                    <div class="tw-pt-3">
                        <x-ui.button
                            type="submit"
                            size="lg"
                            class="w-100 tw-shadow-sm"
                            id="btnSubmitInvoice"
                        >
                            <x-ui.icon name="send" size="sm" />
                            <span>{{ isset($invoice) ? 'Kirim Ulang Revisi' : 'Kirim Pengajuan Invoice' }}</span>
                        </x-ui.button>
                    </div>

                    <div class="tw-rounded-ui-sm tw-bg-surface-container tw-p-3 tw-mt-3">
                        <div class="tw-flex tw-gap-2">
                            <x-ui.icon name="info" size="xs" class="tw-text-primary tw-mt-0.5 tw-shrink-0" />
                            <span class="tw-text-[11px] tw-text-on-surface-variant tw-leading-relaxed">
                                Setelah kirim dokumen digital, serahkan berkas fisik asli (Invoice, Faktur Pajak bermaterai &amp; Surat Jalan) ke loket Finance ADASI pada hari Rabu untuk verifikasi fisik.
                            </span>
                        </div>
                    </div>
                </div>
            </x-ui.card>
        </div>
    </div>
</form>

@include('local-invoices.scripts')

<script>
    // --- PO Auto-Search & Dynamic GR Handling ---
    const poSearchUrl = '{{ route('local-supplier.purchase-orders.search') }}';
    const poInput = document.getElementById('po-number');
    const poSourceInput = document.getElementById('po-source');
    const poDropdown = document.getElementById('po-dropdown');
    const poDropdownItems = document.getElementById('po-dropdown-items');
    const poSearchSpinner = document.getElementById('po-search-spinner');
    const poSearchIcon = document.getElementById('po-search-icon');
    const selectedPoBadge = document.getElementById('selected-po-badge');
    const selectedPoNumberText = document.getElementById('selected-po-number-text');
    const selectedPoDetailText = document.getElementById('selected-po-detail-text');
    const selectedPoGrBadge = document.getElementById('selected-po-gr-badge');
    const btnCopyPoAmount = document.getElementById('btn-copy-po-amount');
    const btnResetPo = document.getElementById('btn-reset-po');
    const sectionManualGr = document.getElementById('section-manual-gr');
    const manualGrInput = document.getElementById('manual-gr-reference');

    let poSearchTimeout = null;
    let selectedPoData = null;

    function showPoDropdown() {
        if (poDropdown) poDropdown.classList.remove('tw-hidden');
    }

    function hidePoDropdown() {
        if (poDropdown) poDropdown.classList.add('tw-hidden');
    }

    function setPoSearching(isSearching) {
        if (isSearching) {
            poSearchSpinner?.classList.remove('tw-hidden');
            poSearchIcon?.classList.add('tw-hidden');
        } else {
            poSearchSpinner?.classList.add('tw-hidden');
            poSearchIcon?.classList.remove('tw-hidden');
        }
    }

    async function fetchPoResults(query = '') {
        setPoSearching(true);
        try {
            const url = new URL(poSearchUrl, window.location.origin);
            if (query) url.searchParams.set('q', query);
            const res = await fetch(url.toString(), {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            if (!res.ok) throw new Error('Network response was not ok');
            const data = await res.json();
            renderPoDropdown(data.data || [], query);
        } catch (err) {
            console.error('PO search error:', err);
            if (poDropdownItems) {
                poDropdownItems.innerHTML = '<div class="tw-p-3 tw-text-center tw-text-xs tw-text-on-surface-variant">Gagal memuat data PO. Silakan ketik manual.</div>';
            }
            showPoDropdown();
        } finally {
            setPoSearching(false);
        }
    }

    function renderPoDropdown(items, query) {
        if (!poDropdownItems) return;

        if (items.length === 0) {
            poDropdownItems.innerHTML = `
                <div class="tw-p-3 tw-text-center">
                    <div class="tw-text-ui-xs tw-text-on-surface-variant tw-mb-1">
                        ${query ? 'Tidak ada PO di database yang cocok dengan "' + escapeHtml(query) + '".' : 'Tidak ada data PO aktif di database.'}
                    </div>
                    <div class="tw-text-[11px] tw-text-primary tw-font-medium">
                        Anda dapat melanjutkan dengan mengisi nomor PO manual dan surat jalan.
                    </div>
                </div>
            `;
            showPoDropdown();
            return;
        }

        let html = '';
        items.forEach((item, index) => {
            const grBadgeClass = item.has_gr
                ? 'tw-bg-emerald-50 tw-text-emerald-700 tw-border tw-border-emerald-200'
                : 'tw-bg-amber-50 tw-text-amber-700 tw-border tw-border-amber-200';
            const grBadgeText = item.has_gr
                ? (item.gr_reference ? 'GR: ' + escapeHtml(item.gr_reference) : 'GR Siap')
                : 'Belum Ada GR';

            const grWarningText = !item.has_gr
                ? '<div class="tw-text-[10px] tw-text-amber-600 tw-mt-0.5 tw-flex tw-items-center tw-gap-1"><span>⚠️ Belum bisa ditagihkan sampai GR diterbitkan oleh ADASI.</span></div>'
                : '';

            html += `
                <div
                    class="po-dropdown-item tw-p-3 hover:tw-bg-surface-container-low tw-cursor-pointer tw-transition-colors ${!item.has_gr ? 'tw-opacity-80' : ''}"
                    data-index="${index}"
                >
                    <div class="tw-flex tw-items-start tw-justify-between tw-gap-2">
                        <div>
                            <div class="tw-flex tw-items-center tw-gap-2">
                                <span class="tw-font-mono tw-font-semibold tw-text-ui-xs tw-text-primary">
                                    ${escapeHtml(item.po_number)}
                                </span>
                                <span class="tw-text-[10px] tw-font-medium tw-px-1.5 tw-py-0.5 tw-rounded ${grBadgeClass}">
                                    ${grBadgeText}
                                </span>
                            </div>
                            ${item.description ? `<div class="tw-text-[11px] tw-text-on-surface-variant tw-mt-0.5 tw-line-clamp-1">${escapeHtml(item.description)}</div>` : ''}
                            ${grWarningText}
                        </div>
                        <div class="tw-text-end tw-shrink-0">
                            <div class="tw-text-[11px] tw-font-semibold tw-text-on-surface">
                                ${item.formatted_total_amount}
                            </div>
                            <div class="tw-text-[10px] tw-text-on-surface-variant">
                                Sisa: ${item.formatted_remaining_amount}
                            </div>
                        </div>
                    </div>
                </div>
            `;
        });

        poDropdownItems.innerHTML = html;

        // Bind clicks
        poDropdownItems.querySelectorAll('.po-dropdown-item').forEach(el => {
            el.addEventListener('click', function(e) {
                e.preventDefault();
                const idx = parseInt(this.getAttribute('data-index'), 10);
                const item = items[idx];
                selectInternalPo(item);
            });
        });

        showPoDropdown();
    }

    function selectInternalPo(item) {
        if (!item.has_gr) {
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'warning',
                    title: 'GR Belum Terbit',
                    text: 'PO ' + item.po_number + ' belum memiliki referensi Goods Receipt (GR) di sistem ADASI. Pengajuan invoice untuk PO Internal hanya dapat diproses setelah material/barang diterima (GR terbit).',
                    confirmButtonText: 'Mengerti'
                });
            } else {
                alert('PO ' + item.po_number + ' belum memiliki Goods Receipt (GR) di sistem ADASI.');
            }
            return;
        }

        selectedPoData = item;
        if (poInput) poInput.value = item.po_number;
        if (poSourceInput) poSourceInput.value = 'INTERNAL';

        // Update selected badge
        if (selectedPoNumberText) selectedPoNumberText.textContent = item.po_number;
        if (selectedPoGrBadge) {
            selectedPoGrBadge.textContent = item.gr_reference ? 'GR: ' + item.gr_reference : 'GR Terverifikasi Otomatis';
        }
        if (selectedPoDetailText) {
            selectedPoDetailText.textContent = 'PO terdaftar di database ADASI • Total: ' + item.formatted_total_amount + ' • Sisa Plafon: ' + item.formatted_remaining_amount;
        }

        // Show copy amount button if remaining amount exists
        if (btnCopyPoAmount) {
            btnCopyPoAmount.classList.remove('tw-hidden');
            btnCopyPoAmount.onclick = function() {
                const dppInput = document.getElementById('invoice-amount');
                if (dppInput) {
                    dppInput.value = item.remaining_amount > 0 ? item.remaining_amount : item.total_amount;
                    updatePpnCalculation();
                }
            };
        }

        selectedPoBadge?.classList.remove('tw-hidden');
        hidePoDropdown();

        // Internal PO automatically resolves GR from DB, so hide manual GR section
        sectionManualGr?.classList.add('tw-hidden');
        if (manualGrInput) manualGrInput.required = false;
    }

    function switchToManual() {
        selectedPoData = null;
        if (poSourceInput) poSourceInput.value = 'MANUAL';
        selectedPoBadge?.classList.add('tw-hidden');
        btnCopyPoAmount?.classList.add('tw-hidden');
        sectionManualGr?.classList.remove('tw-hidden');
        if (manualGrInput) manualGrInput.required = true;
    }

    function escapeHtml(text) {
        if (!text) return '';
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return String(text).replace(/[&<>"']/g, m => map[m]);
    }

    // Event Listeners for PO Input
    if (poInput) {
        poInput.addEventListener('focus', function() {
            // Show recent POs when focused if not already locked
            if (poSourceInput?.value !== 'INTERNAL' || !selectedPoData) {
                fetchPoResults(this.value.trim());
            }
        });

        poInput.addEventListener('input', function() {
            const query = this.value.trim();

            // If user modifies text away from selected internal PO, switch to manual mode
            if (selectedPoData && query !== selectedPoData.po_number) {
                switchToManual();
            } else if (!selectedPoData) {
                switchToManual();
            }

            clearTimeout(poSearchTimeout);
            poSearchTimeout = setTimeout(() => {
                fetchPoResults(query);
            }, 250);
        });

        poInput.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                hidePoDropdown();
            }
        });
    }

    if (btnResetPo) {
        btnResetPo.addEventListener('click', function(e) {
            e.preventDefault();
            switchToManual();
            if (poInput) {
                poInput.focus();
                poInput.select();
            }
        });
    }

    // Close dropdown on click outside
    document.addEventListener('click', function(e) {
        const container = document.getElementById('po-input-container');
        if (container && !container.contains(e.target)) {
            hidePoDropdown();
        }
    });

    document.addEventListener('DOMContentLoaded', function() {
        if (poSourceInput?.value === 'MANUAL') {
            sectionManualGr?.classList.remove('tw-hidden');
            if (manualGrInput) manualGrInput.required = true;
        } else {
            sectionManualGr?.classList.add('tw-hidden');
            if (manualGrInput) manualGrInput.required = false;
        }
    });

    function updatePpnCalculation() {
        const dppInput = document.getElementById('invoice-amount');
        const taxInput = document.getElementById('tax-amount');
        const schemeSelect = document.getElementById('ppn-scheme');
        if (!dppInput || !taxInput || !schemeSelect) return;

        const dppVal = parseFloat(dppInput.value) || 0;
        const scheme = schemeSelect.value;
        let rate = 0;
        if (scheme === '11%') rate = 0.11;
        else if (scheme === '1.1%') rate = 0.011;
        else rate = 0;

        const calculatedPpn = Math.round(dppVal * rate * 100) / 100;
        taxInput.value = calculatedPpn > 0 ? calculatedPpn : (scheme === '0%' ? '0' : '');
        updateCalculations();
    }

    function formatRupiah(number) {
        if (isNaN(number) || number === null || number === '') return 'Rp 0';
        return 'Rp ' + Number(number).toLocaleString('id-ID', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
    }

    function updateCalculations() {
        const dppInput = document.getElementById('invoice-amount');
        const taxInput = document.getElementById('tax-amount');
        const dppPreview = document.getElementById('invoice-amount-preview');
        const taxPreview = document.getElementById('tax-amount-preview');
        const summaryDpp = document.getElementById('summary-dpp');
        const summaryTax = document.getElementById('summary-tax');
        const summaryTotal = document.getElementById('summary-total');

        const dppVal = parseFloat(dppInput?.value) || 0;
        const taxVal = parseFloat(taxInput?.value) || 0;
        const total = dppVal + taxVal;

        if (dppPreview) dppPreview.textContent = dppVal > 0 ? formatRupiah(dppVal) : '';
        if (taxPreview) taxPreview.textContent = taxVal > 0 ? formatRupiah(taxVal) : '';

        if (summaryDpp) summaryDpp.textContent = formatRupiah(dppVal);
        if (summaryTax) summaryTax.textContent = formatRupiah(taxVal);
        if (summaryTotal) summaryTotal.textContent = formatRupiah(total);
    }

    document.addEventListener('DOMContentLoaded', function() {
        const dppInput = document.getElementById('invoice-amount');
        const taxInput = document.getElementById('tax-amount');

        if (dppInput) {
            dppInput.addEventListener('input', function() {
                updatePpnCalculation();
            });
        }
        if (taxInput) {
            taxInput.addEventListener('input', updateCalculations);
        }
        updateCalculations();
    });

    function handleLocalFileChange(input, infoId, emptyId) {
        const infoEl = document.getElementById(infoId);
        const emptyEl = document.getElementById(emptyId);
        if (!infoEl || !emptyEl) return;

        if (input.files && input.files[0]) {
            const file = input.files[0];
            const fileNameEl = infoEl.querySelector('.file-name');
            const fileSizeEl = infoEl.querySelector('.file-size');

            let sizeFormatted = '';
            if (file.size < 1024 * 1024) {
                sizeFormatted = (file.size / 1024).toFixed(1) + ' KB';
            } else {
                sizeFormatted = (file.size / (1024 * 1024)).toFixed(2) + ' MB';
            }

            if (fileNameEl) fileNameEl.textContent = file.name;
            if (fileSizeEl) fileSizeEl.textContent = '· ' + sizeFormatted;

            emptyEl.classList.add('tw-hidden');
            infoEl.classList.remove('tw-hidden');
        } else {
            emptyEl.classList.remove('tw-hidden');
            infoEl.classList.add('tw-hidden');
        }
    }

    function maskTaxInvoiceNumber(input) {
        let value = input.value.replace(/\D/g, '').substring(0, 16);
        let formatted = '';
        if (value.length > 0) {
            formatted = value.substring(0, 3);
            if (value.length > 3) {
                formatted += '.' + value.substring(3, 6);
            }
            if (value.length > 6) {
                formatted += '-' + value.substring(6, 8);
            }
            if (value.length > 8) {
                formatted += '.' + value.substring(8, 16);
            }
        }
        input.value = formatted;
    }
</script>
