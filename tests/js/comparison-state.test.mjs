import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const scriptsView = await readFile(new URL('../../resources/views/purchasing/comparison/_scripts.blade.php', import.meta.url), 'utf8');

// Run the real helper definitions (from the shared-keys line to the end of the guard).
function loadHelpers({ tabs = [], forms = [], links = [], state = null, documentElement = null } = {}) {
    const start = scriptsView.indexOf('const comparisonSharedKeys');
    const end = scriptsView.indexOf('if (!window._analysisCurrencyListener)');
    const replaced = [];
    const windowRef = {
        location: { origin: 'http://portal.test', href: 'http://portal.test/purchasing/comparison/historical' },
        history: {
            state,
            replaceState(next, _title, url) { this.state = next; replaced.push({ state: next, url }); },
        },
    };
    const documentRef = {
        ...(documentElement ? { documentElement } : {}),
        querySelectorAll(selector) {
            if (selector.includes('nav [data-server-tab]')) return tabs;
            if (selector.includes('[data-evaluation-header] form')) return forms;
            if (selector.includes('[data-return-url]')) return links;
            return [];
        },
        createElement: () => ({ type: '', name: '', value: '' }),
    };
    vm.runInNewContext(scriptsView.slice(start, end), { window: windowRef, document: documentRef, URL, AbortController });

    return { windowRef, replaced };
}

function fakeTab(href) {
    return { href, getAttribute() { return this.href; }, setAttribute(_name, value) { this.href = value; } };
}

function fakeForm(hidden) {
    const inputs = [...hidden];
    return {
        inputs,
        querySelectorAll: () => [...inputs].map((input) => ({ ...input, remove: () => inputs.splice(inputs.indexOf(input), 1) })),
        prepend(input) { inputs.unshift(input); },
    };
}

test('the request guard aborts the previous request and only the latest is current', () => {
    const { windowRef } = loadHelpers();
    const guard = windowRef.createComparisonRequestGuard();
    const first = guard.start();
    const second = guard.start();
    assert.equal(first.signal.aborted, true, 'starting a new request aborts the older one');
    assert.equal(second.signal.aborted, false);
    assert.equal(guard.isCurrent(first.id), false, 'a late response of the older request is ignored');
    assert.equal(guard.isCurrent(second.id), true);
    // Flat → Round → Flat quickly: only the last selection may render.
    const ids = [guard.start().id, guard.start().id, guard.start().id];
    assert.deepEqual(ids.map((id) => guard.isCurrent(id)), [false, false, true]);
});

test('syncComparisonState keeps the server-tabs marker and shares material, shape and currency', () => {
    const tabs = [fakeTab('/purchasing/comparison/inter-supplier'), fakeTab('/purchasing/comparison/vs-best?material_name=OLD&shape=Flat')];
    const form = fakeForm([{ type: 'hidden', name: 'material_name', value: 'OLD' }]);
    const { windowRef, replaced } = loadHelpers({ tabs, forms: [form], state: { _serverTabs: '#purchasingComparisonContainer', other: 1 } });

    windowRef.syncComparisonState('http://portal.test/purchasing/comparison/historical?material_name=SCM440&shape=Round&currency=USD&key_min=50.0000&key_max=50.0000');

    assert.equal(replaced.length, 1);
    assert.equal(replaced[0].state._serverTabs, '#purchasingComparisonContainer', 'Back/Forward keeps swapping tabs');
    assert.equal(replaced[0].state.other, 1, 'existing history state is preserved');
    assert.match(replaced[0].url, /key_min=50\.0000/);
    assert.equal(tabs[0].href, '/purchasing/comparison/inter-supplier?material_name=SCM440&shape=Round&currency=USD');
    assert.equal(tabs[1].href, '/purchasing/comparison/vs-best?material_name=SCM440&shape=Round&currency=USD');
    const names = form.inputs.map((input) => `${input.name}=${input.value}`).sort();
    assert.deepEqual(names, ['key_max=50.0000', 'key_min=50.0000', 'material_name=SCM440', 'shape=Round'], 'the currency form preserves the current filters, not stale ones');

    // Clearing the material removes it from every tab.
    windowRef.syncComparisonState('http://portal.test/purchasing/comparison/historical?currency=USD');
    assert.equal(tabs[0].href, '/purchasing/comparison/inter-supplier?currency=USD');
});

