import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

// Chart accessibility contract (docs/audits/CHART-A11Y-BASELINE-20261102.md): series tokens that
// stay apart for color-vision deficiencies, non-color series cues, theme-time recolor, reduced
// motion, forced colors, keyboard readout and a text summary computed from the chart's own data.

const css = await readFile(new URL('../../resources/css/app.css', import.meta.url), 'utf8');
const darkStart = css.indexOf(':root[data-theme="dark"] {');
const lightBlock = css.slice(css.indexOf(':root {'), darkStart);
const darkBlock = css.slice(darkStart, css.indexOf('}', darkStart));
const token = (block, name) => block.match(new RegExp(name + ':\\s*(#[0-9a-fA-F]{6})'))?.[1];

const channel = (hex) => [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16) / 255);
const linear = (c) => (c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4);
const luminance = (hex) => channel(hex).map(linear).reduce((sum, c, i) => sum + c * [0.2126, 0.7152, 0.0722][i], 0);
const contrast = (a, b) => (Math.max(luminance(a), luminance(b)) + 0.05) / (Math.min(luminance(a), luminance(b)) + 0.05);

// Machado et al. (2009) severity-1 simulation in linear RGB, then OKLab; ΔE reported ×100.
const CVD = {
    protan: [[0.152286, 1.052583, -0.204868], [0.114503, 0.786281, 0.099216], [-0.003882, -0.048116, 1.051998]],
    deutan: [[0.367322, 0.860646, -0.227968], [0.280085, 0.672501, 0.047413], [-0.01182, 0.04294, 0.968881]],
    tritan: [[1.255528, -0.076749, -0.178779], [-0.078411, 0.930809, 0.147602], [0.004733, 0.691367, 0.3039]],
};
const oklab = ([r, g, b]) => {
    const l = Math.cbrt(0.4122214708 * r + 0.5363325363 * g + 0.0514459929 * b);
    const m = Math.cbrt(0.2119034982 * r + 0.6806995451 * g + 0.1073969566 * b);
    const s = Math.cbrt(0.0883024619 * r + 0.2817188376 * g + 0.6299787005 * b);
    return [0.2104542553 * l + 0.793617785 * m - 0.0040720468 * s, 1.9779984951 * l - 2.428592205 * m + 0.4505937099 * s, 0.0259040371 * l + 0.7827717662 * m - 0.808675766 * s];
};
const simulate = (hex, matrix) => {
    const rgb = channel(hex).map(linear);
    return matrix ? matrix.map((row) => Math.min(1, Math.max(0, row[0] * rgb[0] + row[1] * rgb[1] + row[2] * rgb[2]))) : rgb;
};
const deltaE = (a, b, matrix) => Math.hypot(...oklab(simulate(a, matrix)).map((v, i) => v - oklab(simulate(b, matrix))[i])) * 100;

const series = (block) => [1, 2, 3, 4, 5, 6].map((n) => token(block, '--md-chart-series-' + n));

test('series tokens are explicit, distinct, ≥ 3:1 on their surface, and separable under CVD (light and dark)', () => {
    for (const [mode, block, surface] of [['light', lightBlock, token(lightBlock, '--md-surface')], ['dark', darkBlock, token(darkBlock, '--md-surface')]]) {
        const values = series(block);
        values.forEach((hex, index) => {
            assert.ok(hex, mode + ' --md-chart-series-' + (index + 1) + ' has an explicit hex value');
            assert.ok(contrast(hex, surface) >= 3, mode + ' series ' + (index + 1) + ' ' + hex + ' needs 3:1 on ' + surface);
        });
        assert.equal(new Set(values.map((v) => v.toLowerCase())).size, 6, mode + ' series tokens are unique');
        for (let i = 0; i < values.length - 1; i++) {
            assert.ok(deltaE(values[i], values[i + 1]) >= 15, mode + ' adjacent slots ' + (i + 1) + '/' + (i + 2) + ' differ for full color vision');
            for (const [name, matrix] of Object.entries(CVD)) {
                assert.ok(deltaE(values[i], values[i + 1], matrix) >= 6, mode + ' ' + name + ' keeps slots ' + (i + 1) + '/' + (i + 2) + ' apart');
            }
        }
        for (const role of ['--md-chart-ok', '--md-chart-ng']) {
            assert.ok(contrast(token(block, role), surface) >= 3, mode + ' ' + role + ' needs 3:1 on the surface');
        }
    }
});

