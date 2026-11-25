import test from 'node:test';
import assert from 'node:assert/strict';
import { AdasiTooltip, bootAdasiTooltips } from '../../resources/js/adasi-tooltip.js';

function makeNode(tagName, { id = null, attrs = {}, parentElement = null } = {}) {
    const attrMap = new Map(Object.entries(attrs));
    const classSet = new Set();
    let popoverOpen = false;
    const node = {
        tagName: tagName.toUpperCase(),
        id,
        hidden: false,
        isConnected: true,
        parentElement,
        children: [],
        style: {},
        textContent: '',
        classList: {
            add: (...names) => names.forEach((n) => classSet.add(n)),
            remove: (...names) => names.forEach((n) => classSet.delete(n)),
            contains: (n) => classSet.has(n),
            toggle: (n, force) => (force === undefined ? (classSet.has(n) ? (classSet.delete(n), false) : (classSet.add(n), true)) : (force ? (classSet.add(n), true) : (classSet.delete(n), false))),
        },
        getAttribute: (name) => (attrMap.has(name) ? attrMap.get(name) : null),
        setAttribute: (name, val) => attrMap.set(name, String(val)),
        removeAttribute: (name) => attrMap.delete(name),
        hasAttribute: (name) => attrMap.has(name),
        appendChild(child) {
            child.parentElement = node;
            node.children.push(child);
            return child;
        },
        showPopover() { popoverOpen = true; },
        hidePopover() { popoverOpen = false; },
        matches(sel) {
            if (sel === ':popover-open') return popoverOpen;
            return false;
        },
        contains(target) {
            let cur = target;
            while (cur) {
                if (cur === this) return true;
                cur = cur.parentElement;
            }
            return false;
        },
        closest(selector) {
            let cur = this;
            while (cur) {
                if (selector === '[data-ui-tooltip]' && cur.hasAttribute?.('data-ui-tooltip')) return cur;
                if (selector === '#adasiTooltipPopover' && cur.id === 'adasiTooltipPopover') return cur;
                cur = cur.parentElement;
            }
            return null;
        },
        getBoundingClientRect: () => ({ left: 20, right: 60, top: 100, bottom: 132, width: 40, height: 32 }),
        focus() {
            if (this.ownerDocument) this.ownerDocument.activeElement = this;
        },
    };
    return node;
}

function makeDoc({ sidebarCollapsed = 'false' } = {}) {
    const bubbleListeners = new Map();
    const captureListeners = new Map();
    const body = makeNode('BODY');
    const docEl = { dataset: { sidebarCollapsed } };
    const doc = {
        nodeType: 9,
        body,
        documentElement: docEl,
        activeElement: body,
        defaultView: {
            innerWidth: 1024,
            innerHeight: 768,
            CSS: { supports: () => false },
        },
        createElement: (tag) => {
            const el = makeNode(tag);
            el.ownerDocument = doc;
            return el;
        },
        getElementById: (id) => (body.children.find((c) => c.id === id) || null),
        addEventListener(type, fn, capture = false) {
            const store = capture ? captureListeners : bubbleListeners;
            if (!store.has(type)) store.set(type, new Set());
            store.get(type).add(fn);
        },
        dispatchEvent(evt) {
            for (const fn of [...(captureListeners.get(evt.type) || [])]) {
                fn(evt);
            }
            for (const fn of [...(bubbleListeners.get(evt.type) || [])]) {
                fn(evt);
            }
        },
    };
    body.ownerDocument = doc;
    return { doc, body, docEl };
}

test('AdasiTooltip shows lazily per document, preserves existing aria-describedby error IDs, and dismisses cleanly on Esc', () => {
    const { doc, body } = makeDoc();
    bootAdasiTooltips(doc);

    const fieldBtn = makeNode('BUTTON', {
        attrs: {
            'data-ui-tooltip': 'Helpful explanation',
            'aria-describedby': 'rate-error-msg',
            title: 'Helpful explanation',
        },
        parentElement: body,
    });
    fieldBtn.ownerDocument = doc;
    fieldBtn.focus();

    doc.dispatchEvent({ type: 'focusin', target: fieldBtn });

    const tip = doc.getElementById('adasiTooltipPopover');
    assert.ok(tip, 'tooltip element lazily created in active document body');
    assert.equal(tip.hidden, false);
    assert.equal(tip.textContent, 'Helpful explanation');
    assert.equal(tip.getAttribute('role'), 'tooltip');
    assert.equal(tip.getAttribute('popover'), 'manual');
    // Token preserved without losing rate-error-msg, and native title suppressed
    assert.equal(fieldBtn.getAttribute('aria-describedby'), 'rate-error-msg adasiTooltipPopover');
    assert.equal(fieldBtn.getAttribute('title'), null);

    // live locale sync via refresh()
    fieldBtn.setAttribute('data-ui-tooltip', 'Penjelasan bermanfaat');
    AdasiTooltip.refresh(doc);
    assert.equal(tip.textContent, 'Penjelasan bermanfaat');

    // Escape dismisses tooltip without moving focus, restores original aria-describedby + title
    let stopped = false;
    doc.dispatchEvent({ type: 'keydown', key: 'Escape', stopPropagation() { stopped = true; } });
    assert.equal(stopped, true);
    assert.equal(tip.hidden, true);
    assert.equal(doc.activeElement, fieldBtn);
    assert.equal(fieldBtn.getAttribute('aria-describedby'), 'rate-error-msg');
    assert.equal(fieldBtn.getAttribute('title'), 'Helpful explanation');
});

test('AdasiTooltip gates sidebar tooltips strictly on data-sidebar-collapsed="true" and hides when expanded', () => {
    const { doc, body, docEl } = makeDoc({ sidebarCollapsed: 'false' });
    bootAdasiTooltips(doc);

    const sidebarItem = makeNode('A', {
        attrs: { 'data-sidebar-tooltip': '', 'data-ui-tooltip': 'Purchase Orders' },
        parentElement: body,
    });
    sidebarItem.ownerDocument = doc;

    doc.dispatchEvent({ type: 'focusin', target: sidebarItem });
    assert.equal(AdasiTooltip.getElement(doc), null, 'expanded sidebar must suppress sidebar tooltip');

    docEl.dataset.sidebarCollapsed = 'true';
    doc.dispatchEvent({ type: 'focusin', target: sidebarItem });
    const tip = AdasiTooltip.getElement(doc);
    assert.equal(tip.hidden, false);
    assert.equal(tip.classList.contains('sidebar-nav-tooltip'), true);
    assert.equal(tip.getAttribute('data-ui-placement'), 'right');

    docEl.dataset.sidebarCollapsed = 'false';
    AdasiTooltip.syncSidebarState(false, doc);
    assert.equal(tip.hidden, true);
});
