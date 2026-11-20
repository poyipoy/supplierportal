import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { bindLive } from '../../resources/js/filter-bar.js';

function makeClassList(initial = []) {
    const classes = new Set(initial);
    return {
        add: (...names) => names.forEach((n) => classes.add(n)),
        remove: (...names) => names.forEach((n) => classes.delete(n)),
        contains: (n) => classes.has(n),
        toggle: (n, force) => {
            if (force === undefined) {
                if (classes.has(n)) {
                    classes.delete(n);
                    return false;
                }
                classes.add(n);
                return true;
            }
            if (force) {
                classes.add(n);
                return true;
            }
            classes.delete(n);
            return false;
        },
    };
}

function makeFakeNode(tagName, props = {}) {
    const listeners = {};
    const attributes = new Map();
    const children = [];
    const node = {
        tagName: tagName.toUpperCase(),
        type: props.type || '',
        value: props.value ?? '',
        checked: props.checked ?? false,
        dataset: { ...(props.dataset || {}) },
        classList: makeClassList(props.classes || []),
        className: (props.classes || []).join(' '),
        textContent: '',
        children,
        options: props.options || null,
        selectedIndex: props.selectedIndex ?? 0,
        _closestTray: props.inTray || false,
        _closestEnhanced: props.enhanced || false,
        get firstChild() {
            return children[0] || null;
        },
        appendChild(child) {
            children.push(child);
            return child;
        },
        removeChild(child) {
            const idx = children.indexOf(child);
            if (idx >= 0) children.splice(idx, 1);
            return child;
        },
        setAttribute(name, val) {
            attributes.set(name, String(val));
        },
        getAttribute(name) {
            return attributes.get(name) ?? null;
        },
        addEventListener(type, fn) {
            (listeners[type] ??= []).push(fn);
        },
        dispatchEvent(event) {
            for (const fn of listeners[event.type] ?? []) {
                fn(event);
            }
            return true;
        },
        closest(selector) {
            if (selector === '[data-filter-bar-tray]') return node._closestTray ? {} : null;
            if (selector === '[data-calendar-enhanced="true"]') return node._closestEnhanced ? {} : null;
            return null;
        },
        _listeners: listeners,
    };
    return node;
}

