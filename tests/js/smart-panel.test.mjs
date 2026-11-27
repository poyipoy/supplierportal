import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import { buildPreviewFrameSrc, initSmartPanel, normalizeTone } from '../../resources/js/smart-panel.js';

function createClassList(initial = []) {
    const set = new Set(initial);
    return {
        add: (...names) => names.forEach((n) => set.add(n)),
        remove: (...names) => names.forEach((n) => set.delete(n)),
        toggle: (name, force) => {
            if (force === undefined) {
                if (set.has(name)) { set.delete(name); return false; }
                set.add(name); return true;
            }
            if (force) { set.add(name); return true; }
            set.delete(name); return false;
        },
        contains: (name) => set.has(name),
    };
}

class MockElement extends EventTarget {
    constructor(tagName = 'div', { id = '', classes = [], attrs = {}, dataset = {} } = {}) {
        super();
        this.tagName = tagName.toUpperCase();
        this.id = id;
        this.classList = createClassList(classes);
        this.attributes = new Map(Object.entries(attrs));
        this.dataset = { ...dataset };
        this.children = [];
        this.textContent = '';
        this.href = attrs.href || '';
        this.hidden = Boolean(attrs.hidden);
        this.focused = false;
        this.offsetParent = {};
    }
    set className(value) {
        this.classList = createClassList(String(value || '').split(/\s+/).filter(Boolean));
    }
    get className() {
        return '';
    }
    getAttribute(k) { return this.attributes.has(k) ? this.attributes.get(k) : null; }
    setAttribute(k, v) {
        this.attributes.set(k, String(v));
        if (k === 'src') this.src = String(v);
        if (k === 'href') this.href = String(v);
    }
    removeAttribute(k) {
        this.attributes.delete(k);
        if (k === 'src') delete this.src;
        if (k === 'href') delete this.href;
    }
    append(...nodes) { this.children.push(...nodes); }
    replaceChildren(...nodes) { this.children = [...nodes]; }
    focus() { this.focused = true; globalThis.document.activeElement = this; }
    click() { this.dispatchEvent(new Event('click', { bubbles: true })); }
    closest(selector) {
        if (selector === '[data-smart-panel-trigger]' && this.attributes.has('data-smart-panel-trigger')) {
            return this;
        }
        return null;
    }
    querySelectorAll() { return []; }
    querySelector() { return null; }
}

