<div class="modal fade" id="uploadGrBulkModal" tabindex="-1" aria-labelledby="uploadGrBulkModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header tw:border-b tw:border-outline-variant">
                <div>
                    <h6 class="modal-title fw-bold tw:text-on-surface" id="uploadGrBulkModalLabel">
                        {{ __('local_procurement.gr_documents.title') }}
                    </h6>
                    <div class="tw:text-on-surface-variant tw:text-ui-xs tw:mt-0.5">
                        {{ __('local_procurement.gr_documents.help') }}
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('local_invoice.actions.close') }}"></button>
            </div>

            <form method="POST" action="{{ route($routePrefix.'.upload-gr') }}" enctype="multipart/form-data" id="uploadGrBulkForm" data-async-submit data-async-summary-skip-file-errors="true">
                @csrf
                <div class="modal-body tw:p-4 tw:space-y-4">
                    <x-ui.searchable-select
                        name="purchase_order"
                        id="grUploadPurchaseOrder"
                        :label="__('local_procurement.gr_documents.po_label')"
                        :placeholder="__('local_procurement.gr_documents.po_placeholder')"
                        :search-placeholder="__('local_procurement.gr_documents.po_search')"
                        :empty-message="__('local_procurement.gr_documents.po_empty')"
                        :options="$grPoOptions ?? []"
                        :suggestions-url="route($routePrefix.'.po-suggestions', [], false)"
                        :remote="true"
                        :required="true"
                        :helper="__('local_procurement.gr_documents.po_help')"
                    />

                    <x-ui.file-upload
                        name="file"
                        id="grDocumentFile"
                        :label="__('local_procurement.gr_documents.file')"
                        :helper="__('local_procurement.gr_documents.formats')"
                        accept=".pdf,.zip"
                        :max-size-mb="config('native_file_security.zip.max_upload_bytes') / 1024 / 1024"
                        required
                    />

                    <div class="tw:rounded-ui-md tw:border tw:border-outline-variant tw:bg-surface-container tw:p-3 tw:text-[11px] tw:text-on-surface-variant tw:leading-relaxed">
                        <div class="tw:font-bold tw:text-on-surface tw:mb-1 d-flex align-items-center tw:gap-1.5">
                            <x-ui.icon name="info" size="xs" />
                            <span>{{ __('local_procurement.surface.naming') }}</span>
                        </div>
                        <ul class="tw:list-disc tw:ps-3.5 tw:space-y-0.5 tw:mb-0">
                            <li>{{ __('local_procurement.gr_documents.pdf_name') }}</li>
                            <li>{{ __('local_procurement.gr_documents.zip_rule') }}</li>
                        </ul>
                    </div>
                </div>

                <div class="modal-footer tw:bg-surface-container-lowest tw:border-t tw:border-outline-variant">
                    <x-ui.button type="button" variant="ghost" size="sm" data-bs-dismiss="modal">
                        {{ __('local_invoice.actions.cancel') }}
                    </x-ui.button>
                    <x-ui.button type="submit" size="sm" variant="primary" id="btnSubmitGrUpload">
                        <x-ui.icon name="upload" size="sm" class="me-1" />
                        <span>{{ __('local_invoice.form.document_upload') }}</span>
                    </x-ui.button>
                </div>
            </form>
        </div>
    </div>
</div>

