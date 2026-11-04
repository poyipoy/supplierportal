import test from 'node:test';
import assert from 'node:assert/strict';
import { bootExchangeRates, sanitizeRateValue } from '../../resources/js/exchange-rates.js';

// The adapter uses native controls and delegated events; a small tree fake avoids a DOM dependency.
class ElementFake {
    constructor(tag = 'div', attributes = {}) {
        this.tagName = tag.toUpperCase();
        this.nodeType = 1;
        this.attributes = new Map();
        this.dataset = {};
        this.children = [];
        this.parentElement = null;
        this.listeners = new Map();
        this.style = {};
        this.disabled = false;
        this.hidden = false;
        this._text = '';
        const classes = new Set();
        this.classList = {
            add: (...names) => names.forEach((name) => classes.add(name)),
            remove: (...names) => names.forEach((name) => classes.delete(name)),
            contains: (name) => classes.has(name),
            toggle: (name, force) => {
                const enabled = force ?? !classes.has(name);
                if (enabled) classes.add(name); else classes.delete(name);
                return enabled;
            },
            replaceAll: (value) => { classes.clear(); String(value).split(/\s+/).filter(Boolean).forEach((name) => classes.add(name)); },
            toString: () => [...classes].join(' '),
        };
        for (const [name, value] of Object.entries(attributes)) this.setAttribute(name, value);
    }

    get id() { return this.attributes.get('id') ?? ''; }
    set id(value) { this.attributes.set('id', String(value)); }
    get className() { return this.classList.toString(); }
    set className(value) { this.classList.replaceAll(value); }
    get textContent() { return this._text + this.children.map((child) => child.textContent).join(''); }
    set textContent(value) { this._text = String(value); this.children = []; }
    get href() { return new URL(this.getAttribute('href') ?? '', 'http://portal.test/admin/exchange-rates').href; }
    set href(value) { this.setAttribute('href', value); }
    get isConnected() { return globalThis.document?.contains(this) ?? false; }
    get firstElementChild() { return this.children[0] ?? null; }
    set innerHTML(value) { this._html = String(value); this.children = []; this._text = ''; }
    get innerHTML() { return this._html ?? ''; }

    setAttribute(name, value) {
        value = String(value);
        this.attributes.set(name, value);
        if (name.startsWith('data-')) this.dataset[dataKey(name)] = value;
        if (['name', 'type', 'value'].includes(name)) this[name] = value;
        if (name === 'disabled') this.disabled = true;
        if (name === 'class') this.className = value;
    }

    getAttribute(name) {
        if (name.startsWith('data-')) return this.dataset[dataKey(name)] ?? null;
        if (name === 'class') return this.className;
        return this.attributes.get(name) ?? null;
    }

    hasAttribute(name) { return this.getAttribute(name) !== null; }
    removeAttribute(name) {
        this.attributes.delete(name);
        if (name.startsWith('data-')) delete this.dataset[dataKey(name)];
        if (name === 'disabled') this.disabled = false;
    }

    append(...nodes) { nodes.forEach((node) => this.appendChild(typeof node === 'string' ? Object.assign(new ElementFake('span'), { textContent: node }) : node)); }
    appendChild(node) { node.remove(); node.parentElement = this; this.children.push(node); return node; }
    prepend(node) { node.remove(); node.parentElement = this; this.children.unshift(node); }
    replaceChildren(...nodes) { this.children.forEach((node) => { node.parentElement = null; }); this.children = []; this._text = ''; this.append(...nodes); }
    remove() { if (this.parentElement) this.parentElement.children = this.parentElement.children.filter((node) => node !== this); this.parentElement = null; }
    insertAdjacentElement(position, node) {
        if (position !== 'afterend' || !this.parentElement) return this.appendChild(node);
        node.parentElement = this.parentElement;
        this.parentElement.children.splice(this.parentElement.children.indexOf(this) + 1, 0, node);
        return node;
    }

