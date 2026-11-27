import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { AdasiSelect, bootAdasiSelects } from '../../resources/js/adasi-select.js';

function createMockEnv({ anchorSupported = false, viewport = { width: 1024, height: 768 }, popoverSupported = true } = {}) {
    const winListeners = new Map();
    const docCaptureListeners = new Map();
    const docBubbleListeners = new Map();
    const observers = [];

    class MockMutationObserver {
        constructor(callback) {
            this.callback = callback;
            this.targets = [];
            observers.push(this);
        }
        observe(target, options) {
            this.targets.push({ target, options });
        }
        disconnect() {
            this.targets = [];
        }
        trigger(records = [{}]) {
            this.callback(records, this);
        }
    }

    const nativeSelectProto = {
        get value() {
            const opts = this.options;
            if (this._selectedIndex >= 0 && opts[this._selectedIndex]) {
                return opts[this._selectedIndex].value;
            }
            return '';
        },
        set value(val) {
            const s = String(val ?? '');
            const opts = this.options;
            const idx = opts.findIndex((o) => o.value === s);
            this._selectedIndex = idx;
            opts.forEach((o, i) => {
                o._selected = i === idx;
            });
        },
        get selectedIndex() {
            return this._selectedIndex;
        },
        set selectedIndex(idx) {
            const n = Number(idx);
            const opts = this.options;
            if (n >= 0 && n < opts.length) {
                this._selectedIndex = n;
                opts.forEach((o, i) => {
                    o._selected = i === n;
                });
            } else {
                this._selectedIndex = -1;
                opts.forEach((o) => {
                    o._selected = false;
                });
            }
        },
    };

    const win = {
        innerWidth: viewport.width,
        innerHeight: viewport.height,
        CSS: {
            escape: (v) => String(v),
            supports: (prop) => (prop === 'position-area' ? anchorSupported : false),
        },
        Event: class MockEvent {
            constructor(type, init = {}) {
                this.type = type;
                this.bubbles = Boolean(init.bubbles);
                this.defaultPrevented = false;
                this.propagationStopped = false;
            }
            preventDefault() {
                this.defaultPrevented = true;
            }
            stopPropagation() {
                this.propagationStopped = true;
            }
        },
        CustomEvent: class MockCustomEvent {
            constructor(type, init = {}) {
                this.type = type;
                this.bubbles = Boolean(init.bubbles);
                this.detail = init.detail;
                this.defaultPrevented = false;
                this.propagationStopped = false;
            }
            preventDefault() {
                this.defaultPrevented = true;
            }
            stopPropagation() {
                this.propagationStopped = true;
            }
        },
        HTMLSelectElement: { prototype: nativeSelectProto },
        MutationObserver: MockMutationObserver,
        AdasiI18n: {
            t(key) {
                const dict = {
                    'js.select.search_placeholder': 'Search options...',
                    'js.select.empty': 'No matching options',
                    'js.select.clear_search': 'Clear search',
                    'js.select.placeholder': 'Select an option',
                };
                return dict[key] ?? key;
            },
        },
        addEventListener(type, fn) {
            if (!winListeners.has(type)) winListeners.set(type, new Set());
            winListeners.get(type).add(fn);
        },
        removeEventListener(type, fn) {
            winListeners.get(type)?.delete(fn);
        },
        dispatchEvent(evt) {
            for (const fn of [...(winListeners.get(evt.type) || [])]) {
                fn(evt);
            }
        },
    };

    function createNode(tagName, doc) {
        const tag = tagName.toUpperCase();
        const attrs = new Map();
        const classSet = new Set();
        const listeners = new Map();
        const children = [];
        let popoverOpen = false;
        let textVal = '';

        const base = tag === 'SELECT' ? Object.create(nativeSelectProto) : {};

        const node = Object.defineProperties(base, Object.getOwnPropertyDescriptors({
            nodeType: 1,
            tagName: tag,
            ownerDocument: doc,
            parentNode: null,
            parentElement: null,
            children,
            style: {},
            dataset: {},
            disabled: false,
            hidden: false,
            required: false,
            multiple: false,
            isConnected: true,
            _selectedIndex: 0,
            _rect: { left: 100, right: 300, top: 120, bottom: 160, width: 200, height: 40 },
            get id() {
                return attrs.get('id') || '';
            },
            set id(val) {
                if (val) attrs.set('id', String(val));
                else attrs.delete('id');
            },
            get className() {
                return [...classSet].join(' ');
            },
            set className(val) {
                classSet.clear();
                String(val || '').split(/\s+/).filter(Boolean).forEach((c) => classSet.add(c));
            },
            get textContent() {
                if (children.length === 0) return textVal;
                return children.map((c) => c.textContent).join('');
            },
            set textContent(val) {
                children.length = 0;
                textVal = String(val ?? '');
            },
            classList: {
                add: (...names) => names.filter(Boolean).forEach((n) => classSet.add(n)),
                remove: (...names) => names.forEach((n) => classSet.delete(n)),
                contains: (n) => classSet.has(n),
                toggle: (n, force) => {
                    if (force === undefined) {
                        if (classSet.has(n)) {
                            classSet.delete(n);
                            return false;
                        }
                        classSet.add(n);
                        return true;
                    }
                    if (force) {
                        classSet.add(n);
                        return true;
                    }
                    classSet.delete(n);
                    return false;
                },
                [Symbol.iterator]: () => classSet[Symbol.iterator](),
            },
            getAttribute(name) {
                return attrs.has(name) ? attrs.get(name) : null;
            },
            setAttribute(name, val) {
                attrs.set(name, String(val));
                if (name === 'disabled') this.disabled = true;
                if (name === 'hidden') this.hidden = true;
                if (name === 'required') this.required = true;
                if (name === 'multiple') this.multiple = true;
                if (name === 'class') this.className = String(val);
            },
            removeAttribute(name) {
                attrs.delete(name);
                if (name === 'disabled') this.disabled = false;
                if (name === 'hidden') this.hidden = false;
                if (name === 'required') this.required = false;
                if (name === 'multiple') this.multiple = false;
            },
            hasAttribute(name) {
                return attrs.has(name);
            },
            appendChild(child) {
                if (child.nodeType === 11) {
                    const items = [...child.children];
                    child.children.length = 0;
                    items.forEach((item) => this.appendChild(item));
                    return child;
                }
                if (child.parentNode && child.parentNode !== this) {
                    const prev = child.parentNode.children;
                    const idx = prev.indexOf(child);
                    if (idx >= 0) prev.splice(idx, 1);
                }
                child.parentNode = this;
                child.parentElement = this;
                children.push(child);
                return child;
            },
            insertBefore(newNode, refNode) {
                if (newNode.parentNode && newNode.parentNode !== this) {
                    const prev = newNode.parentNode.children;
                    const idx = prev.indexOf(newNode);
                    if (idx >= 0) prev.splice(idx, 1);
                }
                const idx = children.indexOf(refNode);
                newNode.parentNode = this;
                newNode.parentElement = this;
                if (idx >= 0) children.splice(idx, 0, newNode);
                else children.push(newNode);
                return newNode;
            },
            remove() {
                if (this.parentNode) {
                    const siblings = this.parentNode.children;
                    const idx = siblings.indexOf(this);
                    if (idx >= 0) siblings.splice(idx, 1);
                    this.parentNode = null;
                    this.parentElement = null;
                }
            },
            replaceChildren(...nodes) {
                children.forEach((c) => {
                    c.parentNode = null;
                    c.parentElement = null;
                });
                children.length = 0;
                nodes.forEach((n) => this.appendChild(n));
            },
            contains(other) {
                let cur = other;
                while (cur) {
                    if (cur === this) return true;
                    cur = cur.parentNode;
                }
                return false;
            },
            closest(selector) {
                const parts = selector.split(',').map((s) => s.trim());
                let cur = this;
                while (cur && cur.nodeType === 1) {
                    if (parts.some((p) => cur.matches(p))) return cur;
                    cur = cur.parentElement;
                }
                return null;
            },
            matches(selector) {
                if (selector === ':popover-open') return popoverOpen;
                if (selector === ':user-invalid') return false;
                if (selector.startsWith('.')) {
                    return classSet.has(selector.slice(1));
                }
                if (selector === '[data-adasi-select]') return attrs.has('data-adasi-select');
                if (selector === '[x-data^="searchableSelect"]') return (attrs.get('x-data') || '').startsWith('searchableSelect');
                if (selector === 'label') return tag === 'LABEL';
                return false;
            },
            querySelectorAll(selector) {
                const out = [];
                const walk = (root) => {
                    for (const child of root.children) {
                        if (selector === 'select' && child.tagName === 'SELECT') out.push(child);
                        else if (selector.startsWith('.') && child.classList.contains(selector.slice(1))) out.push(child);
                        else if (selector.startsWith('label[for="')) {
                            const targetId = selector.slice('label[for="'.length, -2);
                            if (child.tagName === 'LABEL' && child.getAttribute('for') === targetId) out.push(child);
                        }
                        walk(child);
                    }
                };
                walk(this);
                return out;
            },
            querySelector(selector) {
                return this.querySelectorAll(selector)[0] || null;
            },
            addEventListener(type, fn) {
                if (!listeners.has(type)) listeners.set(type, new Set());
                listeners.get(type).add(fn);
            },
            removeEventListener(type, fn) {
                listeners.get(type)?.delete(fn);
            },
            dispatchEvent(evt) {
                if (!evt.target) evt.target = this;
                for (const fn of [...(listeners.get(evt.type) || [])]) {
                    fn(evt);
                }
                if (evt.bubbles && !evt.propagationStopped) {
                    if (this.parentElement) this.parentElement.dispatchEvent(evt);
                    else doc.dispatchEvent(evt);
                }
                return !evt.defaultPrevented;
            },
            getBoundingClientRect() {
                return this._rect;
            },
            scrollIntoView() {},
            focus() {
                doc.activeElement = this;
                this.dispatchEvent(new win.Event('focus', { bubbles: false }));
            },
        }));

        if (tag === 'OPTION') {
            node._selected = false;
            Object.defineProperty(node, 'value', {
                get() {
                    return attrs.has('value') ? attrs.get('value') : node.textContent;
                },
                set(v) {
                    attrs.set('value', String(v));
                },
            });
            Object.defineProperty(node, 'selected', {
                get() {
                    return node._selected || attrs.has('selected');
                },
                set(v) {
                    node._selected = Boolean(v);
                },
            });
        }

        if (tag === 'OPTGROUP') {
            Object.defineProperty(node, 'label', {
                get() {
                    return attrs.get('label') || '';
                },
                set(v) {
                    attrs.set('label', String(v));
                },
            });
        }

        if (tag === 'SELECT') {
            Object.defineProperty(node, 'options', {
                get() {
                    const out = [];
                    for (const child of children) {
                        if (child.tagName === 'OPTION') out.push(child);
                        else if (child.tagName === 'OPTGROUP') {
                            for (const sub of child.children) {
                                if (sub.tagName === 'OPTION') out.push(sub);
                            }
                        }
                    }
                    return out;
                },
            });
            node.syncInitialSelection = () => {
                const opts = node.options;
                const explicit = opts.findIndex((o) => o.selected);
                node._selectedIndex = explicit >= 0 ? explicit : (opts.length > 0 ? 0 : -1);
            };
        }

        if (tag === 'INPUT') {
            node.value = '';
            node.selectionStart = 0;
            node.selectionEnd = 0;
        }

        if (popoverSupported && tag === 'DIV') {
            node.showPopover = function () {
                if (popoverOpen) return;
                popoverOpen = true;
                this.dispatchEvent({ type: 'toggle', newState: 'open', oldState: 'closed', target: this });
            };
            node.hidePopover = function () {
                if (!popoverOpen) return;
                popoverOpen = false;
                this.dispatchEvent({ type: 'toggle', newState: 'closed', oldState: 'open', target: this });
            };
        }

        return node;
    }

    const doc = {
        nodeType: 9,
        defaultView: win,
        activeElement: null,
        createElement(tag) {
            return createNode(tag, doc);
        },
        createElementNS(_ns, tag) {
            return createNode(tag, doc);
        },
        createDocumentFragment() {
            return { nodeType: 11, children: [], appendChild(c) { this.children.push(c); return c; } };
        },
        addEventListener(type, fn, capture = false) {
            const map = capture ? docCaptureListeners : docBubbleListeners;
            if (!map.has(type)) map.set(type, new Set());
            map.get(type).add(fn);
        },
        removeEventListener(type, fn, capture = false) {
            const map = capture ? docCaptureListeners : docBubbleListeners;
            map.get(type)?.delete(fn);
        },
        dispatchEvent(evt) {
            for (const fn of [...(docCaptureListeners.get(evt.type) || [])]) fn(evt);
            for (const fn of [...(docBubbleListeners.get(evt.type) || [])]) fn(evt);
        },
        querySelectorAll(sel) {
            return doc.body.querySelectorAll(sel);
        },
        querySelector(sel) {
            return doc.body.querySelector(sel);
        },
    };

    doc.body = createNode('BODY', doc);
    doc.documentElement = createNode('HTML', doc);

    function makeSelect({ id = 'status', options = [], attrs = {}, classes = [], form = null } = {}) {
        const select = doc.createElement('select');
        if (id) select.id = id;
        classes.forEach((c) => select.classList.add(c));
        Object.entries(attrs).forEach(([k, v]) => select.setAttribute(k, v));
        if (form) select.form = form;

        for (const entry of options) {
            if (entry.group) {
                const og = doc.createElement('optgroup');
                og.setAttribute('label', entry.group);
                if (entry.disabled) og.setAttribute('disabled', '');
                for (const sub of entry.items) {
                    const opt = doc.createElement('option');
                    opt.value = sub.value;
                    opt.textContent = sub.label;
                    if (sub.disabled) opt.setAttribute('disabled', '');
                    if (sub.selected) opt.setAttribute('selected', '');
                    og.appendChild(opt);
                }
                select.appendChild(og);
            } else {
                const opt = doc.createElement('option');
                opt.value = entry.value;
                opt.textContent = entry.label;
                if (entry.disabled) opt.setAttribute('disabled', '');
                if (entry.selected) opt.setAttribute('selected', '');
                if (entry.hidden) opt.setAttribute('hidden', '');
                select.appendChild(opt);
            }
        }
        select.syncInitialSelection();
        return select;
    }

    return { doc, win, observers, makeSelect };
}

