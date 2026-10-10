<div class="modal fade" id="localGrImportModal" tabindex="-1" aria-labelledby="localGrImportModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header tw-border-b tw-border-outline-variant">
                <div>
                    <h6 class="modal-title fw-bold tw-text-on-surface" id="localGrImportModalLabel">
                        {{ __('local_procurement.import.gr_title') }}
                    </h6>
                    <div class="tw-text-on-surface-variant tw-text-ui-xs tw-mt-0.5">
                        {{ __('local_procurement.import.gr_help') }}
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
                            id="localGrImportFile"
                            :label="__('local_procurement.import.gr_file')"
                            :helper="__('local_procurement.import.gr_format')"
                            :max-size-mb="50"
                            accept=".xlsx,.csv,.xls"
                        />
                    </div>

                    <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container tw-p-3.5 tw-space-y-3">
                        <div class="tw-flex tw-items-center tw-justify-between">
                            <span class="tw-text-ui-xs tw-font-bold tw-text-on-surface">{{ __('local_procurement.import.gr_template') }}</span>
                            <x-ui.button :href="route($routePrefix.'.import.gr.template')" variant="outline" size="sm">
                                <x-ui.icon name="download" size="sm" />
                                <span>{{ __('local_procurement.import.template') }}</span>
                            </x-ui.button>
                        </div>
                        <div class="tw-text-[11px] tw-text-on-surface-variant tw-leading-relaxed">
                            <p class="tw-mb-1.5 tw-font-semibold tw-text-on-surface">{{ __('finance.review.gr_mapping') }}</p>
                            <ul class="tw-list-disc tw-ps-3.5 tw-space-y-1 tw-mb-0">
                                <li>{{ __('finance.review.column_j_gr') }}</li>
                                <li>{{ __('finance.review.column_l') }}</li>
                                <li>{{ __('finance.review.column_p') }}</li>
                                <li>{{ __('finance.review.column_t') }}</li>
                                <li>{{ __('finance.review.aggregation', ['description' => __('local_procurement.import.combine_help')]) }}</li>
                                <li>{{ __('local_procurement.import.no_amount') }}</li>
                            </ul>
                        </div>
                    </div>
                </div>

                {{-- Panel Hasil Validasi / Preview --}}
                <div id="localGrImportResult" class="d-none tw-space-y-4">
                    {{-- KPI Summary Pills --}}
                    <div class="tw-grid tw-grid-cols-2 sm:tw-grid-cols-6 tw-gap-3">
                        <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container tw-p-3">
                            <div class="tw-text-[11px] tw-text-on-surface-variant tw-font-medium">{{ __('local_procurement.import.source_rows') }}</div>
                            <div class="tw-text-title-md tw-font-bold tw-text-on-surface" id="kpiGrSourceRows">0</div>
                        </div>
                        <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container tw-p-3">
                            <div class="tw-text-[11px] tw-text-on-surface-variant tw-font-medium">{{ __('local_procurement.labels.gr_consolidated') }}</div>
                            <div class="tw-text-title-md tw-font-bold tw-text-primary" id="kpiGrConsolidated">0</div>
                        </div>
                        <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container tw-p-3">
                            <div class="tw-text-[11px] tw-text-on-surface-variant tw-font-medium">{{ __('local_procurement.labels.gr_new') }}</div>
                            <div class="tw-text-title-md tw-font-bold tw-text-success" id="kpiGrNew">0</div>
                        </div>
                        <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container tw-p-3">
                            <div class="tw-text-[11px] tw-text-on-surface-variant tw-font-medium">{{ __('local_procurement.labels.gr_existing') }}</div>
                            <div class="tw-text-title-md tw-font-bold tw-text-on-surface" id="kpiGrExisting">0</div>
                        </div>
                        <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container tw-p-3">
                            <div class="tw-text-[11px] tw-text-on-surface-variant tw-font-medium">{{ __('finance.review.po_unmatched') }}</div>
                            <div class="tw-text-title-md tw-font-bold tw-text-warning" id="kpiGrUnmatchedPo">0</div>
                        </div>
                        <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container tw-p-3">
                            <div class="tw-text-[11px] tw-text-on-surface-variant tw-font-medium">{{ __('local_procurement.import.error_rows') }}</div>
                            <div class="tw-text-title-md tw-font-bold tw-text-error" id="kpiGrInvalidRows">0</div>
                        </div>
                    </div>

                    {{-- Panel Error Terstruktur --}}
                    <div id="localGrImportErrorsPanel" class="d-none tw-rounded-ui-md tw-border-s-4 tw-border-error tw-bg-error-container tw-p-3.5 tw-text-ui-xs tw-text-error-container-foreground" role="alert">
                        <div class="fw-bold mb-1.5 d-flex align-items-center tw-gap-1.5 tw-text-ui-sm">
                            <x-ui.icon name="circle-x" size="sm" />
                            <span>{{ __('local_procurement.import.gr_error') }}</span>
                        </div>
                        <p class="tw-mb-1.5 tw-text-[11px]">
                            {{ __('local_procurement.import.errors_help') }}:
                        </p>
                        <ul id="localGrImportErrorsList" class="tw-mb-0 tw-ps-4 tw-space-y-0.5"></ul>
                    </div>

                    {{-- Tabel Pratinjau Parsed Data --}}
                    <div id="localGrImportPreviewPanel" class="d-none tw-space-y-2">
                        <div class="tw-flex tw-items-center tw-justify-between">
                            <div class="tw-font-bold tw-text-on-surface tw-text-ui-sm">
                                {{ __('finance.review.gr_preview') }}
                            </div>
                            <span class="tw-text-[11px] tw-text-on-surface-variant" id="localGrImportRowCount"></span>
                        </div>
                        <div class="local-po-import-preview-scroll table-responsive tw-border tw-border-outline-variant tw-rounded-ui-md">
                            <table class="table table-sm table-striped table-hover align-middle mb-0 tw-text-ui-xs">
                                <thead class="table-light sticky-top">
                                    <tr>
                                        <th scope="col" style="width: 45px;" class="text-center">{{ __('local_procurement.import.row') }}</th>
                                        <th scope="col">{{ __('finance.review.gr_number') }}</th>
                                        <th scope="col">{{ __('finance.review.related_po') }}</th>
                                        <th scope="col">{{ __('local_invoice.labels.description') }}</th>
                                        <th scope="col" class="text-center">{{ __('local_procurement.labels.receipt_date') }}</th>
                                        <th scope="col" class="text-end">{{ __('local_procurement.labels.gr_qty_pcs') }}</th>
                                        <th scope="col">{{ __('local_procurement.labels.uom') }}</th>
                                        <th scope="col" class="text-center">{{ __('local_procurement.import.rows_short') }}</th>
                                        <th scope="col" class="text-center">{{ __('finance.review.status_action') }}</th>
                                    </tr>
                                </thead>
                                <tbody id="localGrImportPreviewBody"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer tw-bg-surface-container-lowest tw-border-t tw-border-outline-variant">
                <x-ui.button type="button" variant="ghost" size="sm" data-bs-dismiss="modal">
                    {{ __('local_invoice.actions.cancel') }}
                </x-ui.button>
                <x-ui.button type="button" variant="outline" size="sm" id="btnParseLocalGrImport">
                    <span class="spinner-border spinner-border-sm me-1 d-none" id="localGrImportSpinner"></span>
                    <span>{{ __('local_procurement.import.validate') }}</span>
                </x-ui.button>
                <form id="localGrImportConfirmForm" method="POST" action="{{ route($routePrefix.'.import.gr.confirm') }}" class="d-inline">
                    @csrf
                    <input type="hidden" name="token" id="localGrImportToken" value="">
                    <x-ui.button type="submit" size="sm" variant="primary" id="btnConfirmLocalGrImport" disabled>
                        <x-ui.icon name="circle-check" size="sm" class="me-1" />
                        <span>{{ __('finance.review.confirm_gr') }}</span>
                    </x-ui.button>
                </form>
            </div>
        </div>
    </div>
</div>

<div data-local-import="GR" data-import-preview="{{ route($routePrefix.'.import.gr.preview') }}" data-import-index="{{ route($routePrefix.'.imports.index') }}" data-modal-id="localGrImportModal" data-previous="{{ __('local_procurement.large_import.previous') }}" data-next="{{ __('local_procurement.large_import.next') }}" data-cancel="{{ __('local_procurement.large_import.cancel') }}" hidden></div>