test('accent presets never redefine chart series tokens', () => {
    const accentBlocks = css.split(':root[data-').filter((chunk) => chunk.includes('accent='));
    accentBlocks.forEach((chunk) => assert.doesNotMatch(chunk.slice(0, chunk.indexOf('}')), /--md-chart-(series|ok|ng)/));
});

// ---- Module harness ---------------------------------------------------------------------------

function element(tag, attributes = {}) {
    const listeners = {};
    const node = {
        tagName: tag.toUpperCase(),
        attributes: { ...attributes },
        children: [],
        style: {},
        textContent: '',
        hidden: false,
        parentElement: null,
        getAttribute(name) { return name in this.attributes ? this.attributes[name] : null; },
        setAttribute(name, value) { this.attributes[name] = String(value); },
        hasAttribute(name) { return name in this.attributes; },
        removeAttribute(name) { delete this.attributes[name]; },
        addEventListener(name, fn) { (listeners[name] ||= []).push(fn); },
        removeEventListener(name, fn) { listeners[name] = (listeners[name] || []).filter((f) => f !== fn); },
        dispatch(name, event) { (listeners[name] || []).forEach((fn) => fn(event)); },
        appendChild(child) { child.parentElement = this; this.children.push(child); return child; },
        querySelector(selector) {
            const attr = selector.match(/^\[([a-z-]+)\]$/)?.[1];
            const walk = (n) => n.children.flatMap((c) => [c, ...walk(c)]);
            return walk(this).find((c) => attr && attr in c.attributes) || null;
        },
        closest(selector) {
            let n = this;
            while (n) { if (selector === 'figure' && n.tagName === 'FIGURE') return n; n = n.parentElement; }
            return null;
        },
        get classList() { return { add: () => {} }; },
    };
    return node;
}

async function loadTheme(tag, { vars = {}, media = {}, i18n = null } = {}) {
    const saved = { window: globalThis.window, document: globalThis.document, getComputedStyle: globalThis.getComputedStyle };
    globalThis.window = {
        addEventListener() {},
        matchMedia: (query) => ({ matches: Boolean(media[query]), addEventListener() {} }),
        AdasiPreferences: { displayNumber: (text) => text },
    };
    globalThis.document = {
        documentElement: {},
        readyState: 'complete',
        createElement: (name) => element(name),
        querySelector: (selector) => (selector === 'script[data-adasi-chart-i18n]' && i18n ? { textContent: JSON.stringify(i18n) } : null),
        body: element('body'),
    };
    globalThis.getComputedStyle = (node) => (node === globalThis.document.documentElement || !node
        ? { getPropertyValue: (name) => vars[name] || '' }
        : { color: 'rgb(255, 255, 255)', backgroundColor: 'rgb(0, 0, 0)', borderTopColor: 'rgb(128, 128, 128)', outlineColor: 'rgb(26, 235, 255)' });
    const module = await import('../../resources/js/chart-theme.js?test=' + tag);
    return { module, restore: () => Object.entries(saved).forEach(([k, v]) => (v === undefined ? delete globalThis[k] : (globalThis[k] = v))) };
}

const SERIES_VARS = Object.fromEntries([1, 2, 3, 4, 5, 6].map((n) => ['--md-chart-series-' + n, '#00000' + n]));