test('enhances eligible single selects and respects exclusions', () => {
    const { doc, makeSelect } = createMockEnv();
    const label = doc.createElement('label');
    label.setAttribute('for', 'status_select');
    label.textContent = 'Status';
    doc.body.appendChild(label);

    const eligible = makeSelect({
        id: 'status_select',
        options: [
            { value: '', label: 'All Status' },
            { value: 'open', label: 'Open', selected: true },
            { value: 'closed', label: 'Closed' },
        ],
    });
    const multi = makeSelect({ id: 'multi', attrs: { multiple: '' }, options: [{ value: '1', label: 'A' }] });
    const listbox = makeSelect({ id: 'sized', attrs: { size: '4' }, options: [{ value: '1', label: 'A' }] });
    const optOut = makeSelect({ id: 'density', attrs: { 'data-native-select': 'true' }, options: [{ value: '1', label: 'A' }] });
    const backing = makeSelect({ id: 'backing', attrs: { 'x-ref': 'nativeSelect' }, options: [{ value: '1', label: 'A' }] });

    [eligible, multi, listbox, optOut, backing].forEach((s) => doc.body.appendChild(s));

    const enhancedCount = bootAdasiSelects(doc);
    assert.equal(enhancedCount, 1);

    const inst = AdasiSelect.getInstance(eligible);
    assert.ok(inst, 'eligible select receives an AdasiSelect instance');
    assert.equal(AdasiSelect.getInstance(multi), null);
    assert.equal(AdasiSelect.getInstance(listbox), null);
    assert.equal(AdasiSelect.getInstance(optOut), null);
    assert.equal(AdasiSelect.getInstance(backing), null);

    assert.equal(eligible.getAttribute('tabindex'), '-1');
    assert.equal(eligible.getAttribute('aria-hidden'), 'true');
    assert.equal(inst.trigger.getAttribute('role'), 'combobox');
    assert.equal(inst.trigger.getAttribute('aria-haspopup'), 'listbox');
    assert.equal(inst.trigger.getAttribute('aria-expanded'), 'false');
    assert.equal(inst.trigger.getAttribute('aria-labelledby'), `${label.id} status_select-value`);
    assert.equal(inst.trigger.textContent.trim(), 'Open');
});

