import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const view = await readFile(new URL('../../resources/views/purchasing/comparison/_scripts.blade.php', import.meta.url), 'utf8');
const contentView = await readFile(new URL('../../resources/views/purchasing/comparison/_historical_content.blade.php', import.meta.url), 'utf8');
const start = view.indexOf('// <historical-standalone-chart>');
const end = view.indexOf('// </historical-standalone-chart>');
assert.ok(start > 0 && end > start, 'historical standalone chart builder markers present');
const block = view.slice(start, end);

function loadBuilder() {
    const windowRef = {};
    vm.runInNewContext(block, { window: windowRef });
    return windowRef.AdasiHistoricalChart;
}

const i18n = {
    weightedAverage: 'Weighted Average',
    average: 'Average',
    highest: 'Highest',
    lowest: 'Lowest',
    historyBasis: ':pos POs · :kg kg',
};

const formatMoney = (value) => (value === null || value === undefined ? '-' : 'IDR ' + value);
const formatNumber = (value) => String(value ?? '0');

const plain = (value) => JSON.parse(JSON.stringify(value));

test('resolveChartType always shows switch, defaults Line for <= 2 periods and Bar for >= 3 periods, and honors manual selection', () => {
    const { resolveChartType } = loadBuilder();

    assert.deepEqual(plain(resolveChartType(0, null)), { type: 'line', showSwitch: true });
    assert.deepEqual(plain(resolveChartType(1, null)), { type: 'line', showSwitch: true });
    assert.deepEqual(plain(resolveChartType(2, null)), { type: 'line', showSwitch: true });
    assert.deepEqual(plain(resolveChartType(3, null)), { type: 'bar', showSwitch: true });
    assert.deepEqual(plain(resolveChartType(5, 'invalid')), { type: 'bar', showSwitch: true });

    // Explicit manual user selection is always honored regardless of period count
    assert.deepEqual(plain(resolveChartType(1, 'bar')), { type: 'bar', showSwitch: true });
    assert.deepEqual(plain(resolveChartType(2, 'bar')), { type: 'bar', showSwitch: true });
    assert.deepEqual(plain(resolveChartType(2, 'line')), { type: 'line', showSwitch: true });
    assert.deepEqual(plain(resolveChartType(3, 'line')), { type: 'line', showSwitch: true });
    assert.deepEqual(plain(resolveChartType(3, 'bar')), { type: 'bar', showSwitch: true });
});