function namedTab(name, href) {
    return { ...fakeTab(href), dataset: { tabName: name } };
}

test('Gate 1 C6: the active PR follows the current URL; Historical-local keys never travel', () => {
    const tabs = [
        namedTab('inter-supplier', '/purchasing/comparison/inter-supplier?pr_id=OLDPR'),
        namedTab('historical', '/purchasing/comparison/historical?pr_id=OLDPR&pr_item=ITEM&chart=quote_trend'),
        namedTab('vs-best', '/purchasing/comparison/vs-best?pr_id=OLDPR'),
    ];
    const { windowRef } = loadHelpers({ tabs });

    windowRef.syncComparisonState('http://portal.test/purchasing/comparison/historical?pr_id=PRB&pr_item=ITEM2&chart=po_history&currency=USD');
    assert.deepEqual(tabs.map((tab) => tab.href), [
        '/purchasing/comparison/inter-supplier?pr_id=PRB&currency=USD',
        '/purchasing/comparison/historical?pr_id=PRB&currency=USD',
        '/purchasing/comparison/vs-best?pr_id=PRB&currency=USD',
    ], 'PR A to PR B: every tab carries B; pr_item and chart stay on the page');

    windowRef.syncComparisonState('http://portal.test/purchasing/comparison/vs-best?currency=USD');
    assert.deepEqual(tabs.map((tab) => tab.href), [
        '/purchasing/comparison/inter-supplier?pr_id=PRB&currency=USD',
        '/purchasing/comparison/historical?currency=USD',
        '/purchasing/comparison/vs-best?currency=USD',
    ], 'without an active PR, Historical and Vs Best never keep a remembered one; Inter-Supplier keeps its own');
});

test('Gate 1 C6 (N7): detail links return to the URL shown now', () => {
    const link = fakeTab('/purchasing/quotations/QHASH?return_url=%2Fpurchasing%2Fcomparison%2Fhistorical%3Fpr_id%3DA');
    const { windowRef } = loadHelpers({ links: [link] });

    windowRef.syncComparisonState('http://portal.test/purchasing/comparison/historical?pr_id=A&pr_item=I2&chart=quote_trend');

    const url = new URL(link.href, 'http://portal.test');
    assert.equal(url.pathname, '/purchasing/quotations/QHASH');
    assert.equal(url.searchParams.get('return_url'), '/purchasing/comparison/historical?pr_id=A&pr_item=I2&chart=quote_trend');

    // Inside WorkspaceShell iframes, _embed=1 must never leak into return_url on detail links.
    windowRef.syncComparisonState('http://portal.test/purchasing/comparison/historical?pr_id=A&pr_item=I2&_embed=1');
    const embeddedUrl = new URL(link.href, 'http://portal.test');
    assert.equal(embeddedUrl.searchParams.get('return_url'), '/purchasing/comparison/historical?pr_id=A&pr_item=I2');
});