    contains(node) { return this === node || this.children.some((child) => child.contains(node)); }
    matches(selector) { return selector.split(',').some((choice) => matchesSelector(this, choice.trim())); }
    closest(selector) { for (let node = this; node; node = node.parentElement) if (node.matches(selector)) return node; return null; }
    querySelectorAll(selector) {
        const result = [];
        const visit = (node) => node.children.forEach((child) => { if (child.matches(selector)) result.push(child); visit(child); });
        visit(this);
        return result;
    }
    querySelector(selector) { return this.querySelectorAll(selector)[0] ?? null; }
    addEventListener(type, handler, options) { const listeners = this.listeners.get(type) ?? []; listeners.push({ handler, capture: options === true || options?.capture === true }); this.listeners.set(type, listeners); }
    removeEventListener(type, handler) { this.listeners.set(type, (this.listeners.get(type) ?? []).filter((listener) => listener.handler !== handler)); }
    dispatchEvent(event) {
        event.target ??= this;
        for (const { handler } of this.listeners.get(event.type) ?? []) handler(event);
        return !event.defaultPrevented;
    }
    focus() { if (globalThis.document) globalThis.document.activeElement = this; }
    scrollIntoView() {}
}

function dataKey(name) { return name.slice(5).replace(/-([a-z])/g, (_, letter) => letter.toUpperCase()); }

function matchesSelector(node, selector) {
    const parts = selector.split(/\s+(?![^\[]*\])/).filter(Boolean);
    if (parts.length > 1) {
        if (!matchesSelector(node, parts.pop())) return false;
        for (let ancestor = node.parentElement; ancestor; ancestor = ancestor.parentElement) if (matchesSelector(ancestor, parts.join(' '))) return true;
        return false;
    }
    let valid = true;
    let plain = selector.replace(/:not\(([^)]+)\)/g, (_, excluded) => { if (node.matches(excluded)) valid = false; return ''; });
    plain = plain.replace(/\[([\w:-]+)(?:\s*(\^=|\*=|=)\s*(?:"([^"]*)"|'([^']*)'|([^\]]+)))?\]/g, (_, name, operator, doubleQuoted, singleQuoted, unquoted) => {
        const actual = node.getAttribute(name);
        const expected = doubleQuoted ?? singleQuoted ?? unquoted?.trim();
        if (actual === null || (operator === '=' && actual !== expected) || (operator === '^=' && !actual.startsWith(expected)) || (operator === '*=' && !actual.includes(expected))) valid = false;
        return '';
    });
    const tag = plain.match(/^[\w-]+/);
    const id = plain.match(/#([\w-]+)/);
    const classes = [...plain.matchAll(/\.([\w:-]+)/g)];
    return valid && (!tag || node.tagName.toLowerCase() === tag[0].toLowerCase()) && (!id || node.id === id[1]) && classes.every((match) => node.classList.contains(match[1]));
}

class FormFake extends ElementFake {
    constructor(attributes) { super('form', attributes); }
    get action() { return this.getAttribute('action'); }
    get method() { return this.getAttribute('method') ?? 'GET'; }
    get elements() { const fields = this.querySelectorAll('input, select, textarea, button'); fields.namedItem = (name) => fields.find((field) => field.name === name) ?? null; return fields; }
    checkValidity() { return true; }
    reportValidity() { return true; }
    reset() { this.querySelectorAll('input, select, textarea').forEach((field) => { field.value = field.defaultValue ?? ''; }); }
}

class FormDataFake {
    constructor(form) { this.values = form?.querySelectorAll('input, select, textarea').filter((field) => field.name && !field.disabled).map((field) => [field.name, field.value ?? '']) ?? []; }
    append(name, value) { this.values.push([name, value]); }
    get(name) { return this.values.find(([key]) => key === name)?.[1] ?? null; }
    entries() { return this.values[Symbol.iterator](); }
    [Symbol.iterator]() { return this.entries(); }
}

