/**
 * ADASI chart theme and accessibility layer for every Chart.js instance in the portal.
 * Theme: token colors read at render time, fixed series order, ERP scales and tooltip.
 * Access (docs/audits/CHART-A11Y-BASELINE-20261102.md): non-color series cues, reduced motion,
 * forced colors, keyboard readout through a polite live region, and a text summary built
 * from the same data the chart draws.
 */

const FALLBACK_SERIES = ['#1F5FA6', '#D95926', '#14967A', '#B88600', '#C2477A', '#6250D6'];
const POINT_STYLES = ['circle', 'rect', 'triangle', 'rectRot', 'crossRot', 'star'];
const LINE_DASHES = [[], [6, 4], [2, 3], [10, 3, 2, 3], [4, 4], [1, 3]];
const TICK_FONT = { family: 'Inter', size: 12, weight: '500' };
const TITLE_FONT = { family: 'Inter', size: 12, weight: '600' };
const TOOLTIP_BORDER = 'rgba(255, 255, 255, 0.08)';

const STRINGS = {
    point: ':label: :values',
    series_value: ':series :value',
    no_value: 'no data',
    summary_series: ':series: highest :max (:max_label), lowest :min (:min_label), latest :last (:last_label).',
    summary_single: ':series: :value (:label).',
    summary_distribution: 'Total :total. Largest: :label :value (:percent).',
    summary_empty: 'No data to display.',
};

function media(query) {
    try {
        return typeof window !== 'undefined' && typeof window.matchMedia === 'function' && window.matchMedia(query).matches;
    } catch (error) {
        return false;
    }
}

export const prefersReducedMotion = () => media('(prefers-reduced-motion: reduce)');
export const isForcedColors = () => media('(forced-colors: active)');

// System colors can only be read from a rendered element.
function systemColors() {
    const doc = globalThis.document;
    if (!doc?.createElement || !doc.body) return null;
    const probe = doc.createElement('span');
    probe.style.cssText = 'position:absolute;visibility:hidden;color:CanvasText;background-color:Canvas;border:1px solid GrayText;outline:1px solid Highlight';
    doc.body.appendChild(probe);
    const style = getComputedStyle(probe);
    const colors = { text: style.color, canvas: style.backgroundColor, gray: style.borderTopColor, highlight: style.outlineColor };
    probe.remove?.();
    return colors.text ? colors : null;
}

export function resolveChartThemeColors() {
    const rootStyle = getComputedStyle(document.documentElement);
    const getVar = (name, fallback) => rootStyle.getPropertyValue(name).trim() || fallback;

    const colors = {
        primary: getVar('--md-chart-primary', '#1F5FA6'),
        primaryContainer: getVar('--md-chart-primary-container', '#EBF3FC'),
        secondary: getVar('--md-secondary', '#476072'),
        secondaryContainer: getVar('--md-secondary-container', '#E2E8F0'),
        success: getVar('--md-success', '#1E8449'),
        successContainer: getVar('--md-success-container', '#D4EDDA'),
        error: getVar('--md-error', '#C0392B'),
        errorContainer: getVar('--md-error-container', '#FADBD8'),
        warning: getVar('--md-chart-warning', getVar('--md-warning', '#D35400')),
        warningContainer: getVar('--md-warning-container', '#FDEBD0'),
        info: getVar('--md-info', '#2980B9'),
        series: FALLBACK_SERIES.map((fallback, index) => getVar('--md-chart-series-' + (index + 1), fallback)),
        ok: getVar('--md-chart-ok', getVar('--md-success', '#198754')),
        ng: getVar('--md-chart-ng', getVar('--md-error', '#B12B21')),
        surface: getVar('--md-surface', '#FFFFFF'),
        surfaceContainer: getVar('--md-surface-container', '#F0F4F9'),
        surfaceContainerLow: getVar('--md-surface-container-low', '#F8FAFC'),
        onSurface: getVar('--md-on-surface', '#1A202C'),
        onSurfaceVariant: getVar('--md-on-surface-variant', '#64748B'),
        outline: getVar('--md-outline', '#CBD5E1'),
        outlineVariant: getVar('--md-outline-variant', '#E2E8F0'),
        gridLine: getVar('--md-chart-grid', 'rgba(226, 232, 240, 0.75)'),
        tooltipBg: 'rgba(15, 23, 42, 0.94)',
        tooltipText: '#F8FAFC',
        tooltipMuted: '#94A3B8',
        forced: false,
    };

    // Windows contrast themes do not reach canvas pixels; paint with the user's system colors.
    if (isForcedColors()) {
        const system = systemColors();
        if (system) {
            const text = system.text;
            return {
                ...colors,
                primary: text, secondary: text, success: text, error: text, warning: text, info: text,
                series: colors.series.map(() => text),
                ok: text, ng: text,
                surface: system.canvas, surfaceContainer: system.canvas, surfaceContainerLow: system.canvas,
                onSurface: text, onSurfaceVariant: text,
                outline: system.gray, outlineVariant: system.gray, gridLine: system.gray,
                tooltipBg: system.canvas, tooltipText: text, tooltipMuted: text,
                forced: true,
            };
        }
    }

    return colors;
}

