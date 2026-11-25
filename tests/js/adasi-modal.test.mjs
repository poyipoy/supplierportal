import test from 'node:test';
import assert from 'node:assert/strict';
import { AdasiModal, bootAdasiModals } from '../../resources/js/adasi-modal.js';

function createMockNode(tagName, { id = null, attrs = {}, parentElement = null } = {}) {
    const listeners = new Map();
    const attrMap = new Map(Object.entries(attrs));
    const node = {
        tagName: tagName.toUpperCase(),
        id,
        open: false,
        isConnected: true,
        disabled: false,
        parentElement,
        children: [],
        firstElementChild: null,
        _rect: { left: 100, right: 500, top: 80, bottom: 420, width: 400, height: 340 },
        addEventListener(type, fn) {
            if (!listeners.has(type)) listeners.set(type, new Set());
            listeners.get(type).add(fn);
        },
        removeEventListener(type, fn) {
            listeners.get(type)?.delete(fn);
        },
        dispatchEvent(evt) {
            for (const fn of [...(listeners.get(evt.type) || [])]) {
                fn(evt);
            }
            return !evt.defaultPrevented;
        },
        getAttribute(name) {
            return attrMap.has(name) ? attrMap.get(name) : null;
        },
        setAttribute(name, val) {
            attrMap.set(name, String(val));
        },
        removeAttribute(name) {
            attrMap.delete(name);
        },
        hasAttribute(name) {
            return attrMap.has(name);
        },
        showModal() {
            this.open = true;
            this.setAttribute('open', '');
        },
        close(returnValue = '') {
            this.open = false;
            this.returnValue = returnValue;
            this.removeAttribute('open');
            this.dispatchEvent(new Event('close'));
        },
        getBoundingClientRect() {
            return this._rect;
        },
        querySelector() {
            return this.firstElementChild;
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
                if (selector.includes('[data-ui-modal-open]') && cur.hasAttribute?.('data-ui-modal-open')) return cur;
                if (selector.includes('[data-ui-modal-close]') && cur.hasAttribute?.('data-ui-modal-close')) return cur;
                if (selector.includes('dialog') && cur.tagName === 'DIALOG') return cur;
                if (selector.includes('[popover]') && cur.hasAttribute?.('popover')) return cur;
                cur = cur.parentElement;
            }
            return null;
        },
        focus() {
            if (this.ownerDocument) {
                this.ownerDocument.activeElement = this;
            }
        },
    };
    return node;
}

function createMockDoc() {
    const docListeners = new Map();
    const state = { selectors: new Map() };
    const body = createMockNode('BODY');
    const doc = {
        nodeType: 9,
        body,
        activeElement: body,
        defaultView: globalThis,
        addEventListener(type, fn) {
            if (!docListeners.has(type)) docListeners.set(type, new Set());
            docListeners.get(type).add(fn);
        },
        dispatchEvent(evt) {
            for (const fn of [...(docListeners.get(evt.type) || [])]) {
                fn(evt);
            }
        },
        getElementById(id) {
            return state.selectors.get(`#${id}`) || null;
        },
        querySelector(sel) {
            return state.selectors.get(sel) || null;
        },
        querySelectorAll(sel) {
            return state.selectors.get(`ALL:${sel}`) || [];
        },
        _register(sel, val) {
            state.selectors.set(sel, val);
        },
    };
    body.ownerDocument = doc;
    return { doc, body, state };
}

test('AdasiModal manages singleton lifecycle, cancelable show/hide events, and single close emission', () => {
    const { doc } = createMockDoc();
    const dialog = createMockNode('DIALOG', { id: 'rateModal', attrs: { closedby: 'any' } });
    dialog.ownerDocument = doc;
    const content = createMockNode('DIV', { parentElement: dialog });
    dialog.firstElementChild = content;
    const trigger = createMockNode('BUTTON', { attrs: { 'data-ui-modal-open': '#rateModal' } });
    trigger.ownerDocument = doc;
    doc._register('#rateModal', dialog);

    const events = [];
    let preventNextShow = true;
    dialog.addEventListener('adasi:modal-show', (e) => {
        events.push(['show', e.relatedTarget, e.detail?.relatedTarget]);
        if (preventNextShow) e.preventDefault();
    });
    dialog.addEventListener('adasi:modal-shown', (e) => {
        events.push(['shown', e.relatedTarget]);
    });
    dialog.addEventListener('adasi:modal-hide', () => events.push(['hide']));
    dialog.addEventListener('adasi:modal-hidden', () => events.push(['hidden']));

    assert.equal(AdasiModal.getInstance(dialog), null);
    const modal = AdasiModal.getOrCreateInstance(dialog);
    assert.equal(AdasiModal.getOrCreateInstance('#rateModal', {}, doc), modal);

    // Prevented show leaves dialog closed.
    modal.show(trigger);
    assert.equal(dialog.open, false);

    // Allowed show opens top-layer dialog and passes relatedTarget.
    preventNextShow = false;
    modal.show(trigger);
    assert.equal(dialog.open, true);
    assert.equal(events.some(([t, r]) => t === 'shown' && r === trigger), true);

    // Hide emits adasi:modal-hide and adasi:modal-hidden strictly once even though close() emits native 'close'.
    modal.hide();
    assert.equal(dialog.open, false);
    assert.equal(events.filter(([t]) => t === 'hidden').length, 1);
    assert.equal(doc.activeElement, trigger);

    modal.dispose();
    assert.equal(AdasiModal.getInstance(dialog), null);
});

