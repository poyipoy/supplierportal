<div class="modal fade" id="uploadPoDocumentModal" tabindex="-1" aria-labelledby="uploadPoDocumentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header tw-border-b tw-border-outline-variant">
                <div>
                    <h6 class="modal-title fw-bold tw-text-on-surface" id="uploadPoDocumentModalLabel">
                        {{ __('local_procurement.actions.upload_po_full') }}
                    </h6>
                    <div class="tw-text-on-surface-variant tw-text-ui-xs tw-mt-0.5">
                        {{ __('local_procurement.documents.upload_help') }}
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('local_invoice.actions.close') }}"></button>
            </div>

            <form method="POST" action="{{ route($routePrefix.'.upload-po') }}" enctype="multipart/form-data" id="uploadPoDocumentForm" data-async-submit data-po-document-upload data-async-summary-skip-file-errors="true" data-uploading="{{ __('po_documents.uploading') }}" data-offline="{{ __('po_documents.offline') }}">
                <input type="hidden" name="request_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                @csrf
                <div class="modal-body tw-p-4 tw-space-y-4">
                    {{-- Rekanan Supplier Selection --}}
                    <div>
                        <label for="poUploadSupplierId" class="form-label tw-text-ui-xs tw-font-semibold tw-text-on-surface">
                            {{ __('local_procurement.labels.supplier_partner') }} <span class="tw-text-error">*</span>
                        </label>
                        <select
                            name="supplier_id"
                            id="poUploadSupplierId"
                            class="form-select form-select-sm tw-text-ui-xs @error('supplier_id') is-invalid @enderror"
                            required
                        >
                            <option value="">{{ __('local_procurement.labels.supplier_option') }}</option>
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
                            :label="__('local_procurement.documents.file')"
                            :helper="__('local_procurement.documents.upload_formats')"
                            accept=".pdf,.zip"
                            :max-size-mb="config('native_file_security.zip.max_upload_bytes') / 1024 / 1024"
                            required
                        />
                    </div>

                    {{-- Information Box --}}
                    <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container tw-p-3 tw-text-[11px] tw-text-on-surface-variant tw-leading-relaxed">
                        <div class="tw-font-bold tw-text-on-surface tw-mb-1 d-flex align-items-center tw-gap-1.5">
                            <x-ui.icon name="info" size="xs" />
                            <span>{{ __('local_procurement.surface.naming') }}</span>
                        </div>
                        <ul class="tw-list-disc tw-ps-3.5 tw-space-y-0.5 tw-mb-0">
                            <li>{{ __('local_procurement.surface.pdf_name') }}</li>
                            <li>{{ __('local_procurement.surface.zip_files') }}</li>
                        </ul>
                    </div>
                </div>

                <div class="modal-footer tw-bg-surface-container-lowest tw-border-t tw-border-outline-variant">
                    <p data-po-upload-status role="status" aria-live="polite" class="tw-text-ui-xs tw-mb-0"></p>
                    <progress data-po-upload-progress hidden max="100" aria-label="{{ __('po_documents.progress') }}"></progress>
                    <button type="button" data-po-upload-retry hidden class="btn btn-sm btn-outline-primary">{{ __('po_documents.retry') }}</button>
                    <x-ui.button type="button" variant="ghost" size="sm" data-bs-dismiss="modal">
                        {{ __('local_invoice.actions.cancel') }}
                    </x-ui.button>
                    <x-ui.button type="submit" size="sm" variant="primary" id="btnSubmitPoUpload">
                        <x-ui.icon name="upload" size="sm" class="me-1" />
                        <span>{{ __('local_invoice.form.document_upload') }}</span>
                    </x-ui.button>
                </div>
            </form>
        </div>
    </div>
</div>