function setup({ effective = false } = {}) {
    const keys = ['window', 'document', 'fetch', 'FormData', 'HTMLFormElement', 'HTMLElement', 'Element', 'CustomEvent', 'CSS'];
    const previous = Object.fromEntries(keys.map((key) => [key, globalThis[key]]));
    const document = new ElementFake('document');
    document.readyState = 'complete';
    document.createElement = (tag) => new ElementFake(tag);
    document.getElementById = (id) => document.querySelector(`#${id}`);
    document.body = document.appendChild(new ElementFake('body'));
    const workspace = document.body.appendChild(new ElementFake('div', { id: 'exchangeRatesWorkspace', 'data-exchange-rates-workspace': '', 'data-server-tabs-container': '' }));
    const content = workspace.appendChild(new ElementFake('div', { 'data-server-tabs-content': '' }));
    const config = workspace.appendChild(new ElementFake('script', { 'data-exchange-rates-config': '' }));
    config.textContent = JSON.stringify({ refreshLabel: 'Review latest rates', refreshFailed: 'Could not refresh rates.', saveUnknown: 'Save result unknown. Review the latest rates before trying again.' });
    const modal = effective ? document.body.appendChild(new ElementFake('div', { id: 'rateModal' })) : null;
    if (modal) modal.classList.add('show');
    const reviewUrl = `http://portal.test/admin/exchange-rates?tab=${effective ? 'effective' : 'annual&annual_edit_year=2024'}`;
    const form = (modal ?? content).appendChild(new FormFake({
        id: effective ? 'effective-rate-form' : 'annual-rate-form',
        action: `http://portal.test/admin/exchange-rates${effective ? '' : '/annual'}`, method: 'POST',
        'data-exchange-rates-save': '', 'data-exchange-rates-review-url': reviewUrl, 'data-exchange-rates-review-label': 'Review latest rates',
        'data-async-files-hint': 'false',
    }));
    const values = effective
        ? { _token: 'csrf-test-token', currency: 'USD', rate_to_idr: '17650', valid_from: '2026-11-03' }
        : { _token: 'csrf-test-token', year: '2024', expected_revision: '1', 'rates[USD]': '17500', 'rates[JPY]': '106.2', 'rates[CNY]': '2300' };
    const fields = {};
    for (const [name, value] of Object.entries(values)) {
        const field = new ElementFake('input', { name, value, type: ['_token', 'year', 'expected_revision'].includes(name) ? 'hidden' : 'text', id: `field-${name.replace(/\W/g, '-')}` });
        if (name.startsWith('rates[')) field.setAttribute('data-annual-rate-input', '');
        field.defaultValue = value;
        fields[name] = form.appendChild(field);
    }
    const submitter = form.appendChild(new ElementFake('button', { type: 'submit' }));
    const calls = [];
    const navigations = [];
    const toasts = [];
    const assignments = [];
    const dirtySources = [];
    const state = { dirty: false, submitting: false, prompts: 0, leave: false, buttonResets: 0, modalHides: 0, invalidations: 0, options: null };
    const unsaved = {
        isDirty: () => state.dirty || dirtySources.some((source) => source()),
        markDirty: () => { state.dirty = true; },
        markClean: () => { state.dirty = false; },
        setSubmitting: (value) => { state.submitting = value; },
        resetSnapshot() {},
        registerDirtySource: (source) => { dirtySources.push(source); return () => dirtySources.splice(dirtySources.indexOf(source), 1); },
        showLeaveConfirmation: async (onConfirm) => { state.prompts++; if (state.leave) { state.dirty = false; onConfirm(); } },
    };
    const sdk = {
        init: (selector, options) => { assert.equal(selector, '#exchangeRatesWorkspace'); state.options = options; return { container: workspace, options }; },
        invalidateCache: () => { state.invalidations++; },
        invalidateCacheForTab: () => { state.invalidations++; },
        navigate: async (selector, url, options = {}) => {
            if (await state.options.beforeNavigate?.(url, { ...options, source: 'navigate' }) === false) return false;
            if (state.refreshFails) {
                state.options.onError?.(new Error('Fragment unavailable'));
                return false;
            }
            navigations.push({ selector, url, options });
            state.options.onUpdated?.({ tab: effective ? 'effective' : 'annual', url });
            return true;
        },
    };
    globalThis.document = document;
    globalThis.FormData = FormDataFake;
    globalThis.HTMLFormElement = FormFake;
    globalThis.HTMLElement = ElementFake;
    globalThis.Element = ElementFake;
    globalThis.CSS = { escape: (name) => name };
    globalThis.CustomEvent = class { constructor(type, options = {}) { this.type = type; this.detail = options.detail; } };
    globalThis.fetch = (url, options) => new Promise((resolve, reject) => calls.push({
        url, options, reject,
        respond: (status, data) => resolve({ ok: status >= 200 && status < 300, status, redirected: false, headers: { get: () => 'application/json' }, json: async () => data }),
    }));
    globalThis.window = {
        document, FormData: FormDataFake, fetch: (...args) => globalThis.fetch(...args),
        location: { origin: 'http://portal.test', href: 'http://portal.test/admin/exchange-rates?tab=annual', pathname: '/admin/exchange-rates', assign: (url) => assignments.push(url) },
        requestAnimationFrame: (callback) => callback(), addEventListener() {},
        AdasiI18n: { t: (key) => key }, AdasiServerTabs: sdk, AdasiUnsaved: unsaved,
        AdasiButton: {
            startLoading: (button) => { if (button) button.disabled = true; },
            disableSiblingButtons() {},
            resetForm: () => { state.buttonResets++; submitter.disabled = false; },
        },
        AdasiToast: { success: (message) => toasts.push({ type: 'success', message }), error: (message) => toasts.push({ type: 'error', message }) },
        bootstrap: { Modal: { getOrCreateInstance: () => ({ hide: () => {
            if (state.modalOpening) return;
            state.modalHides++;
            modal.classList.remove('show');
            modal.dispatchEvent({ type: 'hidden.bs.modal' });
        } }) } },
    };
    bootExchangeRates(document);

    return {
        workspace, content, modal, form, fields, submitter, reviewUrl, calls, navigations, toasts, assignments, state, unsaved,
        submit: ({ prevented = false } = {}) => {
            const event = { type: 'submit', target: form, submitter, defaultPrevented: prevented, preventDefault() { this.defaultPrevented = true; }, stopPropagation() {}, stopImmediatePropagation() {} };
            document.dispatchEvent(event);
            assert.equal(event.defaultPrevented, true, 'The owned form must not use native document submission.');
        },
        hasReviewLink: () => document.querySelectorAll('a').some((link) => link.href === reviewUrl && link.textContent.includes('Review')),
        restore: () => Object.assign(globalThis, previous),
    };
}