function fakeChart({ type = 'bar', labels = ['A', 'B', 'C'], datasets, options = {} }) {
    const figure = element('figure');
    const plot = figure.appendChild(element('div'));
    const canvas = plot.appendChild(element('canvas', { role: 'img' }));
    figure.appendChild(element('span', { 'data-adasi-chart-summary': 'auto' }));
    figure.appendChild(element('span', { 'data-adasi-chart-live': '' }));
    const calls = { active: [], tooltip: [], updates: 0 };
    const chart = {
        canvas,
        config: { type, options: { plugins: {}, ...options } },
        data: { labels, datasets },
        options: { plugins: {} },
        isDatasetVisible: (i) => !datasets[i].hidden,
        getDatasetMeta: (i) => ({ data: datasets[i].data.map((_, index) => ({ x: index * 10 + i, y: 5 })) }),
        setActiveElements: (els) => calls.active.push(els),
        tooltip: { setActiveElements: (els, pos) => calls.tooltip.push([els, pos]) },
        update: () => { calls.updates++; },
    };
    return { chart, canvas, figure, calls };
}

test('palette, series style and fills give every series a non-color cue', async () => {
    const { module, restore } = await loadTheme('style', { vars: SERIES_VARS });
    try {
        const colors = module.resolveChartThemeColors();
        assert.deepEqual(module.getSeriesPalette(colors), Object.values(SERIES_VARS));
        const styles = [0, 1, 2, 3, 4, 5].map((i) => module.getSeriesStyle(i, colors));
        assert.equal(new Set(styles.map((s) => s.color)).size, 6);
        assert.equal(new Set(styles.map((s) => s.pointStyle)).size, 6, 'six distinct point shapes');
        assert.equal(new Set(styles.map((s) => JSON.stringify(s.borderDash))).size, 6, 'six distinct line dashes');
        assert.ok(styles.every((s) => s.hatched === false), 'the first six series are solid');
        const seventh = module.getSeriesStyle(6, colors);
        assert.equal(seventh.color, styles[0].color, 'a 7th series reuses a hue, never a generated one');
        assert.equal(seventh.hatched, true, '...and is hatched so it stays distinct from series 1');
        assert.notDeepEqual(seventh.borderDash, styles[0].borderDash);
    } finally {
        restore();
    }
});

test('theme colors are read at render time, so a theme switch recolors datasets', async () => {
    const vars = { ...SERIES_VARS, '--md-chart-ng': '#B12B21', '--md-surface': '#FFFFFF' };
    const { module, restore } = await loadTheme('recolor', { vars });
    try {
        const ng = module.themeColor('ng');
        const ring = module.themeColor('surface');
        assert.equal(ng(), '#B12B21');
        vars['--md-chart-ng'] = '#FFB4AB';
        vars['--md-surface'] = '#171F2C';
        assert.equal(ng(), '#B12B21', 'cached until the theme changes');
        module.refreshChartTheme({ defaults: { font: {}, plugins: { tooltip: {} } }, instances: {} });
        assert.equal(ng(), '#FFB4AB');
        assert.equal(ring(), '#171F2C', 'point rings follow the surface');
        assert.equal(module.themeColor('series-2', 0.5)(), 'rgba(0, 0, 2, 0.5)');
    } finally {
        restore();
    }
});

test('forced colors swap marks to system colors and hatch every series after the first', async () => {
    const { module, restore } = await loadTheme('forced', { vars: SERIES_VARS, media: { '(forced-colors: active)': true } });
    try {
        const colors = module.resolveChartThemeColors();
        assert.equal(colors.forced, true);
        assert.ok(colors.series.every((c) => c === 'rgb(255, 255, 255)'), 'series use CanvasText');
        assert.equal(colors.surface, 'rgb(0, 0, 0)');
        assert.equal(colors.onSurfaceVariant, 'rgb(255, 255, 255)');
        assert.equal(module.getSeriesStyle(0, colors).hatched, false);
        assert.equal(module.getSeriesStyle(1, colors).hatched, true);
    } finally {
        restore();
    }
});

