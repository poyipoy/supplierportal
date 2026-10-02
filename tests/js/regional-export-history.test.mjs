import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const layout = await readFile(new URL('../../resources/views/layouts/app.blade.php', import.meta.url), 'utf8');
const exportView = await readFile(new URL('../../resources/views/exports/index.blade.php', import.meta.url), 'utf8');
const cases = JSON.parse(await readFile(new URL('../Fixtures/regional-timestamp-cases.json', import.meta.url), 'utf8'));
const registry = {
    number_profiles: {},
    date_formats: ['system', 'human', 'dmy', 'iso'],
    months: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
};

function boot(overrides = {}) {
    const marker = 'const preferences = @js($preferenceFrontendPayload);';
    const index = layout.indexOf(marker);
    const start = layout.lastIndexOf('<script>', index) + 8;
    const end = layout.indexOf('</script>', index);
    const payload = {
        theme: 'system', density: 'comfortable', accent: 'brand', accountId: '42',
        sidebarState: 'expanded', sidebarRevision: 'sidebar-v2:1', pageSize: 25,
        regional: {timezone: 'system', date_format: 'system', time_format: 'system', number_format: 'system', ...overrides},
        regionalRegistry: registry,
    };
    const windowRef = {
        matchMedia: () => ({matches: false, addEventListener(){}}),
        dispatchEvent(){},
        localStorage: {getItem: () => null, setItem(){}},
    };
    vm.runInNewContext(layout.slice(start, end).replace(marker, `const preferences = ${JSON.stringify(payload)};`), {
        window: windowRef, document: {documentElement: {dataset: {}}}, CustomEvent: class {},
        Intl, Date, Number, String, Object, Array, Math,
    });
    return windowRef.AdasiPreferences;
}

test('timestamp helper is part of the existing AdasiPreferences API', () => {
    assert.match(layout, /displayTimestamp/);
    assert.doesNotMatch(layout, /window\.AdasiTimestamp|window\.RegionalTimestamp/);
});

for (const sample of cases) {
    test(`timestamp golden: ${sample.name}`, () => {
        const preferences = boot(sample.preferences);
        const legacy = (value) => `browser legacy:${value}`;
        if (sample.systemCompatibility) {
            assert.equal(preferences.displayTimestamp(sample.value, legacy), `browser legacy:${sample.value}`);
        } else {
            assert.equal(preferences.displayTimestamp(sample.value, legacy), sample.expected);
        }
    });
}

test('fully System and number-only preferences preserve renderer-specific legacy output', () => {
    const legacy = (value) => `browser legacy:${value}`;
    for (const values of [
        {timezone: 'system', date_format: 'system', time_format: 'system', number_format: 'system'},
        {timezone: 'system', date_format: 'system', time_format: 'system', number_format: 'indonesian'},
    ]) {
        assert.equal(boot(values).displayTimestamp('2026-09-28T23:35:00Z', legacy), 'browser legacy:2026-09-28T23:35:00Z');
    }
});

test('null, missing, malformed and calendar-date inputs use the neutral fallback', () => {
    const display = boot({timezone: 'Asia/Jakarta', date_format: 'human', time_format: '24h'}).displayTimestamp;
    for (const value of [null, undefined, '', 'not-a-date', '2026-09-30', '2026-02-30T23:35:00Z']) {
        assert.equal(display(value, () => assert.fail('invalid input must not call legacy parsing')), '-');
    }
});

