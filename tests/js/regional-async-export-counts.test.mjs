import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const source = await readFile(new URL('../../public/assets/js/async-export.js', import.meta.url), 'utf8');
const storageKey = 'adasi:pending-export-jobs:v1';
const origin = 'https://portal.test';
const counts = [0, 1, 999, 1000, 1234567];

function boot(numberFormat, records = []) {
    const store = new Map();
    if (records.length) store.set(storageKey, JSON.stringify(records));
    const calls = { updates: [], progress: [], shown: [], confirmations: [], requests: [], display: [], timers: [] };
    const documentEvents = {};
    const displayNumber = (text, profile) => {
        calls.display.push({ text, profile });
        const locale = numberFormat === 'indonesian' ? 'id-ID' : 'en-US';
        return Number(text).toLocaleString(locale);
    };
    class Form {
        constructor(dataset = {}) {
            this.dataset = dataset;
            this.action = origin + '/purchasing/export/report';
        }
        matches() { return true; }
        querySelector() { return button; }
    }
    class FormDataStub { entries() { return []; } }
    const button = {
        dataset: {}, disabled: false, attributes: {}, classes: new Set(),
        classList: { add(value) { button.classes.add(value); }, remove(value) { button.classes.delete(value); } },
        setAttribute(name, value) { button.attributes[name] = String(value); },
        removeAttribute(name) { delete button.attributes[name]; },
    };
    const localStorage = {
        getItem(key) { return store.has(key) ? store.get(key) : null; },
        setItem(key, value) { store.set(key, String(value)); },
        removeItem(key) { store.delete(key); },
    };
    const window = {
        location: { origin },
        crypto: { randomUUID: () => 'test-id' },
        localStorage,
        AdasiPreferences: { regional: { number_format: numberFormat }, displayNumber },
        AdasiToast: {
            progress(options) { calls.progress.push(options); return options.id || 'progress-toast'; },
            update(id, changes) { calls.updates.push({ id, changes }); return true; },
            show(options) { calls.shown.push(options); return options.id || 'toast'; },
        },
        AdasiAlert: {
            confirm(options) {
                calls.confirmations.push(options);
                return Promise.resolve({ isConfirmed: true });
            },
        },
        addEventListener() {},
        setTimeout(callback, delay) { calls.timers.push({ callback, delay }); return calls.timers.length; },
        clearTimeout() {},
        async fetch(url, init) {
            calls.requests.push({ url: String(url), init });
            if (String(url).includes('/cancel/')) {
                return { ok: false, status: 500, json: async () => ({ message: 'cancel failed in test' }) };
            }
            return {
                ok: true,
                status: 200,
                json: async () => ({
                    export_job_id: 'job-1',
                    status_url: origin + '/purchasing/export/status/job-1',
                    cancel_url: origin + '/purchasing/export/cancel/job-1',
                    exports_url: origin + '/exports',
                }),
            };
        },
    };
    const document = {
        documentElement: { dataset: {} },
        body: { appendChild() {} },
        addEventListener(name, callback) { documentEvents[name] = callback; },
        querySelector() { return null; },
        createElement() { return { click() {}, remove() {} }; },
    };
    const context = vm.createContext({
        window, document, URL, FormData: FormDataStub,
        HTMLFormElement: Form, HTMLButtonElement: class {}, HTMLInputElement: class {},
        Set, Map, console,
    });
    vm.runInContext("Number.prototype.toLocaleString = function () { return Intl.NumberFormat('en-US').format(Number(this.valueOf())); };", context);
    vm.runInContext(source, context);
    return { window, document, documentEvents, calls, store, button, Form };
}

async function flush() {
    await new Promise(resolve => setImmediate(resolve));
    await Promise.resolve();
    await new Promise(resolve => setImmediate(resolve));
}

async function submit(app, dataset = {}) {
    const form = new app.Form(dataset);
    app.documentEvents.submit({ target: form, preventDefault() {}, stopImmediatePropagation() {} });
    await flush();
    return form;
}

function latestUpdate(app) {
    assert.ok(app.calls.updates.length, 'expected a progress toast update');
    return app.calls.updates[app.calls.updates.length - 1].changes;
}

function expectedCount(value, preset) {
    return preset === 'system' ? value.toLocaleString('en-US')
        : preset === 'indonesian' ? value.toLocaleString('id-ID')
            : value.toLocaleString('en-US');
}

