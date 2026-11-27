import { createColumnDesigner } from './export-column-designer.js';
import { yieldToMain } from './task-yield.js';

const controllers = new WeakMap();
const formatForms = new WeakSet();

// Network, filters, presets and dispatch stay here. The designer alone owns
// column configuration; every export and preset save reads its serializer.
export function createAdvancedExport(root, { designer: suppliedDesigner } = {}) {
    if (controllers.has(root)) return controllers.get(root);
    const document = root.ownerDocument;
    const window = document.defaultView;
    const modal = root.querySelector('dialog.ui-native-dialog, .ui-export-designer-modal');
    const form = root.querySelector('[data-advanced-export-form]');
    const submit = root.querySelector('[data-export-submit]');
    const quick = root.querySelector('[data-export-quick]');
    const status = root.querySelector('[data-export-status]');
    const error = root.querySelector('[data-export-error]');
    const select = root.querySelector('[data-export-preset]');
    const retry = root.querySelector('[data-export-retry]');
    const copy = JSON.parse(root.dataset.exportCopy);
    const selectors = JSON.parse(root.dataset.filterSelectors);
    const text = (key, params = {}) => Object.entries(params).reduce((s, [k, v]) => s.replaceAll(`:${k}`, String(v)), copy[key] || key);
    const saveButton = root.querySelector('[data-export-save]');
    const updateButton = root.querySelector('[data-export-update]');
    const duplicateButton = root.querySelector('[data-export-duplicate]');
    const deleteButton = root.querySelector('[data-export-delete]');
    const formatSelect = root.querySelector('[data-export-format]');
    const delimiterGroup = root.querySelector('[data-export-csv-delimiter-group]');
    const delimiterSelect = root.querySelector('[data-export-csv-delimiter]');
    const scopeGroup = root.querySelector('[data-export-preset-scope-group]');
    const scopeSelect = root.querySelector('[data-export-preset-scope]');
    const defaultCheckbox = root.querySelector('[data-export-default]');
    const teamReadonlyHint = root.querySelector('[data-export-team-readonly]');
    const previewEl = root.querySelector('[data-export-preview]');
    const relativeCheckbox = root.querySelector('[data-export-relative]');
    const relativePanel = root.querySelector('[data-export-relative-panel]');
    const daysInput = root.querySelector('[data-export-days]');
    const dayPresetButtons = [...root.querySelectorAll('[data-export-days-preset]')];
    const defaultCsvDelimiter = root.dataset.defaultCsvDelimiter === ';' || document.documentElement?.lang === 'id' ? ';' : ',';
    const buttons = [submit, quick, saveButton, updateButton, duplicateButton, deleteButton].filter(Boolean);
    const listeners = [];
    let definition;
    let presets = [];
    let personalPresets = [];
    let teamPresets = [];
    let canManageTeam = false;
    let epoch = 0;
    let pending = null;
    let exportFlight = null;
    let ready = false;
    let shown = false;
    let opening = false;
    let closing = false;
    let disposed = false;
    let preserveOnShow = false;
    let previewTimer = null;
    let previewAbort = null;
    let previewEpoch = 0;
    let previewState = null;
    form?.setAttribute?.('data-managed-submit', '');
    form?.setAttribute?.('data-no-loader', '');
    let opener = root.querySelector('[data-export-open]');
    const renderPreview = () => {
        if (!previewEl) return;
        const baseClass = 'ui-export-preview tw:mr-auto tw:text-ui-xs ui-tabular-nums';
        if (!ready || !previewState) {
            previewEl.textContent = '';
            previewEl.className = `${baseClass} tw:text-on-surface-variant`;
            return;
        }
        const fmt = String(formatSelect?.value || 'xlsx').toUpperCase();
        let columnCount = 0;
        try { columnCount = designer.serialize().length; } catch { columnCount = 0; }
        const numberLocale = document.documentElement?.lang === 'id' ? 'id-ID' : 'en-US';
        const fmtNumber = (value) => new Intl.NumberFormat(numberLocale).format(Math.max(0, Number(value) || 0));
        if (previewState.loading) {
            previewEl.className = `${baseClass} tw:text-on-surface-variant`;
            previewEl.textContent = text('preview_loading');
            return;
        }
        if (previewState.failed) {
            previewEl.className = `${baseClass} tw:text-on-surface-variant`;
            previewEl.textContent = text('preview_failed');
            return;
        }
        if (previewState.exceedsLimit) {
            previewEl.className = `${baseClass} tw:text-error tw:font-semibold`;
            previewEl.textContent = text('preview_exceeds', { rows: fmtNumber(previewState.totalRows), limit: fmtNumber(previewState.maxRows) });
            return;
        }
        if (previewState.isEmpty) {
            previewEl.className = `${baseClass} tw:text-warning tw:font-semibold`;
            previewEl.textContent = text('preview_empty', { columns: fmtNumber(columnCount), format: fmt });
            return;
        }
        previewEl.className = `${baseClass} tw:text-on-surface-variant`;
        previewEl.textContent = text('preview_summary', { rows: fmtNumber(previewState.totalRows), columns: fmtNumber(columnCount), format: fmt });
    };
    const designer = suppliedDesigner || createColumnDesigner(root, { text, onStateChange: () => renderPreview() });
    const listen = (target, name, handler) => {
        if (!target) return;
        target.addEventListener(name, handler);
        listeners.push(() => target.removeEventListener(name, handler));
    };
    const chosenPreset = () => presets.find((preset) => preset.id === select.value);
    const canEditPreset = (preset) => preset ? (preset.can_edit !== undefined ? Boolean(preset.can_edit) : preset.scope !== 'team') : false;
    const canDeletePreset = (preset) => preset ? (preset.can_delete !== undefined ? Boolean(preset.can_delete) : preset.scope !== 'team') : false;
    const syncCsvDelimiter = () => {
        const isCsv = formatSelect?.value === 'csv';
        if (delimiterGroup) delimiterGroup.hidden = !isCsv;
        if (delimiterSelect) delimiterSelect.disabled = !(ready && shown && !pending && !exportFlight && !disposed) || !isCsv;
    };
    const updateControls = () => {
        const available = ready && shown && !pending && !exportFlight && !disposed;
        const selected = chosenPreset();
        const editable = canEditPreset(selected);
        const deletable = canDeletePreset(selected);
        submit.disabled = !available || Boolean(previewState?.exceedsLimit);
        saveButton.disabled = !available;
        updateButton.disabled = !available || !selected || !editable;
        deleteButton.disabled = !available || !selected || !deletable;
        if (duplicateButton) {
            duplicateButton.hidden = !selected;
            duplicateButton.disabled = !available || !selected;
        }
        if (scopeGroup) scopeGroup.hidden = !canManageTeam;
        if (scopeSelect) scopeSelect.disabled = !available || !canManageTeam;
        if (defaultCheckbox) {
            const isTeamSelection = Boolean(scopeSelect && canManageTeam && scopeSelect.value === 'team');
            defaultCheckbox.disabled = !available || isTeamSelection;
            if (isTeamSelection) defaultCheckbox.checked = false;
        }
        if (teamReadonlyHint) {
            teamReadonlyHint.hidden = !(selected && selected.scope === 'team' && !editable);
        }
        select.disabled = !available;
        quick.disabled = Boolean(pending || exportFlight || disposed);
        if (retry) retry.disabled = Boolean(pending || exportFlight || disposed);
        syncCsvDelimiter();
        designer.setEnabled(available);
    };
    const clearButtonSpinners = (button) => {
        if (!button) return;
        button.querySelector?.('[data-export-busy-icon]')?.remove();
        button.querySelectorAll?.('.ui-spinner').forEach((node) => node.remove());
        button.classList?.remove?.('is-loading');
        if (button.dataset) delete button.dataset.loadingActive;
        button.querySelector?.('span:not([data-export-busy-icon])')?.style?.removeProperty('display');
    };
    const busy = (button, active) => {
        if (!button) return;
        if (button.dataset?.loadingActive === 'true') {
            window?.AdasiButton?.stopLoading?.(button);
        }
        clearButtonSpinners(button);
        if (active) {
            button.setAttribute('aria-busy', 'true');
            const icon = document.createElement('span');
            icon.className = 'ui-spinner';
            icon.dataset.exportBusyIcon = '';
            icon.setAttribute('aria-hidden', 'true');
            if (typeof button.prepend === 'function') button.prepend(icon);
            else button.append(icon);
        } else {
            button.removeAttribute('aria-busy');
        }
    };
    const clearError = () => { error.hidden = true; error.textContent = ''; if (retry) retry.hidden = true; };
    const isModalOpen = () => Boolean(modal.open || modal.hasAttribute?.('open'));
    const showError = (message, reload = false) => {
        error.textContent = message; error.hidden = false;
        if (reload) status.textContent = '';
        if (retry) retry.hidden = !reload;
        if (isModalOpen()) error.focus();
    };
    const cancelPreview = () => {
        if (previewTimer !== null) {
            window.clearTimeout(previewTimer);
            previewTimer = null;
        }
        previewAbort?.abort();
        previewAbort = null;
        previewEpoch += 1;
    };
    const invalidate = () => {
        epoch += 1;
        pending?.abort.abort();
        pending = null;
        cancelPreview();
        if (form?.dataset?.submitting) delete form.dataset.submitting;
        buttons.forEach((button) => busy(button, false));
    };
    const begin = (kind, button) => {
        invalidate();
        clearError();
        const operation = { epoch, kind, button, abort: new (window.AbortController || AbortController)() };
        pending = operation;
        if (button) busy(button, true);
        updateControls();
        return operation;
    };
    const current = (operation) => !disposed && operation.epoch === epoch && pending === operation;
    const finish = (operation) => {
        if (!current(operation)) return;
        if (form?.dataset?.submitting) delete form.dataset.submitting;
        if (operation.button) busy(operation.button, false);
        pending = null;
        updateControls();
    };
    const json = async (url, method = 'GET', body, operation) => {
        const response = await window.fetch(url, {
            method, credentials: 'same-origin', cache: 'no-store',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': form.querySelector('[name="_token"]').value, ...(body ? { 'Content-Type': 'application/json' } : {}) },
            ...(body ? { body: JSON.stringify(body) } : {}),
            // UI cancellation aborts reads only. A sent POST may have committed.
            ...(method === 'GET' && operation ? { signal: operation.abort.signal } : {}),
        });
        if (response.status === 204) return null;
        let data;
        try { data = await response.json(); } catch { throw new Error(text('failed')); }
        if (response.status >= 500) throw new Error(text('failed'));
        if (!response.ok) throw new Error(Object.values(data.errors || {}).flat().join('\n') || data.message || text('failed'));
        return data;
    };
    const currentFilters = () => {
        const searchParams = new URL(window.location.href).searchParams;
        const urlValues = {};
        for (const [key, value] of searchParams.entries()) {
            if (key.endsWith('[]')) {
                const cleanKey = key.slice(0, -2);
                if (!Array.isArray(urlValues[cleanKey])) urlValues[cleanKey] = [];
                if (value !== '') urlValues[cleanKey].push(value);
            } else {
                urlValues[key] = value;
            }
        }
        const values = { ...urlValues, ...JSON.parse(root.dataset.initialFilters || '{}') };
        Object.entries(selectors).forEach(([name, selector]) => {
            const input = document.querySelector(selector);
            if (!input) return;
            if (input.multiple) {
                values[name] = Array.from(input.selectedOptions || []).map((option) => option.value).filter((v) => v !== '');
            } else if (input.type === 'checkbox') {
                values[name] = input.checked ? '1' : '0';
            } else {
                values[name] = input.value;
            }
        });
        if (!values.search) {
            const legacySearch = values.po_number || values.pr_number || values.shipment_number;
            if (legacySearch) values.search = legacySearch;
        }
        if (root.dataset.exportTable && window.DataTable?.isDataTable?.(root.dataset.exportTable)) {
            if (!selectors.search && !root.dataset.exportKey.startsWith('qc.')) {
                values.search = new window.DataTable(root.dataset.exportTable, { retrieve: true }).search().trim();
            }
        }
        return values;
    };
    const initial = new URL(window.location.href).searchParams;
    Object.entries(selectors).forEach(([name, selector]) => {
        const input = document.querySelector(selector);
        if (!input || input.multiple || input.type === 'radio') return;
        if (initial.has(name)) {
            if (input.type === 'checkbox') input.checked = initial.get(name) === '1';
            else input.value = initial.get(name);
        }
    });
    const syncRelativePresets = () => {
        const relative = Boolean(relativeCheckbox?.checked);
        const currentDays = String(daysInput?.value ?? '').trim();
        dayPresetButtons.forEach((button) => {
            button.disabled = !relative;
            button.setAttribute('aria-pressed', currentDays !== '' && currentDays === String(button.dataset.exportDaysPreset) ? 'true' : 'false');
        });
    };
    const updateRelative = () => {
        const relative = Boolean(relativeCheckbox?.checked);
        relativeCheckbox?.setAttribute?.('aria-expanded', relative ? 'true' : 'false');
        if (relativePanel) relativePanel.hidden = !relative;
        if (daysInput) daysInput.disabled = !relative;
        syncRelativePresets();
        form.querySelectorAll('[data-filter-name="start_date"], [data-filter-name="end_date"]').forEach((input) => { input.disabled = relative; input.dispatchEvent(new Event('change', { bubbles: true })); });
    };
    const setFilters = (values) => {
        form.querySelectorAll('[data-filter-name]').forEach((input) => {
            const field = definition.filters.find((f) => f.name === input.dataset.filterName);
            const rawVal = values[input.dataset.filterName] ?? field?.default ?? '';
            if (input.multiple) {
                const nextList = Array.isArray(rawVal)
                    ? rawVal.map((v) => String(v)).filter((v) => v !== '')
                    : (rawVal !== '' ? [String(rawVal)] : []);
                Array.from(input.options || []).forEach((opt) => {
                    opt.selected = nextList.includes(String(opt.value));
                });
                if (input.id && typeof window.dispatchEvent === 'function' && typeof CustomEvent === 'function') {
                    window.dispatchEvent(new CustomEvent('set-multi-value', { detail: { id: input.id, values: nextList, silent: true } }));
                }
                input.dispatchEvent(new Event('change', { bubbles: true }));
                return;
            }
            const nextVal = String(rawVal);
            input.value = nextVal;
            if (input.id && typeof window.dispatchEvent === 'function' && typeof CustomEvent === 'function') {
                window.dispatchEvent(new CustomEvent('set-select-value', { detail: { id: input.id, value: nextVal, silent: true } }));
            }
            input.dispatchEvent(new Event('change', { bubbles: true }));
        });
        ['date_from', 'date_to'].forEach((name) => { const input = form.querySelector(`[name="${name}"]`); if (input) { input.value = values[name] || ''; input.dispatchEvent(new Event('change', { bubbles: true })); } });
        if (relativeCheckbox) relativeCheckbox.checked = values.date_range?.mode === 'relative';
        if (daysInput) daysInput.value = values.date_range?.days || 30;
        updateRelative();
    };
    const filters = () => {
        const data = {};
        form.querySelectorAll('[data-filter-name]').forEach((input) => {
            if (input.disabled) return;
            if (input.multiple) {
                const selected = Array.from(input.selectedOptions || []).map((opt) => opt.value).filter((v) => v !== '');
                if (selected.length > 0) data[input.dataset.filterName] = selected;
            } else if (input.value !== '') {
                data[input.dataset.filterName] = input.value;
            }
        });
        ['date_from', 'date_to'].forEach((name) => { const input = form.querySelector(`[name="${name}"]`); if (input?.value) data[name] = input.value; });
        if (relativeCheckbox?.checked) data.date_range = { mode: 'relative', days: Number(daysInput?.value) };
        return data;
    };
    const schedulePreview = (delayMs = 400) => {
        if (!root.dataset.previewUrl || !ready || disposed) return;
        cancelPreview();
        const myEpoch = previewEpoch;
        previewState = { loading: true, totalRows: 0, maxRows: 0, exceedsLimit: false, isEmpty: false, failed: false };
        renderPreview();
        updateControls();
        const run = async () => {
            previewTimer = null;
            if (!ready || disposed || myEpoch !== previewEpoch) return;
            const controller = new (window.AbortController || AbortController)();
            previewAbort = controller;
            try {
                const response = await window.fetch(root.dataset.previewUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    priority: 'low',
                    signal: controller.signal,
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': form.querySelector('[name="_token"]').value,
                    },
                    body: JSON.stringify({ export_key: root.dataset.exportKey, ...filters() }),
                });
                if (!response.ok) throw new Error('Preview failed');
                const data = await response.json();
                if (myEpoch !== previewEpoch || disposed || !ready) return;
                previewState = {
                    loading: false,
                    totalRows: Number(data.total_rows) || 0,
                    maxRows: Number(data.max_rows) || 0,
                    exceedsLimit: Boolean(data.exceeds_limit),
                    isEmpty: Boolean(data.is_empty),
                    failed: false,
                };
            } catch (err) {
                if (err?.name === 'AbortError' || myEpoch !== previewEpoch || disposed || !ready) return;
                previewState = { loading: false, totalRows: 0, maxRows: 0, exceedsLimit: false, isEmpty: false, failed: true };
            } finally {
                if (previewAbort === controller) previewAbort = null;
            }
            renderPreview();
            updateControls();
        };
        previewTimer = window.setTimeout(run, delayMs);
    };
    const renderPresetSelect = () => {
        const option = (label, value) => { const node = document.createElement('option'); node.textContent = label; node.value = value; return node; };
        const defaultOpt = option(text('default_columns'), '');
        if (teamPresets.length > 0) {
            const nodes = [defaultOpt];
            if (personalPresets.length > 0) {
                const personalGroup = document.createElement('optgroup');
                personalGroup.label = text('personal_presets');
                personalPresets.forEach((preset) => personalGroup.append(option(preset.name, preset.id)));
                nodes.push(personalGroup);
            }
            const teamGroup = document.createElement('optgroup');
            teamGroup.label = text('team_presets');
            teamPresets.forEach((preset) => teamGroup.append(option(preset.name, preset.id)));
            nodes.push(teamGroup);
            select.replaceChildren(...nodes);
            return;
        }
        select.replaceChildren(defaultOpt, ...presets.map((preset) => option(preset.name, preset.id)));
    };
    const applyPreset = (preset) => {
        // A presented preset is already normalized by the server. Reject a
        // mismatch instead of silently repairing order/required membership.
        try {
            if (preset) {
                if (!definition.formats.includes(preset.format)) throw new Error('Invalid preset format.');
                designer.setColumns(preset.columns);
            } else designer.reset();
        } catch {
            ready = false;
            throw new Error(text('invalid_columns'));
        }
        setFilters(preset?.filters || currentFilters());
        select.value = preset?.id || '';
        formatSelect.value = preset?.format || 'xlsx';
        if (delimiterSelect) {
            delimiterSelect.value = [',', ';'].includes(preset?.csv_delimiter) ? preset.csv_delimiter : defaultCsvDelimiter;
        }
        if (scopeSelect) {
            scopeSelect.value = preset?.scope === 'team' && canManageTeam ? 'team' : 'personal';
        }
        syncCsvDelimiter();
        root.querySelector('[data-export-preset-name]').value = preset?.name || '';
        if (defaultCheckbox) defaultCheckbox.checked = preset?.scope !== 'team' && Boolean(preset?.is_default);
        if (preset?.warnings?.length) showError(preset.warnings.join('\n'));
    };
    const load = async (operation) => {
        ready = false; previewState = null; renderPreview(); status.textContent = text('loading'); updateControls();
        const [catalog, cohort] = await Promise.all([
            json(root.dataset.definitionUrl, 'GET', undefined, operation),
            json(`${root.dataset.presetsUrl}?export_key=${encodeURIComponent(root.dataset.exportKey)}`, 'GET', undefined, operation),
        ]);
        if (!current(operation)) return false;
        if (catalog.export_key !== root.dataset.exportKey || catalog.supports_columns !== true || !Array.isArray(catalog.filters) || !Array.isArray(catalog.formats) || !catalog.formats.includes('xlsx') || !Array.isArray(cohort.data)) throw new Error(text('failed'));
        await yieldToMain();
        if (!current(operation)) return false;
        try { designer.configure(catalog.columns); }
        catch { throw new Error(text('failed')); }
        definition = catalog;
        presets = cohort.data;
        personalPresets = Array.isArray(cohort.personal) ? cohort.personal : presets.filter((preset) => preset.scope !== 'team');
        teamPresets = Array.isArray(cohort.team) ? cohort.team : presets.filter((preset) => preset.scope === 'team');
        canManageTeam = Boolean(cohort.can_manage_team);
        renderPresetSelect();
        select.value = '';
        applyPreset(null); ready = true; status.textContent = '';
        if (shown && operation.kind !== 'quick') schedulePreview(400);
        return true;
    };
    const revealFailure = () => {
        if (isModalOpen()) return;
        preserveOnShow = true;
        window.AdasiModal?.getOrCreateInstance?.(modal)?.show();
    };
    const dispatch = async (operation) => {
        if (!current(operation) || !ready || exportFlight) return;
        if (!window.AdasiAsyncExport?.startExport) throw new Error(text('failed'));
        const columns = designer.serialize();
        const format = formatSelect.value;
        const exportOptions = { columns, format };
        if (format === 'csv' && delimiterSelect && [',', ';'].includes(delimiterSelect.value)) {
            exportOptions.csv_delimiter = delimiterSelect.value;
        }
        exportFlight = operation; updateControls();
        try {
            const accepted = await window.AdasiAsyncExport.startExport(form, {
                url: form.action,
                body: { ...filters(), options: exportOptions },
                onError: (message) => { if (current(operation)) showError(message); },
            });
            if (!current(operation)) return;
            if (accepted) window.AdasiModal?.getOrCreateInstance?.(modal)?.hide();
            else revealFailure();
        } finally {
            if (exportFlight === operation) exportFlight = null;
            // Recompute from current ownership, never restore a stale disabled flag.
            updateControls();
        }
    };
    const runLoad = async (operation) => {
        try { await load(operation); }
        catch (e) { if (current(operation)) showError(e.message || text('failed'), true); }
        finally { finish(operation); }
    };
    listen(modal, 'adasi:modal-show', (event) => {
        opening = true;
        shown = false;
        if (preserveOnShow) { preserveOnShow = false; return; }
        opener = event.relatedTarget || event.detail?.relatedTarget || root.querySelector('[data-export-open]');
        return runLoad(begin('load'));
    });
    listen(modal, 'adasi:modal-shown', () => {
        opening = false; shown = true; updateControls();
        if (ready && !previewState) schedulePreview(400);
        if (!error.hidden) error.focus();
        else root.querySelector('.modal-title')?.focus();
    });
    listen(modal, 'adasi:modal-hide', () => { opening = false; shown = false; closing = true; invalidate(); ready = false; previewState = null; renderPreview(); designer.suspend(); updateControls(); });
    listen(modal, 'adasi:modal-hidden', () => { closing = false; if (!disposed) opener?.focus(); });
    listen(quick, 'click', async () => {
        if (quick.disabled) return;
        opener = quick;
        const operation = begin('quick', quick);
        try {
            if (!await load(operation) || !current(operation)) return;
            applyPreset(presets.find((preset) => preset.is_default && preset.scope !== 'team'));
            await dispatch(operation);
        } catch (e) { if (current(operation)) { showError(e.message || text('failed'), !ready); revealFailure(); } }
        finally { finish(operation); }
    });
    listen(form, 'submit', async (event) => {
        event.preventDefault();
        if (!ready || pending || exportFlight || previewState?.exceedsLimit || !form.reportValidity()) return;
        const operation = begin('export', submit);
        try { await dispatch(operation); }
        catch (e) { if (current(operation)) showError(e.message || text('failed')); }
        finally { finish(operation); }
    });
    listen(select, 'change', () => {
        if (!ready || pending || exportFlight) return;
        clearError();
        try { applyPreset(chosenPreset()); }
        catch { ready = false; showError(text('invalid_columns'), true); }
        updateControls();
        if (ready) schedulePreview(400);
    });
    listen(relativeCheckbox, 'change', () => { updateRelative(); schedulePreview(400); });
    listen(daysInput, 'input', () => { syncRelativePresets(); schedulePreview(400); });
    dayPresetButtons.forEach((button) => {
        listen(button, 'click', () => {
            if (!daysInput || button.disabled) return;
            daysInput.value = button.dataset.exportDaysPreset;
            syncRelativePresets();
            schedulePreview(400);
        });
    });
    listen(formatSelect, 'change', () => { syncCsvDelimiter(); renderPreview(); });
    listen(scopeSelect, 'change', () => updateControls());
    const handleFilterChange = (event) => {
        if (!ready || pending || exportFlight) return;
        const target = event.target;
        if (!target) return;
        if (target.dataset?.filterName || target.name === 'date_from' || target.name === 'date_to') {
            schedulePreview(400);
        }
    };
    listen(form, 'change', handleFilterChange);
    listen(form, 'input', handleFilterChange);
    listen(retry, 'click', () => runLoad(begin('load')));
    const save = async (update = false) => {
        const selected = chosenPreset();
        if (!ready || pending || exportFlight || (update && (!selected || !canEditPreset(selected)))) return;
        const chosenScope = update
            ? (selected.scope || 'personal')
            : (scopeSelect && canManageTeam ? scopeSelect.value : 'personal');
        const input = {
            export_key: root.dataset.exportKey,
            name: root.querySelector('[data-export-preset-name]').value,
            columns: designer.serialize(),
            filters: filters(),
            format: formatSelect.value,
            ...(delimiterSelect && [',', ';'].includes(delimiterSelect.value) ? { csv_delimiter: delimiterSelect.value } : {}),
            ...(scopeSelect && canManageTeam ? { scope: chosenScope } : {}),
            is_default: chosenScope === 'team' ? false : Boolean(defaultCheckbox?.checked),
        };
        const operation = begin('save', update ? updateButton : saveButton);
        try {
            const result = await json(update ? `${root.dataset.presetsUrl}/${encodeURIComponent(selected.id)}` : root.dataset.presetStoreUrl, update ? 'PUT' : 'POST', input);
            if (!current(operation) || !await load(operation) || !current(operation)) return;
            applyPreset(result); select.value = result.id; status.textContent = text('saved');
        } catch (e) { if (current(operation)) showError(e.message || text('save_failed'), !ready); }
        finally { finish(operation); }
    };
    listen(saveButton, 'click', () => save());
    listen(updateButton, 'click', () => save(true));
    listen(duplicateButton, 'click', async () => {
        const selected = chosenPreset();
        if (!ready || pending || exportFlight || !selected) return;
        const operation = begin('duplicate', duplicateButton);
        try {
            const result = await json(`${root.dataset.presetsUrl}/${encodeURIComponent(selected.id)}/duplicate`, 'POST', {});
            if (!current(operation) || !await load(operation) || !current(operation)) return;
            applyPreset(result); select.value = result.id; status.textContent = text('duplicated');
        } catch (e) { if (current(operation)) showError(e.message || text('save_failed'), !ready); }
        finally { finish(operation); }
    });
    listen(deleteButton, 'click', async () => {
        const selected = chosenPreset();
        if (!ready || pending || exportFlight || !selected || !canDeletePreset(selected)) return;
        const operation = begin('delete', deleteButton);
        try { await json(`${root.dataset.presetsUrl}/${encodeURIComponent(selected.id)}`, 'DELETE'); if (current(operation)) await load(operation); }
        catch (e) { if (current(operation)) showError(e.message || text('save_failed'), !ready); }
        finally { finish(operation); }
    });
    const api = {
        destroy() {
            if (disposed) return;
            disposed = true; invalidate(); designer.destroy();
            listeners.splice(0).forEach((remove) => remove()); controllers.delete(root);
            const instance = window.AdasiModal?.getInstance?.(modal);
            if (!instance) return;
            const dispose = () => instance.dispose();
            const hide = () => { modal.addEventListener('adasi:modal-hidden', dispose, { once: true }); instance.hide(); };
            if (closing) modal.addEventListener('adasi:modal-hidden', dispose, { once: true });
            else if (opening) modal.addEventListener('adasi:modal-shown', hide, { once: true });
            else if (isModalOpen()) {
                if (shown) hide();
                else modal.addEventListener('adasi:modal-shown', hide, { once: true });
            } else dispose();
        },
    };
    controllers.set(root, api);
    updateControls();
    return api;
}