test('reduced motion disables animation before every update, including per-chart durations', async () => {
    for (const reduce of [true, false]) {
        const { module, restore } = await loadTheme('motion-' + reduce, { media: { '(prefers-reduced-motion: reduce)': reduce } });
        try {
            const chart = { config: { options: { animation: { duration: 400 } } } };
            module.a11yPlugin.beforeUpdate(chart);
            assert.deepEqual(chart.config.options.animation, reduce ? false : { duration: 400 });
        } finally {
            restore();
        }
    }
});

test('keyboard readout: focusable canvas, arrows skip empty points, Home/End, Esc clears; live text is plain text', async () => {
    const i18n = { point: ':label: :values', series_value: ':series :value', no_value: 'no data' };
    const { module, restore } = await loadTheme('keyboard', { i18n });
    try {
        const { chart, canvas, figure, calls } = fakeChart({
            datasets: [{ label: 'OK', data: [1, null, 3] }, { label: 'NG <b>', data: [2, null, null] }],
        });
        module.attachChartA11y(chart);
        module.attachChartA11y(chart);
        assert.equal(canvas.getAttribute('tabindex'), '0');
        const live = figure.querySelector('[data-adasi-chart-live]');
        const press = (key) => {
            let prevented = false;
            canvas.dispatch('keydown', { key, preventDefault: () => { prevented = true; } });
            return prevented;
        };

        assert.equal(press('ArrowRight'), true);
        assert.deepEqual(calls.active.at(-1), [{ datasetIndex: 0, index: 0 }, { datasetIndex: 1, index: 0 }]);
        assert.equal(live.textContent, 'A: OK 1; NG <b> 2', 'labels are inserted as text, never HTML');
        press('ArrowRight');
        assert.deepEqual(calls.active.at(-1), [{ datasetIndex: 0, index: 2 }], 'the all-empty point B is skipped');
        assert.equal(live.textContent, 'C: OK 3; NG <b> no data');
        press('Home');
        assert.equal(calls.active.at(-1)[0].index, 0);
        press('End');
        assert.equal(calls.active.at(-1)[0].index, 2);
        assert.deepEqual(calls.tooltip.at(-1)[1], { x: 20, y: 5 }, 'the tooltip anchors on the active point');
        press('Escape');
        assert.deepEqual(calls.active.at(-1), [], 'Esc dismisses the readout (WCAG 1.4.13)');
        assert.equal(press('Tab'), false, 'other keys are left to the browser and assistive technology');
        assert.equal(calls.updates > 0, true);
    } finally {
        restore();
    }
});

test('linear-axis series are read point by point in x order', async () => {
    const { module, restore } = await loadTheme('linear');
    try {
        const { chart, figure } = fakeChart({
            type: 'line',
            labels: undefined,
            datasets: [{ label: 'S-A', data: [{ x: 30, y: 2 }] }, { label: 'S-B', data: [{ x: 10, y: 1 }, { x: 20, y: null }] }],
            options: { plugins: { adasiA11y: { formatLabel: (x) => 'day ' + x } } },
        });
        delete chart.data.labels;
        module.attachChartA11y(chart);
        chart.canvas.dispatch('keydown', { key: 'ArrowRight', preventDefault() {} });
        assert.equal(figure.querySelector('[data-adasi-chart-live]').textContent, 'day 10: S-B 1');
        chart.canvas.dispatch('keydown', { key: 'ArrowRight', preventDefault() {} });
        assert.equal(figure.querySelector('[data-adasi-chart-live]').textContent, 'day 30: S-A 2', 'the null point is skipped');
    } finally {
        restore();
    }
});

