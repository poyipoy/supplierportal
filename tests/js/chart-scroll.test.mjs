import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

async function loadThemeModule() {
    const saved = { window: globalThis.window, document: globalThis.document, getComputedStyle: globalThis.getComputedStyle };
    globalThis.window = {
        addEventListener() {},
        matchMedia: () => ({ matches: false, addEventListener() {} }),
    };
    globalThis.document = {
        documentElement: {},
        readyState: 'complete',
        createElement: () => ({ style: {} }),
        querySelector: () => null,
        body: {},
    };
    globalThis.getComputedStyle = () => ({ getPropertyValue: () => '' });
    const mod = await import('../../resources/js/chart-theme.js?scroll-test=' + Date.now());
    return {
        ...mod,
        restore: () => Object.entries(saved).forEach(([k, v]) => (v === undefined ? delete globalThis[k] : (globalThis[k] = v))),
    };
}

function createScrollDom({ clientWidth = 800 } = {}) {
    const plotEl = { style: { minWidth: '' } };
    const scrollEl = {
        clientWidth,
        scrollWidth: clientWidth,
        scrollLeft: 0,
    };
    const liveRegion = { textContent: '', setAttribute() {}, className: '' };
    const figure = {
        querySelector(selector) {
            if (selector === '[data-adasi-chart-live]') return liveRegion;
            return null;
        },
    };
    const listeners = {};
    const canvas = {
        attributes: {},
        parentElement: plotEl,
        hasAttribute(name) { return Object.hasOwn(this.attributes, name); },
        setAttribute(name, val) { this.attributes[name] = String(val); },
        addEventListener(type, fn) { (listeners[type] ||= []).push(fn); },
        removeEventListener(type, fn) {
            listeners[type] = (listeners[type] || []).filter((item) => item !== fn);
        },
        closest(selector) {
            if (selector === '[data-adasi-chart-plot]') return plotEl;
            if (selector === '[data-adasi-chart-scroll]') return scrollEl;
            if (selector === 'figure') return figure;
            return null;
        },
        dispatchKey(key) {
            const event = { key, prevented: false, preventDefault() { this.prevented = true; } };
            (listeners.keydown || []).forEach((fn) => fn(event));
            return event;
        },
    };
    return { canvas, plotEl, scrollEl, liveRegion };
}

test('fitChartScrollWidth expands plot minWidth only when categories exceed threshold and container width', async () => {
    const { fitChartScrollWidth, restore } = await loadThemeModule();
    try {
        const { canvas, plotEl, scrollEl } = createScrollDom({ clientWidth: 800 });

        assert.equal(fitChartScrollWidth(canvas, { categories: 6, series: 2 }), 0);
        assert.equal(plotEl.style.minWidth, '');

        // 14 materials x 2 suppliers -> max(96, 2*30+36) = 96px -> 1344px (> 800px)
        const width14 = fitChartScrollWidth(canvas, { categories: 14, series: 2 });
        assert.equal(width14, 1344);
        assert.equal(plotEl.style.minWidth, '1344px');

        // More suppliers give wider per-category groups: 14 x 5 -> 186px -> 2604px
        const width5Suppliers = fitChartScrollWidth(canvas, { categories: 14, series: 5 });
        assert.equal(width5Suppliers, 2604);
        assert.equal(plotEl.style.minWidth, '2604px');

        // Filtering back down to 1 material clears minWidth and resets scrollLeft
        scrollEl.scrollLeft = 450;
        assert.equal(fitChartScrollWidth(canvas, { categories: 1, series: 2 }), 0);
        assert.equal(plotEl.style.minWidth, '');
        assert.equal(scrollEl.scrollLeft, 0);
    } finally {
        restore();
    }
});

test('keyboard navigation pans horizontal scroll container to keep active bar visible', async () => {
    const { attachChartA11y, restore } = await loadThemeModule();
    try {
        const { canvas, scrollEl } = createScrollDom({ clientWidth: 500 });
        scrollEl.scrollWidth = 1400;

        const labels = ['M1', 'M2', 'M3', 'M4', 'M5'];
        const xs = [80, 320, 680, 960, 1280];
        const chart = {
            canvas,
            data: {
                labels,
                datasets: [{ label: 'Supplier A', data: [10, 20, 30, 40, 50] }],
            },
            options: { plugins: {} },
            tooltip: { setActiveElements() {} },
            isDatasetVisible() { return true; },
            getDatasetMeta() {
                return { data: xs.map((x) => ({ x, y: 100 })) };
            },
            setActiveElements() {},
            update() {},
        };

        attachChartA11y(chart);

        // Move to M1 (x=80) -> stays at 0
        canvas.dispatchKey('ArrowRight');
        assert.equal(scrollEl.scrollLeft, 0);

        // Move to M2 (x=320) -> inside [0, 500], stays at 0
        canvas.dispatchKey('ArrowRight');
        assert.equal(scrollEl.scrollLeft, 0);

        // Move to M3 (x=680) -> scrolls right so 680 + 64 <= scrollLeft + 500 -> 244
        canvas.dispatchKey('ArrowRight');
        assert.equal(scrollEl.scrollLeft, 244);

        // End (x=1280) -> scrolls to 1280 + 64 - 500 = 844
        canvas.dispatchKey('End');
        assert.equal(scrollEl.scrollLeft, 844);

        // Home (x=80) -> scrolls back to 16 (80 - 64)
        canvas.dispatchKey('Home');
        assert.equal(scrollEl.scrollLeft, 16);
    } finally {
        restore();
    }
});

test('comparison bar charts opt into horizontal scrolling', () => {
    const interSupplier = readFileSync(new URL('../../resources/views/purchasing/comparison/_inter_supplier_content.blade.php', import.meta.url), 'utf8');
    const historicalPr = readFileSync(new URL('../../resources/views/purchasing/comparison/_historical_pr_mode.blade.php', import.meta.url), 'utf8');

    assert.match(interSupplier, /<x-ui\.chart id="comparisonChart"[^>]*:scrollable="true"/);
    assert.match(historicalPr, /<x-ui\.chart id="historicalPrChart"[^>]*:scrollable="true"/);
});