let cachedColors = null;

/** Theme colors for the current theme; cleared on every theme, accent or forced-colors change. */
export function currentChartColors() {
    if (!cachedColors) cachedColors = resolveChartThemeColors();
    return cachedColors;
}

/**
 * Categorical series colors in a fixed token order, so neighbouring series
 * (one per supplier) never share a hue.
 */
export function getSeriesPalette(colors = resolveChartThemeColors()) {
    return Array.isArray(colors.series) && colors.series.length
        ? colors.series
        : [colors.primary, colors.warning, colors.secondary, colors.success, colors.info, colors.error];
}

/**
 * Identity cues for series `index`: hue plus a point shape and a line dash that differ per
 * series, so no series is told apart by color alone. Series 7+ reuse a hue and get hatched;
 * under forced colors every series after the first is hatched.
 */
export function getSeriesStyle(index, colors = currentChartColors()) {
    const palette = getSeriesPalette(colors);
    const cycle = Math.floor(index / palette.length);

    return {
        color: palette[index % palette.length],
        pointStyle: POINT_STYLES[index % POINT_STYLES.length],
        borderDash: LINE_DASHES[(index + cycle) % LINE_DASHES.length],
        hatched: cycle > 0 || (colors.forced === true && index > 0),
        pattern: colors.forced === true ? index % 6 : cycle,
    };
}

/**
 * Hex to RGBA helper for smooth chart gradient stops
 */
export function hexToRgba(hex, alpha = 1) {
    if (!hex || typeof hex !== 'string') return `rgba(31, 95, 166, ${alpha})`;
    if (hex.startsWith('rgba') || hex.startsWith('hsla')) return hex;
    if (hex.startsWith('rgb(')) return hex.replace('rgb(', 'rgba(').replace(')', `, ${alpha})`);

    let clean = hex.replace('#', '').trim();
    if (clean.length === 3) {
        clean = clean.split('').map((c) => c + c).join('');
    }
    if (clean.length !== 6) return `rgba(31, 95, 166, ${alpha})`;

    const num = parseInt(clean, 16);
    const r = (num >> 16) & 255;
    const g = (num >> 8) & 255;
    const b = num & 255;

    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
}

/**
 * Scriptable color for a role ('primary', 'ok', 'ng', 'surface', 'series-1'..): it is resolved when
 * the chart draws, so a theme or forced-colors switch recolors marks without rebuilding the chart.
 */
export function themeColor(role, alpha = null) {
    return () => {
        const colors = currentChartColors();
        const palette = getSeriesPalette(colors);
        const value = role.startsWith('series-')
            ? palette[(Number(role.slice(7)) - 1) % palette.length]
            : colors[role];
        return alpha === null ? value : hexToRgba(value, alpha);
    };
}

const patternCache = new Map();

/** A 45°/135°/horizontal/vertical/cross hatch in `color` over the surface (bars only). */
export function createHatchPattern(color, variant = 1, surface = currentChartColors().surface) {
    const doc = globalThis.document;
    const key = color + '|' + variant + '|' + surface;
    if (patternCache.has(key)) return patternCache.get(key);
    const tile = doc?.createElement?.('canvas');
    const context = tile?.getContext?.('2d');
    if (!context) return color;
    tile.width = 8;
    tile.height = 8;
    context.fillStyle = surface;
    context.fillRect(0, 0, 8, 8);
    context.strokeStyle = color;
    context.lineWidth = 2;
    context.beginPath();
    const kind = ((variant - 1) % 5 + 5) % 5;
    if (kind === 0 || kind === 4) { context.moveTo(0, 8); context.lineTo(8, 0); context.moveTo(-2, 2); context.lineTo(2, -2); context.moveTo(6, 10); context.lineTo(10, 6); }
    if (kind === 1 || kind === 4) { context.moveTo(0, 0); context.lineTo(8, 8); context.moveTo(-2, 6); context.lineTo(2, 10); context.moveTo(6, -2); context.lineTo(10, 2); }
    if (kind === 2) { context.moveTo(0, 4); context.lineTo(8, 4); }
    if (kind === 3) { context.moveTo(4, 0); context.lineTo(4, 8); }
    context.stroke();
    const pattern = context.createPattern(tile, 'repeat') || color;
    patternCache.set(key, pattern);
    return pattern;
}

