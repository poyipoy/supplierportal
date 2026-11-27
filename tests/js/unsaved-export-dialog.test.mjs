import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

let instance = 0;

function createMockControl(form, { tag = 'INPUT', type = 'text', name, value = '', options = [] } = {}) {
    return {
        tagName: tag,
        type,
        name,
        id: name,
        value,
        checked: false,
        disabled: false,
        readOnly: false,
        hidden: false,
        form,
        options,
        matches(selector) {
            return selector.split(',').some((part) => part.trim() === '[hidden]' && this.hidden);
        },
        closest() {
            return null;
        },
        getAttribute(attr) {
            return attr === 'type' ? this.type : null;
        },
    };
}

function createMockForm({
    method = 'post',
    action = '/purchasing/export/price-history',
    attrs = {},
    closestDialog = null,
    controls = [],
} = {}) {
    const proto = globalThis.HTMLFormElement?.prototype || Object.prototype;
    const form = Object.create(proto);
    form.method = method;
    form.action = action;
    form.id = attrs.id || '';
    form.isConnected = true;
    form.dataset = {};
    form._attrs = { ...attrs };
    form._controls = controls;
    controls.forEach((ctrl) => {
        ctrl.form = form;
    });
    form.getAttribute = (name) => (name === 'action' ? action : (form._attrs[name] ?? null));
    form.hasAttribute = (name) => Object.prototype.hasOwnProperty.call(form._attrs, name);
    form.matches = (selector) => selector.split(',').some((raw) => {
        const s = raw.trim();
        if (s === '[data-track-unsaved="false"]') return form._attrs['data-track-unsaved'] === 'false';
        if (s === '[data-track-unsaved="true"]') return form._attrs['data-track-unsaved'] === 'true';
        const attrMatch = /^\[([a-z-]+)\]$/.exec(s);
        return attrMatch ? form.hasAttribute(attrMatch[1]) : false;
    });
    form.closest = (selector) => (selector === 'dialog' ? closestDialog : null);
    form.querySelectorAll = (selector) => (selector.includes('input') ? form._controls : []);
    form.querySelector = () => null;
    form.elements = controls;
    return form;
}

async function withUnsavedHarness(forms, callback) {
    const prev = {
        window: globalThis.window,
        document: globalThis.document,
        HTMLFormElement: globalThis.HTMLFormElement,
    };

    class MockHTMLFormElement {}
    globalThis.HTMLFormElement = MockHTMLFormElement;

    const formList = [];
    const doc = Object.assign(new EventTarget(), {
        title: 'Test',
        readyState: 'complete',
        querySelectorAll(selector) {
            if (selector === 'form') return formList;
            return [];
        },
        querySelector() {
            return null;
        },
    });
    const win = Object.assign(new EventTarget(), {
        location: { pathname: '/purchasing/comparison/historical', href: 'http://localhost/purchasing/comparison/historical' },
        history: { state: null, pushState(state) { this.state = state; }, replaceState(state) { this.state = state; }, back() {} },
        AdasiI18n: { t: (key) => key },
        AdasiAlert: { confirmDanger: async () => ({ isConfirmed: true }) },
    });

    globalThis.window = win;
    globalThis.document = doc;

    try {
        formList.push(...forms(MockHTMLFormElement));
        await import(`../../resources/js/unsaved-changes.js?case=${++instance}`);
        await new Promise((resolve) => setTimeout(resolve, 260));
        await callback({ win, doc, guard: win.AdasiUnsaved, formList });
    } finally {
        globalThis.window = prev.window;
        globalThis.document = prev.document;
        globalThis.HTMLFormElement = prev.HTMLFormElement;
    }
}

