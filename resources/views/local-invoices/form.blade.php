@php
    $invoice = $invoice ?? null;
    $supplier = auth()->user()->supplier;
    $companyName = $supplier?->company_name ?: auth()->user()->name;
    $isPkp = (bool) ($supplier?->is_pkp ?? false);
    $vendorCategory = $supplier?->vendor_category ?: $supplier?->category ?: 'Barang';
    $requiresDeliveryNote = $vendorCategory === 'Barang';
    $selectedPo = old('local_purchase_order_id', $invoice->local_purchase_order_id ?? '');
    $selectedGrs = collect(old('goods_receipt_ids', $invoice?->goodsReceiptHistories?->whereIn('state', ['RESERVED', 'CONSUMED'])->pluck('local_goods_receipt_id')->all() ?? []))->map(fn ($v) => (string) $v)->all();
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

<form method="POST" enctype="multipart/form-data" action="{{ isset($invoice) ? route('local-supplier.invoices.resubmit', $invoice) : route('local-supplier.invoices.store') }}" id="localInvoiceForm">
    @csrf
    <div class="tw-grid tw-gap-6 lg:tw-grid-cols-12 tw-items-start">
        <div class="lg:tw-col-span-8 tw-space-y-6">
            {{-- 1. Profil Vendor & Ketentuan --}}
            <x-ui.form-section title="Informasi Vendor" description="Profil supplier terdaftar.">
                <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container tw-p-4">
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
            </x-ui.form-section>

            {{-- 2. Informasi Tagihan & Alokasi GR --}}
            <x-ui.form-section title="Informasi Invoice & Alokasi Penerimaan Barang (GR)" description="Satu invoice terhubung ke satu PO dan satu atau lebih Penerimaan Barang (GR) utuh. Total nilai GR terpilih harus sama persis dengan DPP.">
                <div class="tw-grid tw-gap-4 sm:tw-grid-cols-2">
                    <div>
                        <label for="invoice-number" class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface">
                            Nomor Invoice <span class="tw-text-error">*</span>
                        </label>
                        <input
                            name="invoice_number"
                            id="invoice-number"
                            class="form-control form-control-sm"
                            value="{{ old('invoice_number', $invoice->invoice_number ?? '') }}"
                            placeholder="Contoh: INV/2026/09/001"
                            @readonly(isset($invoice))
                            required
                        >
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

                    @php
                        $localPoGrMap = collect($purchaseOrders ?? [])->mapWithKeys(fn($po) => [
                            (string) $po->id => $po->goodsReceipts->map(fn($gr) => [
                                'id' => $gr->id,
                                'number' => $gr->gr_number,
                                'amount' => (string) $gr->received_amount,
                                'date' => $gr->gr_date?->format('Y-m-d'),
                            ])->values(),
                        ]);

                        $poOptions = collect($purchaseOrders ?? [])->map(function ($po) {
                            $grNumbers = $po->goodsReceipts->pluck('gr_number')->implode(', ');
                            $sublabelParts = [];
                            $sublabelParts[] = 'Tanggal: ' . ($po->po_date?->format('d M Y') ?? '-');
                            if ($grNumbers) {
                                $sublabelParts[] = 'GR: ' . $grNumbers;
                            }
                            if ($po->notes) {
                                $sublabelParts[] = Str::limit($po->notes, 30);
                            }

                            return [
                                'value' => (string) $po->id,
                                'label' => $po->po_number . ' · Rp ' . number_format($po->total_amount, 2, ',', '.'),
                                'sublabel' => implode(' · ', $sublabelParts),
                                'badge' => $po->status,
                                'badgeTone' => $po->status === 'OPEN' ? 'success' : 'neutral',
                                'searchKeywords' => $po->po_number . ' ' . $po->total_amount . ' ' . number_format($po->total_amount, 0, ',', '.') . ' ' . $po->status . ' ' . $grNumbers,
                            ];
                        })->all();

                        $initialGrOptions = [];
                        if (!empty($selectedPo)) {
                            $initialGrOptions = ($localPoGrMap[(string) $selectedPo] ?? collect())->map(fn ($gr) => [
                                'value' => (string) $gr['id'],
                                'label' => $gr['number'],
                                'sublabel' => $gr['date'] ? 'Tanggal: ' . $gr['date'] : null,
                                'amount' => (float) $gr['amount'],
                                'date' => $gr['date'] ?? null,
                                'searchKeywords' => $gr['number'] . ' ' . $gr['amount'] . ' ' . ($gr['date'] ?? ''),
                            ])->all();
                        }
                    @endphp

                    <div class="sm:tw-col-span-2">
                        <x-ui.searchable-select
                            name="local_purchase_order_id"
                            id="local_purchase_order_id"
                            label="Purchase Order (PO) Lokal"
                            placeholder="Pilih PO berstatus OPEN..."
                            search-placeholder="Cari nomor PO, nominal, atau status..."
                            :options="$poOptions"
                            :value="$selectedPo"
                            required
                        />
                    </div>

                    <div class="sm:tw-col-span-2">
                        <x-ui.multi-select
                            name="goods_receipt_ids[]"
                            id="goods_receipt_ids"
                            label="Penerimaan Barang (GR) Utuh"
                            placeholder="Pilih GR yang sesuai..."
                            disabled-placeholder="Pilih PO terlebih dahulu..."
                            search-placeholder="Cari nomor GR atau nominal..."
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
                                Dasar Pengenaan Pajak (harus persis sama dengan total GR terpilih).
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
                            <label for="tax_invoice_number" class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface tw-mb-0">
                                Nomor Faktur Pajak @if($isPkp) <span class="tw-text-error">*</span> @else <span class="tw-text-on-surface-variant tw-font-normal">(Opsional untuk Non-PKP)</span> @endif
                            </label>
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

            {{-- 3. Dokumen Lampiran Berkas --}}
            @php
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
            @endphp
            <x-ui.form-section title="Dokumen Lampiran" description="Penyimpanan privat terenkripsi di server; format berkas PDF, JPG, JPEG, atau PNG, maks. 5 MB per berkas (maks. 5 berkas per kategori).">
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
            </x-ui.form-section>
        </div>

        {{-- Sticky Sidebar: Ringkasan Pengajuan & Kesesuaian Alokasi --}}
        <div class="lg:tw-col-span-4 tw-sticky" style="top: calc(var(--topbar-height, 56px) + 1.25rem)">
            <x-ui.card title="Ringkasan Pengajuan">
                <div class="tw-space-y-4">
                    {{-- Financial Breakdown --}}
                    <div class="tw-space-y-2.5 tw-text-ui-xs">
                        <div class="tw-flex tw-items-center tw-justify-between tw-text-on-surface-variant">
                            <span>DPP Invoice:</span>
                            <span id="summary-dpp" class="tw-font-mono tw-font-semibold tw-text-on-surface">Rp 0</span>
                        </div>
                        <div class="tw-flex tw-items-center tw-justify-between tw-text-on-surface-variant">
                            <span>Estimasi PPN:</span>
                            <span id="summary-tax" class="tw-font-mono tw-font-semibold tw-text-on-surface">Rp 0</span>
                        </div>
                        <div class="tw-flex tw-items-center tw-justify-between tw-border-t tw-border-outline-variant tw-pt-2.5 tw-text-ui-sm">
                            <span class="tw-font-bold tw-text-on-surface">Grand Total:</span>
                            <span id="summary-grand-total" class="tw-font-mono tw-font-bold tw-text-primary tw-text-ui-base">Rp 0</span>
                        </div>
                    </div>

                    {{-- Allocation Status Card --}}
                    <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container tw-p-3.5 tw-space-y-2.5">
                        <div class="tw-flex tw-items-center tw-justify-between tw-text-ui-xs">
                            <span class="tw-text-on-surface-variant">Total Alokasi GR:</span>
                            <span id="summary-gr" class="tw-font-mono tw-font-semibold tw-text-on-surface">Rp 0</span>
                        </div>
                        <div class="tw-flex tw-items-center tw-justify-between tw-text-ui-xs">
                            <span class="tw-text-on-surface-variant">Kesesuaian:</span>
                            <span id="summary-match-chip">
                                <span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-medium tw-bg-surface-high tw-text-on-surface-variant">
                                    Menunggu Pilihan
                                </span>
                            </span>
                        </div>
                        <div id="summary-diff-container" class="tw-hidden tw-text-[11px] tw-text-error tw-pt-1.5 tw-border-t tw-border-outline-variant">
                            <div class="tw-flex tw-items-center tw-justify-between">
                                <span>Selisih DPP & GR:</span>
                                <strong id="summary-diff-amount" class="tw-font-mono">Rp 0</strong>
                            </div>
                        </div>
                    </div>

                    {{-- Petunjuk Pengajuan --}}
                    <div class="tw-text-ui-xs tw-text-on-surface-variant tw-pt-1">
                        <p class="tw-text-[11px] tw-text-on-surface-variant tw-m-0 tw-leading-relaxed">
                            Pastikan nominal DPP sama persis dengan total GR utuh yang dipilih agar proses verifikasi Finance berjalan lancar.
                        </p>
                    </div>

                    {{-- Tombol Kirim Pengajuan --}}
                    <div class="tw-pt-2">
                        <x-ui.button type="submit" variant="primary" class="tw-w-full tw-justify-center" id="btnSubmitInvoice">
                            <x-ui.icon name="send" size="sm" />
                            <span>{{ isset($invoice) ? 'Kirim Revisi Invoice' : 'Ajukan Invoice' }}</span>
                        </x-ui.button>
                    </div>
                </div>
            </x-ui.card>
        </div>
    </div>