/** Scriptable bar fill for series `index`: solid hue, or a hatch when the hue alone is not unique. */
export function seriesFill(index) {
    return () => {
        const style = getSeriesStyle(index);
        return style.hatched ? createHatchPattern(style.color, style.pattern || 1) : style.color;
    };
}

/** Numbers through the user's regional format (source text is id-ID). */
export function formatChartNumber(value) {
    if (value === null || value === undefined || value === '' || !Number.isFinite(Number(value))) return '-';
    const text = Number(value).toLocaleString('id-ID');
    const display = globalThis.window?.AdasiPreferences?.displayNumber;
    return typeof display === 'function' ? display(text, 'indonesian') : text;
}

let pageStrings = null;

/** Chart copy delivered once per page by <x-ui.chart> (outside the 16 KiB global dictionary). */
export function chartStrings() {
    if (pageStrings) return pageStrings;
    let local = null;
    try {
        const node = globalThis.document?.querySelector?.('script[data-adasi-chart-i18n]');
        if (node) local = JSON.parse(node.textContent) || {};
    } catch (error) {
        local = null;
    }
    if (!local) return STRINGS;
    pageStrings = { ...STRINGS, ...local };
    return pageStrings;
}

export function chartText(key, replacements = {}) {
    const template = chartStrings()[key] ?? key;
    // One pass: ':max' never eats ':max_label', and business text in a value is never re-scanned.
    return String(template).replace(/:([a-z_]+)/g, (match, name) => (
        Object.prototype.hasOwnProperty.call(replacements, name) ? String(replacements[name]) : match
    ));
}

/**
 * Creates a vertical bar gradient (top solid highlight to bottom translucent tint)
 */
export function createBarGradient(ctx, baseColor = '#1F5FA6', topAlpha = 0.95, bottomAlpha = 0.4, height = 280) {
    if (!ctx) return baseColor;
    const context = ctx.canvas ? ctx.canvas.getContext('2d') : ctx;
    if (!context || typeof context.createLinearGradient !== 'function') return baseColor;

    const chartHeight = ctx.chart ? ctx.chart.chartArea?.bottom - ctx.chart.chartArea?.top : height;
    const gradient = context.createLinearGradient(0, 0, 0, chartHeight || height);
    gradient.addColorStop(0, hexToRgba(baseColor, topAlpha));
    gradient.addColorStop(1, hexToRgba(baseColor, bottomAlpha));

    return gradient;
}

/**
 * Creates an area glow gradient for line charts (under-line fill)
 */
export function createAreaGradient(ctx, baseColor = '#1F5FA6', topAlpha = 0.22, bottomAlpha = 0.01, height = 280) {
    if (!ctx) return 'transparent';
    const context = ctx.canvas ? ctx.canvas.getContext('2d') : ctx;
    if (!context || typeof context.createLinearGradient !== 'function') return 'transparent';

    const chartHeight = ctx.chart ? ctx.chart.chartArea?.bottom - ctx.chart.chartArea?.top : height;
    const gradient = context.createLinearGradient(0, 0, 0, chartHeight || height);
    gradient.addColorStop(0, hexToRgba(baseColor, topAlpha));
    gradient.addColorStop(1, hexToRgba(baseColor, bottomAlpha));

    return gradient;
}

/**
 * Universal Dark Floating Pill Tooltip configuration
 */
