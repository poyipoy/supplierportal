const boot = () => document.querySelectorAll('[data-advanced-export]').forEach((root) => {
    const modal = root.querySelector('.modal');
    const form = root.querySelector('[data-advanced-export-form]');
    const list = root.querySelector('[data-export-columns]');
    const submit = root.querySelector('[data-export-submit]');
    const quick = root.querySelector('[data-export-quick]');
    const status = root.querySelector('[data-export-status]');
    const error = root.querySelector('[data-export-error]');
    const select = root.querySelector('[data-export-preset]');
    const copy = JSON.parse(root.dataset.exportCopy);
    const selectors = JSON.parse(root.dataset.filterSelectors);
    let definition;
    let presets = [];
    let dragKey = null;
    let preserveOnShow = false;
    const text = (key, params = {}) => Object.entries(params).reduce((s, [k, v]) => s.replaceAll(`:${k}`, String(v)), copy[key] || key);
    const showError = (message) => { error.textContent = message; error.hidden = false; error.focus(); };
    const clearError = () => { error.hidden = true; error.textContent = ''; };
    const busy = (button, active) => {
        button.disabled = active;
        const label = button.querySelector('span');
        if (active) {
            button.setAttribute('aria-busy', 'true');
            if (label) label.style.display = 'none';
            const icon = document.createElement('span'); icon.className = 'ui-spinner'; icon.dataset.exportBusyIcon = ''; icon.setAttribute('aria-hidden', 'true');
            button.append(icon);
        } else {
            button.removeAttribute('aria-busy'); button.querySelector('[data-export-busy-icon]')?.remove();
            if (label) label.style.removeProperty('display');
        }
    };
    const json = async (url, method = 'GET', body) => {
        const response = await fetch(url, {
            method, credentials: 'same-origin', cache: 'no-store',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': form.querySelector('[name="_token"]').value, ...(body ? { 'Content-Type': 'application/json' } : {}) },
            ...(body ? { body: JSON.stringify(body) } : {}),
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
    // Initialize existing page controls from canonical query filters before its first table load.
    const initial = new URL(window.location.href).searchParams;
    Object.entries(selectors).forEach(([name, selector]) => { const input = document.querySelector(selector); if (input && initial.has(name)) { if (input.type === 'checkbox') input.checked = initial.get(name) === '1'; else input.value = initial.get(name); } });
    const updateRelative = () => {
        const relative = root.querySelector('[data-export-relative]').checked;
        root.querySelector('[data-export-days]').disabled = !relative;
        form.querySelectorAll('[data-filter-name="start_date"], [data-filter-name="end_date"]').forEach((input) => { input.disabled = relative; input.dispatchEvent(new Event('change', { bubbles: true })); });
    };
    const setFilters = (values) => {
        form.querySelectorAll('[data-filter-name]').forEach((input) => { const field = definition.filters.find((f) => f.name === input.dataset.filterName); input.value = values[input.dataset.filterName] ?? field?.default ?? ''; input.dispatchEvent(new Event('change', { bubbles: true })); });
        ['date_from','date_to'].forEach((name) => { const input = form.querySelector(`[name="${name}"]`); if (input) { input.value = values[name] || ''; input.dispatchEvent(new Event('change', { bubbles: true })); } });
        root.querySelector('[data-export-relative]').checked = values.date_range?.mode === 'relative';
        root.querySelector('[data-export-days]').value = values.date_range?.days || 30;
        updateRelative();
    };
    const filters = () => {
        const data = {};
        form.querySelectorAll('[data-filter-name]').forEach((input) => { if (!input.disabled && input.value !== '') data[input.dataset.filterName] = input.value; });
        ['date_from','date_to'].forEach((name) => { const input = form.querySelector(`[name="${name}"]`); if (input?.value) data[name] = input.value; });
        if (root.querySelector('[data-export-relative]').checked) data.date_range = { mode: 'relative', days: Number(root.querySelector('[data-export-days]').value) };
        return data;
    };
    const keys = () => Array.from(list.children).filter((row) => row.querySelector('[data-column-check]').checked).map((row) => row.dataset.key);
    const refreshOrder = () => {
        Array.from(list.children).forEach((row, i) => { row.querySelector('[data-column-up]').disabled = i === 0; row.querySelector('[data-column-down]').disabled = i === list.children.length - 1; });
        status.textContent = text('selected', { count: keys().length });
    };
    const columns = (selected) => {
        list.replaceChildren();
        const ordered = [...selected, ...definition.columns.map((c) => c.key).filter((k) => !selected.includes(k))];
        ordered.forEach((key) => {
            const column = definition.columns.find((c) => c.key === key);
            if (!column) return;
            const row = root.querySelector('[data-export-column-template]').content.firstElementChild.cloneNode(true);
            row.dataset.key = key;
            row.querySelector('[data-column-label]').textContent = column.label;
            const checkbox = row.querySelector('[data-column-check]');
            checkbox.checked = selected.includes(key) || column.required;
            checkbox.disabled = column.required;
            row.querySelector('[data-column-required]').hidden = !column.required;
            const up = row.querySelector('[data-column-up]');
            const down = row.querySelector('[data-column-down]');
            up.setAttribute('aria-label', text('move_up', { column: column.label }));
            down.setAttribute('aria-label', text('move_down', { column: column.label }));
            up.addEventListener('click', () => { if (row.previousElementSibling) list.insertBefore(row, row.previousElementSibling); refreshOrder(); up.focus(); });
            down.addEventListener('click', () => { if (row.nextElementSibling) list.insertBefore(row.nextElementSibling, row); refreshOrder(); down.focus(); });
            checkbox.addEventListener('change', refreshOrder);
            row.addEventListener('dragstart', (event) => { dragKey = key; event.dataTransfer?.setData('text/plain', key); });
            row.addEventListener('dragend', () => { dragKey = null; });
            row.addEventListener('dragover', (event) => event.preventDefault());
            row.addEventListener('drop', (event) => { event.preventDefault(); const source = Array.from(list.children).find((r) => r.dataset.key === dragKey); if (source && source !== row) list.insertBefore(source, row); dragKey = null; refreshOrder(); });
            list.append(row);
        });
        refreshOrder();
    };
    const applyPreset = (preset) => {
        columns(preset?.columns || definition.columns.filter((c) => c.default).map((c) => c.key));
        setFilters(preset?.filters || currentFilters());
        root.querySelector('[data-export-format]').value = preset?.format || 'xlsx';
        root.querySelector('[data-export-preset-name]').value = preset?.name || '';
        root.querySelector('[data-export-default]').checked = preset?.is_default || false;
        if (preset?.warnings?.length) showError(preset.warnings.join('\n'));
    };
    const load = async () => {
        clearError(); status.textContent = text('loading'); submit.disabled = true;
        [definition, { data: presets }] = await Promise.all([json(root.dataset.definitionUrl), json(`${root.dataset.presetsUrl}?export_key=${encodeURIComponent(root.dataset.exportKey)}`)]);
        select.replaceChildren(new Option(text('default_columns'), ''));
        presets.forEach((p) => select.add(new Option(p.name, p.id)));
        applyPreset(null); submit.disabled = false;
    };
    const execute = async () => {
        if (!window.AdasiAsyncExport?.startExport) { showError(text('failed')); return; }
        clearError(); busy(submit, true);
        try {
            const accepted = await window.AdasiAsyncExport.startExport(form, { url: form.action, body: { ...filters(), options: { columns: keys(), format: root.querySelector('[data-export-format]').value } }, onError: showError });
            if (accepted) window.bootstrap.Modal.getOrCreateInstance(modal).hide();
            else if (!modal.classList.contains('show')) { preserveOnShow = true; window.bootstrap.Modal.getOrCreateInstance(modal).show(); }
        } finally { busy(submit, false); }
    };
    modal.addEventListener('show.bs.modal', () => { if (preserveOnShow) { preserveOnShow = false; return; } load().catch((e) => showError(e.message || text('failed'))); });
    modal.addEventListener('hidden.bs.modal', () => root.querySelector('[data-export-open]').focus());
    quick.addEventListener('click', async () => {
        busy(quick, true);
        try { await load(); const preset = presets.find((p) => p.is_default); applyPreset(preset); await execute(); }
        catch (e) { window.bootstrap.Modal.getOrCreateInstance(modal).show(); showError(e.message || text('failed')); }
        finally { busy(quick, false); }
    });
    form.addEventListener('submit', (event) => { event.preventDefault(); if (form.reportValidity()) execute().catch((e) => showError(e.message || text('failed'))); });
    select.addEventListener('change', () => { clearError(); applyPreset(presets.find((p) => p.id === select.value)); });
    root.querySelector('[data-export-relative]').addEventListener('change', updateRelative);
    root.querySelector('[data-export-reset]').addEventListener('click', () => columns(definition.columns.filter((c) => c.default).map((c) => c.key)));
    const save = async (update = false) => {
        clearError();
        const selected = presets.find((p) => p.id === select.value);
        if (update && !selected) return;
        const button = root.querySelector(update ? '[data-export-update]' : '[data-export-save]');
        if (button.disabled) return;
        busy(button, true);
        const input = { export_key: root.dataset.exportKey, name: root.querySelector('[data-export-preset-name]').value, columns: keys(), filters: filters(), format: root.querySelector('[data-export-format]').value, is_default: root.querySelector('[data-export-default]').checked };
        try {
            const result = await json(update ? `${root.dataset.presetsUrl}/${encodeURIComponent(selected.id)}` : root.dataset.presetStoreUrl, update ? 'PUT' : 'POST', input);
            await load(); select.value = result.id; applyPreset(result); status.textContent = text('saved');
        } catch (e) { showError(e.message || text('save_failed')); }
        finally { busy(button, false); }
    };
    root.querySelector('[data-export-save]').addEventListener('click', () => save());
    root.querySelector('[data-export-update]').addEventListener('click', () => save(true));
    root.querySelector('[data-export-delete]').addEventListener('click', async () => {
        const selected = presets.find((p) => p.id === select.value);
        if (!selected) return;
        try { await json(`${root.dataset.presetsUrl}/${encodeURIComponent(selected.id)}`, 'DELETE'); await load(); }
        catch (e) { showError(e.message || text('save_failed')); }
    });
});

const bootFormats = () => document.querySelectorAll('[data-format-export-form]').forEach((form) => {
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
            const data = Object.fromEntries(new FormData(form));
            const format = data['options[format]'];
            delete data['options[format]'];
            delete data._token;
            if (!window.AdasiAsyncExport?.startExport) throw new Error(form.dataset.exportFailed);
            await window.AdasiAsyncExport.startExport(form, { url: form.action, body: { ...data, options: { format } }, onError: showError });
        } catch { showError(form.dataset.exportFailed); }
        finally { button.disabled = false; button.removeAttribute('aria-busy'); }
    });
});

const bootAll = () => { boot(); bootFormats(); };
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bootAll);
else bootAll();
