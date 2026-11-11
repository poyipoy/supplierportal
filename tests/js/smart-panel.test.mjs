import test from 'node:test';
import assert from 'node:assert/strict';

import { buildPreviewFrameSrc, initSmartPanel } from '../../resources/js/smart-panel.js';

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
        this.focused = false;
        this.offsetParent = {};
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

    const panel = mk('div', 'adasi-smart-panel', { classes: ['tw:hidden'] });
    const dialog = mk('div', 'smart-panel-dialog', { classes: ['tw:w-screen', 'tw:max-w-xl'], attrs: { tabindex: '-1' } });
    const closeBtn = mk('button', 'close-btn', { attrs: { 'data-smart-panel-close': '', 'aria-label': 'Close' } });
    const title = mk('h2', 'smart-panel-title');
    const subtitle = mk('span', 'smart-panel-subtitle');
    const status = mk('span', 'smart-panel-status');
    const loading = mk('div', 'smart-panel-loading');
    const error = mk('div', 'smart-panel-error', { classes: ['tw:hidden'] });
    const tabChain = mk('button', 'smart-panel-tab-chain', { attrs: { role: 'tab', 'aria-selected': 'true', tabindex: '0' }, dataset: { smartPanelTab: 'chain' } });
    const tabPreview = mk('button', 'smart-panel-tab-preview', { attrs: { role: 'tab', 'aria-selected': 'false', tabindex: '-1' }, dataset: { smartPanelTab: 'preview' } });
    const tabSla = mk('button', 'smart-panel-tab-sla', { attrs: { role: 'tab', 'aria-selected': 'false', tabindex: '-1' }, dataset: { smartPanelTab: 'sla' } });
    const chainPane = mk('div', 'smart-panel-pane-chain', { classes: ['tw:hidden'] });
    const previewPane = mk('div', 'smart-panel-pane-preview', { classes: ['tw:hidden'] });
    const slaPane = mk('div', 'smart-panel-pane-sla', { classes: ['tw:hidden'] });
    const docList = mk('div', 'smart-panel-doc-list');
    const expandText = mk('span', '');
    expandText.textContent = 'Full width';
    const expandBtn = mk('button', 'smart-panel-expand-toggle', {
        attrs: { 'aria-pressed': 'false' },
        dataset: { expandLabel: 'Full width', collapseLabel: 'Default width' },
    });
    expandBtn.querySelector = (sel) => (sel === '[data-smart-panel-expand-text]' ? expandText : null);
    const openExternal = mk('a', 'smart-panel-open-external', { classes: ['tw:hidden'], attrs: { href: '#' } });
    const frameWrap = mk('div', 'smart-panel-preview-frame-wrap', { classes: ['tw:hidden'] });
    const iframe = mk('iframe', 'smart-panel-iframe', { attrs: { title: 'Split PDF Preview' } });
    const noDocs = mk('div', 'smart-panel-no-docs', { classes: ['tw:hidden'] });

    const tabs = [tabChain, tabPreview, tabSla];
    const tablist = mk('nav', '', { attrs: { role: 'tablist' } });
    panel.querySelector = (sel) => {
        if (sel === '[data-smart-panel-close][aria-label]') return closeBtn;
        if (sel === '[role="tablist"]') return tablist;
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
        doc, panel, dialog, closeBtn, tabChain, tabPreview, tabSla, tablist,
        chainPane, previewPane, slaPane, docList, expandBtn, expandText, openExternal, frameWrap, iframe,
        trigger: mk('button', 'trigger', { attrs: { 'data-smart-panel-trigger': '' }, dataset: { smartPanelUrl: '/context-panel/import-po/abc' } }),
    };
}

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

test('smart panel widens preview layout, toggles full width, syncs ARIA, and supports keyboard tabs', async () => {
    const h = createPanelHarness();
    globalThis.document = h.doc;
    globalThis.fetch = async () => ({
        ok: true,
        json: async () => ({
            title: 'PO/11/2026/001',
            subtitle: 'Purchase Order',
            status: 'ACTIVE',
            nodes: [{ label: 'PO', reference: 'PO/11/2026/001', status: 'ACTIVE', current: true }],
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
            sla_summary: [{ label: 'Ack', value: 'Pending' }],
        }),
    });

    try {
        initSmartPanel();

        // Simulate clicking trigger via delegated document listener
        const clickEvt = new Event('click');
        Object.defineProperty(clickEvt, 'target', { value: h.trigger });
        h.doc.dispatchEvent(clickEvt);
        await new Promise((r) => setTimeout(r, 10));

        assert.equal(h.panel.classList.contains('tw:hidden'), false);
        assert.equal(h.dialog.classList.contains('tw:max-w-xl'), true);
        assert.equal(h.iframe.getAttribute('src'), null);

        // Keyboard ArrowRight switches Chain -> Preview cleanly
        h.tablist.dispatchEvent(Object.assign(new Event('keydown'), { key: 'ArrowRight' }));

        assert.equal(h.tabPreview.getAttribute('aria-selected'), 'true');
        assert.equal(h.tabPreview.getAttribute('tabindex'), '0');
        assert.equal(h.tabChain.getAttribute('tabindex'), '-1');
        assert.equal(h.previewPane.classList.contains('tw:hidden'), false);
        assert.equal(h.previewPane.classList.contains('tw:flex'), true);
        assert.equal(h.dialog.classList.contains('tw:max-w-6xl'), true);
        assert.equal(h.iframe.getAttribute('src'), '/shared/pdf/purchase-order/hash1?inline=1#navpanes=0&view=FitH');
        assert.equal(h.iframe.getAttribute('title'), 'Purchase Order PDF: PO/11/2026/001.pdf');

        // Expand width toggle sets full width and updates aria-pressed
        h.expandBtn.click();
        assert.equal(h.expandBtn.getAttribute('aria-pressed'), 'true');
        assert.equal(h.expandText.textContent, 'Default width');
        assert.equal(h.dialog.classList.contains('tw:max-w-full'), true);
        assert.equal(h.dialog.classList.contains('tw:max-w-6xl'), false);

        // Switching doc updates aria-pressed & iframe src
        assert.equal(h.docList.children[0].getAttribute('aria-pressed'), 'true');
        h.docList.children[1].click();
        assert.equal(h.docList.children[0].getAttribute('aria-pressed'), 'false');
        assert.equal(h.docList.children[1].getAttribute('aria-pressed'), 'true');
        assert.equal(h.iframe.getAttribute('src'), '/attachments/att2');
        assert.equal(h.iframe.getAttribute('title'), 'PO Attachment: drawing.png');

        // Escape closes panel cleanly and resets dialog-open
        h.doc.dispatchEvent(Object.assign(new Event('keydown'), { key: 'Escape' }));
        assert.equal(h.panel.classList.contains('tw:hidden'), true);
        assert.equal(h.iframe.getAttribute('src'), null);
        assert.equal(h.doc.body.classList.contains('ui-dialog-open'), false);
    } finally {
        delete globalThis.document;
        delete globalThis.fetch;
    }
});
