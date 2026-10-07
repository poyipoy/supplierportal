import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';
import { installI18n } from './i18n-fixture.mjs';

const source = (await readFile(new URL('../../public/assets/js/async-export.js', import.meta.url), 'utf8'))
    .replace('window.AdasiAsyncExport = Object.freeze({', 'window.testExports = { applyProgressUpdate, pollStatus, persistPendingExport, activeExports }; window.AdasiAsyncExport = Object.freeze({');
function boot(seed = []) {
    let now = 650000;
    let fetches = 0;
    const timers = [];
    const storage = new Map(seed);
    const window = {
        location: { origin: 'https://portal.test' }, crypto: { randomUUID: () => 'test-tab' },
        localStorage: { getItem: (k) => storage.get(k) || null, setItem: (k, v) => storage.set(k, v), removeItem: (k) => storage.delete(k) },
        AdasiToast: { progress: (o) => o.id, update() {}, show() {} },
        addEventListener() {}, setTimeout(callback, delay) { if (delay === 1000) timers.push(callback); },
        fetch: async () => { fetches++; return { ok: true, json: async () => ({ status: 'processing', stage: 'generating', processed_rows: 500, total_rows: 50000, progress: 1 }) }; },
    };
    class Clock extends Date { static now() { return now; } }
    const document = { documentElement: { dataset: {} }, addEventListener() {}, querySelector: () => ({ content: 'token' }) };
    installI18n(window);
    vm.runInNewContext(source, { window, document, Date: Clock, URL, Set, Map, HTMLFormElement: class {}, HTMLButtonElement: class {}, HTMLInputElement: class {}, FormData: class {} });
    return { window, storage, setTime: (value) => { now = value; }, fetches: () => fetches, poll: async () => { assert.ok(timers.length); await timers.shift()(); } };
}
function state(csvProgressClock) {
    return { control: null, requestUrl: '/supplier/export/purchase-orders', exportJobId: 'csv-hash', statusUrl: '/exports/csv-hash/status', cancelUrl: null, toastId: 'toast-id', startedAt: 1,
        csvProgressClock, lastProgressAt: 1, lastStatus: 'processing', lastStage: 'generating', lastProgress: 0, lastProcessedRows: 100, lastTotalRows: 50000, lastMessage: '', rowLabel: 'rows', lastProgressSignature: null, isPending: true };
}

test('CSV progress continues beyond eleven minutes and still stops after eleven minutes without progress', async () => {
    const app = boot();
    const job = state(true);
    app.window.testExports.applyProgressUpdate(job, { status: 'processing', stage: 'generating', processed_rows: 500, total_rows: 50000, progress: 1 });
    assert.equal(job.lastProgressAt, 650000);
    app.setTime(900000);
    app.window.testExports.pollStatus(job);
    await app.poll();
    assert.equal(app.fetches(), 1);
    assert.equal(job.monitoringStopped, undefined);
    assert.equal(job.lastProgressAt, 650000, 'Repeated polling of identical progress must not extend the clock.');
    app.setTime(1310000);
    await app.poll();
    assert.equal(job.monitoringStopped, true);
    assert.equal(app.fetches(), 1);
});

test('XLSX and legacy jobs keep the absolute timeout and unchanged persisted record shape', async () => {
    const app = boot();
    const job = state(false);
    app.window.testExports.persistPendingExport(job);
    const record = JSON.parse(app.storage.get('adasi:pending-export-jobs:v1'))[0];
    assert.equal(Object.hasOwn(record, 'csvProgressClock'), false);
    assert.equal(Object.hasOwn(record, 'lastProgressAt'), false);
    app.setTime(900000);
    app.window.testExports.pollStatus(job);
    await app.poll();
    assert.equal(job.monitoringStopped, true);
    assert.equal(app.fetches(), 0);
});

test('CSV activity clock survives navigation restore without changing canonical row counts', async () => {
    const app = boot();
    const job = state(true);
    job.lastProgressAt = 650000;
    app.window.testExports.persistPendingExport(job);
    const stored = app.storage.get('adasi:pending-export-jobs:v1');
    const restored = boot([['adasi:pending-export-jobs:v1', stored]]);
    await restored.poll();
    const resumed = [...restored.window.testExports.activeExports.values()][0];
    assert.equal(resumed.csvProgressClock, true);
    assert.equal(resumed.startedAt, 1);
    assert.equal(resumed.lastProgressAt, 650000);
    assert.equal(resumed.lastProcessedRows, 500);
});