function setupHarness({ initialSearch = '', initialStatus = '', initialFrom = '', initialTo = '' } = {}) {
    const searchEl = makeFakeNode('input', { type: 'search', value: initialSearch });
    const statusEl = makeFakeNode('select', {
        value: initialStatus,
        selectedIndex: initialStatus === 'draft' ? 1 : 0,
        options: [
            { value: '', textContent: 'All Statuses' },
            { value: 'draft', textContent: 'Draft' },
        ],
    });
    const supplierEl = makeFakeNode('select', {
        value: '',
        selectedIndex: 0,
        inTray: true,
        options: [
            { value: '', textContent: 'All Suppliers' },
            { value: 'sup1', textContent: 'PT Sumber Logam' },
        ],
    });
    const rangeStartEl = makeFakeNode('input', { value: initialFrom, enhanced: true, inTray: true });
    const rangeEndEl = makeFakeNode('input', { value: initialTo, enhanced: true, inTray: true });
    const rangePickerEl = makeFakeNode('div', { inTray: true });
    const resetBtn = makeFakeNode('button');
    const chipsEl = makeFakeNode('div', {
        classes: ['ui-filter-chips', 'tw:hidden'],
        dataset: {
            activeLabel: 'Active filters:',
            clearLabel: 'Clear all',
            removeTemplate: 'Remove filter :label',
        },
    });
    const advancedCountEl = makeFakeNode('span', { classes: ['ui-filter-bar__badge', 'tw:hidden'] });

    const bySelector = {
        '#searchInput': searchEl,
        '#statusSelect': statusEl,
        '#supplierSelect': supplierEl,
        '#dateRange': rangePickerEl,
        '#dateFrom': rangeStartEl,
        '#dateTo': rangeEndEl,
        '[data-filter-bar-reset]': resetBtn,
        '[data-filter-bar-chips]': chipsEl,
        '[data-filter-bar-advanced-count]': advancedCountEl,
    };

    const container = makeFakeNode('div');
    container.querySelector = (sel) => bySelector[sel] ?? null;

    const emittedEvents = [];
    const calendarEvents = [];
    rangePickerEl.addEventListener('adasi:calendar-reset', () => calendarEvents.push('adasi:calendar-reset'));

    globalThis.document = {
        querySelector: (sel) => (sel === '#testFilterBar' ? container : bySelector[sel] ?? null),
        createElement: (tag) => makeFakeNode(tag),
    };
    globalThis.CustomEvent = class {
        constructor(type, init = {}) {
            this.type = type;
            this.detail = init.detail;
        }
    };

    const changes = [];
    const resets = [];

    const api = bindLive(container, {
        debounceMs: 20,
        fields: [
            { key: 'search', label: 'Search', selector: '#searchInput', type: 'search' },
            { key: 'status', label: 'Status', selector: '#statusSelect', type: 'select' },
            { key: 'supplier', label: 'Supplier', selector: '#supplierSelect', type: 'select' },
            {
                key: 'date_range',
                label: 'Date',
                type: 'date-range',
                picker: '#dateRange',
                start: '#dateFrom',
                end: '#dateTo',
            },
        ],
        onFilterChange: (info) => changes.push(info),
        onReset: (info) => resets.push(info),
    });

    return {
        api,
        container,
        searchEl,
        statusEl,
        supplierEl,
        rangePickerEl,
        rangeStartEl,
        rangeEndEl,
        resetBtn,
        chipsEl,
        advancedCountEl,
        changes,
        resets,
        calendarEvents,
        emittedEvents,
    };
}

test('initial bind syncs pre-populated filters, chips, and advanced badge without firing onFilterChange', () => {
    const h = setupHarness({ initialSearch: 'PO/001', initialFrom: '2026-11-01', initialTo: '2026-11-20' });

    assert.equal(h.changes.length, 0);
    assert.equal(h.advancedCountEl.textContent, '1');
    assert.equal(h.advancedCountEl.classList.contains('tw:hidden'), false);
    assert.equal(h.resetBtn.classList.contains('ui-filter-bar__reset--active'), true);
    assert.equal(h.chipsEl.classList.contains('tw:hidden'), false);

    const chips = h.chipsEl.children.filter((node) => node.className === 'ui-filter-chip');
    assert.equal(chips.length, 2);
    assert.equal(chips[0].children[1].textContent, 'PO/001');
    assert.equal(chips[1].children[1].textContent, '2026-11-01 - 2026-11-20');
});

test('debounced search input and immediate Enter key trigger onFilterChange and render XSS-safe chip text', async () => {
    const h = setupHarness();

    h.searchEl.value = '<img src=x onerror=alert(1)>';
    h.searchEl.dispatchEvent({ type: 'input' });
    assert.equal(h.changes.length, 0, 'search input is debounced');

    await new Promise((r) => setTimeout(r, 35));
    assert.equal(h.changes.length, 1);
    assert.equal(h.changes[0].reason, 'input');

    const chip = h.chipsEl.children.find((node) => node.className === 'ui-filter-chip');
    assert.equal(chip.children[1].textContent, '<img src=x onerror=alert(1)>');

    h.searchEl.value = 'REQ/2026/01';
    h.searchEl.dispatchEvent({ type: 'input' });
    let prevented = false;
    h.searchEl.dispatchEvent({ type: 'keydown', key: 'Enter', preventDefault: () => { prevented = true; } });
    assert.equal(prevented, true);
    assert.equal(h.changes.length, 2);
    assert.equal(h.changes[1].reason, 'enter');

    await new Promise((r) => setTimeout(r, 35));
    assert.equal(h.changes.length, 2, 'Enter cancels pending debounce timer');
});

