import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const scriptsView = await readFile(new URL('../../resources/views/purchasing/comparison/_scripts.blade.php', import.meta.url), 'utf8');

// Run the real helper definitions (from the shared-keys line to the end of the guard).
function loadHelpers({ tabs = [], forms = [], links = [], state = null } = {}) {
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
});
