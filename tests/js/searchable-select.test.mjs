import test from 'node:test';
import assert from 'node:assert/strict';
import { searchableSelect } from '../../resources/js/searchable-select.js';

function createFakeNativeSelect({ name = 'search', filterName = 'search', form = null } = {}) {
    const addedOptions = [];
    const dispatched = [];
    return {
        name,
        dataset: { filterName },
        value: '',
        disabled: false,
        form,
        addedOptions,
        dispatched,
        querySelector(selector) {
            return addedOptions.find((opt) => selector.includes(`"${opt.value}"`)) || null;
        },
        add(opt) {
            addedOptions.push(opt);
        },
        dispatchEvent(evt) {
            dispatched.push(evt.type);
        },
    };
}

test('preloadOnOpen + exportFilterContext fetches low-priority contextual suggestions on open and preserves selectedValue', async () => {
    const originalFetch = globalThis.fetch;
    const originalOption = globalThis.Option;
    const calls = [];

    globalThis.Option = function FakeOption(text, value) {
        this.text = text;
        this.value = value;
    };
    globalThis.fetch = async (url, init) => {
        calls.push({ url, init });
        return {
            ok: true,
            json: async () => ({
                data: [
                    { value: 'PO/11/2026/010', label: 'PO/11/2026/010', sublabel: 'PT Alpha', badge: 'ACTIVE', badgeTone: 'success' },
                    { value: 'JIS G3141 SPCC-SD', label: 'JIS G3141 SPCC-SD', sublabel: 'Material', badge: 'MATERIAL', badgeTone: 'neutral' },
                ],
            }),
        };
    };

    try {
        const filterInputs = [
            { dataset: { filterName: 'search' }, value: 'OLD', disabled: false },
            { dataset: { filterName: 'supplier_id' }, value: 'supHash123', disabled: false },
            { dataset: { filterName: 'status' }, value: 'active', disabled: false },
            { dataset: { filterName: 'start_date' }, value: '2026-01-01', disabled: true },
        ];
        const namedInputs = new Map([
            ['[name="date_from"]', { value: '2026-10', disabled: false }],
            ['[name="date_to"]', { value: '2026-11', disabled: false }],
            ['[data-export-relative]', { checked: true }],
            ['[data-export-days]', { value: '30' }],
        ]);
        const form = {
            addEventListener() {},
            querySelectorAll(sel) {
                return sel === '[data-filter-name]' ? filterInputs : [];
            },
            querySelector(sel) {
                return namedInputs.get(sel) || null;
            },
        };

        const nativeSelect = createFakeNativeSelect({ name: 'search', filterName: 'search', form });
        const instance = searchableSelect({
            selectedValue: 'PO/CUSTOM/KEEP',
            options: [{ value: 'STALE/001', label: 'STALE/001', sublabel: null, badge: null, badgeTone: 'neutral', searchKeywords: 'stale/001' }],
            allowCustom: true,
            remoteSearch: true,
            preloadOnOpen: true,
            exportFilterContext: true,
            suggestionsUrl: 'https://portal.test/exports/search-suggestions?export_key=purchasing.po',
        });

        instance.$refs = {
            nativeSelect,
            triggerButton: { focus() {}, getBoundingClientRect: () => ({ top: 100, bottom: 132 }), closest: () => null },
            searchInput: { focus() {} },
            popover: { scrollIntoView() {} },
        };
        instance.$el = { closest: () => form };
        instance.$watch = () => {};
        instance.$nextTick = (fn) => fn();

        instance.init();
        instance.toggle();
        await new Promise(setImmediate);

        assert.equal(calls.length, 1);
        assert.equal(calls[0].init.priority, 'low');
        assert.equal(calls[0].init.credentials, 'same-origin');

        const parsed = new URL(calls[0].url);
        assert.equal(parsed.searchParams.get('export_key'), 'purchasing.po');
        assert.equal(parsed.searchParams.get('supplier_id'), 'supHash123');
        assert.equal(parsed.searchParams.get('status'), 'active');
        assert.equal(parsed.searchParams.get('start_date'), null, 'disabled inputs must be excluded');
        assert.equal(parsed.searchParams.get('search'), null, 'self search input must be excluded');
        assert.equal(parsed.searchParams.get('date_from'), '2026-10');
        assert.equal(parsed.searchParams.get('date_to'), '2026-11');
        assert.equal(parsed.searchParams.get('date_range[mode]'), 'relative');
        assert.equal(parsed.searchParams.get('date_range[days]'), '30');

        // Stale option was replaced, while active selectedValue was preserved at the top
        const values = instance.options.map((o) => o.value);
        assert.equal(values.includes('STALE/001'), false);
        assert.equal(values.includes('PO/11/2026/010'), true);
        assert.equal(values.includes('JIS G3141 SPCC-SD'), true);
        assert.equal(instance.selectedOption?.value, 'PO/CUSTOM/KEEP');
    } finally {
        globalThis.fetch = originalFetch;
        globalThis.Option = originalOption;
    }
});