const settle = () => new Promise((resolve) => setImmediate(resolve));

test('a save posts once with CSRF then refreshes the fragment without document navigation', async () => {
    const page = setup();
    try {
        assert.equal(page.state.options.cacheTtlMs, 0);
        assert.equal(page.state.options.prefetch, false);
        assert.equal(page.state.options.managedHistory, true);
        assert.equal(page.workspace.dataset.serverTabsUnsaved, 'managed');
        page.state.dirty = true;
        page.submit();
        page.submit();
        assert.equal(page.calls.length, 1, 'A pending save must block duplicate submits.');
        assert.equal(page.calls[0].options.method.toUpperCase(), 'POST');
        assert.equal(page.calls[0].options.body.get('_token'), 'csrf-test-token');
        assert.equal(page.calls[0].options.body.get('expected_revision'), '1');
        assert.equal(new Headers(page.calls[0].options.headers).get('Accept'), 'application/json');
        assert.equal(await page.state.options.beforeNavigate('/admin/exchange-rates?tab=effective'), false, 'Navigation cannot remove a pending save.');
        page.calls[0].respond(200, { redirect: 'http://portal.test/admin/exchange-rates?tab=annual&annual_page=1', message: 'Annual rates saved.' });
        await settle();
        assert.equal(page.navigations.length, 1);
        assert.equal(page.navigations[0].url, 'http://portal.test/admin/exchange-rates?tab=annual&annual_page=1');
        assert.deepEqual(page.assignments, []);
        assert.equal(page.state.submitting, false);
        assert.equal(page.unsaved.isDirty(), false);
        assert.ok(page.toasts.some((toast) => toast.type === 'success' && toast.message === 'Annual rates saved.'));
    } finally { page.restore(); }
});