function createPanelHarness() {
    const byId = new Map();
    const mk = (tag, id, opts = {}) => {
        const el = new MockElement(tag, { id, ...opts });
        if (id) byId.set(id, el);
        return el;
    };

    const panel = mk('div', 'adasi-smart-panel', { classes: ['tw:hidden'], attrs: { hidden: true } });
    const dialog = mk('div', 'smart-panel-dialog', { classes: ['tw:w-screen', 'tw:max-w-xl'], attrs: { tabindex: '-1' } });
    const closeBtn = mk('button', 'close-btn', { attrs: { 'data-smart-panel-close': '', 'aria-label': 'Close' } });
    const title = mk('h2', 'smart-panel-title');
    const subtitle = mk('span', 'smart-panel-subtitle');
    const status = mk('span', 'smart-panel-status', { classes: ['tw:hidden'], attrs: { hidden: true } });
    const loading = mk('div', 'smart-panel-loading', { classes: ['ui-inline-loading', 'tw:hidden'], attrs: { hidden: true } });
    const error = mk('div', 'smart-panel-error', { classes: ['tw:hidden'], attrs: { hidden: true } });
    const retryBtn = mk('button', 'smart-panel-retry');
    const tabChain = mk('button', 'smart-panel-tab-chain', { attrs: { role: 'tab', 'aria-selected': 'true', tabindex: '0' }, dataset: { smartPanelTab: 'chain' } });
    const tabPreview = mk('button', 'smart-panel-tab-preview', { attrs: { role: 'tab', 'aria-selected': 'false', tabindex: '-1' }, dataset: { smartPanelTab: 'preview' } });
    const tabSla = mk('button', 'smart-panel-tab-sla', { attrs: { role: 'tab', 'aria-selected': 'false', tabindex: '-1' }, dataset: { smartPanelTab: 'sla' } });
    const countChain = mk('span', '', { classes: ['tw:hidden'], attrs: { hidden: true } });
    const countPreview = mk('span', '', { classes: ['tw:hidden'], attrs: { hidden: true } });
    const countSla = mk('span', '', { classes: ['tw:hidden'], attrs: { hidden: true } });
    const chainPane = mk('div', 'smart-panel-pane-chain', { classes: ['tw:hidden'], attrs: { hidden: true } });
    const previewPane = mk('div', 'smart-panel-pane-preview', { classes: ['tw:hidden'], attrs: { hidden: true } });
    const slaPane = mk('div', 'smart-panel-pane-sla', { classes: ['tw:hidden'], attrs: { hidden: true } });
    const docList = mk('div', 'smart-panel-doc-list');
    const expandText = mk('span', '');
    expandText.textContent = 'Full width';
    const expandBtn = mk('button', 'smart-panel-expand-toggle', {
        attrs: { 'aria-pressed': 'false' },
        dataset: { expandLabel: 'Full width', collapseLabel: 'Default width' },
    });
    expandBtn.querySelector = (sel) => (sel === '[data-smart-panel-expand-text]' ? expandText : null);
    const openExternal = mk('a', 'smart-panel-open-external', { classes: ['tw:hidden'], attrs: { href: '#', hidden: true } });
    const frameWrap = mk('div', 'smart-panel-preview-frame-wrap', { classes: ['tw:hidden'], attrs: { hidden: true } });
    const previewLoading = mk('div', 'smart-panel-preview-loading', { classes: ['tw:hidden'], attrs: { hidden: true } });
    const iframe = mk('iframe', 'smart-panel-iframe', { attrs: { title: 'Split PDF Preview' } });
    const noDocs = mk('div', 'smart-panel-no-docs', { classes: ['tw:hidden'], attrs: { hidden: true } });

    const tabs = [tabChain, tabPreview, tabSla];
    const tablist = mk('nav', '', { attrs: { role: 'tablist' } });
    panel.querySelector = (sel) => {
        if (sel === '[data-smart-panel-close][aria-label]') return closeBtn;
        if (sel === '[role="tablist"]') return tablist;
        if (sel === '[data-smart-panel-tab-count="chain"]') return countChain;
        if (sel === '[data-smart-panel-tab-count="preview"]') return countPreview;
        if (sel === '[data-smart-panel-tab-count="sla"]') return countSla;
        return null;
    };
    panel.querySelectorAll = (sel) => {
        if (sel === '[data-smart-panel-tab]') return tabs;
        if (sel === '[data-smart-panel-close]') return [closeBtn];
        return [];
    };
    dialog.querySelectorAll = () => [closeBtn, ...tabs, expandBtn, openExternal];

    const body = new MockElement('body');
    const doc = Object.assign(new EventTarget(), {
        readyState: 'complete',
        dir: 'ltr',
        body,
        activeElement: null,
        getElementById: (id) => byId.get(id) || null,
        createElement: (tag) => new MockElement(tag),
    });

    return {
        doc, panel, dialog, closeBtn, status, loading, error, retryBtn,
        tabChain, tabPreview, tabSla, countChain, countPreview, countSla, tablist,
        chainPane, previewPane, slaPane, docList, expandBtn, expandText, openExternal,
        frameWrap, previewLoading, iframe, noDocs,
        trigger: mk('button', 'trigger', { attrs: { 'data-smart-panel-trigger': '' }, dataset: { smartPanelUrl: '/context-panel/import-po/abc' } }),
    };
}

test('app.css declares ui-inline-loading and chat-drawer-pane before tailwindcss/utilities so tw:hidden wins cleanly', () => {
    const testDir = dirname(fileURLToPath(import.meta.url));
    const appCss = readFileSync(resolve(testDir, '../../resources/css/app.css'), 'utf8');
    const utilitiesIdx = appCss.indexOf('@import "tailwindcss/utilities.css"');
    const inlineLoadingIdx = appCss.indexOf('.ui-inline-loading {');
    const chatPaneIdx = appCss.indexOf('.chat-drawer-pane {');

    assert.ok(utilitiesIdx > 0, 'Expected @import "tailwindcss/utilities.css" in app.css');
    assert.ok(inlineLoadingIdx > 0 && inlineLoadingIdx < utilitiesIdx, '.ui-inline-loading must precede @import "tailwindcss/utilities.css" so tw:hidden hides inline loaders');
    assert.ok(chatPaneIdx > 0 && chatPaneIdx < utilitiesIdx, '.chat-drawer-pane must precede @import "tailwindcss/utilities.css" so tw:hidden hides inactive drawer panes');
    assert.equal(appCss.lastIndexOf('.ui-inline-loading {'), inlineLoadingIdx, 'Duplicate post-utilities .ui-inline-loading rule must not exist');
    assert.equal(appCss.lastIndexOf('.chat-drawer-pane {'), chatPaneIdx, 'Duplicate post-utilities .chat-drawer-pane rule must not exist');
});