test('AdasiModal guards Escape cancel on closedby="none", Swal, higher dialogs, and inner calendars', () => {
    const { doc } = createMockDoc();
    const staticDialog = createMockNode('DIALOG', { attrs: { closedby: 'none' } });
    staticDialog.ownerDocument = doc;
    AdasiModal.getOrCreateInstance(staticDialog).show();
    const cancelStatic = new Event('cancel', { cancelable: true });
    staticDialog.dispatchEvent(cancelStatic);
    assert.equal(cancelStatic.defaultPrevented, true);

    const anyDialog = createMockNode('DIALOG', { attrs: { closedby: 'any' } });
    anyDialog.ownerDocument = doc;
    const innerCal = createMockNode('DIV', { parentElement: anyDialog });
    AdasiModal.getOrCreateInstance(anyDialog).show();

    doc._register('.ui-calendar-panel:not([hidden]), .ui-time-panel:not([hidden])', innerCal);
    const cancelWithCal = new Event('cancel', { cancelable: true });
    anyDialog.dispatchEvent(cancelWithCal);
    assert.equal(cancelWithCal.defaultPrevented, true);

    doc._register('.ui-calendar-panel:not([hidden]), .ui-time-panel:not([hidden])', null);
    doc._register('.swal2-container, [data-session-expired-modal][open]', createMockNode('DIV'));
    const cancelWithSwal = new Event('cancel', { cancelable: true });
    anyDialog.dispatchEvent(cancelWithSwal);
    assert.equal(cancelWithSwal.defaultPrevented, true);
});

test('bootAdasiModals delegates triggers, closes enclosing popovers first, and supports backdrop click fallback', () => {
    const { doc, body } = createMockDoc();
    bootAdasiModals(doc);

    const popover = createMockNode('UL', { attrs: { popover: 'auto' }, parentElement: body });
    let popoverClosed = false;
    popover.matches = (sel) => sel === ':popover-open';
    popover.hidePopover = () => { popoverClosed = true; };

    const openBtn = createMockNode('BUTTON', { attrs: { 'data-ui-modal-open': '#importModal' }, parentElement: popover });
    openBtn.ownerDocument = doc;

    const dialog = createMockNode('DIALOG', { id: 'importModal', attrs: { closedby: 'any' }, parentElement: body });
    dialog.ownerDocument = doc;
    const content = createMockNode('DIV', { parentElement: dialog });
    dialog.firstElementChild = content;
    const closeBtn = createMockNode('BUTTON', { attrs: { 'data-ui-modal-close': '' }, parentElement: dialog });
    closeBtn.ownerDocument = doc;

    doc._register('#importModal', dialog);

    // Click delegated open trigger inside popover -> hides popover, opens modal.
    doc.dispatchEvent({ type: 'click', target: openBtn, preventDefault() {} });
    assert.equal(popoverClosed, true);
    assert.equal(dialog.open, true);

    // Outside click fallback closes closedby="any" dialog when pointerdown + click occur outside content rect.
    dialog.dispatchEvent({ type: 'pointerdown', target: dialog, clientX: 5, clientY: 5 });
    dialog.dispatchEvent({ type: 'click', target: dialog, clientX: 5, clientY: 5 });
    assert.equal(dialog.open, false);

    // Reopen then dismiss via delegated [data-ui-modal-close].
    doc.dispatchEvent({ type: 'click', target: openBtn, preventDefault() {} });
    assert.equal(dialog.open, true);
    doc.dispatchEvent({ type: 'click', target: closeBtn, preventDefault() {} });
    assert.equal(dialog.open, false);
});