test('selects options via click, dispatches native events, and avoids pointerdown double-toggle race', () => {
    const { doc, win, makeSelect } = createMockEnv();
    const select = makeSelect({
        id: 'currency',
        options: [
            { value: 'IDR', label: 'Indonesian Rupiah', selected: true },
            { value: 'USD', label: 'US Dollar' },
            { value: 'EUR', label: 'Euro', disabled: true },
        ],
    });
    doc.body.appendChild(select);
    const inst = AdasiSelect.enhance(select);

    const fired = [];
    select.addEventListener('input', () => fired.push('input'));
    select.addEventListener('change', () => fired.push('change'));

    // Open via click
    inst.trigger.dispatchEvent(new win.Event('click', { bubbles: true }));
    assert.equal(inst.isOpen(), true);
    assert.equal(inst.trigger.getAttribute('aria-expanded'), 'true');

    // Simulate real browser pointerdown + click on trigger while open -> must close and stay closed
    const pd = new win.Event('pointerdown', { bubbles: true });
    pd.button = 0;
    inst.trigger.dispatchEvent(pd);
    assert.equal(inst.isOpen(), false);
    inst.trigger.dispatchEvent(new win.Event('click', { bubbles: true }));
    assert.equal(inst.isOpen(), false, 'click following pointerdown close must not immediately re-open');

    // Open again and click disabled option -> no change, stays open
    inst.trigger.dispatchEvent(new win.Event('click', { bubbles: true }));
    const optionNodes = inst.listEl.querySelectorAll('.ui-custom-select__option');
    assert.equal(optionNodes.length, 3);
    optionNodes[2].dispatchEvent(new win.Event('click', { bubbles: true }));
    assert.equal(select.value, 'IDR');
    assert.equal(inst.isOpen(), true);

    // Click enabled USD option -> commits and fires input + change
    optionNodes[1].dispatchEvent(new win.Event('click', { bubbles: true }));
    assert.equal(select.value, 'USD');
    assert.equal(inst.isOpen(), false);
    assert.deepEqual(fired, ['input', 'change']);
    assert.equal(doc.activeElement, inst.trigger);
    assert.equal(inst.trigger.textContent.trim(), 'US Dollar');
});

