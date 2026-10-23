import { installI18n } from './i18n-fixture.mjs';
globalThis.window = installI18n(globalThis.window || {});
import test from 'node:test';
import assert from 'node:assert/strict';
import {
    bootDashboardEditor, buildPayload, computeStateSpans, countChanges, createEditorState, dropPlacement,
    moveWidget, moveWidgetTo, resetToDefault, restoreSnapshot, setVisible,
} from '../../resources/js/dashboard-editor.js';

const WIDGETS = [
    { key: 'admin.summary', label: 'Operational Summary', span: 12, required: false, visible: true, kind: 'metrics' },
    { key: 'admin.rates', label: 'Exchange Rates', span: 8, required: true, visible: true, kind: 'table' },
    { key: 'admin.shortcuts', label: 'Shortcuts', span: 4, required: false, visible: true, kind: 'list' },
    { key: 'admin.notifications', label: 'Recent Activity', span: 12, required: false, visible: false, kind: 'list' },
];
const DEFAULT_ORDER = ['admin.summary', 'admin.rates', 'admin.shortcuts', 'admin.notifications'];
const freshState = () => createEditorState({ widgets: WIDGETS, defaultOrder: DEFAULT_ORDER });

/* ---------------------------------------------------------------- pure state */

test('state starts from the saved order and hidden set and snapshots them', () => {
    const state = freshState();
    assert.deepEqual(state.order, DEFAULT_ORDER);
    assert.deepEqual(state.hidden, ['admin.notifications']);
    assert.deepEqual(state.snapshot, { order: DEFAULT_ORDER, hidden: ['admin.notifications'] });
    assert.equal(countChanges(state), 0);
});

test('moveWidget swaps with the neighbour and is a no-op at either end', () => {
    const state = freshState();
    const down = moveWidget(state, 'admin.summary', 'down');
    assert.deepEqual(down.order, ['admin.rates', 'admin.summary', 'admin.shortcuts', 'admin.notifications']);
    assert.equal(moveWidget(state, 'admin.summary', 'up'), state, 'already first');
    assert.equal(moveWidget(state, 'admin.notifications', 'down'), state, 'already last');
    assert.equal(moveWidget(state, 'admin.unknown', 'up'), state);
    assert.deepEqual(state.order, DEFAULT_ORDER, 'the previous state is never mutated');
});

test('moveWidgetTo drops before or after the target and ignores no-op drops', () => {
    const state = freshState();
    assert.deepEqual(moveWidgetTo(state, 'admin.shortcuts', 'admin.summary', 'before').order,
        ['admin.shortcuts', 'admin.summary', 'admin.rates', 'admin.notifications']);
    assert.deepEqual(moveWidgetTo(state, 'admin.summary', 'admin.shortcuts', 'after').order,
        ['admin.rates', 'admin.shortcuts', 'admin.summary', 'admin.notifications']);
    assert.equal(moveWidgetTo(state, 'admin.rates', 'admin.summary', 'after'), state, 'dropping where it already is');
    assert.equal(moveWidgetTo(state, 'admin.rates', 'admin.rates', 'before'), state);
    assert.equal(moveWidgetTo(state, 'admin.rates', 'admin.unknown', 'before'), state);
});

test('setVisible hides and shows optional panels but never a required one', () => {
    const state = freshState();
    const hidden = setVisible(state, 'admin.shortcuts', false);
    assert.deepEqual(hidden.hidden, ['admin.notifications', 'admin.shortcuts']);
    assert.equal(setVisible(hidden, 'admin.shortcuts', false), hidden, 'already hidden');
    assert.deepEqual(setVisible(hidden, 'admin.shortcuts', true).hidden, ['admin.notifications']);
    assert.equal(setVisible(state, 'admin.rates', false), state, 'required panels stay visible');
    assert.equal(setVisible(state, 'admin.unknown', false), state);
});