export function getChartTooltip(customOptions = {}) {
    const colors = currentChartColors();

    return {
        enabled: true,
        backgroundColor: colors.tooltipBg,
        titleColor: colors.tooltipText,
        bodyColor: colors.tooltipText,
        titleFont: { family: 'Inter', size: 12, weight: '600' },
        bodyFont: { family: 'Inter', size: 12, weight: '400' },
        padding: { top: 8, bottom: 8, left: 12, right: 12 },
        cornerRadius: 8,
        boxPadding: 4,
        displayColors: true,
        usePointStyle: true,
        boxWidth: 8,
        boxHeight: 8,
        borderColor: colors.forced ? colors.tooltipText : TOOLTIP_BORDER,
        borderWidth: 1,
        caretPadding: 6,
        caretSize: 5,
        ...customOptions,
    };
}

/**
 * Universal Clean ERP Scale configuration
 */
export function getChartScales(options = {}) {
    const colors = currentChartColors();
    const {
        yTitle = null,
        xTitle = null,
        yMaxTicks = 5,
        yBeginAtZero = true,
        yFormat = (val) => formatChartNumber(val),
        xGrid = false,
        yGrid = true,
    } = options;

    return {
        x: {
            grid: {
                display: xGrid,
                color: colors.gridLine,
            },
            border: {
                display: false,
            },
            ticks: {
                font: TICK_FONT,
                color: colors.onSurfaceVariant,
                padding: 6,
            },
            title: xTitle ? {
                display: true,
                text: xTitle,
                font: TITLE_FONT,
                color: colors.onSurfaceVariant,
            } : { display: false },
        },
        y: {
            beginAtZero: yBeginAtZero,
            grid: {
                display: yGrid,
                color: colors.gridLine,
            },
            border: {
                display: false,
            },
            ticks: {
                maxTicksLimit: yMaxTicks,
                font: TICK_FONT,
                color: colors.onSurfaceVariant,
                padding: 8,
                callback: yFormat,
            },
            title: yTitle ? {
                display: true,
                text: yTitle,
                font: TITLE_FONT,
                color: colors.onSurfaceVariant,
            } : { display: false },
        },
    };
}

/** Legend that mirrors the marks (point shape for lines, square for bars) in 12px text. */
export function getChartLegend(custom = {}) {
    return {
        position: 'top',
        align: 'end',
        ...custom,
        labels: {
            usePointStyle: true,
            boxWidth: 10,
            boxHeight: 10,
            padding: 12,
            font: TICK_FONT,
            color: currentChartColors().onSurfaceVariant,
            ...(custom.labels || {}),
        },
    };
}

/** Draws each bar's value at its end in text ink, so values are read without the tooltip. */
export function barValueLabels(format = (value) => formatChartNumber(value)) {
    return {
        id: 'adasiBarValueLabels',
        afterDatasetsDraw(chart) {
            const colors = currentChartColors();
            const horizontal = chart.options.indexAxis === 'y';
            const { ctx } = chart;
            ctx.save();
            ctx.fillStyle = colors.onSurface;
            ctx.font = '600 12px Inter, sans-serif';
            ctx.textBaseline = horizontal ? 'middle' : 'bottom';
            ctx.textAlign = horizontal ? 'left' : 'center';
            chart.data.datasets.forEach((dataset, datasetIndex) => {
                if (!chart.isDatasetVisible(datasetIndex)) return;
                chart.getDatasetMeta(datasetIndex).data.forEach((bar, index) => {
                    const value = valueOf(dataset.data[index]);
                    if (value === null) return;
                    const text = format(value, index, datasetIndex);
                    if (horizontal) ctx.fillText(text, bar.x + 6, bar.y);
                    else ctx.fillText(text, bar.x, bar.y - 4);
                });
            });
            ctx.restore();
        },
    };
}

// ---- Text alternative ------------------------------------------------------------------------

const valueOf = (raw) => {
    const value = raw !== null && typeof raw === 'object' ? raw.y : raw;
    if (value === null || value === undefined || value === '') return null;
    const number = Number(value);
    return Number.isFinite(number) ? number : null;
};

const flatLabel = (label) => (Array.isArray(label) ? label.join(' ') : String(label ?? ''));

const a11yOptions = (chart) => chart?.config?.options?.plugins?.adasiA11y || {};

const isVisible = (chart, index) => (typeof chart.isDatasetVisible === 'function'
    ? chart.isDatasetVisible(index)
    : !chart.data.datasets[index].hidden);

const visibleDatasets = (chart) => chart.data.datasets.map((_, index) => index).filter((index) => isVisible(chart, index));

