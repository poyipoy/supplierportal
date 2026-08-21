<div class="modal fade" id="prImportModal" tabindex="-1" aria-labelledby="prImportModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title" id="prImportModalLabel">Import PR Materials from Excel</h5>
                    <div class="small text-muted">The spreadsheet is validated first and will not save data automatically.</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="tw-grid tw-gap-4 md:tw-grid-cols-[minmax(0,1.4fr)_minmax(14rem,1fr)]">
                    <x-ui.file-upload
                        name="import_file"
                        id="prImportFile"
                        label="Spreadsheet file"
                        helper="XLSX, XLS, or CSV; maximum 10 MB and 1,000 data rows."
                        accept=".xlsx,.xls,.csv"
                    />
                    <x-ui.select name="import_mode" id="prImportMode" label="Import mode">
                            <option value="replace" selected>Replace Current Rows</option>
                            <option value="append">Append to Current Rows</option>
                    </x-ui.select>
                </div>

                <div id="prImportResult" class="d-none mt-4">
                    <div id="prImportSummary" class="alert alert-light border py-2 mb-3"></div>

                    <div id="prImportWarningsPanel" class="alert alert-warning d-none">
                        <div class="fw-semibold mb-1"><x-ui.icon name="triangle-alert" class="me-1" />Warnings</div>
                        <ul id="prImportWarnings" class="mb-0 small ps-3"></ul>
                    </div>

                    <div id="prImportErrorsPanel" class="alert alert-danger d-none">
                        <div class="fw-semibold mb-1"><x-ui.icon name="x-circle" class="me-1" />Import Errors</div>
                        <ul id="prImportErrors" class="mb-0 small ps-3"></ul>
                    </div>

                    <div id="prImportPreviewPanel" class="d-none">
                        <div class="fw-semibold mb-2">Parsed Row Preview</div>
                        <div class="pr-import-preview table-responsive border rounded">
                            <table class="table table-sm table-striped align-middle mb-0">
                                <thead class="table-light sticky-top">
                                    <tr>
                                        <th>Row</th>
                                        <th>Material</th>
                                        <th>HS Result</th>
                                        <th>Shape</th>
                                        <th>Qty</th>
                                        <th>KG/Unit (Auto)</th>
                                        <th>Remark</th>
                                    </tr>
                                </thead>
                                <tbody id="prImportPreviewBody"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <x-ui.button type="button" variant="ghost" data-bs-dismiss="modal">Cancel</x-ui.button>
                <x-ui.button type="button" variant="secondary" id="btnParsePrImport">
                    <span class="spinner-border spinner-border-sm me-1 d-none" id="prImportSpinner"></span>
                    Parse &amp; Validate
                </x-ui.button>
                <x-ui.button type="button" id="btnApplyPrImport" disabled>
                    <x-ui.icon name="circle-check" class="me-1" /> Apply to Form
                </x-ui.button>
            </div>
        </div>
    </div>
</div>

@once
    @push('styles')
        <style>
            .pr-import-preview {
                max-height: 20.625rem;
            }
        </style>
    @endpush
@endonce