</form>

<script>
const localPoGr = @json($localPoGrMap);
const poSelect = document.getElementById('local_purchase_order_id');
const grSelect = document.getElementById('goods_receipt_ids');
const dppInput = document.getElementById('invoice-amount');
const taxInput = document.getElementById('tax-amount');
const schemeSelect = document.getElementById('ppn-scheme');
const summaryGr = document.getElementById('summary-gr');
const summaryDpp = document.getElementById('summary-dpp');
const summaryTax = document.getElementById('summary-tax');
const summaryGrandTotal = document.getElementById('summary-grand-total');
const summaryMatchChip = document.getElementById('summary-match-chip');
const summaryDiffContainer = document.getElementById('summary-diff-container');
const summaryDiffAmount = document.getElementById('summary-diff-amount');
const submitBtn = document.getElementById('btnSubmitInvoice');

let currentGrTotal = 0;
let isInitialLoad = true;

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

function updateTotals(grTotal, syncDpp = false) {
    currentGrTotal = Number(grTotal || 0);

    if (syncDpp && dppInput) {
        dppInput.value = currentGrTotal > 0 ? currentGrTotal.toFixed(2) : '';
        calculateTax();
    }

    const dpp = Number(dppInput?.value || 0);
    const tax = Number(taxInput?.value || 0);
    const grandTotal = dpp + tax;

    if (summaryGr) summaryGr.textContent = rupiah(currentGrTotal);
    if (summaryDpp) summaryDpp.textContent = rupiah(dpp);
    if (summaryTax) summaryTax.textContent = rupiah(tax);
    if (summaryGrandTotal) summaryGrandTotal.textContent = rupiah(grandTotal);

    if (summaryMatchChip) {
        if (currentGrTotal === 0 && dpp === 0) {
            summaryMatchChip.innerHTML = '<span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-medium tw-bg-surface-high tw-text-on-surface-variant">Menunggu Pilihan</span>';
            if (summaryDiffContainer) summaryDiffContainer.classList.add('tw-hidden');
        } else {
            const diff = Math.abs(currentGrTotal - dpp);
            const isMatch = diff < 0.005;

            if (isMatch) {
                summaryMatchChip.innerHTML = '<span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-semibold tw-bg-success/10 tw-text-success"><svg class="tw-w-3 tw-h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path></svg> 100% Sesuai</span>';
                if (summaryDiffContainer) summaryDiffContainer.classList.add('tw-hidden');
            } else {
                summaryMatchChip.innerHTML = '<span class="tw-inline-flex tw-items-center tw-gap-1 tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-semibold tw-bg-error/10 tw-text-error"><svg class="tw-w-3 tw-h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg> Selisih</span>';
                if (summaryDiffContainer) {
                    summaryDiffContainer.classList.remove('tw-hidden');
                    if (summaryDiffAmount) summaryDiffAmount.textContent = rupiah(diff);
                }
            }
        }
    }
}