function stripBladeJsonCalls(source) {
    let result = '';
    let index = 0;

    while (index < source.length) {
        const matchOffset = source.slice(index).search(/@(?:json|js)\s*\(/);
        if (matchOffset === -1) {
            result += source.slice(index);
            break;
        }

        result += source.slice(index, index + matchOffset);
        let cursor = source.indexOf('(', index + matchOffset) + 1;
        let depth = 1;
        let inSingleQuote = false;

        while (cursor < source.length && depth > 0) {
            const char = source[cursor];
            if (inSingleQuote) {
                if (char === '\\') {
                    cursor += 2;
                    continue;
                }
                if (char === '\'') {
                    inSingleQuote = false;
                }
            } else if (char === '\'') {
                inSingleQuote = true;
            } else if (char === '(') {
                depth += 1;
            } else if (char === ')') {
                depth -= 1;
            }
            cursor += 1;
        }

        result += '"translated"';
        index = cursor;
    }

    return result;
}

function loadHistoricalController({ materialValue = '', materialDisabled = false, rangeValue = 'all' } = {}) {
    const helperStart = scriptsView.indexOf('const comparisonSharedKeys');
    const helperEnd = scriptsView.indexOf('if (!window._analysisCurrencyListener)');
    const histStart = scriptsView.indexOf('window.initHistoricalComparison = function()');
    const histEnd = scriptsView.indexOf('window.initVsBestComparison = function()');

    assert.notEqual(histStart, -1);
    assert.notEqual(histEnd, -1);

    const syncedUrls = [];
    const submitEvents = [];
    const fetchCalls = [];
    const listenersByTarget = new WeakMap();
    const documentListeners = new Map();
    const focused = [];

    function makeNode(id, initial = {}) {
        const node = {
            id,
            value: '',
            disabled: false,
            checked: false,
            textContent: '',
            dataset: {},
            style: {},
            options: [],
            get selectedOptions() {
                const match = this.options.find((opt) => opt.value === this.value);
                return match ? [match] : [];
            },
            _innerHTML: '',
            get innerHTML() {
                return this._innerHTML;
            },
            set innerHTML(html) {
                this._innerHTML = String(html);
                const opts = [];
                const re = /<option\s+value="([^"]*)"([^>]*)>([\s\S]*?)<\/option>/g;
                let m;
                while ((m = re.exec(this._innerHTML)) !== null) {
                    const selected = /\bselected\b/.test(m[2]);
                    opts.push({ value: m[1], selected, dataset: {}, textContent: m[3] });
                    if (selected) this.value = m[1];
                }
                if (opts.length > 0 && !opts.some((opt) => opt.selected)) {
                    this.value = opts[0].value;
                }
                this.options = opts;
            },
            attributes: {},
            setAttribute(name, value) { this.attributes[name] = String(value); },
            getAttribute(name) { return this.attributes[name] ?? null; },
            focus() { focused.push(id); documentRef.activeElement = node; },
            querySelectorAll() { return []; },
            querySelector() { return null; },
            addEventListener(type, handler) {
                const map = listenersByTarget.get(this) || new Map();
                const list = map.get(type) || [];
                list.push(handler);
                map.set(type, list);
                listenersByTarget.set(this, map);
            },
            dispatchEvent(event) {
                if (typeof event.preventDefault !== 'function') {
                    event.defaultPrevented = false;
                    event.preventDefault = () => { event.defaultPrevented = true; };
                }
                event.target = this;
                const map = listenersByTarget.get(this);
                for (const handler of (map?.get(event.type) || [])) {
                    handler.call(this, event);
                }
                if (event.bubbles) {
                    for (const handler of (documentListeners.get(event.type) || [])) {
                        handler.call(documentRef, event);
                    }
                }
                if (event.type === 'submit') {
                    submitEvents.push({ defaultPrevented: event.defaultPrevented });
                }
                return !event.defaultPrevented;
            },
            ...initial,
        };
        return node;
    }

    const configEl = makeNode('historicalConfig', {
        textContent: JSON.stringify({
            materialsUrl: 'http://portal.test/purchasing/comparison/historical/materials',
            historicalDataUrl: 'http://portal.test/purchasing/comparison/historical',
            currency: 'IDR',
            periodView: 'monthly',
            monthlyRangeOptions: { '3m': '3 Months', 'all': 'All Months' },
            yearlyRangeOptions: { '1y': '1 Year', 'all': 'All Years' },
            chartData: null,
            selectedMaterialName: '',
        }),
    });
    const filterForm = makeNode('historicalFilterForm', { action: 'http://portal.test/purchasing/comparison/historical' });
    const supplierSelect = makeNode('historicalSupplierSelect', { value: '' });
    const materialSelect = makeNode('historicalMaterialSelect', {
        value: materialValue,
        disabled: materialDisabled,
        options: materialValue
            ? [{ value: materialValue, dataset: { shape: 'Flat', keyOptions: '[]' }, textContent: materialValue }]
            : [{ value: '', dataset: {}, textContent: 'Select Material' }],
    });
    const rangeSelect = makeNode('historicalRangeSelect', { value: rangeValue });
    const resultsContainer = makeNode('historicalResults');
    const resultsStatus = makeNode('historicalResultsStatus');
    const monthlyInput = makeNode('periodViewMonthly', { name: 'period_view', value: 'monthly', checked: true });
    const yearlyInput = makeNode('periodViewYearly', { name: 'period_view', value: 'yearly', checked: false });
    const nodes = {
        historicalConfig: configEl,
        historicalFilterForm: filterForm,
        historicalSupplierSelect: supplierSelect,
        historicalMaterialSelect: materialSelect,
        historicalRangeSelect: rangeSelect,
        historicalResults: resultsContainer,
        historicalResultsStatus: resultsStatus,
    };

    const documentRef = {
        activeElement: null,
        getElementById: (id) => nodes[id] || null,
        querySelectorAll(selector) {
            if (selector === 'input[name="period_view"]') return [monthlyInput, yearlyInput];
            return [];
        },
        querySelector(selector) {
            if (selector === 'input[name="period_view"]:checked') {
                return [monthlyInput, yearlyInput].find((input) => input.checked) || null;
            }
            return null;
        },
        addEventListener(type, handler) {
            const list = documentListeners.get(type) || [];
            list.push(handler);
            documentListeners.set(type, list);
        },
        createElement: () => ({ type: '', name: '', value: '' }),
    };

    class FakeEvent {
        constructor(type, options = {}) {
            this.type = type;
            this.bubbles = Boolean(options.bubbles);
            this.cancelable = Boolean(options.cancelable);
            this.defaultPrevented = false;
        }
        preventDefault() {
            if (this.cancelable) this.defaultPrevented = true;
        }
    }

    class FakeFormData {
        entries() {
            const activePeriodView = yearlyInput.checked ? 'yearly' : 'monthly';
            const pairs = [
                ['supplier_id', supplierSelect.value],
                ['range', rangeSelect.value],
                ['period_view', activePeriodView],
            ];
            if (!materialSelect.disabled && materialSelect.value) {
                pairs.push(['material_name', materialSelect.value]);
            }
            return pairs.values();
        }
    }

    const windowRef = {
        location: { origin: 'http://portal.test', href: 'http://portal.test/purchasing/comparison/historical' },
        history: {
            state: null,
            replaceState(next, _title, url) { this.state = next; syncedUrls.push(url); },
        },
        AdasiI18n: { t: (key) => key },
        AdasiLoader: { setTableBusy() {} },
        requestAnimationFrame: (cb) => cb(),
    };

    const combinedCode = [
        scriptsView.slice(helperStart, helperEnd),
        stripBladeJsonCalls(scriptsView.slice(histStart, histEnd)),
    ].join('\n');

    vm.runInNewContext(combinedCode, {
        window: windowRef,
        document: documentRef,
        URL,
        AbortController,
        Event: FakeEvent,
        FormData: FakeFormData,
        fetch: async (url) => {
            fetchCalls.push(url);
            return { ok: true, json: async () => ({ chartData: null, tableData: [], materialName: materialSelect.value }) };
        },
        console,
    });

    windowRef.initHistoricalComparison();

    return {
        windowRef,
        filterForm,
        supplierSelect,
        materialSelect,
        rangeSelect,
        resultsStatus,
        monthlyInput,
        yearlyInput,
        syncedUrls,
        submitEvents,
        fetchCalls,
        focused,
        FakeEvent,
    };
}