test('setExternalValue syncs custom filter values silently on preset load and dispatches change when non-silent', () => {
    const originalOption = globalThis.Option;
    globalThis.Option = function FakeOption(text, value) {
        this.text = text;
        this.value = value;
    };

    try {
        const nativeSelect = createFakeNativeSelect({ name: 'search', filterName: 'search' });
        const instance = searchableSelect({
            selectedValue: '',
            options: [],
            allowCustom: true,
            remoteSearch: true,
        });
        instance.$refs = { nativeSelect };
        instance.$watch = () => {};

        instance.init();
        instance.setExternalValue('REQ/11/2026/999', true);

        assert.equal(instance.selectedValue, 'REQ/11/2026/999');
        assert.equal(instance.selectedOption?.label, 'REQ/11/2026/999');
        assert.equal(nativeSelect.value, 'REQ/11/2026/999');
        assert.deepEqual(nativeSelect.dispatched, []);

        instance.setExternalValue('', false);
        assert.equal(instance.selectedValue, '');
        assert.equal(instance.selectedOption, null);
        assert.deepEqual(nativeSelect.dispatched, ['change']);
    } finally {
        globalThis.Option = originalOption;
    }
});

test('selectHighlighted ignores Enter during IME composition (isComposing or keyCode 229)', () => {
    const originalOption = globalThis.Option;
    globalThis.Option = function FakeOption(text, value) {
        this.text = text;
        this.value = value;
    };

    try {
        const nativeSelect = createFakeNativeSelect({ name: 'search', filterName: 'search' });
        const instance = searchableSelect({
            selectedValue: '',
            options: [{ value: 'PO/001', label: 'PO/001', sublabel: null, badge: null, badgeTone: 'neutral', searchKeywords: 'po/001' }],
            allowCustom: true,
        });
        instance.$refs = { nativeSelect, triggerButton: { focus() {} } };
        instance.$watch = () => {};
        instance.$nextTick = (fn) => fn();

        instance.open = true;
        instance.search = 'PO/001';
        instance.highlightedIndex = 0;

        instance.selectHighlighted({ isComposing: true, keyCode: 13 });
        assert.equal(instance.open, true);
        assert.equal(instance.selectedValue, '');

        instance.selectHighlighted({ isComposing: false, keyCode: 229 });
        assert.equal(instance.open, true);
        assert.equal(instance.selectedValue, '');

        instance.selectHighlighted({ isComposing: false, keyCode: 13 });
        assert.equal(instance.open, false);
        assert.equal(instance.selectedValue, 'PO/001');
    } finally {
        globalThis.Option = originalOption;
    }
});

test('setExternalOptions replaces option list, syncs native select options, and respects silent flag', () => {
    const originalOption = globalThis.Option;
    globalThis.Option = function FakeOption(text, value) {
        this.text = text;
        this.value = value;
        this.dataset = {};
    };

    try {
        const nativeSelect = createFakeNativeSelect({ name: 'range', filterName: 'range' });
        nativeSelect.innerHTML = '';
        nativeSelect.options = [];
        nativeSelect.add = (opt) => nativeSelect.options.push(opt);

        const instance = searchableSelect({
            selectedValue: '3m',
            options: [{ value: '3m', label: '3 Months', searchKeywords: '3 months' }],
            clearable: false,
        });
        instance.$refs = { nativeSelect };
        instance.$watch = () => {};
        instance.init();

        instance.setExternalOptions(
            [
                { value: '1y', label: '1 Year', dataset: { period: 'yearly' } },
                { value: 'all', label: 'All Years' },
            ],
            { value: '1y', disabled: false, silent: true },
        );

        assert.equal(instance.options.length, 2);
        assert.equal(instance.selectedValue, '1y');
        assert.equal(nativeSelect.value, '1y');
        assert.equal(nativeSelect.options.length, 2);
        assert.equal(nativeSelect.options[0].dataset.period, 'yearly');
        assert.deepEqual(nativeSelect.dispatched, []);

        instance.setExternalOptions([{ value: 'all', label: 'All Years' }], { value: 'all', silent: false });
        assert.deepEqual(nativeSelect.dispatched, ['change']);
    } finally {
        globalThis.Option = originalOption;
    }
});