test('normalizeTone maps backend tone tokens consistently to ADASI status chip tones', () => {
    assert.equal(normalizeTone('success'), 'success');
    assert.equal(normalizeTone('danger'), 'error');
    assert.equal(normalizeTone('primary'), 'info');
    assert.equal(normalizeTone('WARNING'), 'warning');
    assert.equal(normalizeTone('unknown'), 'neutral');
    assert.equal(normalizeTone(null), 'neutral');
});

test('buildPreviewFrameSrc adds PDF navpane/fit params only to PDF previews without hash', () => {
    assert.equal(
        buildPreviewFrameSrc({
            filename: 'PO/11/2026/001.pdf',
            url: '/shared/pdf/purchase-order/hash123?inline=1',
        }),
        '/shared/pdf/purchase-order/hash123?inline=1#navpanes=0&view=FitH'
    );

    assert.equal(
        buildPreviewFrameSrc({
            filename: 'scan.PNG',
            url: '/attachments/img999',
        }),
        '/attachments/img999'
    );

    assert.equal(
        buildPreviewFrameSrc({
            filename: 'doc.pdf',
            url: '/attachments/pdf1#page=2',
        }),
        '/attachments/pdf1#page=2'
    );
});

test('smart panel hides loading cleanly, applies tone chips, toggles full width, syncs ARIA, and clears preview loader', async () => {
    const h = createPanelHarness();
    globalThis.document = h.doc;
    globalThis.fetch = async () => ({
        ok: true,
        json: async () => ({
            title: 'PO/11/2026/001',
            subtitle: 'Purchase Order',
            status: 'COMPLETED',
            status_tone: 'success',
            nodes: [
                { label: 'PR', reference: 'REQ/11/2026/001', status: 'COMPLETED', tone: 'success', current: false, url: '/purchasing/requisitions/pr1' },
                { label: 'PO', reference: 'PO/11/2026/001', status: 'COMPLETED', tone: 'success', current: true, url: '/purchasing/purchase-orders/po1' },
            ],
            documents: [
                {
                    label: 'Purchase Order PDF',
                    filename: 'PO/11/2026/001.pdf',
                    url: '/shared/pdf/purchase-order/hash1?inline=1',
                    download_url: '/shared/pdf/purchase-order/hash1',
                },
                {
                    label: 'PO Attachment',
                    filename: 'drawing.png',
                    url: '/attachments/att2',
                    download_url: '/attachments/att2?download=1',
                },
            ],
            sla_summary: [{ label: 'Ack', value: 'Pending', tone: 'warning' }],
        }),
    });

    try {
        initSmartPanel();

        const clickEvt = new Event('click');
        Object.defineProperty(clickEvt, 'target', { value: h.trigger });
        h.doc.dispatchEvent(clickEvt);
        await new Promise((r) => setTimeout(r, 10));

        assert.equal(h.panel.classList.contains('tw:hidden'), false);
        assert.equal(h.panel.hidden, false);
        assert.equal(h.loading.classList.contains('tw:hidden'), true);
        assert.equal(h.loading.hidden, true);
        assert.equal(h.status.classList.contains('tw:hidden'), false);
        assert.equal(h.status.classList.contains('ui-status-chip--success'), true);
        assert.equal(h.countChain.textContent, '2');
        assert.equal(h.countPreview.textContent, '2');
        assert.equal(h.countSla.textContent, '1');
        assert.equal(h.dialog.classList.contains('tw:max-w-xl'), true);
        assert.equal(h.iframe.getAttribute('src'), null);

        // Keyboard ArrowRight switches Chain -> Preview cleanly & triggers preview loader until iframe load
        h.tablist.dispatchEvent(Object.assign(new Event('keydown'), { key: 'ArrowRight' }));

        assert.equal(h.tabPreview.getAttribute('aria-selected'), 'true');
        assert.equal(h.tabPreview.getAttribute('tabindex'), '0');
        assert.equal(h.tabChain.getAttribute('tabindex'), '-1');
        assert.equal(h.previewPane.classList.contains('tw:hidden'), false);
        assert.equal(h.previewPane.classList.contains('tw:flex'), true);
        assert.equal(h.dialog.classList.contains('tw:max-w-6xl'), true);
        assert.equal(h.previewLoading.hidden, false);
        assert.equal(h.iframe.getAttribute('src'), '/shared/pdf/purchase-order/hash1?inline=1#navpanes=0&view=FitH');
        assert.equal(h.iframe.getAttribute('title'), 'Purchase Order PDF: PO/11/2026/001.pdf');

        h.iframe.dispatchEvent(new Event('load'));
        assert.equal(h.previewLoading.hidden, true);

        // Expand width toggle sets full width and updates aria-pressed
        h.expandBtn.click();
        assert.equal(h.expandBtn.getAttribute('aria-pressed'), 'true');
        assert.equal(h.expandText.textContent, 'Default width');
        assert.equal(h.dialog.classList.contains('tw:max-w-full'), true);
        assert.equal(h.dialog.classList.contains('tw:max-w-6xl'), false);

        // Switching doc updates aria-pressed & iframe src + restarts preview loader until loaded
        assert.equal(h.docList.children[0].getAttribute('aria-pressed'), 'true');
        h.docList.children[1].click();
        assert.equal(h.docList.children[0].getAttribute('aria-pressed'), 'false');
        assert.equal(h.docList.children[1].getAttribute('aria-pressed'), 'true');
        assert.equal(h.previewLoading.hidden, false);
        assert.equal(h.iframe.getAttribute('src'), '/attachments/att2');
        assert.equal(h.iframe.getAttribute('title'), 'PO Attachment: drawing.png');

        // Escape closes panel cleanly, clears preview loader & dialog-open
        h.doc.dispatchEvent(Object.assign(new Event('keydown'), { key: 'Escape' }));
        assert.equal(h.panel.classList.contains('tw:hidden'), true);
        assert.equal(h.panel.hidden, true);
        assert.equal(h.previewLoading.hidden, true);
        assert.equal(h.iframe.getAttribute('src'), null);
        assert.equal(h.doc.body.classList.contains('ui-dialog-open'), false);

        // Duplicate close on an already-hidden panel is a no-op (does not steal focus back to trigger)
        h.trigger.focused = false;
        h.closeBtn.click();
        assert.equal(h.trigger.focused, false, 'duplicate close must not refocus trigger when panel is already closed');
    } finally {
        delete globalThis.document;
        delete globalThis.fetch;
    }
});

