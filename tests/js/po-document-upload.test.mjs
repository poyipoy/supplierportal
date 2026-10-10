import assert from 'node:assert/strict';
import test from 'node:test';
import { readFile } from 'node:fs/promises';

const source = await readFile(new URL('../../resources/js/po-document-upload.js', import.meta.url), 'utf8');
const { bootPoDocumentUploads } = await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`);

test('queued state is announced, does not imply publication, and polling finishes on completion', async () => {
    const handlers = new Map();
    const state = { textContent: '' };
    const progress = { hidden: true, max: 0, value: 0, removeAttribute() {} };
    const retry = { hidden: true, addEventListener() {} };
    const submit = { disabled: false };
    const form = {
        action: 'http://portal.test/upload', dataset: { uploading: 'Uploading', offline: 'Unavailable' },
        elements: { request_key: { value: 'first' }, _token: { value: 'csrf' } },
        querySelector: (selector) => selector.includes('status') ? state : selector.includes('progress') ? progress : retry,
        querySelectorAll: () => [submit], addEventListener: (event, callback) => handlers.set(event, callback),
    };
    const stored = new Map();
    const prior = Object.fromEntries(['location', 'sessionStorage', 'setTimeout', 'clearTimeout', 'fetch'].map((key) => [key, globalThis[key]]));
    let poll;
    try {
        globalThis.location = { origin: 'http://portal.test' };
        globalThis.sessionStorage = { getItem: (key) => stored.get(key), setItem: (key, value) => stored.set(key, value), removeItem: (key) => stored.delete(key) };
        globalThis.setTimeout = (callback) => { poll = callback; return 1; };
        globalThis.clearTimeout = () => {};
        globalThis.fetch = async () => ({ ok: true, json: async () => ({ status: 'COMPLETED', message: 'Published', processed: 2, total: 2 }) });
        bootPoDocumentUploads({ querySelectorAll: () => [form] });
        handlers.get('adasi:form-processing')({ detail: { status: 'PENDING', message: 'Queued', processed: 0, total: 2, status_url: 'http://portal.test/status' } });
        assert.equal(state.textContent, 'Queued');
        assert.equal(submit.disabled, true);
        assert.equal(progress.value, 0);
        assert.equal(stored.size, 1);
        await poll();
        assert.equal(state.textContent, 'Published');
        assert.equal(submit.disabled, false);
        assert.equal(progress.value, 2);
        assert.equal(stored.size, 0);
        globalThis.fetch = async () => ({ ok: true, json: async () => ({
            status: 'REJECTED', message: 'ZIP exceeds available storage. Reduce the file size.', processed: 0, total: 2,
        }) });
        handlers.get('adasi:form-processing')({ detail: { status: 'PENDING', message: 'Queued', processed: 0, total: 2, status_url: 'http://portal.test/status' } });
        await poll();
        assert.equal(state.textContent, 'ZIP exceeds available storage. Reduce the file size.');
        assert.equal(submit.disabled, false);
        assert.equal(progress.value, 0);
        assert.equal(stored.size, 0);
    } finally { Object.assign(globalThis, prior); }
});

test('storage validation error takes precedence over a generic response summary', () => {
    const handlers = new Map();
    const status = { textContent: '' };
    const progress = { hidden: false };
    const form = {
        action: 'http://portal.test/upload', dataset: { offline: 'Unavailable' },
        elements: { request_key: { value: 'first' } },
        querySelector: (selector) => selector.includes('status') ? status : selector.includes('progress') ? progress : null,
        addEventListener: (event, callback) => handlers.set(event, callback),
    };
    const prior = globalThis.sessionStorage;
    try {
        globalThis.sessionStorage = { getItem: () => null };
        bootPoDocumentUploads({ querySelectorAll: () => [form] });
        handlers.get('adasi:form-errors')({ detail: {
            message: 'The given data was invalid.', errors: { file: ['ZIP exceeds available storage. Reduce the file size.'] },
        } });
        assert.equal(status.textContent, 'ZIP exceeds available storage. Reduce the file size.');
        assert.equal(progress.hidden, true);
    } finally {
        globalThis.sessionStorage = prior;
    }
});