export const bootFormats = (scope = globalThis.document) => {
    const window = scope.defaultView || globalThis.window;
    const FormDataClass = window.FormData || globalThis.FormData;
    scope.querySelectorAll('[data-format-export-form]').forEach((form) => {
        if (formatForms.has(form)) return;
        formatForms.add(form);
        form?.setAttribute?.('data-managed-submit', '');
        form?.setAttribute?.('data-no-loader', '');
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const button = form.querySelector('[type="submit"]');
            const error = form.querySelector('[data-format-export-error]');
            if (!button || button.disabled || !form.reportValidity()) return;
            const showError = (message) => { error.textContent = message; error.hidden = false; error.focus(); };
            error.hidden = true;
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            button.querySelector?.('[data-export-busy-icon]')?.remove();
            button.querySelectorAll?.('.ui-spinner')?.forEach((node) => node.remove());
            let busyIcon = null;
            if (typeof scope.createElement === 'function' && (typeof button.prepend === 'function' || typeof button.append === 'function')) {
                busyIcon = scope.createElement('span');
                busyIcon.className = 'ui-spinner';
                busyIcon.dataset.exportBusyIcon = '';
                busyIcon.setAttribute('aria-hidden', 'true');
                if (typeof button.prepend === 'function') button.prepend(busyIcon);
                else button.append(busyIcon);
            }
            try {
                const data = Object.fromEntries(new FormDataClass(form));
                const format = data['options[format]'];
                delete data['options[format]']; delete data._token;
                if (!window.AdasiAsyncExport?.startExport) throw new Error(form.dataset.exportFailed);
                await window.AdasiAsyncExport.startExport(form, { url: form.action, body: { ...data, options: { format } }, onError: showError });
            } catch { showError(form.dataset.exportFailed); }
            finally {
                busyIcon?.remove?.();
                button.querySelector?.('[data-export-busy-icon]')?.remove();
                button.querySelectorAll?.('.ui-spinner')?.forEach((node) => node.remove());
                button.disabled = false;
                button.removeAttribute('aria-busy');
            }
        });
    });
};
export const bootAdvancedExports = (scope = globalThis.document) => {
    scope.querySelectorAll('[data-advanced-export]').forEach((root) => createAdvancedExport(root));
    bootFormats(scope);
};
if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => bootAdvancedExports(), { once: true });
    else bootAdvancedExports();
}