test('countChanges counts one reorder plus each panel shown or hidden', () => {
    let state = freshState();
    state = moveWidget(moveWidget(state, 'admin.summary', 'down'), 'admin.summary', 'down');
    assert.equal(countChanges(state), 1, 'several moves are still one reorder');
    state = setVisible(state, 'admin.shortcuts', false);
    state = setVisible(state, 'admin.notifications', true);
    assert.equal(countChanges(state), 3);
    assert.equal(countChanges(restoreSnapshot(state)), 0);
    assert.equal(countChanges(moveWidget(moveWidget(freshState(), 'admin.summary', 'down'), 'admin.summary', 'up')), 0, 'moving back is no change');
});

test('reset applies the default order and shows every panel, and stays undoable', () => {
    let state = moveWidget(setVisible(freshState(), 'admin.shortcuts', false), 'admin.summary', 'down');
    state = resetToDefault(state);
    assert.deepEqual(state.order, DEFAULT_ORDER);
    assert.deepEqual(state.hidden, []);
    assert.equal(countChanges(state), 1, 'the notifications panel is now shown');
    assert.deepEqual(restoreSnapshot(state).hidden, ['admin.notifications']);
});

test('the payload is exactly what the server validates: full order, hidden keys in display order', () => {
    const state = setVisible(moveWidget(freshState(), 'admin.summary', 'down'), 'admin.shortcuts', false);
    assert.deepEqual(buildPayload(state), {
        dashboard: {
            order: ['admin.rates', 'admin.summary', 'admin.shortcuts', 'admin.notifications'],
            hidden: ['admin.shortcuts', 'admin.notifications'],
        },
    });
});

test('spans follow the smart-span rule, and a hidden panel keeps its natural width', () => {
    const state = freshState();
    assert.deepEqual(computeStateSpans(state), { 'admin.summary': 12, 'admin.rates': 8, 'admin.shortcuts': 4, 'admin.notifications': 12 });
    const withoutShortcuts = computeStateSpans(setVisible(state, 'admin.shortcuts', false));
    assert.equal(withoutShortcuts['admin.rates'], 12, 'the survivor fills the row');
    assert.equal(withoutShortcuts['admin.shortcuts'], 4, 'the placeholder keeps its natural span');
});

test('dropPlacement compares on the horizontal axis for shared rows and the vertical axis for full rows', () => {
    const half = { left: 0, top: 0, width: 400, height: 200 };
    assert.deepEqual(dropPlacement(half, { x: 100, y: 190 }, 1000), { position: 'before', edge: 'left' });
    assert.deepEqual(dropPlacement(half, { x: 300, y: 10 }, 1000), { position: 'after', edge: 'right' });
    const full = { left: 0, top: 100, width: 1000, height: 200 };
    assert.deepEqual(dropPlacement(full, { x: 900, y: 150 }, 1000), { position: 'before', edge: 'top' });
    assert.deepEqual(dropPlacement(full, { x: 10, y: 250 }, 1000), { position: 'after', edge: 'bottom' });
    assert.deepEqual(dropPlacement({ left: 0, top: 0, width: 0, height: 0 }, { x: 1, y: 1 }, 1000), { position: 'after', edge: 'bottom' });
});

/* ---------------------------------------------------------------- minimal DOM */

