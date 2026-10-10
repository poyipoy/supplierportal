import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { installI18n } from './i18n-fixture.mjs';

const source = readFileSync(new URL('../../resources/js/local-procurement-import.js', import.meta.url), 'utf8')
    .replace("import { t } from './i18n.js';", "const t = (...args) => window.AdasiI18n.t(...args);")
    .replace('export function bootLocalProcurementImports', 'function bootLocalProcurementImports');
class Element {
    children = []; dataset = {}; listeners = {}; value = ''; textContent = ''; disabled = false;
    classes = new Set();
    classList = {
        add: (...names) => names.forEach(name => this.classes.add(name)),
        remove: (...names) => names.forEach(name => this.classes.delete(name)),
        toggle: (name, force) => { if (force) this.classes.add(name); else this.classes.delete(name); },
        contains: name => this.classes.has(name),
    };
    setAttribute(name, value) { this[name] = value; }
    append(...children) { this.children.push(...children); }
    prepend(...children) { this.children.unshift(...children); }
    replaceChildren(...children) { this.children = children; }
    addEventListener(name, listener) { this.listeners[name] = listener; }
    cloneNode() { const clone = new Element(); clone.textContent = this.textContent; return clone; }
    async fire(name) { return this.listeners[name]?.({ preventDefault() {} }); }
}
function boot(locale, kind = 'PO', jobs = null) {
    const name = kind === 'PO' ? 'Po' : 'Gr';
    const elements = new Map();
    const created = [];
    const body = new Element();
    const requests = [];
    const timers = [];
    const responseWaits = new Map();
    let status = 'QUEUED';
    let reloads = 0;
    const get = id => { if (!elements.has(id)) elements.set(id, new Element()); return elements.get(id); };
    const modal = get(`local${name}ImportModal`); modal.querySelector = () => body;
    modal.querySelectorAll = () => [...elements.entries()].filter(([id]) => id.startsWith(`kpi${name}`)).map(([, element]) => element);
    const form = get(`local${name}ImportConfirmForm`); form.querySelector = () => ({ value: 'csrf-token' });
    get(`local${name}ImportFile`).files = [{ name: 'sample.xlsx' }];
    const dropzone = { stagedFiles: ['sample.xlsx'], clientError: 'old error', clearAll() {
        this.stagedFiles = []; this.clientError = '';
        get(`local${name}ImportFile`).files = []; get(`local${name}ImportFile`).value = '';
    } };
    get(`local${name}ImportFile`).closest = () => dropzone;
    const config = new Element();
    config.dataset = { localImport: kind, modalId: `local${name}ImportModal`, importPreview: '/preview', importIndex: '/imports', previous: 'Previous', next: 'Next', cancel: 'Cancel' };
    const document = { readyState: 'loading', hidden: false, addEventListener() {}, querySelectorAll: () => [config], getElementById: get,
        createElement: tag => { const element = new Element(); element.tag = tag; created.push(element); return element; },
        createTextNode: text => ({ textContent: text }) };
    const window = installI18n({ location: { reload() { reloads++; } } }, locale);
    window.Alpine = { $data: element => element };
    const malicious = '<img src=x onerror=alert(1)>';
    let recentJobs = jobs ?? [
        { kind: kind === 'PO' ? 'GR' : 'PO', filename: 'Other kind.xlsx', status: 'READY', status_url: '/imports/other-kind' },
        { kind, filename: malicious, status: 'READY', status_url: '/imports/current' },
        { kind, filename: 'Older file.xlsx', status: 'READY', status_url: '/imports/older' },
    ];
    const row = { _row: 2, po_number: malicious, supplier_name: malicious, po_date: '2026-10-01', po_amount: '1000.00',
        gr_number: malicious, description: 'x" onmouseover="alert(1)', qty: '10.125', uom: 'kg', source_rows_count: 2, action: 'NEW',
        action_label: locale === 'en' ? `New ${kind}` : `${kind} Baru` };
    const payload = () => ({ status, token: status === 'READY' ? 'opaque-token' : null, processed_rows: 70000,
        confirm_url: '/confirm', records_url: '/records', records_total: 70000, errors_total: 1, errors_url: '/errors',
        warnings: [{ message: malicious }], preview: { summary: { source_rows: 70000 }, rows: Array.from({ length: 101 }, () => row), errors: [{ row: 8, column: 'qty', message: malicious }] } });
    const context = { window, document, Set, URLSearchParams, setTimeout: fn => { timers.push(fn); return timers.length; }, clearTimeout() {},
        FormData: class { values = new Map(); constructor(value) { if (value) { this.values.set('token', get(`local${name}ImportToken`).value); this.values.set('_token', 'csrf-token'); } } append(k, v) { this.values.set(k, v); } },
        fetch: async (url, options) => {
            requests.push({ url, options });
            const wait = responseWaits.get(url);
            if (wait) { responseWaits.delete(url); await wait; }
            const response = url === '/preview' ? { status_url: '/imports/current' }
                : url === '/confirm' ? (status = 'IMPORTING', { status_url: '/imports/current' })
                    : url === '/imports' ? recentJobs
                        : payload();
            return { ok: true, json: async () => response };
        } };
    vm.runInNewContext(source, context);
    context.bootLocalProcurementImports(document);
    return { get, created, requests, modal, form, row, timers, malicious, dropzone, setStatus: value => { status = value; }, setJobs: value => { recentJobs = value; }, reloads: () => reloads,
        holdNextResponse: url => { let release; responseWaits.set(url, new Promise(resolve => { release = resolve; })); return release; } };
}
for (const locale of ['en', 'id']) for (const kind of ['PO', 'GR']) {
    test(`${locale} ${kind}: queue progress, safe text, bounded preview and explicit confirmation`, async () => {
        const app = boot(locale, kind);
        const name = kind === 'PO' ? 'Po' : 'Gr';
        await app.get(`btnParseLocal${name}Import`).fire('click');
        assert.equal(app.get(`btnConfirmLocal${name}Import`).disabled, true);
        assert.equal(app.get(`local${name}ImportToken`).value, '');
        app.setStatus('READY'); await app.timers.at(-1)();
        assert.equal(app.get(`btnConfirmLocal${name}Import`).disabled, false);
        assert.equal(app.get(`local${name}ImportToken`).value, 'opaque-token');
        const rows = app.get(`local${name}ImportPreviewBody`).children;
        assert.equal(rows.length, 100);
        assert.equal(rows[0].children[1].textContent, app.malicious);
        assert.equal(rows[0].children.at(-1).textContent, locale === 'en' ? `New ${kind}` : `${kind} Baru`);
        assert.ok(rows[0].children.every(cell => !Object.hasOwn(cell, 'innerHTML')));
        assert.equal(app.get(`local${name}ImportErrorsList`).children[0].textContent, `${locale === 'en' ? 'Row' : 'Baris'} 8 [qty]: ${app.malicious}`);
        assert.equal(app.row.action, 'NEW'); assert.equal(app.row.qty, '10.125');
        await app.form.fire('submit');
        const confirmation = app.requests.find(request => request.url === '/confirm');
        assert.equal(confirmation.options.body.values.get('token'), 'opaque-token');
        assert.equal(app.get(`btnConfirmLocal${name}Import`).disabled, true);
        app.setStatus('COMPLETED'); await app.timers.at(-1)();
        const reload = app.created.find(element => element.textContent === (locale === 'en' ? 'Reload register' : 'Muat ulang daftar'));
        assert.equal(reload.hidden, false); await reload.fire('click'); assert.equal(app.reloads(), 1);
    });

    test(`${locale} ${kind}: opening is fresh without loading previous imports`, async () => {
        const app = boot(locale, kind);
        const name = kind === 'PO' ? 'Po' : 'Gr';
        app.setStatus('READY');
        await app.modal.fire('show.bs.modal');
        assert.equal(app.created.some(element => element.tag === 'select' || element.tag === 'option'), false);
        assert.deepEqual(app.requests, []);
        assert.equal(app.get(`btnConfirmLocal${name}Import`).disabled, true);
        assert.equal(app.get(`local${name}ImportToken`).value, '');
        assert.equal(app.dropzone.clientError, '');
        assert.deepEqual(app.get(`local${name}ImportFile`).files, []);
    });

    test(`${locale} ${kind}: reopening discards the previous active UI without cancelling the backend batch`, async () => {
        const app = boot(locale, kind);
        const name = kind === 'PO' ? 'Po' : 'Gr';
        await app.get(`btnParseLocal${name}Import`).fire('click');
        app.setJobs([{ kind, filename: 'Newer.xlsx', status_url: '/imports/newer' }]);
        const requestCount = app.requests.length;
        await app.modal.fire('hidden.bs.modal');
        await app.modal.fire('show.bs.modal');
        await app.timers.at(-1)();
        assert.equal(app.requests.length, requestCount);
        assert.equal(app.requests.some(request => request.url.endsWith('/cancel')), false);
        assert.equal(app.get(`btnParseLocal${name}Import`).disabled, false);
        assert.equal(app.get(`btnConfirmLocal${name}Import`).disabled, true);
        assert.equal(app.get(`local${name}ImportResult`).classList.contains('d-none'), true);
    });

    test(`${locale} ${kind}: no recent batch leaves new upload available without a dropdown`, async () => {
        const app = boot(locale, kind, []);
        const name = kind === 'PO' ? 'Po' : 'Gr';
        await app.modal.fire('show.bs.modal');
        assert.equal(app.created.some(element => element.tag === 'select'), false);
        assert.deepEqual(app.requests, []);
        assert.equal(app.get(`btnParseLocal${name}Import`).disabled, false);
        app.get(`local${name}ImportFile`).files = [{ name: 'new.xlsx' }];
        await app.get(`btnParseLocal${name}Import`).fire('click');
        assert.ok(app.requests.some(request => request.url === '/preview'));
        assert.equal(app.get(`btnConfirmLocal${name}Import`).disabled, true);
    });
}

