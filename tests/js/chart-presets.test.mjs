import test from 'node:test';
import assert from 'node:assert/strict';

const SERIES_VARS = Object.fromEntries([1, 2, 3, 4, 5, 6].map((n) => ['--md-chart-series-' + n, '#00000' + n]));

async function loadThemeModule(tag, { vars = {}, media = {}, i18n = null, displayNumber = null, Chart = null } = {}) {
    const saved = { window: globalThis.window, document: globalThis.document, getComputedStyle: globalThis.getComputedStyle };
    globalThis.window = {
        addEventListener() {},
        matchMedia: (query) => ({ matches: Boolean(media[query]), addEventListener() {} }),
        ...(displayNumber ? { AdasiPreferences: { displayNumber } } : {}),
        ...(Chart ? { Chart } : {}),
    };
    globalThis.document = {
        documentElement: {},
        readyState: 'complete',
        createElement: () => ({ style: {}, remove() {} }),
        querySelector: (selector) => (selector === 'script[data-adasi-chart-i18n]' && i18n ? { textContent: JSON.stringify(i18n) } : null),
        body: { appendChild() {} },
    };
    globalThis.getComputedStyle = (node) => (node === globalThis.document.documentElement || !node
        ? { getPropertyValue: (name) => vars[name] || '' }
        : { color: 'rgb(255, 255, 255)', backgroundColor: 'rgb(0, 0, 0)', borderTopColor: 'rgb(128, 128, 128)', outlineColor: 'rgb(26, 235, 255)' });
    const mod = await import('../../resources/js/chart-theme.js?presets-test=' + tag + '-' + Date.now());
    return {
        ...mod,
        restore: () => Object.entries(saved).forEach(([k, v]) => (v === undefined ? delete globalThis[k] : (globalThis[k] = v))),
    };
}

test('barDataset applies modern bar defaults and dims non-active categories unless disabled or forced-colors', async () => {
    const { barDataset, BAR_STYLE, restore } = await loadThemeModule('bar-dim', { vars: SERIES_VARS });
    try {
        const ds = barDataset({ index: 0 });
        assert.equal(ds.borderRadius, BAR_STYLE.borderRadius);
        assert.equal(ds.borderSkipped, 'start');
        assert.equal(ds.maxBarThickness, 32);
        assert.equal(ds.adasiDim, true);
        assert.equal(typeof ds.backgroundColor, 'function');
        assert.equal(typeof ds.borderColor, 'function');
        assert.equal(ds.borderColor(), '#000001');

        // Resting state (no active index): solid color
        assert.equal(ds.backgroundColor({ type: 'data', dataIndex: 1, chart: { $adasiActiveIndex: null } }), '#000001');
        // Active category stays solid; other categories dim to 35%
        assert.equal(ds.backgroundColor({ type: 'data', dataIndex: 1, chart: { $adasiActiveIndex: 1 } }), '#000001');
        assert.equal(ds.backgroundColor({ type: 'data', dataIndex: 2, chart: { $adasiActiveIndex: 1 } }), 'rgba(0, 0, 1, 0.35)');
        // Opt-in per-bar dimMode dims sibling datasets in the same category too
        const perBarDs = barDataset({ index: 0, dimMode: 'bar' });
        assert.equal(perBarDs.adasiDimMode, 'bar');
        assert.equal(perBarDs.backgroundColor({ type: 'data', datasetIndex: 0, dataIndex: 1, chart: { $adasiActiveIndex: 1, $adasiActiveDatasetIndex: 0 } }), '#000001');
        assert.equal(perBarDs.backgroundColor({ type: 'data', datasetIndex: 1, dataIndex: 1, chart: { $adasiActiveIndex: 1, $adasiActiveDatasetIndex: 0 } }), 'rgba(0, 0, 1, 0.35)');
        // Non-data scriptable calls stay solid
        assert.equal(ds.backgroundColor({ type: 'dataset', dataIndex: 2, chart: { $adasiActiveIndex: 1 } }), '#000001');

        // Explicit dim: false returns non-dimming base fill
        const staticDs = barDataset({ index: 0, color: '#1F5FA6', dim: false });
        assert.equal(staticDs.backgroundColor, '#1F5FA6');
        assert.equal(staticDs.adasiDim, false);

        // Non-string pattern fills (e.g. hatched 7th+ series) are passed through without dimming
        const patternObj = { kind: 'CanvasPattern' };
        const hatchedDs = barDataset({ index: 6, color: '#000001', fill: patternObj });
        assert.equal(hatchedDs.backgroundColor({ type: 'data', dataIndex: 2, chart: { $adasiActiveIndex: 1 } }), patternObj);
    } finally {
        restore();
    }

    // Forced-colors mode never dims bars
    const forced = await loadThemeModule('bar-forced', { vars: SERIES_VARS, media: { '(forced-colors: active)': true } });
    try {
        const ds = forced.barDataset({ index: 0 });
        assert.equal(ds.backgroundColor({ type: 'data', dataIndex: 2, chart: { $adasiActiveIndex: 1 } }), 'rgb(255, 255, 255)');
    } finally {
        forced.restore();
    }
});

