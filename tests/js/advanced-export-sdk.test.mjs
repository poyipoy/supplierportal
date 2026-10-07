import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';
import { installI18n } from './i18n-fixture.mjs';

const source = await readFile(new URL('../../public/assets/js/async-export.js', import.meta.url), 'utf8');
function boot(fail = false) {
    const requests = [];
    const listeners = {};
    const store = new Map();
    class Form { constructor() { this.action = 'https://portal.test/supplier/export/purchase-orders'; this.dataset = {}; } hasAttribute() { return true; } }
    const window = {
        location: { origin: 'https://portal.test' }, crypto: { randomUUID: () => 'toast-id' },
        localStorage: { getItem: (k) => store.get(k) || null, setItem: (k, v) => store.set(k, v), removeItem: (k) => store.delete(k) },
        AdasiToast: { progress: (o) => o.id, update() {}, show() {} },
        addEventListener() {}, setTimeout() {},
        fetch: async (url, init) => { requests.push({ url, init }); return { ok: !fail, status: fail ? 422 : 202, json: async () => fail ? { errors: { columns: ['Choose allowed columns.'] } } : { export_job_id: `job-${requests.length}`, status_url: '/exports/hash/status', exports_url: '/exports' } }; },
    };
    const document = { documentElement: { dataset: {} }, addEventListener: (n, cb) => { listeners[n] = cb; }, querySelector: () => ({ content: 'test-token' }) };
    installI18n(window);
    vm.runInNewContext(source, { window, document, URL, Set, Map, HTMLFormElement: Form, HTMLButtonElement: class {}, HTMLInputElement: class {}, FormData: class {} });
    return { window, requests, listeners, form: new Form() };
}
test('advanced POST uses CSRF/JSON and the existing progress SDK without navigation', async () => {
    const app = boot();
    const body = { status: 'active', options: { columns: ['po_number'], format: 'csv' } };
    assert.equal(await app.window.AdasiAsyncExport.startExport(app.form, { url: app.form.action, body }), true);
    assert.equal(app.requests[0].init.method, 'POST');
    assert.equal(app.requests[0].init.headers['X-CSRF-TOKEN'], 'test-token');
    assert.deepEqual(JSON.parse(app.requests[0].init.body), body);
    assert.equal(app.requests[0].init.credentials, 'same-origin');
    assert.equal(await app.window.AdasiAsyncExport.startExport(app.form, { url: app.form.action, body }), false);
    assert.equal(app.requests.length, 1);
});
test('different options have distinct request identities and cross-origin requests are rejected', async () => {
    const app = boot();
    for (const format of ['csv', 'xlsx']) assert.equal(await app.window.AdasiAsyncExport.startExport(app.form, { url: app.form.action, body: { options: { columns: ['po_number'], format } } }), true);
    assert.equal(app.requests.length, 2);
    assert.equal(await app.window.AdasiAsyncExport.startExport(app.form, { url: 'https://foreign.test/', body: {} }), false);
    assert.equal(app.requests.length, 2);
});
test('validation failure is returned to the modal and advanced forms skip legacy confirmation', async () => {
    const app = boot(true);
    let message = '';
    assert.equal(await app.window.AdasiAsyncExport.startExport(app.form, { url: app.form.action, body: { options: {} }, onError: (m) => { message = m; } }), false);
    assert.equal(message, 'Choose allowed columns.');
    let prevented = false;
    app.listeners.submit({ target: app.form, preventDefault() { prevented = true; }, stopImmediatePropagation() { prevented = true; } });
    assert.equal(prevented, false);
});
