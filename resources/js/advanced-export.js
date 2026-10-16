import { createColumnDesigner } from './export-column-designer.js';

const controllers = new WeakMap();
const formatForms = new WeakSet();

// Network, filters, presets and dispatch stay here. The designer alone owns
// column configuration; every export and preset save reads its serializer.
export function createAdvancedExport(root, { designer: suppliedDesigner } = {}) {
    if (controllers.has(root)) return controllers.get(root);
    const document = root.ownerDocument;
    const window = document.defaultView;
    const modal = root.querySelector('.modal');
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
    const designer = suppliedDesigner || createColumnDesigner(root, { text });
    const saveButton = root.querySelector('[data-export-save]');
    const updateButton = root.querySelector('[data-export-update]');
    const deleteButton = root.querySelector('[data-export-delete]');
    const buttons = [submit, quick, saveButton, updateButton, deleteButton];
    const listeners = [];
    let definition;
    let presets = [];
    let epoch = 0;
    let pending = null;
    let exportFlight = null;
    let ready = false;
    let shown = false;
    let opening = false;
    let closing = false;
    let disposed = false;
    let preserveOnShow = false;
    let opener = root.querySelector('[data-export-open]');
    const listen = (target, name, handler) => {
        if (!target) return;
        target.addEventListener(name, handler);
        listeners.push(() => target.removeEventListener(name, handler));
    };
    const chosenPreset = () => presets.find((preset) => preset.id === select.value);
    const updateControls = () => {
        const available = ready && shown && !pending && !exportFlight && !disposed;
        submit.disabled = !available;
        saveButton.disabled = !available;
        updateButton.disabled = !available || !chosenPreset();
        deleteButton.disabled = !available || !chosenPreset();
        select.disabled = !available;
        quick.disabled = Boolean(pending || exportFlight || disposed);
        if (retry) retry.disabled = Boolean(pending || exportFlight || disposed);
        designer.setEnabled(available);
    };
    const busy = (button, active) => {
        const label = button.querySelector('span');
        button.querySelector('[data-export-busy-icon]')?.remove();
        if (active) {
            button.setAttribute('aria-busy', 'true');
            if (label) label.style.display = 'none';
            const icon = document.createElement('span');
            icon.className = 'ui-spinner'; icon.dataset.exportBusyIcon = ''; icon.setAttribute('aria-hidden', 'true');
            button.append(icon);
        } else {
            button.removeAttribute('aria-busy');
            if (label) label.style.removeProperty('display');
        }
    };
    const clearError = () => { error.hidden = true; error.textContent = ''; if (retry) retry.hidden = true; };
    const showError = (message, reload = false) => {
        error.textContent = message; error.hidden = false;
        if (reload) status.textContent = '';
        if (retry) retry.hidden = !reload;
        if (modal.classList.contains('show')) error.focus();
    };
    const invalidate = () => {
        epoch += 1;
        pending?.abort.abort();
        pending = null;
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
        const values = { ...Object.fromEntries(new URL(window.location.href).searchParams), ...JSON.parse(root.dataset.initialFilters || '{}') };
        Object.entries(selectors).forEach(([name, selector]) => { const input = document.querySelector(selector); if (input) values[name] = input.type === 'checkbox' ? (input.checked ? '1' : '0') : input.value; });
        if (root.dataset.exportTable && window.jQuery?.fn?.DataTable?.isDataTable(root.dataset.exportTable)) {
            if (!selectors.search && !root.dataset.exportKey.startsWith('qc.')) values.search = window.jQuery(root.dataset.exportTable).DataTable().search().trim();
        }
        return values;
    };
    const initial = new URL(window.location.href).searchParams;
    Object.entries(selectors).forEach(([name, selector]) => { const input = document.querySelector(selector); if (input && initial.has(name)) { if (input.type === 'checkbox') input.checked = initial.get(name) === '1'; else input.value = initial.get(name); } });
    const updateRelative = () => {
        const relative = root.querySelector('[data-export-relative]').checked;
        root.querySelector('[data-export-days]').disabled = !relative;
        form.querySelectorAll('[data-filter-name="start_date"], [data-filter-name="end_date"]').forEach((input) => { input.disabled = relative; input.dispatchEvent(new Event('change', { bubbles: true })); });
    };
    const setFilters = (values) => {
        form.querySelectorAll('[data-filter-name]').forEach((input) => { const field = definition.filters.find((f) => f.name === input.dataset.filterName); input.value = values[input.dataset.filterName] ?? field?.default ?? ''; input.dispatchEvent(new Event('change', { bubbles: true })); });
        ['date_from', 'date_to'].forEach((name) => { const input = form.querySelector(`[name="${name}"]`); if (input) { input.value = values[name] || ''; input.dispatchEvent(new Event('change', { bubbles: true })); } });
        root.querySelector('[data-export-relative]').checked = values.date_range?.mode === 'relative';
        root.querySelector('[data-export-days]').value = values.date_range?.days || 30;
        updateRelative();
    };
    const filters = () => {
        const data = {};
        form.querySelectorAll('[data-filter-name]').forEach((input) => { if (!input.disabled && input.value !== '') data[input.dataset.filterName] = input.value; });
        ['date_from', 'date_to'].forEach((name) => { const input = form.querySelector(`[name="${name}"]`); if (input?.value) data[name] = input.value; });
        if (root.querySelector('[data-export-relative]').checked) data.date_range = { mode: 'relative', days: Number(root.querySelector('[data-export-days]').value) };
        return data;
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
        root.querySelector('[data-export-format]').value = preset?.format || 'xlsx';
        root.querySelector('[data-export-preset-name]').value = preset?.name || '';
        root.querySelector('[data-export-default]').checked = preset?.is_default || false;
        if (preset?.warnings?.length) showError(preset.warnings.join('\n'));
    };
    const load = async (operation) => {
        ready = false; status.textContent = text('loading'); updateControls();
        const [catalog, cohort] = await Promise.all([
            json(root.dataset.definitionUrl, 'GET', undefined, operation),
            json(`${root.dataset.presetsUrl}?export_key=${encodeURIComponent(root.dataset.exportKey)}`, 'GET', undefined, operation),
        ]);
        if (!current(operation)) return false;
        if (catalog.export_key !== root.dataset.exportKey || catalog.supports_columns !== true || !Array.isArray(catalog.filters) || !Array.isArray(catalog.formats) || !catalog.formats.includes('xlsx') || !Array.isArray(cohort.data)) throw new Error(text('failed'));
        try { designer.configure(catalog.columns); }
        catch { throw new Error(text('failed')); }
        definition = catalog; presets = cohort.data;
        const option = (label, value) => { const node = document.createElement('option'); node.textContent = label; node.value = value; return node; };
        select.replaceChildren(option(text('default_columns'), ''), ...presets.map((preset) => option(preset.name, preset.id)));
        select.value = '';
        applyPreset(null); ready = true; status.textContent = '';
        return true;
    };
    const revealFailure = () => {
        if (modal.classList.contains('show')) return;
        preserveOnShow = true;
        window.bootstrap.Modal.getOrCreateInstance(modal).show();
    };
    const dispatch = async (operation) => {
        if (!current(operation) || !ready || exportFlight) return;
        if (!window.AdasiAsyncExport?.startExport) throw new Error(text('failed'));
        const columns = designer.serialize();
        exportFlight = operation; updateControls();
        try {
            const accepted = await window.AdasiAsyncExport.startExport(form, {
                url: form.action,
                body: { ...filters(), options: { columns, format: root.querySelector('[data-export-format]').value } },
                onError: (message) => { if (current(operation)) showError(message); },
            });
            if (!current(operation)) return;
            if (accepted) window.bootstrap.Modal.getOrCreateInstance(modal).hide();
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
    listen(modal, 'show.bs.modal', (event) => {
        opening = true;
        shown = false;
        if (preserveOnShow) { preserveOnShow = false; return; }
        opener = event.relatedTarget || root.querySelector('[data-export-open]');
        return runLoad(begin('load'));
    });
    listen(modal, 'shown.bs.modal', () => { opening = false; shown = true; updateControls(); if (!error.hidden) error.focus(); else root.querySelector('.modal-title')?.focus(); });
    listen(modal, 'hide.bs.modal', () => { opening = false; shown = false; closing = true; invalidate(); ready = false; designer.suspend(); updateControls(); });
    listen(modal, 'hidden.bs.modal', () => { closing = false; if (!disposed) opener?.focus(); });
    listen(quick, 'click', async () => {
        if (quick.disabled) return;
        opener = quick;
        const operation = begin('quick', quick);
        try {
            if (!await load(operation) || !current(operation)) return;
            applyPreset(presets.find((preset) => preset.is_default));
            await dispatch(operation);
        } catch (e) { if (current(operation)) { showError(e.message || text('failed'), !ready); revealFailure(); } }
        finally { finish(operation); }
    });
    listen(form, 'submit', async (event) => {
        event.preventDefault();
        if (!ready || pending || exportFlight || !form.reportValidity()) return;
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
    });
    listen(root.querySelector('[data-export-relative]'), 'change', updateRelative);
    listen(retry, 'click', () => runLoad(begin('load')));
    const save = async (update = false) => {
        const selected = chosenPreset();
        if (!ready || pending || exportFlight || (update && !selected)) return;
        const input = { export_key: root.dataset.exportKey, name: root.querySelector('[data-export-preset-name]').value, columns: designer.serialize(), filters: filters(), format: root.querySelector('[data-export-format]').value, is_default: root.querySelector('[data-export-default]').checked };
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
    listen(deleteButton, 'click', async () => {
        const selected = chosenPreset();
        if (!ready || pending || exportFlight || !selected) return;
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
            const instance = window.bootstrap?.Modal.getInstance?.(modal);
            if (!instance) return;
            // Bootstrap owns a global resize listener. Release its instance too,
            // after any in-flight transition has finished restoring body scroll.
            const dispose = () => instance.dispose();
            const hide = () => { modal.addEventListener('hidden.bs.modal', dispose, { once: true }); instance.hide(); };
            if (closing) modal.addEventListener('hidden.bs.modal', dispose, { once: true });
            else if (opening) modal.addEventListener('shown.bs.modal', hide, { once: true });
            else if (modal.classList.contains('show')) {
                if (shown) hide();
                else modal.addEventListener('shown.bs.modal', hide, { once: true });
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
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const button = form.querySelector('[type="submit"]');
            const error = form.querySelector('[data-format-export-error]');
            if (button.disabled || !form.reportValidity()) return;
            const showError = (message) => { error.textContent = message; error.hidden = false; error.focus(); };
            error.hidden = true;
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            try {
                const data = Object.fromEntries(new FormDataClass(form));
                const format = data['options[format]'];
                delete data['options[format]']; delete data._token;
                if (!window.AdasiAsyncExport?.startExport) throw new Error(form.dataset.exportFailed);
                await window.AdasiAsyncExport.startExport(form, { url: form.action, body: { ...data, options: { format } }, onError: showError });
            } catch { showError(form.dataset.exportFailed); }
            finally { button.disabled = false; button.removeAttribute('aria-busy'); }
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