test('lineDataset distinguishes multi vs single series resting points and flat 8% area fill', async () => {
    const { lineDataset, getSeriesStyle, restore } = await loadThemeModule('line-preset', {
        vars: { ...SERIES_VARS, '--md-surface': '#FFFFFF' },
    });
    try {
        // Multi-series: resting radius 3, non-color dash + pointStyle from series style, no fill
        const multiDs = lineDataset({ index: 1, multi: true });
        const expectedStyle = getSeriesStyle(1);
        assert.equal(multiDs.tension, 0);
        assert.equal(multiDs.borderCapStyle, 'round');
        assert.equal(multiDs.borderJoinStyle, 'round');
        assert.equal(multiDs.fill, false);
        assert.equal(multiDs.pointStyle, expectedStyle.pointStyle);
        assert.deepEqual(multiDs.borderDash, expectedStyle.borderDash);
        assert.equal(multiDs.pointRadius({ type: 'data', dataIndex: 1, dataset: { data: [10, 20, 30, 40] } }), 3);
        assert.equal(multiDs.pointRadius({ type: 'data', dataIndex: 1, dataset: { data: [10, null, 30] } }), 0);

        // Single-series with >= 3 plotted points: resting radius 0, isolated points stay visible (3)
        const singleDs = lineDataset({ index: 0, multi: false, area: true });
        assert.equal(singleDs.fill, 'start');
        assert.deepEqual(singleDs.borderDash, []);
        assert.equal(singleDs.backgroundColor({}), 'rgba(0, 0, 1, 0.08)');
        assert.equal(singleDs.pointBackgroundColor({}), '#000001');
        assert.equal(singleDs.pointBorderColor({}), '#FFFFFF');

        assert.equal(singleDs.pointRadius({ type: 'data', dataIndex: 1, dataset: { data: [10, 20, 30] } }), 0);
        assert.equal(singleDs.pointRadius({ type: 'data', dataIndex: 1, dataset: { data: [null, 20, null, 40, 50] } }), 3, 'isolated point flanked by nulls stays visible');
        assert.equal(singleDs.pointRadius({ type: 'data', dataIndex: 0, dataset: { data: [10, 20] } }), 4, 'fewer than 3 plotted points stay visible');

        // Opt-in hover spotlight (dim: true) dims inactive line series on dataset & data contexts while keeping active series vivid
        const dimLineDs = lineDataset({ index: 0, multi: true, dim: true, borderWidth: 2, hoverBorderWidth: 3 });
        assert.equal(dimLineDs.adasiDim, true);
        assert.equal(dimLineDs.borderColor({ type: 'dataset', datasetIndex: 0, chart: { $adasiActiveDatasetIndex: null } }), '#000001');
        assert.equal(dimLineDs.borderColor({ type: 'dataset', datasetIndex: 0, chart: { $adasiActiveDatasetIndex: 0 } }), '#000001');
        assert.equal(dimLineDs.borderColor({ type: 'dataset', datasetIndex: 0, chart: { $adasiActiveDatasetIndex: 1 } }), 'rgba(0, 0, 1, 0.2)');
        assert.equal(dimLineDs.pointBackgroundColor({ type: 'data', datasetIndex: 0, dataIndex: 0, chart: { $adasiActiveDatasetIndex: 1 } }), 'rgba(0, 0, 1, 0.2)');
        assert.equal(dimLineDs.borderWidth({ datasetIndex: 0, chart: { $adasiActiveDatasetIndex: 0 } }), 3);
        assert.equal(dimLineDs.borderWidth({ datasetIndex: 0, chart: { $adasiActiveDatasetIndex: 1 } }), 2);
    } finally {
        restore();
    }

    // Forced-colors disables area tint and never dims spotlight lines
    const forced = await loadThemeModule('line-forced', { vars: SERIES_VARS, media: { '(forced-colors: active)': true } });
    try {
        const ds = forced.lineDataset({ index: 0, multi: false, area: true });
        assert.equal(ds.backgroundColor({}), 'transparent');
        const dimForced = forced.lineDataset({ index: 0, multi: true, dim: true });
        assert.equal(dimForced.borderColor({ type: 'dataset', datasetIndex: 0, chart: { $adasiActiveDatasetIndex: 1 } }), 'rgb(255, 255, 255)');
    } finally {
        forced.restore();
    }
});

