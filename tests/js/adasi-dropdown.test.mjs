import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { AdasiDropdown, bootAdasiDropdowns } from '../../resources/js/adasi-dropdown.js';

function makeNode(tagName, { id = null, attrs = {}, classes = [], parentElement = null } = {}) {
    const listeners = new Map();
    const attrMap = new Map(Object.entries(attrs));
    const classSet = new Set(classes);
    let popoverOpen = false;

    const node = {
        tagName: tagName.toUpperCase(),
        id,
        disabled: false,
        hidden: false,
        isConnected: true,
        parentElement,
        style: {},
        dataset: {},
        _rect: { left: 400, right: 480, top: 720, bottom: 750, width: 180, height: 180 },
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
        addEventListener(type, fn) {
            if (!listeners.has(type)) listeners.set(type, new Set());
            listeners.get(type).add(fn);
        },
        dispatchEvent(evt) {
            if (!evt.target) {
                Object.defineProperty(evt, 'target', { value: this, configurable: true });
            }
            for (const fn of [...(listeners.get(evt.type) || [])]) {
                fn(evt);
            }
            if (evt.bubbles) {
                if (this.parentElement) {
                    this.parentElement.dispatchEvent(evt);
                } else if (this.ownerDocument) {
                    this.ownerDocument.dispatchEvent(evt);
                }
            }
            return !evt.defaultPrevented;
        },
        showPopover() {
            if (popoverOpen) return;
            this.ownerDocument?.dispatchCapture?.('beforetoggle', { target: this, newState: 'open', oldState: 'closed' });
            popoverOpen = true;
            this.ownerDocument?.dispatchCapture?.('toggle', { target: this, newState: 'open', oldState: 'closed' });
        },
        hidePopover() {
            if (!popoverOpen) return;
            popoverOpen = false;
            this.ownerDocument?.dispatchCapture?.('toggle', { target: this, newState: 'closed', oldState: 'open' });
        },
        matches(sel) {
            if (sel === ':popover-open') return popoverOpen;
            if (sel.includes('[data-ui-dropdown-trigger]')) return attrMap.has('data-ui-dropdown-trigger');
            if (sel.includes('[popovertarget]')) return attrMap.has('popovertarget');
            return false;
        },
        closest(selector) {
            let cur = this;
            while (cur) {
                if (selector.includes('[data-ui-dropdown-trigger]') && cur.hasAttribute?.('data-ui-dropdown-trigger')) return cur;
                if (selector.includes('[data-ui-dropdown-menu]') && (cur.hasAttribute?.('data-ui-dropdown-menu') || cur.classList?.contains?.('dropdown-menu'))) return cur;
                if (selector.includes('.dropdown-item') && (cur.classList?.contains?.('dropdown-item') || cur.hasAttribute?.('data-ui-dropdown-item'))) return cur;
                if (selector.includes('label') && (cur.tagName === 'LABEL' || cur.tagName === 'INPUT')) return cur;
                cur = cur.parentElement;
            }
            return null;
        },
        getBoundingClientRect() {
            return this._rect;
        },
        querySelectorAll() {
            return this._queryItems || [];
        },
        focus() {
            if (this.ownerDocument) this.ownerDocument.activeElement = this;
        },
    };
    return node;
}

function makeDoc({ anchorSupported = false } = {}) {
    const captureListeners = new Map();
    const bubbleListeners = new Map();
    const byId = new Map();
    const bySelector = new Map();
    const winListeners = new Map();

    const win = {
        innerWidth: 1024,
        innerHeight: 768,
        CSS: {
            escape: (v) => String(v),
            supports: (prop) => (prop === 'position-area' ? anchorSupported : false),
        },
        CustomEvent,
        addEventListener(type, fn) {
            if (!winListeners.has(type)) winListeners.set(type, new Set());
            winListeners.get(type).add(fn);
        },
        removeEventListener(type, fn) {
            winListeners.get(type)?.delete(fn);
        },
    };

    const body = makeNode('BODY');
    const doc = {
        nodeType: 9,
        body,
        defaultView: win,
        activeElement: body,
        addEventListener(type, fn, capture = false) {
            const store = capture ? captureListeners : bubbleListeners;
            if (!store.has(type)) store.set(type, new Set());
            store.get(type).add(fn);
        },
        dispatchCapture(type, event) {
            for (const fn of [...(captureListeners.get(type) || [])]) {
                fn(event);
            }
        },
        dispatchEvent(evt) {
            for (const fn of [...(bubbleListeners.get(evt.type) || [])]) {
                fn(evt);
            }
        },
        getElementById: (id) => byId.get(id) || null,
        querySelector: (sel) => bySelector.get(sel) || null,
        querySelectorAll: (sel) => bySelector.get(`ALL:${sel}`) || [],
        _register(id, node, triggerSel) {
            byId.set(id, node);
            if (triggerSel) bySelector.set(triggerSel, node);
        },
        _registerQuery(sel, val) {
            bySelector.set(sel, val);
        },
        _winListeners: winListeners,
    };
    body.ownerDocument = doc;
    return { doc, body, win };
}

