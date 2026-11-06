import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import { readFile } from 'node:fs/promises';
import {
    normalizeTargetLocale,
    applySegmentedButtonState,
    morphDomTree,
    bootLocaleSwitcher,
} from '../../resources/js/locale-switcher.js';

function makeClassList(initial = []) {
    const set = new Set(initial);
    return {
        add: (...tokens) => tokens.forEach((t) => set.add(t)),
        remove: (...tokens) => tokens.forEach((t) => set.delete(t)),
        contains: (token) => set.has(token),
        toggle: (token, force) => {
            const shouldAdd = force === undefined ? !set.has(token) : Boolean(force);
            if (shouldAdd) set.add(token);
            else set.delete(token);
            return shouldAdd;
        },
        toArray: () => [...set],
    };
}

function makeTextNode(text) {
    return {
        nodeType: 3,
        nodeValue: text,
        get textContent() {
            return this.nodeValue;
        },
        set textContent(val) {
            this.nodeValue = val;
        },
    };
}

function makeElement(tagName, { attrs = {}, classes = [], children = [], dataset = {}, value = '', checked = false, files = null } = {}) {
    const attrMap = new Map(Object.entries(attrs).map(([k, v]) => [k, String(v)]));
    const el = {
        nodeType: 1,
        tagName: tagName.toUpperCase(),
        dataset: { ...dataset },
        classList: makeClassList(classes),
        childNodes: children,
        value,
        checked,
        files,
        disabled: attrMap.has('disabled'),
        focused: false,
        focus() {
            this.focused = true;
        },
        getAttribute(name) {
            return attrMap.has(name) ? attrMap.get(name) : null;
        },
        setAttribute(name, val) {
            attrMap.set(name, String(val));
            if (name === 'disabled') this.disabled = true;
        },
        removeAttribute(name) {
            attrMap.delete(name);
            if (name === 'disabled') this.disabled = false;
        },
        hasAttribute(name) {
            return attrMap.has(name);
        },
        matches(selector) {
            if (selector.includes(',')) {
                return selector.split(',').some((s) => this.matches(s.trim()));
            }
            if (selector === '[data-locale-ignore]') return attrMap.has('data-locale-ignore') || Boolean(this.dataset.localeIgnore);
            if (selector === '.lang-toggle-track') return this.classList.contains('lang-toggle-track');
            if (selector === '.lang-pill-btn') return this.classList.contains('lang-pill-btn');
            return this.tagName.toLowerCase() === selector.toLowerCase();
        },
        get textContent() {
            return this.childNodes.map((c) => c.textContent ?? c.nodeValue ?? '').join('');
        },
        set textContent(val) {
            this.childNodes = [makeTextNode(String(val))];
        },
        querySelectorAll(selector) {
            const results = [];
            const visit = (node) => {
                if (!node || node.nodeType !== 1) return;
                if (selector === '.lang-toggle-track' && node.classList?.contains('lang-toggle-track')) results.push(node);
                if (selector === '.lang-pill-btn' && node.classList?.contains('lang-pill-btn')) results.push(node);
                if (selector === 'option' && node.tagName === 'OPTION') results.push(node);
                (node.childNodes || []).forEach(visit);
            };
            visit(this);
            return results;
        },
        querySelector(selector) {
            return this.querySelectorAll(selector)[0] ?? null;
        },
    };
    return el;
}

test('normalizeTargetLocale strictly allowlists id and en', () => {
    assert.equal(normalizeTargetLocale('id'), 'id');
    assert.equal(normalizeTargetLocale('ID'), 'id');
    assert.equal(normalizeTargetLocale('en'), 'en');
    assert.equal(normalizeTargetLocale('EN'), 'en');
    assert.equal(normalizeTargetLocale('fr'), 'en');
    assert.equal(normalizeTargetLocale('<script>'), 'en');
    assert.equal(normalizeTargetLocale(null), 'en');
    assert.equal(normalizeTargetLocale(undefined), 'en');
});

