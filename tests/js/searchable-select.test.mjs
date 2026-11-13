import test from 'node:test';
import assert from 'node:assert/strict';
import { searchableSelect } from '../../resources/js/searchable-select.js';

test('searchableSelect initializes with options and selected value', () => {
    const comp = searchableSelect({
        selectedValue: 'PR-001',
        options: [
            { value: 'PR-001', label: 'PR-001', searchKeywords: 'pr-001' },
            { value: 'PR-002', label: 'PR-002', searchKeywords: 'pr-002' },
        ],
        allowCustom: true,
    });

    assert.equal(comp.selectedValue, 'PR-001');
    assert.equal(comp.options.length, 2);
    assert.equal(comp.selectedOption.label, 'PR-001');
});

test('filteredOptions filters by query matching label or keywords', () => {
    const comp = searchableSelect({
        options: [
            { value: 'PR-100', label: 'PR-100', searchKeywords: 'pr-100 vendor a' },
            { value: 'PR-200', label: 'PR-200', searchKeywords: 'pr-200 vendor b' },
        ],
    });

    comp.search = 'vendor b';
    assert.equal(comp.filteredOptions.length, 1);
    assert.equal(comp.filteredOptions[0].value, 'PR-200');

    comp.search = 'xyz';
    assert.equal(comp.filteredOptions.length, 0);
});

test('showCustomOption is true only when allowCustom is true, search is not empty, and no exact match', () => {
    const comp = searchableSelect({
        options: [
            { value: 'PR-100', label: 'PR-100', searchKeywords: 'pr-100' },
        ],
        allowCustom: true,
    });

    comp.search = '';
    assert.equal(comp.showCustomOption, false);

    comp.search = 'PR-100';
    assert.equal(comp.hasExactMatch, true);
    assert.equal(comp.showCustomOption, false);

    comp.search = 'PR-999';
    assert.equal(comp.hasExactMatch, false);
    assert.equal(comp.showCustomOption, true);
    assert.equal(comp.totalSelectableCount, 1);
});

test('selectCustom prepends new option and selects it', () => {
    const comp = searchableSelect({
        options: [
            { value: 'PR-100', label: 'PR-100', searchKeywords: 'pr-100' },
        ],
        allowCustom: true,
    });

    comp.selectCustom('PR-NEW-99');
    assert.equal(comp.selectedValue, 'PR-NEW-99');
    assert.equal(comp.options[0].value, 'PR-NEW-99');
    assert.equal(comp.open, false);
});

test('clear resets selection', () => {
    const comp = searchableSelect({
        selectedValue: 'PR-100',
        options: [
            { value: 'PR-100', label: 'PR-100', searchKeywords: 'pr-100' },
        ],
    });

    comp.clear();
    assert.equal(comp.selectedValue, '');
    assert.equal(comp.selectedOption, null);
});

function withFetch(responder, run) {
    const original = globalThis.fetch;
    const calls = [];
    globalThis.fetch = async (url) => {
        calls.push(String(url));
        return responder(String(url));
    };

    return Promise.resolve(run(calls)).finally(() => {
        globalThis.fetch = original;
    });
}

const jsonResponse = (data) => ({ ok: true, json: async () => ({ data }) });
const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

test('remoteSearch fetches without allowCustom and maps label, sublabel and badge', async () => {
    await withFetch(
        () => jsonResponse([{ value: 'hash1', label: 'PO-77', sublabel: 'PT Maju', badge: 'OPEN', badgeTone: 'success' }]),
        async (calls) => {
            const comp = searchableSelect({ suggestionsUrl: '/po-suggestions', remoteSearch: true });
            comp.fetchSuggestions('maju');
            await wait(400);

            assert.equal(calls.length, 1);
            assert.match(calls[0], /po-suggestions\?q=maju/);
            assert.deepEqual(comp.options.map((o) => [o.value, o.label, o.sublabel, o.badge]), [['hash1', 'PO-77', 'PT Maju', 'OPEN']]);
            comp.search = 'maju';
            assert.equal(comp.filteredOptions.length, 1, 'remote hit must survive the client-side filter');
        },
    );
});

test('remoteSearch does not duplicate options and ignores stale responses', async () => {
    const resolvers = {};
    await withFetch(
        (url) => new Promise((resolve) => { resolvers[new URL(url, 'http://x').searchParams.get('q')] = resolve; }),
        async () => {
            const comp = searchableSelect({
                suggestionsUrl: '/po-suggestions',
                remoteSearch: true,
                options: [{ value: 'hash1', label: 'PO-77', sublabel: null, searchKeywords: 'po-77' }],
            });
            comp.fetchSuggestions('po');
            await wait(350);
            comp.fetchSuggestions('po-7');
            await wait(350);

            resolvers['po-7'](jsonResponse([{ value: 'hash1', label: 'PO-77' }, { value: 'hash2', label: 'PO-78' }]));
            await wait(20);
            resolvers.po(jsonResponse([{ value: 'stale', label: 'PO-STALE' }]));
            await wait(20);

            assert.deepEqual(comp.options.map((o) => o.value).sort(), ['hash1', 'hash2']);
        },
    );
});

