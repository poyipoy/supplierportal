<div class="modal fade" id="localPoGrImportModal" tabindex="-1" aria-labelledby="localPoGrImportModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header tw-border-b tw-border-outline-variant">
                <div>
                    <h6 class="modal-title fw-bold tw-text-on-surface" id="localPoGrImportModalLabel">
                        Import Master PO & GR dari Spreadsheet
                    </h6>
                    <div class="tw-text-on-surface-variant tw-text-ui-xs tw-mt-0.5">
                        Validasi file Excel (.xlsx) secara atomic sebelum data PO dan GR tersimpan ke sistem.
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
                            id="localImportFile"
                            label="File Spreadsheet PO & GR (.xlsx)"
                            helper="Format XLSX maksimal 10 MB. Kolom formula dilarang; seluruh nominal berupa angka desimal positif."
                            accept=".xlsx"
                        />
                    </div>

                    <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container tw-p-3.5 tw-space-y-3">
                        <div class="tw-flex tw-items-center tw-justify-between">
                            <span class="tw-text-ui-xs tw-font-bold tw-text-on-surface">Template Resmi</span>
                            <x-ui.button :href="route($routePrefix.'.import.template')" variant="outline" size="sm">
                                <x-ui.icon name="download" size="xs" />
                                <span>Unduh Template</span>
                            </x-ui.button>
                        </div>
                        <div class="tw-text-[11px] tw-text-on-surface-variant tw-leading-relaxed">
                            <p class="tw-mb-1.5 tw-font-semibold tw-text-on-surface">Ketentuan Format Workbook:</p>
                            <ul class="tw-list-disc tw-ps-3.5 tw-space-y-1 tw-mb-0">
                                <li><strong>PO Wajib:</strong> <code>po_number</code>, <code>supplier_name</code>, <code>po_date</code>, <code>po_amount</code>.</li>
                                <li><strong>GR (Opsional):</strong> <code>gr_number</code>, <code>gr_date</code>, <code>gr_amount</code> harus diisi lengkap bersamaan.</li>
                                <li>PO yang sudah tercatat di sistem tidak akan ditimpa (bersifat aman).</li>
                            </ul>
                        </div>
                    </div>
                </div>

                {{-- Panel Hasil Validasi / Preview --}}
                <div id="localImportResult" class="d-none tw-space-y-4">
                    {{-- KPI Summary Pills --}}
                    <div class="tw-grid tw-grid-cols-2 sm:tw-grid-cols-5 tw-gap-3">
                        <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container tw-p-3">
                            <div class="tw-text-[11px] tw-text-on-surface-variant tw-font-medium">Total Baris</div>
                            <div class="tw-text-title-md tw-font-bold tw-text-on-surface" id="kpiTotalRows">0</div>
                        </div>
                        <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container tw-p-3">
                            <div class="tw-text-[11px] tw-text-on-surface-variant tw-font-medium">PO Baru</div>
                            <div class="tw-text-title-md tw-font-bold tw-text-primary" id="kpiNewPo">0</div>
                        </div>
                        <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container tw-p-3">
                            <div class="tw-text-[11px] tw-text-on-surface-variant tw-font-medium">PO Terdaftar</div>
                            <div class="tw-text-title-md tw-font-bold tw-text-on-surface" id="kpiExistingPo">0</div>
                        </div>
                        <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container tw-p-3">
                            <div class="tw-text-[11px] tw-text-on-surface-variant tw-font-medium">GR Baru</div>
                            <div class="tw-text-title-md tw-font-bold tw-text-success" id="kpiNewGr">0</div>
                        </div>
                        <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container tw-p-3">
                            <div class="tw-text-[11px] tw-text-on-surface-variant tw-font-medium">Baris Gagal / Error</div>
                            <div class="tw-text-title-md tw-font-bold tw-text-error" id="kpiInvalidRows">0</div>
                        </div>
                    </div>

                    {{-- Panel Error Terstruktur --}}
                    <div id="localImportErrorsPanel" class="d-none tw-rounded-ui-md tw-border-s-4 tw-border-error tw-bg-error-container tw-p-3.5 tw-text-ui-xs tw-text-error-container-foreground" role="alert">
                        <div class="fw-bold mb-1.5 d-flex align-items-center tw-gap-1.5 tw-text-ui-sm">
                            <x-ui.icon name="circle-x" size="sm" />
                            <span>Ditemukan Kesalahan Validasi Spreadsheet</span>
                        </div>
                        <p class="tw-mb-1.5 tw-text-[11px]">
                            Import tidak dapat dikonfirmasi sebelum seluruh kesalahan berikut diperbaiki pada file spreadsheet:
                        </p>
                        <ul id="localImportErrorsList" class="tw-mb-0 tw-ps-4 tw-space-y-0.5"></ul>
                    </div>

                    {{-- Tabel Pratinjau Parsed Data --}}
                    <div id="localImportPreviewPanel" class="d-none tw-space-y-2">
                        <div class="tw-flex tw-items-center tw-justify-between">
                            <div class="tw-font-bold tw-text-on-surface tw-text-ui-sm">
                                Pratinjau Baris Data Spreadsheet (Parsed Rows)
                            </div>
                            <span class="tw-text-[11px] tw-text-on-surface-variant" id="localImportRowCount"></span>
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
                                        <th scope="col">Nomor GR</th>
                                        <th scope="col" class="text-center">Tgl GR</th>
                                        <th scope="col" class="text-end">Nilai GR (IDR)</th>
                                        <th scope="col">Keterangan</th>
                                    </tr>
                                </thead>
                                <tbody id="localImportPreviewBody"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer tw-bg-surface-container-lowest tw-border-t tw-border-outline-variant">
                <x-ui.button type="button" variant="ghost" size="sm" data-bs-dismiss="modal">
                    Batal
                </x-ui.button>
                <x-ui.button type="button" variant="outline" size="sm" id="btnParseLocalImport">
                    <span class="spinner-border spinner-border-sm me-1 d-none" id="localImportSpinner"></span>
                    <span>Validasi & Tinjau Data</span>
                </x-ui.button>
                <form id="localImportConfirmForm" method="POST" action="{{ route($routePrefix.'.import.confirm') }}" class="d-inline">
                    @csrf
                    <input type="hidden" name="token" id="localImportToken" value="">
                    <x-ui.button type="submit" size="sm" variant="primary" id="btnConfirmLocalImport" disabled>
                        <x-ui.icon name="circle-check" size="sm" class="me-1" />
                        <span>Konfirmasi Import Data</span>
                    </x-ui.button>
                </form>
            </div>
        </div>
    </div>
