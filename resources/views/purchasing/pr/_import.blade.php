<div class="modal fade" id="prImportModal" tabindex="-1" aria-labelledby="prImportModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h6 class="modal-title fw-bold" id="prImportModalLabel">Import PR Materials from Spreadsheet</h6>
                    <div class="tw-text-on-surface-variant tw-text-ui-xs tw-mt-0.5">Validate your Excel spreadsheet (.xlsx, .xls, .csv) before inserting into the requisition.</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body tw-p-3.5">
                <div class="tw-grid tw-gap-4 md:tw-grid-cols-[minmax(0,1.4fr)_minmax(14rem,1fr)]">
                    <x-ui.file-upload
                        name="import_file"
                        id="prImportFile"
                        label="Spreadsheet File"
                        helper="XLSX, XLS, or CSV format; maximum 10 MB and up to 1,000 rows."
                        accept=".xlsx,.xls,.csv"
                    />
                    <x-ui.select name="import_mode" id="prImportMode" label="Import Mode">
                        <option value="replace" selected>Replace Current Rows</option>
                        <option value="append">Append to Current Rows</option>
                    </x-ui.select>
                </div>

                <div id="prImportResult" class="d-none mt-4">
                    <div id="prImportSummary" class="tw-mb-3 tw-rounded-ui-sm tw-border tw-border-outline tw-bg-surface-container tw-px-3 tw-py-2 tw-text-ui-xs tw-font-semibold tw-text-on-surface" role="status"></div>

                    <div id="prImportWarningsPanel" class="d-none tw-rounded-ui-sm tw-border-s-4 tw-border-warning tw-bg-warning-container tw-px-3 tw-py-2.5 tw-text-ui-xs tw-text-warning-container-foreground" role="status">
                        <div class="fw-bold mb-1 d-flex align-items-center tw-gap-1.5"><x-ui.icon name="triangle-alert" size="sm" /> Warnings</div>
                        <ul id="prImportWarnings" class="mb-0 ps-3"></ul>
                    </div>

                    <div id="prImportErrorsPanel" class="d-none tw-rounded-ui-sm tw-border-s-4 tw-border-error tw-bg-error-container tw-px-3 tw-py-2.5 tw-text-ui-xs tw-text-error-container-foreground" role="alert">
                        <div class="fw-bold mb-1 d-flex align-items-center tw-gap-1.5"><x-ui.icon name="circle-x" size="sm" /> Import Errors</div>
                        <ul id="prImportErrors" class="mb-0 ps-3"></ul>
                    </div>

                    <div id="prImportPreviewPanel" class="d-none">
                        <div class="fw-bold tw-text-on-surface tw-text-ui-sm mb-2">Parsed Materials Preview</div>
                        <div class="pr-import-preview table-responsive border rounded">
                            <table class="table table-sm table-striped table-hover align-middle mb-0 tw-text-ui-xs">
                                <thead class="table-light sticky-top">
                                    <tr>
                                        <th scope="col" style="width: 40px;" class="text-center">Row</th>
                                        <th scope="col">Material</th>
                                        <th scope="col">HS Code Result</th>
                                        <th scope="col" class="text-center">Shape</th>
                                        <th scope="col" class="text-center">Qty</th>
                                        <th scope="col" class="text-end">KG/Unit</th>
                                        <th scope="col">Remark</th>
                                    </tr>
                                </thead>
                                <tbody id="prImportPreviewBody"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer tw-bg-surface-low border-top">
                <x-ui.button type="button" variant="ghost" size="sm" data-bs-dismiss="modal">Cancel</x-ui.button>
                <x-ui.button type="button" variant="outline" size="sm" id="btnParsePrImport">
                    <span class="spinner-border spinner-border-sm me-1 d-none" id="prImportSpinner"></span>
                    Parse &amp; Validate
                </x-ui.button>
                <x-ui.button type="button" size="sm" id="btnApplyPrImport" disabled>
                    <x-ui.icon name="circle-check" size="sm" class="me-1" /> Apply to Form
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

        // Render badge and format values directly without firing scheduleMaterialPreview()
        const isManual = String(data.manual_hs_code) === '1';
        const hsStatus = data.hs_code_resolution_status || 'insufficient_data';
        const labelMap = {
            matched: 'Auto matched',
            ambiguous: 'Ambiguous',
            no_rule: 'No rule',
            unmapped_material: 'Unmapped material',
            insufficient_data: 'Needs more data'
        };

        $row.find('.hs-code-display').val(data.hs_code ?? '');
        $row.find('.hs-status-badge')
            .removeClass('ui-status-chip--success ui-status-chip--warning ui-status-chip--error ui-status-chip--neutral')
            .addClass(isManual ? 'ui-status-chip--warning' : (hsStatus === 'matched' ? 'ui-status-chip--success' : 'ui-status-chip--neutral'))
            .text(isManual ? 'Manual selection' : (labelMap[hsStatus] || hsStatus || 'Needs more data'));

        const weight = Number(data.weight_needed || 0);
        $row.find('.weight-unit-display').val(weight.toFixed(4));

        itemIndex++;

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

        const fileInput = document.getElementById('prImportFile');
        const file = fileInput.files[0];
        if (!file) {
            fileInput.setCustomValidity('Select an XLSX, XLS, or CSV file before continuing.');
            fileInput.reportValidity();
            return;
        }
        fileInput.setCustomValidity('');

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
