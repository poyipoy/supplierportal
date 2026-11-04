import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

// Gate 1 C5 / E1 / AC6: the chart builders plot one declared basis, never turn gaps into 0,
// never average, and mark Indicative points with a non-color cue (hollow marker).
const view = await readFile(new URL('../../resources/views/purchasing/comparison/_historical_pr_scripts.blade.php', import.meta.url), 'utf8');
const start = view.indexOf('// <historical-quotation-chart>');
const end = view.indexOf('// </historical-quotation-chart>');
assert.ok(start > 0 && end > start, 'builder markers present');

function builders() {
    const windowRef = {};
    vm.runInNewContext(view.slice(start, end), { window: windowRef });

    return windowRef.AdasiHistoricalQuotationChart;
}

const colors = { primary: 'P', warning: 'W', secondary: 'S', success: 'G', info: 'I', error: 'E', onSurfaceVariant: 'V' };
const i18n = { current: 'Current', previous: 'Previous', indicative: 'Indicative', weightedAverage: 'Weighted avg', poBasis: 'IDR/kg PO' };
const format = (value) => String(value);
const formatDate = (value) => new Date(value).toISOString().slice(0, 10);

test('current vs previous (G26): IDR basis, native IDR kept, missing values stay null, indicative spelled out', () => {
    const { currentPrevious } = builders();
    const chart = {
        basis: 'IDR',
        axis_label: 'IDR/kg: IDR native, other currencies at each quotation snapshot',
        labels: ['S-A', 'S-B', 'S-C', 'S-D'],
        series: { current: ['67260.0000', '22680.0000', '60000.0000', null], previous: ['63720.0000', '29700.0000', null, null] },
        tooltips: { current: [{}, {}, {}, null], previous: [{ indicative: true }, {}, null, null] },
    };

    const built = currentPrevious(chart, i18n, colors, format);
    const [previous, current] = built.config.data.datasets;

    assert.equal(built.config.type, 'bar');
    assert.deepEqual(current.data, [67260, 22680, 60000, null]);
    assert.deepEqual(previous.data, [63720, 29700, null, null]);
    assert.ok(![...current.data, ...previous.data].includes(0), 'a gap is never plotted as zero');
    assert.equal(built.config.options.scales.y.title.text, 'IDR/kg', 'a short basis on the axis; the full sentence is the caption');
    assert.equal(previous.backgroundColor, 'S', 'the last purchase bar is always filled, never hollow');
    assert.equal(previous.borderWidth, 0);
    assert.deepEqual(JSON.parse(JSON.stringify(built.config.data.labels)), [['S-A', '(Indicative)'], 'S-B', 'S-C', 'S-D'], 'Indicative is text under the supplier, not a style');
    assert.equal(built.plotted, 5);
    assert.equal(built.empty, false);
});

test('statuses 1-3 only: nothing to plot is an empty chart, not zeros', () => {
    const { currentPrevious } = builders();
    const built = currentPrevious({ basis: 'USD', axis_label: 'USD/kg', labels: ['S-E'], series: { current: [null], previous: [null] }, tooltips: { current: [null], previous: [null] } }, i18n, colors, format);

    assert.equal(built.empty, true);
    assert.equal(built.plotted, 0);
});

test('quotation trend (G14): real dates on a linear axis, every quote its own point, no smoothing or gaps bridged', () => {
    const { trend } = builders();
    const points = [
        { x: Date.UTC(2026, 5, 3, 2), y: '3.5500', tooltip: {} },
        { x: Date.UTC(2026, 5, 10, 2), y: '3.6000', tooltip: { indicative: true } },
        { x: Date.UTC(2026, 9, 15, 2), y: '3.8000', tooltip: {} },
    ];
    const built = trend({ basis: 'USD', axis_label: 'USD/kg', series: [{ supplier: 'S-A', points }], excluded: [] }, i18n, colors, format, formatDate);
    const dataset = built.config.data.datasets[0];

    assert.equal(built.config.options.scales.x.type, 'linear');
    assert.deepEqual(JSON.parse(JSON.stringify(dataset.data)), [
        { x: points[0].x, y: 3.55 },
        { x: points[1].x, y: 3.6 },
        { x: points[2].x, y: 3.8 },
    ], 'three quotes, three points: nothing averaged');
    assert.equal(dataset.spanGaps, false);
    assert.equal(dataset.tension, 0);
    assert.equal(dataset.fill, false);
    assert.deepEqual(dataset.pointBackgroundColor, ['P', 'transparent', 'P']);
    assert.equal(built.config.options.scales.x.ticks.callback(points[0].x), '2026-06-03');
});