test('adasi-i18n runtime supports hot-swapping dictionary payload without losing function references', async () => {
    const scriptText = await readFile(new URL('../../public/assets/js/adasi-i18n.js', import.meta.url), 'utf8');
    const scriptNode = {
        textContent: JSON.stringify({
            locale: 'en',
            messages: {
                'js.toast.completed': 'Completed',
                'js.notification.unread_count': { zero: 'No unread', one: ':count unread item', other: ':count unread items' },
            },
        }),
    };
    const windowRef = {};
    const documentRef = {
        getElementById: (id) => (id === 'adasi-i18n' ? scriptNode : null),
    };

    vm.runInNewContext(scriptText, { window: windowRef, document: documentRef });

    const { t, choice } = windowRef.AdasiI18n;
    assert.equal(windowRef.AdasiI18n.locale, 'en');
    assert.equal(t('js.toast.completed'), 'Completed');
    assert.equal(choice('js.notification.unread_count', 2), '2 unread items');

    windowRef.AdasiI18n.setPayload({
        locale: 'id',
        messages: {
            'js.toast.completed': 'Selesai',
            'js.notification.unread_count': { zero: 'Tidak ada', one: ':count belum dibaca', other: ':count belum dibaca' },
        },
    });

    assert.equal(windowRef.AdasiI18n.locale, 'id');
    assert.equal(t('js.toast.completed'), 'Selesai');
    assert.equal(choice('js.notification.unread_count', 2), '2 belum dibaca');
    assert.equal(JSON.parse(scriptNode.textContent).locale, 'id');
});

test('applySegmentedButtonState switches active pill classes, aria-pressed, disabled, and aria-busy', () => {
    const btnId = makeElement('button', {
        attrs: { value: 'id', 'aria-pressed': 'false' },
        classes: ['lang-pill-btn', 'tw:border-transparent', 'tw:text-on-surface-variant'],
        dataset: { localeOption: 'id' },
    });
    const btnEn = makeElement('button', {
        attrs: { value: 'en', 'aria-pressed': 'true', disabled: 'disabled' },
        classes: ['lang-pill-btn', 'is-active', 'tw:bg-surface', 'tw:text-primary', 'tw:font-bold', 'tw:border', 'tw:border-outline'],
        dataset: { localeOption: 'en' },
    });
    const track = makeElement('form', {
        classes: ['lang-toggle-track'],
        children: [btnId, btnEn],
    });
    const root = makeElement('div', { children: [track] });

    applySegmentedButtonState(root, 'id', true);
    assert.equal(track.getAttribute('aria-busy'), 'true');
    assert.equal(btnId.classList.contains('is-active'), true);
    assert.equal(btnId.getAttribute('aria-pressed'), 'true');
    assert.equal(btnId.disabled, true);
    assert.equal(btnEn.classList.contains('is-active'), false);
    assert.equal(btnEn.getAttribute('aria-pressed'), 'false');
    assert.equal(btnEn.disabled, true, 'while busy, both buttons are disabled to prevent duplicate submits');

    applySegmentedButtonState(root, 'id', false);
    assert.equal(track.getAttribute('aria-busy'), null);
    assert.equal(btnId.disabled, true, 'active locale button stays disabled');
    assert.equal(btnEn.disabled, false, 'inactive locale button becomes clickable when idle');
});