</div>

@once
    @push('styles')
        <style>
            .local-po-import-preview-scroll {
                max-height: 22rem;
                overflow-y: auto;
            }
        </style>
    @endpush
@endonce

@push('scripts')
<script>
    (function() {
        let localImportRequestInFlight = false;
        const localImportPreviewUrl = @json(route($routePrefix.'.import.preview'));

        function formatRupiahPreview(val) {
            if (!val || val === '0.00' || val === '0') return '-';
            const num = parseFloat(val);
            if (isNaN(num)) return val;
            return 'Rp ' + Math.round(num).toLocaleString('id-ID');
        }

        function setLocalImportBusy(isBusy) {
            localImportRequestInFlight = isBusy;
            $('#btnParseLocalImport').prop('disabled', isBusy);
            $('#localImportFile').prop('disabled', isBusy);
            $('#localImportSpinner').toggleClass('d-none', !isBusy);
        }

        function renderLocalImportResult(payload, token) {
            const preview = payload.preview || payload;
            const summary = preview.summary || { total: 0, new_po: 0, existing_po: 0, new_gr: 0, invalid: 0 };
            const errors = Array.isArray(preview.errors) ? preview.errors : [];
            const rows = Array.isArray(preview.rows) ? preview.rows : [];
            const isSuccess = Boolean(preview.success);

            $('#localImportResult').removeClass('d-none');

            // Update KPI summary
            $('#kpiTotalRows').text(summary.total || rows.length || 0);
            $('#kpiNewPo').text(summary.new_po || 0);
            $('#kpiExistingPo').text(summary.existing_po || 0);
            $('#kpiNewGr').text(summary.new_gr || 0);
            $('#kpiInvalidRows').text(summary.invalid || errors.length || 0);

            // Render Errors Panel
            const $errorsList = $('#localImportErrorsList').empty();
            if (errors.length > 0) {
                errors.forEach(function(err) {
                    const loc = err.row ? `Baris ${err.row}${err.column ? ` (${err.column})` : ''}` : (err.column || 'File');
                    $('<li>').text(`${loc}: ${err.message}`).appendTo($errorsList);
                });
                $('#localImportErrorsPanel').removeClass('d-none');
            } else {
                $('#localImportErrorsPanel').addClass('d-none');
            }

            // Render Parsed Rows Table
            const $previewBody = $('#localImportPreviewBody').empty();
            if (rows.length > 0) {
                rows.forEach(function(row, idx) {
                    const $tr = $('<tr>');
                    [
                        row._row ?? (idx + 1),
                        row.po_number ?? '-',
                        row.supplier_name ?? '-',
                        row.po_date ?? '-',
                        formatRupiahPreview(row.po_amount),
                        row.gr_number ?? '-',
                        row.gr_date ?? '-',
                        formatRupiahPreview(row.gr_amount),
                        row.po_remarks || row.gr_remarks || '-'
                    ].forEach(function(cellVal, cIdx) {
                        const $td = $('<td>').text(cellVal);
                        if (cIdx === 0 || cIdx === 3 || cIdx === 6) {
                            $td.addClass('text-center');
                        } else if (cIdx === 4 || cIdx === 7) {
                            $td.addClass('text-end tw-font-mono');
                        }
                        $td.appendTo($tr);
                    });
                    $tr.appendTo($previewBody);
                });
                $('#localImportRowCount').text(`${rows.length} baris data ditemukan`);
                $('#localImportPreviewPanel').removeClass('d-none');
            } else {
                $('#localImportPreviewPanel').addClass('d-none');
            }

            // Update Confirmation Button State
            if (isSuccess && token && rows.length > 0) {
                $('#localImportToken').val(token);
                $('#btnConfirmLocalImport').prop('disabled', false);
            } else {
                $('#localImportToken').val('');
                $('#btnConfirmLocalImport').prop('disabled', true);
            }
        }

        function parseLocalImport() {
            if (localImportRequestInFlight) return;

            const fileInput = document.getElementById('localImportFile');
            const file = fileInput ? fileInput.files[0] : null;
            if (!file) {
                if (fileInput) {
                    fileInput.setCustomValidity('Silakan pilih file spreadsheet (.xlsx) terlebih dahulu.');
                    fileInput.reportValidity();
                }
                return;
            }
            if (fileInput) fileInput.setCustomValidity('');

            const formData = new FormData();
            formData.append('_token', @json(csrf_token()));
            formData.append('import_file', file);

            $('#btnConfirmLocalImport').prop('disabled', true);
            setLocalImportBusy(true);

            $.ajax({
                url: localImportPreviewUrl,
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }).done(function(response) {
                renderLocalImportResult(response, response.token);
            }).fail(function(xhr) {
                let errorData = xhr.responseJSON;
                if (!errorData || !errorData.preview) {
                    const rawErrors = errorData && errorData.errors ? errorData.errors : {};
                    const errList = [];
                    Object.keys(rawErrors).forEach(function(key) {
                        const msgs = Array.isArray(rawErrors[key]) ? rawErrors[key] : [rawErrors[key]];
                        msgs.forEach(function(msg) {
                            errList.push({ row: null, column: key, message: msg });
                        });
                    });
                    if (errList.length === 0) {
                        errList.push({ row: null, column: 'file', message: 'File spreadsheet tidak dapat diproses atau format tidak sesuai.' });
                    }
                    errorData = {
                        preview: {
                            success: false,
                            rows: [],
                            errors: errList,
                            summary: { total: 0, new_po: 0, existing_po: 0, new_gr: 0, invalid: errList.length }
                        }
                    };
                }
                renderLocalImportResult(errorData, null);
            }).always(function() {
                setLocalImportBusy(false);
            });
        }

        $(document).ready(function() {
            $('#btnParseLocalImport').on('click', parseLocalImport);

            $('#localPoGrImportModal').on('hidden.bs.modal', function() {
                if (localImportRequestInFlight) return;
                const fileInput = document.getElementById('localImportFile');
                if (fileInput) fileInput.value = '';
                $('#localImportResult').addClass('d-none');
                $('#localImportErrorsPanel').addClass('d-none');
                $('#localImportPreviewPanel').addClass('d-none');
                $('#localImportErrorsList, #localImportPreviewBody').empty();
                $('#btnConfirmLocalImport').prop('disabled', true);
                $('#localImportToken').val('');
            });
        });
    })();
</script>
@endpush
