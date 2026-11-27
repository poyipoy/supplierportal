import test from 'node:test';
import assert from 'node:assert/strict';
import { createAdvancedExport } from '../../resources/js/advanced-export.js';
import { createColumnDesigner, createColumnState, transitionColumns, serializeColumns } from '../../resources/js/export-column-designer.js';

const catalog = [
    { key: 'po_number', label: 'PO Number', default: true, required: true },
    { key: 'status', label: 'Status', default: true, required: false },
    { key: 'remark', label: 'Remark', default: true, required: false },
    { key: 'incoterm', label: 'Incoterms', default: false, required: false },
];
const definition = (label = 'PO Number') => ({ export_key: 'purchasing.po', supports_columns: true, supports_presets: true, formats: ['xlsx', 'csv'], columns: catalog.map((c, i) => i ? c : { ...c, label }), filters: [] });
const deferred = () => { let resolve; let reject; const promise = new Promise((a, b) => { resolve = a; reject = b; }); return { promise, resolve, reject }; };
const response = (data, status = 200) => ({ status, ok: status >= 200 && status < 300, json: async () => data });

class Element {
    constructor(doc) { this.ownerDocument = doc; this.listeners = new Map(); this.dataset = {}; this.attributes = new Map(); this.value = ''; this.checked = false; this.disabled = false; this.hidden = false; this.children = []; this.style = { removeProperty() {} }; this.nodes = new Map(); this.lists = new Map(); this.classList = { values: new Set(), contains: (x) => this.classList.values.has(x), add: (x) => this.classList.values.add(x), remove: (x) => this.classList.values.delete(x) }; }
    addEventListener(name, fn) { if (!this.listeners.has(name)) this.listeners.set(name, new Set()); this.listeners.get(name).add(fn); }
    removeEventListener(name, fn) { this.listeners.get(name)?.delete(fn); }
    async emit(name, extra = {}) { const event = { target: this, preventDefault() {}, ...extra }; await Promise.all([...(this.listeners.get(name) || [])].map((fn) => fn(event))); }
    dispatchEvent() {}
    setAttribute(k, v) { this.attributes.set(k, v); }
    removeAttribute(k) { this.attributes.delete(k); }
    querySelector(k) { if (k === 'span') return null; if (k === '[data-export-busy-icon]') return this.children.find((x) => Object.hasOwn(x.dataset, 'exportBusyIcon')); return this.nodes.get(k) || null; }
    querySelectorAll(k) { return this.lists.get(k) || []; }
    append(x) { this.children.push(x); }
    replaceChildren(...items) { this.children = items; }
    remove() {}
    focus() { this.ownerDocument.activeElement = this; }
    reportValidity() { return true; }
}