test('getChartLegend aligns start and fills line swatches with solid stroke color', async () => {
    const fakeChartClass = {
        defaults: {
            font: {},
            plugins: {
                tooltip: {},
                legend: {
                    labels: {
                        generateLabels: (chart) => chart.data.datasets.map((ds, i) => ({
                            datasetIndex: i,
                            text: ds.label,
                            fillStyle: 'rgba(0, 0, 1, 0.08)',
                            strokeStyle: '#000001',
                            lineWidth: 1,
                            lineDash: [6, 4],
                            pointStyle: ds.pointStyle,
                        })),
                    },
                },
            },
        },
    };
    const { getChartLegend, restore } = await loadThemeModule('legend', { Chart: fakeChartClass });
    try {
        const legend = getChartLegend();
        assert.equal(legend.position, 'top');
        assert.equal(legend.align, 'start');
        assert.equal(legend.labels.boxWidth, 8);
        assert.equal(legend.labels.boxHeight, 8);
        assert.equal(legend.labels.padding, 20);

        const items = legend.labels.generateLabels({
            config: { type: 'line' },
            data: {
                datasets: [
                    { label: 'Avg', pointStyle: 'circle', borderColor: '#000001' },
                    { label: 'Max', pointStyle: 'star', borderColor: '#000002' },
                ],
            },
        });
        assert.equal(items[0].fillStyle, '#000001', 'line legend swatch uses solid stroke color, not area tint');
        assert.deepEqual(items[0].lineDash, []);
        assert.equal(items[1].lineWidth, 2, 'stroke-only point style gets visible lineWidth');

        // Line datasets with scriptable borderColor (and white pointBorderColor halo) resolve true series color, never white-on-white
        const fnLineItems = legend.labels.generateLabels({
            config: { type: 'line' },
            data: {
                datasets: [
                    {
                        label: '2026',
                        pointStyle: 'star',
                        borderColor: (ctx) => (ctx?.chart?.$adasiActiveDatasetIndex === 1 ? 'rgba(0, 0, 6, 0.2)' : '#6250D6'),
                        pointBorderColor: () => '#FFFFFF',
                    },
                ],
            },
        });
        // Simulate Chart.js returning white pointBorderColor as item.strokeStyle
        fakeChartClass.defaults.plugins.legend.labels.generateLabels = (chart) => chart.data.datasets.map((ds, i) => ({
            datasetIndex: i,
            text: ds.label,
            fillStyle: '#FFFFFF',
            strokeStyle: '#FFFFFF',
            lineWidth: 2,
            pointStyle: ds.pointStyle,
        }));
        const whiteBugItems = legend.labels.generateLabels({
            config: { type: 'line' },
            data: {
                datasets: [
                    {
                        label: '2026',
                        pointStyle: 'circle',
                        borderColor: () => '#6250D6',
                    },
                ],
            },
        });
        assert.equal(fnLineItems[0].fillStyle, '#6250D6');
        assert.equal(whiteBugItems[0].fillStyle, '#6250D6', 'line legend evaluates borderColor function instead of white pointBorderColor');
        assert.equal(whiteBugItems[0].strokeStyle, '#6250D6');

        // Dimmed bar datasets still render vivid undimmed legend swatches even while hovering another bar
        const barItems = legend.labels.generateLabels({
            config: { type: 'bar' },
            data: {
                datasets: [
                    {
                        label: '2023',
                        adasiDim: true,
                        backgroundColor: (ctx) => (ctx.type === 'data' ? 'rgba(0, 0, 1, 0.35)' : '#000001'),
                        borderColor: '#000001',
                    },
                ],
            },
        });
        assert.equal(barItems[0].fillStyle, '#000001', 'bar legend swatch stays undimmed during bar hover');
    } finally {
        restore();
    }
});