function labelFor(chart, datasetIndex, index) {
    const options = a11yOptions(chart);
    const raw = chart.data.datasets[datasetIndex]?.data?.[index];
    const labels = chart.data.labels;
    if (labels && labels.length) {
        return options.formatLabel ? options.formatLabel(labels[index], index) : flatLabel(labels[index]);
    }
    const x = raw !== null && typeof raw === 'object' ? raw.x : index;
    return options.formatLabel ? options.formatLabel(x, index) : String(x);
}

function formatValue(chart, datasetIndex, index) {
    const options = a11yOptions(chart);
    if (typeof options.valueText === 'function') return options.valueText(datasetIndex, index, chart);
    const value = valueOf(chart.data.datasets[datasetIndex].data[index]);
    if (value === null) return chartText('no_value');
    return typeof options.format === 'function' ? options.format(value, datasetIndex, chart) : formatChartNumber(value);
}

const isDistribution = (chart) => a11yOptions(chart).summary === 'distribution'
    || ['doughnut', 'pie', 'polarArea'].includes(chart.config?.type);

function distributionSummary(chart, datasetIndex) {
    const values = chart.data.datasets[datasetIndex].data.map(valueOf);
    const total = values.reduce((sum, value) => sum + (value ?? 0), 0);
    if (!total) return chartText('summary_empty');
    const largest = values.reduce((best, value, index) => ((value ?? -Infinity) > (values[best] ?? -Infinity) ? index : best), 0);

    return chartText('summary_distribution', {
        total: formatChartNumber(total),
        label: labelFor(chart, datasetIndex, largest),
        value: formatValue(chart, datasetIndex, largest),
        percent: Math.round((values[largest] / total) * 100) + '%',
    });
}

/** Text summary of what the chart shows, from the chart's own data (never from the tooltip). */
export function describeChart(chart) {
    const visible = visibleDatasets(chart);
    if (isDistribution(chart)) return visible.length ? distributionSummary(chart, visible[0]) : chartText('summary_empty');

    const parts = visible.map((datasetIndex) => {
        const dataset = chart.data.datasets[datasetIndex];
        const points = dataset.data.map((raw, index) => ({ value: valueOf(raw), index })).filter((point) => point.value !== null);
        if (!points.length) return null;
        const name = dataset.label || '';
        if (points.length === 1) {
            return chartText('summary_single', { series: name, value: formatValue(chart, datasetIndex, points[0].index), label: labelFor(chart, datasetIndex, points[0].index) });
        }
        const max = points.reduce((a, b) => (b.value > a.value ? b : a));
        const min = points.reduce((a, b) => (b.value < a.value ? b : a));
        const last = points[points.length - 1];

        return chartText('summary_series', {
            series: name,
            max: formatValue(chart, datasetIndex, max.index),
            max_label: labelFor(chart, datasetIndex, max.index),
            min: formatValue(chart, datasetIndex, min.index),
            min_label: labelFor(chart, datasetIndex, min.index),
            last: formatValue(chart, datasetIndex, last.index),
            last_label: labelFor(chart, datasetIndex, last.index),
        });
    }).filter(Boolean);

    return parts.length ? parts.join(' ') : chartText('summary_empty');
}

/** Reading stops: one per category (all series at that label), or one per point on a linear axis. */
export function chartStops(chart) {
    const visible = visibleDatasets(chart);
    const labels = chart.data.labels;
    if (labels && labels.length) {
        return labels.map((_, index) => ({ index }))
            .filter((stop) => visible.some((datasetIndex) => valueOf(chart.data.datasets[datasetIndex].data[stop.index]) !== null));
    }
    const stops = [];
    visible.forEach((datasetIndex) => {
        chart.data.datasets[datasetIndex].data.forEach((raw, index) => {
            if (valueOf(raw) === null) return;
            stops.push({ datasetIndex, index, x: raw !== null && typeof raw === 'object' ? Number(raw.x) : index });
        });
    });

    return stops.sort((a, b) => a.x - b.x || a.datasetIndex - b.datasetIndex);
}

function stopElements(chart, stop) {
    if (stop.datasetIndex !== undefined) return [{ datasetIndex: stop.datasetIndex, index: stop.index }];

    return visibleDatasets(chart)
        .filter((datasetIndex) => valueOf(chart.data.datasets[datasetIndex].data[stop.index]) !== null)
        .map((datasetIndex) => ({ datasetIndex, index: stop.index }));
}

