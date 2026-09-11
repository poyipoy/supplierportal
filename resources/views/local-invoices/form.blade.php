@php
    $termDays = isset($invoice) ? (int) $invoice->payment_term_days_snapshot : (int) (auth()->user()->supplier?->payment_term_days ?? 30);
    $companyName = auth()->user()->supplier?->company_name ?: auth()->user()->name;
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
                            <span class="tw-block tw-text-ui-xs tw-text-on-surface-variant">{{ auth()->user()->email }}</span>
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
                description="Specify your official invoice details and the associated ADASI PO number."
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

                    <div class="sm:tw-col-span-2">
                        <label for="po-number" class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface tw-mb-2">
                            Purchase Order (PO) Number <span class="text-danger">*</span>
                        </label>
                        <input
                            type="text"
                            class="form-control @error('po_number') is-invalid @enderror"
                            id="po-number"
                            name="po_number"
                            value="{{ old('po_number', $invoice->po_number ?? '') }}"
                            maxlength="100"
                            placeholder="e.g. PO-LOCAL-2026-001"
                            required
                        >
                        <small class="tw-text-ui-xs tw-text-on-surface-variant tw-mt-1.5 tw-block">
                            Enter the reference number from the Purchase Order issued by PT Astra Daido Steel Indonesia.
                        </small>
                        @error('po_number')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </x-ui.form-section>

            {{-- Financial Values --}}
            <x-ui.form-section
                title="Financial Amounts (IDR)"
                description="Enter the DPP (Dasar Pengenaan Pajak) and PPN separately. Values will be automatically totaled."
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
                            <label for="tax-amount" class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface tw-m-0">
                                PPN 11% (IDR) <span class="text-danger">*</span>
                            </label>
                            <button
                                type="button"
                                id="btn-calc-ppn"
                                class="btn btn-link tw-p-0 tw-text-ui-xs tw-text-primary tw-no-underline hover:tw-underline tw-inline-flex tw-items-center tw-gap-1"
                            >
                                <x-ui.icon name="calculator" size="xs" /> Auto-calc 11%
                            </button>
                        </div>
                        <div class="input-group">
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
                                required
                            >
                        </div>
                        <span id="tax-amount-preview" class="tw-text-ui-xs tw-text-primary tw-font-mono tw-mt-1.5 tw-block"></span>
                        @error('tax_amount')
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
                                    Faktur Pajak Elektronik <span class="text-danger">*</span>
                                </label>
                                <span class="tw-text-ui-xs tw-text-on-surface-variant">Faktur Pajak resmi dari DJP dengan QR Code valid.</span>
                            </div>
                            <span class="tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-semibold tw-bg-primary/10 tw-text-primary tw-shrink-0">Required</span>
                        </div>

                        <label for="file-tax_invoice" class="tw-relative tw-block tw-border-2 tw-border-dashed tw-border-outline-variant hover:tw-border-primary/60 tw-rounded-ui-sm tw-p-4 tw-text-center tw-bg-surface hover:tw-bg-primary/[0.02] tw-transition-all tw-cursor-pointer tw-m-0">
                            <input
                                type="file"
                                class="tw-sr-only @error('tax_invoice') is-invalid @enderror"
                                id="file-tax_invoice"
                                name="tax_invoice"
                                accept=".pdf,.jpg,.jpeg,.png"
                                required
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

                    {{-- Supporting Document File --}}
                    <div class="tw-border tw-border-outline-variant tw-rounded-ui-sm tw-p-3.5 tw-bg-surface-container-low hover:tw-border-primary/40 tw-transition-colors">
                        <div class="tw-flex tw-items-start tw-justify-between tw-gap-3 tw-mb-2">
                            <div>
                                <label for="file-supporting" class="tw-font-semibold tw-text-ui-sm tw-text-on-surface tw-block">
                                    Dokumen Pendukung
                                </label>
                                <span class="tw-text-ui-xs tw-text-on-surface-variant">Surat Jalan, Berita Acara Serah Terima (BAST), atau PO Copy (Opsional).</span>
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
                        <div class="tw-flex tw-justify-between tw-text-ui-xs">
                            <span class="tw-text-on-surface-variant">Estimasi Jatuh Tempo:</span>
                            <span id="summary-due-date" class="tw-font-semibold tw-text-on-surface">—</span>
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
                                Setelah kirim dokumen digital, kirimkan berkas fisik asli (Invoice &amp; Faktur Pajak bermaterai) ke loket Accounting ADASI untuk verifikasi fisik.
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
    document.addEventListener('DOMContentLoaded', function() {
        const dppInput = document.getElementById('invoice-amount');
        const taxInput = document.getElementById('tax-amount');
        const btnCalcPpn = document.getElementById('btn-calc-ppn');
        const dateInput = document.getElementById('invoice-date');

        const dppPreview = document.getElementById('invoice-amount-preview');
        const taxPreview = document.getElementById('tax-amount-preview');

        const summaryDpp = document.getElementById('summary-dpp');
        const summaryTax = document.getElementById('summary-tax');
        const summaryTotal = document.getElementById('summary-total');
        const summaryDueDate = document.getElementById('summary-due-date');

        const termDays = {{ (int) $termDays }};

        function formatRupiah(number) {
            if (isNaN(number) || number === null || number === '') return 'Rp 0';
            return 'Rp ' + Number(number).toLocaleString('id-ID', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
        }

        function updateCalculations() {
            const dppVal = parseFloat(dppInput.value) || 0;
            const taxVal = parseFloat(taxInput.value) || 0;
            const total = dppVal + taxVal;

            if (dppPreview) dppPreview.textContent = dppVal > 0 ? formatRupiah(dppVal) : '';
            if (taxPreview) taxPreview.textContent = taxVal > 0 ? formatRupiah(taxVal) : '';

            if (summaryDpp) summaryDpp.textContent = formatRupiah(dppVal);
            if (summaryTax) summaryTax.textContent = formatRupiah(taxVal);
            if (summaryTotal) summaryTotal.textContent = formatRupiah(total);

            // Calculate estimated due date
            if (dateInput && dateInput.value && summaryDueDate) {
                const invoiceDate = new Date(dateInput.value);
                if (!isNaN(invoiceDate.getTime())) {
                    invoiceDate.setDate(invoiceDate.getDate() + termDays);
                    summaryDueDate.textContent = invoiceDate.toLocaleDateString('id-ID', {
                        day: 'numeric',
                        month: 'short',
                        year: 'numeric'
                    });
                } else {
                    summaryDueDate.textContent = '—';
                }
            }
        }

        if (btnCalcPpn && dppInput && taxInput) {
            btnCalcPpn.addEventListener('click', function() {
                const dppVal = parseFloat(dppInput.value) || 0;
                const calculatedPpn = Math.round(dppVal * 0.11 * 100) / 100;
                taxInput.value = calculatedPpn > 0 ? calculatedPpn : '';
                updateCalculations();
            });
        }

        if (dppInput) dppInput.addEventListener('input', updateCalculations);
        if (taxInput) taxInput.addEventListener('input', updateCalculations);
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
</script>
