<div class="modal fade" id="localGrImportModal" tabindex="-1" aria-labelledby="localGrImportModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header tw-border-b tw-border-outline-variant">
                <div>
                    <h6 class="modal-title fw-bold tw-text-on-surface" id="localGrImportModalLabel">
                        Import Goods Receipt (Infor ERP Reference)
                    </h6>
                    <div class="tw-text-on-surface-variant tw-text-ui-xs tw-mt-0.5">
                        Unggah file ekspor ERP Infor (.xlsx) untuk memproses GR ke PO yang telah terdaftar.
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>

            <div class="modal-body tw-p-4 tw-space-y-4">
                {{-- Area Upload File & Download Template --}}
                <div class="tw-grid tw-gap-4 md:tw-grid-cols-[minmax(0,1.5fr)_minmax(14rem,1fr)] tw-items-start">
                    <div class="tw-space-y-2">
                        <x-ui.file-upload
                            name="import_file"
                            id="localGrImportFile"
                            label="File Spreadsheet Goods Receipt (GR) ERP (.xlsx)"
                            helper="Format XLSX maksimal 10 MB. Kolom formula dilarang; Kuantitas (Qty) wajib berupa angka positif."
                            accept=".xlsx"
                        />
                    </div>

                    <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container tw-p-3.5 tw-space-y-3">
                        <div class="tw-flex tw-items-center tw-justify-between">
                            <span class="tw-text-ui-xs tw-font-bold tw-text-on-surface">Template Goods Receipt (GR) ERP</span>
                            <x-ui.button :href="route($routePrefix.'.import.gr.template')" variant="outline" size="sm">
                                <x-ui.icon name="download" size="sm" />
                                <span>Unduh Template</span>
                            </x-ui.button>
                        </div>
                        <div class="tw-text-[11px] tw-text-on-surface-variant tw-leading-relaxed">
                            <p class="tw-mb-1.5 tw-font-semibold tw-text-on-surface">Ketentuan Agregasi & Pemetaan ERP Infor Goods Receipt (GR):</p>
                            <ul class="tw-list-disc tw-ps-3.5 tw-space-y-1 tw-mb-0">
                                <li><strong>Kolom J:</strong> Nomor GR (<code>Receipt</code>).</li>
                                <li><strong>Kolom L:</strong> Nomor PO Referensi (<code>Order Line</code>) — wajib cocok tepat dengan PO terdaftar.</li>
                                <li><strong>Kolom P:</strong> Kuantitas Diterima (<code>Received Quantity</code>).</li>
                                <li><strong>Kolom T:</strong> Tanggal Penerimaan (<code>Actual Receipt Date</code>).</li>
                                <li><strong>Agregasi Otomatis:</strong> Baris item ganda untuk GR dan PO yang sama akan disatukan menjadi 1 GR dengan total Qty = SUM(Qty).</li>
                                <li>Tidak memerlukan nominal/amount rupiah dari file ERP.</li>
                            </ul>
                        </div>
                    </div>
                </div>

                {{-- Panel Hasil Validasi / Preview --}}
                <div id="localGrImportResult" class="d-none tw-space-y-4">
                    {{-- KPI Summary Pills --}}
                    <div class="tw-grid tw-grid-cols-2 sm:tw-grid-cols-6 tw-gap-3">
                        <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container tw-p-3">
                            <div class="tw-text-[11px] tw-text-on-surface-variant tw-font-medium">Baris Sumber ERP</div>
                            <div class="tw-text-title-md tw-font-bold tw-text-on-surface" id="kpiGrSourceRows">0</div>
                        </div>
                        <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container tw-p-3">
                            <div class="tw-text-[11px] tw-text-on-surface-variant tw-font-medium">GR Konsolidasi</div>
                            <div class="tw-text-title-md tw-font-bold tw-text-primary" id="kpiGrConsolidated">0</div>
                        </div>
                        <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container tw-p-3">
                            <div class="tw-text-[11px] tw-text-on-surface-variant tw-font-medium">GR Baru</div>
                            <div class="tw-text-title-md tw-font-bold tw-text-success" id="kpiGrNew">0</div>
                        </div>
                        <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container tw-p-3">
                            <div class="tw-text-[11px] tw-text-on-surface-variant tw-font-medium">GR Terdaftar (Skip)</div>
                            <div class="tw-text-title-md tw-font-bold tw-text-on-surface" id="kpiGrExisting">0</div>
                        </div>
                        <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container tw-p-3">
                            <div class="tw-text-[11px] tw-text-on-surface-variant tw-font-medium">PO Tidak Cocok</div>
                            <div class="tw-text-title-md tw-font-bold tw-text-warning" id="kpiGrUnmatchedPo">0</div>
                        </div>
                        <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container tw-p-3">
                            <div class="tw-text-[11px] tw-text-on-surface-variant tw-font-medium">Baris Error</div>
                            <div class="tw-text-title-md tw-font-bold tw-text-error" id="kpiGrInvalidRows">0</div>
                        </div>
                    </div>

                    {{-- Panel Error Terstruktur --}}
                    <div id="localGrImportErrorsPanel" class="d-none tw-rounded-ui-md tw-border-s-4 tw-border-error tw-bg-error-container tw-p-3.5 tw-text-ui-xs tw-text-error-container-foreground" role="alert">
                        <div class="fw-bold mb-1.5 d-flex align-items-center tw-gap-1.5 tw-text-ui-sm">
                            <x-ui.icon name="circle-x" size="sm" />
                            <span>Ditemukan Kesalahan Validasi Spreadsheet GR</span>
                        </div>
                        <p class="tw-mb-1.5 tw-text-[11px]">
                            Import tidak dapat dikonfirmasi sebelum seluruh kesalahan berikut diperbaiki pada file spreadsheet:
                        </p>
                        <ul id="localGrImportErrorsList" class="tw-mb-0 tw-ps-4 tw-space-y-0.5"></ul>
                    </div>

                    {{-- Tabel Pratinjau Parsed Data --}}
                    <div id="localGrImportPreviewPanel" class="d-none tw-space-y-2">
                        <div class="tw-flex tw-items-center tw-justify-between">
                            <div class="tw-font-bold tw-text-on-surface tw-text-ui-sm">
                                Pratinjau GR Terkonsolidasi (Consolidated Records)
                            </div>
                            <span class="tw-text-[11px] tw-text-on-surface-variant" id="localGrImportRowCount"></span>
                        </div>
                        <div class="local-po-import-preview-scroll table-responsive tw-border tw-border-outline-variant tw-rounded-ui-md">
                            <table class="table table-sm table-striped table-hover align-middle mb-0 tw-text-ui-xs">
                                <thead class="table-light sticky-top">
                                    <tr>
                                        <th scope="col" style="width: 45px;" class="text-center">Baris</th>
                                        <th scope="col">Nomor GR</th>
                                        <th scope="col">Nomor PO Terkait</th>
                                        <th scope="col">Deskripsi</th>
                                        <th scope="col" class="text-center">Tgl Penerimaan</th>
                                        <th scope="col" class="text-end">Total Qty (Pcs)</th>
                                        <th scope="col" class="text-center">Jml Baris ERP</th>
                                        <th scope="col" class="text-center">Status / Aksi</th>
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
                    Batal
                </x-ui.button>
                <x-ui.button type="button" variant="outline" size="sm" id="btnParseLocalGrImport">
                    <span class="spinner-border spinner-border-sm me-1 d-none" id="localGrImportSpinner"></span>
                    <span>Validasi & Tinjau Data</span>
                </x-ui.button>
                <form id="localGrImportConfirmForm" method="POST" action="{{ route($routePrefix.'.import.gr.confirm') }}" class="d-inline">
                    @csrf
                    <input type="hidden" name="token" id="localGrImportToken" value="">
                    <x-ui.button type="submit" size="sm" variant="primary" id="btnConfirmLocalGrImport" disabled>
                        <x-ui.icon name="circle-check" size="sm" class="me-1" />
                        <span>Konfirmasi Import GR</span>
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
            return div.innerHTML;
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
                    AdasiToast.error('Pilih file spreadsheet GR (.xlsx) terlebih dahulu.');
                } else {
                    alert('Pilih file spreadsheet GR (.xlsx) terlebih dahulu.');
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
                    const msgs = body.errors ? Object.values(body.errors).flat() : [body.message || 'Terjadi kesalahan validasi.'];
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
                    AdasiToast.error('Gagal menghubungi server: ' + err.message);
                } else {
                    alert('Gagal menghubungi server: ' + err.message);
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
                    li.textContent = `Baris ${e.row || '-'} [${e.column || '-'}]: ${e.message}`;
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
                rowCountLabel.textContent = `${rows.length} GR terkonsolidasi (${preview.source_row_count || 0} baris sumber)`;
                previewBody.innerHTML = '';

                rows.slice(0, 100).forEach(r => {
                    const tr = document.createElement('tr');
                    let badge = '<span class="badge bg-secondary">UNKNOWN</span>';
                    if (r.action === 'NEW') badge = '<span class="badge bg-success">GR BARU</span>';
                    else if (r.action === 'EXISTING') badge = '<span class="badge bg-info text-white">TERDAFTAR</span>';
                    else if (r.action === 'UNMATCHED_PO') badge = '<span class="badge bg-danger">PO TIDAK COCOK</span>';
                    else if (r.action === 'PO_CLOSED') badge = '<span class="badge bg-warning text-dark">PO CLOSED</span>';
                    else if (r.action === 'CONFLICT') badge = '<span class="badge bg-danger">KONFLIK</span>';

                    tr.innerHTML = `
                        <td class="text-center font-monospace">${r._row || '-'}</td>
                        <td class="fw-semibold font-monospace">${escapeHtml(r.gr_number || '-')}</td>
                        <td class="font-monospace">${escapeHtml(r.po_number || '-')}</td>
                        <td class="tw-max-w-[200px] tw-truncate" title="${escapeHtml(r.description || '')}">${escapeHtml(r.description || '-')}</td>
                        <td class="text-center font-monospace">${r.gr_date || '-'}</td>
                        <td class="text-end font-monospace">${formatQty(r.qty)}</td>
                        <td class="text-center font-monospace"><span class="badge bg-light text-dark border">${r.source_rows_count || 1} baris</span></td>
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
