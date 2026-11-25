<dialog class="ui-native-dialog" id="prImportModal" aria-labelledby="prImportModalLabel" closedby="any">
    <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h6 class="modal-title fw-bold" id="prImportModalLabel">{{ __('purchasing.copy.import_pr_materials_from_spreadsheet') }}</h6>
                    <div class="tw:text-on-surface-variant tw:text-ui-xs tw:mt-0.5">{{ __('purchasing.copy.validate_your_excel_spreadsheet_xlsx_xls_csv_before_inserting_into_the_requisition') }}</div>
                </div>
                <button type="button" class="btn-close" data-ui-modal-close aria-label="{{ __('purchasing.copy.close') }}"></button>
            </div>
            <div class="modal-body tw:p-3.5">
                <div class="tw:grid tw:gap-4 tw:md:grid-cols-[minmax(0,1.4fr)_minmax(14rem,1fr)]">
                    <x-ui.file-upload
                        name="import_file"
                        id="prImportFile"
                        :label="__('purchasing.copy.spreadsheet_file')"
                        :helper="__('purchasing.copy.xlsx_xls_or_csv_format_maximum_10_mb_and_up_to_1_000_rows')"
                        accept=".xlsx,.xls,.csv"
                    />
                    <div>
                        <x-ui.select name="import_mode" id="prImportMode" :label="__('purchasing.copy.import_mode')" aria-describedby="prImportModeHelp">
                            <option value="replace" selected>{{ __('purchasing.copy.replace_current_rows') }}</option>
                            <option value="append">{{ __('purchasing.copy.append_to_current_rows') }}</option>
                        </x-ui.select>
                        <div id="prImportModeHelp" class="form-text tw:text-ui-xs" aria-live="polite"></div>
                    </div>
                </div>

                <div id="prImportResult" class="d-none mt-4">
                    <div id="prImportSummary" class="tw:mb-3 tw:rounded-ui-sm tw:border tw:border-outline tw:bg-surface-container tw:px-3 tw:py-2 tw:text-ui-xs tw:font-semibold tw:text-on-surface" role="status"></div>

                    <div id="prImportWarningsPanel" class="d-none tw:rounded-ui-sm tw:border-s-4 tw:border-warning tw:bg-warning-container tw:px-3 tw:py-2.5 tw:text-ui-xs tw:text-warning-container-foreground" role="status">
                        <div class="fw-bold mb-1 d-flex align-items-center tw:gap-1.5"><x-ui.icon name="triangle-alert" size="sm" /> {{ __('purchasing.copy.warnings') }}</div>
                        <ul id="prImportWarnings" class="mb-0 ps-3"></ul>
                    </div>

                    <div id="prImportErrorsPanel" class="d-none tw:rounded-ui-sm tw:border-s-4 tw:border-error tw:bg-error-container tw:px-3 tw:py-2.5 tw:text-ui-xs tw:text-error-container-foreground" role="alert">
                        <div class="fw-bold mb-1 d-flex align-items-center tw:gap-1.5"><x-ui.icon name="circle-x" size="sm" /> {{ __('purchasing.copy.import_errors') }}</div>
                        <ul id="prImportErrors" class="mb-0 ps-3"></ul>
                    </div>

                    <div id="prImportPreviewPanel" class="d-none">
                        <div class="fw-bold tw:text-on-surface tw:text-ui-sm mb-2">{{ __('purchasing.copy.parsed_materials_preview') }}</div>
                        <div class="pr-import-preview table-responsive border rounded">
                            <table class="table table-sm table-striped table-hover align-middle mb-0 tw:text-ui-xs">
                                <thead class="table-light sticky-top">
                                    <tr>
                                        <th scope="col" style="width: 40px;" class="text-center">{{ __('purchasing.copy.row') }}</th>
                                        <th scope="col">{{ __('purchasing.copy.material') }}</th>
                                        <th scope="col">{{ __('purchasing.copy.hs_code_result') }}</th>
                                        <th scope="col" class="text-center">{{ __('purchasing.copy.shape') }}</th>
                                        @foreach(\App\Models\PrItem::FIXED_DIMENSION_ORDER as $dimensionField)
                                            <th scope="col" class="text-end">{{ \App\Models\PrItem::dimensionLabel($dimensionField) }}</th>
                                        @endforeach
                                        <th scope="col" class="text-center">{{ __('purchasing.copy.qty') }}</th>
                                        <th scope="col" class="text-end">KG/Unit</th>
                                        <th scope="col" class="text-end">{{ __('procurement.totals.total_kg') }}</th>
                                        <th scope="col">{{ __('purchasing.copy.remark') }}</th>
                                    </tr>
                                </thead>
                                <tbody id="prImportPreviewBody"></tbody>
                                <tfoot class="table-light fw-bold">
                                    <tr>
                                        <th scope="row" colspan="9" class="text-end">{{ __('procurement.totals.total') }}</th>
                                        <td class="text-center ui-tabular-nums">
                                            <span class="tw:sr-only">{{ __('procurement.totals.total_qty') }}:</span>
                                            <output id="prImportTotalQty">0</output>
                                        </td>
                                        <td class="text-end tw:text-on-surface-variant">{{ __('procurement.totals.total_kg') }}</td>
                                        <td class="text-end ui-tabular-nums"><output id="prImportTotalKg">0</output></td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer tw:bg-surface-low border-top">
                <x-ui.button type="button" variant="ghost" size="sm" data-ui-modal-close>{{ __('purchasing.copy.cancel') }}</x-ui.button>
                <x-ui.button type="button" variant="outline" size="sm" id="btnParsePrImport">
                    <span class="ui-spinner me-1 d-none" id="prImportSpinner" aria-hidden="true"></span>
                    {{ __('purchasing.copy.parse_validate') }}
                </x-ui.button>
                <x-ui.button type="button" size="sm" id="btnApplyPrImport" disabled>
                    <x-ui.icon name="circle-check" size="sm" class="me-1" /> {{ __('purchasing.copy.apply_to_form') }}
                </x-ui.button>
            </div>
        </div>
    </div>