test('System mode keeps the browser toLocaleString output for visible progress and confirmation', async () => {
    const app = boot('system');
    await submit(app, { exportSourceCount: '1234567', exportSourcePlural: 'records', exportRowLabel: 'rows' });
    app.window.AdasiAsyncExport.handleProgress({
        export_job_id: 'job-1', status: 'processing', stage: 'generating', progress: 44.6,
        processed_rows: 1234567, total_rows: 2000000,
    });

    const update = latestUpdate(app);
    assert.equal(update.message, 'Processed ' + expectedCount(1234567, 'system') + ' of ' + expectedCount(2000000, 'system') + ' rows.');
    assert.equal(update.progressLabel, expectedCount(1234567, 'system') + ' of ' + expectedCount(2000000, 'system') + ' rows');
    assert.equal(app.calls.confirmations[0].text.includes(expectedCount(1234567, 'system')), true);
    assert.equal(update.progress, 45);
    assert.equal(typeof update.progress, 'number');
    assert.equal(app.calls.display.length, 0);
});

test('System mode preserves finalizing, cancel/retry and restored toast text', async () => {
    const app = boot('system');
    await submit(app);
    app.window.AdasiAsyncExport.handleProgress({
        export_job_id: 'job-1', status: 'processing', stage: 'finalizing', progress: 99.8,
        processed_rows: 1, total_rows: 1,
    });

    const finalizing = latestUpdate(app);
    assert.equal(finalizing.message, 'All 1 row is processed. Finalizing the file.');
    assert.equal(finalizing.progressLabel, '1 of 1 row');
    await finalizing.actions.find(action => action.label === 'Cancel').onClick();
    assert.equal(latestUpdate(app).message, 'All 1 row is processed. Finalizing the file.');
    assert.equal(latestUpdate(app).progressLabel, '1 of 1 row');

    const raw = {
        exportJobId: 'restored-system-job', statusUrl: origin + '/purchasing/export/status/restored-system-job',
        startedAt: Date.now(), toastId: 'restored-system-toast', exportsUrl: origin + '/exports', cancelUrl: null,
        status: 'processing', stage: 'generating', progress: 40, processedRows: 1234567, totalRows: 2000000,
        message: 'Processed ' + expectedCount(1234567, 'system') + ' of ' + expectedCount(2000000, 'system') + ' rows.',
        rowLabel: 'rows', progressDismissed: false, terminalDismissed: false, terminalToastId: null,
    };
    const restored = boot('system', [raw]);
    assert.equal(restored.calls.progress[0].message, raw.message);
    assert.equal(restored.calls.progress[0].progressLabel, expectedCount(1234567, 'system') + ' of ' + expectedCount(2000000, 'system') + ' rows');
    assert.equal(JSON.parse(restored.store.get(storageKey))[0].message, raw.message);
    assert.equal(restored.calls.display.length, 0);
});

for (const preset of ['international', 'indonesian']) {
    test(preset + ' formats only the normal progress and confirmation text', async () => {
        const app = boot(preset);
        await submit(app, {
            exportSourceCount: '1234567', exportSourcePlural: 'records',
            exportSourceSingular: 'record', exportRowLabel: 'rows',
        });
        app.window.AdasiAsyncExport.handleProgress({
            export_job_id: 'job-1', status: 'processing', stage: 'generating', progress: 44.6,
            processed_rows: 1234567, total_rows: 2000000,
        });

        const visibleProcessed = preset === 'international' ? '1,234,567' : '1.234.567';
        const visibleTotal = preset === 'international' ? '2,000,000' : '2.000.000';
        const update = latestUpdate(app);
        assert.equal(update.message, 'Processed ' + visibleProcessed + ' of ' + visibleTotal + ' rows.');
        assert.equal(update.progressLabel, visibleProcessed + ' of ' + visibleTotal + ' rows');
        assert.ok(app.calls.confirmations[0].text.includes('Export ' + visibleProcessed + ' records'));
        assert.equal(typeof update.progress, 'number');
        const record = JSON.parse(app.store.get(storageKey))[0];
        assert.equal(typeof record.processedRows, 'number');
        assert.equal(typeof record.totalRows, 'number');
        assert.equal(record.processedRows, 1234567);
        assert.equal(record.totalRows, 2000000);
        assert.equal(record.message, 'Processed ' + expectedCount(1234567, 'system') + ' of ' + expectedCount(2000000, 'system') + ' rows.');
    });
}