test('suggestions stay disabled without remoteSearch or allowCustom', async () => {
    await withFetch(
        () => jsonResponse([{ value: 'x', label: 'X' }]),
        async (calls) => {
            const comp = searchableSelect({ suggestionsUrl: '/po-suggestions' });
            comp.fetchSuggestions('abc');
            await wait(400);
            assert.equal(calls.length, 0);
        },
    );
});

test('legacy pr_number suggestions keep working for allowCustom selects', async () => {
    await withFetch(
        () => jsonResponse([{ pr_number: 'PR-900' }]),
        async () => {
            const comp = searchableSelect({ suggestionsUrl: '/pr-suggestions', allowCustom: true });
            comp.fetchSuggestions('PR-9');
            await wait(400);
            assert.equal(comp.options[0].value, 'PR-900');
            assert.equal(comp.options[0].label, 'PR-900');
        },
    );
});

test('remoteSearch preserves server searchKeywords so PR number matches survive filteredOptions', async () => {
    await withFetch(
        () => jsonResponse([{
            value: 'hash99',
            label: 'PO-99',
            sublabel: 'PT Sumber',
            badge: 'OPEN',
            searchKeywords: 'po-99 pr-2026-088 pt sumber open',
        }]),
        async () => {
            const comp = searchableSelect({ suggestionsUrl: '/po-suggestions', remoteSearch: true });
            comp.fetchSuggestions('pr-2026-088');
            await wait(400);

            comp.search = 'pr-2026-088';
            assert.equal(comp.filteredOptions.length, 1);
            assert.equal(comp.filteredOptions[0].value, 'hash99');
        },
    );
});

test('remoteSearch ignores stale in-flight response after search shrinks below 2 chars', async () => {
    let resolver;
    await withFetch(
        () => new Promise((resolve) => { resolver = resolve; }),
        async () => {
            const comp = searchableSelect({ suggestionsUrl: '/po-suggestions', remoteSearch: true });
            comp.fetchSuggestions('po-old');
            await wait(350);

            comp.fetchSuggestions('p');
            resolver(jsonResponse([{ value: 'stale', label: 'PO-OLD' }]));
            await wait(20);

            assert.equal(comp.options.length, 0);
            assert.equal(comp.loadingSuggestions, false);
        },
    );
});

test('searchableSelect toggles hasAsyncError on form submit/error events and clears on select', () => {
    const formListeners = {};
    const form = {
        addEventListener(type, fn) { formListeners[type] = fn; },
    };
    const comp = searchableSelect({ remoteSearch: true });
    comp.$watch = () => {};
    comp.$refs = { nativeSelect: { name: 'purchase_order', form, value: '', dispatchEvent() {}, querySelector: () => ({}) } };
    comp.init();

    assert.equal(comp.hasAsyncError, false);
    formListeners['adasi:form-errors']({ detail: { errors: { purchase_order: ['Required'] } } });
    assert.equal(comp.hasAsyncError, true);

    comp.select({ value: 'hash1', label: 'PO-1' });
    assert.equal(comp.hasAsyncError, false);

    formListeners['adasi:form-errors']({ detail: { errors: { purchase_order: ['Cancelled'] } } });
    assert.equal(comp.hasAsyncError, true);
    formListeners['adasi:form-submitting']();
    assert.equal(comp.hasAsyncError, false);
});

test('select switches directly to another PO without clear and avoids duplicate change dispatch on watcher tick', () => {
    const watchers = [];
    let changeEvents = 0;
    const nativeSelect = {
        name: 'local_purchase_order_id',
        value: '',
        dispatchEvent(ev) {
            if (ev?.type === 'change') {
                changeEvents++;
            }
        },
        querySelector: () => ({}),
    };
    const comp = searchableSelect({
        options: [
            { value: '30', label: 'PO-LOC-3WAY-001' },
            { value: '31', label: 'PO-LOC-3WAY-002' },
        ],
    });
    comp.$watch = (prop, fn) => {
        if (prop === 'selectedValue') watchers.push(fn);
    };
    comp.$refs = { nativeSelect };
    comp.init();

    comp.select(comp.options[0]);
    watchers.forEach((fn) => fn(comp.selectedValue));
    assert.equal(comp.selectedValue, '30');
    assert.equal(comp.selectedOption?.label, 'PO-LOC-3WAY-001');
    assert.equal(nativeSelect.value, '30');
    assert.equal(changeEvents, 1);

    // Directly select another PO without calling clear()
    comp.select(comp.options[1]);
    watchers.forEach((fn) => fn(comp.selectedValue));
    assert.equal(comp.selectedValue, '31');
    assert.equal(comp.selectedOption?.label, 'PO-LOC-3WAY-002');
    assert.equal(nativeSelect.value, '31');
    assert.equal(changeEvents, 2);

    // Mutating selectedValue directly (without syncNativeSelect) still syncs via watcher
    comp.selectedValue = '30';
    watchers.forEach((fn) => fn(comp.selectedValue));
    assert.equal(nativeSelect.value, '30');
    assert.equal(changeEvents, 3);
});