const parseSelector = (selector) => selector.split(',').map((part) => {
    const match = part.trim().match(/^([a-z]*)\[([a-z-]+)(?:="([^"]*)")?\]$/);
    assert.ok(match, `unsupported selector in the fake DOM: ${part}`);
    return { tag: match[1].toUpperCase(), attribute: match[2], value: match[3] };
});

class FakeElement {
    constructor(tag, attributes = {}, children = []) {
        this.tagName = tag.toUpperCase();
        this.attributes = new Map(Object.entries(attributes).map(([name, value]) => [name, String(value)]));
        this.children = [];
        this.parentNode = null;
        this.handlers = new Map();
        this.classes = new Set((attributes.class ?? '').split(/\s+/).filter(Boolean));
        this.textContent = '';
        this.inert = false;
        this.checked = this.attributes.has('checked');
        this.disabled = this.attributes.has('disabled');
        children.forEach((child) => this.append(child));
    }

    get classList() {
        return { add: (name) => this.classes.add(name), remove: (name) => this.classes.delete(name), contains: (name) => this.classes.has(name) };
    }

    get dataset() {
        return new Proxy({}, {
            get: (_, property) => this.attributes.get(`data-${String(property).replace(/[A-Z]/g, (letter) => `-${letter.toLowerCase()}`)}`),
        });
    }

    get hidden() { return this.attributes.has('hidden'); }
    set hidden(value) { if (value) this.attributes.set('hidden', ''); else this.attributes.delete('hidden'); }
    get nextElementSibling() { return this.parentNode?.children[this.parentNode.children.indexOf(this) + 1] ?? null; }

    append(child) {
        child.parentNode?.children.splice(child.parentNode.children.indexOf(child), 1);
        child.parentNode = this;
        this.children.push(child);
        return child;
    }

    insertBefore(node, reference) {
        node.parentNode?.children.splice(node.parentNode.children.indexOf(node), 1);
        node.parentNode = this;
        if (reference === null || reference === undefined) this.children.push(node);
        else this.children.splice(this.children.indexOf(reference), 0, node);
        return node;
    }

    hasAttribute(name) { return this.attributes.has(name); }
    setAttribute(name, value) { this.attributes.set(name, String(value)); }
    removeAttribute(name) { this.attributes.delete(name); }

    matches(selector) {
        return parseSelector(selector).some(({ tag, attribute, value }) => (tag === '' || tag === this.tagName)
            && this.attributes.has(attribute) && (value === undefined || this.attributes.get(attribute) === value));
    }

    closest(selector) {
        for (let node = this; node; node = node.parentNode) if (node.matches(selector)) return node;
        return null;
    }

    querySelectorAll(selector) {
        const found = [];
        const walk = (node) => node.children.forEach((child) => { if (child.matches(selector)) found.push(child); walk(child); });
        walk(this);
        return found;
    }

    querySelector(selector) { return this.querySelectorAll(selector)[0] ?? null; }

    addEventListener(name, handler) { this.handlers.set(name, [...(this.handlers.get(name) ?? []), handler]); }
    removeEventListener(name, handler) { this.handlers.set(name, (this.handlers.get(name) ?? []).filter((item) => item !== handler)); }

    dispatch(type, init = {}) {
        const event = { type, target: this, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; }, ...init };
        for (let node = this; node; node = node.parentNode) (node.handlers.get(type) ?? []).forEach((handler) => handler(event));
        return event;
    }

    focus() { FakeElement.focused = this; }
    getBoundingClientRect() { return this.rect ?? { left: 0, top: 0, width: 100, height: 100 }; }
}

const el = (tag, attributes, children) => new FakeElement(tag, attributes, children);

function strip({ required = false } = {}) {
    return el('div', { 'data-editor-strip': '', hidden: '' }, [
        el('span', { 'data-editor-strip-status': '', hidden: '' }),
        required ? el('span') : el('input', { 'data-editor-visibility': '', checked: '' }),
        el('button', { 'data-editor-move': 'up' }),
        el('button', { 'data-editor-move': 'down' }),
    ]);
}

const ghost = () => el('div', { 'data-editor-ghost': '', hidden: '' }, [el('p', { 'data-editor-ghost-status': '' })]);

