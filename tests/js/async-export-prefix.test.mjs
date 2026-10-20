import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';
import { installI18n } from './i18n-fixture.mjs';

const source = await readFile(new URL('../../public/assets/js/async-export.js', import.meta.url), 'utf8');
const origin = 'https://portal.test';
const storageKey = 'adasi:pending-export-jobs:v1';
const prefixes = ['', 'release_9/ADASI-TST'];

function boot(prefix) {
    let now = 1700000000000;
    const store = new Map();
    const listeners = {};
    const calls = { requests: [], confirmations: [], timers: [], updates: [] };
    const portalPath = path => `${prefix ? `/${prefix}` : ''}${path}`;

    class Element {
        constructor(href, marked = false) {
            this.href = href;
            this.dataset = {};
            this.marked = marked;
            this.classList = { add() {}, remove() {} };
        }
        matches() { return this.marked; }
        closest(selector) { return selector === 'a[href]' ? this : null; }
        setAttribute() {}
        removeAttribute() {}
        hasAttribute() { return false; }
    }
    class Form extends Element {
        constructor(action, marked = false) {
            super(undefined, marked);
            this.action = action;
        }
        querySelector() { return null; }
    }
    class FormData {
        entries() { return [['_token', 'csrf'], ['status', 'active']]; }
    }
    class Clock extends Date {
        static now() { return now; }
    }
    const window = {
        location: { origin },
        ...(prefix === undefined ? {} : { AdasiPortal: { pathPrefix: prefix } }),
        crypto: { randomUUID: () => 'prefix-test-toast' },
        localStorage: {
            getItem: key => store.get(key) ?? null,
            setItem: (key, value) => store.set(key, String(value)),
            removeItem: key => store.delete(key),
        },
        AdasiAlert: {
            async confirm(options) {
                calls.confirmations.push(options);
                return { isConfirmed: true };
            },
        },
        AdasiToast: {
            progress: options => options.id,
            show: options => options.id,
            update(id, changes) { calls.updates.push(changes); return true; },
        },
        addEventListener() {},
        setTimeout(callback, delay) { calls.timers.push({ callback, delay }); return calls.timers.length; },
        clearTimeout() {},
        async fetch(url, init) {
            calls.requests.push({ url: String(url), init });
            return {
                ok: true,
                json: async () => init.method === 'POST' ? {
                    export_job_id: 'job-1',
                    status_url: origin + portalPath('/purchasing/export/status/job-1'),
                    exports_url: origin + portalPath('/exports'),
                } : {
                    status: 'processing', stage: 'generating', progress: 10,
                    processed_rows: 1, total_rows: 10,
                },
            };
        },
    };
    const document = {
        documentElement: { dataset: {} },
        addEventListener(name, callback) { listeners[name] = callback; },
        querySelector() { return null; },
    };
    installI18n(window);
    vm.runInNewContext(source, {
        window, document, URL, Element, HTMLFormElement: Form, FormData,
        HTMLButtonElement: class {}, HTMLInputElement: class {}, Date: Clock,
    });

    return {
        window, calls, store, Element, Form, portalPath,
        advance(milliseconds) { now += milliseconds; },
        async dispatch(type, control, overrides = {}) {
            const event = {
                target: control, button: 0, prevented: false, stopped: false,
                preventDefault() { this.prevented = true; },
                stopImmediatePropagation() { this.stopped = true; },
                ...overrides,
            };
            listeners[type](event);
            await new Promise(resolve => setImmediate(resolve));
            return event;
        },
        async poll() {
            const timer = calls.timers.shift();
            assert.ok(timer, 'an active export should schedule polling');
            await timer.callback();
        },
    };
}