test('switching Yearly or Monthly without a selected material syncs URL cleanly and never triggers an unprevented submit', () => {
    const ctx = loadHistoricalController({ materialValue: '' });

    ctx.monthlyInput.checked = false;
    ctx.yearlyInput.checked = true;
    ctx.yearlyInput.dispatchEvent(new ctx.FakeEvent('change'));

    assert.equal(ctx.submitEvents.length, 0, 'yearly toggle must not dispatch synthetic form submit');
    assert.equal(ctx.fetchCalls.length, 0, 'yearly toggle without material must not call JSON fetch');
    assert.deepEqual(
        ctx.rangeSelect.options.map((opt) => opt.value),
        ['1y', 'all'],
        'yearly range options render immediately',
    );
    assert.match(ctx.syncedUrls.at(-1) || '', /period_view=yearly/);

    ctx.yearlyInput.checked = false;
    ctx.monthlyInput.checked = true;
    ctx.monthlyInput.dispatchEvent(new ctx.FakeEvent('change'));

    assert.equal(ctx.submitEvents.length, 0, 'monthly toggle must not dispatch synthetic form submit');
    assert.deepEqual(
        ctx.rangeSelect.options.map((opt) => opt.value),
        ['3m', 'all'],
        'monthly range options render immediately',
    );
    assert.match(ctx.syncedUrls.at(-1) || '', /period_view=monthly/);

    ctx.rangeSelect.value = '3m';
    ctx.rangeSelect.dispatchEvent(new ctx.FakeEvent('change'));
    assert.equal(ctx.submitEvents.length, 0, 'range change without material must not dispatch synthetic submit');
    assert.match(ctx.syncedUrls.at(-1) || '', /range=3m/);
});