test('closing and reopening clears a READY preview, token, messages, counters and selected file', async () => {
    const app = boot('en');
    await app.get('btnParseLocalPoImport').fire('click');
    app.setStatus('READY'); await app.timers.at(-1)();
    assert.equal(app.get('localPoImportToken').value, 'opaque-token');
    await app.modal.fire('hidden.bs.modal');
    await app.modal.fire('show.bs.modal');
    assert.equal(app.get('localPoImportToken').value, '');
    assert.equal(app.get('localPoImportPreviewBody').children.length, 0);
    assert.equal(app.get('localPoImportErrorsList').children.length, 0);
    assert.equal(app.get('kpiPoTotalRows').textContent, '0');
    assert.equal(app.created.find(element => element.role === 'status').textContent, '');
    assert.equal(app.get('btnConfirmLocalPoImport').disabled, true);
    const requests = app.requests.length;
    await app.form.fire('submit');
    assert.equal(app.requests.length, requests);
});

test('a stale poll response cannot restore the old preview after the modal is reset', async () => {
    const app = boot('en');
    await app.get('btnParseLocalPoImport').fire('click');
    app.setStatus('READY');
    const release = app.holdNextResponse('/imports/current');
    const pending = app.timers.at(-1)();
    await app.modal.fire('hidden.bs.modal');
    await app.modal.fire('show.bs.modal');
    release(); await pending;
    assert.equal(app.get('localPoImportToken').value, '');
    assert.equal(app.get('btnConfirmLocalPoImport').disabled, true);
    assert.equal(app.get('localPoImportPreviewBody').children.length, 0);
});

test('a stale upload response cannot attach its batch to a newly opened modal', async () => {
    const app = boot('id', 'GR');
    const release = app.holdNextResponse('/preview');
    const pending = app.get('btnParseLocalGrImport').fire('click');
    await app.modal.fire('hidden.bs.modal');
    await app.modal.fire('show.bs.modal');
    release(); await pending;
    assert.deepEqual(app.requests.map(request => request.url), ['/preview']);
    assert.equal(app.get('btnParseLocalGrImport').disabled, false);
    assert.equal(app.get('btnConfirmLocalGrImport').disabled, true);
});
