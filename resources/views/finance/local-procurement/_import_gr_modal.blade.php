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
                            accept=".xlsx"
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

@push('scripts')
<script>
    (function() {
        let requestInFlight = false;
        const previewUrl = @json(route($routePrefix.'.import.gr.preview'));

        function formatQty(val) {
            if (val === null || val === undefined || val === '') return '-';
            const num = parseFloat(val);
            if (isNaN(num)) return val;
            return num.toLocaleString('id-ID', { minimumFractionDigits: 0, maximumFractionDigits: 4 });
        }

        function escapeHtml(str) {
            if (str === null || str === undefined || str === '') return '-';
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }

        const parseBtn = document.getElementById('btnParseLocalGrImport');
        const confirmBtn = document.getElementById('btnConfirmLocalGrImport');
        const spinner = document.getElementById('localGrImportSpinner');
        const fileInput = document.getElementById('localGrImportFile');
        const resultPanel = document.getElementById('localGrImportResult');
        const errorsPanel = document.getElementById('localGrImportErrorsPanel');
        const errorsList = document.getElementById('localGrImportErrorsList');
        const previewPanel = document.getElementById('localGrImportPreviewPanel');
        const previewBody = document.getElementById('localGrImportPreviewBody');
        const rowCountLabel = document.getElementById('localGrImportRowCount');
        const tokenInput = document.getElementById('localGrImportToken');

        if (!parseBtn || !fileInput) return;

        parseBtn.addEventListener('click', function() {
            if (requestInFlight) return;
            const file = fileInput.files[0];
            if (!file) {
                if (typeof AdasiToast !== 'undefined') {
                    AdasiToast.error(@js(__('finance.async_copy.choose_gr')));
                } else {
                    alert(@js(__('finance.async_copy.choose_gr')));
                }
                return;
            }

            requestInFlight = true;
            parseBtn.disabled = true;
            spinner.classList.remove('d-none');
            confirmBtn.disabled = true;
            tokenInput.value = '';

            const formData = new FormData();
            formData.append('import_file', file);
            formData.append('_token', '{{ csrf_token() }}');

            fetch(previewUrl, {
                method: 'POST',
                headers: { 'Accept': 'application/json' },
                body: formData
            })
            .then(res => res.json().then(data => ({ status: res.status, body: data })))
            .then(({ status, body }) => {
                requestInFlight = false;
                parseBtn.disabled = false;
                spinner.classList.add('d-none');

                if (status >= 400) {
                    resultPanel.classList.remove('d-none');
                    errorsPanel.classList.remove('d-none');
                    previewPanel.classList.add('d-none');
                    errorsList.innerHTML = '';
                    const msgs = body.errors ? Object.values(body.errors).flat() : [body.message || @js(__('finance.async_copy.validation_error'))];
                    msgs.forEach(m => {
                        const li = document.createElement('li');
                        li.textContent = m;
                        errorsList.appendChild(li);
                    });
                    return;
                }

                renderPreview(body.preview, body.token);
            })
            .catch(err => {
                requestInFlight = false;
                parseBtn.disabled = false;
                spinner.classList.add('d-none');
                if (typeof AdasiToast !== 'undefined') {
                    AdasiToast.error(@js(__('finance.async_copy.server_error')).replace(':message', String(err.message)));
                } else {
                    alert(@js(__('finance.async_copy.server_error')).replace(':message', String(err.message)));
                }
            });
        });

        function renderPreview(preview, token) {
            resultPanel.classList.remove('d-none');
            const summary = preview.summary || {};

            document.getElementById('kpiGrSourceRows').textContent = summary.source_rows || 0;
            document.getElementById('kpiGrConsolidated').textContent = summary.consolidated_gr || 0;
            document.getElementById('kpiGrNew').textContent = summary.new_gr || 0;
            document.getElementById('kpiGrExisting').textContent = summary.existing_gr || 0;
            document.getElementById('kpiGrUnmatchedPo').textContent = summary.unmatched_po || 0;
            document.getElementById('kpiGrInvalidRows').textContent = summary.invalid || 0;

            if (!preview.success && preview.errors && preview.errors.length > 0) {
                errorsPanel.classList.remove('d-none');
                errorsList.innerHTML = '';
                preview.errors.forEach(e => {
                    const li = document.createElement('li');
                    li.textContent = @js(__('finance.async_copy.row_error')).replace(':row', String(e.row || '-')).replace(':column', String(e.column || '-')).replace(':message', String(e.message));
                    errorsList.appendChild(li);
                });
                confirmBtn.disabled = true;
            } else {
                errorsPanel.classList.add('d-none');
                errorsList.innerHTML = '';
                if (preview.success && token) {
                    tokenInput.value = token;
                    confirmBtn.disabled = false;
                }
            }

            const rows = preview.rows || [];
            if (rows.length > 0) {
                previewPanel.classList.remove('d-none');
                rowCountLabel.textContent = @js(__('finance.async_copy.gr_count')).replace(':count', String(rows.length)).replace(':rows', String(preview.source_row_count || 0));
                previewBody.innerHTML = '';

                rows.slice(0, 100).forEach(r => {
                    const tr = document.createElement('tr');
                    let badge = document.createElement('span');
                    badge.className = 'badge bg-secondary';
                    badge.textContent = @js(__('finance.async_copy.unknown'));
                    badge = badge.outerHTML;
                    if (r.action === 'NEW') badge = `<span class="badge bg-success">${escapeHtml(@js(__('finance.async_copy.new_gr')))}</span>`;
                    else if (r.action === 'EXISTING') badge = `<span class="badge bg-info text-white">${escapeHtml(@js(__('finance.async_copy.existing')))}</span>`;
                    else if (r.action === 'UNMATCHED_PO') badge = `<span class="badge bg-danger">${escapeHtml(@js(__('finance.async_copy.unmatched')))}</span>`;
                    else if (r.action === 'PO_CLOSED') badge = `<span class="badge bg-warning text-dark">${escapeHtml(@js(__('finance.async_copy.closed')))}</span>`;
                    else if (r.action === 'CONFLICT') badge = `<span class="badge bg-danger">${escapeHtml(@js(__('finance.async_copy.conflict')))}</span>`;

                    tr.innerHTML = `
                        <td class="text-center font-monospace">${escapeHtml(r._row || '-')}</td>
                        <td class="fw-semibold font-monospace">${escapeHtml(r.gr_number || '-')}</td>
                        <td class="font-monospace">${escapeHtml(r.po_number || '-')}</td>
                        <td class="tw-max-w-[200px] tw-truncate" title="${escapeHtml(r.description || '')}">${escapeHtml(r.description || '-')}</td>
                        <td class="text-center font-monospace">${escapeHtml(r.gr_date || '-')}</td>
                        <td class="text-end font-monospace">${escapeHtml(formatQty(r.qty))}</td><td>${escapeHtml(r.uom || '—')}</td>
                        <td class="text-center font-monospace"><span class="badge bg-light text-dark border">${escapeHtml(@js(__('finance.async_copy.source_rows')).replace(':count', String(r.source_rows_count || 1)))}</span></td>
                        <td class="text-center">${badge}</td>
                    `;
                    previewBody.appendChild(tr);
                });
            } else {
                previewPanel.classList.add('d-none');
            }
        }
    })();
</script>
@endpush