test('removing date-range chip and clicking reset dispatch adasi:calendar-reset and update badge count', () => {
    const h = setupHarness();

    h.statusEl.value = 'draft';
    h.statusEl.selectedIndex = 1;
    h.statusEl.dispatchEvent({ type: 'change' });
    assert.equal(h.changes.length, 1);
    assert.equal(h.advancedCountEl.textContent, '0');

    h.rangeStartEl.value = '2026-11-01';
    h.rangeEndEl.value = '2026-11-15';
    h.rangePickerEl.dispatchEvent({ type: 'adasi:date-range-commit' });
    assert.equal(h.changes.length, 2);
    assert.equal(h.advancedCountEl.textContent, '1');
    assert.equal(h.advancedCountEl.classList.contains('tw:hidden'), false);

    // Click remove button on date_range chip
    const dateChip = h.chipsEl.children.find((node) => node.dataset.filterChipKey === 'date_range');
    assert.ok(dateChip);
    const removeBtn = dateChip.children[2];
    assert.equal(removeBtn.getAttribute('aria-label'), 'Remove filter Date: 2026-11-01 - 2026-11-15');
    removeBtn.dispatchEvent({ type: 'click' });

    assert.equal(h.rangeStartEl.value, '');
    assert.equal(h.rangeEndEl.value, '');
    assert.deepEqual(h.calendarEvents, ['adasi:calendar-reset']);
    assert.equal(h.advancedCountEl.textContent, '0');
    assert.equal(h.advancedCountEl.classList.contains('tw:hidden'), true);
    assert.equal(h.changes.length, 3);
    assert.equal(h.changes[2].reason, 'remove');

    // Add supplier and date range, then reset all
    h.supplierEl.value = 'sup1';
    h.supplierEl.selectedIndex = 1;
    h.supplierEl.dispatchEvent({ type: 'change' });
    h.rangeStartEl.value = '2026-10-01';
    h.rangePickerEl.dispatchEvent({ type: 'adasi:date-range-commit' });
    assert.equal(h.advancedCountEl.textContent, '2');

    h.resetBtn.dispatchEvent({ type: 'click', preventDefault() {} });
    assert.equal(h.resets.length, 1);
    assert.equal(h.statusEl.value, '');
    assert.equal(h.supplierEl.value, '');
    assert.equal(h.rangeStartEl.value, '');
    assert.equal(h.advancedCountEl.textContent, '0');
    assert.equal(h.chipsEl.classList.contains('tw:hidden'), true);
    assert.equal(h.resetBtn.classList.contains('ui-filter-bar__reset--active'), false);
    assert.equal(h.calendarEvents.length, 2);
});

test('filter-bar CSS keeps action buttons adjacent to search/filter controls and resets search flex-basis on mobile', async () => {
    const css = await readFile(new URL('../../resources/css/app.css', import.meta.url), 'utf8');

    const controlsRule = css.match(/\.ui-filter-bar__controls\s*\{([^}]*)\}/)?.[1] ?? '';
    const actionsRule = css.match(/\.ui-filter-bar__actions\s*\{([^}]*)\}/)?.[1] ?? '';
    const mobileBlock = css.match(/@media\s*\(max-width:\s*639\.98px\)\s*\{([\s\S]*?)@media\s*\(prefers-reduced-motion:\s*reduce\)/)?.[1] ?? '';

    assert.match(controlsRule, /flex:\s*0\s+1\s+auto/);
    assert.doesNotMatch(actionsRule, /margin-inline-start:\s*auto/);
    assert.doesNotMatch(actionsRule, /justify-content:\s*flex-end/);
    assert.match(mobileBlock, /\.ui-filter-bar__search\s*\{[^}]*flex:\s*0\s+0\s+auto/);
});