test('a component-cancelled submission never posts and restores unsaved tracking', () => {
    const page = setup();
    try {
        page.state.submitting = true;
        page.state.dirty = true;
        page.submit({ prevented: true });
        assert.equal(page.calls.length, 0);
        assert.equal(page.state.submitting, false);
        assert.equal(page.unsaved.isDirty(), true);
    } finally { page.restore(); }
});

test('a stale 422 keeps the entered rates and revision and does not fetch a fresh form', async () => {
    const page = setup();
    try {
        page.submit();
        page.calls[0].respond(422, { message: 'Review the latest rates.', errors: { expected_revision: ['These rates have changed.'] } });
        await settle();
        assert.equal(page.calls.length, 1);
        assert.equal(page.navigations.length, 0);
        assert.equal(page.fields['rates[USD]'].value, '17500');
        assert.equal(page.fields['rates[JPY]'].value, '106.2');
        assert.equal(page.fields.expected_revision.value, '1');
        assert.equal(page.unsaved.isDirty(), true);
        assert.equal(page.state.submitting, false);
        assert.equal(page.submitter.disabled, false);
        const summary = page.form.querySelector('[data-async-error-summary]');
        assert.ok(summary?.textContent.includes('These rates have changed.'));
        assert.equal(summary.textContent.includes('js.async_form.files_kept'), false);
        assert.equal(page.hasReviewLink(), true);
    } finally { page.restore(); }
});

test('an uncertain network result retains the form and offers review without retrying the write', async () => {
    const page = setup();
    try {
        page.submit();
        page.calls[0].reject(new TypeError('Network request failed'));
        await settle();
        await settle();
        assert.equal(page.calls.length, 1);
        assert.equal(page.navigations.length, 0);
        assert.deepEqual(page.assignments, []);
        assert.equal(page.fields['rates[USD]'].value, '17500');
        assert.equal(page.fields.expected_revision.value, '1');
        assert.equal(page.unsaved.isDirty(), true);
        assert.equal(page.state.submitting, false);
        assert.equal(page.submitter.disabled, false);
        assert.equal(page.hasReviewLink(), true);
        assert.ok(page.toasts.some((toast) => toast.type === 'error') || page.form.textContent.includes('Save result unknown'));
    } finally { page.restore(); }
});

test('dirty navigation respects stay and leave through the existing confirmation API', async () => {
    const page = setup();
    try {
        page.state.dirty = true;
        page.state.leave = false;
        assert.equal(await page.state.options.beforeNavigate('/admin/exchange-rates?tab=effective'), false);
        assert.equal(page.unsaved.isDirty(), true);
        page.state.leave = true;
        assert.equal(await page.state.options.beforeNavigate('/admin/exchange-rates?tab=effective'), true);
        assert.equal(page.state.prompts, 2);
        assert.deepEqual(page.assignments, []);
    } finally { page.restore(); }
});

test('a cross-origin success redirect is refused instead of navigating the SDK or document', async () => {
    const page = setup();
    try {
        page.submit();
        page.calls[0].respond(200, { redirect: 'https://attacker.example.test/collect', message: 'Saved.' });
        await settle();
        assert.equal(page.calls.length, 1);
        assert.equal(page.navigations.length, 0);
        assert.deepEqual(page.assignments, []);
        assert.equal(page.state.submitting, false);
        assert.equal(page.submitter.disabled, false);
    } finally { page.restore(); }
});

test('a confirmed write with a failed refresh cannot be submitted twice and offers a safe refresh link', async () => {
    const page = setup();
    try {
        page.state.refreshFails = true;
        page.submit();
        page.calls[0].respond(200, { redirect: page.reviewUrl, message: 'Annual rates saved.' });
        await settle();
        assert.equal(page.form.dataset.exchangeRatesCommitted, 'true');
        assert.equal(page.submitter.disabled, true);
        assert.equal(page.hasReviewLink(), true);
        assert.ok(page.form.textContent.includes('Could not refresh rates.'));
        page.submit();
        assert.equal(page.calls.length, 1, 'The confirmed write must never be retried by the stale form.');
        assert.deepEqual(page.assignments, []);
    } finally { page.restore(); }
});