test('submitting historical filter form without a selected material always calls preventDefault even when disabled', () => {
    const ctx = loadHistoricalController({ materialValue: '', materialDisabled: true });

    const notCancelled = ctx.filterForm.dispatchEvent(new ctx.FakeEvent('submit', { bubbles: true, cancelable: true }));

    assert.equal(notCancelled, false, 'submit event must be cancelled so AdasiLoader never locks UI');
    assert.deepEqual(ctx.submitEvents, [{ defaultPrevented: true }]);
    assert.deepEqual(ctx.focused, ['historicalSupplierSelect'], 'focus moves to supplier select when material select is disabled');
    assert.notEqual(ctx.resultsStatus.textContent, '', 'polite status announces prompt to select a material');
});

test('syncComparisonState keeps _embed=1 on tab hrefs only inside workspace embed frames and request guard exposes abort()', () => {
    const tabs = [
        namedTab('inter-supplier', '/purchasing/comparison/inter-supplier'),
        namedTab('historical', '/purchasing/comparison/historical'),
    ];
    const link = fakeTab('/purchasing/quotations/QHASH?return_url=%2Fpurchasing%2Fcomparison%2Fhistorical');
    const { windowRef } = loadHelpers({
        tabs,
        links: [link],
        documentElement: { dataset: { workspaceEmbed: 'true' } },
    });

    windowRef.syncComparisonState('http://portal.test/purchasing/comparison/historical?pr_id=PRB&currency=USD&_embed=1');

    assert.equal(
        tabs[0].href,
        '/purchasing/comparison/inter-supplier?currency=USD&pr_id=PRB&_embed=1',
        'embedded comparison tab links preserve _embed=1',
    );
    assert.equal(
        tabs[1].href,
        '/purchasing/comparison/historical?currency=USD&pr_id=PRB&_embed=1',
        'embedded historical tab link preserves _embed=1',
    );
    const detailUrl = new URL(link.href, 'http://portal.test');
    assert.equal(
        detailUrl.searchParams.get('return_url'),
        '/purchasing/comparison/historical?pr_id=PRB&currency=USD',
        'detail return_url still strips _embed=1',
    );

    const guard = windowRef.createComparisonRequestGuard();
    const active = guard.start();
    assert.equal(typeof guard.abort, 'function');
    guard.abort();
    assert.equal(active.signal.aborted, true, 'abort() cancels the in-flight request');
    assert.equal(guard.isCurrent(active.id), false, 'aborted request is invalidated');
});