test('getChartTooltip uses surface tokens and merges custom callbacks with default labelColor', async () => {
    const { getChartTooltip, themeColor, restore } = await loadThemeModule('tooltip', {
        vars: {
            ...SERIES_VARS,
            '--ui-menu-bg': '#222B3A',
            '--md-on-surface': '#E7EDF6',
            '--md-on-surface-variant': '#B4C0D2',
            '--md-outline-variant': '#2E3A4D',
        },
    });
    try {
        const customLabel = () => 'custom-label';
        const tooltip = getChartTooltip({ callbacks: { label: customLabel } });
        assert.equal(tooltip.backgroundColor, '#222B3A');
        assert.equal(tooltip.titleColor, '#E7EDF6');
        assert.equal(tooltip.bodyColor, '#E7EDF6');
        assert.equal(tooltip.footerColor, '#B4C0D2');
        assert.equal(tooltip.borderColor, '#2E3A4D');
        assert.equal(tooltip.caretSize, 0);
        assert.equal(tooltip.callbacks.label, customLabel, 'page label callback is preserved');
        assert.equal(typeof tooltip.callbacks.labelColor, 'function', 'default labelColor is merged');

        const swatch = tooltip.callbacks.labelColor({ dataset: { borderColor: themeColor('series-2') }, dataIndex: 0 });
        assert.deepEqual(swatch, { borderColor: '#000002', backgroundColor: '#000002', borderWidth: 0, borderRadius: 2 });
    } finally {
        restore();
    }
});

test('formatCompact and compactTicks share one unit per axis and keep values below 1e6 uncompacted', async () => {
    // English fallback strings
    const en = await loadThemeModule('compact-en', {
        displayNumber: (text) => text.replace(/\./g, '_').replace(/,/g, '.').replace(/_/g, ','),
    });
    try {
        assert.equal(en.formatCompact(null), '-');
        assert.equal(en.formatCompact(0, 2_000_000_000), '0');
        assert.equal(en.formatCompact(500_000), '500,000');
        assert.equal(en.formatCompact(1_500_000), '1.5 M');
        assert.equal(en.formatCompact(2_400_000_000), '2.4 B');
        assert.equal(en.formatCompact(3_000_000_000_000), '3 T');

        const tick = en.compactTicks('Rp ');
        const scale = { max: 2_000_000_000, min: 0 };
        assert.equal(tick.call(scale, 0), 'Rp 0');
        assert.equal(tick.call(scale, 500_000_000), 'Rp 0.5 B', 'axis range in billions formats sub-billion tick in the same unit');
        assert.equal(tick.call({ max: 500_000, min: 0 }, 250_000), 'Rp 250,000');
    } finally {
        en.restore();
    }

    // Indonesian chart strings delivered by Blade
    const id = await loadThemeModule('compact-id', {
        i18n: { compact_million: ':value jt', compact_billion: ':value M', compact_trillion: ':value T' },
    });
    try {
        assert.equal(id.formatCompact(1_500_000), '1,5 jt');
        assert.equal(id.formatCompact(2_400_000_000), '2,4 M');
        assert.equal(id.formatCompact(2_000_000_000_000), '2 T');
    } finally {
        id.restore();
    }
});