test('morphDomTree updates server text and translated attributes while preserving live input values and file selections', () => {
    const fileStub = { name: 'nib.pdf', size: 10240 };
    const liveTitle = makeElement('h2', { children: [makeTextNode('Account & Company Profile')] });
    const liveTextInput = makeElement('input', {
        attrs: { type: 'text', name: 'company_name', placeholder: 'Enter company name', 'aria-label': 'Company Name' },
        value: 'PT Maju Jaya',
    });
    const liveFileInput = makeElement('input', {
        attrs: { type: 'file', name: 'nib_file', 'aria-label': 'Upload NIB' },
        files: [fileStub],
    });
    const liveOpt1 = makeElement('option', { attrs: { value: 'general' }, children: [makeTextNode('General')] });
    const liveOpt2 = makeElement('option', { attrs: { value: 'raw_material' }, children: [makeTextNode('Raw Material')] });
    const liveSelect = makeElement('select', {
        attrs: { name: 'category', 'aria-label': 'Vendor Category' },
        value: 'raw_material',
        children: [liveOpt1, liveOpt2],
    });
    const liveRoot = makeElement('main', {
        children: [liveTitle, liveTextInput, liveFileInput, liveSelect],
    });

    const serverTitle = makeElement('h2', { children: [makeTextNode('Akun & Profil Perusahaan')] });
    const serverTextInput = makeElement('input', {
        attrs: { type: 'text', name: 'company_name', placeholder: 'Masukkan nama perusahaan', 'aria-label': 'Nama Perusahaan' },
        value: '',
    });
    const serverFileInput = makeElement('input', {
        attrs: { type: 'file', name: 'nib_file', 'aria-label': 'Unggah NIB' },
        files: [],
    });
    const serverOpt1 = makeElement('option', { attrs: { value: 'general' }, children: [makeTextNode('Umum')] });
    const serverOpt2 = makeElement('option', { attrs: { value: 'raw_material' }, children: [makeTextNode('Bahan Baku')] });
    const serverSelect = makeElement('select', {
        attrs: { name: 'category', 'aria-label': 'Kategori Vendor' },
        value: 'general',
        children: [serverOpt1, serverOpt2],
    });
    const serverRoot = makeElement('main', {
        children: [serverTitle, serverTextInput, serverFileInput, serverSelect],
    });

    morphDomTree(liveRoot, serverRoot);

    // Text and translated attributes updated to Indonesian
    assert.equal(liveTitle.textContent, 'Akun & Profil Perusahaan');
    assert.equal(liveTextInput.getAttribute('placeholder'), 'Masukkan nama perusahaan');
    assert.equal(liveTextInput.getAttribute('aria-label'), 'Nama Perusahaan');
    assert.equal(liveFileInput.getAttribute('aria-label'), 'Unggah NIB');
    assert.equal(liveSelect.getAttribute('aria-label'), 'Kategori Vendor');
    assert.equal(liveOpt1.textContent, 'Umum');
    assert.equal(liveOpt2.textContent, 'Bahan Baku');

    // User-entered values and selected files are 100% preserved
    assert.equal(liveTextInput.value, 'PT Maju Jaya');
    assert.deepEqual(liveFileInput.files, [fileStub]);
    assert.equal(liveSelect.value, 'raw_material');
});

test('bootLocaleSwitcher rolls back optimistic pill state and shows toast error when POST /locale fails', async () => {
    const btnId = makeElement('button', {
        attrs: { value: 'id', 'aria-pressed': 'false' },
        classes: ['lang-pill-btn'],
        dataset: { localeOption: 'id' },
    });
    const btnEn = makeElement('button', {
        attrs: { value: 'en', 'aria-pressed': 'true', disabled: 'disabled' },
        classes: ['lang-pill-btn', 'is-active'],
        dataset: { localeOption: 'en' },
    });
    const track = makeElement('form', {
        attrs: { action: 'https://portal.example.test/locale', method: 'POST', 'data-locale-switcher': '' },
        classes: ['lang-toggle-track'],
        children: [btnId, btnEn],
    });

    const toasts = [];
    const doc = Object.assign(new EventTarget(), {
        documentElement: { lang: 'en' },
        title: 'Dashboard',
        querySelector: (sel) => {
            if (sel === 'meta[name="csrf-token"]') return { content: 'csrf-123' };
            if (sel === 'form[data-locale-switcher]') return track;
            return null;
        },
        querySelectorAll: (sel) => {
            if (sel === '.lang-toggle-track') return [track];
            if (sel === '.lang-pill-btn') return [btnId, btnEn];
            return [];
        },
    });
    const win = Object.assign(new EventTarget(), {
        location: {
            href: 'https://portal.example.test/purchasing/dashboard',
            origin: 'https://portal.example.test',
            pathname: '/purchasing/dashboard',
            search: '',
        },
        fetch: async () => ({ ok: false, status: 500, json: async () => ({}) }),
        AdasiToast: { error: (msg) => toasts.push(msg) },
        AdasiI18n: { locale: 'en', t: (k) => k },
    });

    const switcher = bootLocaleSwitcher(win, doc);
    const result = await switcher.switchLocale('id');

    assert.equal(result, false);
    assert.equal(doc.documentElement.lang, 'en');
    assert.equal(btnEn.classList.contains('is-active'), true, 'rolled back to EN');
    assert.equal(btnId.classList.contains('is-active'), false);
    assert.equal(track.getAttribute('aria-busy'), null);
    assert.equal(toasts.length, 1);
});