test('buildConfig preserves null gaps (spanGaps false) and uses non-color cues for multi-material lines and bars', () => {
    const { buildConfig } = loadBuilder();
    const theme = {
        getSeriesStyle: (idx) => ({
            color: `series-${(idx % 6) + 1}`,
            pointStyle: ['circle', 'rect', 'triangle', 'rectRot', 'crossRot', 'star'][idx % 6],
            borderDash: [[], [6, 4], [2, 3], [10, 3, 2, 3], [4, 4], [1, 3]][idx % 6],
            hatched: idx >= 6,
            pattern: Math.floor(idx / 6),
        }),
        seriesFill: (idx) => (idx >= 6 ? `hatch:${idx}` : `solid:${idx}`),
        color: (role) => `token:${role}`,
        getLegend: (opts) => ({ themedLegend: true, ...opts }),
        getScales: (opts) => ({ themedScales: true, ...opts }),
        getTooltip: (opts) => ({ themedTooltip: true, ...opts }),
    };

    const chartData = {
        labels: ['2024', '2025', '2026'],
        series: Array.from({ length: 7 }, (_, i) => ({
            index: i,
            label: `MAT-${i + 1} (Flat)`,
            pricesIdr: [10000 + i * 100, i === 1 ? null : 11000 + i * 100, 12000 + i * 100],
            minIdr: [9000, null, 11500],
            maxIdr: [10500, null, 12500],
            poCounts: [2, 0, 3],
            totalKgs: [100, 0, 150],
        })),
    };

    const lineResult = buildConfig(chartData, 'line', { theme, currency: 'IDR', formatMoney, formatNumber, i18n });
    assert.equal(lineResult.type, 'line');
    assert.equal(lineResult.showSwitch, true);
    assert.equal(lineResult.config.data.datasets.length, 7);
    assert.equal(lineResult.config.data.datasets[1].data[1], null, 'missing period stays null');
    assert.equal(lineResult.config.data.datasets[1].spanGaps, false, 'line never fabricates continuity across missing periods');
    assert.deepEqual(plain(lineResult.config.data.datasets[1].borderDash), [6, 4], 'multi-material line uses dash cue');
    assert.equal(lineResult.config.data.datasets[1].pointStyle, 'rect', 'multi-material line uses pointStyle cue');

    const barResult = buildConfig(chartData, 'bar', { theme, currency: 'IDR', formatMoney, formatNumber, i18n });
    assert.equal(barResult.type, 'bar');
    assert.equal(barResult.showSwitch, true);
    assert.equal(barResult.config.data.datasets[0].backgroundColor, 'solid:0');
    assert.equal(barResult.config.data.datasets[6].backgroundColor, 'hatch:6', '7th series bar uses hatched pattern');

    // <= 2 periods default to Line with switch always shown, and honor manual switch to Bar
    const shortData = { ...chartData, labels: ['2025', '2026'], series: [chartData.series[0]] };
    const shortDefault = buildConfig(shortData, null, { theme, currency: 'IDR', formatMoney, formatNumber, i18n });
    assert.equal(shortDefault.type, 'line');
    assert.equal(shortDefault.showSwitch, true);
    assert.equal(shortDefault.config.data.datasets[0].pointRadius, 4);

    const shortManualBar = buildConfig(shortData, 'bar', { theme, currency: 'IDR', formatMoney, formatNumber, i18n });
    assert.equal(shortManualBar.type, 'bar');
    assert.equal(shortManualBar.showSwitch, true);

    // 1-period Line chart enlarges point marker for clear visibility and hoverability
    const singlePeriodData = {
        ...chartData,
        labels: ['2026'],
        series: [{ ...chartData.series[0], pricesIdr: [12000], minIdr: [11500], maxIdr: [12500], poCounts: [2], totalKgs: [50] }],
    };
    const singlePeriodLine = buildConfig(singlePeriodData, null, { theme, currency: 'IDR', formatMoney, formatNumber, i18n });
    assert.equal(singlePeriodLine.type, 'line');
    assert.equal(singlePeriodLine.showSwitch, true);
    assert.equal(singlePeriodLine.config.data.datasets[0].pointRadius, 6);
    assert.equal(singlePeriodLine.config.data.datasets[0].pointHoverRadius, 8);
});

test('historicalChartTypeSwitch is always visible in Blade (zero jump), capNote lives in footer, and retains WCAG hover tokens', () => {
    const switchBlockMatch = contentView.match(/id="historicalChartTypeSwitch"[\s\S]*?<\/div>/);
    assert.ok(switchBlockMatch, 'historicalChartTypeSwitch markup must exist');
    assert.doesNotMatch(switchBlockMatch[0], /tw:hidden/, 'chart switch must never be hidden via tw:hidden');
    assert.doesNotMatch(switchBlockMatch[0], /\s+hidden(?:\s|>)/, 'chart switch must stay permanently visible across both axis modes');
    assert.match(contentView, /\$chartSwitchActiveClass[\s\S]*?tw:hover:bg-\[var\(--ui-primary-hover\)\]/, 'SSR active switch button uses primary hover token');
    assert.match(view, /updateChartTypeSwitchUI[\s\S]*?tw:hover:bg-\[var\(--ui-primary-hover\)\]/, 'runtime active switch button uses primary hover token');

    const chartPos = contentView.indexOf('id="historicalChart"');
    const footerPos = contentView.indexOf('id="historicalChartFooter"');
    const capPos = contentView.indexOf('id="historicalChartAxisCapNote"');
    assert.ok(chartPos > 0 && footerPos > chartPos && capPos > footerPos, 'cap note sits inside footer below canvas to prevent vertical layout shift');
    assert.match(contentView, /:summary-sr-only="true"/, 'raw paragraph auto-summary is sr-only while visual footer renders structured summary');
});

test('resolveAxisMode applies Smart Default (1 -> period, 2+ -> material) and honors manual user choice', () => {
    const { resolveAxisMode } = loadBuilder();

    assert.equal(resolveAxisMode(0, null), 'period');
    assert.equal(resolveAxisMode(1, null), 'period');
    assert.equal(resolveAxisMode(2, null), 'material');
    assert.equal(resolveAxisMode(5, null), 'material');

    // Manual override always wins
    assert.equal(resolveAxisMode(1, 'material'), 'material');
    assert.equal(resolveAxisMode(4, 'period'), 'period');
});

