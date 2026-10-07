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
                            accept=".xlsx"
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

@push('scripts')
<script>
    (function() {
        let requestInFlight = false;
        const previewUrl = @json(route($routePrefix.'.import.po.preview'));

        function escapeHtml(value) {
            const span = document.createElement('span');
            span.textContent = String(value ?? '');
            return span.innerHTML;
        }

        function formatRupiah(val) {
            if (!val || val === '0.00' || val === '0') return '-';
            const num = parseFloat(val);
            if (isNaN(num)) return val;
            return 'Rp ' + Math.round(num).toLocaleString('id-ID');
        }

        const parseBtn = document.getElementById('btnParseLocalPoImport');
        const confirmBtn = document.getElementById('btnConfirmLocalPoImport');
        const spinner = document.getElementById('localPoImportSpinner');
        const fileInput = document.getElementById('localPoImportFile');
        const resultPanel = document.getElementById('localPoImportResult');
        const errorsPanel = document.getElementById('localPoImportErrorsPanel');
        const errorsList = document.getElementById('localPoImportErrorsList');
        const previewPanel = document.getElementById('localPoImportPreviewPanel');
        const previewBody = document.getElementById('localPoImportPreviewBody');
        const rowCountLabel = document.getElementById('localPoImportRowCount');
        const tokenInput = document.getElementById('localPoImportToken');

        if (!parseBtn || !fileInput) return;

        parseBtn.addEventListener('click', function() {
            if (requestInFlight) return;
            const file = fileInput.files[0];
            if (!file) {
                if (typeof AdasiToast !== 'undefined') {
                    AdasiToast.error(@js(__('finance.async_copy.choose_po')));
                } else {
                    alert(@js(__('finance.async_copy.choose_po')));
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

            document.getElementById('kpiPoTotalRows').textContent = summary.total_rows || 0;
            document.getElementById('kpiPoNew').textContent = summary.new_po || 0;
            document.getElementById('kpiPoExisting').textContent = summary.existing_po || 0;
            document.getElementById('kpiPoConflict').textContent = summary.conflicts || 0;
            document.getElementById('kpiPoInvalidRows').textContent = summary.invalid || 0;

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
                rowCountLabel.textContent = @js(__('finance.async_copy.po_count')).replace(':count', String(rows.length));
                previewBody.innerHTML = '';

                rows.slice(0, 100).forEach(r => {
                    const tr = document.createElement('tr');
                    let badge = document.createElement('span');
                    badge.className = 'badge bg-secondary';
                    badge.textContent = @js(__('finance.async_copy.unknown'));
                    badge = badge.outerHTML;
                    if (r.action === 'NEW') badge = `<span class="badge bg-primary">${escapeHtml(@js(__('finance.async_copy.new_po')))}</span>`;
                    else if (r.action === 'EXISTING') badge = `<span class="badge bg-info text-dark">${escapeHtml(@js(__('finance.async_copy.existing')))}</span>`;
                    else if (r.action === 'CONFLICT') badge = `<span class="badge bg-danger">${escapeHtml(@js(__('finance.async_copy.conflict')))}</span>`;

                    tr.innerHTML = `
                        <td class="text-center font-monospace">${escapeHtml(r._row || '-')}</td>
                        <td class="fw-semibold font-monospace">${escapeHtml(r.po_number || '-')}</td>
                        <td>${escapeHtml(r.supplier_name || '-')}</td>
                        <td class="text-center font-monospace">${escapeHtml(r.po_date || '-')}</td>
                        <td class="text-end font-monospace">${escapeHtml(formatRupiah(r.po_amount))}</td>
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