</dialog>

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
    const prImportCsrfToken = @json(csrf_token());

    const prImportCopy = {
        needsMoreData: @json(__('purchasing.copy.needs_more_data')),
        manualSelection: @json(__('purchasing.copy.manual_selection')),
        selectFileBeforeContinuing: @json(__('purchasing.copy.select_an_xlsx_xls_or_csv_file_before_continuing')),
        spreadsheetProcessingFailed: @json(__('purchasing.copy.the_spreadsheet_could_not_be_processed')),
        importApplied: @json(__('purchasing.copy.import_applied')),
        replaceCurrentRowsTitle: @json(__('purchasing.copy.replace_current_material_rows')),
        replaceCurrentRowsText: @json(__('purchasing.copy.material_values_currently_entered_in_the_form_will_be_replaced_by_the_validated_import')),
        yesReplaceRows: @json(__('purchasing.copy.yes_replace_rows')),
        cancel: @json(__('purchasing.copy.cancel')),
    };

    function prRowHasMeaningfulData(row) {
        const rowEl = row instanceof Element ? row : (row?.[0] instanceof Element ? row[0] : null);
        if (!rowEl) return false;

        const fields = [
            'material_master_id', 'material_name', 'shape', 'thickness', 'd_inner',
            'd_outer', 'width', 'length', 'manual_hs_code', 'remark'
        ];

        return fields.some((field) => {
            const value = rowEl.querySelector(`[name$="[${field}]"]`)?.value;
            return value !== undefined && String(value).trim() !== '';
        });
    }

    function appendImportedPrRow(data) {
        const rowIndex = itemIndex;
        const templateHtml = document.getElementById('rowTemplate').innerHTML.replace(/{INDEX}/g, String(rowIndex));
        const tpl = document.createElement('template');
        tpl.innerHTML = templateHtml.trim();
        const rowEl = tpl.content.firstElementChild;
        if (!rowEl) return null;

        document.getElementById('itemsBody')?.appendChild(rowEl);

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
            const input = rowEl.querySelector(`[name="items[${rowIndex}][${field}]"]`);
            if (input) input.value = value;
        });

        applyMaterialShapeRules(rowEl, true);
        rowEl.dataset.selectedMaterialName = data.material_name ?? '';

        // Render badge and format values directly without firing scheduleMaterialPreview()
        const isManual = String(data.manual_hs_code) === '1';
        const hsStatus = data.hs_code_resolution_status || 'insufficient_data';
        const labelMap = {
            matched: @json(__('purchasing.copy.auto_matched')),
            ambiguous: @json(__('purchasing.copy.ambiguous')),
            no_rule: @json(__('purchasing.copy.no_rule')),
            unmapped_material: @json(__('purchasing.copy.unmapped_material')),
            insufficient_data: @json(__('purchasing.copy.needs_more_data'))
        };

        const hsCodeInput = rowEl.querySelector('.hs-code-display');
        if (hsCodeInput) hsCodeInput.value = data.hs_code ?? '';

        const hsBadge = rowEl.querySelector('.hs-status-badge');
        if (hsBadge) {
            hsBadge.classList.remove(
                'ui-status-chip--success',
                'ui-status-chip--warning',
                'ui-status-chip--error',
                'ui-status-chip--neutral'
            );
            hsBadge.classList.add(
                isManual
                    ? 'ui-status-chip--warning'
                    : (hsStatus === 'matched' ? 'ui-status-chip--success' : 'ui-status-chip--neutral')
            );
            hsBadge.textContent = isManual
                ? prImportCopy.manualSelection
                : (labelMap[hsStatus] || hsStatus || prImportCopy.needsMoreData);
        }

        const gradeLabel = data.grade_label ?? '';
        const gradeInput = rowEl.querySelector('.material-grade-display');
        if (gradeInput) {
            gradeInput.value = gradeLabel;
            gradeInput.setAttribute('title', gradeLabel);
        }

        const weight = Number(data.weight_needed || 0);
        const weightInput = rowEl.querySelector('.weight-unit-display');
        if (weightInput) weightInput.value = weight.toFixed(4);

        if (data.remark) {
            const triggerText = rowEl.querySelector('.pr-remark-trigger__text');
            const trigger = rowEl.querySelector('.pr-remark-trigger');
            const draftInput = rowEl.querySelector('.pr-remark-draft');
            if (triggerText) triggerText.textContent = data.remark;
            if (trigger) {
                trigger.classList.add('has-remark');
                trigger.setAttribute('title', data.remark);
                if (!trigger.querySelector('.pr-remark-trigger__badge')) {
                    const remarkBadge = document.createElement('span');
                    remarkBadge.className = 'pr-remark-trigger__badge';
                    remarkBadge.title = @json(__('purchasing.copy.remark_entered'));
                    remarkBadge.setAttribute('aria-hidden', 'true');
                    trigger.appendChild(remarkBadge);
                }
            }
            if (draftInput) draftInput.value = data.remark;
        }
        if (data.material_name) {
            const remarkMatName = rowEl.querySelector('.pr-remark-material-name');
            if (remarkMatName) remarkMatName.textContent = data.material_name;
        }

        itemIndex++;

        return rowEl;
    }

    function formatPrImportMessage(entry) {
        const location = entry.row
            ? (entry.column
                ? window.AdasiI18n.t('js.import.row_column', { row: entry.row, column: entry.column })
                : window.AdasiI18n.t('js.import.row', { row: entry.row }))
            : (entry.column || window.AdasiI18n.t('js.import.file'));

        return `${location}: ${entry.message}`;
    }

    function renderPrImportResult(payload) {
        const summary = payload.summary || { total: 0, valid: 0, invalid: 0 };
        const warnings = Array.isArray(payload.warnings) ? payload.warnings : [];
        const errors = Array.isArray(payload.errors) ? payload.errors : [];
        const rows = Array.isArray(payload.rows) ? payload.rows : [];

        document.getElementById('prImportResult')?.classList.remove('d-none');
        const summaryEl = document.getElementById('prImportSummary');
        if (summaryEl) {
            summaryEl.textContent = window.AdasiI18n.t('js.import.summary', {
                total: summary.total || 0,
                valid: summary.valid || 0,
                invalid: summary.invalid || 0,
            });
        }

        const warningsEl = document.getElementById('prImportWarnings');
        if (warningsEl) {
            warningsEl.replaceChildren();
            warnings.forEach((warning) => {
                const li = document.createElement('li');
                li.textContent = formatPrImportMessage(warning);
                warningsEl.appendChild(li);
            });
        }
        document.getElementById('prImportWarningsPanel')?.classList.toggle('d-none', warnings.length === 0);

        const errorsEl = document.getElementById('prImportErrors');
        if (errorsEl) {
            errorsEl.replaceChildren();
            errors.forEach((error) => {
                const li = document.createElement('li');
                li.textContent = formatPrImportMessage(error);
                errorsEl.appendChild(li);
            });
        }
        document.getElementById('prImportErrorsPanel')?.classList.toggle('d-none', errors.length === 0);

        const previewEl = document.getElementById('prImportPreviewBody');
        const kgFormat = new Intl.NumberFormat(document.documentElement.lang || undefined, { maximumFractionDigits: 4 });
        const kg = (value) => value === null || value === undefined || value === '' ? '-' : kgFormat.format(Number(value));
        // PUR-01: every PR dimension in its own column; a dimension the shape does not use is null → '-'.
        const dimensionOrder = @json(\App\Models\PrItem::FIXED_DIMENSION_ORDER);
        if (previewEl) {
            previewEl.replaceChildren();
            rows.forEach((row, index) => {
                const tableRow = document.createElement('tr');
                [
                    index + 1,
                    row.material_name ?? '-',
                    `${row.hs_code ?? '-'} (${row.hs_code_resolution_status ?? 'unresolved'})`,
                    row.shape ?? '-',
                    ...dimensionOrder.map((field) => row[field] ?? '-'),
                    row.quantity ?? '-',
                    kg(row.weight_needed),
                    kg(row.total_weight),
                    row.remark ?? '-'
                ].forEach((value, column) => {
                    const td = document.createElement('td');
                    td.textContent = value;
                    if (column === 0 || column === 3 || column === 9) {
                        td.classList.add('text-center');
                    }
                    if (column === 9) {
                        td.classList.add('ui-tabular-nums');
                    }
                    if ((column >= 4 && column <= 8) || column === 10 || column === 11) {
                        td.classList.add('text-end', 'ui-tabular-nums');
                    }
                    tableRow.appendChild(td);
                });
                previewEl.appendChild(tableRow);
            });
        }

        const totalQtyEl = document.getElementById('prImportTotalQty');
        if (totalQtyEl) totalQtyEl.textContent = String(summary.total_quantity ?? 0);

        const totalKgEl = document.getElementById('prImportTotalKg');
        if (totalKgEl) totalKgEl.textContent = kg(summary.total_weight_kg ?? 0);

        document.getElementById('prImportPreviewPanel')?.classList.toggle('d-none', rows.length === 0);

        prImportRows = payload.success === true ? rows : [];
        const applyBtn = document.getElementById('btnApplyPrImport');
        if (applyBtn) applyBtn.disabled = payload.success !== true || rows.length === 0;
    }

    function setPrImportBusy(isBusy) {
        prImportRequestInFlight = isBusy;
        const parseBtn = document.getElementById('btnParsePrImport');
        const fileEl = document.getElementById('prImportFile');
        const modeEl = document.getElementById('prImportMode');
        if (parseBtn) parseBtn.disabled = isBusy;
        if (fileEl) fileEl.disabled = isBusy;
        if (modeEl) modeEl.disabled = isBusy;
        document.getElementById('prImportSpinner')?.classList.toggle('d-none', !isBusy);
    }

    async function parsePrImport() {
        if (prImportRequestInFlight) {
            return;
        }

        const fileInput = document.getElementById('prImportFile');
        const file = fileInput?.files[0];
        if (!file) {
            fileInput?.setCustomValidity(prImportCopy.selectFileBeforeContinuing);
            fileInput?.reportValidity();
            return;
        }
        fileInput.setCustomValidity('');

        const formData = new FormData();
        formData.append('_token', prImportCsrfToken);
        formData.append('import_file', file);
        prImportRows = [];
        const applyBtn = document.getElementById('btnApplyPrImport');
        if (applyBtn) applyBtn.disabled = true;
        setPrImportBusy(true);

        const fallbackErrorPayload = {
            success: false,
            rows: [],
            warnings: [],
            summary: { total: 0, valid: 0, invalid: 0 },
            errors: [{ row: null, column: 'import_file', message: prImportCopy.spreadsheetProcessingFailed }]
        };

        try {
            const response = await fetch(prImportPreviewUrl, {
                method: 'POST',
                body: formData,
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': prImportCsrfToken,
                },
            });
            const payload = await response.json().catch(() => null);
            renderPrImportResult((response.ok && payload) ? payload : (payload || fallbackErrorPayload));
        } catch (error) {
            renderPrImportResult(fallbackErrorPayload);
        } finally {
            setPrImportBusy(false);
        }
    }

    function performPrImportApply(mode) {
        const itemsBody = document.getElementById('itemsBody');
        if (mode === 'replace') {
            itemsBody?.replaceChildren();
        } else {
            document.querySelectorAll('#itemsBody tr.item-row').forEach((row) => {
                if (!prRowHasMeaningfulData(row)) {
                    row.remove();
                }
            });
        }

        prImportRows.forEach(appendImportedPrRow);
        renumberPrRows();
        checkRowCount();
        window.AdasiModal?.getOrCreateInstance?.(document.getElementById('prImportModal'))?.hide();
        AdasiToast.show({
            type: 'success',
            title: prImportCopy.importApplied,
            message: window.AdasiI18n.choice('purchasing.js.import_added', prImportRows.length),
            autoClose: 2200
        });
    }

    function applyPrImport() {
        if (prImportRows.length === 0) {
            return;
        }

        const mode = document.getElementById('prImportMode')?.value || 'replace';
        const hasCurrentData = Array.from(document.querySelectorAll('#itemsBody tr.item-row')).some(prRowHasMeaningfulData);

        if (mode === 'replace' && hasCurrentData) {
            AdasiAlert.confirmDanger({
                title: prImportCopy.replaceCurrentRowsTitle,
                text: prImportCopy.replaceCurrentRowsText,
                confirmText: prImportCopy.yesReplaceRows,
                cancelText: prImportCopy.cancel
            }).then((result) => {
                if (result.isConfirmed) {
                    performPrImportApply(mode);
                }
            });
            return;
        }

        performPrImportApply(mode);
    }

    function initPrImportControls() {
        const importModeDescriptions = {
            replace: @json(__('purchasing.copy.removes_the_material_rows_currently_shown_in_this_form_and_replaces_them_with_the_validated_rows_fro')),
            append: @json(__('purchasing.copy.keeps_the_material_rows_currently_shown_and_adds_validated_spreadsheet_rows_below_them')),
        };
        const modeSelect = document.getElementById('prImportMode');
        const modeHelp = document.getElementById('prImportModeHelp');
        const updateImportModeHelp = () => {
            if (modeHelp && modeSelect) {
                modeHelp.textContent = importModeDescriptions[modeSelect.value] || '';
            }
        };

        document.getElementById('btnParsePrImport')?.addEventListener('click', parsePrImport);
        document.getElementById('btnApplyPrImport')?.addEventListener('click', applyPrImport);
        modeSelect?.addEventListener('change', updateImportModeHelp);
        updateImportModeHelp();

        document.getElementById('prImportModal')?.addEventListener('adasi:modal-hidden', () => {
            if (prImportRequestInFlight) {
                return;
            }

            const fileInput = document.getElementById('prImportFile');
            if (fileInput) fileInput.value = '';
            if (modeSelect) modeSelect.value = 'replace';
            updateImportModeHelp();
            document.getElementById('prImportResult')?.classList.add('d-none');
            document.getElementById('prImportWarnings')?.replaceChildren();
            document.getElementById('prImportErrors')?.replaceChildren();
            document.getElementById('prImportPreviewBody')?.replaceChildren();
            const applyBtn = document.getElementById('btnApplyPrImport');
            if (applyBtn) applyBtn.disabled = true;
            prImportRows = [];
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPrImportControls);
    } else {
        initPrImportControls();
    }
</script>
@endpush