test('bootLocaleSwitcher completes full server-side switch, morphs DOM regions, invalidates caches, and emits adasi:locale-changed', async () => {
    const btnId = makeElement('button', {
        attrs: { value: 'id', 'aria-pressed': 'false' },
        classes: ['lang-pill-btn'],
        dataset: { localeOption: 'id' },
    });
    const btnEn = makeElement('button', {
        attrs: { value: 'en', 'aria-pressed': 'true', disabled: 'disabled' },
        classes: ['lang-pill-btn', 'is-active'],
        dataset: { localeOption: 'en' },
    });
    const track = makeElement('form', {
        attrs: { action: 'https://portal.example.test/locale', method: 'POST', 'data-locale-switcher': '' },
        classes: ['lang-toggle-track'],
        children: [btnId, btnEn],
    });

    const liveHeading = makeElement('h1', { children: [makeTextNode('Purchase Orders')] });
    const liveMain = makeElement('main', { attrs: { id: 'main-content' }, children: [liveHeading] });
    const serverHeading = makeElement('h1', { children: [makeTextNode('Pesanan Pembelian')] });
    const serverMain = makeElement('main', { attrs: { id: 'main-content' }, children: [serverHeading] });

    let cacheInvalidated = false;
    let snapshotReset = false;
    let i18nUpdated = null;
    const events = [];

    const doc = Object.assign(new EventTarget(), {
        documentElement: { lang: 'en' },
        title: 'Purchase Orders - ADASI',
        querySelector: (sel) => {
            if (sel === 'meta[name="csrf-token"]') return { content: 'csrf-456' };
            if (sel === 'form[data-locale-switcher]') return track;
            if (sel === '#main-content') return liveMain;
            if (sel.includes('.lang-pill-btn[data-locale-option="id"]')) return btnId;
            return null;
        },
        querySelectorAll: (sel) => {
            if (sel === '.lang-toggle-track') return [track];
            if (sel === '.lang-pill-btn') return [btnId, btnEn];
            return [];
        },
    });

    const win = Object.assign(new EventTarget(), {
        CustomEvent: class {
            constructor(type, init) {
                this.type = type;
                this.detail = init?.detail;
            }
        },
        DOMParser: class {
            parseFromString() {
                return {
                    title: 'Pesanan Pembelian - ADASI',
                    querySelector: (sel) => (sel === '#main-content' ? serverMain : null),
                };
            }
        },
        location: {
            href: 'https://portal.example.test/purchasing/orders',
            origin: 'https://portal.example.test',
            pathname: '/purchasing/orders',
            search: '',
        },
        fetch: async (url, init) => {
            if (init?.method === 'POST') {
                return {
                    ok: true,
                    status: 200,
                    json: async () => ({
                        status: 'success',
                        locale: 'id',
                        i18n: { locale: 'id', messages: { 'js.toast.completed': 'Selesai' } },
                        datatables: { search: 'Cari:' },
                        session_copy: { restored: 'Dipulihkan', failed: 'Gagal' },
                    }),
                };
            }
            return {
                ok: true,
                status: 200,
                url: 'https://portal.example.test/purchasing/orders',
                text: async () => '<html></html>',
            };
        },
        AdasiI18n: {
            locale: 'en',
            t: (k) => k,
            setPayload: (p) => { i18nUpdated = p; },
        },
        AdasiServerTabs: {
            invalidateCache: () => { cacheInvalidated = true; },
        },
        AdasiUnsaved: {
            isDirty: () => false,
            resetSnapshot: () => { snapshotReset = true; },
        },
        AdasiSession: {
            copy: { restored: 'Restored', failed: 'Failed' },
        },
    });
    win.dispatchEvent = (evt) => events.push(evt);

    const switcher = bootLocaleSwitcher(win, doc);
    const ok = await switcher.switchLocale('id', { triggerElement: btnId });

    assert.equal(ok, true);
    assert.equal(doc.documentElement.lang, 'id');
    assert.equal(doc.title, 'Pesanan Pembelian - ADASI');
    assert.equal(liveHeading.textContent, 'Pesanan Pembelian');
    assert.equal(cacheInvalidated, true);
    assert.equal(snapshotReset, true);
    assert.equal(i18nUpdated?.locale, 'id');
    assert.equal(win.AdasiSession.copy.restored, 'Dipulihkan');
    assert.equal(btnId.classList.contains('is-active'), true);
    assert.equal(btnId.focused, true);
    assert.equal(events.length, 1);
    assert.equal(events[0].type, 'adasi:locale-changed');
    assert.equal(events[0].detail.locale, 'id');
});

