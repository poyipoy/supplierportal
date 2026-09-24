<div class="modal fade" id="uploadPoDocumentModal" tabindex="-1" aria-labelledby="uploadPoDocumentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header tw-border-b tw-border-outline-variant">
                <div>
                    <h6 class="modal-title fw-bold tw-text-on-surface" id="uploadPoDocumentModalLabel">
                        Upload Dokumen Purchase Order (PO)
                    </h6>
                    <div class="tw-text-on-surface-variant tw-text-ui-xs tw-mt-0.5">
                        Tautkan dokumen resmi PO (PDF) ke Purchase Order lokal yang telah terdaftar.
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>

            <form method="POST" action="{{ route($routePrefix.'.upload-po') }}" enctype="multipart/form-data" id="uploadPoDocumentForm">
                @csrf
                <div class="modal-body tw-p-4 tw-space-y-4">
                    {{-- Rekanan Supplier Selection --}}
                    <div>
                        <label for="poUploadSupplierId" class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface">
                            Rekanan Supplier <span class="tw-text-error">*</span>
                        </label>
                        <select
                            name="supplier_id"
                            id="poUploadSupplierId"
                            class="form-select form-select-sm tw-text-ui-xs @error('supplier_id') is-invalid @enderror"
                            required
                        >
                            <option value="">-- Pilih Rekanan Supplier --</option>
                            @foreach($suppliers as $s)
                                <option value="{{ $s->id }}" {{ (string) request('supplier_id') === (string) $s->id ? 'selected' : '' }}>
                                    {{ $s->name }} ({{ $s->supplier?->company_name ?: $s->name }})
                                </option>
                            @endforeach
                        </select>
                        @error('supplier_id')
                            <div class="invalid-feedback tw-text-[11px]">{{ $message }}</div>
                        @enderror
                    </div>

                    {{-- File Input: PDF or ZIP --}}
                    <div>
                        <x-ui.file-upload
                            name="file"
                            id="poDocumentFile"
                            label="Berkas Dokumen PO (.pdf atau .zip)"
                            helper="Unggah file tunggal .pdf (nama file wajib sesuai nomor PO, contoh: PO-2026-001.pdf) atau arsip .zip berisi kumpulan PDF PO (maksimal 50 MB)."
                            accept=".pdf,.zip"
                            required
                        />
                        @error('file')
                            <div class="tw-text-error tw-text-[11px] tw-mt-1">{{ $message }}</div>
                        @enderror
                    </div>

                    {{-- Information Box --}}
                    <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container tw-p-3 tw-text-[11px] tw-text-on-surface-variant tw-leading-relaxed">
                        <div class="tw-font-bold tw-text-on-surface tw-mb-1 d-flex align-items-center tw-gap-1.5">
                            <x-ui.icon name="info" size="xs" />
                            <span>Ketentuan Penamaan Berkas:</span>
                        </div>
                        <ul class="tw-list-disc tw-ps-3.5 tw-space-y-0.5 tw-mb-0">
                            <li>Nama berkas PDF harus persis mencantumkan <strong>Nomor PO</strong> yang sudah terdaftar di sistem.</li>
                            <li>Jika mengunggah <strong>.zip</strong>, seluruh berkas di dalam arsip wajib berformat PDF dan terverifikasi cocok 100% tanpa ada PO yang tidak ditemukan.</li>
                        </ul>
                    </div>
                </div>

                <div class="modal-footer tw-bg-surface-container-lowest tw-border-t tw-border-outline-variant">
                    <x-ui.button type="button" variant="ghost" size="sm" data-bs-dismiss="modal">
                        Batal
                    </x-ui.button>
                    <x-ui.button type="submit" size="sm" variant="primary" id="btnSubmitPoUpload">
                        <x-ui.icon name="upload" size="sm" class="me-1" />
                        <span>Upload Dokumen</span>
                    </x-ui.button>
                </div>
            </form>
        </div>
    </div>
</div>
