import test from 'node:test';
import assert from 'node:assert/strict';
import { createAdvancedExport } from '../../resources/js/advanced-export.js';
import { createColumnState, transitionColumns, serializeColumns } from '../../resources/js/export-column-designer.js';

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
    constructor(doc) { this.ownerDocument = doc; this.listeners = new Map(); this.dataset = {}; this.attributes = new Map(); this.value = ''; this.checked = false; this.disabled = false; this.hidden = false; this.children = []; this.style = { removeProperty() {} }; this.nodes = new Map(); this.classList = { values: new Set(), contains: (x) => this.classList.values.has(x), add: (x) => this.classList.values.add(x), remove: (x) => this.classList.values.delete(x) }; }
    addEventListener(name, fn) { if (!this.listeners.has(name)) this.listeners.set(name, new Set()); this.listeners.get(name).add(fn); }
    removeEventListener(name, fn) { this.listeners.get(name)?.delete(fn); }
    async emit(name, extra = {}) { const event = { target: this, preventDefault() {}, ...extra }; await Promise.all([...(this.listeners.get(name) || [])].map((fn) => fn(event))); }
    setAttribute(k, v) { this.attributes.set(k, v); }
    removeAttribute(k) { this.attributes.delete(k); }
    querySelector(k) { if (k === 'span') return null; if (k === '[data-export-busy-icon]') return this.children.find((x) => Object.hasOwn(x.dataset, 'exportBusyIcon')); return this.nodes.get(k) || null; }
    querySelectorAll() { return []; }
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
    const selectors = ['.modal', '[data-advanced-export-form]', '[data-export-submit]', '[data-export-quick]', '[data-export-status]', '[data-export-error]', '[data-export-preset]', '[data-export-format]', '[data-export-preset-name]', '[data-export-default]', '[data-export-relative]', '[data-export-days]', '[data-export-save]', '[data-export-update]', '[data-export-delete]', '[data-export-open]', '[data-export-retry]', '.modal-title'];
    for (const key of selectors) root.nodes.set(key, new Element(doc));
    const form = root.querySelector('[data-advanced-export-form]'); form.action = 'https://portal.test/purchasing/export/purchase-orders'; const token = new Element(doc); token.value = 'token'; form.nodes.set('[name="_token"]', token);
    root.querySelector('[data-export-format]').value = 'xlsx';
    root.querySelector('[data-export-error]').hidden = true;
    const modal = root.querySelector('.modal');
    const bs = { async show() { modal.classList.add('show'); await modal.emit('show.bs.modal'); await modal.emit('shown.bs.modal'); }, async hide() { await modal.emit('hide.bs.modal'); modal.classList.remove('show'); await modal.emit('hidden.bs.modal'); } };
    win.bootstrap = { Modal: { getOrCreateInstance: () => bs } };
    win.AdasiAsyncExport = { startExport: async (target, options) => { exports.push(options.body); return sdk ? sdk(options) : true; } };
    let state; let enabled; let configureCalls = 0;
    const designer = { configure(c, selected) { const next = createColumnState(c, selected); state = next; configureCalls++; }, setColumns(columns) { state = transitionColumns(state, { type: 'preset', columns }); }, reset() { state = transitionColumns(state, { type: 'reset' }); }, serialize() { return serializeColumns(state); }, setEnabled(value) { enabled = value; }, suspend() { enabled = false; }, destroy() {}, focus() {}, getState: () => state };
    const api = createAdvancedExport(root, { designer });
    return { root, api, designer, requests, exports, pending, doc, win, modal, bs, get state() { return state; }, get enabled() { return enabled; }, get configureCalls() { return configureCalls; }, manual() { auto = false; }, sdk(fn) { sdk = fn; }, el: (selector) => root.querySelector(selector), command(command) { state = transitionColumns(state, command); }, async open() { modal.classList.add('show'); await modal.emit('show.bs.modal'); await modal.emit('shown.bs.modal'); }, close: () => bs.hide() };
}

test('controller boot is idempotent and exports the exact ordered visible state with existing request shape', async () => {
    const h = harness(); assert.equal(createAdvancedExport(h.root), h.api);
    await h.open(); h.command({ type: 'hide', key: 'status' }); h.command({ type: 'move-left', key: 'remark' });
    await h.el('[data-advanced-export-form]').emit('submit');
    assert.deepEqual(h.exports, [{ options: { columns: ['remark', 'po_number'], format: 'xlsx' } }]);
    assert.equal(h.configureCalls, 1); assert.equal(h.requests.length, 2); assert.equal(h.modal.classList.contains('show'), false);
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
    assert.equal(h.modal.classList.contains('show'), false); assert.equal(h.el('[data-export-error]').hidden, true); assert.equal(h.configureCalls, 0);
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
    assert.equal(h.modal.classList.contains('show'), true); assert.equal(h.exports.length, 1); assert.equal(h.el('[data-export-submit]').disabled, false); assert.deepEqual(h.designer.serialize(), ['po_number', 'status', 'remark']);
});

test('destroy invalidates in-flight work and releases controller event handlers', async () => {
    const h = harness(); h.manual(); const opening = h.open(); h.api.destroy(); h.pending[0].resolve(response(definition())); h.pending[1].resolve(response({ data: [] })); await opening;
    assert.equal(h.configureCalls, 0); assert.equal(h.modal.listeners.get('show.bs.modal').size, 0);
});

test('fast metadata cannot enable worksheet actions before Bootstrap finishes showing and owns focus', async () => {
    const h = harness(); h.modal.classList.add('show');
    await h.modal.emit('show.bs.modal');
    assert.equal(h.el('[data-export-submit]').disabled, true);
    assert.equal(h.enabled, false);
    await h.modal.emit('shown.bs.modal');
    assert.equal(h.el('[data-export-submit]').disabled, false);
    assert.equal(h.enabled, true);
});

test('destroy also disposes the Bootstrap instance after a closed modal so global resize listeners release the root', async () => {
    const h = harness(); await h.open(); await h.close();
    let disposed = 0; h.win.bootstrap.Modal.getInstance = () => ({ dispose() { disposed++; } });
    h.api.destroy(); h.api.destroy();
    assert.equal(disposed, 1);
});

test('destroy during the opening backdrop waits for shown before hiding and disposing the modal element', async () => {
    const h = harness(); let disposals = 0; let hides = 0;
    h.win.bootstrap.Modal.getInstance = () => ({ dispose() { disposals++; }, hide() { hides++; return h.modal.emit('hidden.bs.modal'); } });
    const opening = h.modal.emit('show.bs.modal');
    h.api.destroy(); assert.equal(disposals, 0);
    await h.modal.emit('shown.bs.modal'); await opening;
    assert.equal(hides, 1); assert.equal(disposals, 1);
});

test('malformed catalog is rejected with localized load feedback and never enables serialization', async () => {
    const h = harness(); h.manual(); const opening = h.open();
    const invalid = definition(); invalid.columns = invalid.columns.map((column) => ({ ...column, default: false }));
    h.pending[0].resolve(response(invalid)); h.pending[1].resolve(response({ data: [] })); await opening;
    assert.equal(h.el('[data-export-error]').textContent, 'Failed');
    assert.equal(h.el('[data-export-submit]').disabled, true); assert.equal(h.configureCalls, 0);
});