test('supports full WAI-ARIA keyboard navigation, typeahead, and IME composition guard', () => {
    const { doc, win, makeSelect } = createMockEnv();
    const select = makeSelect({
        id: 'stage',
        options: [
            { value: 'draft', label: 'Draft', selected: true },
            { value: 'locked', label: 'Locked', disabled: true },
            { value: 'review', label: 'Review' },
            { value: 'approved', label: 'Approved' },
        ],
    });
    doc.body.appendChild(select);
    const inst = AdasiSelect.enhance(select);

    const key = (k, extra = {}) => Object.assign(new win.Event('keydown', { bubbles: true }), { key: k, ...extra });

    // IME composition Enter must be ignored
    inst.trigger.dispatchEvent(key('Enter', { isComposing: true }));
    assert.equal(inst.isOpen(), false);
    inst.trigger.dispatchEvent(key('Enter', { keyCode: 229 }));
    assert.equal(inst.isOpen(), false);

    // Closed typeahead selects Approved directly
    inst.trigger.dispatchEvent(key('a'));
    assert.equal(select.value, 'approved');
    assert.equal(inst.isOpen(), false);

    // Open with Home -> highlights Draft (index 0)
    inst.trigger.dispatchEvent(key('Home'));
    assert.equal(inst.isOpen(), true);
    assert.equal(inst.trigger.getAttribute('aria-activedescendant'), 'stage-opt-0');

    // ArrowDown skips disabled index 1 and moves to index 2 (Review)
    inst.trigger.dispatchEvent(key('ArrowDown'));
    assert.equal(inst.trigger.getAttribute('aria-activedescendant'), 'stage-opt-2');

    // Escape stops propagation and closes without committing
    const esc = key('Escape');
    inst.trigger.dispatchEvent(esc);
    assert.equal(esc.propagationStopped, true);
    assert.equal(inst.isOpen(), false);
    assert.equal(select.value, 'approved');

    // Open, move to Review, commit with Enter
    inst.trigger.dispatchEvent(key('Home'));
    inst.trigger.dispatchEvent(key('ArrowDown'));
    inst.trigger.dispatchEvent(key('Enter'));
    assert.equal(select.value, 'review');
    assert.equal(inst.isOpen(), false);
});