test('buildConfig in material axisMode transposes X to Material Name, bars to chronological Year/Month, caps >6 periods to latest 6, and disables xAutoSkip', () => {
    const { buildConfig } = loadBuilder();
    let capturedScalesOpts = null;
    const theme = {
        barDataset: ({ index, maxBarThickness, categoryPercentage }) => ({
            backgroundColor: `bar-${index}`,
            maxBarThickness,
            categoryPercentage,
        }),
        getLegend: () => ({ themedLegend: true }),
        getScales: (opts) => {
            capturedScalesOpts = opts;
            return { themedScales: true };
        },
        getTooltip: (opts) => opts,
    };

    const eightMonths = ['Apr 2026', 'May 2026', 'Jun 2026', 'Jul 2026', 'Aug 2026', 'Sep 2026', 'Oct 2026', 'Nov 2026'];
    const chartData = {
        labels: eightMonths,
        series: [
            {
                index: 0,
                label: 'P20 (Flat)',
                pricesIdr: [10, 11, 12, 13, 14, 15, null, 17],
                minIdr: [9, 10, 11, 12, 13, 14, null, 16],
                maxIdr: [11, 12, 13, 14, 15, 16, null, 18],
                poCounts: [1, 1, 2, 2, 3, 3, 0, 4],
                totalKgs: [100, 100, 200, 200, 300, 300, 0, 450],
            },
            {
                index: 1,
                label: 'SCM440 (Round)',
                pricesIdr: [20, 21, 22, 23, 24, 25, 26, 27],
                minIdr: [19, 20, 21, 22, 23, 24, 25, 26],
                maxIdr: [21, 22, 23, 24, 25, 26, 27, 28],
                poCounts: [2, 2, 2, 2, 2, 2, 5, 6],
                totalKgs: [50, 60, 70, 80, 90, 100, 500, 600],
            },
        ],
    };

    // Default type in material axisMode is 'bar' when preferredType is null, while showSwitch stays true.
    const built = buildConfig(chartData, null, {
        theme,
        currency: 'IDR',
        formatMoney,
        formatNumber,
        i18n,
        axisMode: 'material',
    });

    assert.equal(built.axisMode, 'material');
    assert.equal(built.type, 'bar');
    assert.equal(built.showSwitch, true, 'Line | Bar switch stays visible in By Material mode');
    assert.equal(built.truncatedPeriods, true);
    assert.equal(built.shownPeriods, 6);
    assert.equal(built.totalPeriods, 8);
    assert.equal(capturedScalesOpts.xAutoSkip, false, 'X-axis never auto-skips material names');

    // Selecting Line while in material axisMode automatically routes X-axis to Period (Year/Month) with all periods uncapped and auto-zoom Y-axis
    let lineScalesOpts = null;
    const switchedToLine = buildConfig(chartData, 'line', {
        theme: {
            ...theme,
            getColors: () => ({ forced: false }),
            getScales: (opts) => {
                lineScalesOpts = opts;
                return { themedScales: true };
            },
            lineDataset: ({ index, multi }) => ({
                borderColor: `line-${index}`,
                backgroundColor: `line-${index}`,
                spanGaps: false,
                multi,
            }),
        },
        currency: 'IDR',
        formatMoney,
        formatNumber,
        i18n,
        axisMode: 'material',
    });
    assert.equal(switchedToLine.axisMode, 'period', 'Line chart places Year/Month on X-axis');
    assert.equal(switchedToLine.type, 'line');
    assert.equal(switchedToLine.showSwitch, true);
    assert.equal(switchedToLine.truncatedPeriods, false, 'Line on Period axis shows all 8 periods uncapped');
    assert.deepEqual(plain(switchedToLine.config.data.labels), eightMonths);
    assert.equal(switchedToLine.config.data.datasets.length, 2, 'each material becomes one line series');
    assert.deepEqual(plain(switchedToLine.config.options.interaction), { mode: 'index', intersect: false });
    assert.equal(switchedToLine.config.data.datasets[0].spanGaps, false);
    assert.equal(lineScalesOpts.yBeginAtZero, false, 'Historical Line auto-zooms Y-axis');
    assert.equal(lineScalesOpts.yGrace, '12%');
    assert.equal(capturedScalesOpts.yBeginAtZero, true, 'Bar chart stays strictly anchored at zero');

    // X-axis holds Material names; datasets hold the latest 6 chronological periods (Jun..Nov 2026).
    assert.deepEqual(plain(built.config.data.labels), ['P20 (Flat)', 'SCM440 (Round)']);
    assert.equal(built.config.data.datasets.length, 6);
    assert.deepEqual(
        built.config.data.datasets.map((ds) => ds.label),
        ['Jun 2026', 'Jul 2026', 'Aug 2026', 'Sep 2026', 'Oct 2026', 'Nov 2026'],
    );
    // Oct 2026 (dataset index 4): P20 had null, SCM440 had 26
    assert.deepEqual(plain(built.config.data.datasets[4].data), [null, 26]);
    // Nov 2026 (dataset index 5): P20 had 17, SCM440 had 27
    assert.deepEqual(plain(built.config.data.datasets[5].data), [17, 27]);

    // Tooltip callbacks show Period label + Lowest/Highest/POs·kg for the hovered material index
    const tooltipCb = built.config.options.plugins.tooltip.callbacks;
    const labelText = tooltipCb.label({
        parsed: { y: 27 },
        dataset: built.config.data.datasets[5],
        dataIndex: 1,
    });
    assert.equal(labelText, ' Nov 2026: IDR 27');
    const afterText = tooltipCb.afterLabel({
        dataset: built.config.data.datasets[5],
        dataIndex: 1,
    });
    assert.deepEqual(plain(afterText), ['   Lowest: IDR 26 · Highest: IDR 28 · 6 POs · 600 kg']);
});