function harness() {
    const requests = []; const exports = []; const pending = []; let auto = true; let sdk;
    const doc = { activeElement: null, querySelector: () => null, createElement: () => new Element(doc) };
    const win = { location: { href: 'https://portal.test/purchasing/purchase-orders' }, AbortController, fetch: async (url, init) => { requests.push({ url, init }); if (!auto) { const d = deferred(); pending.push({ ...d, url, init }); return d.promise; } return response(url.includes('/definitions/') ? definition() : { data: [] }); } };
    doc.defaultView = win;
    const root = new Element(doc); root.dataset = { exportKey: 'purchasing.po', definitionUrl: '/definitions/purchasing.po', presetsUrl: '/presets', presetStoreUrl: '/presets', filterSelectors: '{}', initialFilters: '{}', exportCopy: JSON.stringify({ failed: 'Failed', invalid_columns: 'Invalid columns', save_failed: 'Save failed', saved: 'Saved', loading: 'Loading', default_columns: 'Default' }) };
    const selectors = ['dialog.ui-native-dialog, .ui-export-designer-modal', '[data-advanced-export-form]', '[data-export-submit]', '[data-export-quick]', '[data-export-status]', '[data-export-error]', '[data-export-preset]', '[data-export-format]', '[data-export-preset-name]', '[data-export-default]', '[data-export-relative]', '[data-export-days]', '[data-export-save]', '[data-export-update]', '[data-export-delete]', '[data-export-open]', '[data-export-retry]', '.modal-title'];
    for (const key of selectors) root.nodes.set(key, new Element(doc));
    const form = root.querySelector('[data-advanced-export-form]'); form.action = 'https://portal.test/purchasing/export/purchase-orders'; const token = new Element(doc); token.value = 'token'; form.nodes.set('[name="_token"]', token);
    root.querySelector('[data-export-format]').value = 'xlsx';
    root.querySelector('[data-export-error]').hidden = true;
    const modal = root.querySelector('dialog.ui-native-dialog, .ui-export-designer-modal');
    const modalCtrl = { async show() { modal.open = true; await modal.emit('adasi:modal-show'); await modal.emit('adasi:modal-shown'); }, async hide() { await modal.emit('adasi:modal-hide'); modal.open = false; await modal.emit('adasi:modal-hidden'); } };
    win.AdasiModal = { getOrCreateInstance: () => modalCtrl };
    win.AdasiAsyncExport = { startExport: async (target, options) => { exports.push(options.body); return sdk ? sdk(options) : true; } };
    let state; let enabled; let configureCalls = 0;
    const designer = { configure(c, selected) { const next = createColumnState(c, selected); state = next; configureCalls++; }, setColumns(columns) { state = transitionColumns(state, { type: 'preset', columns }); }, reset() { state = transitionColumns(state, { type: 'reset' }); }, serialize() { return serializeColumns(state); }, setEnabled(value) { enabled = value; }, suspend() { enabled = false; }, destroy() {}, focus() {}, getState: () => state };
    const api = createAdvancedExport(root, { designer });
    return { root, api, designer, requests, exports, pending, doc, win, modal, modalCtrl, get state() { return state; }, get enabled() { return enabled; }, get configureCalls() { return configureCalls; }, manual() { auto = false; }, sdk(fn) { sdk = fn; }, el: (selector) => root.querySelector(selector), command(command) { state = transitionColumns(state, command); }, async open() { modal.open = true; await modal.emit('adasi:modal-show'); await modal.emit('adasi:modal-shown'); }, close: () => modalCtrl.hide() };
}

test('controller boot is idempotent and exports the exact ordered visible state with existing request shape', async () => {
    const h = harness(); assert.equal(createAdvancedExport(h.root), h.api);
    await h.open(); h.command({ type: 'hide', key: 'status' }); h.command({ type: 'move-left', key: 'remark' });
    await h.el('[data-advanced-export-form]').emit('submit');
    assert.deepEqual(h.exports, [{ options: { columns: ['remark', 'po_number'], format: 'xlsx' } }]);
    assert.equal(h.configureCalls, 1); assert.equal(h.requests.length, 2); assert.equal(Boolean(h.modal.open), false);
});

test('save, reload presented preset, and export use the same serializer and preserve hidden persistence contract', async () => {
    const h = harness(); await h.open(); h.command({ type: 'hide', key: 'status' }); h.command({ type: 'move-left', key: 'remark' });
    h.el('[data-export-preset-name]').value = 'Mine'; h.el('[data-export-format]').value = 'csv';
    const preset = { id: 'hash', export_key: 'purchasing.po', name: 'Mine', columns: ['remark', 'po_number'], filters: {}, format: 'csv', is_default: false };
    h.manual(); const save = h.el('[data-export-save]').emit('click'); assert.deepEqual(JSON.parse(h.requests.at(-1).init.body).columns, preset.columns);
    h.pending[0].resolve(response(preset)); await new Promise(setImmediate);
    h.pending[1].resolve(response(definition())); h.pending[2].resolve(response({ data: [preset] })); await save;
    assert.deepEqual(h.state.order, ['remark', 'po_number', 'status', 'incoterm']);
    await h.el('[data-advanced-export-form]').emit('submit'); assert.deepEqual(h.exports[0].options, { columns: preset.columns, format: 'csv' });
});

test('close then reversed stale load cannot replace a newer catalog or enable current pending controls', async () => {
    const h = harness(); h.manual(); const old = h.open(); assert.equal(h.pending.length, 2); await h.close(); const recent = h.open();
    h.pending[0].resolve(response(definition('Old'))); h.pending[1].resolve(response({ data: [] })); await old;
    assert.equal(h.el('[data-export-submit]').disabled, true); assert.equal(h.configureCalls, 0);
    h.pending[2].resolve(response(definition('New'))); h.pending[3].resolve(response({ data: [] })); await recent;
    assert.equal(h.state.catalog[0].label, 'New'); assert.equal(h.enabled, true); assert.equal(h.configureCalls, 1);
});