poSelect?.addEventListener('change', () => {
    const poVal = poSelect.value;
    const rows = (poVal && localPoGr[String(poVal)]) ? localPoGr[String(poVal)] : [];
    const emptyMsg = poVal ? 'Tidak ada GR TERSEDIA untuk PO ini.' : 'Pilih PO terlebih dahulu...';

    window.dispatchEvent(new CustomEvent('set-multi-options', {
        detail: {
            id: 'goods_receipt_ids',
            options: rows,
            autoSelect: true,
            emptyPlaceholder: emptyMsg,
        }
    }));
});

grSelect?.addEventListener('multi-select-change', (e) => {
    const total = Number(e.detail?.total ?? 0);
    const shouldSyncDpp = isInitialLoad ? (!dppInput.value || Number(dppInput.value) === 0) : true;
    isInitialLoad = false;
    updateTotals(total, shouldSyncDpp);
});

dppInput?.addEventListener('input', () => {
    calculateTax();
    updateTotals(currentGrTotal, false);
});

taxInput?.addEventListener('input', () => {
    updateTotals(currentGrTotal, false);
});

schemeSelect?.addEventListener('change', () => {
    calculateTax();
    updateTotals(currentGrTotal, false);
});

// Initial state calculation
const initialDpp = Number(dppInput?.value || 0);
if (initialDpp > 0 && (!taxInput.value || Number(taxInput.value) === 0)) {
    calculateTax();
}
updateTotals(0, false);

// Submit button loading state
const invoiceForm = document.getElementById('localInvoiceForm');
invoiceForm?.addEventListener('submit', function() {
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