test('enables Smart Auto-Search above 10 options, filters optgroups, and respects explicit overrides', () => {
    const { doc, win, makeSelect } = createMockEnv();

    const shortSelect = makeSelect({
        id: 'short_sel',
        options: Array.from({ length: 5 }, (_, i) => ({ value: `v${i}`, label: `Item ${i}` })),
    });
    const longSelect = makeSelect({
        id: 'long_sel',
        options: [
            {
                group: 'Asia',
                items: Array.from({ length: 6 }, (_, i) => ({ value: `asia-${i}`, label: `Jakarta Plant ${i}` })),
            },
            {
                group: 'Europe',
                items: Array.from({ length: 6 }, (_, i) => ({ value: `eu-${i}`, label: `Berlin Hub ${i}` })),
            },
        ],
    });
    const forcedOn = makeSelect({
        id: 'forced_on',
        attrs: { 'data-select-search': 'true' },
        options: [{ value: 'a', label: 'Alpha' }, { value: 'b', label: 'Beta' }],
    });
    const forcedOff = makeSelect({
        id: 'forced_off',
        attrs: { 'data-select-search': 'false' },
        options: Array.from({ length: 14 }, (_, i) => ({ value: `x${i}`, label: `Opt ${i}` })),
    });

    [shortSelect, longSelect, forcedOn, forcedOff].forEach((s) => doc.body.appendChild(s));
    const shortInst = AdasiSelect.enhance(shortSelect);
    const longInst = AdasiSelect.enhance(longSelect);
    const onInst = AdasiSelect.enhance(forcedOn);
    const offInst = AdasiSelect.enhance(forcedOff);

    assert.equal(shortInst.searchInput.parentNode.parentNode.hidden, true);
    assert.equal(longInst.searchInput.parentNode.parentNode.hidden, false);
    assert.equal(onInst.searchInput.parentNode.parentNode.hidden, false);
    assert.equal(offInst.searchInput.parentNode.parentNode.hidden, true);

    // Open long select -> focuses search input and renders both optgroups
    longInst.open();
    assert.equal(doc.activeElement, longInst.searchInput);
    assert.equal(longInst.listEl.querySelectorAll('.ui-custom-select__group').length, 2);

    // Filter to Berlin -> Asia optgroup is pruned cleanly
    longInst.searchInput.value = 'Berlin Hub 3';
    longInst.searchInput.dispatchEvent(new win.Event('input', { bubbles: true }));
    assert.equal(longInst.listEl.querySelectorAll('.ui-custom-select__group').length, 1);
    assert.equal(longInst.listEl.querySelectorAll('.ui-custom-select__option').length, 1);

    // IME Enter in search input is ignored
    longInst.searchInput.dispatchEvent(Object.assign(new win.Event('keydown', { bubbles: true }), { key: 'Enter', isComposing: true }));
    assert.equal(longInst.isOpen(), true);

    // Normal Enter commits filtered option
    longInst.searchInput.dispatchEvent(Object.assign(new win.Event('keydown', { bubbles: true }), { key: 'Enter' }));
    assert.equal(longSelect.value, 'eu-3');
    assert.equal(longInst.isOpen(), false);

    // Empty search result shows polite status empty message
    longInst.open();
    longInst.searchInput.value = 'zzzz-no-match';
    longInst.searchInput.dispatchEvent(new win.Event('input', { bubbles: true }));
    const emptyEl = longInst.listEl.querySelector('.ui-custom-select__empty');
    assert.ok(emptyEl);
    assert.equal(emptyEl.getAttribute('role'), 'status');
    assert.equal(emptyEl.textContent, 'No matching options');
});