test('stale rejection and finally cannot show errors or reopen a manually closed modal', async () => {
    const h = harness(); h.manual(); const quick = h.el('[data-export-quick]').emit('click'); await h.close();
    h.pending[0].reject(new Error('old failure')); h.pending[1].resolve(response({ data: [] })); await quick;
    assert.equal(Boolean(h.modal.open), false); assert.equal(h.el('[data-export-error]').hidden, true); assert.equal(h.configureCalls, 0);
});

test('failed preset POST preserves valid configuration and releases only its own busy operation', async () => {
    const h = harness(); await h.open(); h.command({ type: 'move-left', key: 'remark' }); const before = h.designer.serialize(); h.manual();
    const save = h.el('[data-export-save]').emit('click'); h.pending[0].resolve(response({ errors: { name: ['Name duplicate'] } }, 422)); await save;
    assert.deepEqual(h.designer.serialize(), before); assert.equal(h.enabled, true); assert.match(h.el('[data-export-error]').textContent, /Name duplicate/);
});

test('invalid presented preset fails closed without repairing or partially replacing valid state', async () => {
    const h = harness(); h.manual(); const opening = h.open();
    h.pending[0].resolve(response(definition())); h.pending[1].resolve(response({ data: [{ id: 'bad', columns: ['status'], name: 'Bad', format: 'xlsx' }] })); await opening;
    const before = h.designer.serialize(); h.el('[data-export-preset]').value = 'bad'; await h.el('[data-export-preset]').emit('change');
    assert.deepEqual(h.designer.serialize(), before); assert.equal(h.enabled, false); assert.equal(h.el('[data-export-submit]').disabled, true);
});

test('Quick Export applies personal default through the same serialization and never leaks into another root', async () => {
    const h = harness(); const other = harness(); await other.open(); h.manual();
    const quick = h.el('[data-export-quick]').emit('click'); h.pending[0].resolve(response(definition())); h.pending[1].resolve(response({ data: [{ id: 'mine', name: 'Mine', columns: ['remark', 'po_number'], format: 'csv', filters: {}, is_default: true }] })); await quick;
    assert.deepEqual(h.exports[0].options, { columns: ['remark', 'po_number'], format: 'csv' }); assert.deepEqual(other.designer.serialize(), ['po_number', 'status', 'remark']);
});

test('late accepted export does not dismiss a newer modal intent or cancel the accepted SDK job', async () => {
    const h = harness(); await h.open(); const accepted = deferred(); h.sdk(() => accepted.promise);
    const exporting = h.el('[data-advanced-export-form]').emit('submit'); await h.close(); await h.open();
    assert.equal(h.el('[data-export-submit]').disabled, true); accepted.resolve(true); await exporting;
    assert.equal(Boolean(h.modal.open), true); assert.equal(h.exports.length, 1); assert.equal(h.el('[data-export-submit]').disabled, false); assert.deepEqual(h.designer.serialize(), ['po_number', 'status', 'remark']);
});

test('destroy invalidates in-flight work and releases controller event handlers', async () => {
    const h = harness(); h.manual(); const opening = h.open(); h.api.destroy(); h.pending[0].resolve(response(definition())); h.pending[1].resolve(response({ data: [] })); await opening;
    assert.equal(h.configureCalls, 0); assert.equal(h.modal.listeners.get('adasi:modal-show').size, 0);
});

test('fast metadata cannot enable worksheet actions before dialog finishes showing and owns focus', async () => {
    const h = harness(); h.modal.open = true;
    await h.modal.emit('adasi:modal-show');
    assert.equal(h.el('[data-export-submit]').disabled, true);
    assert.equal(h.enabled, false);
    await h.modal.emit('adasi:modal-shown');
    assert.equal(h.el('[data-export-submit]').disabled, false);
    assert.equal(h.enabled, true);
});