test('legend swatches match the plotted markers: series shape, always filled', () => {
    const windowRef = {
        Chart: { defaults: { plugins: { legend: { labels: { generateLabels: (chart) => chart.data.datasets.map((dataset, datasetIndex) => ({
            datasetIndex, text: dataset.label, fillStyle: 'transparent', strokeStyle: dataset.borderColor, lineWidth: 2, pointStyle: 'circle',
        })) } } } } },
    };
    vm.runInNewContext(view.slice(start, end), { window: windowRef });
    const { trend, currentPrevious } = windowRef.AdasiHistoricalQuotationChart;

    const series = ['S-A', 'S-B'].map((supplier) => ({ supplier, points: [{ x: 1, y: '1', tooltip: { indicative: true } }] }));
    const line = trend({ basis: 'USD', axis_label: 'USD/kg', series, excluded: [] }, i18n, { ...colors, series: ['A', 'B'] }, format, formatDate).config;
    const lineItems = line.options.plugins.legend.labels.generateLabels({ data: line.data });
    assert.deepEqual(JSON.parse(JSON.stringify(lineItems.map((item) => [item.pointStyle, item.fillStyle]))), [['circle', 'A'], ['rect', 'B']], 'shape of each series, filled even when the first point is Indicative');
    assert.equal(line.options.plugins.legend.labels.pointStyle, undefined, 'no single forced swatch shape on the line chart');

    // Regression: stroke-only shapes (5th crossRot, 6th star) vanished from the legend with a zero line width.
    const six = ['S-1', 'S-2', 'S-3', 'S-4', 'S-5', 'S-6'].map((supplier) => ({ supplier, points: [{ x: 1, y: '1', tooltip: {} }] }));
    const sixLine = trend({ basis: 'IDR', axis_label: 'IDR/kg', series: six, excluded: [] }, i18n, { ...colors, series: ['A', 'B', 'C', 'D', 'E', 'F'] }, format, formatDate).config;
    const sixItems = sixLine.options.plugins.legend.labels.generateLabels({ data: sixLine.data });
    assert.deepEqual(JSON.parse(JSON.stringify(sixItems.map((item) => item.pointStyle))), ['circle', 'rect', 'triangle', 'rectRot', 'crossRot', 'star']);
    sixItems.forEach((item) => {
        if (['crossRot', 'star', 'cross', 'line', 'dash'].includes(item.pointStyle)) {
            assert.ok(item.lineWidth > 0, item.pointStyle + ' is drawn with a stroke, so it needs a visible line width');
        }
        assert.equal(item.strokeStyle, item.fillStyle, 'stroke and fill use the series color');
    });

    const bar = currentPrevious({ basis: 'IDR', axis_label: 'IDR/kg', labels: ['S-A'], series: { current: ['2'], previous: ['1'] }, tooltips: { current: [{}], previous: [{ indicative: true }] } }, i18n, colors, format).config;
    const barItems = bar.options.plugins.legend.labels.generateLabels({ data: bar.data });
    assert.deepEqual(JSON.parse(JSON.stringify(barItems.map((item) => [item.pointStyle, item.fillStyle]))), [['rectRounded', 'S'], ['rectRounded', 'P']]);
});

test('historical purchase price keeps the PO benchmark values and nulls as given', () => {
    const { purchaseHistory } = builders();
    const built = purchaseHistory({ currency: 'IDR', chartData: { labels: ['Aug 2026', 'Sep 2026', 'Oct 2026'], pricesIdr: [50000, 65500, null] } }, i18n, colors, format);

    assert.deepEqual(built.config.data.datasets[0].data, [50000, 65500, null]);
    assert.equal(built.config.data.datasets[0].spanGaps, false);
    assert.equal(built.config.options.scales.y.title.text, 'IDR/kg');
    assert.equal(purchaseHistory(null, i18n, colors, format).empty, true);
});

