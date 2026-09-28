import test from 'node:test';
import assert from 'node:assert/strict';
import { bindDashboardReorder } from '../../resources/js/preferences.js';

function controls() {
    const status = { textContent: '' };
    const list = {
        rows: [],
        querySelectorAll: () => list.rows,
        insertBefore(row, before) {
            list.rows.splice(list.rows.indexOf(row), 1);
            list.rows.splice(list.rows.indexOf(before), 0, row);
        },
        addEventListener(_name, listener) { this.listener = listener; },
        contains(row) { return this.rows.includes(row); },
        closest: () => ({ querySelector: () => status }),
    };
    for (const key of ['admin.rates', 'admin.notifications', 'admin.summary']) {
        const row = { dataset: { widgetKey: key, widgetLabel: key }, closest: () => list };
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
});