test('keeps bidirectional sync for programmatic value, selectedIndex, mutations, and destroy', async () => {
    const { doc, win, observers, makeSelect } = createMockEnv();
    const select = makeSelect({
        id: 'vendor_sync',
        options: [
            { value: '1', label: 'Alpha Corp', selected: true },
            { value: '2', label: 'Beta Logistics' },
        ],
    });
    doc.body.appendChild(select);
    const inst = AdasiSelect.enhance(select);

    // Programmatic .value setter updates trigger without throwing
    select.value = '2';
    assert.equal(inst.trigger.textContent.trim(), 'Beta Logistics');

    // Programmatic .selectedIndex setter updates trigger
    select.selectedIndex = 0;
    assert.equal(inst.trigger.textContent.trim(), 'Alpha Corp');

    // Dynamic option + invalid + disabled mutations via MutationObserver
    const newOpt = doc.createElement('option');
    newOpt.value = '3';
    newOpt.textContent = 'Gamma Steel';
    select.appendChild(newOpt);
    select.setAttribute('aria-invalid', 'true');
    observers.forEach((o) => o.trigger());
    await Promise.resolve();

    assert.equal(inst.trigger.getAttribute('aria-invalid'), 'true');
    assert.equal(inst.trigger.getAttribute('data-invalid'), 'true');

    select.value = '3';
    assert.equal(inst.trigger.textContent.trim(), 'Gamma Steel');

    // Escape inside popover stops propagation and closes
    inst.open();
    const popEsc = Object.assign(new win.Event('keydown', { bubbles: true }), { key: 'Escape' });
    inst.popover.dispatchEvent(popEsc);
    assert.equal(popEsc.propagationStopped, true);
    assert.equal(inst.isOpen(), false);

    // Destroy restores native select
    AdasiSelect.destroy(select);
    assert.equal(AdasiSelect.getInstance(select), null);
    assert.equal(select.hasAttribute('data-adasi-select-native'), false);
    assert.equal(select.parentNode, doc.body);
});