@push('scripts')
<script>
    let prImportRows = [];
    let prImportRequestInFlight = false;
    const prImportPreviewUrl = @json(route('purchasing.requisitions.import-preview'));

    function prRowHasMeaningfulData(row) {
        const fields = [
            'material_master_id', 'material_name', 'shape', 'thickness', 'd_inner',
            'd_outer', 'width', 'length', 'manual_hs_code', 'remark'
        ];

        return fields.some((field) => {
            const value = $(row).find(`[name$="[${field}]"]`).val();
            return value !== undefined && String(value).trim() !== '';
        });
    }

    function appendImportedPrRow(data) {
        const rowIndex = itemIndex;
        const template = document.getElementById('rowTemplate').innerHTML;
        const $row = $(template.replace(/{INDEX}/g, rowIndex));
        $('#itemsBody').append($row);

        const values = {
            material_master_id: data.material_master_id ?? '',
            material_name: data.material_name ?? '',
            hs_code: data.hs_code ?? '',
            shape: data.shape ?? '',
            quantity: data.quantity ?? 1,
            thickness: data.thickness ?? '',
            d_inner: data.d_inner ?? '',
            d_outer: data.d_outer ?? '',
            width: data.width ?? '',
            length: data.length ?? '',
            weight_needed: data.weight_needed ?? '',
            manual_hs_code: data.manual_hs_code ?? '',
            remark: data.remark ?? ''
        };

        Object.entries(values).forEach(([field, value]) => {
            $row.find(`[name="items[${rowIndex}][${field}]"]`).val(value);
        });

        applyMaterialShapeRules($row, true);
        $row.data('selected-material-name', data.material_name ?? '');
        scheduleMaterialPreview($row, 0);
        itemIndex++;
        $row.find('input, select, textarea').first().trigger('input');

        return $row;
    }

    function formatPrImportMessage(entry) {
        const location = entry.row
            ? `Row ${entry.row}${entry.column ? `, ${entry.column}` : ''}`
            : (entry.column || 'File');

        return `${location}: ${entry.message}`;
    }

    function renderPrImportResult(payload) {
        const summary = payload.summary || { total: 0, valid: 0, invalid: 0 };
        const warnings = Array.isArray(payload.warnings) ? payload.warnings : [];
        const errors = Array.isArray(payload.errors) ? payload.errors : [];
        const rows = Array.isArray(payload.rows) ? payload.rows : [];

        $('#prImportResult').removeClass('d-none');
        $('#prImportSummary').text(
            `Total: ${summary.total || 0} | Valid: ${summary.valid || 0} | Invalid: ${summary.invalid || 0}`
        );

        const $warnings = $('#prImportWarnings').empty();
        warnings.forEach((warning) => $('<li>').text(formatPrImportMessage(warning)).appendTo($warnings));
        $('#prImportWarningsPanel').toggleClass('d-none', warnings.length === 0);

        const $errors = $('#prImportErrors').empty();
        errors.forEach((error) => $('<li>').text(formatPrImportMessage(error)).appendTo($errors));
        $('#prImportErrorsPanel').toggleClass('d-none', errors.length === 0);

        const $preview = $('#prImportPreviewBody').empty();
        rows.forEach((row, index) => {
            const $tableRow = $('<tr>');
            [
                index + 1,
                row.material_name ?? '-',
                `${row.hs_code ?? '-'} (${row.hs_code_resolution_status ?? 'unresolved'})`,
                row.shape ?? '-',
                row.quantity ?? '-',
                row.weight_needed ?? '-',
                row.remark ?? '-'
            ].forEach((value) => $('<td>').text(value).appendTo($tableRow));
            $tableRow.appendTo($preview);
        });
        $('#prImportPreviewPanel').toggleClass('d-none', rows.length === 0);

        prImportRows = payload.success === true ? rows : [];
        $('#btnApplyPrImport').prop('disabled', payload.success !== true || rows.length === 0);
    }

    function setPrImportBusy(isBusy) {
        prImportRequestInFlight = isBusy;
        $('#btnParsePrImport').prop('disabled', isBusy);
        $('#prImportFile, #prImportMode').prop('disabled', isBusy);
        $('#prImportSpinner').toggleClass('d-none', !isBusy);
    }

    function parsePrImport() {
        if (prImportRequestInFlight) {
            return;
        }

        const file = document.getElementById('prImportFile').files[0];
        if (!file) {
            AdasiAlert.warning({
                title: 'File Required',
                text: 'Select an XLSX, XLS, or CSV file first.'
            });
            return;
        }

        const formData = new FormData();
        formData.append('_token', @json(csrf_token()));
        formData.append('import_file', file);
        prImportRows = [];
        $('#btnApplyPrImport').prop('disabled', true);
        setPrImportBusy(true);

        $.ajax({
            url: prImportPreviewUrl,
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        }).done((payload) => {
            renderPrImportResult(payload);
        }).fail((xhr) => {
            renderPrImportResult(xhr.responseJSON || {
                success: false,
                rows: [],
                warnings: [],
                summary: { total: 0, valid: 0, invalid: 0 },
                errors: [{ row: null, column: 'import_file', message: 'The spreadsheet could not be processed.' }]
            });
        }).always(() => {
            setPrImportBusy(false);
        });
    }

    function performPrImportApply(mode) {
        if (mode === 'replace') {
            $('#itemsBody').empty();
        } else {
            $('#itemsBody tr.item-row').each(function() {
                if (!prRowHasMeaningfulData(this)) {
                    $(this).remove();
                }
            });
        }

        prImportRows.forEach(appendImportedPrRow);
        checkRowCount();
        bootstrap.Modal.getOrCreateInstance(document.getElementById('prImportModal')).hide();
        AdasiToast.show({
            type: 'success',
            title: 'Import Applied',
            message: `${prImportRows.length} material row(s) were added to the form. Review them before saving.`,
            autoClose: 2200
        });
    }

    function applyPrImport() {
        if (prImportRows.length === 0) {
            return;
        }

        const mode = $('#prImportMode').val();
        const hasCurrentData = $('#itemsBody tr.item-row').toArray().some(prRowHasMeaningfulData);

        if (mode === 'replace' && hasCurrentData) {
            AdasiAlert.confirmDanger({
                title: 'Replace current material rows?',
                text: 'Material values currently entered in the form will be replaced by the validated import.',
                confirmText: 'Yes, Replace Rows',
                cancelText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    performPrImportApply(mode);
                }
            });
            return;
        }

        performPrImportApply(mode);
    }

    $(document).ready(function() {
        $('#btnParsePrImport').on('click', parsePrImport);
        $('#btnApplyPrImport').on('click', applyPrImport);
        $('#prImportModal').on('hidden.bs.modal', function() {
            if (prImportRequestInFlight) {
                return;
            }

            document.getElementById('prImportFile').value = '';
            $('#prImportMode').val('replace');
            $('#prImportResult').addClass('d-none');
            $('#prImportWarnings, #prImportErrors, #prImportPreviewBody').empty();
            $('#btnApplyPrImport').prop('disabled', true);
            prImportRows = [];
        });
    });
</script>
@endpush
