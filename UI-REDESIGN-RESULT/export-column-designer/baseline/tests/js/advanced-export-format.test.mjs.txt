import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const source = await readFile(new URL('../../resources/js/advanced-export.js', import.meta.url), 'utf8');
function boot({ unavailable = false, invalid = false } = {}) {
    let submit;
    const requests = [];
    const attributes = new Map();
    const button = { disabled: false, setAttribute: (k, v) => attributes.set(k, v), removeAttribute: (k) => attributes.delete(k) };
    const error = { hidden: true, textContent: '', focus() { this.focused = true; } };
    const form = {
        action: '/supplier/price-history/export', dataset: { exportFailed: 'Ekspor gagal.' },
        fields: new Map([['_token', 'token'], ['options[format]', 'csv'], ['material_name', 'Steel'], ['currency', 'USD'], ['range', 'all'], ['width', '0']]),
        querySelector: (selector) => selector === '[type="submit"]' ? button : error,
        reportValidity: () => true,
        addEventListener(name, callback) { if (name === 'submit') submit = callback; },
    };
    const document = { readyState: 'complete', querySelectorAll: (selector) => selector === '[data-format-export-form]' ? [form] : [] };
    const window = { AdasiAsyncExport: unavailable ? undefined : { async startExport(target, options) {
        assert.equal(target, form);
        requests.push(options.body);
        if (invalid) options.onError('Persempit filter.');
        return !invalid;
    } } };
    class FormData { constructor(target) { this.fields = target.fields; } [Symbol.iterator]() { return this.fields[Symbol.iterator](); } }
    vm.runInNewContext(source, { document, window, FormData });
    return { form, button, error, attributes, requests, submit: () => submit({ preventDefault() {} }) };
}

test('format-only submit forwards applied filters and sends format without selectable columns or CSRF in JSON', async () => {
    const app = boot();
    await app.submit();
    assert.deepEqual(JSON.parse(JSON.stringify(app.requests[0])), { material_name: 'Steel', currency: 'USD', range: 'all', width: '0', options: { format: 'csv' } });
    app.form.fields.set('options[format]', 'xlsx');
    await app.submit();
    assert.equal(app.requests[1].options.format, 'xlsx');
    assert.equal(app.button.disabled, false);
    assert.equal(app.attributes.has('aria-busy'), false);
});

test('validation errors are presented as text with focus and retry remains enabled', async () => {
    const app = boot({ invalid: true });
    await app.submit();
    assert.equal(app.error.textContent, 'Persempit filter.');
    assert.equal(app.error.hidden, false);
    assert.equal(app.error.focused, true);
    assert.equal(app.button.disabled, false);
});

test('missing SDK gives localized feedback and a disabled submit cannot dispatch twice', async () => {
    const app = boot({ unavailable: true });
    await app.submit();
    assert.equal(app.error.textContent, 'Ekspor gagal.');
    assert.equal(app.button.disabled, false);
    app.button.disabled = true;
    await app.submit();
    assert.equal(app.requests.length, 0);
});