test('destroy also disposes the AdasiModal instance after a closed dialog so resources release the root', async () => {
    const h = harness(); await h.open(); await h.close();
    let disposed = 0; h.win.AdasiModal.getInstance = () => ({ dispose() { disposed++; } });
    h.api.destroy(); h.api.destroy();
    assert.equal(disposed, 1);
});

test('destroy waits for shown before hiding and disposing an opening modal element', async () => {
    const h = harness(); let disposals = 0; let hides = 0;
    h.win.AdasiModal.getInstance = () => ({ dispose() { disposals++; }, hide() { hides++; return h.modal.emit('adasi:modal-hidden'); } });
    const opening = h.modal.emit('adasi:modal-show');
    h.api.destroy(); assert.equal(disposals, 0);
    await h.modal.emit('adasi:modal-shown'); await opening;
    assert.equal(hides, 1); assert.equal(disposals, 1);
});

test('malformed catalog is rejected with localized load feedback and never enables serialization', async () => {
    const h = harness(); h.manual(); const opening = h.open();
    const invalid = definition(); invalid.columns = invalid.columns.map((column) => ({ ...column, default: false }));
    h.pending[0].resolve(response(invalid)); h.pending[1].resolve(response({ data: [] })); await opening;
    assert.equal(h.el('[data-export-error]').textContent, 'Failed');
    assert.equal(h.el('[data-export-submit]').disabled, true); assert.equal(h.configureCalls, 0);
});

test('CSV delimiter selector toggles on CSV format, sends csv_delimiter, and debounced preview disables submit on exceeds_limit', async () => {
    const timers = [];
    const requests = [];
    const exports = [];
    const doc = { activeElement: null, documentElement: { lang: 'id' }, querySelector: () => null, createElement: () => new Element(doc) };
    let previewPayload = { total_rows: 150000, max_rows: 100000, exceeds_limit: true, is_empty: false };
    const win = {
        location: { href: 'https://portal.test/purchasing/purchase-orders' },
        AbortController,
        setTimeout: (fn, delay) => { const id = timers.length + 1; timers.push({ id, fn, delay, cleared: false }); return id; },
        clearTimeout: (id) => { const t = timers.find((item) => item.id === id); if (t) t.cleared = true; },
        fetch: async (url, init) => {
            requests.push({ url, init });
            if (url.includes('/definitions/')) return response(definition());
            if (url.includes('/preview-count')) return response(previewPayload);
            return response({ data: [], personal: [], team: [], can_manage_team: true });
        },
    };
    doc.defaultView = win;
    const root = new Element(doc);
    root.dataset = {
        exportKey: 'purchasing.po',
        definitionUrl: '/definitions/purchasing.po',
        presetsUrl: '/presets',
        presetStoreUrl: '/presets',
        previewUrl: '/exports/preview-count',
        defaultCsvDelimiter: ';',
        filterSelectors: '{}',
        initialFilters: '{}',
        exportCopy: JSON.stringify({
            failed: 'Failed', invalid_columns: 'Invalid', save_failed: 'Save failed', saved: 'Saved',
            loading: 'Loading', default_columns: 'Default', preview_loading: 'Counting...',
            preview_summary: ':rows rows · :columns cols · :format',
            preview_empty: '0 rows', preview_exceeds: 'Limit :limit exceeded by :rows', preview_failed: 'Unavailable',
        }),
    };
    const keys = [
        'dialog.ui-native-dialog, .ui-export-designer-modal', '[data-advanced-export-form]', '[data-export-submit]', '[data-export-quick]',
        '[data-export-status]', '[data-export-error]', '[data-export-preset]', '[data-export-format]',
        '[data-export-csv-delimiter-group]', '[data-export-csv-delimiter]', '[data-export-preview]',
        '[data-export-preset-name]', '[data-export-default]', '[data-export-relative]', '[data-export-days]',
        '[data-export-save]', '[data-export-update]', '[data-export-delete]', '[data-export-open]', '[data-export-retry]', '.modal-title',
    ];
    for (const key of keys) root.nodes.set(key, new Element(doc));
    const form = root.querySelector('[data-advanced-export-form]');
    form.action = 'https://portal.test/purchasing/export/purchase-orders';
    const token = new Element(doc); token.value = 'csrf-tok'; form.nodes.set('[name="_token"]', token);
    root.querySelector('[data-export-format]').value = 'xlsx';
    root.querySelector('[data-export-csv-delimiter]').value = ';';
    root.querySelector('[data-export-error]').hidden = true;
    const modal = root.querySelector('dialog.ui-native-dialog, .ui-export-designer-modal');
    const modalCtrl = { async show() { modal.open = true; await modal.emit('adasi:modal-show'); await modal.emit('adasi:modal-shown'); }, async hide() { await modal.emit('adasi:modal-hide'); modal.open = false; await modal.emit('adasi:modal-hidden'); } };
    win.AdasiModal = { getOrCreateInstance: () => modalCtrl };
    win.AdasiAsyncExport = { startExport: async (_target, options) => { exports.push(options.body); return true; } };
    let state;
    const designer = {
        configure(c, s) { state = createColumnState(c, s); },
        setColumns(c) { state = transitionColumns(state, { type: 'preset', columns: c }); },
        reset() { state = transitionColumns(state, { type: 'reset' }); },
        serialize() { return serializeColumns(state); },
        setEnabled() {}, suspend() {}, destroy() {}, focus() {}, getState: () => state,
    };
    createAdvancedExport(root, { designer });
    modal.open = true;
    await modal.emit('adasi:modal-show');
    await modal.emit('adasi:modal-shown');

    // CSV delimiter hidden initially for XLSX
    assert.equal(root.querySelector('[data-export-csv-delimiter-group]').hidden, true);
    // Preview timer scheduled at 400ms
    const activeTimer = timers.filter((t) => !t.cleared).at(-1);
    assert.equal(activeTimer.delay, 400);
    await activeTimer.fn();
    const previewReq = requests.find((r) => r.url === '/exports/preview-count');
    assert.equal(previewReq.init.priority, 'low');
    assert.equal(root.querySelector('[data-export-submit]').disabled, true);
    assert.match(root.querySelector('[data-export-preview]').textContent, /100\.000|100,000/);

    // Switch preview back under limit and choose CSV format with semicolon delimiter
    previewPayload = { total_rows: 12, max_rows: 100000, exceeds_limit: false, is_empty: false };
    root.querySelector('[data-export-format]').value = 'csv';
    await root.querySelector('[data-export-format]').emit('change');
    assert.equal(root.querySelector('[data-export-csv-delimiter-group]').hidden, false);
    assert.equal(root.querySelector('[data-export-csv-delimiter]').value, ';');
    await form.emit('change', { target: { dataset: { filterName: 'status' } } });
    await timers.filter((t) => !t.cleared).at(-1).fn();
    assert.equal(root.querySelector('[data-export-submit]').disabled, false);

    await form.emit('submit');
    assert.deepEqual(exports[0].options, { columns: ['po_number', 'status', 'remark'], format: 'csv', csv_delimiter: ';' });
});