test('refreshChartTheme preserves function grid.color and hoverFocus/tooltipShadow plugins behave safely', async () => {
    const vars = {
        '--md-outline': '#C8D0DA',
        '--md-chart-grid': 'rgba(200, 208, 218, 0.6)',
        '--ui-menu-bg': '#FFFFFF',
        '--md-on-surface': '#0F172A',
        '--md-outline-variant': '#E2E6EC',
        '--md-chart-tooltip-shadow': 'rgba(15, 23, 42, 0.12)',
    };
    const { getChartScales, refreshChartTheme, hoverFocusPlugin, tooltipShadowPlugin, restore } = await loadThemeModule('plugins', { vars });
    try {
        const scales = getChartScales();
        assert.equal(typeof scales.y.grid.color, 'function');
        assert.equal(scales.y.grid.color({ tick: { value: 0 } }), '#C8D0DA', 'zero baseline uses outline');
        assert.equal(scales.y.grid.color({ tick: { value: 100 } }), 'rgba(200, 208, 218, 0.6)');

        // Auto-zoom line scales enable x.offset, y.grace, non-zero baseline, and clamp negative min to 0
        const zoomedScales = getChartScales({ yBeginAtZero: false, yGrace: '12%', xOffset: true, xGrid: true });
        assert.equal(zoomedScales.x.offset, true);
        assert.equal(zoomedScales.x.grid.display, true);
        assert.equal(zoomedScales.y.beginAtZero, false);
        assert.equal(zoomedScales.y.grace, '12%');
        const negativeScale = { min: -5000, max: 120000 };
        zoomedScales.y.afterDataLimits(negativeScale);
        assert.equal(negativeScale.min, 0, 'negative grace floor clamps to 0 for prices');
        const positiveScale = { min: 65000, max: 135000 };
        zoomedScales.y.afterDataLimits(positiveScale);
        assert.equal(positiveScale.min, 65000, 'positive auto-zoom floor is preserved');

        const fnRef = scales.y.grid.color;
        const chart = {
            config: { type: 'bar' },
            data: { datasets: [{ adasiDim: true, data: [1, 2] }] },
            options: { scales, plugins: { tooltip: {} } },
            updates: [],
            active: [{ datasetIndex: 0, index: 1, element: { x: 42 } }],
            getActiveElements() { return this.active; },
            update(mode) { this.updates.push(mode); },
        };
        const Chart = { defaults: { font: {}, plugins: { tooltip: {} } }, instances: { chart } };
        refreshChartTheme(Chart);
        assert.equal(chart.options.scales.y.grid.color, fnRef, 'scriptable grid.color is preserved on theme refresh');
        assert.equal(chart.options.plugins.tooltip.backgroundColor, '#FFFFFF');
        assert.equal(chart.options.plugins.tooltip.borderColor, '#E2E6EC');

        // hoverFocusPlugin updates once when active index changes, skips replay
        chart.updates = [];
        chart.$adasiActiveIndex = null;
        hoverFocusPlugin.afterEvent(chart, { replay: true });
        assert.equal(chart.updates.length, 0);
        hoverFocusPlugin.afterEvent(chart, { replay: false });
        assert.deepEqual(chart.updates, ['none']);
        assert.equal(chart.$adasiActiveIndex, 1);
        hoverFocusPlugin.afterEvent(chart, { replay: false });
        assert.equal(chart.updates.length, 1, 'no redundant update when active index is unchanged');

        // Line crosshair draws only for line charts
        const strokes = [];
        const lineCtx = {
            save() {}, restore() {}, beginPath() {},
            moveTo(x, y) { strokes.push(['move', x, y]); },
            lineTo(x, y) { strokes.push(['line', x, y]); },
            stroke() { strokes.push(['stroke', this.strokeStyle]); },
        };
        hoverFocusPlugin.beforeDatasetsDraw({
            config: { type: 'line' },
            ctx: lineCtx,
            chartArea: { top: 10, bottom: 200 },
            getActiveElements: () => [{ datasetIndex: 0, index: 0, element: { x: 88 } }],
        });
        assert.deepEqual(strokes, [['move', 88, 10], ['line', 88, 200], ['stroke', '#C8D0DA']]);

        // tooltipShadowPlugin draws shadow rect when visible native tooltip is active
        let filled = false;
        const tipCtx = {
            save() {}, restore() {}, beginPath() {},
            roundRect(x, y, w, h, r) { this.rectArgs = [x, y, w, h, r]; },
            fill() { filled = true; },
        };
        tooltipShadowPlugin.beforeTooltipDraw({ ctx: tipCtx }, { tooltip: { opacity: 1, x: 10, y: 20, width: 120, height: 48 } });
        assert.equal(filled, true);
        assert.deepEqual(tipCtx.rectArgs, [10, 20, 120, 48, 8]);

        // tooltipShadowPlugin skips drawing when tooltip is disabled or rendered externally (prevents blank ghost box)
        filled = false;
        tooltipShadowPlugin.beforeTooltipDraw(
            { ctx: tipCtx, options: { plugins: { tooltip: { enabled: false, external: () => {} } } } },
            { tooltip: { opacity: 1, x: 10, y: 20, width: 120, height: 48, options: { enabled: false, external: () => {} } } },
        );
        assert.equal(filled, false, 'never paints a canvas ghost shadow box when external HTML tooltip is active');
    } finally {
        restore();
    }
});

