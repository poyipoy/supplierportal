import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import { readFileSync } from 'node:fs';

const source = readFileSync(new URL('../../resources/js/local-po-gr-table.js', import.meta.url), 'utf8').replace('export function', 'function');

function boot({ dataTable = false, focusables = [] } = {}) {
    const listeners = new Map();
    const modalListeners = new Map();
    const formListeners = new Map();
    const reason = { value: 'Previous reason', focusCount: 0, focus() { this.focusCount++; } };
    const checkbox = { checked: true };
    const form = {
        action: '',
        reset() { reason.value = ''; checkbox.checked = false; },
        querySelector(selector) { return selector === '[name="reason"]' ? reason : null; },
        querySelectorAll() { return []; },
        addEventListener: (name, handler) => formListeners.set(name, handler),
        reportValidity: () => reason.value.length > 0,
    };
    const number = { textContent: '' };
    const target = { value: '' };
    const modal = {
        dataset: {},
        querySelector(selector) {
            if (selector === 'form') return form;
            if (selector === '[data-gr-modal-number]') return number;
            if (selector === '[name="_recovery_target"]') return target;
            return null;
        },
        querySelectorAll(selector) { return selector.includes('button') ? focusables : []; },
        addEventListener: (name, handler) => modalListeners.set(name, handler),
    };
    const filter = { focusCount: 0, focus() { this.focusCount++; }, addEventListener() {} };
    const tableElement = { dataset: { readOnly: '0' }, addEventListener() {} };
    const error = { hidden: true };
    const root = {
        querySelector(selector) {
            return { '[data-gr-table]': tableElement, '[data-gr-table-error]': error,
                '[data-gr-table-retry]': { addEventListener() {} }, '#poGrStatusFilter': filter,
                '#restorePoGrModal': modal }[selector] || null;
        },
        querySelectorAll: selector => selector === '[data-recovery-modal]' ? [modal] : [],
        addEventListener: (name, handler) => listeners.set(name, handler),
    };
    let showCount = 0;
    const table = { ajax: { reload() {} } };
    const jQuery = Object.assign(() => ({ DataTable: () => table, on() {} }), { fn: { dataTable: { ext: {} } } });
    const window = {
        location: { origin: 'https://portal.example', reload() {} },
        bootstrap: { Modal: { getOrCreateInstance: () => ({ show() { showCount++; } }) } },
        ...(dataTable ? { jQuery } : {}),
    };
    vm.runInNewContext(`${source}\nbootLocalPoGrTable(document);`, { document: root, window, URL });
    function click(url = '/finance/goods-receipts/hashed/restore') {
        const button = {
            dataset: { grRestoreUrl: url, grNumber: 'GR-001', grHash: 'hashed' },
            isConnected: true, focusCount: 0, focus() { this.focusCount++; },
            hasAttribute: name => name === 'data-gr-restore',
        };
        listeners.get('click')?.({ target: { closest: selector => selector.includes('data-gr-restore') ? button : null }, preventDefault() {} });
        return button;
    }
    return { click, form, reason, checkbox, modalListeners, formListeners, number, target, filter, root,
        shows: () => showCount };
}

test('GR recovery works from the server-rendered fallback without DataTables', () => {
    const app = boot();
    app.click();
    assert.equal(app.shows(), 1);
    assert.equal(app.form.action, 'https://portal.example/finance/goods-receipts/hashed/restore');
    assert.equal(app.number.textContent, 'GR-001');
    assert.equal(app.target.value, 'hashed');
    assert.equal(app.reason.value, '');
    assert.equal(app.checkbox.checked, false);
});

test('GR recovery rejects foreign and script actions', () => {
    const app = boot({ dataTable: true });
    app.click('https://foreign.example/restore');
    app.click('javascript:alert(1)');
    assert.equal(app.shows(), 0);
    assert.equal(app.form.action, '');
});

test('GR recovery focuses the reason and returns focus to its trigger', () => {
    const app = boot();
    const button = app.click();
    app.modalListeners.get('shown.bs.modal')?.();
    assert.equal(app.reason.focusCount, 1);
    app.modalListeners.get('hidden.bs.modal')?.();
    assert.equal(button.focusCount, 1);
});

test('GR recovery trims the reason and prevents a whitespace-only submission', () => {
    const app = boot();
    app.reason.value = '   Correct attachment   ';
    app.formListeners.get('submit')?.({ preventDefault() { assert.fail('valid reason must submit'); } });
    assert.equal(app.reason.value, 'Correct attachment');
    app.reason.value = '   ';
    let prevented = false;
    app.formListeners.get('submit')?.({ preventDefault() { prevented = true; } });
    assert.equal(prevented, true);
});

test('recovery modal wraps Tab and Shift Tab at both boundaries', () => {
    const field = () => ({disabled:false,tabIndex:0,getClientRects:()=>[{}],focusCount:0,focus(){this.focusCount++;}});
    const first=field(), last=field();
    const app=boot({focusables:[first,last]});
    let prevented=0;
    app.root.activeElement=last;
    app.modalListeners.get('keydown')?.({key:'Tab',shiftKey:false,preventDefault(){prevented++;}});
    assert.equal(first.focusCount,1);
    app.root.activeElement=first;
    app.modalListeners.get('keydown')?.({key:'Tab',shiftKey:true,preventDefault(){prevented++;}});
    assert.equal(last.focusCount,1);
    assert.equal(prevented,2);
});

test('GR recovery returns focus to the status filter after a table redraw removes its trigger', () => {
    const app = boot();
    const button = app.click();
    button.isConnected = false;
    app.modalListeners.get('hidden.bs.modal')?.();
    assert.equal(app.filter.focusCount, 1);
});