test('one confirmed leave from a failed form covers the whole history transition and a fetch error restores protection', async () => {
    const page = setup();
    try {
        page.submit();
        page.calls[0].respond(422, { errors: { expected_revision: ['These rates have changed.'] } });
        await settle();
        page.state.leave = true;
        assert.equal(await page.state.options.beforeNavigate(page.reviewUrl), true);
        assert.equal(await page.state.options.beforeNavigate(page.reviewUrl), true);
        assert.equal(page.state.prompts, 1);
        page.state.options.onError(new Error('Fragment unavailable'));
        page.state.leave = false;
        assert.equal(await page.state.options.beforeNavigate(page.reviewUrl), false);
        assert.equal(page.state.prompts, 2);
        assert.equal(page.fields.expected_revision.value, '1');
    } finally { page.restore(); }
});

test('a permanent effective-rate modal submits through the same adapter and closes after success', async () => {
    const page = setup({ effective: true });
    try {
        page.submit();
        assert.equal(page.calls.length, 1);
        assert.equal(page.calls[0].options.body.get('currency'), 'USD');
        assert.equal(page.calls[0].options.body.get('rate_to_idr'), '17650');
        page.calls[0].respond(200, { redirect: 'http://portal.test/admin/exchange-rates?tab=effective', message: 'Effective rate saved.' });
        await settle();
        assert.equal(page.navigations.length, 1);
        assert.equal(page.navigations[0].url, 'http://portal.test/admin/exchange-rates?tab=effective');
        assert.equal(page.state.modalHides, 1);
        assert.deepEqual(page.assignments, []);
        assert.equal(page.state.submitting, false);
    } finally { page.restore(); }
});

test('a save completed while the effective modal is opening waits for shown then closes and refreshes', async () => {
    const page = setup({ effective: true });
    try {
        page.state.modalOpening = true;
        page.submit();
        page.calls[0].respond(200, { redirect: 'http://portal.test/admin/exchange-rates?tab=effective', message: 'Effective rate saved.' });
        await settle();
        assert.equal(page.navigations.length, 0);
        page.state.modalOpening = false;
        page.modal.dispatchEvent({ type: 'shown.bs.modal' });
        await settle();
        assert.equal(page.state.modalHides, 1);
        assert.equal(page.navigations.length, 1);
        assert.equal(page.state.submitting, false);
    } finally { page.restore(); }
});

test('sanitizeRateValue cleans letters, normalizes comma, and limits to 4 decimal places', () => {
    assert.equal(sanitizeRateValue('MASIH BISA DIKETIK HURUF'), '');
    assert.equal(sanitizeRateValue('17500'), '17500');
    assert.equal(sanitizeRateValue('105,2'), '105.2');
    assert.equal(sanitizeRateValue('105.2000'), '105.2000');
    assert.equal(sanitizeRateValue('105.200055'), '105.2000');
    assert.equal(sanitizeRateValue('105.2.3.4'), '105.234');
    assert.equal(sanitizeRateValue('USD 18,000.50'), '18.0005');
});

test('annual rate inputs reject alphabet keydown and sanitize pasted input', () => {
    const page = setup();
    try {
        const usdInput = page.fields['rates[USD]'];
        assert.ok(usdInput);

        // Letter keypress should be prevented
        let defaultPrevented = false;
        usdInput.dispatchEvent({
            type: 'keydown',
            key: 'a',
            defaultPrevented: false,
            preventDefault() { this.defaultPrevented = true; defaultPrevented = true; },
        });
        assert.equal(defaultPrevented, true);

        // Digit keypress should not be prevented
        let digitPrevented = false;
        usdInput.dispatchEvent({
            type: 'keydown',
            key: '5',
            defaultPrevented: false,
            preventDefault() { digitPrevented = true; },
        });
        assert.equal(digitPrevented, false);

        // Input sanitization strips non-numeric characters
        usdInput.value = '17500MASIH BISA';
        usdInput.dispatchEvent({ type: 'input' });
        assert.equal(usdInput.value, '17500');

        // Input sanitization converts comma to dot
        usdInput.value = '106,25';
        usdInput.dispatchEvent({ type: 'input' });
        assert.equal(usdInput.value, '106.25');
    } finally { page.restore(); }
});
