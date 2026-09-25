<div class="modal fade" id="localPoImportModal" tabindex="-1" aria-labelledby="localPoImportModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header tw-border-b tw-border-outline-variant">
                <div>
                    <h6 class="modal-title fw-bold tw-text-on-surface" id="localPoImportModalLabel">
                        Import Purchase Order (Infor ERP)
                    </h6>
                    <div class="tw-text-on-surface-variant tw-text-ui-xs tw-mt-0.5">
                        Unggah file ekspor ERP Infor (.xlsx) untuk membuat atau memperbarui referensi PO.
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
                            id="localPoImportFile"
                            label="File Spreadsheet Purchase Order (PO) ERP (.xlsx)"
                            helper="Format XLSX maksimal 10 MB. Kolom formula dilarang; seluruh nominal berupa angka desimal positif."
                            accept=".xlsx"
                        />
                    </div>

                    <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container tw-p-3.5 tw-space-y-3">
                        <div class="tw-flex tw-items-center tw-justify-between">
                            <span class="tw-text-ui-xs tw-font-bold tw-text-on-surface">Template Purchase Order (PO) ERP</span>
                            <x-ui.button :href="route($routePrefix.'.import.po.template')" variant="outline" size="sm">
                                <x-ui.icon name="download" size="sm" />
                                <span>Unduh Template</span>
                            </x-ui.button>
                        </div>
                        <div class="tw-text-[11px] tw-text-on-surface-variant tw-leading-relaxed">
                            <p class="tw-mb-1.5 tw-font-semibold tw-text-on-surface">Pemetaan Kolom ERP Infor Purchase Order (PO):</p>
                            <ul class="tw-list-disc tw-ps-3.5 tw-space-y-1 tw-mb-0">
                                <li><strong>Kolom E:</strong> Nomor PO (<code>Order</code>).</li>
                                <li><strong>Kolom G:</strong> Nama Rekanan Supplier Lokal aktif.</li>
                                <li><strong>Kolom J:</strong> Tanggal PO (<code>Order Date</code>).</li>
                                <li><strong>Kolom K:</strong> Plafon Nominal PO (<code>Order Amount</code>).</li>
                                <li>PO dengan data identik yang sudah ada di sistem akan otomatis dilewati (aman).</li>
                            </ul>
                        </div>
                    </div>
                </div>

                {{-- Panel Hasil Validasi / Preview --}}
                <div id="localPoImportResult" class="d-none tw-space-y-4">
                    {{-- KPI Summary Pills --}}
                    <div class="tw-grid tw-grid-cols-2 sm:tw-grid-cols-5 tw-gap-3">
                        <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container tw-p-3">
                            <div class="tw-text-[11px] tw-text-on-surface-variant tw-font-medium">Total Baris ERP</div>
                            <div class="tw-text-title-md tw-font-bold tw-text-on-surface" id="kpiPoTotalRows">0</div>
                        </div>
                        <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container tw-p-3">
                            <div class="tw-text-[11px] tw-text-on-surface-variant tw-font-medium">PO Baru</div>
                            <div class="tw-text-title-md tw-font-bold tw-text-primary" id="kpiPoNew">0</div>
                        </div>
                        <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container tw-p-3">
                            <div class="tw-text-[11px] tw-text-on-surface-variant tw-font-medium">PO Terdaftar (Skip)</div>
                            <div class="tw-text-title-md tw-font-bold tw-text-on-surface" id="kpiPoExisting">0</div>
                        </div>
                        <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container tw-p-3">
                            <div class="tw-text-[11px] tw-text-on-surface-variant tw-font-medium">Konflik Header</div>
                            <div class="tw-text-title-md tw-font-bold tw-text-warning" id="kpiPoConflict">0</div>
                        </div>
                        <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container tw-p-3">
                            <div class="tw-text-[11px] tw-text-on-surface-variant tw-font-medium">Baris Error</div>
                            <div class="tw-text-title-md tw-font-bold tw-text-error" id="kpiPoInvalidRows">0</div>
                        </div>
                    </div>

                    {{-- Panel Error Terstruktur --}}
                    <div id="localPoImportErrorsPanel" class="d-none tw-rounded-ui-md tw-border-s-4 tw-border-error tw-bg-error-container tw-p-3.5 tw-text-ui-xs tw-text-error-container-foreground" role="alert">
                        <div class="fw-bold mb-1.5 d-flex align-items-center tw-gap-1.5 tw-text-ui-sm">
                            <x-ui.icon name="circle-x" size="sm" />
                            <span>Ditemukan Kesalahan Validasi Spreadsheet PO</span>
                        </div>
                        <p class="tw-mb-1.5 tw-text-[11px]">
                            Import tidak dapat dikonfirmasi sebelum seluruh kesalahan berikut diperbaiki pada file spreadsheet:
                        </p>
                        <ul id="localPoImportErrorsList" class="tw-mb-0 tw-ps-4 tw-space-y-0.5"></ul>
                    </div>

                    {{-- Tabel Pratinjau Parsed Data --}}
                    <div id="localPoImportPreviewPanel" class="d-none tw-space-y-2">
                        <div class="tw-flex tw-items-center tw-justify-between">
                            <div class="tw-font-bold tw-text-on-surface tw-text-ui-sm">
                                Pratinjau Baris PO (Parsed Rows)
                            </div>
                            <span class="tw-text-[11px] tw-text-on-surface-variant" id="localPoImportRowCount"></span>
                        </div>
                        <div class="local-po-import-preview-scroll table-responsive tw-border tw-border-outline-variant tw-rounded-ui-md">
                            <table class="table table-sm table-striped table-hover align-middle mb-0 tw-text-ui-xs">
                                <thead class="table-light sticky-top">
                                    <tr>
                                        <th scope="col" style="width: 45px;" class="text-center">Baris</th>
                                        <th scope="col">Nomor PO</th>
                                        <th scope="col">Rekanan Supplier</th>
                                        <th scope="col" class="text-center">Tgl PO</th>
                                        <th scope="col" class="text-end">Plafon PO (IDR)</th>
                                        <th scope="col" class="text-center">Status / Aksi</th>
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
                    Batal
                </x-ui.button>
                <x-ui.button type="button" variant="outline" size="sm" id="btnParseLocalPoImport">
                    <span class="spinner-border spinner-border-sm me-1 d-none" id="localPoImportSpinner"></span>
                    <span>Validasi & Tinjau Data</span>
                </x-ui.button>
                <form id="localPoImportConfirmForm" method="POST" action="{{ route($routePrefix.'.import.po.confirm') }}" class="d-inline">
                    @csrf
                    <input type="hidden" name="token" id="localPoImportToken" value="">
                    <x-ui.button type="submit" size="sm" variant="primary" id="btnConfirmLocalPoImport" disabled>
                        <x-ui.icon name="circle-check" size="sm" class="me-1" />
                        <span>Konfirmasi Import PO</span>
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
                    AdasiToast.error('Pilih file spreadsheet PO (.xlsx) terlebih dahulu.');
                } else {
                    alert('Pilih file spreadsheet PO (.xlsx) terlebih dahulu.');
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
                rowCountLabel.textContent = `${rows.length} baris ditampilkan`;
                previewBody.innerHTML = '';

                rows.slice(0, 100).forEach(r => {
                    const tr = document.createElement('tr');
                    let badge = '<span class="badge bg-secondary">UNKNOWN</span>';
                    if (r.action === 'NEW') badge = '<span class="badge bg-primary">PO BARU</span>';
                    else if (r.action === 'EXISTING') badge = '<span class="badge bg-info text-dark">TERDAFTAR</span>';
                    else if (r.action === 'CONFLICT') badge = '<span class="badge bg-danger">KONFLIK</span>';

                    tr.innerHTML = `
                        <td class="text-center font-monospace">${r._row || '-'}</td>
                        <td class="fw-semibold font-monospace">${r.po_number || '-'}</td>
                        <td>${r.supplier_name || '-'}</td>
                        <td class="text-center font-monospace">${r.po_date || '-'}</td>
                        <td class="text-end font-monospace">${formatRupiah(r.po_amount)}</td>
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
