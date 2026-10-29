import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const scriptsView = await readFile(new URL('../../resources/views/purchasing/comparison/_scripts.blade.php', import.meta.url), 'utf8');

// Run the real helper definitions (from the shared-keys line to the end of the guard).
function loadHelpers({ tabs = [], forms = [], state = null } = {}) {
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