function buildDom({ href = 'https://portal.test/admin/dashboard', fetch = async () => ({ ok: true }) } = {}) {
    const classes = { 'admin.summary': 'tw:lg:col-span-12', 'admin.rates': 'tw:lg:col-span-8', 'admin.shortcuts': 'tw:lg:col-span-4', 'admin.notifications': 'tw:lg:col-span-12' };
    const widget = (key, { required = false } = {}) => el('div', { class: `tw:min-w-0 ${classes[key]}`, 'data-dashboard-widget': key, 'data-widget-has-content': 'true' }, [
        strip({ required }), el('div', { class: `content-${key}` }), ghost(),
    ]);
    const placeholder = el('div', { class: `tw:min-w-0 ${classes['admin.notifications']}`, 'data-dashboard-placeholder': 'admin.notifications', hidden: '' }, [strip(), ghost()]);
    placeholder.querySelector('[data-editor-visibility]').checked = false;

    const config = el('script', { 'data-editor-config': '' });
    config.textContent = JSON.stringify({
        saveUrl: 'https://portal.test/profile/dashboard-layout',
        defaultOrder: DEFAULT_ORDER,
        widgets: WIDGETS,
        copy: { unsaved: ':count unsaved changes', noChanges: 'No unsaved changes' },
    });

    const grid = el('div', { 'data-dashboard-audience': 'admin' }, [
        el('div', {}, [el('button', { 'data-editor-enter': '', 'data-dashboard-customize-link': '', hidden: '' })]),
        widget('admin.summary'), widget('admin.rates', { required: true }), widget('admin.shortcuts'), placeholder,
        el('p', { 'data-editor-announce': '' }),
        el('div', { 'data-editor-actionbar': '', hidden: '' }, [
            el('button', { 'data-editor-reset': '' }),
            el('span', { 'data-editor-status': '' }),
            el('button', { 'data-editor-cancel': '' }),
            el('button', { 'data-editor-save': '', disabled: '' }, [el('span', { 'data-editor-saving': '', hidden: '' })]),
        ]),
        config,
    ]);
    grid.rect = { left: 0, top: 0, width: 200, height: 800 };
    const meta = el('meta', { name: 'csrf-token' });
    meta.content = 'token-123';
    const body = el('body', {}, [meta, grid]);

    const calls = { fetch: [], reload: 0, markDirty: 0, markClean: 0, submitting: [], toasts: [], replaced: [] };
    const windowRef = {
        fetch: async (...args) => { calls.fetch.push(args); return fetch(...args); },
        location: { href, reload: () => { calls.reload += 1; } },
        history: { state: null, replaceState: (...args) => calls.replaced.push(args) },
        AdasiUnsaved: { markDirty: () => { calls.markDirty += 1; }, markClean: () => { calls.markClean += 1; }, setSubmitting: (value) => calls.submitting.push(value) },
        AdasiToast: { error: (message) => calls.toasts.push(message) },
        addEventListener: () => {},
        removeEventListener: () => {},
    };
    const editor = bootDashboardEditor({ querySelector: (selector) => body.querySelector(selector) }, windowRef);

    const $ = (selector) => grid.querySelector(selector);
    const wrapper = (key) => grid.querySelector(`[data-dashboard-widget="${key}"]`) ?? grid.querySelector(`[data-dashboard-placeholder="${key}"]`);
    const content = (key) => wrapper(key).children.find((child) => child.classes.has(`content-${key}`));
    const order = () => grid.children
        .filter((child) => child.hasAttribute('data-dashboard-widget') || child.hasAttribute('data-dashboard-placeholder'))
        .map((child) => child.dataset.dashboardWidget ?? child.dataset.dashboardPlaceholder);
    const spanOf = (key) => [...wrapper(key).classes].find((name) => name.startsWith('tw:lg:col-span-'));

    return { editor, grid, calls, $, wrapper, content, order, spanOf };
}

/* ---------------------------------------------------------------- DOM behaviour */

test('booting without a config (a non-owner) does nothing', () => {
    assert.equal(bootDashboardEditor({ querySelector: () => null }, {}), null);
});

test('boot reveals the customize button and leaves the page exactly as rendered', () => {
    const { editor, $, order, spanOf, wrapper } = buildDom();
    assert.equal(editor.isEditing(), false);
    assert.equal($('[data-editor-enter]').hidden, false);
    assert.equal($('[data-editor-actionbar]').hidden, true);
    assert.deepEqual(order(), DEFAULT_ORDER);
    assert.equal(spanOf('admin.rates'), 'tw:lg:col-span-8');
    assert.equal(spanOf('admin.shortcuts'), 'tw:lg:col-span-4');
    assert.equal(wrapper('admin.notifications').hidden, true, 'a placeholder exists only while editing');
    assert.equal(wrapper('admin.summary').querySelector('[data-editor-strip]').hidden, true);
});

