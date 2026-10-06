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

function boot(payload, initialStorage = {}, storageAvailable = true) {
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
    assert.equal(state.root.dataset.bsTheme, 'light');
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
    for (const [selector, surface] of [[':root[data-accent="slate"]', '#FFFFFF'], [':root[data-theme="dark"][data-accent="slate"]', '#171F2C']]) {
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

test('slate Bootstrap hover uses a contrast-safe palette and legacy dark hover excludes slate', async () => {
    const css = await readFile(new URL('../../resources/css/app.css', import.meta.url), 'utf8');
    assert.ok(/:root\[data-theme="dark"\]:not\(\[data-accent="slate"\]\) \.btn-primary/.test(css), 'legacy dark hover excludes slate');
    const dark = css.slice(css.indexOf(':root[data-theme="dark"][data-accent="slate"] {'));
    assert.match(dark.slice(0, dark.indexOf('}')), /--ui-primary-hover: #C1CDDF/);
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
    assert.match(darkRoot, /--ui-primary-text:\s*#A8C9F0;/, 'brand dark primary text is #A8C9F0');
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

    const nonBrandPresets = ['slate', 'indigo', 'teal', 'violet'];
    for (const preset of nonBrandPresets) {
        for (const [selector, surface] of [
            [':root[data-accent="' + preset + '"]', '#FFFFFF'],
            [':root[data-theme="dark"][data-accent="' + preset + '"]', '#171F2C'],
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
        teal: '#0F766E',
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

test('dark button hover rules are explicitly defined for indigo, teal, and violet', async () => {
    const css = await readFile(new URL('../../resources/css/app.css', import.meta.url), 'utf8');
    assert.match(
        css,
        /:root\[data-theme="dark"\]\[data-accent="indigo"\] \.btn-primary,\s*:root\[data-theme="dark"\]\[data-accent="teal"\] \.btn-primary,\s*:root\[data-theme="dark"\]\[data-accent="violet"\] \.btn-primary/,
        'dark theme hover overrides are declared for new presets'
    );
});