for (const prefix of prefixes) {
    const mode = prefix || 'no prefix';

    for (const path of [
        '/purchasing/export/purchase-orders',
        '/supplier/export/quotations',
        '/qc/export/inspections',
        '/supplier/price-history/export/',
    ]) {
        test(`unmarked export links are confirmed and dispatched with ${mode}: ${path}`, async () => {
            const app = boot(prefix);
            const href = app.portalPath(path) + '?status=active';
            const event = await app.dispatch('click', new app.Element(href));
            assert.equal(event.prevented, true);
            assert.equal(app.calls.confirmations.length, 1);
            assert.equal(app.calls.requests.length, 1);
            assert.equal(app.calls.requests[0].url, origin + href);
            assert.equal(app.calls.requests[0].init.method, 'POST');
        });
    }

    test(`unmarked export forms are intercepted and retain filters with ${mode}`, async () => {
        const app = boot(prefix);
        const action = app.portalPath('/purchasing/export/requisitions');
        const event = await app.dispatch('submit', new app.Form(action));
        assert.equal(event.prevented, true);
        assert.equal(event.stopped, true);
        assert.equal(app.calls.requests[0].url, origin + action + '?status=active');
        assert.equal(app.calls.requests[0].init.credentials, 'same-origin');
    });

    test(`foreign exports, ordinary links and modified clicks are untouched with ${mode}`, async () => {
        const app = boot(prefix);
        for (const href of [
            'https://external.test' + app.portalPath('/purchasing/export/purchase-orders'),
            app.portalPath('/purchasing/purchase-orders'),
            app.portalPath('/supplier/price-history/exported'),
            app.portalPath('/purchasing/export'),
        ]) {
            const event = await app.dispatch('click', new app.Element(href));
            assert.equal(event.prevented, false, href);
        }
        for (const modifiers of [{ ctrlKey: true }, { metaKey: true }, { shiftKey: true }, { altKey: true }, { button: 1 }]) {
            const event = await app.dispatch('click', new app.Element(app.portalPath('/qc/export/inspections')), modifiers);
            assert.equal(event.prevented, false);
        }
        assert.equal(app.calls.requests.length, 0);
        assert.equal(app.calls.confirmations.length, 0);
    });

    for (const path of [
        '/purchasing/export/requisitions', '/purchasing/export/purchase-orders',
        '/supplier/export/quotations', '/supplier/export/shipments',
        '/qc/export/inspections', '/finance/master-invoices/export',
        '/accounting/reports/export', '/supplier/price-history/export/',
    ]) {
        test(`CSV progress extends monitoring and idle timeout still expires with ${mode}: ${path}`, async () => {
            const app = boot(prefix);
            assert.equal(await app.window.AdasiAsyncExport.startExport(null, {
                url: app.portalPath(path) + '?status=active', body: { options: { format: 'csv' } },
            }), true);
            app.advance(600000);
            app.window.AdasiAsyncExport.handleProgress({
                export_job_id: 'job-1', status: 'processing', stage: 'generating',
                progress: 10, processed_rows: 1, total_rows: 10,
            });
            app.advance(120000);
            await app.poll();
            assert.equal(app.calls.requests.length, 2, 'progress should keep CSV polling beyond eleven minutes from dispatch');
            assert.equal(JSON.parse(app.store.get(storageKey)).length, 1);
            app.advance(660000);
            await app.poll();
            assert.equal(app.calls.requests.length, 2, 'eleven minutes without progress should stop monitoring');
            assert.equal(JSON.parse(app.store.get(storageKey)).length, 0);
        });
    }

    test(`XLSX keeps the original absolute timeout with ${mode}`, async () => {
        const app = boot(prefix);
        await app.window.AdasiAsyncExport.startExport(null, {
            url: app.portalPath('/purchasing/export/purchase-orders'), body: { options: { format: 'xlsx' } },
        });
        app.advance(600000);
        app.window.AdasiAsyncExport.handleProgress({
            export_job_id: 'job-1', status: 'processing', stage: 'generating', processed_rows: 1,
        });
        app.advance(120000);
        await app.poll();
        assert.equal(app.calls.requests.length, 1);
        assert.equal(JSON.parse(app.store.get(storageKey)).length, 0);
    });
}

test('the SDK works without the optional server prefix payload', async () => {
    const app = boot(undefined);
    const event = await app.dispatch('click', new app.Element('/purchasing/export/purchase-orders'));
    assert.equal(event.prevented, true);
    assert.equal(app.calls.requests.length, 1);
});

test('prefix removal requires a segment boundary for interception and CSV monitoring', async () => {
    const app = boot('release_9/ADASI-TST');
    for (const path of [
        '/release_9/ADASI-TST-extra/purchasing/export/purchase-orders',
        '/release_9/ADASI-TSTextra/supplier/price-history/export',
        '/other/release_9/ADASI-TST/qc/export/inspections',
    ]) {
        const event = await app.dispatch('click', new app.Element(path));
        assert.equal(event.prevented, false, path);
    }
    const started = await app.window.AdasiAsyncExport.startExport(null, {
        url: '/release_9/ADASI-TST-extra/purchasing/export/purchase-orders', body: { options: { format: 'csv' } },
    });
    assert.equal(started, true);
    app.advance(600000);
    app.window.AdasiAsyncExport.handleProgress({
        export_job_id: 'job-1', status: 'processing', stage: 'generating', processed_rows: 1,
    });
    app.advance(120000);
    await app.poll();
    assert.equal(app.calls.requests.length, 1, 'a different path segment must not gain the CSV progress timeout');
});

test('direct export dispatch rejects a foreign origin even when its path matches', async () => {
    const app = boot('release_9/ADASI-TST');
    assert.equal(await app.window.AdasiAsyncExport.startExport(null, {
        url: 'https://external.test' + app.portalPath('/purchasing/export/purchase-orders'),
        body: { options: { format: 'csv' } },
    }), false);
    assert.equal(app.calls.requests.length, 0);
});