test('relative date range panel stays hidden until checked and quick day presets update days, aria-pressed, and preview', async () => {
    const timers = [];
    const requests = [];
    const exports = [];
    const doc = { documentElement: { lang: 'en' }, querySelector: () => null, createElement: () => new Element(doc), addEventListener() {}, removeEventListener() {} };
    const win = {
        location: { href: 'https://portal.test/purchasing/purchase-orders?po_number=PO%2FLEGACY%2F9' },
        AbortController,
        setTimeout: (fn, delay) => { const id = timers.length + 1; timers.push({ id, fn, delay, cleared: false }); return id; },
        clearTimeout: (id) => { const t = timers.find((item) => item.id === id); if (t) t.cleared = true; },
        fetch: async (url, init) => {
            requests.push({ url, init });
            if (url.includes('/definitions/')) return response({ ...definition(), filters: [{ name: 'start_date', type: 'date' }, { name: 'end_date', type: 'date' }, { name: 'search', type: 'text' }] });
            if (url.includes('/preview-count')) return response({ total_rows: 15, max_rows: 100000, exceeds_limit: false, is_empty: false });
            return response({ data: [], personal: [], team: [], can_manage_team: false });
        },
    };
    doc.defaultView = win;
    const root = new Element(doc);
    root.dataset = {
        exportKey: 'purchasing.po',
        definitionUrl: '/definitions/purchasing.po',
        presetsUrl: '/presets',
        presetStoreUrl: '/presets',
        previewUrl: '/exports/preview-count',
        defaultCsvDelimiter: ',',
        filterSelectors: '{}',
        initialFilters: '{}',
        exportCopy: JSON.stringify({
            failed: 'Failed', invalid_columns: 'Invalid', save_failed: 'Save failed', saved: 'Saved',
            loading: 'Loading', default_columns: 'Default', preview_loading: 'Counting...',
            preview_summary: ':rows rows · :columns cols · :format', preview_empty: '0 rows', preview_exceeds: 'Too many', preview_failed: 'Unavailable',
        }),
    };
    const keys = [
        'dialog.ui-native-dialog, .ui-export-designer-modal', '[data-advanced-export-form]', '[data-export-submit]', '[data-export-quick]',
        '[data-export-status]', '[data-export-error]', '[data-export-preset]', '[data-export-format]',
        '[data-export-preset-name]', '[data-export-default]', '[data-export-relative]', '[data-export-relative-panel]',
        '[data-export-days]', '[data-export-preview]', '[data-export-save]', '[data-export-update]', '[data-export-delete]',
        '[data-export-open]', '[data-export-retry]', '.modal-title',
    ];
    for (const key of keys) root.nodes.set(key, new Element(doc));
    const presetButtons = [7, 30, 90].map((days) => {
        const btn = new Element(doc);
        btn.dataset = { exportDaysPreset: String(days) };
        btn.disabled = true;
        return btn;
    });
    root.lists.set('[data-export-days-preset]', presetButtons);

    const form = root.querySelector('[data-advanced-export-form]');
    form.action = 'https://portal.test/purchasing/export/purchase-orders';
    const token = new Element(doc); token.value = 'csrf-tok'; form.nodes.set('[name="_token"]', token);
    const startInput = new Element(doc); startInput.dataset = { filterName: 'start_date' };
    const endInput = new Element(doc); endInput.dataset = { filterName: 'end_date' };
    const searchInput = new Element(doc); searchInput.dataset = { filterName: 'search' };
    form.lists.set('[data-filter-name]', [startInput, endInput, searchInput]);
    form.lists.set('[data-filter-name="start_date"], [data-filter-name="end_date"]', [startInput, endInput]);
    root.querySelector('[data-export-format]').value = 'xlsx';
    root.querySelector('[data-export-error]').hidden = true;
    const modal = root.querySelector('dialog.ui-native-dialog, .ui-export-designer-modal');
    const modalCtrl = { async show() { modal.open = true; await modal.emit('adasi:modal-show'); await modal.emit('adasi:modal-shown'); }, async hide() { await modal.emit('adasi:modal-hide'); modal.open = false; await modal.emit('adasi:modal-hidden'); } };
    win.AdasiModal = { getOrCreateInstance: () => modalCtrl };
    win.AdasiAsyncExport = { startExport: async (_target, options) => { exports.push(options.body); return true; } };
    let state;
    const designer = {
        configure(c, s) { state = createColumnState(c, s); },
        setColumns(c) { state = transitionColumns(state, { type: 'preset', columns: c }); },
        reset() { state = transitionColumns(state, { type: 'reset' }); },
        serialize() { return serializeColumns(state); },
        setEnabled() {}, suspend() {}, destroy() {}, focus() {}, getState: () => state,
    };
    createAdvancedExport(root, { designer });
    modal.open = true;
    await modal.emit('adasi:modal-show');
    await modal.emit('adasi:modal-shown');

    // Legacy URL ?po_number=... hydrates unified search input.
    assert.equal(searchInput.value, 'PO/LEGACY/9');
    // Relative panel stays hidden until checked.
    assert.equal(root.querySelector('[data-export-relative-panel]').hidden, true);
    assert.equal(root.querySelector('[data-export-relative]').attributes.get('aria-expanded'), 'false');
    assert.equal(startInput.disabled, false);
    assert.equal(presetButtons[0].disabled, true);

    // Check relative date range -> panel visible, start/end disabled, 30-day chip pressed.
    root.querySelector('[data-export-relative]').checked = true;
    await root.querySelector('[data-export-relative]').emit('change');
    assert.equal(root.querySelector('[data-export-relative-panel]').hidden, false);
    assert.equal(root.querySelector('[data-export-relative]').attributes.get('aria-expanded'), 'true');
    assert.equal(startInput.disabled, true);
    assert.equal(endInput.disabled, true);
    assert.equal(presetButtons[1].attributes.get('aria-pressed'), 'true');
    assert.equal(presetButtons[2].attributes.get('aria-pressed'), 'false');

    // Click 90-day chip -> updates days, aria-pressed, preview payload, and submit payload.
    await presetButtons[2].emit('click');
    assert.equal(root.querySelector('[data-export-days]').value, '90');
    assert.equal(presetButtons[1].attributes.get('aria-pressed'), 'false');
    assert.equal(presetButtons[2].attributes.get('aria-pressed'), 'true');
    await timers.filter((t) => !t.cleared).at(-1).fn();
    const previewBody = JSON.parse(requests.filter((r) => r.url === '/exports/preview-count').at(-1).init.body);
    assert.deepEqual(previewBody.date_range, { mode: 'relative', days: 90 });

    await form.emit('submit');
    assert.deepEqual(exports[0].date_range, { mode: 'relative', days: 90 });
    assert.equal(exports[0].search, 'PO/LEGACY/9');
});