test('count boundaries, finalizing copy and singular/plural use visible text only', async () => {
    for (const preset of ['international', 'indonesian']) {
        const app = boot(preset);
        await submit(app);
        for (const value of counts) {
            app.window.AdasiAsyncExport.handleProgress({
                export_job_id: 'job-1', status: 'processing', stage: 'generating', progress: 25,
                processed_rows: value, total_rows: 1234567,
            });
            const rendered = preset === 'international' ? value.toLocaleString('en-US') : value.toLocaleString('id-ID');
            const total = preset === 'international' ? '1,234,567' : '1.234.567';
            assert.equal(latestUpdate(app).progressLabel, rendered + ' of ' + total + ' rows');
        }
        app.window.AdasiAsyncExport.handleProgress({
            export_job_id: 'job-1', status: 'processing', stage: 'finalizing', progress: 99.8,
            processed_rows: 1000, total_rows: 1234567,
        });
        const formattedTotal = preset === 'international' ? '1,234,567' : '1.234.567';
        const formattedProcessed = preset === 'international' ? '1,000' : '1.000';
        assert.equal(latestUpdate(app).message, 'All ' + formattedTotal + ' rows are processed. Finalizing the file.');
        assert.equal(latestUpdate(app).progressLabel, formattedProcessed + ' of ' + formattedTotal + ' rows');
        app.window.AdasiAsyncExport.handleProgress({
            export_job_id: 'job-1', status: 'processing', stage: 'generating', progress: 100,
            processed_rows: 1, total_rows: 1,
        });
        assert.equal(latestUpdate(app).message, 'Processed 1 of 1 row.');
        assert.equal(latestUpdate(app).progressLabel, '1 of 1 row');
        assert.equal(typeof latestUpdate(app).progress, 'number');
    }
});

test('cancel retry uses Regional visible counts while persistence keeps System message and numbers', async () => {
    const app = boot('indonesian');
    await submit(app);
    app.window.AdasiAsyncExport.handleProgress({
        export_job_id: 'job-1', status: 'processing', stage: 'generating', progress: 55,
        processed_rows: 1234567, total_rows: 2000000,
    });
    const cancel = latestUpdate(app).actions.find(action => action.label === 'Cancel');
    assert.equal(typeof cancel.onClick, 'function');
    await cancel.onClick();

    assert.equal(latestUpdate(app).message, 'Processed 1.234.567 of 2.000.000 rows.');
    assert.equal(latestUpdate(app).progressLabel, '1.234.567 of 2.000.000 rows');
    const record = JSON.parse(app.store.get(storageKey))[0];
    assert.equal(record.message, 'Processed ' + expectedCount(1234567, 'system') + ' of ' + expectedCount(2000000, 'system') + ' rows.');
    assert.equal(typeof record.processedRows, 'number');
    assert.equal(typeof record.totalRows, 'number');
});

test('restored toast projects Regional text without rewriting the persisted message or record shape', () => {
    const raw = {
        exportJobId: 'restored-job', statusUrl: origin + '/purchasing/export/status/restored-job',
        startedAt: Date.now(), toastId: 'persisted-toast', exportsUrl: origin + '/exports',
        cancelUrl: origin + '/purchasing/export/cancel/restored-job', status: 'processing',
        stage: 'generating', progress: 60, processedRows: 1000, totalRows: 1234567,
        message: 'Processed ' + expectedCount(1000, 'system') + ' of ' + expectedCount(1234567, 'system') + ' rows.',
        rowLabel: 'rows', progressDismissed: false, terminalDismissed: false, terminalToastId: null,
    };
    const app = boot('indonesian', [raw]);

    assert.ok(app.calls.progress.length);
    assert.equal(app.calls.progress[0].message, 'Processed 1.000 of 1.234.567 rows.');
    assert.equal(app.calls.progress[0].progressLabel, '1.000 of 1.234.567 rows');
    const persisted = JSON.parse(app.store.get(storageKey))[0];
    assert.deepEqual(Object.keys(persisted).sort(), Object.keys(raw).sort());
    assert.equal(persisted.message, raw.message);
    assert.equal(typeof persisted.processedRows, 'number');
    assert.equal(typeof persisted.totalRows, 'number');
});