test('entering edit mode shows strips and placeholders, makes content inert and hands focus to the first move control', () => {
    const { editor, grid, $, wrapper, content, calls } = buildDom();
    $('[data-editor-enter]').dispatch('click');

    assert.equal(editor.isEditing(), true);
    assert.ok(grid.hasAttribute('data-dashboard-editing'));
    assert.equal($('[data-editor-enter]').hidden, true);
    assert.equal($('[data-editor-actionbar]').hidden, false);
    assert.equal(wrapper('admin.summary').querySelector('[data-editor-strip]').hidden, false);
    assert.equal(content('admin.summary').inert, true);
    assert.equal(wrapper('admin.notifications').hidden, false);
    assert.equal(wrapper('admin.notifications').querySelector('[data-editor-ghost]').hidden, false);
    assert.equal(wrapper('admin.notifications').querySelector('[data-editor-ghost-status]').textContent, 'Hidden');
    assert.equal($('[data-editor-status]').textContent, 'No unsaved changes');
    assert.equal($('[data-editor-save]').disabled, true, 'nothing to save yet');
    assert.match($('[data-editor-announce]').textContent, /Editing the dashboard layout/);
    assert.equal(FakeElement.focused, wrapper('admin.summary').querySelector('[data-editor-move]'));
    assert.equal(calls.markDirty, 0);
});

test('moving a panel reorders the DOM, rebalances widths, counts the change and keeps focus on the control', () => {
    const { grid, $, wrapper, order, spanOf, calls } = buildDom();
    $('[data-editor-enter]').dispatch('click');
    const down = wrapper('admin.summary').querySelectorAll('[data-editor-move]')[1];
    down.dispatch('click');

    assert.deepEqual(order(), ['admin.rates', 'admin.summary', 'admin.shortcuts', 'admin.notifications']);
    assert.ok(grid.children.indexOf($('[data-editor-actionbar]')) > grid.children.indexOf(wrapper('admin.notifications')), 'panels stay before the action bar');
    assert.equal(spanOf('admin.rates'), 'tw:lg:col-span-12', 'rates no longer shares a row, so it widens');
    assert.equal(spanOf('admin.shortcuts'), 'tw:lg:col-span-12');
    assert.equal($('[data-editor-status]').textContent, '1 unsaved changes');
    assert.equal($('[data-editor-save]').disabled, false);
    assert.equal(FakeElement.focused, down);
    assert.match($('[data-editor-announce]').textContent, /Operational Summary is at position 2 of 4/);
    assert.equal(calls.markDirty, 1);
});

test('hiding a panel conceals its content behind a placeholder and widens its row partner', () => {
    const { $, wrapper, content, spanOf, calls } = buildDom();
    $('[data-editor-enter]').dispatch('click');
    const toggle = wrapper('admin.shortcuts').querySelector('[data-editor-visibility]');
    toggle.checked = false;
    toggle.dispatch('change');

    assert.equal(content('admin.shortcuts').hasAttribute('data-editor-concealed'), true);
    assert.equal(wrapper('admin.shortcuts').querySelector('[data-editor-ghost]').hidden, false);
    assert.equal(wrapper('admin.shortcuts').querySelector('[data-editor-strip-status]').textContent, 'Hidden');
    assert.equal(spanOf('admin.rates'), 'tw:lg:col-span-12');
    assert.equal(spanOf('admin.shortcuts'), 'tw:lg:col-span-4', 'the placeholder keeps its natural width');
    assert.equal($('[data-editor-status]').textContent, '1 unsaved changes');
    assert.equal(calls.markDirty, 1);

    toggle.checked = true;
    toggle.dispatch('change');
    assert.equal(content('admin.shortcuts').hasAttribute('data-editor-concealed'), false, 'showing it again needs no reload');
    assert.equal(spanOf('admin.rates'), 'tw:lg:col-span-8');
    assert.equal($('[data-editor-status]').textContent, 'No unsaved changes');
    assert.equal(calls.markClean, 1);
});

test('showing a panel that was hidden at page load says it appears after saving', () => {
    const { $, wrapper } = buildDom();
    $('[data-editor-enter]').dispatch('click');
    const toggle = wrapper('admin.notifications').querySelector('[data-editor-visibility]');
    toggle.checked = true;
    toggle.dispatch('change');

    assert.equal(wrapper('admin.notifications').querySelector('[data-editor-ghost-status]').textContent, 'Shown after saving');
    assert.equal(wrapper('admin.notifications').querySelector('[data-editor-ghost]').hidden, false, 'there is no data to show yet');
    assert.match($('[data-editor-announce]').textContent, /Recent Activity: Shown after saving/);
});