test('applies viewport flip and clamp fallback when CSS Anchor Positioning is unavailable', () => {
    const { doc, makeSelect } = createMockEnv({ anchorSupported: false, viewport: { width: 420, height: 600 } });
    const select = makeSelect({
        id: 'bottom_right',
        options: [
            { value: 'a', label: 'First Option' },
            { value: 'b', label: 'Second Option' },
        ],
    });
    doc.body.appendChild(select);
    const inst = AdasiSelect.enhance(select);

    // Place trigger near bottom-right corner
    inst.trigger._rect = { left: 300, right: 410, top: 540, bottom: 580, width: 110, height: 40 };
    inst.popover._rect = { width: 220, height: 220 };

    inst.open();
    assert.equal(inst.popover.style.position, 'fixed');
    const topPx = parseInt(inst.popover.style.top, 10);
    const leftPx = parseInt(inst.popover.style.left, 10);
    assert.ok(topPx < 540, `expected popover to flip above trigger (top=${topPx})`);
    assert.ok(leftPx + 220 <= 420 - 8, `expected popover to clamp inside right viewport edge (left=${leftPx})`);
});

test('renders untrusted option and optgroup labels safely as plain text without HTML injection', () => {
    const { doc, makeSelect } = createMockEnv();
    const xssLabel = '<img src=x onerror=alert(1)>';
    const xssGroup = '<script>window.__xss=1</script>';
    const select = makeSelect({
        id: 'xss_select',
        options: [
            { group: xssGroup, items: [{ value: 'bad', label: xssLabel, selected: true }] },
        ],
    });
    doc.body.appendChild(select);
    const inst = AdasiSelect.enhance(select);
    inst.open();

    assert.equal(inst.trigger.textContent.trim(), xssLabel);
    const grpLabel = inst.listEl.querySelector('.ui-custom-select__group-label');
    const optLabel = inst.listEl.querySelector('.ui-custom-select__option-label');
    assert.equal(grpLabel.textContent, xssGroup);
    assert.equal(optLabel.textContent, xssLabel);
    assert.equal(optLabel.children.length, 0);
});

