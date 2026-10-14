import test from 'node:test';
import assert from 'node:assert/strict';
import { errorKeyToFieldNames, flattenErrors, isSameOriginUrl, submitAsync } from '../../resources/js/async-form-submit.js';

test('Laravel error keys map to the input names used by the forms', () => {
    assert.deepEqual(errorKeyToFieldNames('email'), ['email', 'email[]']);
    assert.equal(errorKeyToFieldNames('invoice.0')[0], 'invoice[]');
    assert.ok(errorKeyToFieldNames('invoice.0').includes('invoice[0]'));
    assert.equal(errorKeyToFieldNames('questionnaire.child_labor')[0], 'questionnaire[child_labor]');
    assert.ok(errorKeyToFieldNames('questionnaire.child_labor').includes('questionnaire'));
    assert.deepEqual(errorKeyToFieldNames(''), []);
    assert.deepEqual(errorKeyToFieldNames(null), []);
});

for (const status of [413, 422]) test(`${status} error restores the form without removing the selected upload`, async () => {
    const prior = Object.fromEntries(['window', 'document', 'fetch', 'FormData', 'CustomEvent'].map((key) => [key, globalThis[key]]));
    const events = [];
    const selected = { name: 'documents.zip', size: 70 * 1024 * 1024 };
    const element = () => ({ setAttribute() {}, append() {}, replaceChildren() {}, focus() {}, scrollIntoView() {} });
    const summary = element();
    const input = { name: 'file', type: 'file', files: [selected], closest: () => element() };
    const form = {
        action: 'http://portal.test/upload', dataset: {},
        setAttribute() {}, removeAttribute() {}, getAttribute: () => 'POST',
        querySelectorAll: () => [],
        querySelector: (selector) => selector === '[data-async-error-summary]' ? summary : selector.includes('file') ? input : null,
        dispatchEvent: (event) => events.push(event),
    };
    let buttonsReset = false;
    let loaderHidden = false;
    try {
        globalThis.window = {
            location: { origin: 'http://portal.test' }, requestAnimationFrame: (callback) => callback(),
            AdasiI18n: { t: (key) => key }, AdasiLoader: { show: () => 1, hide: () => { loaderHidden = true; } },
            AdasiButton: { resetForm: () => { buttonsReset = true; } },
        };
        globalThis.document = { createElement: element, querySelector: () => null };
        globalThis.FormData = class { append() {} };
        globalThis.CustomEvent = class { constructor(type, options) { this.type = type; this.detail = options?.detail; } };
        globalThis.fetch = async () => ({ ok: false, status, json: async () => {
            if (status === 413) throw new Error('HTML response');
            return { errors: { email: ['This email is already registered.'] } };
        } });
        await submitAsync(form);
        const error = events.find((event) => event.type === 'adasi:form-errors');
        assert.deepEqual(error?.detail.errors, status === 413 ? { file: ['js.async_form.too_large'] } : { email: ['This email is already registered.'] });
        assert.equal(form.dataset.asyncSubmitting, undefined);
        assert.equal(loaderHidden, true);
        assert.equal(buttonsReset, true);
        assert.deepEqual(input.files, [selected]);
        assert.equal(summary.hidden, false);
    } finally {
        Object.assign(globalThis, prior);
    }
});

test('submits to the attribute action URL, not a same-named form control that shadows the IDL property', async () => {
    // A <input name="action"> (or id="action") inside a <form> shadows the native
    // HTMLFormElement.action getter in real browsers: form.action then returns the
    // input element instead of the URL string. form.getAttribute('action') is immune
    // to this because it reads the raw content attribute, bypassing named-item lookup.
    const prior = Object.fromEntries(['window', 'document', 'fetch', 'FormData', 'CustomEvent'].map((key) => [key, globalThis[key]]));
    let requestedUrl = null;
    const shadowingActionInput = { toString: () => '[object HTMLInputElement]' };
    const form = {
        action: shadowingActionInput,
        dataset: {},
        getAttribute: (name) => (name === 'action' ? 'http://portal.test/supplier/quotations/abc123' : 'POST'),
        setAttribute() {}, removeAttribute() {},
        querySelectorAll: () => [],
        querySelector: () => null,
        dispatchEvent() {},
    };
    try {
        globalThis.window = {
            location: { origin: 'http://portal.test', assign() {} },
            requestAnimationFrame: (callback) => callback(),
            AdasiI18n: { t: (key) => key },
            AdasiLoader: { show: () => 1, hide() {} },
            AdasiButton: { resetForm() {} },
        };
        globalThis.document = { createElement: () => ({ setAttribute() {}, append() {}, replaceChildren() {} }), querySelector: () => null };
        globalThis.FormData = class { append() {} };
        globalThis.CustomEvent = class { constructor(type, options) { this.type = type; this.detail = options?.detail; } };
        globalThis.fetch = async (url) => {
            requestedUrl = url;
            return { ok: true, redirected: false, json: async () => ({ redirect: '/supplier/quotations/period/1' }) };
        };
        await submitAsync(form);
        assert.equal(requestedUrl, 'http://portal.test/supplier/quotations/abc123');
    } finally {
        Object.assign(globalThis, prior);
    }
});

test('only same-origin http(s) redirects are followed', () => {
    const origin = 'https://portal.example.test';

    assert.equal(isSameOriginUrl('https://portal.example.test/supplier/register/success', origin), true);
    assert.equal(isSameOriginUrl('/supplier/registration/status', origin), true);
    assert.equal(isSameOriginUrl('https://evil.example.test/phish', origin), false);
    assert.equal(isSameOriginUrl('//evil.example.test/phish', origin), false);
    assert.equal(isSameOriginUrl('javascript:alert(1)', origin), false);
    assert.equal(isSameOriginUrl('http://portal.example.test/insecure-downgrade', origin), false);
});

test('validation errors flatten to the first message per field, in server order', () => {
    assert.deepEqual(
        flattenErrors({
            nib: ['NIB is required.', 'NIB must be 13 digits.'],
            'questionnaire.msds': ['Select Yes or No.'],
            empty: [],
        }),
        [
            { key: 'nib', message: 'NIB is required.' },
            { key: 'questionnaire.msds', message: 'Select Yes or No.' },
        ],
    );
    assert.deepEqual(flattenErrors(null), []);
    assert.deepEqual(flattenErrors('oops'), []);
});
