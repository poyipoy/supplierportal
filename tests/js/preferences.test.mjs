import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const layout = await readFile(new URL('../../resources/views/layouts/app.blade.php', import.meta.url), 'utf8');

function headBootstrap() {
    const marker = 'const preferences = @js($preferenceFrontendPayload);';
    const content = layout.indexOf(marker);
    assert.notEqual(content, -1, 'the early preference bootstrap is present in the application head');
    const start = layout.lastIndexOf('<script>', content) + '<script>'.length;
    const end = layout.indexOf('</script>', content);

    return layout.slice(start, end);
}

function boot(payload, initialStorage = {}, storageAvailable = true, initialDensity = null) {
    payload = { densityKeys: ['spacious', 'comfortable', 'balanced', 'compact', 'dense'], ...payload, densityPreview: initialDensity };
    const values = new Map(Object.entries(initialStorage));
    const mediaListeners = [];
    const events = [];
    const root = { dataset: {} };
    const media = {
        matches: false,
        addEventListener: (_event, listener) => mediaListeners.push(listener),
    };
    const windowRef = {
        matchMedia: () => media,
        dispatchEvent: (event) => events.push(event),
        localStorage: {
            getItem: (key) => {
                if (!storageAvailable) throw new Error('storage unavailable');
                return values.get(key) ?? null;
            },
            setItem: (key, value) => {
                if (!storageAvailable) throw new Error('storage unavailable');
                values.set(key, value);
            },
        },
    };
    const script = headBootstrap().replace('@js($preferenceFrontendPayload)', JSON.stringify(payload));
    vm.runInNewContext(script, { window: windowRef, document: { documentElement: root }, CustomEvent: class { constructor(type, init) { this.type = type; this.detail = init.detail; } } });

    return { root, media, mediaListeners, events, values, windowRef };
}

test('server theme is applied before styles and system theme follows OS changes', () => {
    const state = boot({ theme: 'system', density: 'comfortable', sidebarState: 'expanded', pageSize: 25, sidebarRevision: '0:0', accountId: '41' });
    assert.equal(state.root.dataset.theme, 'light');
    assert.equal(state.root.dataset.bsTheme, undefined);
    state.media.matches = true;
    state.mediaListeners[0]();
    assert.equal(state.root.dataset.theme, 'dark');
    state.media.matches = false;
    state.mediaListeners[0]();
    assert.equal(state.root.dataset.theme, 'light');
});

test('explicit themes override OS preference and live preview restores saved theme and density', () => {
    const state = boot({ theme: 'light', density: 'comfortable', sidebarState: 'expanded', pageSize: 25, sidebarRevision: '0:0', accountId: '41' });
    state.media.matches = true;
    state.mediaListeners[0]();
    assert.equal(state.root.dataset.theme, 'light');
    state.windowRef.AdasiPreferences.previewTheme('dark');
    state.windowRef.AdasiPreferences.previewDensity('compact');
    assert.equal(state.root.dataset.theme, 'dark');
    assert.equal(state.root.dataset.density, 'compact');
    state.windowRef.AdasiPreferences.restoreSaved();
    assert.equal(state.root.dataset.theme, 'light');
    assert.equal(state.root.dataset.density, 'comfortable');
});

test('five canonical density previews stay temporary and independent of other preferences', () => {
    const densityKeys = ['spacious', 'comfortable', 'balanced', 'compact', 'dense'];
    const state = boot({ theme: 'light', density: 'comfortable', densityKeys, accent: 'brand', accentKeys: ['brand', 'slate'], sidebarState: 'expanded', pageSize: 25, sidebarRevision: '1:1', accountId: '41' });
    const beforeStorage = [...state.values];
    state.windowRef.AdasiPreferences.previewTheme('dark');
    state.windowRef.AdasiPreferences.previewAccent('slate');

    for (const density of densityKeys) {
        state.windowRef.AdasiPreferences.previewDensity(density);
        assert.equal(state.root.dataset.density, density);
        assert.equal(state.events.at(-1)?.type, 'adasi:density-change');
        assert.equal(state.events.at(-1)?.detail?.density, density);
        assert.equal(state.windowRef.AdasiPreferences.density, 'comfortable');
        assert.equal(state.root.dataset.theme, 'dark');
        assert.equal(state.root.dataset.accent, 'slate');
        assert.equal(state.windowRef.AdasiPreferences.pageSize, 25);
        assert.deepEqual([...state.values], beforeStorage);
    }

    for (const invalid of ['tiny', '2', 2, null, undefined, [], ['dense'], { density: 'dense' }]) {
        state.windowRef.AdasiPreferences.previewDensity(invalid);
        assert.equal(state.root.dataset.density, 'dense');
    }

    state.windowRef.AdasiPreferences.restoreSaved();
    assert.equal(state.root.dataset.density, 'comfortable');
    assert.equal(state.root.dataset.theme, 'light');
    assert.equal(state.root.dataset.accent, 'brand');
    assert.deepEqual([...state.values], beforeStorage);
});

test('valid server draft density is applied on bootstrap without replacing saved density', () => {
    const densityKeys = ['spacious', 'comfortable', 'balanced', 'compact', 'dense'];
    const state = boot({ theme: 'light', density: 'compact', densityKeys, sidebarState: 'expanded', pageSize: 25, sidebarRevision: '1:1', accountId: '41' }, {}, true, 'balanced');
    assert.equal(state.root.dataset.density, 'balanced');
    assert.equal(state.windowRef.AdasiPreferences.density, 'compact');
    state.windowRef.AdasiPreferences.restoreSaved();
    assert.equal(state.root.dataset.density, 'compact');
});