test('export submit marks form managed, renders a single spinner preserving button label, and leaves zero spinners when reopened', async () => {
    const h = harness();
    const form = h.el('[data-advanced-export-form]');
    const submit = h.el('[data-export-submit]');

    // Realistic child DOM tracking for submit button & label span
    const labelSpan = new Element(h.doc);
    labelSpan.textContent = 'Export';
    submit.children = [labelSpan];
    const attach = (child, prepend = false) => {
        child.parentElement = submit;
        child.remove = () => {
            submit.children = submit.children.filter((item) => item !== child);
        };
        if (prepend) submit.children.unshift(child);
        else submit.children.push(child);
    };
    submit.append = (child) => attach(child, false);
    submit.prepend = (child) => attach(child, true);
    submit.querySelector = (sel) => {
        if (sel === 'span:not([data-export-busy-icon])' || sel === 'span') {
            return submit.children.find((node) => !Object.hasOwn(node.dataset, 'exportBusyIcon')) || null;
        }
        if (sel === '[data-export-busy-icon]') {
            return submit.children.find((node) => Object.hasOwn(node.dataset, 'exportBusyIcon')) || null;
        }
        return null;
    };
    submit.querySelectorAll = (sel) => sel === '.ui-spinner'
        ? submit.children.filter((node) => String(node.className || '').split(/\s+/).includes('ui-spinner'))
        : [];

    assert.equal(form.attributes.has('data-managed-submit'), true);
    assert.equal(form.attributes.has('data-no-loader'), true);

    await h.open();
    const inflight = deferred();
    h.sdk(() => inflight.promise);

    const exportPromise = form.emit('submit');
    await Promise.resolve();

    // During dispatch: exactly 1 spinner (on the left), accessible label stays visible, aria-busy is true
    const activeSpinners = submit.querySelectorAll('.ui-spinner');
    assert.equal(activeSpinners.length, 1);
    assert.equal(submit.children[0], activeSpinners[0]);
    assert.equal(submit.attributes.get('aria-busy'), 'true');
    assert.equal(labelSpan.style.display, undefined);

    // Resolve export -> modal closes -> spinner removed cleanly
    inflight.resolve(true);
    await exportPromise;
    assert.equal(Boolean(h.modal.open), false);
    assert.equal(submit.querySelectorAll('.ui-spinner').length, 0);
    assert.equal(submit.attributes.has('aria-busy'), false);

    // Reopen Advanced Export even if a stray spinner were injected externally -> cleaned to 0 spinners
    const straySpinner = new Element(h.doc);
    straySpinner.className = 'ui-spinner';
    submit.append(straySpinner);
    await h.open();
    assert.equal(submit.querySelectorAll('.ui-spinner').length, 0);
    assert.equal(submit.attributes.has('aria-busy'), false);
    assert.equal(submit.disabled, false);
});

