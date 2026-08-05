@extends('layouts.app')

@section('title', 'Create Purchase Requisition - ADASI Portal')
@section('page-title', 'Create New Purchase Requisition')

@section('content')
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3">
        <h5 class="mb-0 fw-semibold">Purchase Requisition Form</h5>
    </div>
    <div class="card-body">
        <form id="prForm" action="{{ route('purchasing.requisitions.store') }}" method="POST">
            @csrf
            <input type="hidden" name="return_url" value="{{ request('return_url') }}">
            
            <input type="hidden" name="action" id="formAction" value="draft">

            <input type="hidden" name="supplier_selection_present" value="1">

            <div class="row mb-4">
                <div class="col-md-4">
                    <label for="period_id" class="form-label fw-medium">Quotation Period<span class="text-danger">*</span></label>
                    <select name="period_id" id="period_id" class="form-select @error('period_id') is-invalid @enderror" required>
                        <option value="">-- Select Period --</option>
                        @foreach($periods as $period)
                            <option value="{{ $period->id }}" {{ old('period_id') == $period->id ? 'selected' : '' }}>
                                {{ $period->name }} ({{ str_pad($period->month, 2, '0', STR_PAD_LEFT) }}/{{ $period->year }})
                            </option>
                        @endforeach
                    </select>
                    @error('period_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-4">
                    @php
                        $selectedSupplierIds = collect(old('supplier_ids', []));
                        if (old('supplier_id')) {
                            $selectedSupplierIds->push(old('supplier_id'));
                        }
                    @endphp
                    @include('purchasing.pr._supplier_picker_modal', [
                        'modalId' => 'createSupplierPickerModal',
                        'suppliers' => $suppliers,
                        'selectedSupplierIds' => $selectedSupplierIds,
                    ])
                    {{--
                    <div class="form-text">Select one supplier, or leave “All Registered Suppliers” so the PR can be viewed by all suppliers.</div>
                    --}}
                </div>
                <div class="col-md-4">
                    <label for="notes" class="form-label fw-medium">Additional Notes / Remarks</label>
                    <textarea name="notes" id="notes" class="form-control" rows="2" placeholder="Optional...">{{ old('notes') }}</textarea>
                </div>
            </div>

            <hr>

            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="fw-bold mb-0">Required Material List</h6>
                <div class="d-flex flex-wrap gap-2 justify-content-end">
                    <a href="{{ route('purchasing.requisitions.import-template') }}" class="btn btn-sm btn-outline-success">
                        <i class="bi bi-file-earmark-arrow-down me-1"></i> Download Template
                    </a>
                    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#prImportModal">
                        <i class="bi bi-file-earmark-spreadsheet me-1"></i> Import Excel
                    </button>
                    <button type="button" class="btn btn-sm btn-success" id="btnAddRow">
                        <i class="bi bi-plus"></i> Add Material
                    </button>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-bordered table-sm align-middle" id="itemsTable">
                    <thead class="table-light text-center" style="font-size: 0.8rem;">
                        <tr>
                            <th width="28%">Material & HS Code <span class="text-danger">*</span></th>
                            <th width="12%">Shape</th>
                            <th width="8%">Qty <span class="text-danger">*</span></th>
                            <th width="26%">Dimensions (mm)</th>
                            <th width="10%">Weight/Unit (Kg) <span class="text-danger">*</span></th>
                            <th width="10%">Remark</th>
                            <th width="6%">Action</th>
                        </tr>
                    </thead>
                    <tbody id="itemsBody">
                        {{-- Initially empty, row will be added by JS.
                             If there are old input (validation error), render them. --}}
                        @if(old('items'))
                            @foreach(old('items') as $index => $item)
                                @include('purchasing.pr._item_row', ['index' => $index, 'item' => $item])
                            @endforeach
                        @endif
                    </tbody>
                </table>
                @error('items') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                <div id="noItemAlert" class="text-danger small mt-1 d-none">At least 1 material is required.</div>
            </div>

            <div class="mt-4 d-flex gap-2">
                <a href="{{ \App\Support\PurchasingNavigation::backUrl('purchasing.requisitions.index') }}" class="btn btn-light">Cancel</a>
                <button type="button" class="btn btn-secondary" onclick="submitForm('draft')">Save Draft</button>
                <button type="button" class="btn btn-primary" style="background-color: var(--adasi-blue);" onclick="confirmSubmit()">Submit Now</button>
            </div>
        </form>
    </div>
</div>

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
                <div class="row g-3">
                    <div class="col-md-7">
                        <label for="prImportFile" class="form-label fw-medium">Spreadsheet File</label>
                        <input type="file" id="prImportFile" class="form-control" accept=".xlsx,.xls,.csv">
                        <div class="form-text">XLSX, XLS, or CSV; maximum 10 MB and 1,000 data rows.</div>
                    </div>
                    <div class="col-md-5">
                        <label for="prImportMode" class="form-label fw-medium">Import Mode</label>
                        <select id="prImportMode" class="form-select">
                            <option value="replace" selected>Replace Current Rows</option>
                            <option value="append">Append to Current Rows</option>
                        </select>
                    </div>
                </div>

                <div id="prImportResult" class="d-none mt-4">
                    <div id="prImportSummary" class="alert alert-light border py-2 mb-3"></div>

                    <div id="prImportWarningsPanel" class="alert alert-warning d-none">
                        <div class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle me-1"></i>Warnings</div>
                        <ul id="prImportWarnings" class="mb-0 small ps-3"></ul>
                    </div>

                    <div id="prImportErrorsPanel" class="alert alert-danger d-none">
                        <div class="fw-semibold mb-1"><i class="bi bi-x-circle me-1"></i>Import Errors</div>
                        <ul id="prImportErrors" class="mb-0 small ps-3"></ul>
                    </div>

                    <div id="prImportPreviewPanel" class="d-none">
                        <div class="fw-semibold mb-2">Parsed Row Preview</div>
                        <div class="table-responsive border rounded" style="max-height: 330px;">
                            <table class="table table-sm table-striped align-middle mb-0">
                                <thead class="table-light sticky-top">
                                    <tr>
                                        <th>Row</th>
                                        <th>Material</th>
                                        <th>HS Code</th>
                                        <th>Shape</th>
                                        <th>Qty</th>
                                        <th>Weight/Unit</th>
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
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-outline-primary" id="btnParsePrImport">
                    <span class="spinner-border spinner-border-sm me-1 d-none" id="prImportSpinner"></span>
                    Parse &amp; Validate
                </button>
                <button type="button" class="btn btn-primary" id="btnApplyPrImport" disabled>
                    <i class="bi bi-check2-circle me-1"></i> Apply to Form
                </button>
            </div>
        </div>
    </div>
</div>

{{-- Template for new row --}}
<template id="rowTemplate">
    @include('purchasing.pr._item_row', ['index' => '{INDEX}', 'item' => null])
</template>

@endsection

@push('scripts')
<script>
    let itemIndex = {{ old('items') ? count(old('items')) : 0 }};
    let prImportRows = [];
    let prImportRequestInFlight = false;
    const prImportPreviewUrl = @json(route('purchasing.requisitions.import-preview'));

    @include('purchasing.pr._material_shape_script')

    function addRow() {
        const template = document.getElementById('rowTemplate').innerHTML;
        const html = template.replace(/{INDEX}/g, itemIndex);
        $('#itemsBody').append(html);
        applyMaterialShapeRules($('#itemsBody tr.item-row').last(), true);
        itemIndex++;
        checkRowCount();
    }

    function removeRow(btn) {
        AdasiAlert.confirmDanger({
            title: 'Delete this row?',
            confirmText: 'Yes',
            cancelText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                $(btn).closest('tr').remove();
                checkRowCount();
            }
        });
    }

    function checkRowCount() {
        if ($('#itemsBody tr').length === 0) {
            $('#noItemAlert').removeClass('d-none');
        } else {
            $('#noItemAlert').addClass('d-none');
        }
    }

    function prRowHasMeaningfulData(row) {
        const fields = [
            'material_name', 'hs_code', 'shape', 'thickness', 'd_inner',
            'd_outer', 'width', 'length', 'weight_needed', 'remark'
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
            remark: data.remark ?? ''
        };

        Object.entries(values).forEach(([field, value]) => {
            $row.find(`[name="items[${rowIndex}][${field}]"]`).val(value);
        });

        applyMaterialShapeRules($row, true);
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
                row.hs_code ?? '-',
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
        AdasiAlert.toast({
            type: 'success',
            title: 'Import Applied',
            text: `${prImportRows.length} material row(s) were added to the form. Review them before saving.`,
            duration: 2200
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

    function submitForm(action) {
        if ($('#itemsBody tr').length === 0) {
            $('#noItemAlert').removeClass('d-none');
            AdasiAlert.error({ title: 'Error', text: 'At least 1 material must be added.' });
            return;
        }
        $('#formAction').val(action);
        $('#prForm').submit();
    }

    function confirmSubmit() {
        if ($('#itemsBody tr').length === 0) {
            $('#noItemAlert').removeClass('d-none');
            AdasiAlert.error({ title: 'Error', text: 'At least 1 material must be added.' });
            return;
        }

        AdasiAlert.confirm({
            title: 'Submit Requisition?',
            text: 'Status will change to Submitted and cannot be edited anymore.',
            confirmText: 'Yes, Submit!',
            cancelText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                $('#formAction').val('submitted');
                $('#prForm').submit();
            }
        });
    }

    $(document).ready(function() {
        $('#btnAddRow').click(addRow);
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
        
        // Add one empty row initially if old input doesn't exist
        if ($('#itemsBody tr').length === 0) {
            addRow();
        } else {
            initializeMaterialShapeRows();
        }

        let isDirty = false;
        $('#prForm').on('input change', 'input, select, textarea', function() {
            isDirty = true;
        });
        $('#prForm').on('submit', function() {
            isDirty = false;
        });
        $(window).on('beforeunload', function() {
            if (isDirty) {
                return 'You have unsaved changes. Are you sure you want to leave this page?';
            }
        });
    });
</script>
@endpush
