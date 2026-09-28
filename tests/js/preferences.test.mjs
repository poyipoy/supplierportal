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
