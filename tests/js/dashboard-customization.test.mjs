import { installI18n } from './i18n-fixture.mjs';
globalThis.window = installI18n(globalThis.window || {});
import test from 'node:test';
import assert from 'node:assert/strict';
import { bindDashboardDragAndDrop, bindDashboardReorder } from '../../resources/js/preferences.js';

function controls(drag = false) {
    const status = { textContent: '' };
    const list = {
        rows: [],
        events: [],
        handlers: {},
        querySelectorAll: () => list.rows,
        insertBefore(row, before) {
            list.rows.splice(list.rows.indexOf(row), 1);
            list.rows.splice(list.rows.indexOf(before), 0, row);
        },
        addEventListener(name, listener) { this.handlers[name] = listener; if (name === 'click') this.listener = listener; },
        dispatchEvent(event) { this.events.push(event.type); return true; },
        contains(row) { return this.rows.includes(row); },
        closest: () => ({ querySelector: () => status }),
    };
    for (const key of ['admin.rates', 'admin.notifications', 'admin.summary']) {
        const classes = new Set();
        const row = {
            dataset: { widgetKey: key, widgetLabel: key },
            closest: selector => selector === '[data-dashboard-choice]' ? row : list,
            classList: { add: (...names) => names.forEach(name => classes.add(name)), remove: (...names) => names.forEach(name => classes.delete(name)) },
            before(item) { list.insertBefore(item, row); },
            after(item) {
                list.rows.splice(list.rows.indexOf(item), 1);
                list.rows.splice(list.rows.indexOf(row) + 1, 0, item);
            },
            getBoundingClientRect: () => ({ top: 0, height: 40 }),
        };
        row.orderInput = { name: 'dashboard[order][]', value: key };
        list.rows.push(row);
    }
    function button(row, direction) {
        return {
            dataset: { dashboardMove: direction },
            closest(selector) { return selector === '[data-dashboard-move]' ? this : row; },
            focus(options) { this.focused = true; this.focusOptions = options; },
        };
    }
    const doc = { querySelectorAll: () => [list] };
    bindDashboardReorder(doc);
    if (drag) bindDashboardDragAndDrop(doc);
    return { list, status, button };
}

test('native button activation reorders submitted stable keys and retains focus', () => {
    const { list, status, button } = controls();
    const control = button(list.rows[2], 'up');
    list.listener({ target: control });
    assert.deepEqual(list.rows.map(row => row.orderInput.value), ['admin.rates', 'admin.summary', 'admin.notifications']);
    assert.equal(control.focused, true);
    assert.equal(control.focusOptions, undefined, 'reorder focus may scroll the control into view');
    assert.match(status.textContent, /position 2 of 3/);
    assert.deepEqual(list.events, ['adasi:dashboard-order-change']);
});

test('down movement and boundary movement keep every widget and usable focus', () => {
    const { list, status, button } = controls();
    const control = button(list.rows[0], 'down');
    list.listener({ target: control });
    assert.deepEqual(list.rows.map(row => row.orderInput.value), ['admin.notifications', 'admin.rates', 'admin.summary']);
    const first = button(list.rows[0], 'up');
    list.listener({ target: first });
    assert.deepEqual(list.rows.map(row => row.orderInput.value), ['admin.notifications', 'admin.rates', 'admin.summary']);
    assert.equal(first.focused, true);
    assert.match(status.textContent, /position 1 of 3/);
    assert.deepEqual(list.events, ['adasi:dashboard-order-change'], 'a boundary move must not announce a new dirty change');
});

test('drag reorder announces the same dirty-state event as keyboard reorder', () => {
    const { list } = controls(true);
    const dragged = list.rows[2];
    const target = list.rows[0];
    list.handlers.dragstart({ target: dragged, dataTransfer: { setData() {} } });
    list.handlers.drop({ target, clientY: 0, preventDefault() {} });
    assert.deepEqual(list.rows.map(row => row.orderInput.value), ['admin.summary', 'admin.rates', 'admin.notifications']);
    assert.deepEqual(list.events, ['adasi:dashboard-order-change']);
    list.handlers.drop({ target, clientY: 0, preventDefault() {} });
    assert.equal(list.events.length, 1, 'a completed drag must not remain active');
});

test('tiles sharing a row are compared on the horizontal axis, so the right half drops after', () => {
    const { list } = controls(true);
    list.getBoundingClientRect = () => ({ left: 0, top: 0, width: 1000, height: 400 });
    const dragged = list.rows[2];
    const target = list.rows[0];
    target.getBoundingClientRect = () => ({ left: 0, top: 0, width: 300, height: 40 });

    list.handlers.dragstart({ target: dragged, dataTransfer: { setData() {} } });
    // The pointer is in the top half but the right half: only the horizontal axis puts it after the target.
    list.handlers.drop({ target, clientX: 250, clientY: 5, preventDefault() {} });

    assert.deepEqual(list.rows.map(row => row.orderInput.value), ['admin.rates', 'admin.summary', 'admin.notifications']);
    assert.deepEqual(list.events, ['adasi:dashboard-order-change']);
});

test('full-width tiles still use the vertical axis', () => {
    const { list } = controls(true);
    list.getBoundingClientRect = () => ({ left: 0, top: 0, width: 1000, height: 400 });
    const dragged = list.rows[2];
    const target = list.rows[0];
    target.getBoundingClientRect = () => ({ left: 0, top: 0, width: 1000, height: 40 });

    list.handlers.dragstart({ target: dragged, dataTransfer: { setData() {} } });
    list.handlers.drop({ target, clientX: 900, clientY: 5, preventDefault() {} });

    assert.deepEqual(list.rows.map(row => row.orderInput.value), ['admin.summary', 'admin.rates', 'admin.notifications']);
});