test('confirmation preserves zero, singular, null and generic fallback behavior', async () => {
    const zero = boot('indonesian');
    await submit(zero, { exportSourceCount: '0', exportSourcePlural: 'records', exportRowLabel: 'rows' });
    assert.ok(zero.calls.confirmations[0].text.includes('Export 0 records'));

    const singular = boot('international');
    await submit(singular, { exportSourceCount: '1', exportSourceSingular: 'record', exportSourcePlural: 'records', exportRowLabel: 'rows' });
    assert.ok(singular.calls.confirmations[0].text.includes('Export 1 record matching'));

    const missing = boot('indonesian');
    await submit(missing, { exportSourcePlural: 'records', exportRowLabel: 'rows' });
    assert.ok(missing.calls.confirmations[0].text.includes('Export records matching the current filters?'));
    assert.equal(missing.calls.confirmations[0].text.includes('Export 0 records'), false);
});

test('confirmation Regionalizes the visible source count at every requested boundary', async () => {
    for (const preset of ['international', 'indonesian']) {
        for (const value of counts) {
            const app = boot(preset);
            const form = await submit(app, {
                exportSourceCount: String(value),
                exportSourceSingular: 'record',
                exportSourcePlural: 'records',
                exportRowLabel: 'rows',
            });
            const expected = value.toLocaleString(preset === 'international' ? 'en-US' : 'id-ID');
            assert.ok(app.calls.confirmations[0].text.includes('Export ' + expected + ' '));
            assert.equal(form.dataset.exportSourceCount, String(value));
        }
    }
});

test('Regional display never replaces raw persisted counts, progress or canonical message', async () => {
    const app = boot('indonesian');
    await submit(app);
    const payload = {
        export_job_id: 'job-1', status: 'processing', stage: 'generating', progress: 33.3,
        processed_rows: 1234567, total_rows: 2000000,
    };
    app.window.AdasiAsyncExport.handleProgress(payload);
    const before = app.calls.updates.length;
    const record = JSON.parse(app.store.get(storageKey))[0];
    assert.equal(typeof record.processedRows, 'number');
    assert.equal(typeof record.totalRows, 'number');
    assert.equal(typeof record.progress, 'number');
    assert.equal(record.processedRows, payload.processed_rows);
    assert.equal(record.totalRows, payload.total_rows);
    assert.equal(record.progress, 33);
    assert.equal(record.message, 'Processed ' + expectedCount(1234567, 'system') + ' of ' + expectedCount(2000000, 'system') + ' rows.');
    assert.equal(record.message.includes('1.234.567'), false);
    app.window.AdasiAsyncExport.handleProgress(payload);
    assert.equal(app.calls.updates.length, before, 'the existing raw progress signature remains stable');
});

test('unsafe integer counts keep legacy visible output without Regional conversion', async () => {
    const app = boot('indonesian');
    const unsafeCount = Number.MAX_SAFE_INTEGER + 1;
    await submit(app, {
        exportSourceCount: String(unsafeCount),
        exportSourcePlural: 'records',
        exportRowLabel: 'rows',
    });
    assert.ok(app.calls.confirmations[0].text.includes(unsafeCount.toLocaleString('en-US')));
    assert.equal(app.calls.display.some(call => call.text === String(unsafeCount)), false);
});

test('source references the existing plain Regional helper, and values stay output-only', async () => {
    assert.equal(source.includes('preferences?.displayNumber'), true, 'visible counts should use the existing Regional helper');
    assert.equal(source.includes("'plain'"), true, 'the existing plain integer profile should be used');
    const app = boot('international');
    await submit(app);
    for (const value of counts) {
        app.window.AdasiAsyncExport.handleProgress({
            export_job_id: 'job-1', status: 'processing', stage: 'generating', progress: 33.3,
            processed_rows: value, total_rows: 1234567,
        });
        const record = JSON.parse(app.store.get(storageKey))[0];
        assert.equal(typeof record.processedRows, 'number');
        assert.equal(typeof record.totalRows, 'number');
        assert.equal(typeof record.progress, 'number');
        assert.equal(record.progress, 33);
    }
    assert.equal(app.calls.display.every(call => call.profile === 'plain'), true);
});