function describeStop(chart, stop) {
    const datasets = stop.datasetIndex !== undefined ? [stop.datasetIndex] : visibleDatasets(chart);
    const label = labelFor(chart, datasets[0], stop.index);
    const values = datasets.map((datasetIndex) => {
        const name = chart.data.datasets[datasetIndex].label;
        const value = formatValue(chart, datasetIndex, stop.index);
        if (isDistribution(chart)) {
            const total = chart.data.datasets[datasetIndex].data.reduce((sum, raw) => sum + (valueOf(raw) ?? 0), 0);
            const share = total ? Math.round((valueOf(chart.data.datasets[datasetIndex].data[stop.index]) / total) * 100) + '%' : '';
            return value + (share ? ' (' + share + ')' : '');
        }
        return name ? chartText('series_value', { series: name, value }) : value;
    });

    return chartText('point', { label, values: values.join('; ') });
}

function figureOf(canvas) {
    return canvas?.closest?.('figure') || null;
}

function liveRegion(canvas) {
    const existing = figureOf(canvas)?.querySelector('[data-adasi-chart-live]');
    if (existing) return existing;
    const doc = globalThis.document;
    if (!doc?.createElement || !canvas.parentElement) return null;
    const region = doc.createElement('span');
    region.setAttribute('aria-live', 'polite');
    region.setAttribute('data-adasi-chart-live', '');
    region.className = 'tw:sr-only';
    canvas.parentElement.appendChild(region);
    return region;
}

function writeSummary(chart) {
    const options = a11yOptions(chart);
    if (options.summary === false || !chart?.canvas) return;
    const target = figureOf(chart.canvas)?.querySelector('[data-adasi-chart-summary]');
    if (!target || target.getAttribute('data-adasi-chart-summary') !== 'auto') return;
    target.textContent = describeChart(chart);
}

/**
 * Keyboard readout for one chart (idempotent). The canvas stays role="img" so screen readers keep
 * their own arrow keys in browse mode; they get the summary and the data table instead. Sighted
 * keyboard users get the tooltip plus a polite announcement.
 */
export function attachChartA11y(chart) {
    const canvas = chart?.canvas;
    if (!canvas || chart.$adasiA11y || a11yOptions(chart).keyboard === false) return;
    if (!canvas.hasAttribute('tabindex')) canvas.setAttribute('tabindex', '0');
    if (!canvas.hasAttribute('role')) canvas.setAttribute('role', 'img');
    canvas.setAttribute('data-adasi-chart-focusable', '');

    const state = { position: -1 };
    const activate = (stop) => {
        const elements = stopElements(chart, stop);
        const anchor = chart.getDatasetMeta(elements[0].datasetIndex)?.data?.[elements[0].index];
        chart.setActiveElements(elements);
        chart.tooltip?.setActiveElements?.(elements, { x: anchor?.x ?? 0, y: anchor?.y ?? 0 });
        chart.update('none');
    };
    const clear = () => {
        chart.setActiveElements([]);
        chart.tooltip?.setActiveElements?.([], { x: 0, y: 0 });
        chart.update('none');
    };
    const onKeydown = (event) => {
        if (!['ArrowRight', 'ArrowDown', 'ArrowLeft', 'ArrowUp', 'Home', 'End', 'Escape'].includes(event.key)) return;
        const region = liveRegion(canvas);
        if (event.key === 'Escape') {
            if (state.position < 0) return;
            event.preventDefault();
            state.position = -1;
            clear();
            if (region) region.textContent = '';
            return;
        }
        const stops = chartStops(chart);
        if (!stops.length) return;
        event.preventDefault();
        const last = stops.length - 1;
        const current = Math.min(state.position, last);
        if (event.key === 'Home') state.position = 0;
        else if (event.key === 'End') state.position = last;
        else if (event.key === 'ArrowRight' || event.key === 'ArrowDown') state.position = current < 0 ? 0 : Math.min(last, current + 1);
        else state.position = current < 0 ? 0 : Math.max(0, current - 1);
        const stop = stops[state.position];
        activate(stop);
        if (region) region.textContent = describeStop(chart, stop);
    };
    const onBlur = () => {
        if (state.position >= 0) clear();
    };

    canvas.addEventListener('keydown', onKeydown);
    canvas.addEventListener('blur', onBlur);
    chart.$adasiA11y = {
        detach() {
            canvas.removeEventListener('keydown', onKeydown);
            canvas.removeEventListener('blur', onBlur);
        },
    };
}

