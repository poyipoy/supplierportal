import test from 'node:test';
import assert from 'node:assert/strict';
import { AdasiTabs, bootAdasiTabs } from '../../resources/js/adasi-tabs.js';

function makeNode(tagName, { id = null, attrs = {}, classes = [], parentElement = null } = {}) {
    const attrMap = new Map(Object.entries(attrs));
    const classSet = new Set(classes);
    const children = [];
    const node = {
        tagName: tagName.toUpperCase(),
        id,
        disabled: false,
        hidden: Boolean(attrs.hidden),
        parentElement,
        children,
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
        closest(selector) {
            let cur = this;
            while (cur) {
                if (selector.includes('[role="tab"]') && cur.getAttribute?.('role') === 'tab' && cur.hasAttribute?.('data-ui-tab')) return cur;
                if (selector === '[role="tablist"]' && cur.getAttribute?.('role') === 'tablist') return cur;
                cur = cur.parentElement;
            }
            return null;
        },
        querySelectorAll(sel) {
            if (sel === '[role="tab"][data-ui-tab]') {
                return children.filter((c) => c.getAttribute?.('role') === 'tab');
            }
            return [];
        },
        dispatchEvent(evt) {
            this.ownerDocument?.dispatchEvent?.(evt);
        },
        focus() {
            if (this.ownerDocument) this.ownerDocument.activeElement = this;
        },
    };
    return node;
}

test('AdasiTabs manages aria-selected, roving tabindex, hidden panes, IME guard, and adasi:tab-shown', () => {
    const listeners = new Map();
    const byId = new Map();
    const doc = {
        nodeType: 9,
        defaultView: { CustomEvent },
        activeElement: null,
        addEventListener(type, fn) {
            if (!listeners.has(type)) listeners.set(type, new Set());
            listeners.get(type).add(fn);
        },
        dispatchEvent(evt) {
            for (const fn of [...(listeners.get(evt.type) || [])]) {
                fn(evt);
            }
        },
        getElementById: (id) => byId.get(id) || null,
        querySelector: (sel) => (sel.startsWith('#') ? byId.get(sel.slice(1)) || null : null),
    };

    const tablist = makeNode('DIV', { attrs: { role: 'tablist' } });
    const t1 = makeNode('BUTTON', { id: 'a-tab', attrs: { role: 'tab', 'data-ui-tab': '', 'data-ui-tab-target': '#paneA', 'aria-selected': 'true' }, classes: ['active'], parentElement: tablist });
    const t2 = makeNode('BUTTON', { id: 'b-tab', attrs: { role: 'tab', 'data-ui-tab': '', 'data-ui-tab-target': '#paneB', 'aria-selected': 'false', tabindex: '-1' }, parentElement: tablist });
    const t3 = makeNode('BUTTON', { id: 'c-tab', attrs: { role: 'tab', 'data-ui-tab': '', 'data-ui-tab-target': '#paneC', 'aria-selected': 'false', tabindex: '-1' }, parentElement: tablist });
    t3.disabled = true;
    tablist.children.push(t1, t2, t3);

    const panesParent = makeNode('DIV');
    const p1 = makeNode('DIV', { id: 'paneA', attrs: { role: 'tabpanel' }, classes: ['tab-pane', 'show', 'active'], parentElement: panesParent });
    const p2 = makeNode('DIV', { id: 'paneB', attrs: { role: 'tabpanel', hidden: '' }, classes: ['tab-pane'], parentElement: panesParent });
    p2.hidden = true;
    panesParent.children.push(p1, p2);

    [tablist, t1, t2, t3, panesParent, p1, p2].forEach((n) => { n.ownerDocument = doc; });
    byId.set('paneA', p1);
    byId.set('paneB', p2);

    const events = [];
    doc.addEventListener('adasi:tab-shown', (e) => events.push(e.detail));

    bootAdasiTabs(doc);

    // ArrowRight wraps across enabled tabs (skipping disabled t3).
    t1.focus();
    doc.dispatchEvent({ type: 'keydown', key: 'ArrowRight', target: t1, preventDefault() {} });
    assert.equal(doc.activeElement, t2);
    assert.equal(t2.getAttribute('aria-selected'), 'true');
    assert.equal(t2.hasAttribute('tabindex'), false);
    assert.equal(t1.getAttribute('aria-selected'), 'false');
    assert.equal(t1.getAttribute('tabindex'), '-1');
    assert.equal(p2.hidden, false);
    assert.equal(p1.hidden, true);
    assert.equal(events.length, 1);
    assert.equal(events[0].target, '#paneB');

    // Wrap back to t1 when t2 receives ArrowRight and t3 is disabled.
    doc.dispatchEvent({ type: 'keydown', key: 'ArrowRight', target: t2, preventDefault() {} });
    assert.equal(doc.activeElement, t1);
    assert.equal(p1.hidden, false);

    // IME keyCode 229 is ignored.
    doc.dispatchEvent({ type: 'keydown', key: 'ArrowRight', keyCode: 229, target: t1, preventDefault() {} });
    assert.equal(doc.activeElement, t1);

    // Imperative API matches delegated clicks.
    AdasiTabs.getOrCreateInstance(t2).show();
    assert.equal(p2.hidden, false);
});