test('historical bar charts use per-bar interaction, external HTML tooltip, and perBarStops while line charts keep index mode', () => {
    const { buildConfig, buildBarTooltipModel } = loadBuilder();
    const theme = {
        barDataset: ({ index, dimMode, minBarLength, maxBarThickness, categoryPercentage }) => ({
            backgroundColor: `bar-${index}`,
            borderColor: `#00000${index + 1}`,
            adasiDim: true,
            adasiDimMode: dimMode,
            minBarLength,
            maxBarThickness,
            categoryPercentage,
        }),
        lineDataset: ({ index }) => ({
            borderColor: `#00000${index + 1}`,
            backgroundColor: `#00000${index + 1}`,
        }),
        getLegend: () => ({ themedLegend: true }),
        getScales: () => ({ themedScales: true }),
        getTooltip: (opts) => ({ enabled: true, ...opts }),
    };

    const chartData = {
        labels: ['2024', '2025', '2026'],
        series: [
            {
                index: 0,
                label: 'SKD11 (Flat)',
                pricesIdr: [100000, 110000, 104500],
                minIdr: [95000, 105000, 100000],
                maxIdr: [108000, 118000, 112000],
                poCounts: [12, 15, 18],
                totalKgs: [5000, 6200, 7100],
            },
            {
                index: 1,
                label: 'P20 (Flat)',
                pricesIdr: [60000, 65000, 70000],
                minIdr: [58000, 62000, 66000],
                maxIdr: [64000, 68000, 74000],
                poCounts: [8, 10, 11],
                totalKgs: [3200, 4100, 4800],
            },
        ],
    };

    // Bar chart (material axis): nearest/intersect:true + external HTML tooltip + single-bar dim + perBarStops
    const barBuilt = buildConfig(chartData, 'bar', {
        theme,
        currency: 'IDR',
        formatMoney,
        formatNumber,
        axisMode: 'material',
        i18n: {
            ...i18n,
            vsPrev: 'vs :period',
            groupLowestBadge: 'Lowest in group',
            groupHighestBadge: 'Highest in group',
        },
    });
    assert.deepEqual(plain(barBuilt.config.options.interaction), { mode: 'nearest', axis: 'xy', intersect: true });
    assert.equal(barBuilt.config.options.plugins.tooltip.enabled, false);
    assert.equal(typeof barBuilt.config.options.plugins.tooltip.external, 'function');
    assert.equal(barBuilt.config.options.plugins.adasiA11y.perBarStops, true);
    assert.equal(barBuilt.config.data.datasets[0].adasiDimMode, 'bar');
    assert.equal(barBuilt.config.data.datasets[0].minBarLength, 6);
    assert.equal(barBuilt.config.plugins?.[0]?.id, 'adasiHistoricalBarTooltipCleanup');

    // Line chart (period axis): retains index mode + native Chart.js tooltip
    const lineBuilt = buildConfig(chartData, 'line', {
        theme,
        currency: 'IDR',
        formatMoney,
        formatNumber,
        axisMode: 'period',
        i18n,
    });
    assert.deepEqual(plain(lineBuilt.config.options.interaction), { mode: 'index', intersect: false });
    assert.equal(lineBuilt.config.options.plugins.tooltip.enabled, true);
    assert.equal(lineBuilt.config.options.plugins.tooltip.external, undefined);
    assert.equal(lineBuilt.config.options.plugins.adasiA11y.perBarStops, undefined);

    // Pure tooltip view-model: SKD11 (Flat) in 2026 (datasetIndex 2, dataIndex 0)
    const fakeChart = {
        data: barBuilt.config.data,
        isDatasetVisible: () => true,
    };
    const model2026 = buildBarTooltipModel(fakeChart, 2, 0, {
        axisMode: 'material',
        isMultiSeries: true,
        formatMoney,
        formatNumber,
        i18n: {
            ...i18n,
            vsPrev: 'vs :period',
            groupLowestBadge: 'Lowest in group',
            groupHighestBadge: 'Highest in group',
        },
    });
    assert.equal(model2026.title, 'SKD11 (Flat)');
    assert.equal(model2026.periodTag, '2026');
    assert.equal(model2026.averageValue, 'IDR 104500');
    assert.equal(model2026.delta.direction, 'down');
    assert.equal(model2026.delta.pct, -5);
    assert.equal(model2026.delta.vsLabel, 'vs 2025');
    assert.equal(model2026.basisText, '18 POs · 7100 kg');
    assert.equal(model2026.groupContext.count, 3);
    assert.equal(model2026.groupContext.lowLabel, '2024');
    assert.equal(model2026.groupContext.highLabel, '2025');
    assert.equal(model2026.groupContext.isLowest, false);
    assert.equal(model2026.groupContext.isHighest, false);

    // Lowest bar in cluster gets "Lowest in group" badge and no delta for first period
    const model2024 = buildBarTooltipModel(fakeChart, 0, 0, {
        axisMode: 'material',
        isMultiSeries: true,
        formatMoney,
        formatNumber,
        i18n: {
            ...i18n,
            vsPrev: 'vs :period',
            groupLowestBadge: 'Lowest in group',
            groupHighestBadge: 'Highest in group',
        },
    });
    assert.equal(model2024.delta, null);
    assert.equal(model2024.groupContext.isLowest, true);
    assert.equal(model2024.groupContext.badgeText, 'Lowest in group');

    // Period axis bar chart computes delta along the same dataset's previous period
    const periodBarBuilt = buildConfig(chartData, 'bar', {
        theme,
        currency: 'IDR',
        formatMoney,
        formatNumber,
        axisMode: 'period',
        i18n: { ...i18n, vsPrev: 'vs :period' },
    });
    const periodModel = buildBarTooltipModel(
        { data: periodBarBuilt.config.data, isDatasetVisible: () => true },
        0,
        1,
        { axisMode: 'period', isMultiSeries: true, formatMoney, formatNumber, i18n: { ...i18n, vsPrev: 'vs :period' } },
    );
    assert.equal(periodModel.title, 'SKD11 (Flat)');
    assert.equal(periodModel.periodTag, '2025');
    assert.equal(periodModel.delta.direction, 'up');
    assert.equal(periodModel.delta.pct, 10);
    assert.equal(periodModel.delta.vsLabel, 'vs 2024');
    assert.equal(periodModel.groupContext.count, 2);
});