test('cancel restores order, widths and visibility, closes the editor and returns focus', () => {
    const { editor, grid, $, wrapper, content, order, spanOf, calls } = buildDom();
    $('[data-editor-enter]').dispatch('click');
    wrapper('admin.summary').querySelectorAll('[data-editor-move]')[1].dispatch('click');
    const toggle = wrapper('admin.shortcuts').querySelector('[data-editor-visibility]');
    toggle.checked = false;
    toggle.dispatch('change');
    $('[data-editor-cancel]').dispatch('click');

    assert.equal(editor.isEditing(), false);
    assert.equal(grid.hasAttribute('data-dashboard-editing'), false);
    assert.deepEqual(order(), DEFAULT_ORDER);
    assert.equal(spanOf('admin.rates'), 'tw:lg:col-span-8');
    assert.equal(toggle.checked, true);
    assert.equal(content('admin.shortcuts').hasAttribute('data-editor-concealed'), false);
    assert.equal(content('admin.summary').inert, false);
    assert.equal(wrapper('admin.notifications').hidden, true);
    assert.equal($('[data-editor-actionbar]').hidden, true);
    assert.equal(FakeElement.focused, $('[data-editor-enter]'));
    assert.equal(calls.markClean, 1, 'the leave-page guard is released');
});

test('reset applies the default layout in the editor and still needs saving', () => {
    const { $, wrapper, order, calls } = buildDom();
    $('[data-editor-enter]').dispatch('click');
    wrapper('admin.summary').querySelectorAll('[data-editor-move]')[1].dispatch('click');
    $('[data-editor-reset]').dispatch('click');

    assert.deepEqual(order(), DEFAULT_ORDER);
    assert.equal(wrapper('admin.notifications').querySelector('[data-editor-ghost-status]').textContent, 'Shown after saving');
    assert.equal($('[data-editor-status]').textContent, '1 unsaved changes', 'only the notifications panel differs from what is saved');
    assert.equal($('[data-editor-save]').disabled, false);
    assert.match($('[data-editor-announce]').textContent, /Default layout applied/);
    assert.equal(calls.fetch.length, 0, 'nothing is sent until Save is pressed');
});

test('save sends the payload with the CSRF token, then reloads and releases the leave-page guard', async () => {
    const { editor, $, wrapper, calls } = buildDom();
    $('[data-editor-enter]').dispatch('click');
    wrapper('admin.summary').querySelectorAll('[data-editor-move]')[1].dispatch('click');
    const saved = editor.save();
    assert.equal($('[data-editor-save]').disabled, true, 'a second click cannot double-submit');
    assert.equal($('[data-editor-saving]').hidden, false);
    await saved;

    assert.equal(calls.fetch.length, 1);
    const [url, init] = calls.fetch[0];
    assert.equal(url, 'https://portal.test/profile/dashboard-layout');
    assert.equal(init.method, 'PATCH');
    assert.equal(init.headers['X-CSRF-TOKEN'], 'token-123');
    assert.equal(init.headers.Accept, 'application/json');
    assert.deepEqual(JSON.parse(init.body), { dashboard: { order: ['admin.rates', 'admin.summary', 'admin.shortcuts', 'admin.notifications'], hidden: ['admin.notifications'] } });
    assert.equal(calls.reload, 1);
    assert.deepEqual(calls.submitting, [true]);
    await editor.save();
    assert.equal(calls.fetch.length, 1, 'still busy until the page reloads');
});

test('a rejected or failed save stays in edit mode, reports the error and re-enables the buttons', async () => {
    for (const failing of [async () => ({ ok: false, status: 422 }), async () => { throw new Error('offline'); }]) {
        const { editor, $, wrapper, calls } = buildDom({ fetch: failing });
        $('[data-editor-enter]').dispatch('click');
        wrapper('admin.summary').querySelectorAll('[data-editor-move]')[1].dispatch('click');
        await editor.save();

        assert.equal(editor.isEditing(), true);
        assert.equal(calls.reload, 0);
        assert.deepEqual(calls.toasts, ['The dashboard layout could not be saved. Please try again.']);
        assert.equal($('[data-editor-save]').disabled, false);
        assert.equal($('[data-editor-cancel]').disabled, false);
        assert.equal($('[data-editor-saving]').hidden, true);
        assert.deepEqual(calls.submitting, []);
    }
});