test('applyGlobalChartDefaults and getChartTooltip preserve all Chart.js built-in tooltip callbacks and set index interaction defaults', async () => {
    const builtInNames = [
        'beforeTitle', 'title', 'afterTitle',
        'beforeBody', 'beforeLabel', 'label', 'labelColor', 'labelTextColor', 'labelPointStyle',
        'afterLabel', 'afterBody',
        'beforeFooter', 'footer', 'afterFooter',
    ];
    const nativeCallbacks = Object.fromEntries(
        builtInNames.map((name) => [name, () => `native:${name}`]),
    );
    const fakeChartClass = {
        defaults: {
            font: {},
            plugins: {
                tooltip: {
                    callbacks: { ...nativeCallbacks },
                },
            },
        },
    };
    const { applyGlobalChartDefaults, refreshChartTheme, getChartTooltip, themeColor, restore } = await loadThemeModule('tooltip-callbacks-preserved', {
        vars: {
            ...SERIES_VARS,
            '--ui-menu-bg': '#FFFFFF',
            '--md-on-surface': '#0F172A',
            '--md-on-surface-variant': '#475569',
            '--md-outline-variant': '#E2E6EC',
        },
        Chart: fakeChartClass,
    });
    try {
        // Call multiple times (load + font load / theme toggle) to verify built-ins are never lost.
        applyGlobalChartDefaults(fakeChartClass);
        refreshChartTheme(fakeChartClass);

        for (const name of builtInNames) {
            assert.equal(typeof fakeChartClass.defaults.plugins.tooltip.callbacks[name], 'function', `global default callback "${name}" must survive applyGlobalChartDefaults`);
        }
        assert.equal(fakeChartClass.defaults.plugins.tooltip.callbacks.beforeTitle(), 'native:beforeTitle');
        assert.equal(fakeChartClass.defaults.plugins.tooltip.callbacks.title(), 'native:title');
        assert.deepEqual(fakeChartClass.defaults.interaction, { mode: 'index', intersect: false });

        const customLabel = () => 'custom-label';
        const merged = getChartTooltip({ callbacks: { label: customLabel } });
        for (const name of builtInNames) {
            assert.equal(typeof merged.callbacks[name], 'function', `per-chart tooltip callback "${name}" must remain available`);
        }
        assert.equal(merged.callbacks.label, customLabel);
        assert.equal(merged.callbacks.beforeTitle(), 'native:beforeTitle');

        const starSwatch = merged.callbacks.labelColor({
            dataset: { pointStyle: 'star', borderColor: themeColor('series-6') },
            dataIndex: 0,
        });
        assert.equal(starSwatch.borderWidth, 2, 'stroke-only point styles keep visible stroke width in tooltip swatch');
    } finally {
        restore();
    }
});

test('legendSpacingPlugin adds bottom breathing room only to visible top legends without double-wrapping', async () => {
    const { legendSpacingPlugin, restore } = await loadThemeModule('legend-spacing');
    try {
        const topLegend = {
            position: 'top',
            options: { display: true },
            height: 0,
            fit() {
                this.height = 32;
            },
        };
        legendSpacingPlugin.beforeLayout({ legend: topLegend });
        legendSpacingPlugin.beforeLayout({ legend: topLegend });
        topLegend.fit();
        assert.equal(topLegend.height, 48, 'top legend gets +16px bottom gap exactly once');

        const hiddenLegend = {
            position: 'top',
            options: { display: false },
            height: 0,
            fit() {
                this.height = 0;
            },
        };
        legendSpacingPlugin.beforeLayout({ legend: hiddenLegend });
        hiddenLegend.fit();
        assert.equal(hiddenLegend.height, 0, 'hidden legend stays 0px');

        const bottomLegend = {
            position: 'bottom',
            options: { display: true },
            height: 0,
            fit() {
                this.height = 32;
            },
        };
        legendSpacingPlugin.beforeLayout({ legend: bottomLegend });
        bottomLegend.fit();
        assert.equal(bottomLegend.height, 32, 'bottom legend height is unchanged');
    } finally {
        restore();
    }
});
