<div class="modal fade" id="localPoImportModal" tabindex="-1" aria-labelledby="localPoImportModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header tw-border-b tw-border-outline-variant">
                <div>
                    <h6 class="modal-title fw-bold tw-text-on-surface" id="localPoImportModalLabel">
                        {{ __('local_procurement.import.po_title') }}
                    </h6>
                    <div class="tw-text-on-surface-variant tw-text-ui-xs tw-mt-0.5">
                        {{ __('local_procurement.import.po_help') }}
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('local_invoice.actions.close') }}"></button>
            </div>

            <div class="modal-body tw-p-4 tw-space-y-4">
                {{-- Area Upload File & Download Template --}}
                <div class="tw-grid tw-gap-4 md:tw-grid-cols-[minmax(0,1.5fr)_minmax(14rem,1fr)] tw-items-start">
                    <div class="tw-space-y-2">
                        <x-ui.file-upload
                            name="import_file"
                            id="localPoImportFile"
                            :label="__('local_procurement.import.po_file')"
                            :helper="__('local_procurement.import.po_format')"
                            :max-size-mb="50"
                            accept=".xlsx,.csv,.xls"
                        />
                    </div>

                    <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container tw-p-3.5 tw-space-y-3">
                        <div class="tw-flex tw-items-center tw-justify-between">
                            <span class="tw-text-ui-xs tw-font-bold tw-text-on-surface">{{ __('local_procurement.import.po_template') }}</span>
                            <x-ui.button :href="route($routePrefix.'.import.po.template')" variant="outline" size="sm">
                                <x-ui.icon name="download" size="sm" />
                                <span>{{ __('local_procurement.import.template') }}</span>
                            </x-ui.button>
                        </div>
                        <div class="tw-text-[11px] tw-text-on-surface-variant tw-leading-relaxed">
                            <p class="tw-mb-1.5 tw-font-semibold tw-text-on-surface">{{ __('finance.review.po_mapping') }}</p>
                            <ul class="tw-list-disc tw-ps-3.5 tw-space-y-1 tw-mb-0">
                                <li>{{ __('finance.review.column_e') }}</li>
                                <li>{{ __('finance.review.column_g') }}</li>
                                <li>{{ __('finance.review.column_j_po') }}</li>
                                <li>{{ __('finance.review.column_k') }}</li>
                                <li>{{ __('finance.review.po_duplicate') }}</li>
                            </ul>
                        </div>
                    </div>
                </div>

                {{-- Panel Hasil Validasi / Preview --}}
                <div id="localPoImportResult" class="d-none tw-space-y-4">
                    {{-- KPI Summary Pills --}}
                    <div class="tw-grid tw-grid-cols-2 sm:tw-grid-cols-5 tw-gap-3">
                        <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container tw-p-3">
                            <div class="tw-text-[11px] tw-text-on-surface-variant tw-font-medium">{{ __('local_procurement.import.rows') }}</div>
                            <div class="tw-text-title-md tw-font-bold tw-text-on-surface" id="kpiPoTotalRows">0</div>
                        </div>
                        <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container tw-p-3">
                            <div class="tw-text-[11px] tw-text-on-surface-variant tw-font-medium">{{ __('finance.review.new_po') }}</div>
                            <div class="tw-text-title-md tw-font-bold tw-text-primary" id="kpiPoNew">0</div>
                        </div>
                        <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container tw-p-3">
                            <div class="tw-text-[11px] tw-text-on-surface-variant tw-font-medium">{{ __('finance.review.existing_po') }}</div>
                            <div class="tw-text-title-md tw-font-bold tw-text-on-surface" id="kpiPoExisting">0</div>
                        </div>
                        <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container tw-p-3">
                            <div class="tw-text-[11px] tw-text-on-surface-variant tw-font-medium">{{ __('finance.review.header_conflict') }}</div>
                            <div class="tw-text-title-md tw-font-bold tw-text-warning" id="kpiPoConflict">0</div>
                        </div>
                        <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container tw-p-3">
                            <div class="tw-text-[11px] tw-text-on-surface-variant tw-font-medium">{{ __('local_procurement.import.error_rows') }}</div>
                            <div class="tw-text-title-md tw-font-bold tw-text-error" id="kpiPoInvalidRows">0</div>
                        </div>
                    </div>

                    {{-- Panel Error Terstruktur --}}
                    <div id="localPoImportErrorsPanel" class="d-none tw-rounded-ui-md tw-border-s-4 tw-border-error tw-bg-error-container tw-p-3.5 tw-text-ui-xs tw-text-error-container-foreground" role="alert">
                        <div class="fw-bold mb-1.5 d-flex align-items-center tw-gap-1.5 tw-text-ui-sm">
                            <x-ui.icon name="circle-x" size="sm" />
                            <span>{{ __('local_procurement.import.po_error') }}</span>
                        </div>
                        <p class="tw-mb-1.5 tw-text-[11px]">
                            {{ __('local_procurement.import.errors_help') }}:
                        </p>
                        <ul id="localPoImportErrorsList" class="tw-mb-0 tw-ps-4 tw-space-y-0.5"></ul>
                    </div>

                    {{-- Tabel Pratinjau Parsed Data --}}
                    <div id="localPoImportPreviewPanel" class="d-none tw-space-y-2">
                        <div class="tw-flex tw-items-center tw-justify-between">
                            <div class="tw-font-bold tw-text-on-surface tw-text-ui-sm">
                                {{ __('finance.review.po_preview') }}
                            </div>
                            <span class="tw-text-[11px] tw-text-on-surface-variant" id="localPoImportRowCount"></span>
                        </div>
                        <div class="local-po-import-preview-scroll table-responsive tw-border tw-border-outline-variant tw-rounded-ui-md">
                            <table class="table table-sm table-striped table-hover align-middle mb-0 tw-text-ui-xs">
                                <thead class="table-light sticky-top">
                                    <tr>
                                        <th scope="col" style="width: 45px;" class="text-center">{{ __('local_procurement.import.row') }}</th>
                                        <th scope="col">{{ __('local_invoice.receipt.po_number') }}</th>
                                        <th scope="col">{{ __('local_procurement.labels.supplier_partner') }}</th>
                                        <th scope="col" class="text-center">{{ __('local_procurement.labels.po_date_short') }}</th>
                                        <th scope="col" class="text-end">{{ __('finance.review.po_ceiling') }}</th>
                                        <th scope="col" class="text-center">{{ __('finance.review.status_action') }}</th>
                                    </tr>
                                </thead>
                                <tbody id="localPoImportPreviewBody"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer tw-bg-surface-container-lowest tw-border-t tw-border-outline-variant">
                <x-ui.button type="button" variant="ghost" size="sm" data-bs-dismiss="modal">
                    {{ __('local_invoice.actions.cancel') }}
                </x-ui.button>
                <x-ui.button type="button" variant="outline" size="sm" id="btnParseLocalPoImport">
                    <span class="spinner-border spinner-border-sm me-1 d-none" id="localPoImportSpinner"></span>
                    <span>{{ __('local_procurement.import.validate') }}</span>
                </x-ui.button>
                <form id="localPoImportConfirmForm" method="POST" action="{{ route($routePrefix.'.import.po.confirm') }}" class="d-inline">
                    @csrf
                    <input type="hidden" name="token" id="localPoImportToken" value="">
                    <x-ui.button type="submit" size="sm" variant="primary" id="btnConfirmLocalPoImport" disabled>
                        <x-ui.icon name="circle-check" size="sm" class="me-1" />
                        <span>{{ __('finance.review.confirm_po') }}</span>
                    </x-ui.button>
                </form>
            </div>
        </div>
    </div>
</div>

<div data-local-import="PO" data-import-preview="{{ route($routePrefix.'.import.po.preview') }}" data-import-index="{{ route($routePrefix.'.imports.index') }}" data-modal-id="localPoImportModal" data-previous="{{ __('local_procurement.large_import.previous') }}" data-next="{{ __('local_procurement.large_import.next') }}" data-cancel="{{ __('local_procurement.large_import.cancel') }}" hidden></div>