test('save does nothing when there are no changes', async () => {
    const { editor, $, calls } = buildDom();
    $('[data-editor-enter]').dispatch('click');
    await editor.save();
    assert.equal(calls.fetch.length, 0);
});

test('dragging a strip onto another panel reorders by the pointer side and shows where it will land', () => {
    const { $, wrapper, order, grid } = buildDom();
    $('[data-editor-enter]').dispatch('click');
    wrapper('admin.rates').rect = { left: 0, top: 0, width: 100, height: 100 };

    const dragged = wrapper('admin.shortcuts');
    const transfer = { data: {}, setData(type, value) { this.data[type] = value; }, setDragImage() {} };
    dragged.querySelector('[data-editor-strip]').dispatch('dragstart', { dataTransfer: transfer });
    assert.equal(transfer.data['text/plain'], 'admin.shortcuts');
    assert.ok(dragged.hasAttribute('data-dragging'));

    const over = wrapper('admin.rates').dispatch('dragover', { clientX: 20, clientY: 50, dataTransfer: transfer });
    assert.equal(over.defaultPrevented, true, 'the panel accepts the drop');
    assert.equal(wrapper('admin.rates').dataset.dropEdge, 'left');

    wrapper('admin.rates').dispatch('drop', { clientX: 20, clientY: 50 });
    assert.deepEqual(order(), ['admin.summary', 'admin.shortcuts', 'admin.rates', 'admin.notifications']);
    assert.equal(dragged.hasAttribute('data-dragging'), false);
    assert.equal(wrapper('admin.rates').hasAttribute('data-drop-edge'), false);
    assert.equal($('[data-editor-status]').textContent, '1 unsaved changes');
    assert.equal(grid.hasAttribute('data-dashboard-editing'), true);
});

test('dragging over the panel being dragged or outside any panel does not accept a drop', () => {
    const { $, wrapper, order, grid } = buildDom();
    $('[data-editor-enter]').dispatch('click');
    const dragged = wrapper('admin.shortcuts');
    dragged.querySelector('[data-editor-strip]').dispatch('dragstart', { dataTransfer: { setData() {} } });

    assert.equal(dragged.dispatch('dragover', { clientX: 1, clientY: 1 }).defaultPrevented, false);
    assert.equal(grid.dispatch('dragover', { clientX: 1, clientY: 1 }).defaultPrevented, false);
    grid.dispatch('drop', {});
    assert.deepEqual(order(), DEFAULT_ORDER);
    assert.equal(dragged.hasAttribute('data-dragging'), false);
});

test('drags are ignored outside edit mode', () => {
    const { wrapper, order } = buildDom();
    wrapper('admin.shortcuts').querySelector('[data-editor-strip]').dispatch('dragstart', { dataTransfer: { setData() {} } });
    wrapper('admin.rates').dispatch('drop', { clientX: 1, clientY: 1 });
    assert.deepEqual(order(), DEFAULT_ORDER);
});

test('?customize=1 opens edit mode and removes the parameter from the address', () => {
    const { editor, calls } = buildDom({ href: 'https://portal.test/admin/dashboard?customize=1&tab=a#top' });
    assert.equal(editor.isEditing(), true);
    assert.deepEqual(calls.replaced, [[null, '', '/admin/dashboard?tab=a#top']]);

    const plain = buildDom({ href: 'https://portal.test/admin/dashboard?customize=0' });
    assert.equal(plain.editor.isEditing(), false);
    assert.deepEqual(plain.calls.replaced, []);
});

test('required panels expose no hide switch in their strip', () => {
    const { wrapper } = buildDom();
    assert.equal(wrapper('admin.rates').querySelector('[data-editor-visibility]'), null);
});