test('global appearance binding leaves Customization to its single form owner', async () => {
    const { bindCustomizationPreviews } = await import('../../resources/js/preferences.js');
    const calls = [];
    const inside = { checked: true, value: 'compact', listeners: [], closest: () => ({}), addEventListener(_type, handler) { this.listeners.push(handler); } };
    const outside = { checked: true, value: 'compact', listeners: [], closest: () => null, addEventListener(_type, handler) { this.listeners.push(handler); } };
    const doc = { querySelectorAll: selector => selector.includes('density') ? [inside, outside] : [] };
    const preferences = { previewDensity: value => calls.push(value) };
    bindCustomizationPreviews(doc, preferences);
    bindCustomizationPreviews(doc, preferences);
    assert.equal(inside.listeners.length, 0);
    assert.equal(outside.listeners.length, 1);
    outside.listeners[0]();
    assert.deepEqual(calls, ['compact']);
});

test('bfcache restoration delegates canonical form synchronization after one saved restore', async () => {
    const { bindBackForwardRestoration } = await import('../../resources/js/preferences.js');
    let pageshow;
    let restores = 0;
    const events = [];
    const doc = {
        querySelector: selector => selector === '#customizationForm' ? {} : null,
        querySelectorAll: () => { throw new Error('form owner must synchronize its own controls'); },
    };
    const browser = { addEventListener(_type, handler) { pageshow = handler; }, dispatchEvent(event) { events.push(event.type); } };
    bindBackForwardRestoration(browser, { restoreSaved() { restores++; } }, doc);
    pageshow({ persisted: false });
    assert.equal(restores, 0);
    pageshow({ persisted: true });
    assert.equal(restores, 1);
    assert.deepEqual(events, ['adasi:preferences-restored']);
});

test('ordinary history restoration synchronizes browser-restored fields without bfcache', async () => {
    const { bindBackForwardRestoration } = await import('../../resources/js/preferences.js');
    let pageshow;
    let restores = 0;
    const events = [];
    const browser = {
        performance: { getEntriesByType: () => [{ type: 'back_forward' }] },
        addEventListener(_type, handler) { pageshow = handler; },
        dispatchEvent(event) { events.push(event.type); },
    };
    bindBackForwardRestoration(browser, { restoreSaved() { restores++; } }, { querySelector: () => ({}) });
    pageshow({ persisted: false });
    assert.equal(restores, 1);
    assert.deepEqual(events, ['adasi:preferences-restored']);
});

test('sidebar cache is isolated per account and re-synced when server revision changes', () => {
    const cache = {
        'adasi.sidebar.41': JSON.stringify({ revision: '8:3', collapsed: true }),
        'adasi.sidebar.42': JSON.stringify({ revision: '7:2', collapsed: true }),
    };
    const sameRevision = boot({ theme: 'light', density: 'comfortable', sidebarState: 'expanded', pageSize: 25, sidebarRevision: '8:3', accountId: '41' }, cache);
    assert.equal(sameRevision.windowRef.__adasiSidebarInitialCollapsed, true);
    const changedRevision = boot({ theme: 'light', density: 'comfortable', sidebarState: 'expanded', pageSize: 25, sidebarRevision: '8:4', accountId: '41' }, cache);
    assert.equal(changedRevision.windowRef.__adasiSidebarInitialCollapsed, false);
    assert.equal(JSON.parse(changedRevision.values.get('adasi.sidebar.41')).revision, '8:4');
    const otherAccount = boot({ theme: 'light', density: 'comfortable', sidebarState: 'expanded', pageSize: 25, sidebarRevision: '7:2', accountId: '42' }, cache);
    assert.equal(otherAccount.windowRef.__adasiSidebarInitialCollapsed, true);
});

test('unavailable localStorage falls back to the server sidebar preference', () => {
    const state = boot({ theme: 'light', density: 'comfortable', sidebarState: 'collapsed', pageSize: 25, sidebarRevision: '0:0', accountId: '41' }, {}, false);
    assert.equal(state.windowRef.__adasiSidebarInitialCollapsed, true);
    state.windowRef.AdasiSidebarPreferences.write(false);
    assert.equal(state.windowRef.AdasiSidebarPreferences.read(), false);
});

test('sidebar width cache persists in adasi.sidebar per account, clamps bounds, and survives revision bumps', () => {
    const appliedProps = new Map();
    const layoutScript = headBootstrap().replace('@js($preferenceFrontendPayload)', JSON.stringify({
        theme: 'light',
        density: 'comfortable',
        densityKeys: ['spacious', 'comfortable', 'balanced', 'compact', 'dense'],
        sidebarState: 'expanded',
        pageSize: 25,
        sidebarRevision: '8:3',
        accountId: '41',
    }));
    const values = new Map([
        ['adasi.sidebar.41', JSON.stringify({ revision: '8:2', collapsed: true, width: 312 })],
    ]);
    const root = {
        dataset: {},
        style: {
            setProperty: (key, val) => appliedProps.set(key, val),
            removeProperty: (key) => appliedProps.delete(key),
        },
    };
    const windowRef = {
        innerWidth: 1280,
        matchMedia: () => ({ matches: false, addEventListener: () => {} }),
        dispatchEvent: () => {},
        localStorage: {
            getItem: (key) => values.get(key) ?? null,
            setItem: (key, val) => values.set(key, val),
        },
    };
    vm.runInNewContext(layoutScript, {
        window: windowRef,
        document: { documentElement: root },
        CustomEvent: class { constructor(type, init) { this.type = type; this.detail = init.detail; } },
    });

    assert.equal(windowRef.__adasiSidebarInitialCollapsed, false);
    assert.equal(windowRef.AdasiSidebarPreferences.readWidth(), 312);
    assert.equal(appliedProps.get('--sidebar-width'), '312px');

    windowRef.AdasiSidebarPreferences.writeWidth(1600);
    assert.equal(windowRef.AdasiSidebarPreferences.readWidth(), 1280);
    assert.equal(appliedProps.get('--sidebar-width'), '1280px');
    assert.equal(JSON.parse(values.get('adasi.sidebar.41')).width, 1280);

    windowRef.AdasiSidebarPreferences.writeWidth(120);
    assert.equal(root.dataset.sidebarNarrow, 'true');

    windowRef.AdasiSidebarPreferences.writeWidth(null);
    assert.equal(windowRef.AdasiSidebarPreferences.readWidth(), null);
    assert.equal(root.dataset.sidebarNarrow, undefined);
    assert.equal(appliedProps.has('--sidebar-width'), false);
    assert.equal('width' in JSON.parse(values.get('adasi.sidebar.41')), false);
});