test('adasi-select CSS and JS satisfy ADASI design-system, accessibility, and security guardrails', () => {
    const css = readFileSync(new URL('../../resources/css/adasi-select.css', import.meta.url), 'utf8');
    const js = readFileSync(new URL('../../resources/js/adasi-select.js', import.meta.url), 'utf8');
    const modalJs = readFileSync(new URL('../../resources/js/adasi-modal.js', import.meta.url), 'utf8');

    assert.doesNotMatch(css, /font-size:\s*[0-9.]+px/, 'adasi-select.css must not use literal px font-size');
    assert.doesNotMatch(css, /#[0-9a-fA-F]{3,8}\b/, 'adasi-select.css must use design tokens instead of raw hex colors');
    assert.doesNotMatch(css, /:focus-visible\s*\{[^}]*outline:\s*(?:0|none)/s, 'adasi-select.css must keep visible focus rings');
    assert.doesNotMatch(css, /\u2014/, 'adasi-select.css must not contain em dashes');
    assert.doesNotMatch(js, /\u2014/, 'adasi-select.js must not contain em dashes');
    assert.doesNotMatch(js, /\.innerHTML\s*=|insertAdjacentHTML/, 'adasi-select.js must never build DOM with innerHTML');

    assert.match(css, /@supports\s*\(position-area:\s*block-end\)/, 'CSS Anchor Positioning must be gated inside @supports');
    assert.match(css, /@starting-style/, 'popover animation must use @starting-style');
    assert.match(css, /transition-behavior:\s*allow-discrete/, 'popover animation must use separate transition-behavior: allow-discrete');
    assert.match(css, /:root\[data-theme="dark"\][\s\S]*--md-surface-container-high/, 'dark mode hover must step up to --md-surface-container-high');
    assert.match(css, /@media\s*\(prefers-reduced-motion:\s*reduce\)/, 'must respect prefers-reduced-motion');
    assert.match(css, /@media\s*\(forced-colors:\s*active\)/, 'must support forced-colors mode');
    assert.match(js, /isComposing\s*\|\|\s*event\.keyCode\s*===\s*229/, 'must guard Enter against IME composition');
    assert.match(modalJs, /\.ui-custom-select__popover\[data-ui-select-open="true"\]/, 'AdasiModal must guard open AdasiSelect popovers on Escape');
});