test('the text summary is computed from the same data the chart draws', async () => {
    const { module, restore } = await loadTheme('summary');
    try {
        const series = fakeChart({ labels: ['Jan', 'Feb', 'Mar'], datasets: [{ label: 'NG', data: [4, 9, 2] }, { label: 'Hidden', hidden: true, data: [99, 99, 99] }] }).chart;
        assert.equal(module.describeChart(series), 'NG: highest 9 (Feb), lowest 2 (Mar), latest 2 (Mar).');

        const raw = fakeChart({ labels: ['PO :min', 'PO :max'], datasets: [{ label: 'S :series', data: [1, 2] }] }).chart;
        assert.equal(module.describeChart(raw), 'S :series: highest 2 (PO :max), lowest 1 (PO :min), latest 2 (PO :max).', 'business text is never treated as a placeholder');

        const one = fakeChart({ labels: ['2026'], datasets: [{ label: 'Avg', data: [null] }, { label: 'Min', data: [5] }] }).chart;
        assert.equal(module.describeChart(one), 'Min: 5 (2026).');

        const parts = fakeChart({ type: 'doughnut', labels: ['Active', 'Done'], datasets: [{ data: [3, 1] }] }).chart;
        assert.equal(module.describeChart(parts), 'Total 4. Largest: Active 3 (75%).');

        const bars = fakeChart({ labels: ['Active', 'Done'], datasets: [{ label: 'PO', data: [1, 3] }], options: { plugins: { adasiA11y: { summary: 'distribution' } } } }).chart;
        assert.equal(module.describeChart(bars), 'Total 4. Largest: Done 3 (75%).');

        const empty = fakeChart({ datasets: [{ label: 'X', data: [null, null, null] }] }).chart;
        assert.equal(module.describeChart(empty), 'No data to display.');

        const { chart, figure } = fakeChart({ labels: ['Jan', 'Feb'], datasets: [{ label: 'PR', data: [1, 2] }] });
        module.a11yPlugin.afterUpdate(chart);
        assert.equal(figure.querySelector('[data-adasi-chart-summary]').textContent, 'PR: highest 2 (Feb), lowest 1 (Jan), latest 2 (Feb).');
    } finally {
        restore();
    }
});

test('a server-written summary is never overwritten', async () => {
    const { module, restore } = await loadTheme('server-summary');
    try {
        const { chart, figure } = fakeChart({ datasets: [{ label: 'PR', data: [1, 2, 3] }] });
        const target = figure.querySelector('[data-adasi-chart-summary]');
        target.setAttribute('data-adasi-chart-summary', 'server');
        target.textContent = 'Written by Blade';
        module.a11yPlugin.afterUpdate(chart);
        assert.equal(target.textContent, 'Written by Blade');
    } finally {
        restore();
    }
});

test('charts rendered by fetch use the same accessible frame as <x-ui.chart>', async () => {
    const scripts = await readFile(new URL('../../resources/views/purchasing/comparison/_scripts.blade.php', import.meta.url), 'utf8');
    const component = await readFile(new URL('../../resources/views/components/ui/chart.blade.php', import.meta.url), 'utf8');
    const template = scripts.slice(scripts.indexOf('<figure class="ui-chart'), scripts.indexOf('</figure>', scripts.indexOf('<figure class="ui-chart')));

    for (const marker of ['data-adasi-chart', 'tabindex="0"', 'aria-describedby="historicalChart-summary historicalChart-hint"', 'data-adasi-chart-live', 'data-adasi-chart-summary="auto"', "__('charts.keyboard_hint')"]) {
        assert.ok(template.includes(marker), 'historical template keeps ' + marker);
    }
    assert.match(scripts, /@once\('adasi-chart-i18n'\)/, 'chart copy is on the page before the first fetch render');
    assert.match(component, /@once\('adasi-chart-i18n'\)/, 'the component shares the once-key, so the copy is printed once');
    assert.doesNotMatch(scripts.slice(scripts.indexOf('function historicalChartConfig')), /yAxisID|y1:/, 'no dual-axis historical chart');
});