test('chart theme refresh updates chrome while retaining callbacks and datasets', async () => {
    const oldWindow = globalThis.window;
    const oldGetComputedStyle = globalThis.getComputedStyle;
    const oldDocument = globalThis.document;
    let themeChange;
    globalThis.window = { addEventListener: (name, callback) => { if (name === 'adasi:theme-change') themeChange = callback; } };
    globalThis.document = { documentElement: {} };
    globalThis.getComputedStyle = () => ({ getPropertyValue: (name) => ({
        '--md-primary': '#1F5FA6',
        '--md-on-surface': '#E7EDF6',
        '--md-on-surface-variant': '#B4C0D2',
        '--md-outline': '#4C5B70',
        '--md-chart-grid': 'rgba(76, 91, 112, 0.58)',
    })[name] || '' });

    try {
        const { refreshChartTheme } = await import('../../resources/js/chart-theme.js?test=refresh');
        const callback = () => 'preserved';
        const data = [{ label: 'Revenue', data: [3, 5] }];
        const chart = {
            data: { datasets: data },
            options: {
                scales: { x: { grid: {}, ticks: {}, title: {} }, y: { grid: {}, ticks: {}, title: {} } },
                plugins: { legend: { labels: { callback } }, title: { text: 'Current' }, tooltip: { callbacks: { label: callback } } },
            },
            updates: [],
            update(mode) { this.updates.push(mode); },
        };
        const Chart = { defaults: { font: {}, plugins: { tooltip: {} } }, instances: { chart } };
        window.Chart = Chart;

        refreshChartTheme(Chart);
        themeChange();

        assert.equal(chart.options.scales.x.grid.color, 'rgba(76, 91, 112, 0.58)');
        assert.equal(chart.options.scales.y.ticks.color, '#B4C0D2');
        assert.equal(chart.options.plugins.title.color, '#E7EDF6');
        assert.equal(chart.options.plugins.tooltip.callbacks.label, callback);
        assert.equal(chart.options.plugins.legend.labels.callback, callback);
        assert.equal(chart.data.datasets, data);
        assert.deepEqual(chart.updates, ['none', 'none']);
    } finally {
        if (oldWindow === undefined) delete globalThis.window; else globalThis.window = oldWindow;
        if (oldGetComputedStyle === undefined) delete globalThis.getComputedStyle; else globalThis.getComputedStyle = oldGetComputedStyle;
        if (oldDocument === undefined) delete globalThis.document; else globalThis.document = oldDocument;
    }
});

test('System preview continues to follow OS changes when the saved choice is explicit', () => {
    const state = boot({ theme: 'light', density: 'comfortable', sidebarState: 'expanded', pageSize: 25, sidebarRevision: '0:0', accountId: '41' });
    state.windowRef.AdasiPreferences.previewTheme('system');
    state.media.matches = true;
    state.mediaListeners[0]();
    assert.equal(state.root.dataset.theme, 'dark');
    state.media.matches = false;
    state.mediaListeners[0]();
    assert.equal(state.root.dataset.theme, 'light');
});

test('accent is applied early and preview does not write persistent state', () => {
    const state = boot({ theme: 'light', density: 'comfortable', accent: 'brand', accentKeys: ['brand', 'slate'], sidebarState: 'expanded', pageSize: 25, sidebarRevision: '8:2', accountId: '41' });
    assert.equal(state.root.dataset.accent, 'brand');
    const storageBefore = Array.from(state.values.entries());
    state.windowRef.AdasiPreferences.previewAccent('slate');
    assert.equal(state.root.dataset.accent, 'slate');
    assert.deepEqual(Array.from(state.values.entries()), storageBefore);
    state.windowRef.AdasiPreferences.previewAccent('#ff0000');
    assert.equal(state.root.dataset.accent, 'slate');
    state.windowRef.AdasiPreferences.restoreSaved();
    assert.equal(state.root.dataset.accent, 'brand');
});

test('unrelated overall revisions preserve the same device sidebar token', () => {
    const cache = { 'adasi.sidebar.41': JSON.stringify({ revision: '8:2', collapsed: true }) };
    const state = boot({ theme: 'dark', density: 'compact', accent: 'slate', revision: 99, sidebarState: 'expanded', pageSize: 25, sidebarRevision: '8:2', accountId: '41' }, cache);
    assert.equal(state.windowRef.__adasiSidebarInitialCollapsed, true);
});

test('back-forward restoration restores saved accent alongside theme and density', async () => {
    const { bindBackForwardRestoration } = await import('../../resources/js/preferences.js');
    const state = boot({ theme: 'light', density: 'comfortable', accent: 'brand', accentKeys: ['brand', 'slate'], sidebarState: 'expanded', pageSize: 25, sidebarRevision: '8:2', accountId: '41' });
    let pageshow;
    bindBackForwardRestoration({ addEventListener: (_event, callback) => { pageshow = callback; } }, state.windowRef.AdasiPreferences);
    state.windowRef.AdasiPreferences.previewAccent('slate');
    state.windowRef.AdasiPreferences.previewTheme('dark');
    pageshow({ persisted: true });
    assert.equal(state.root.dataset.accent, 'brand');
    assert.equal(state.root.dataset.theme, 'light');
});