test('AdasiDropdown manages single active anchor, aria-expanded, adasi:dropdown-show/hidden, and viewport fallback flip', () => {
    const { doc, body } = makeDoc({ anchorSupported: false });
    bootAdasiDropdowns(doc);

    const events = [];
    doc.addEventListener('adasi:dropdown-show', (e) => events.push(['show', e.target.id]));
    doc.addEventListener('adasi:dropdown-hidden', (e) => events.push(['hidden', e.target.id]));

    const trigger = makeNode('BUTTON', {
        id: 'ddTrigger1',
        attrs: { 'data-ui-dropdown-trigger': '', popovertarget: 'dd-pr-abc', 'aria-expanded': 'false' },
        parentElement: body,
    });
    trigger.ownerDocument = doc;
    trigger._rect = { left: 920, right: 1010, top: 700, bottom: 730, width: 90, height: 30 };

    const menu = makeNode('UL', {
        id: 'dd-pr-abc',
        attrs: { popover: 'auto', 'data-ui-dropdown-menu': '' },
        classes: ['dropdown-menu', 'dropdown-menu-end'],
        parentElement: body,
    });
    menu.ownerDocument = doc;
    menu._rect = { left: 0, right: 200, top: 0, bottom: 180, width: 200, height: 180 };

    doc._register('dd-pr-abc', menu);
    doc._registerQuery('[popovertarget="dd-pr-abc"]', trigger);
    doc._registerQuery('ALL:[data-ui-dropdown-open="true"]', []);

    AdasiDropdown.show(trigger, doc);
    assert.equal(trigger.getAttribute('aria-expanded'), 'true');
    assert.equal(trigger.getAttribute('data-ui-dropdown-open'), 'true');
    assert.equal(events[0][0], 'show');
    assert.equal(events[0][1], 'ddTrigger1');
    // Fallback flipped upward above top:700 and clamped inside 1024px width
    assert.equal(menu.style.position, 'fixed');
    assert.ok(parseInt(menu.style.top, 10) < 700);
    assert.ok(parseInt(menu.style.left, 10) <= 1024 - 200 - 8);
    assert.equal(doc._winListeners.get('scroll')?.size, 1);

    AdasiDropdown.hide(menu, doc);
    assert.equal(trigger.getAttribute('aria-expanded'), 'false');
    assert.equal(trigger.getAttribute('data-ui-dropdown-open'), null);
    assert.equal(events[1][0], 'hidden');
    assert.equal(menu.style.position, '');
    assert.equal(doc._winListeners.get('scroll')?.size, 0);
});