test('only the three approved polling labels use displayTimestamp and keep raw fields', () => {
    assert.match(exportView, /formatDate\s*=\s*\(value\)\s*=>/);
    assert.match(exportView, /const regionalTimestamp\s*=\s*\(value\)\s*=>\s*window\.AdasiPreferences\.displayTimestamp\(value,\s*formatDate\)/);
    assert.equal((exportView.match(/regionalTimestamp\(item\.(?:created_at|completed_at|expires_at)\)/g) || []).length, 3);
    assert.doesNotMatch(exportView, /regionalTimestamp\(item\.(?!created_at|completed_at|expires_at)/);
    assert.equal((exportView.match(/formatDate\(item\.(?:created_at|completed_at|expires_at)\)/g) || []).length, 0);
    assert.match(exportView, /escapeHtml\(regionalTimestamp\(item\.created_at\)\)/);
    assert.match(exportView, /escapeHtml\(regionalTimestamp\(item\.completed_at\)\)/);
    assert.match(exportView, /escapeHtml\(regionalTimestamp\(item\.expires_at\)\)/);
});

test('polling renders escaped Regional timestamps without mutating the response items', async () => {
    const preferences = boot({timezone: 'Asia/Jakarta', date_format: 'human', time_format: '24h'});
    const items = [{
        id: 'hashed-export-id', label: '<script>label</script>', file_name: '<img src=x>',
        status: 'completed', created_at: '2026-09-28T23:35:00Z',
        completed_at: '2026-09-29T00:35:00Z', expires_at: '2026-10-02T23:35:00Z',
        download_url: null,
    }];
    const rawSnapshot = JSON.stringify(items);
    const timers = [];
    let ready;
    const body = {innerHTML: ''};
    const state = {innerHTML: '', textContent: '', className: ''};
    const document = {
        addEventListener: (name, handler) => { if (name === 'DOMContentLoaded') ready = handler; },
        getElementById: (id) => id === 'exportJobsTableBody' ? body : state,
    };
    const window = {
        AdasiPreferences: preferences,
        setTimeout: (callback, delay) => timers.push({callback, delay}),
    };
    const scriptStart = exportView.lastIndexOf('<script>');
    const scriptEnd = exportView.indexOf('</script>', scriptStart);
    const script = exportView.slice(scriptStart + '<script>'.length, scriptEnd)
        .replace('@json(route(\'exports.index\', request()->query(), absolute: false))', '"/exports"')
        .replace('@json($hasPending)', 'true');
    const context = {
        window, document, Date, Intl, String, Number, Object, Array, Promise,
        fetch: async () => ({ok: true, json: async () => ({data: items, has_pending: false})}),
    };
    vm.runInNewContext(script, context);
    ready();
    assert.equal(timers[0].delay, 5000);
    await timers[0].callback();

    assert.match(body.innerHTML, /29 Sep 2026 06:35 WIB/);
    assert.match(body.innerHTML, /Completed: 29 Sep 2026 07:35 WIB/);
    assert.match(body.innerHTML, /Expires: 03 Oct 2026 06:35 WIB/);
    assert.match(body.innerHTML, /&lt;script&gt;label&lt;\/script&gt;/);
    assert.match(body.innerHTML, /&lt;img src=x&gt;/);
    assert.doesNotMatch(body.innerHTML, /<script>|<img/);
    assert.equal(JSON.stringify(items), rawSnapshot);
    assert.equal(timers.length, 1);
    assert.equal(state.textContent, '');
});

test('polling System mode keeps the browser Intl renderer instead of the server display profile', async () => {
    const previousTimeZone = process.env.TZ;
    process.env.TZ = 'America/Los_Angeles';

    try {
    const value = '2026-09-28T23:35:00Z';
    const expectedBrowser = new Intl.DateTimeFormat('en-GB', {dateStyle: 'medium', timeStyle: 'short'}).format(new Date(value));
    const timers = [];
    let ready;
    const body = {innerHTML: ''};
    const state = {innerHTML: '', textContent: '', className: ''};
    const window = {AdasiPreferences: boot(), setTimeout: (callback, delay) => timers.push({callback, delay})};
    const document = {
        addEventListener: (name, handler) => { if (name === 'DOMContentLoaded') ready = handler; },
        getElementById: (id) => id === 'exportJobsTableBody' ? body : state,
    };
    const scriptStart = exportView.lastIndexOf('<script>');
    const scriptEnd = exportView.indexOf('</script>', scriptStart);
    const script = exportView.slice(scriptStart + '<script>'.length, scriptEnd)
        .replace('@json(route(\'exports.index\', request()->query(), absolute: false))', '"/exports"')
        .replace('@json($hasPending)', 'true');
    vm.runInNewContext(script, {
        window, document, Date, Intl, String, Number, Object, Array, Promise,
        fetch: async () => ({
            ok: true,
            json: async () => ({
                data: [{id: 'job', label: 'Export', file_name: 'file.xlsx', status: 'completed', created_at: value, completed_at: null, expires_at: null}],
                has_pending: false,
            }),
        }),
    });
    ready();
    await timers[0].callback();

    assert.equal(Intl.DateTimeFormat().resolvedOptions().timeZone, 'America/Los_Angeles');
    assert.match(body.innerHTML, new RegExp(expectedBrowser.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));
    assert.equal(expectedBrowser, '28 Sept 2026, 16:35');
    assert.notEqual(expectedBrowser, '28 Sep 2026 23:35');
    assert.match(body.innerHTML, /<td class="tw-text-ui-xs tw-text-on-surface-variant">-<\/td>/);
    assert.equal(timers[0].delay, 5000);
    } finally {
        if (previousTimeZone === undefined) delete process.env.TZ;
        else process.env.TZ = previousTimeZone;
    }
});

test('timestamp preference logic accepts no arbitrary formatter or timezone input', () => {
    const source = layout.slice(layout.lastIndexOf('const displayTimestamp'), layout.indexOf('window.AdasiPreferences = Object.freeze'));
    assert.doesNotMatch(source, /new Function|eval\(|Intl\.[^(]+\([^)]*regional/);
    assert.match(source, /Asia\/Jakarta/);
    assert.match(source, /regionalRegistry\.date_formats/);
});