test('accent changes refresh chart chrome without changing business-series token colors', async () => {
    const saved = { window: globalThis.window, document: globalThis.document, getComputedStyle: globalThis.getComputedStyle };
    const listeners = {};
    globalThis.window = { addEventListener: (name, listener) => { listeners[name] = listener; } };
    globalThis.document = { documentElement: {} };
    globalThis.getComputedStyle = () => ({ getPropertyValue: name => ({ '--md-primary': '#475569', '--md-chart-primary': '#1F5FA6', '--md-chart-primary-container': '#EBF3FC' })[name] || '' });
    try {
        const theme = await import('../../resources/js/chart-theme.js?test=accent');
        assert.equal(theme.resolveChartThemeColors().primary, '#1F5FA6');
        assert.equal(typeof listeners['adasi:accent-change'], 'function');
    } finally {
        for (const [name, value] of Object.entries(saved)) {
            if (value === undefined) delete globalThis[name]; else globalThis[name] = value;
        }
    }
});

test('slate semantic tokens meet text and focus contrast in Light and Dark', async () => {
    const css = await readFile(new URL('../../resources/css/app.css', import.meta.url), 'utf8');
    const body = selector => {
        const index = css.indexOf(selector + ' {');
        assert.notEqual(index, -1);
        return css.slice(index, css.indexOf('}', index));
    };
    const value = (text, token) => text.match(new RegExp(token + ':\\s*(#[0-9a-fA-F]{6})'))?.[1];
    const luminance = hex => {
        assert.ok(hex, 'token has an explicit server-owned palette value');
        return [1, 3, 5].map(index => parseInt(hex.slice(index, index + 2), 16) / 255).map(c => c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4).reduce((sum, c, i) => sum + c * [0.2126, 0.7152, 0.0722][i], 0);
    };
    const ratio = (a, b) => (Math.max(luminance(a), luminance(b)) + 0.05) / (Math.min(luminance(a), luminance(b)) + 0.05);
    const lightSurface = value(body(':root'), '--md-surface');
    const darkSurface = value(body(':root[data-theme="dark"]'), '--md-surface');
    for (const [selector, surface] of [[':root[data-accent="slate"]', lightSurface], [':root[data-theme="dark"][data-accent="slate"]', darkSurface]]) {
        const tokens = body(selector);
        assert.ok(ratio(value(tokens, '--md-primary'), value(tokens, '--md-on-primary')) >= 4.5);
        assert.ok(ratio(value(tokens, '--ui-primary-hover'), value(tokens, '--md-on-primary')) >= 4.5);
        assert.ok(ratio(value(tokens, '--ui-primary-text'), surface) >= 4.5);
        assert.ok(ratio(value(tokens, '--md-primary'), surface) >= 3);
        assert.doesNotMatch(tokens, /--md-(?:error|warning|success):/);
    }
});

test('on-surface variant copy meets normal-text contrast on light and dark work surfaces', async () => {
    const css = await readFile(new URL('../../resources/css/app.css', import.meta.url), 'utf8');
    const body = selector => {
        const index = css.indexOf(selector + ' {');
        assert.notEqual(index, -1, 'selector ' + selector + ' exists');
        return css.slice(index, css.indexOf('}', index));
    };
    const value = (text, token) => text.match(new RegExp(token + ':\\s*(#[0-9a-fA-F]{6})'))?.[1];
    const luminance = hex => [1, 3, 5].map(index => parseInt(hex.slice(index, index + 2), 16) / 255)
        .map(channel => channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4)
        .reduce((sum, channel, index) => sum + channel * [0.2126, 0.7152, 0.0722][index], 0);
    const ratio = (a, b) => (Math.max(luminance(a), luminance(b)) + 0.05) / (Math.min(luminance(a), luminance(b)) + 0.05);

    const light = body(':root');
    const onVariant = value(light, '--md-on-surface-variant');
    assert.ok(ratio(onVariant, value(light, '--md-background')) >= 4.5, 'muted text on the workspace background');
    assert.ok(ratio(onVariant, value(light, '--md-surface-container')) >= 4.5, 'muted text on container surfaces');

    const dark = body(':root[data-theme="dark"]');
    assert.ok(ratio(value(dark, '--md-on-surface-variant'), value(dark, '--md-background')) >= 4.5, 'dark muted text on the workspace background');
    assert.ok(ratio(value(dark, '--md-on-surface-variant'), value(dark, '--md-surface-container')) >= 4.5, 'dark muted text on container surfaces');
});