test('smart panel delegates safe timeline links, keeps current & unsafe nodes inert, and handles error retry & empty states', async () => {
    const h = createPanelHarness();
    const navigated = [];
    const released = [];
    const prevDoc = globalThis.document;
    const prevWin = globalThis.window;
    const prevFetch = globalThis.fetch;
    let shouldFailFirst = true;

    globalThis.document = h.doc;
    globalThis.window = {
        location: { origin: 'http://portal.test', href: 'http://portal.test/purchasing/requisitions/abc' },
        AdasiWorkspaceShell: { openUrl() {} },
        AdasiNavigateUrl: (url) => { navigated.push(url); },
        AdasiOverlayModality: {
            acquire() {},
            release: (el, opts) => released.push({ el, opts }),
        },
        addEventListener() {},
    };
    globalThis.fetch = async () => {
        if (shouldFailFirst) {
            shouldFailFirst = false;
            return { ok: false, status: 500 };
        }
        return {
            ok: true,
            json: async () => ({
                title: 'PR/2026/001',
                subtitle: 'Purchase Requisition',
                status: 'ACTIVE',
                status_tone: 'info',
                nodes: [
                    { label: 'PR', reference: 'PR/2026/001', url: '/purchasing/requisitions/abc', status: 'ACTIVE', tone: 'info', current: true },
                    { label: 'PO', reference: 'PO/2026/001', url: '/purchasing/purchase-orders/po-hash', status: 'ACTIVE', tone: 'info', current: false },
                    { label: 'Evil', reference: 'XSS', url: 'javascript:alert(1)', status: 'ACTIVE', tone: 'danger', current: false },
                    { label: 'Ext', reference: 'EXT', url: 'https://evil.test/phish', status: 'ACTIVE', tone: 'neutral', current: false },
                ],
                documents: [],
                sla_summary: [],
            }),
        };
    };

    try {
        initSmartPanel();
        const openEvt = new Event('click');
        Object.defineProperty(openEvt, 'target', { value: h.trigger });
        h.doc.dispatchEvent(openEvt);
        await new Promise((r) => setTimeout(r, 10));

        // Initial error shows retry prompt and hides loading spinner cleanly
        assert.equal(h.loading.hidden, true);
        assert.equal(h.error.hidden, false);

        // Clicking Retry recovers and populates timeline + empty SLA state
        h.retryBtn.click();
        await new Promise((r) => setTimeout(r, 10));

        assert.equal(h.error.hidden, true);
        assert.equal(h.loading.hidden, true);
        assert.equal(h.panel.classList.contains('tw:hidden'), false);
        const timeline = h.chainPane.children[0];
        assert.equal(timeline.tagName, 'OL');

        // Current node stays non-link <strong> with aria-current="step" even when url exists
        const currentItem = timeline.children[0];
        assert.equal(currentItem.getAttribute('aria-current'), 'step');
        assert.equal(currentItem.children[1].children[0].children[0].children[1].tagName, 'STRONG');

        // Sibling safe URL renders navigable <a>
        const poItem = timeline.children[1];
        const link = poItem.children[1].children[0].children[0].children[1];
        assert.equal(link.tagName, 'A');

        // Unsafe javascript: and cross-origin URLs must render as inert <strong>, never <a>
        assert.equal(timeline.children[2].children[1].children[0].children[0].children[1].tagName, 'STRONG');
        assert.equal(timeline.children[3].children[1].children[0].children[0].children[1].tagName, 'STRONG');

        // Empty SLA renders explicit empty state message rather than blank container
        assert.equal(h.slaPane.children.length, 1);

        const linkEvt = Object.assign(new Event('click', { bubbles: true, cancelable: true }), {
            button: 0,
        });
        link.dispatchEvent(linkEvt);

        assert.equal(linkEvt.defaultPrevented, true, 'chain link inside WorkspaceShell must prevent top-window reload');
        assert.equal(h.panel.classList.contains('tw:hidden'), true, 'smart panel must auto-close on WorkspaceShell link navigation');
        assert.deepEqual(navigated, ['/purchasing/purchase-orders/po-hash']);
        assert.equal(released.at(-1)?.opts?.restoreFocus, false, 'auto-close on tab navigation must not pull focus back');
    } finally {
        globalThis.document = prevDoc;
        globalThis.window = prevWin;
        globalThis.fetch = prevFetch;
    }
});