test('AdasiDropdown keeps checkbox/label open on outside auto-close menus and closes on action item click microtask', async () => {
    const { doc, body } = makeDoc({ anchorSupported: true });
    bootAdasiDropdowns(doc);

    const trigger = makeNode('BUTTON', {
        attrs: { 'data-ui-dropdown-trigger': '', popovertarget: 'userMenu', 'aria-expanded': 'false' },
        parentElement: body,
    });
    trigger.ownerDocument = doc;

    const menu = makeNode('UL', {
        id: 'userMenu',
        attrs: { popover: 'auto', 'data-ui-dropdown-menu': '', 'data-ui-auto-close': 'outside' },
        classes: ['dropdown-menu'],
        parentElement: body,
    });
    menu.ownerDocument = doc;

    const checkboxLabel = makeNode('LABEL', { parentElement: menu });
    checkboxLabel.ownerDocument = doc;
    const actionBtn = makeNode('BUTTON', { classes: ['dropdown-item'], parentElement: menu });
    actionBtn.ownerDocument = doc;

    doc._register('userMenu', menu);
    doc._registerQuery('[popovertarget="userMenu"]', trigger);
    doc._registerQuery('ALL:[data-ui-dropdown-open="true"]', []);

    AdasiDropdown.show(menu, doc);
    assert.equal(menu.matches(':popover-open'), true);

    // Clicking inside label keeps menu open.
    doc.dispatchEvent({ type: 'click', target: checkboxLabel });
    await Promise.resolve();
    assert.equal(menu.matches(':popover-open'), true);

    // Clicking .dropdown-item hides menu in microtask after click handlers finish.
    doc.dispatchEvent({ type: 'click', target: actionBtn });
    await Promise.resolve();
    assert.equal(menu.matches(':popover-open'), false);
});

test('AdasiDropdown keyboard arrows navigate menu items and Escape restores focus to trigger', () => {
    const { doc, body } = makeDoc({ anchorSupported: true });
    bootAdasiDropdowns(doc);

    const trigger = makeNode('BUTTON', {
        attrs: { 'data-ui-dropdown-trigger': '', popovertarget: 'rowMenu', 'aria-expanded': 'false' },
        parentElement: body,
    });
    trigger.ownerDocument = doc;
    const menu = makeNode('UL', {
        id: 'rowMenu',
        attrs: { popover: 'auto', 'data-ui-dropdown-menu': '' },
        classes: ['dropdown-menu'],
        parentElement: body,
    });
    menu.ownerDocument = doc;
    const item1 = makeNode('A', { classes: ['dropdown-item'], attrs: { href: '/1' }, parentElement: menu });
    const item2 = makeNode('BUTTON', { classes: ['dropdown-item'], parentElement: menu });
    item1.ownerDocument = doc;
    item2.ownerDocument = doc;
    menu._queryItems = [item1, item2];

    doc._register('rowMenu', menu);
    doc._registerQuery('[popovertarget="rowMenu"]', trigger);
    doc._registerQuery('ALL:[data-ui-dropdown-open="true"]', []);

    AdasiDropdown.show(trigger, doc);
    item1.focus();

    doc.dispatchEvent({ type: 'keydown', key: 'ArrowDown', target: item1, preventDefault() {} });
    assert.equal(doc.activeElement, item2);

    doc.dispatchEvent({ type: 'keydown', key: 'Home', target: item2, preventDefault() {} });
    assert.equal(doc.activeElement, item1);

    doc.dispatchEvent({ type: 'keydown', key: 'Escape', target: item1, preventDefault() {} });
    assert.equal(doc.activeElement, trigger);
});

test('AdasiDropdown ensures aria-haspopup="menu" and app.css restores .dropdown-toggle::after chevron + symmetric flip margin', () => {
    const css = readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8');
    assert.match(css, /\.dropdown-toggle::after\s*\{/);
    assert.match(css, /\[data-ui-dropdown-menu\]\[popover\]:popover-open\s*\{[^}]*margin-block:\s*0\.25rem;/s);

    const { doc, body } = makeDoc({ anchorSupported: true });
    bootAdasiDropdowns(doc);
    const trigger = makeNode('BUTTON', {
        attrs: { 'data-ui-dropdown-trigger': '', popovertarget: 'dynMenu', 'aria-expanded': 'false' },
        parentElement: body,
    });
    trigger.ownerDocument = doc;
    const menu = makeNode('UL', { id: 'dynMenu', attrs: { popover: 'auto', 'data-ui-dropdown-menu': '' }, parentElement: body });
    menu.ownerDocument = doc;
    doc._register('dynMenu', menu);
    doc._registerQuery('[popovertarget="dynMenu"]', trigger);
    doc._registerQuery('ALL:[data-ui-dropdown-open="true"]', []);

    AdasiDropdown.show(trigger, doc);
    assert.equal(trigger.getAttribute('aria-haspopup'), 'menu');
});