test('buildChartSummaryModel computes overall highest/lowest and omits chronological latest on Material axis', () => {
    const { buildChartSummaryModel } = loadBuilder();
    const chartConfig = {
        data: {
            labels: ['1.2316 (Flat)', '1.2738'],
            datasets: [
                { label: '2024', borderColor: '#1F5FA6', data: [90000, 72000] },
                { label: '2025', borderColor: '#D95926', data: [120000, 81000] },
            ],
        },
        isDatasetVisible: () => true,
    };

    const materialSummary = buildChartSummaryModel(chartConfig, { axisMode: 'material', formatMoney });
    assert.equal(materialSummary.overallHigh.formatted, 'IDR 120000');
    assert.equal(materialSummary.overallHigh.context, '1.2316 (Flat) · 2025');
    assert.equal(materialSummary.overallLow.formatted, 'IDR 72000');
    assert.equal(materialSummary.overallLow.context, '1.2738 · 2024');
    assert.equal(materialSummary.seriesRows.length, 2);
    assert.equal(materialSummary.seriesRows[0].latestValue, null, 'material axis omits misleading latest');

    const periodSummary = buildChartSummaryModel(
        {
            data: {
                labels: ['2024', '2025'],
                datasets: [{ label: 'SKD11 (Flat)', borderColor: '#1F5FA6', data: [95000, 110000] }],
            },
            isDatasetVisible: () => true,
        },
        { axisMode: 'period', formatMoney },
    );
    assert.equal(periodSummary.seriesRows[0].latestValue, 'IDR 110000');
    assert.equal(periodSummary.seriesRows[0].latestLabel, '2025');
});