test('tooltips carry the original value, rate and source, and flag Indicative', () => {
    const { currentPrevious } = builders();
    const chart = {
        basis: 'IDR', axis_label: 'IDR/kg', labels: ['S-B'],
        series: { current: ['22680.0000'], previous: ['29700.0000'] },
        tooltips: {
            current: [{ original: 'JPY 210', rate: 'Rate 108 (valid from 1 May 2026)', pr_number: 'REQ/G/2', date: '15 Oct 2026', status: 'Submitted' }],
            previous: [{ original: 'CNY 13.2', rate: null, pr_number: 'REQ/G/1', date: '12 Aug 2026', status: 'Accepted', indicative: true }],
        },
    };
    const built = currentPrevious(chart, i18n, colors, format);
    const label = built.config.options.plugins.tooltip.callbacks.label;

    assert.deepEqual(Array.from(label({ dataset: built.config.data.datasets[1], dataIndex: 0, parsed: { y: 22680 } })), [
        'Current: 22680/kg', 'JPY 210 · Rate 108 (valid from 1 May 2026)', 'REQ/G/2 · 15 Oct 2026 · Submitted',
    ]);
    assert.ok(label({ dataset: built.config.data.datasets[0], dataIndex: 0, parsed: { y: 29700 } }).includes('Indicative'));
});

test('current vs previous: an all-empty series has no legend entry and bars stay bounded', () => {
    const { currentPrevious } = builders();
    const chart = {
        basis: 'CNY', axis_label: 'CNY/kg', labels: ['S-A', 'S-B'],
        series: { current: ['4.0000', '10.0000'], previous: [null, null] },
        tooltips: { current: [{}, {}], previous: [null, null] },
    };
    const { config } = currentPrevious(chart, i18n, colors, format);
    const filter = config.options.plugins.legend.labels.filter;

    assert.equal(filter({ datasetIndex: 0 }, config.data), false, 'Previous has nothing plotted');
    assert.equal(filter({ datasetIndex: 1 }, config.data), true);
    config.data.datasets.forEach((dataset) => assert.equal(dataset.maxBarThickness, 56));
});

test('the AdasiChart theme is layered under the declared basis, never replacing it', () => {
    const { currentPrevious, trend } = builders();
    const theme = {
        scales: () => ({ x: { grid: { display: false }, themed: true }, y: { themed: true, title: { display: false }, ticks: { padding: 8, callback: () => 'theme' } } }),
        tooltip: (custom) => ({ themed: true, ...custom }),
    };
    const chart = { basis: 'IDR', axis_label: 'IDR/kg', labels: ['S-A'], series: { current: ['1'], previous: ['2'] }, tooltips: { current: [{}], previous: [{}] } };
    const { config } = currentPrevious(chart, i18n, colors, format, theme);

    assert.equal(config.options.scales.y.themed, true);
    assert.equal(config.options.scales.y.title.display, true);
    assert.equal(config.options.scales.y.title.text, 'IDR/kg');
    assert.equal(config.options.scales.y.ticks.callback(5), '5', 'builder tick format wins over the theme default');
    assert.equal(config.options.plugins.tooltip.themed, true);
    assert.equal(typeof config.options.plugins.tooltip.callbacks.label, 'function');

    const line = trend({ basis: 'USD', axis_label: 'USD/kg', series: [], excluded: [] }, i18n, colors, format, formatDate, theme);
    assert.equal(line.config.options.scales.x.type, 'linear', 'trend keeps its real-date axis');
    assert.equal(line.config.options.scales.y.title.text, 'USD/kg');
});

test('drawer and table text is rendered with textContent, never innerHTML', () => {
    const runtime = view.slice(end);
    assert.doesNotMatch(runtime, /innerHTML/, 'supplier notes and names cannot inject markup');
    assert.match(runtime, /if \(text !== undefined && text !== null\) element\.textContent = text;/);
});

test('quotation trend suppliers differ by point shape and line dash, not by color alone', () => {
    const { trend } = builders();
    const series = ['S-A', 'S-B', 'S-C'].map((supplier, index) => ({ supplier, points: [{ x: Date.UTC(2026, 5, 1 + index), y: '1.0000', tooltip: {} }] }));
    const built = trend({ basis: 'USD', axis_label: 'USD/kg', series, excluded: [] }, i18n, { ...colors, series: ['A', 'B', 'C', 'D', 'E', 'F'] }, format, formatDate);
    const datasets = built.config.data.datasets;

    assert.deepEqual(datasets.map((dataset) => dataset.borderColor), ['A', 'B', 'C'], 'shared AdasiChart series tokens when present');
    assert.equal(new Set(datasets.map((dataset) => dataset.pointStyle)).size, 3);
    assert.equal(new Set(datasets.map((dataset) => JSON.stringify(dataset.borderDash))).size, 3);
});
