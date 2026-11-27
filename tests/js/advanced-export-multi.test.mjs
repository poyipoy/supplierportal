import test from 'node:test';
import assert from 'node:assert/strict';
import { createAdvancedExport } from '../../resources/js/advanced-export.js';

function makeElement(overrides = {}) {
    const listeners = new Map();
    return {
        dataset: {},
        value: '',
        disabled: false,
        hidden: false,
        checked: false,
        textContent: '',
        className: '',
        classList: { add() {}, remove() {} },
        attributes: {},
        setAttribute(k, v) { this.attributes[k] = String(v); },
        removeAttribute(k) { delete this.attributes[k]; },
        hasAttribute(k) { return Object.hasOwn(this.attributes, k); },
        querySelector() { return null; },
        querySelectorAll() { return []; },
        addEventListener(type, cb) {
            const list = listeners.get(type) || [];
            list.push(cb);
            listeners.set(type, list);
        },
        removeEventListener(type, cb) {
            const list = listeners.get(type) || [];
            listeners.set(type, list.filter((fn) => fn !== cb));
        },
        dispatchEvent(evt) {
            for (const cb of listeners.get(evt.type) || []) cb(evt);
            return true;
        },
        focus() {},
        replaceChildren() {},
        prepend() {},
        append() {},
        ...overrides,
    };
}

test('createAdvancedExport reads <select multiple> filters and materials[] URL params on Quick Export and syncs set-multi-value', async () => {
    const dispatchedEvents = [];
    const startedExports = [];

    const pageMultiSelect = makeElement({
        id: 'historicalMaterialSelect',
        multiple: true,
        options: [
            { value: 'MAT-A|Flat', selected: true },
            { value: 'MAT-B|Round', selected: true },
            { value: 'MAT-C|Hollow', selected: false },
        ],
    });
    Object.defineProperty(pageMultiSelect, 'selectedOptions', {
        get() { return this.options.filter((o) => o.selected); },
    });

    const modalMultiSelect = makeElement({
        id: 'advanced-export-purchasing-price-history-materials',
        multiple: true,
        dataset: { filterName: 'materials' },
        options: [
            { value: 'MAT-A|Flat', selected: false },
            { value: 'MAT-B|Round', selected: false },
            { value: 'MAT-C|Hollow', selected: false },
        ],
    });
    Object.defineProperty(modalMultiSelect, 'selectedOptions', {
        get() { return this.options.filter((o) => o.selected); },
    });

    const modalCurrencySelect = makeElement({
        id: 'advanced-export-purchasing-price-history-currency',
        dataset: { filterName: 'currency' },
        value: 'IDR',
    });

    const csrfInput = makeElement({ value: 'csrf-token' });
    const form = makeElement({
        action: 'https://portal.test/purchasing/export/price-history',
        reportValidity: () => true,
        querySelector(sel) {
            if (sel === '[name="_token"]') return csrfInput;
            return null;
        },
        querySelectorAll(sel) {
            if (sel === '[data-filter-name]') return [modalMultiSelect, modalCurrencySelect];
            return [];
        },
    });

    const quickBtn = makeElement();
    const submitBtn = makeElement();
    const saveBtn = makeElement();
    const updateBtn = makeElement();
    const deleteBtn = makeElement();
    const statusEl = makeElement();
    const errorEl = makeElement({ hidden: true });
    const presetSelect = makeElement();
    const presetName = makeElement();
    const formatSelect = makeElement({ value: 'xlsx' });
    const modal = makeElement({ open: false });

    const windowRef = {
        location: { href: 'https://portal.test/purchasing/comparison/historical?materials[]=MAT-A|Flat&materials[]=MAT-B|Round&currency=USD' },
        AbortController,
        setTimeout,
        clearTimeout,
        CustomEvent,
        dispatchEvent(evt) { dispatchedEvents.push(evt); },
        AdasiModal: { getOrCreateInstance: () => ({ show() {}, hide() {}, dispose() {} }), getInstance: () => null },
        AdasiAsyncExport: {
            async startExport(_form, payload) {
                startedExports.push(payload);
                return true;
            },
        },
        fetch: async (url) => {
            if (url.includes('/definitions/')) {
                return {
                    ok: true,
                    status: 200,
                    json: async () => ({
                        export_key: 'purchasing.price-history',
                        supports_columns: true,
                        formats: ['xlsx', 'csv'],
                        columns: [{ key: 'material_name', label: 'Material', default: true, required: true }],
                        filters: [
                            { name: 'materials', type: 'multi-select' },
                            { name: 'currency', type: 'select', default: 'IDR' },
                        ],
                    }),
                };
            }
            return {
                ok: true,
                status: 200,
                json: async () => ({ data: [], personal: [], team: [], can_manage_team: false }),
            };
        },
    };

    const documentRef = {
        defaultView: windowRef,
        documentElement: { lang: 'en' },
        createElement: (tag) => makeElement({ tagName: tag.toUpperCase() }),
        querySelector(sel) {
            if (sel === '#historicalMaterialSelect') return pageMultiSelect;
            return null;
        },
    };

    const root = makeElement({
        ownerDocument: documentRef,
        dataset: {
            exportKey: 'purchasing.price-history',
            definitionUrl: 'https://portal.test/exports/definitions/purchasing.price-history',
            presetsUrl: 'https://portal.test/export-presets',
            presetStoreUrl: 'https://portal.test/export-presets',
            filterSelectors: JSON.stringify({ materials: '#historicalMaterialSelect' }),
            initialFilters: JSON.stringify({ currency: 'USD' }),
            exportCopy: JSON.stringify({ loading: 'Loading', failed: 'Failed', default_columns: 'Default' }),
        },
        querySelector(sel) {
            const map = {
                'dialog.ui-native-dialog, .ui-export-designer-modal': modal,
                '[data-advanced-export-form]': form,
                '[data-export-submit]': submitBtn,
                '[data-export-quick]': quickBtn,
                '[data-export-status]': statusEl,
                '[data-export-error]': errorEl,
                '[data-export-preset]': presetSelect,
                '[data-export-save]': saveBtn,
                '[data-export-update]': updateBtn,
                '[data-export-delete]': deleteBtn,
                '[data-export-format]': formatSelect,
                '[data-export-preset-name]': presetName,
            };
            return map[sel] || null;
        },
    });

    const controller = createAdvancedExport(root, {
        designer: {
            configure() {},
            setColumns() {},
            reset() {},
            serialize: () => ['material_name'],
            setEnabled() {},
            suspend() {},
            destroy() {},
        },
    });

    quickBtn.dispatchEvent(new Event('click'));
    await new Promise((resolve) => setTimeout(resolve, 40));

    assert.equal(startedExports.length, 1);
    assert.deepEqual(startedExports[0].body.materials, ['MAT-A|Flat', 'MAT-B|Round']);
    assert.equal(startedExports[0].body.currency, 'USD');

    const multiSyncEvent = dispatchedEvents.find((evt) => evt.type === 'set-multi-value' && evt.detail?.id === modalMultiSelect.id);
    assert.ok(multiSyncEvent, 'set-multi-value custom event dispatched to sync multi-select UI');
    assert.deepEqual(multiSyncEvent.detail.values, ['MAT-A|Flat', 'MAT-B|Round']);

    controller.destroy();
});