test('smart panel aborts stale fetch responses when reopened rapidly for another document', async () => {
    const h = createPanelHarness();
    const prevDoc = globalThis.document;
    const prevFetch = globalThis.fetch;

    const trigger2 = new MockElement('button', {
        id: 'trigger2',
        attrs: { 'data-smart-panel-trigger': '' },
        dataset: { smartPanelUrl: '/context-panel/import-po/second' },
    });

    let resolveFirst;
    let callCount = 0;
    const abortedSignals = [];

    globalThis.document = h.doc;
    globalThis.fetch = (url, opts = {}) => {
        callCount += 1;
        opts.signal?.addEventListener?.('abort', () => abortedSignals.push(url));
        if (callCount === 1) {
            return new Promise((resolve) => { resolveFirst = resolve; });
        }
        return Promise.resolve({
            ok: true,
            json: async () => ({
                title: 'PO/SECOND',
                subtitle: 'Purchase Order',
                status: 'OPEN',
                status_tone: 'info',
                nodes: [{ label: 'PO', reference: 'PO/SECOND', status: 'OPEN', tone: 'info', current: true }],
                documents: [],
                sla_summary: [],
            }),
        });
    };

    try {
        initSmartPanel();

        const openFirst = new Event('click');
        Object.defineProperty(openFirst, 'target', { value: h.trigger });
        h.doc.dispatchEvent(openFirst);

        const openSecond = new Event('click');
        Object.defineProperty(openSecond, 'target', { value: trigger2 });
        h.doc.dispatchEvent(openSecond);
        await new Promise((r) => setTimeout(r, 10));

        assert.deepEqual(abortedSignals, ['/context-panel/import-po/abc']);
        assert.equal(h.doc.getElementById('smart-panel-title').textContent, 'PO/SECOND');

        // Stale first response resolving late must NOT overwrite newer content
        resolveFirst({
            ok: true,
            json: async () => ({
                title: 'PO/STALE',
                subtitle: 'Purchase Order',
                status: 'OLD',
                nodes: [{ label: 'PO', reference: 'PO/STALE', status: 'OLD', current: true }],
                documents: [],
                sla_summary: [],
            }),
        });
        await new Promise((r) => setTimeout(r, 10));

        assert.equal(h.doc.getElementById('smart-panel-title').textContent, 'PO/SECOND');
    } finally {
        globalThis.document = prevDoc;
        globalThis.fetch = prevFetch;
    }
});