export const a11yPlugin = {
    id: 'adasiA11y',
    afterInit(chart) {
        attachChartA11y(chart);
    },
    beforeUpdate(chart) {
        if (prefersReducedMotion() && chart?.config?.options) chart.config.options.animation = false;
    },
    afterUpdate(chart) {
        writeSummary(chart);
    },
    afterDestroy(chart) {
        chart.$adasiA11y?.detach();
        chart.$adasiA11y = null;
    },
};

/**
 * Configure global Chart.js defaults if Chart instance exists
 */
export function applyGlobalChartDefaults(ChartInstance = window.Chart) {
    if (!ChartInstance?.defaults) return;

    const colors = currentChartColors();
    ChartInstance.defaults.font.family = 'Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
    ChartInstance.defaults.font.size = 12;
    ChartInstance.defaults.color = colors.onSurfaceVariant;
    ChartInstance.defaults.responsive = true;
    ChartInstance.defaults.maintainAspectRatio = false;
    ChartInstance.defaults.plugins.tooltip = {
        ...ChartInstance.defaults.plugins.tooltip,
        ...getChartTooltip(),
    };
    if (prefersReducedMotion()) ChartInstance.defaults.animation = false;
    if (typeof ChartInstance.register === 'function' && !ChartInstance.$adasiA11yRegistered) {
        ChartInstance.register(a11yPlugin);
        ChartInstance.$adasiA11yRegistered = true;
    }
}

export function refreshChartTheme(ChartInstance = window.Chart) {
    cachedColors = null;
    patternCache.clear();
    applyGlobalChartDefaults(ChartInstance);

    if (!ChartInstance?.instances) return;

    const colors = currentChartColors();
    Object.values(ChartInstance.instances).forEach((chart) => {
        if (!chart?.options) return;
        // Charts built before this module loaded still get the keyboard readout and summary.
        attachChartA11y(chart);

        Object.values(chart.options.scales || {}).forEach((scale) => {
            if (scale.grid) scale.grid.color = colors.gridLine;
            if (scale.border) scale.border.color = colors.outline;
            if (scale.ticks) scale.ticks.color = colors.onSurfaceVariant;
            if (scale.title) scale.title.color = colors.onSurfaceVariant;
        });

        const plugins = chart.options.plugins || (chart.options.plugins = {});
        if (plugins.legend) {
            plugins.legend.labels = { ...plugins.legend.labels, color: colors.onSurfaceVariant };
        }
        if (plugins.title) plugins.title.color = colors.onSurface;
        if (plugins.tooltip) {
            plugins.tooltip = {
                ...plugins.tooltip,
                backgroundColor: colors.tooltipBg,
                titleColor: colors.tooltipText,
                bodyColor: colors.tooltipText,
                borderColor: colors.forced ? colors.tooltipText : TOOLTIP_BORDER,
            };
        }

        chart.update('none');
        writeSummary(chart);
    });
}

// Attach to window object for global availability
const AdasiChart = {
    getColors: currentChartColors,
    getSeriesPalette,
    getSeriesStyle,
    seriesFill,
    color: themeColor,
    hatch: createHatchPattern,
    hexToRgba,
    createBarGradient,
    createAreaGradient,
    getTooltip: getChartTooltip,
    getScales: getChartScales,
    getLegend: getChartLegend,
    valueLabels: barValueLabels,
    formatNumber: formatChartNumber,
    t: chartText,
    describe: describeChart,
    applyDefaults: applyGlobalChartDefaults,
    refreshTheme: refreshChartTheme,
    reducedMotion: prefersReducedMotion,
};

window.AdasiChart = AdasiChart;
export default AdasiChart;

window.addEventListener('adasi:theme-change', () => refreshChartTheme());

window.addEventListener('adasi:accent-change', () => refreshChartTheme());

['(forced-colors: active)', '(prefers-reduced-motion: reduce)'].forEach((query) => {
    try {
        window.matchMedia?.(query)?.addEventListener?.('change', () => refreshChartTheme());
    } catch (error) {
        // Older engines without MediaQueryList events keep the state read at load.
    }
});

// Page scripts can initialize charts before the deferred Vite module is ready.
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => refreshChartTheme(), { once: true });
} else {
    refreshChartTheme();
}
