import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

// Inter-supplier price chart: distinct token colors per supplier, a missing offer is a gap
// (never 0, I-8), the lowest price per material has a non-color cue, and the % scale
// normalises each material to its cheapest offer.
const view = await readFile(new URL('../../resources/views/purchasing/comparison/_scripts.blade.php', import.meta.url), 'utf8');
const start = view.indexOf('// <inter-supplier-chart>');
const end = view.indexOf('// </inter-supplier-chart>');
assert.ok(start > 0 && end > start, 'builder markers present');
const block = view.slice(start, end);

function builders() {
    const windowRef = {};
    vm.runInNewContext(block, { window: windowRef });

    return windowRef.AdasiInterSupplierChart;
}

const plain = (value) => JSON.parse(JSON.stringify(value));
const colors = { primary: 'P', warning: 'W', secondary: 'S', success: 'G', info: 'I', error: 'E', onSurfaceVariant: 'V' };
const palette = ['P', 'W', 'S', 'G', 'I', 'E'];
const i18n = { lowest: 'Lowest', noOffer: 'No offer', aboveLowest: ':percent above lowest', summary: 'Lowest: :items' };
const format = (value) => (value === null ? '-' : 'Rp ' + value);
const formatPercent = (value) => (value > 0 ? '+' : '') + value + '%';

const chartData = {
    labels: ['1.2316', 'SKD11', 'SKD11', 'Aluminium 7075'],
    label_details: ['Flat 205 × 610', 'Flat 20 × 100', 'Round Ø 50', 'Flat 10 × 50'],
    currency: 'IDR',
    datasets: [
        { label: 'Krakatau', data: [10000, 20000, 15000, null] },
        { label: 'Nippon', data: [25000, 40000, 30000, 200000] },
        { label: 'Posco', data: [40000, 50000, 12000, 210000] },
    ],
};

test('each supplier gets its own palette color, solid fill, no gradient or raw hex', () => {
    const { build } = builders();
    const { config } = build(chartData, { colors, palette, i18n, format, formatPercent });
    const fills = config.data.datasets.map((dataset) => dataset.backgroundColor);

    assert.deepEqual(fills, ['P', 'W', 'S']);
    assert.equal(new Set(fills).size, fills.length, 'neighbouring suppliers never share a color');
    assert.doesNotMatch(block, /createBarGradient|createLinearGradient/);
    assert.doesNotMatch(block, /#[0-9a-fA-F]{3,6}\b/);
});

test('same-name materials get a second axis line with shape and size', () => {
    const { axisLabels } = builders();

    assert.deepEqual(plain(axisLabels(chartData)), [
        '1.2316',
        ['SKD11', 'Flat 20 × 100'],
        ['SKD11', 'Round Ø 50'],
        'Aluminium 7075',
    ]);
    assert.deepEqual(plain(axisLabels({ labels: ['A'] })), ['A'], 'label_details is optional');
});

test('a missing offer stays null in the full chart and in a single-material subset', () => {
    const { build, subset } = builders();
    const full = build(chartData, { colors, palette, i18n, format, formatPercent });
    assert.equal(full.config.data.datasets[0].data[3], null);

    const single = subset(chartData, 3);
    assert.deepEqual(plain(single.datasets.map((dataset) => dataset.data)), [[null], [200000], [210000]]);
    assert.deepEqual(plain(single.labels), ['Aluminium 7075']);
    assert.deepEqual(plain(single.label_details), ['Flat 10 × 50']);
});

test('lowest per material needs at least two offers and ignores gaps and hidden series', () => {
    const { lowestIndexes } = builders();
    const prices = chartData.datasets.map((dataset) => dataset.data);

    assert.deepEqual(plain(lowestIndexes(prices, 4)), [0, 0, 2, 1]);
    assert.deepEqual(plain(lowestIndexes([[5], [null]], 1)), [null], 'one offer is not a comparison');
    assert.deepEqual(plain(lowestIndexes([[5], [], [7]], 1)), [0], 'a hidden series (empty) is skipped');
});

test('% scale: cheapest is 0, others are percent above it, gaps stay null', () => {
    const { build } = builders();
    const { config } = build(chartData, { colors, palette, i18n, format, formatPercent, mode: 'relative' });

    assert.deepEqual(plain(config.data.datasets.map((dataset) => dataset.data)), [
        [0, 0, 25, null],
        [150, 100, 150, 0],
        [300, 150, 0, 5],
    ]);
    assert.deepEqual(plain(config.data.datasets[1].prices), [25000, 40000, 30000, 200000], 'raw prices kept for the tooltip');
    assert.equal(config.options.scales.y.ticks.callback(25), '+25%');
});

test('tooltip lists every supplier for the material, marks the lowest and names missing offers', () => {
    const { build } = builders();
    const { config } = build(chartData, { colors, palette, i18n, format, formatPercent });
    const tooltip = config.options.plugins.tooltip;
    const [krakatau, nippon] = config.data.datasets;

    assert.equal(tooltip.mode, 'index');
    assert.equal(tooltip.callbacks.label({ dataset: krakatau, datasetIndex: 0, dataIndex: 0 }), ' Krakatau: Rp 10000 · Lowest');
    assert.equal(tooltip.callbacks.label({ dataset: nippon, datasetIndex: 1, dataIndex: 0 }), ' Nippon: Rp 25000 · +150% above lowest');
    assert.deepEqual(plain(tooltip.callbacks.afterBody([{ dataIndex: 3 }])), ['Krakatau: No offer']);
    assert.ok(tooltip.itemSort({ dataset: krakatau, dataIndex: 0 }, { dataset: nippon, dataIndex: 0 }) < 0, 'cheapest first');
});

test('AdasiChart theme scales and tooltip are used when available', () => {
    const { build } = builders();
    const theme = {
        scales: (options) => ({ x: { themed: true }, y: { themed: true, ticks: { callback: options.yFormat } } }),
        tooltip: (custom) => ({ themed: true, ...custom }),
    };
    const { config } = build(chartData, { colors, palette, i18n, format, formatPercent, theme });

    assert.equal(config.options.scales.y.themed, true);
    assert.equal(config.options.plugins.tooltip.themed, true);
    assert.equal(config.options.plugins.tooltip.mode, 'index');
});

test('screen reader summary names the lowest supplier per material', () => {
    const { summary } = builders();

    assert.equal(
        summary(chartData, i18n, format),
        'Lowest: 1.2316 Krakatau Rp 10000; SKD11 Krakatau Rp 20000; SKD11 Posco Rp 12000; Aluminium 7075 Nippon Rp 200000',
    );
});

test('a 7th supplier reuses a palette hue but is hatched, so no two suppliers look identical', () => {
    const { build } = builders();
    const many = {
        labels: ['A'],
        datasets: Array.from({ length: 7 }, (_, index) => ({ label: 'S' + (index + 1), data: [1000 + index] })),
    };
    const theme = { hatch: (color, cycle) => 'hatch:' + color + ':' + cycle };
    const { config } = build(many, { colors, palette, i18n, format, formatPercent, theme });
    const fills = config.data.datasets.map((dataset) => dataset.backgroundColor);

    assert.deepEqual(fills.slice(0, 6), palette, 'the first six suppliers are solid palette hues');
    assert.equal(fills[6], 'hatch:P:1', 'the 7th repeats hue 1 with a hatch, never a generated color');
    assert.equal(config.data.datasets[6].borderColor, 'P');
});