test('dark overlays form a tonal elevation ladder and dark chart series stay perceivable', async () => {
    const css = await readFile(new URL('../../resources/css/app.css', import.meta.url), 'utf8');
    const body = selector => {
        const index = css.indexOf(selector + ' {');
        assert.notEqual(index, -1, 'selector ' + selector + ' exists');
        return css.slice(index, css.indexOf('}', index));
    };
    const value = (text, token) => text.match(new RegExp(token + ':\\s*(#[0-9a-fA-F]{6})'))?.[1];
    const luminance = hex => {
        assert.ok(hex, 'token has an explicit server-owned palette value');
        return [1, 3, 5].map(index => parseInt(hex.slice(index, index + 2), 16) / 255)
            .map(channel => channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4)
            .reduce((sum, channel, index) => sum + channel * [0.2126, 0.7152, 0.0722][index], 0);
    };
    const ratio = (a, b) => (Math.max(luminance(a), luminance(b)) + 0.05) / (Math.min(luminance(a), luminance(b)) + 0.05);

    const dark = body(':root[data-theme="dark"]');
    assert.match(dark, /--ui-menu-bg:\s*var\(--md-surface-container\);/);
    assert.match(dark, /--ui-dialog-bg:\s*var\(--md-surface-container-high\);/);

    // Full 6-tier dark tonal elevation ladder:
    // workspace < surface < container-low < container < container-high < container-highest.
    const tones = [
        '--ui-workspace-bg',
        '--md-surface',
        '--md-surface-container-low',
        '--md-surface-container',
        '--md-surface-container-high',
        '--md-surface-container-highest',
    ].map(token => luminance(value(dark, token)));
    tones.slice(1).forEach((tone, index) => assert.ok(tone > tones[index], 'overlay tier ' + (index + 1) + ' is lighter than the tier below'));

    // Dark table stripe stays between surface and thead, while light tones decrease strictly toward the workspace canvas.
    const darkStripe = luminance(value(dark, '--ui-table-row-stripe-bg'));
    const darkThead = luminance(value(dark, '--md-surface-container'));
    assert.ok(darkStripe >= luminance(value(dark, '--md-surface')) && darkStripe <= darkThead, 'dark table stripe sits cleanly between surface and thead');

    const light = body(':root');
    const lightTones = [
        luminance(value(light, '--md-surface')),
        luminance(value(light, '--ui-table-row-stripe-bg')),
        luminance(value(light, '--ui-table-header-bg')),
        luminance(value(light, '--md-surface-container')),
        luminance(value(light, '--ui-workspace-bg')),
    ];
    lightTones.slice(1).forEach((tone, index) => assert.ok(tone < lightTones[index], 'light surface step ' + (index + 1) + ' is strictly separated from the tier above'));
    assert.ok(ratio(value(light, '--md-surface'), value(light, '--ui-workspace-bg')) >= 1.20, 'light card/table surface separates clearly from the workspace canvas');
    assert.ok(ratio(value(light, '--md-surface'), value(light, '--ui-table-header-bg')) >= 1.12, 'light table header separates clearly from table body rows');

    const dialog = value(dark, '--md-surface-container-high');
    assert.ok(ratio(value(dark, '--md-on-surface'), dialog) >= 4.5, 'primary text on dialogs');
    assert.ok(ratio(value(dark, '--md-on-surface-variant'), dialog) >= 4.5, 'muted text on dialogs');

    for (const token of ['--md-chart-primary', '--md-chart-warning']) {
        assert.ok(ratio(value(dark, token), value(dark, '--md-surface')) >= 3, token + ' is a graphical object and needs 3:1 on the dark surface');
    }

    const cardComponent = await readFile(new URL('../../resources/views/components/ui/card.blade.php', import.meta.url), 'utf8');
    assert.match(cardComponent, /tw:bg-surface/, 'x-ui.card consumes --md-surface');
    assert.match(css, /dialog\.ui-native-dialog\s*>\s*\.modal-dialog\s*>\s*\.modal-content[\s\S]*?background-color:\s*var\(--ui-dialog-bg\);/, 'native dialog consumes --ui-dialog-bg');
    assert.match(css, /\[data-ui-dropdown-menu\]\[popover\]\s*\{[^}]*background-color:\s*var\(--ui-menu-bg\);/s, 'dropdown popover consumes --ui-menu-bg');
    assert.match(css, /\.adasi-toast\s*\{[^}]*background:\s*var\(--ui-dialog-bg\);/s, 'toast consumes --ui-dialog-bg');
    assert.match(css, /\.notification-dropdown\s*\{[^}]*background:\s*var\(--ui-menu-bg\);/s, 'notification dropdown consumes --ui-menu-bg');
    assert.match(css, /\.ui-tooltip\[popover\]\s*\{[^}]*background:\s*var\(--md-on-surface\);/s, 'tooltip popover uses inverted surface token');
    assert.match(dark, /--md-primary:\s*#8FB9EA;/, 'dark brand primary token is readable across dark surfaces');
    assert.match(dark, /--md-on-primary:\s*#0B192C;/, 'dark brand on-primary token passes AA on #8FB9EA');
    assert.match(dark, /--ui-primary-text:\s*#8FB9EA;/, 'dark brand primary text token is readable on dark surface');
});

test('history restoration synchronizes appearance radios with saved preference values', async () => {
    const { bindBackForwardRestoration } = await import('../../resources/js/preferences.js');
    const groups = {
        theme: [{ value: 'light', checked: false }, { value: 'dark', checked: true }],
        accent: [{ value: 'brand', checked: false }, { value: 'slate', checked: true }],
        density: [{ value: 'comfortable', checked: false }, { value: 'compact', checked: true }],
    };
    let pageshow;
    const doc = { querySelectorAll: selector => groups[selector.match(/name="([^"]+)"/)[1]] };
    bindBackForwardRestoration({ addEventListener: (_event, handler) => { pageshow = handler; } }, { theme: 'light', accent: 'brand', density: 'comfortable', restoreSaved() {} }, doc);
    pageshow({ persisted: true });
    for (const group of Object.values(groups)) assert.deepEqual(group.map(input => input.checked), [true, false]);
});

test('slate and dark primary hover tokens use contrast-safe palette without Bootstrap bridges', async () => {
    const css = await readFile(new URL('../../resources/css/app.css', import.meta.url), 'utf8');
    assert.match(css, /\.ui-button\.tw\\:bg-primary:hover:not\(:disabled\):not\(\[aria-disabled="true"\]\)\s*\{\s*background-color:\s*var\(--ui-primary-hover\);/s, 'primary button hover uses --ui-primary-hover token');
    const darkBrand = css.slice(css.indexOf(':root[data-theme="dark"] {'));
    assert.match(darkBrand.slice(0, darkBrand.indexOf('}')), /--ui-primary-hover: #A6C8F0;/);
    const darkSlate = css.slice(css.indexOf(':root[data-theme="dark"][data-accent="slate"] {'));
    assert.match(darkSlate.slice(0, darkSlate.indexOf('}')), /--ui-primary-hover: #C1CDDF/);
});

test('charts initialized by page scripts receive saved-theme chrome when the module loads', async () => {
    const saved = { window: globalThis.window, document: globalThis.document, getComputedStyle: globalThis.getComputedStyle };
    const datasets = [{ label: 'Requisitions', data: [1, 2] }];
    const chart = { data: { datasets }, options: { scales: { x: { ticks: { color: '#666' }, grid: {} } }, plugins: { tooltip: {} } }, update() {} };
    globalThis.window = { Chart: { defaults: { font: {}, plugins: { tooltip: {} } }, instances: { chart } }, addEventListener() {} };
    globalThis.document = { documentElement: {}, readyState: 'interactive' };
    globalThis.getComputedStyle = () => ({ getPropertyValue: name => ({ '--md-on-surface-variant': '#B4C0D2', '--md-chart-grid': 'rgba(76, 91, 112, 0.58)' })[name] || '' });
    try {
        await import('../../resources/js/chart-theme.js?test=initial-theme');
        assert.equal(chart.options.scales.x.ticks.color, '#B4C0D2');
        assert.equal(chart.options.scales.x.grid.color, 'rgba(76, 91, 112, 0.58)');
        assert.equal(chart.data.datasets, datasets);
    } finally {
        for (const [name, value] of Object.entries(saved)) {
            if (value === undefined) delete globalThis[name]; else globalThis[name] = value;
        }
    }
});

test('brand default tokens and legacy appearance are strictly locked', async () => {
    const css = await readFile(new URL('../../resources/css/app.css', import.meta.url), 'utf8');

    // Light root token assertions for brand
    assert.match(css, /--md-primary:\s*#1F5FA6;/, 'brand light primary base is #1F5FA6');
    assert.match(css, /--md-primary-rgb:\s*31,\s*95,\s*166;/, 'brand light primary rgb is 31, 95, 166');
    assert.match(css, /--md-on-primary:\s*#FFFFFF;/, 'brand light on-primary is #FFFFFF');
    assert.match(css, /--md-primary-container:\s*#EBF3FC;/, 'brand light container is #EBF3FC');
    assert.match(css, /--md-on-primary-container:\s*#0E3566;/, 'brand light on-container is #0E3566');

    // Dark root token assertions for brand
    const darkRootIdx = css.indexOf(':root[data-theme="dark"] {');
    assert.notEqual(darkRootIdx, -1, 'dark theme root is defined');
    const darkRoot = css.slice(darkRootIdx, css.indexOf('}', darkRootIdx));
    assert.match(darkRoot, /--md-primary:\s*#8FB9EA;/, 'brand dark primary is #8FB9EA');
    assert.match(darkRoot, /--md-on-primary:\s*#0B192C;/, 'brand dark on-primary is #0B192C');
    assert.match(darkRoot, /--ui-primary-text:\s*#8FB9EA;/, 'brand dark primary text is #8FB9EA');
    assert.match(darkRoot, /--md-primary-container:\s*#1B3654;/, 'brand dark container is #1B3654');
    assert.match(darkRoot, /--md-on-primary-container:\s*#D9E9FF;/, 'brand dark on-container is #D9E9FF');
    assert.match(darkRoot, /--ui-input-focus:\s*#8FB9EA;/, 'brand dark input focus is #8FB9EA');

    // Swatch assertions
    assert.match(css, /\[data-accent-swatch="brand"\]\s*\{\s*background-color:\s*#1F5FA6;\s*\}/, 'brand swatch is #1F5FA6');
});

test('early bootstrap defaults to brand and ignores unregistered preview accents', () => {
    // Missing accent in payload falls back to brand
    const state = boot({ theme: 'light', density: 'comfortable', sidebarState: 'expanded', pageSize: 25, sidebarRevision: '1:1', accountId: '41' });
    assert.equal(state.root.dataset.accent, 'brand');

    // previewAccent with unknown key does not alter data-accent
    state.windowRef.AdasiPreferences.previewAccent('non_existent_color');
    assert.equal(state.root.dataset.accent, 'brand');
});

test('all approved preset tokens meet text, hover, and focus contrast in Light and Dark with semantic decoupling', async () => {
    const css = await readFile(new URL('../../resources/css/app.css', import.meta.url), 'utf8');
    const body = selector => {
        const index = css.indexOf(selector + ' {');
        assert.notEqual(index, -1, 'selector ' + selector + ' exists in app.css');
        return css.slice(index, css.indexOf('}', index));
    };
    const value = (text, token) => text.match(new RegExp(token + ':\\s*(#[0-9a-fA-F]{6})'))?.[1];
    const luminance = hex => {
        assert.ok(hex, 'token has an explicit server-owned palette value');
        return [1, 3, 5].map(index => parseInt(hex.slice(index, index + 2), 16) / 255)
            .map(c => c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4)
            .reduce((sum, c, i) => sum + c * [0.2126, 0.7152, 0.0722][i], 0);
    };
    const ratio = (a, b) => (Math.max(luminance(a), luminance(b)) + 0.05) / (Math.min(luminance(a), luminance(b)) + 0.05);

    const lightSurface = value(body(':root'), '--md-surface');
    const darkSurface = value(body(':root[data-theme="dark"]'), '--md-surface');
    const nonBrandPresets = ['slate', 'indigo', 'teal', 'violet'];
    for (const preset of nonBrandPresets) {
        for (const [selector, surface] of [
            [':root[data-accent="' + preset + '"]', lightSurface],
            [':root[data-theme="dark"][data-accent="' + preset + '"]', darkSurface],
        ]) {
            const tokens = body(selector);
            const primary = value(tokens, '--md-primary');
            const onPrimary = value(tokens, '--md-on-primary');
            const hover = value(tokens, '--ui-primary-hover');
            const text = value(tokens, '--ui-primary-text');

            // Text contrast on primary button: >= 4.5:1
            assert.ok(ratio(primary, onPrimary) >= 4.5, preset + ' ' + selector + ' primary button contrast: ' + ratio(primary, onPrimary));
            // Hover contrast on primary button: >= 4.5:1
            assert.ok(ratio(hover, onPrimary) >= 4.5, preset + ' ' + selector + ' hover contrast: ' + ratio(hover, onPrimary));
            // Text contrast on surface: >= 4.5:1
            assert.ok(ratio(text, surface) >= 4.5, preset + ' ' + selector + ' text on surface: ' + ratio(text, surface));
            // Non-text contrast against surface: >= 3:1
            assert.ok(ratio(primary, surface) >= 3.0, preset + ' ' + selector + ' non-text contrast: ' + ratio(primary, surface));

            // Semantic protection: no semantic status tokens may be redefined in accent
            assert.doesNotMatch(tokens, /--md-(?:error|warning|success):/, preset + ' preserves semantic status tokens');
        }

        // Info color decoupling: in light mode, info remains bound to ADASI Blue #1F5FA6
        const lightTokens = body(':root[data-accent="' + preset + '"]');
        assert.equal(value(lightTokens, '--md-info'), '#1F5FA6', preset + ' preserves decoupled --md-info #1F5FA6');
    }
});

test('all five accent swatches are statically defined with exact palette colors', async () => {
    const css = await readFile(new URL('../../resources/css/app.css', import.meta.url), 'utf8');
    const expected = {
        brand: '#1F5FA6',
        slate: '#475569',
        indigo: '#4338CA',
        teal: '#0D6B63',
        violet: '#6D28D9',
    };
    for (const [key, color] of Object.entries(expected)) {
        const pattern = new RegExp('\\[data-accent-swatch="' + key + '"\\]\\s*\\{\\s*background-color:\\s*' + color + ';\\s*\\}');
        assert.match(css, pattern, 'swatch ' + key + ' is ' + color);
    }
});

test('preview layer accepts all five approved accents and rejects unapproved keys or raw styles', () => {
    const accentKeys = ['brand', 'slate', 'indigo', 'teal', 'violet'];
    const state = boot({ theme: 'light', density: 'comfortable', accent: 'brand', accentKeys, sidebarState: 'expanded', pageSize: 25, sidebarRevision: '8:2', accountId: '41' });

    assert.equal(state.root.dataset.accent, 'brand');

    for (const key of accentKeys) {
        state.windowRef.AdasiPreferences.previewAccent(key);
        assert.equal(state.root.dataset.accent, key, 'previewed accent becomes ' + key);
    }

    // Rejection of invalid / unapproved candidates
    const rejected = ['amber', 'yellow', 'rose', 'red', '#123456', 'rgb(0,0,0)', 'color: red', '<script>'];
    for (const invalid of rejected) {
        state.windowRef.AdasiPreferences.previewAccent(invalid);
        assert.equal(state.root.dataset.accent, 'violet', 'rejected candidate ' + invalid + ' did not change accent');
    }

    // Restore saved resets back to initial persisted accent
    state.windowRef.AdasiPreferences.restoreSaved();
    assert.equal(state.root.dataset.accent, 'brand', 'restoreSaved returned to brand');
});

test('dark primary hover tokens are explicitly defined for indigo, teal, and violet', async () => {
    const css = await readFile(new URL('../../resources/css/app.css', import.meta.url), 'utf8');
    for (const [accent, hoverHex] of [['indigo', '#C7D2FE'], ['teal', '#99F6E4'], ['violet', '#DDD6FE']]) {
        const blockStart = css.indexOf(`:root[data-theme="dark"][data-accent="${accent}"] {`);
        assert.notEqual(blockStart, -1, `dark ${accent} block exists`);
        assert.match(css.slice(blockStart, css.indexOf('}', blockStart)), new RegExp(`--ui-primary-hover:\\s*${hoverHex};`));
    }
});

test('shellStyle defaults to classic, supports slate live preview, rejects invalid keys, and restores saved', () => {
    const fallbackState = boot({ theme: 'light', density: 'comfortable', sidebarState: 'expanded', pageSize: 25, sidebarRevision: '1:1', accountId: '41' });
    assert.equal(fallbackState.root.dataset.shell, 'classic');

    const state = boot({
        theme: 'light',
        shellStyle: 'slate',
        shellStyleKeys: ['classic', 'slate'],
        density: 'comfortable',
        sidebarState: 'expanded',
        pageSize: 25,
        sidebarRevision: '1:1',
        accountId: '41',
    });
    assert.equal(state.root.dataset.shell, 'slate');
    assert.equal(state.windowRef.AdasiPreferences.shellStyle, 'slate');

    state.windowRef.AdasiPreferences.previewShellStyle('classic');
    assert.equal(state.root.dataset.shell, 'classic');

    state.windowRef.AdasiPreferences.previewShellStyle('midnight-black');
    assert.equal(state.root.dataset.shell, 'classic', 'unknown shell style candidate is ignored');

    state.windowRef.AdasiPreferences.restoreSaved();
    assert.equal(state.root.dataset.shell, 'slate');
});

test('dark slate shell tokens meet WCAG 2.2 AA contrast without halation glare across topbar, sidebar, role badges, env tags, dark mode, and all five accents', async () => {
    const css = await readFile(new URL('../../resources/css/app.css', import.meta.url), 'utf8');
    const body = selector => {
        const index = css.indexOf(selector + ' {');
        assert.notEqual(index, -1, 'selector ' + selector + ' exists in app.css');
        return css.slice(index, css.indexOf('}', index));
    };
    const value = (text, token) => text.match(new RegExp(token + ':\\s*(#[0-9a-fA-F]{6})'))?.[1];
    const hexToRgb = hex => [1, 3, 5].map(index => parseInt(hex.slice(index, index + 2), 16));
    const blend = (fg, bg, alpha) => {
        const a = hexToRgb(fg);
        const b = hexToRgb(bg);
        return '#' + a.map((c, i) => Math.round(c * alpha + b[i] * (1 - alpha)).toString(16).padStart(2, '0')).join('');
    };
    const luminance = hex => {
        assert.ok(hex, 'shell token has an explicit hex value');
        return hexToRgb(hex)
            .map(c => c / 255)
            .map(c => c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4)
            .reduce((sum, c, i) => sum + c * [0.2126, 0.7152, 0.0722][i], 0);
    };
    const ratio = (a, b) => (Math.max(luminance(a), luminance(b)) + 0.05) / (Math.min(luminance(a), luminance(b)) + 0.05);

    const slate = body(':root[data-shell="slate"]');
    const darkSlate = body(':root[data-theme="dark"][data-shell="slate"]');
    const resolve = (block, token) => value(block, token) || value(slate, token);

    for (const [modeName, block] of [['light+slate', slate], ['dark+slate', darkSlate]]) {
        const topbarBg = resolve(block, '--ui-shell-topbar-bg');
        const topbarCtrlBg = resolve(block, '--ui-shell-topbar-control-bg');
        const topbarPillActiveBg = resolve(block, '--ui-shell-topbar-pill-active-bg');
        const sidebarBg = resolve(block, '--ui-shell-sidebar-bg');
        const sidebarCtrlBg = resolve(block, '--ui-shell-sidebar-control-bg');
        const sidebarActiveBg = resolve(block, '--ui-shell-sidebar-active-bg');

        const topbarFg = resolve(block, '--ui-shell-topbar-fg');
        const topbarMuted = resolve(block, '--ui-shell-topbar-fg-muted');
        const sidebarFg = resolve(block, '--ui-shell-sidebar-fg');
        const sidebarMuted = resolve(block, '--ui-shell-sidebar-fg-muted');
        const sidebarActiveFg = resolve(block, '--ui-shell-sidebar-active-fg');

        assert.notEqual(topbarFg, topbarMuted, `${modeName} topbar muted text must be distinct from primary text`);
        assert.notEqual(sidebarFg, sidebarMuted, `${modeName} sidebar muted text must be distinct from primary text`);

        const topbarPrimaryRatio = ratio(topbarFg, topbarBg);
        const sidebarPrimaryRatio = ratio(sidebarFg, sidebarBg);
        const sidebarActiveRatio = ratio(sidebarActiveFg, sidebarActiveBg);

        assert.ok(topbarPrimaryRatio >= 9.0 && topbarPrimaryRatio < 18.0, `${modeName} topbar primary text stays AA compliant without harsh pure-black halation`);
        assert.ok(ratio(topbarMuted, topbarBg) >= 4.5, `${modeName} topbar muted text >= 4.5:1`);
        assert.ok(ratio(topbarMuted, topbarCtrlBg) >= 4.5, `${modeName} topbar muted text on control bg >= 4.5:1`);
        assert.ok(ratio(resolve(block, '--ui-shell-topbar-control-border'), topbarBg) >= 3.0, `${modeName} topbar control border >= 3:1`);
        assert.ok(ratio(resolve(block, '--ui-shell-topbar-control-border'), topbarPillActiveBg) >= 3.0, `${modeName} topbar control border on active pill >= 3:1`);
        assert.ok(ratio(resolve(block, '--ui-shell-topbar-pill-active-fg'), topbarPillActiveBg) >= 4.5, `${modeName} active lang pill >= 4.5:1`);
        assert.ok(ratio(resolve(block, '--ui-shell-topbar-avatar-fg'), resolve(block, '--ui-shell-topbar-avatar-bg')) >= 4.5, `${modeName} topbar avatar >= 4.5:1`);

        assert.ok(sidebarPrimaryRatio >= 9.0 && sidebarPrimaryRatio < 18.0, `${modeName} sidebar item text stays AA compliant without harsh pure-black halation`);
        assert.ok(ratio(sidebarMuted, sidebarBg) >= 4.5, `${modeName} sidebar headings & disabled >= 4.5:1`);
        assert.ok(ratio(sidebarMuted, sidebarCtrlBg) >= 4.5, `${modeName} sidebar search placeholder on control bg >= 4.5:1`);
        assert.ok(sidebarActiveRatio >= 9.0 && sidebarActiveRatio < 18.0, `${modeName} sidebar active text stays AA compliant without harsh pure-black halation`);
        assert.ok(ratio(resolve(block, '--ui-shell-sidebar-control-border'), sidebarBg) >= 3.0, `${modeName} sidebar control border >= 3:1`);
        assert.ok(ratio(resolve(block, '--ui-shell-sidebar-control-border'), sidebarCtrlBg) >= 3.0, `${modeName} sidebar control border on control bg >= 3:1`);

        for (const role of ['default', 'purchasing', 'qc']) {
            assert.ok(ratio(resolve(block, `--ui-shell-role-${role}-fg`), resolve(block, `--ui-shell-role-${role}-bg`)) >= 4.5, `${modeName} ${role} role badge >= 4.5:1`);
            assert.ok(ratio(resolve(block, `--ui-shell-role-${role}-border`), topbarBg) >= 3.0, `${modeName} ${role} role badge border >= 3:1`);
        }

        for (const tone of ['neutral', 'info', 'warning']) {
            assert.ok(ratio(resolve(block, `--ui-shell-env-${tone}-fg`), resolve(block, `--ui-shell-env-${tone}-bg`)) >= 4.5, `${modeName} env ${tone} tag text >= 4.5:1`);
            assert.ok(ratio(resolve(block, `--ui-shell-env-${tone}-border`), topbarBg) >= 3.0, `${modeName} env ${tone} tag border >= 3:1`);
        }

        const brandAccent = value(slate, '--ui-shell-accent');
        const brandFocus = value(slate, '--ui-shell-focus');
        assert.ok(ratio(brandAccent, sidebarActiveBg) >= 4.5, `${modeName} brand shell accent on active bg >= 4.5:1`);
        assert.ok(ratio(brandAccent, blend(brandAccent, sidebarActiveBg, 0.16)) >= 4.5, `${modeName} brand portal switcher icon badge >= 4.5:1`);
        assert.ok(ratio(brandFocus, topbarBg) >= 3.0, `${modeName} brand shell focus ring >= 3:1`);

        for (const accent of ['slate', 'indigo', 'teal', 'violet']) {
            const accentBlock = body(`:root[data-shell="slate"][data-accent="${accent}"]`);
            const accentColor = value(accentBlock, '--ui-shell-accent');
            assert.ok(ratio(accentColor, sidebarActiveBg) >= 4.5, `${modeName} ${accent} shell accent >= 4.5:1`);
            assert.ok(ratio(accentColor, blend(accentColor, sidebarActiveBg, 0.16)) >= 4.5, `${modeName} ${accent} portal switcher icon badge >= 4.5:1`);
            assert.ok(ratio(value(accentBlock, '--ui-shell-focus'), topbarBg) >= 3.0, `${modeName} ${accent} shell focus >= 3:1`);
        }
    }
});