test('column designer aligns Column Menu directly below trigger button and flips above when bottom space overflows', () => {
    const docListeners = new Map();
    const winListeners = new Map();
    const doc = {
        activeElement: null,
        addEventListener: (name, fn) => docListeners.set(name, fn),
        removeEventListener: (name) => docListeners.delete(name),
        createElement: () => new Element(doc),
    };
    const win = {
        innerWidth: 1366,
        innerHeight: 768,
        visualViewport: { offsetLeft: 0, offsetTop: 0, width: 1366, height: 768, addEventListener() {}, removeEventListener() {} },
        addEventListener: (name, fn) => winListeners.set(name, fn),
        removeEventListener: (name) => winListeners.delete(name),
        clearTimeout() {},
        setTimeout: () => 1,
    };
    doc.defaultView = win;

    const makeNode = (overrides = {}) => {
        const node = new Element(doc);
        node.style = { setProperty() {}, removeProperty() {} };
        node.getAttribute = (k) => node.attributes.get(k) ?? null;
        node.closest = () => null;
        node.contains = (target) => target === node;
        node.insertBefore = (child) => { if (!node.children.includes(child)) node.children.push(child); return child; };
        node.cloneNode = () => {
            const copy = makeNode();
            const letter = makeNode();
            const label = makeNode();
            const req = makeNode();
            copy.nodes.set('[data-column-letter]', letter);
            copy.nodes.set('[data-column-label]', label);
            copy.nodes.set('[data-column-required]', req);
            return copy;
        };
        return Object.assign(node, overrides);
    };

    const root = makeNode();
    const host = makeNode();
    host.querySelectorAll = (sel) => (sel === '[data-export-column]' ? host.children : []);
    const scroll = makeNode();
    const emptyRows = makeNode();
    const template = { content: { firstElementChild: makeNode() } };
    const fieldset = makeNode();
    const menuItem = makeNode({ disabled: false });
    const menu = makeNode({
        hidden: true,
        getBoundingClientRect: () => ({ width: 200, height: 180, left: 0, top: 0, right: 200, bottom: 180 }),
    });
    menu.nodes.set('[role="menuitem"]:not(:disabled)', menuItem);
    const unhide = makeNode({
        hidden: true,
        getBoundingClientRect: () => ({ width: 336, height: 160, left: 0, top: 0, right: 336, bottom: 160 }),
    });
    const hiddenSelect = makeNode();
    const modal = makeNode();

    const menuTrigger = makeNode({
        dataset: { columnCommand: 'menu' },
        getBoundingClientRect: () => ({ left: 684, top: 210, right: 806, bottom: 244, width: 122, height: 34 }),
    });
    menuTrigger.closest = (sel) => (sel === '[data-column-command]' ? menuTrigger : null);

    root.contains = (target) => target === root || target === menuTrigger || target === menu;
    root.nodes.set('[data-export-columns]', host);
    root.nodes.set('[data-column-scroll]', scroll);
    root.nodes.set('[data-column-empty-rows]', emptyRows);
    root.nodes.set('[data-export-column-template]', template);
    root.nodes.set('[data-column-designer]', fieldset);
    root.nodes.set('[data-column-menu]', menu);
    root.nodes.set('[data-column-unhide]', unhide);
    root.nodes.set('[data-column-hidden-select]', hiddenSelect);
    root.nodes.set('dialog.ui-native-dialog, .ui-export-designer-modal', modal);
    root.lists.set('[data-column-command]', [menuTrigger]);
    root.lists.set('[data-column-command="menu"], [data-column-command="unhide"]', [menuTrigger]);
    root.lists.set('[data-column-command="menu"]', [menuTrigger]);

    const designer = createColumnDesigner(root, { text: (key) => key });
    designer.configure(catalog);
    designer.setEnabled(true);

    // 1. Standard position: aligned flush with trigger's left edge and 4px below trigger bottom
    root.emit('click', { target: menuTrigger });
    assert.equal(menu.hidden, false);
    assert.equal(menu.style.left, '684px');
    assert.equal(menu.style.top, '248px');

    // 2. Near bottom of viewport: flips cleanly above trigger (top - menuHeight - 4)
    menuTrigger.getBoundingClientRect = () => ({ left: 684, top: 650, right: 806, bottom: 684, width: 122, height: 34 });
    root.emit('click', { target: menuTrigger });
    assert.equal(menu.style.left, '684px');
    assert.equal(menu.style.top, '466px');

    designer.destroy();
});