test('async export forms and closed native dialog forms never trigger unsaved-change warnings', async () => {
    const closedDialog = { open: false, hasAttribute: () => false };
    let advForm;
    let advSelect;
    let formatForm;
    let formatSelect;
    let closedEntityForm;
    let closedEntityInput;

    await withUnsavedHarness(
        () => {
            advSelect = createMockControl(null, {
                tag: 'SELECT',
                type: 'select-multiple',
                name: 'filters[materials][]',
                options: [
                    { value: 'SKD11|Flat', selected: false },
                    { value: 'S45C|Flat', selected: false },
                ],
            });
            advForm = createMockForm({
                attrs: { 'data-advanced-export-form': '' },
                closestDialog: closedDialog,
                controls: [advSelect],
            });

            formatSelect = createMockControl(null, {
                tag: 'SELECT',
                type: 'select-one',
                name: 'options[format]',
                value: 'xlsx',
                options: [
                    { value: 'xlsx', selected: true },
                    { value: 'csv', selected: false },
                ],
            });
            formatForm = createMockForm({
                action: '/supplier/price-history/export',
                attrs: { 'data-format-export-form': '' },
                controls: [formatSelect],
            });

            closedEntityInput = createMockControl(null, {
                tag: 'INPUT',
                type: 'text',
                name: 'location_name',
                value: '',
            });
            closedEntityForm = createMockForm({
                action: '/supplier/goods-locations',
                closestDialog: closedDialog,
                controls: [closedEntityInput],
            });

            return [advForm, formatForm, closedEntityForm];
        },
        ({ doc, guard }) => {
            assert.equal(guard.isDirty(), false);

            // Simulate multi-select hydration / filter sync mutating [data-advanced-export-form]
            advSelect.options[0].selected = true;
            advSelect.options[1].selected = true;
            const advChange = new Event('change', { bubbles: true });
            Object.defineProperty(advChange, 'target', { value: advSelect });
            doc.dispatchEvent(advChange);
            assert.equal(guard.isDirty(), false, 'advanced export filter sync must not mark page dirty');

            // Even if the native <dialog> opens, [data-advanced-export-form] stays non-dirty
            closedDialog.open = true;
            closedDialog.hasAttribute = (attr) => attr === 'open';
            guard.resetSnapshot(advForm);
            assert.equal(guard.isDirty(), false, 'open advanced export modal must not mark page dirty');
            closedDialog.open = false;
            closedDialog.hasAttribute = () => false;

            // Inline supplier price-history export format switch stays clean
            formatSelect.value = 'csv';
            formatSelect.options[0].selected = false;
            formatSelect.options[1].selected = true;
            const fmtChange = new Event('change', { bubbles: true });
            Object.defineProperty(fmtChange, 'target', { value: formatSelect });
            doc.dispatchEvent(fmtChange);
            assert.equal(guard.isDirty(), false, 'inline format export form must not mark page dirty');

            // Background sync inside a closed regular <dialog> stays clean
            closedEntityInput.value = 'Background Sync';
            const closedChange = new Event('change', { bubbles: true });
            Object.defineProperty(closedChange, 'target', { value: closedEntityInput });
            doc.dispatchEvent(closedChange);
            assert.equal(guard.isDirty(), false, 'closed native dialog form must not mark page dirty');
        },
    );
});

test('regular POST forms inside an open native <dialog> still protect genuine edits', async () => {
    const dialog = { open: true, hasAttribute: (attr) => attr === 'open' };
    let modalForm;
    let modalInput;

    await withUnsavedHarness(
        () => {
            modalInput = createMockControl(null, {
                tag: 'INPUT',
                type: 'text',
                name: 'address',
                value: 'Initial',
            });
            modalForm = createMockForm({
                action: '/supplier/goods-locations',
                closestDialog: dialog,
                controls: [modalInput],
            });
            return [modalForm];
        },
        ({ doc, guard }) => {
            assert.equal(guard.isDirty(), false);
            modalInput.value = 'Edited Address';
            const inputEvent = new Event('input', { bubbles: true });
            Object.defineProperty(inputEvent, 'target', { value: modalInput });
            doc.dispatchEvent(inputEvent);
            assert.equal(guard.isDirty(), true, 'open native dialog POST form with edits remains protected');
        },
    );
});

test('export form Blade views explicitly opt out of unsaved-change tracking', () => {
    const advancedModal = readFileSync(new URL('../../resources/views/components/export/advanced-modal.blade.php', import.meta.url), 'utf8');
    const supplierHistory = readFileSync(new URL('../../resources/views/supplier/price-history/historical.blade.php', import.meta.url), 'utf8');

    assert.match(advancedModal, /data-advanced-export-form[^>]*data-track-unsaved="false"/);
    assert.match(supplierHistory, /data-format-export-form[^>]*data-track-unsaved="false"/);
});