test('initVsBestComparison wires top-toolbar search, respects AdasiPreferences.pageSize, enforces IME safety, and clears timer on abort', () => {
    const helperStart = scriptsView.indexOf('const comparisonSharedKeys');
    const helperEnd = scriptsView.indexOf('if (!window._analysisCurrencyListener)');
    const abortStart = scriptsView.indexOf('window.abortComparisonPendingRequests = function()');
    const abortEnd = scriptsView.indexOf('window.destroyComparisonActiveInstances = function()');
    const vsBestStart = scriptsView.indexOf('window.initVsBestComparison = function()');
    const vsBestEnd = scriptsView.indexOf('// ─── 4. GLOBAL CONTAINER COORDINATION');

    assert.notEqual(abortStart, -1);
    assert.notEqual(abortEnd, -1);
    assert.notEqual(vsBestStart, -1);
    assert.notEqual(vsBestEnd, -1);

    const listeners = new WeakMap();
    const focused = [];
    const dtCalls = {
        options: null,
        searches: [],
        draws: 0,
    };

    function makeNode(id, initial = {}) {
        const node = {
            id,
            value: '',
            hidden: false,
            textContent: '',
            selectedOptions: [],
            focus() { focused.push(id); },
            addEventListener(type, handler) {
                const map = listeners.get(this) || new Map();
                const list = map.get(type) || [];
                list.push(handler);
                map.set(type, list);
                listeners.set(this, map);
            },
            dispatchEvent(event) {
                if (typeof event.preventDefault !== 'function') {
                    event.defaultPrevented = false;
                    event.preventDefault = () => { event.defaultPrevented = true; };
                }
                if (typeof event.stopPropagation !== 'function') {
                    event.stopPropagation = () => {};
                }
                event.target = this;
                const map = listeners.get(this);
                for (const handler of (map?.get(event.type) || [])) {
                    handler.call(this, event);
                }
            },
            ...initial,
        };
        return node;
    }

    const configEl = makeNode('vsBestConfig', {
        textContent: JSON.stringify({
            summaryFallback: { total_rows: 10 },
            currency: 'IDR',
            filterParams: { currency: 'IDR' },
            tableDataUrl: 'http://portal.test/purchasing/comparison/vs-best/data',
        }),
    });
    const tableEl = makeNode('vsBestTable');
    const searchInput = makeNode('vsBestTableSearch', { value: '' });
    const searchClear = makeNode('vsBestTableSearchClear', { hidden: true });

    const nodes = {
        vsBestConfig: configEl,
        vsBestTable: tableEl,
        vsBestTableSearch: searchInput,
        vsBestTableSearchClear: searchClear,
    };

    let timerSeq = 0;
    const timers = new Map();
    const fakeSetTimeout = (fn, delay) => {
        const id = ++timerSeq;
        timers.set(id, { fn, delay });
        return id;
    };
    const fakeClearTimeout = (id) => {
        timers.delete(id);
    };

    class FakeDataTable {
        static isDataTable() {
            return false;
        }
        constructor(_el, options) {
            dtCalls.options = options;
        }
        search(query) {
            dtCalls.searches.push(query);
            return this;
        }
        draw() {
            dtCalls.draws += 1;
            return this;
        }
    }

    const windowRef = {
        location: { origin: 'http://portal.test', href: 'http://portal.test/purchasing/comparison/vs-best' },
        history: { state: null, replaceState() {} },
        AdasiPreferences: { pageSize: 50 },
        DataTable: FakeDataTable,
    };

    const documentRef = {
        getElementById: (id) => nodes[id] || null,
        querySelectorAll: () => [],
        createElement: () => ({ type: '', name: '', value: '' }),
    };

    const combinedCode = [
        scriptsView.slice(helperStart, helperEnd),
        scriptsView.slice(abortStart, abortEnd),
        stripBladeJsonCalls(scriptsView.slice(vsBestStart, vsBestEnd)),
    ].join('\n');

    vm.runInNewContext(combinedCode, {
        window: windowRef,
        document: documentRef,
        DataTable: FakeDataTable,
        URL,
        AbortController,
        setTimeout: fakeSetTimeout,
        clearTimeout: fakeClearTimeout,
        console,
    });

    windowRef.initVsBestComparison();

    const parsedLayout = JSON.parse(JSON.stringify(dtCalls.options.layout));
    assert.deepEqual(parsedLayout, {
        topStart: null,
        topEnd: null,
        bottomStart: 'info',
        bottomEnd: 'paging',
    }, 'default DataTables pageLength/search rows stay omitted');
    assert.equal(dtCalls.options.pageLength, 50, 'AdasiPreferences.pageSize sets initial pageLength without a dropdown');
    assert.equal(searchClear.hidden, true, 'clear button stays hidden while search input is empty');

    // Typing unhides clear button immediately and schedules 250ms debounced search.
    searchInput.value = 'SKD11';
    searchInput.dispatchEvent({ type: 'input' });
    assert.equal(searchClear.hidden, false);
    assert.equal(timers.size, 1);

    // Aborting tab requests cancels any pending debounced search timer before navigating away.
    windowRef.abortComparisonPendingRequests();
    assert.equal(timers.size, 0, 'abortComparisonPendingRequests cancels pending search debounce timers');

    // Re-trigger input and allow debounce timer to fire.
    searchInput.dispatchEvent({ type: 'input' });
    const pendingTimer = [...timers.values()][0];
    assert.equal(pendingTimer.delay, 250);
    pendingTimer.fn();
    assert.deepEqual([...dtCalls.searches], ['SKD11']);
    assert.equal(dtCalls.draws, 1);

    // IME composing Enter (isComposing or Safari keyCode 229) must NOT trigger search.
    searchInput.value = 'SUS304';
    searchInput.dispatchEvent({ type: 'keydown', key: 'Enter', isComposing: true });
    searchInput.dispatchEvent({ type: 'keydown', key: 'Enter', isComposing: false, keyCode: 229 });
    assert.equal(dtCalls.draws, 1, 'IME composition Enter keys never trigger premature search');

    // Confirmed non-composing Enter prevents form submission and commits immediately.
    const enterEvent = { type: 'keydown', key: 'Enter', isComposing: false, keyCode: 13 };
    searchInput.dispatchEvent(enterEvent);
    assert.equal(enterEvent.defaultPrevented, true, 'Enter inside merged filter form prevents full tab reload');
    assert.deepEqual([...dtCalls.searches], ['SKD11', 'SUS304']);
    assert.equal(dtCalls.draws, 2);

    // Escape clears active search text immediately.
    const escEvent = { type: 'keydown', key: 'Escape' };
    searchInput.dispatchEvent(escEvent);
    assert.equal(escEvent.defaultPrevented, true);
    assert.equal(searchInput.value, '');
    assert.equal(searchClear.hidden, true);
    assert.deepEqual([...dtCalls.searches], ['SKD11', 'SUS304', '']);
    assert.equal(dtCalls.draws, 3);

    // Clear button empties search, re-hides itself, redraws table, and restores focus.
    searchInput.value = 'P20';
    searchInput.dispatchEvent({ type: 'keydown', key: 'Enter', isComposing: false, keyCode: 13 });
    searchClear.dispatchEvent({ type: 'click' });
    assert.equal(searchInput.value, '');
    assert.equal(searchClear.hidden, true);
    assert.deepEqual([...dtCalls.searches], ['SKD11', 'SUS304', '', 'P20', '']);
    assert.equal(dtCalls.draws, 5);
    assert.deepEqual(focused, ['vsBestTableSearch']);
});
