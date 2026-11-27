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
const STROKE_ONLY_STYLES = ['cross', 'crossRot', 'star', 'line', 'dash'];
const AREA_ALPHA = 0.08;
const DIM_ALPHA = 0.35;
const LINE_DIM_ALPHA = 0.2;
const RESTING_RADIUS = 3;
const LEGEND_BOTTOM_GAP = 16;
const COMPACT_UNITS = [
    [1e12, 'compact_trillion'],
    [1e9, 'compact_billion'],
    [1e6, 'compact_million'],
];
// Font objects carry no family, so Chart.js falls back to defaults.font.family (the portal token).
const TICK_FONT = { size: 12, weight: '500' };
const TITLE_FONT = { size: 12, weight: '600' };
const FALLBACK_FONT_FAMILY = '"Source Sans Pro", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif';

export const BAR_STYLE = Object.freeze({
    borderWidth: 0,
    borderRadius: 6,
    borderSkipped: 'start',
    minBarLength: 2,
    maxBarThickness: 32,
    categoryPercentage: 0.72,
    barPercentage: 0.84,
});

export const LINE_STYLE = Object.freeze({
    borderWidth: 2,
    borderCapStyle: 'round',
    borderJoinStyle: 'round',
    tension: 0,
    spanGaps: false,
    pointBorderWidth: 2,
    pointHoverRadius: 5,
    pointHoverBorderWidth: 2,
    pointHitRadius: 12,
});

const STRINGS = {
    point: ':label: :values',
    series_value: ':series :value',
    no_value: 'no data',
    summary_series: ':series: highest :max (:max_label), lowest :min (:min_label), latest :last (:last_label).',
    summary_single: ':series: :value (:label).',
    summary_distribution: 'Total :total. Largest: :label :value (:percent).',
    summary_empty: 'No data to display.',
    compact_million: ':value M',
    compact_billion: ':value B',
    compact_trillion: ':value T',
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

export function chartFontFamily() {
    try {
        const value = getComputedStyle(document.documentElement).getPropertyValue('--ui-font-sans').trim();
        return value || FALLBACK_FONT_FAMILY;
    } catch (error) {
        return FALLBACK_FONT_FAMILY;
    }
}

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

    const surface = getVar('--md-surface', '#FFFFFF');
    const onSurface = getVar('--md-on-surface', '#1A202C');
    const onSurfaceVariant = getVar('--md-on-surface-variant', '#64748B');
    const outlineVariant = getVar('--md-outline-variant', '#E2E8F0');

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
        surface,
        surfaceContainer: getVar('--md-surface-container', '#F0F4F9'),
        surfaceContainerLow: getVar('--md-surface-container-low', '#F8FAFC'),
        onSurface,
        onSurfaceVariant,
        outline: getVar('--md-outline', '#CBD5E1'),
        outlineVariant,
        gridLine: getVar('--md-chart-grid', 'rgba(200, 208, 218, 0.6)'),
        tooltipBg: getVar('--ui-menu-bg', surface),
        tooltipText: onSurface,
        tooltipMuted: onSurfaceVariant,
        tooltipBorder: outlineVariant,
        tooltipShadow: getVar('--md-chart-tooltip-shadow', 'rgba(15, 23, 42, 0.12)'),
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
                tooltipBorder: text, tooltipShadow: 'transparent',
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

/** Compact number formatting for large axis ticks (>= 1e6); tooltip, summary and table keep full values. */
export function formatCompact(value, reference = value) {
    if (value === null || value === undefined || value === '') return '-';
    const number = Number(value);
    if (!Number.isFinite(number)) return '-';
    const unit = COMPACT_UNITS.find(([size]) => Math.abs(Number(reference) || 0) >= size);
    if (!unit || number === 0) return formatChartNumber(number);
    const text = (number / unit[0]).toLocaleString('id-ID', { maximumFractionDigits: 1 });
    const display = globalThis.window?.AdasiPreferences?.displayNumber;
    return chartText(unit[1], { value: typeof display === 'function' ? display(text, 'indonesian') : text });
}

/** Axis tick callback bound to a scale so every tick shares one unit derived from the axis range. */
export function compactTicks(prefix = '') {
    return function compactTick(value) {
        const reference = Math.max(Math.abs(Number(this?.max ?? value) || 0), Math.abs(Number(this?.min ?? 0) || 0));
        return prefix + formatCompact(value, reference);
    };
}

const valueOf = (raw) => {
    const value = raw !== null && typeof raw === 'object' ? raw.y : raw;
    if (value === null || value === undefined || value === '') return null;
    const number = Number(value);
    return Number.isFinite(number) ? number : null;
};

function dimmableFill(base, dimMode = 'category') {
    return (ctx) => {
        const value = typeof base === 'function' ? base(ctx) : base;
        const active = ctx?.chart?.$adasiActiveIndex;
        if (active === null || active === undefined || ctx?.type !== 'data' || typeof value !== 'string' || currentChartColors().forced) {
            return value;
        }
        const activeDataset = ctx?.chart?.$adasiActiveDatasetIndex;
        const perBar = (dimMode === 'bar' || ctx?.dataset?.adasiDimMode === 'bar')
            && activeDataset !== null
            && activeDataset !== undefined;
        const isActive = perBar
            ? (ctx.dataIndex === active && ctx.datasetIndex === activeDataset)
            : ctx.dataIndex === active;
        if (isActive) {
            return value;
        }
        return hexToRgba(value, DIM_ALPHA);
    };
}

function withAlpha(color, alpha) {
    return (ctx) => {
        if (currentChartColors().forced) return 'transparent';
        const resolved = typeof color === 'function' ? color(ctx) : color;
        return hexToRgba(resolved, alpha);
    };
}

function restingPointRadius(multi) {
    return (ctx) => {
        if (ctx?.type !== 'data') return RESTING_RADIUS;
        const data = ctx.dataset?.data || [];
        const index = ctx.dataIndex;
        if (valueOf(data[index]) === null) return 0;
        const isolated = valueOf(data[index - 1]) === null && valueOf(data[index + 1]) === null;
        if (multi || isolated) return RESTING_RADIUS;
        const plotted = data.filter((item) => valueOf(item) !== null).length;
        return plotted < 3 ? RESTING_RADIUS + 1 : 0;
    };
}

/** Shared bar dataset preset with rounded tops, token colors, and category/bar hover dimming. */
export function barDataset({ index = 0, color = null, fill = null, dim = true, dimMode = 'category', ...overrides } = {}) {
    const stroke = color ?? themeColor('series-' + (index + 1));
    const base = fill ?? color ?? seriesFill(index);
    return {
        ...BAR_STYLE,
        backgroundColor: dim ? dimmableFill(base, dimMode) : base,
        borderColor: stroke,
        adasiDim: dim,
        adasiDimMode: dimMode,
        ...overrides,
    };
}

function dimmableLineColor(base, dimAlpha = LINE_DIM_ALPHA) {
    return (ctx) => {
        const value = typeof base === 'function' ? base(ctx) : base;
        const activeDataset = ctx?.chart?.$adasiActiveDatasetIndex;
        if (activeDataset === null || activeDataset === undefined || typeof value !== 'string' || currentChartColors().forced) {
            return value;
        }
        if (ctx?.datasetIndex === activeDataset) {
            return value;
        }
        return hexToRgba(value, dimAlpha);
    };
}

/** Shared line dataset preset with round caps, non-color cues, optional area tint, and opt-in hover series spotlight. */
export function lineDataset({
    index = 0,
    color = null,
    pointStyle = null,
    borderDash = null,
    multi = true,
    area = false,
    dim = false,
    borderWidth = LINE_STYLE.borderWidth,
    hoverBorderWidth = null,
    ...overrides
} = {}) {
    const style = getSeriesStyle(index);
    const stroke = color ?? themeColor('series-' + (index + 1));
    const lineStroke = dim ? dimmableLineColor(stroke) : stroke;
    const widthProp = (dim && hoverBorderWidth !== null)
        ? (ctx) => {
            const activeDataset = ctx?.chart?.$adasiActiveDatasetIndex;
            return activeDataset !== null && activeDataset !== undefined && ctx?.datasetIndex === activeDataset
                ? hoverBorderWidth
                : borderWidth;
        }
        : borderWidth;
    return {
        ...LINE_STYLE,
        borderColor: lineStroke,
        borderWidth: widthProp,
        backgroundColor: area ? withAlpha(stroke, AREA_ALPHA) : lineStroke,
        pointBackgroundColor: lineStroke,
        pointHoverBackgroundColor: stroke,
        pointBorderColor: themeColor('surface'),
        pointHoverBorderColor: themeColor('surface'),
        pointStyle: pointStyle ?? style.pointStyle,
        borderDash: borderDash ?? (multi ? style.borderDash : []),
        pointRadius: restingPointRadius(multi),
        fill: area ? 'start' : false,
        ...(dim ? { adasiDim: true } : {}),
        ...overrides,
    };
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

const nativeTooltipCallbacks = new WeakMap();

function getNativeTooltipCallbacks(ChartInstance) {
    const callbacks = ChartInstance?.defaults?.plugins?.tooltip?.callbacks;
    if (!callbacks || typeof callbacks !== 'object' || Object.keys(callbacks).length === 0) {
        return null;
    }
    if (!nativeTooltipCallbacks.has(ChartInstance)) {
        nativeTooltipCallbacks.set(ChartInstance, { ...callbacks });
    }
    return nativeTooltipCallbacks.get(ChartInstance);
}

function defaultTooltipLabelColor(ctx) {
    const raw = ctx?.dataset?.borderColor ?? ctx?.dataset?.backgroundColor;
    const evaluated = typeof raw === 'function' ? raw(ctx) : raw;
    const picked = Array.isArray(evaluated) ? (evaluated[ctx?.dataIndex] ?? evaluated[0]) : evaluated;
    const resolved = typeof picked === 'string' ? picked : currentChartColors().primary;
    const pointStyle = ctx?.dataset?.pointStyle;

    return {
        borderColor: resolved,
        backgroundColor: resolved,
        borderWidth: STROKE_ONLY_STYLES.includes(pointStyle) ? 2 : 0,
        borderRadius: 2,
    };
}

/**
 * Theme-aware surface tooltip configuration (light card in light mode, tonal elevated surface in dark mode).
 */
export function getChartTooltip(customOptions = {}) {
    const colors = currentChartColors();
    const { callbacks: customCallbacks, ...rest } = customOptions;
    const globalChart = globalThis.window?.Chart;
    const inheritedCallbacks = getNativeTooltipCallbacks(globalChart)
        || globalChart?.defaults?.plugins?.tooltip?.callbacks
        || {};

    return {
        enabled: true,
        backgroundColor: colors.tooltipBg,
        titleColor: colors.tooltipText,
        bodyColor: colors.tooltipText,
        footerColor: colors.tooltipMuted,
        titleFont: { size: 12, weight: '600' },
        bodyFont: { size: 12, weight: '400' },
        titleMarginBottom: 6,
        bodySpacing: 4,
        padding: { top: 10, bottom: 10, left: 12, right: 12 },
        cornerRadius: 8,
        boxPadding: 6,
        displayColors: true,
        usePointStyle: true,
        boxWidth: 8,
        boxHeight: 8,
        borderColor: colors.tooltipBorder,
        borderWidth: 1,
        caretPadding: 8,
        caretSize: 0,
        ...rest,
        callbacks: {
            ...inheritedCallbacks,
            labelColor: defaultTooltipLabelColor,
            ...(customCallbacks || {}),
        },
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
        yGrace = null,
        yFormat = (val) => formatChartNumber(val),
        xGrid = false,
        xOffset = false,
        yGrid = true,
        xMaxRotation = 0,
        xAutoSkip = true,
    } = options;

    return {
        x: {
            ...(xOffset ? { offset: true } : {}),
            grid: {
                display: xGrid,
                drawTicks: false,
                color: colors.gridLine,
            },
            border: {
                display: false,
            },
            ticks: {
                font: TICK_FONT,
                color: colors.onSurfaceVariant,
                padding: 8,
                maxRotation: xMaxRotation,
                autoSkip: xAutoSkip,
                autoSkipPadding: 12,
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
            ...(yGrace !== null && yGrace !== undefined ? { grace: yGrace } : {}),
            ...(!yBeginAtZero ? {
                afterDataLimits(scale) {
                    if (Number.isFinite(scale?.min) && scale.min < 0) {
                        scale.min = 0;
                    }
                },
            } : {}),
            grid: {
                display: yGrid,
                drawTicks: false,
                color: (ctx) => (ctx?.tick?.value === 0 ? currentChartColors().outline : currentChartColors().gridLine),
            },
            border: {
                display: false,
            },
            ticks: {
                maxTicksLimit: yMaxTicks,
                font: TICK_FONT,
                color: colors.onSurfaceVariant,
                padding: 10,
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

function resolveUndimmedBarFill(dataset, datasetIndex) {
    const raw = dataset?.backgroundColor;
    if (typeof raw === 'function') {
        return raw({ type: 'dataset', dataset, datasetIndex, dataIndex: 0, chart: { $adasiActiveIndex: null, $adasiActiveDatasetIndex: null } });
    }
    if (Array.isArray(raw)) {
        return raw[0];
    }
    return raw;
}

function resolveUndimmedStroke(dataset, datasetIndex) {
    const raw = dataset?.borderColor;
    if (typeof raw === 'function') {
        return raw({ type: 'dataset', dataset, datasetIndex, dataIndex: 0, chart: { $adasiActiveIndex: null, $adasiActiveDatasetIndex: null } });
    }
    if (Array.isArray(raw)) {
        return raw[0];
    }
    return raw;
}

function defaultLegendLabels(chart) {
    const base = globalThis.window?.Chart?.defaults?.plugins?.legend?.labels?.generateLabels?.(chart) ?? [];
    return base.map((item) => {
        const dataset = chart?.data?.datasets?.[item.datasetIndex] || {};
        const isLine = (dataset.type || chart?.config?.type) === 'line';
        const style = item.pointStyle ?? dataset.pointStyle;
        const resolvedLineStroke = isLine ? resolveUndimmedStroke(dataset, item.datasetIndex) : null;
        const stroke = (typeof resolvedLineStroke === 'string' && resolvedLineStroke.trim() !== '')
            ? resolvedLineStroke
            : (item.strokeStyle || (typeof dataset.borderColor === 'string' ? dataset.borderColor : item.fillStyle));
        const barFill = !isLine && dataset.adasiDim
            ? (resolveUndimmedBarFill(dataset, item.datasetIndex) || item.fillStyle)
            : item.fillStyle;
        return {
            ...item,
            fillStyle: isLine ? stroke : barFill,
            strokeStyle: isLine ? stroke : item.strokeStyle,
            lineWidth: STROKE_ONLY_STYLES.includes(style) ? 2 : item.lineWidth,
            lineDash: [],
        };
    });
}

/** Legend that mirrors the marks (point shape for lines, square for bars) in 12px text. */
export function getChartLegend(custom = {}) {
    return {
        position: 'top',
        align: 'start',
        ...custom,
        labels: {
            usePointStyle: true,
            boxWidth: 8,
            boxHeight: 8,
            padding: 20,
            font: TICK_FONT,
            color: currentChartColors().onSurfaceVariant,
            generateLabels: defaultLegendLabels,
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
            ctx.font = `600 12px ${chartFontFamily()}`;
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

/** Reading stops: one per category (all series at that label), per individual bar when opted-in, or per point on a linear axis. */
export function chartStops(chart) {
    const visible = visibleDatasets(chart);
    const labels = chart.data.labels;
    if (labels && labels.length) {
        if (a11yOptions(chart).perBarStops === true) {
            const perBarStops = [];
            labels.forEach((_, index) => {
                visible.forEach((datasetIndex) => {
                    if (valueOf(chart.data.datasets[datasetIndex].data[index]) !== null) {
                        perBarStops.push({ datasetIndex, index });
                    }
                });
            });
            return perBarStops;
        }

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
    const ensureAnchorVisible = (anchor) => {
        const scrollEl = canvas.closest?.('[data-adasi-chart-scroll]');
        if (!scrollEl || !anchor || !Number.isFinite(anchor.x)) return;
        const viewWidth = scrollEl.clientWidth || 0;
        if (!viewWidth || scrollEl.scrollWidth <= viewWidth) return;
        const margin = 64;
        const currentLeft = scrollEl.scrollLeft || 0;
        if (anchor.x - margin < currentLeft) {
            scrollEl.scrollLeft = Math.max(0, anchor.x - margin);
        } else if (anchor.x + margin > currentLeft + viewWidth) {
            scrollEl.scrollLeft = Math.max(0, anchor.x + margin - viewWidth);
        }
    };
    const activate = (stop) => {
        const elements = stopElements(chart, stop);
        const anchor = chart.getDatasetMeta(elements[0].datasetIndex)?.data?.[elements[0].index];
        ensureAnchorVisible(anchor);
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

const activeIndexOf = (chart) => chart?.getActiveElements?.()?.[0]?.index ?? null;
const activeDatasetIndexOf = (chart) => chart?.getActiveElements?.()?.[0]?.datasetIndex ?? null;

export const hoverFocusPlugin = {
    id: 'adasiHoverFocus',
    beforeUpdate(chart) {
        chart.$adasiActiveIndex = activeIndexOf(chart);
        chart.$adasiActiveDatasetIndex = activeDatasetIndexOf(chart);
    },
    afterEvent(chart, args) {
        if (args?.replay) return;
        const hasDim = chart?.data?.datasets?.some?.((dataset) => dataset?.adasiDim);
        if (!hasDim) return;
        const nextIndex = activeIndexOf(chart);
        const nextDataset = activeDatasetIndexOf(chart);
        if (nextIndex !== chart.$adasiActiveIndex || nextDataset !== chart.$adasiActiveDatasetIndex) {
            chart.$adasiActiveIndex = nextIndex;
            chart.$adasiActiveDatasetIndex = nextDataset;
            chart.update('none');
        }
    },
    beforeDatasetsDraw(chart) {
        if (chart?.config?.type !== 'line') return;
        const active = chart.getActiveElements?.();
        if (!active?.length) return;
        const first = active[0];
        const x = first?.element?.x ?? chart.getDatasetMeta?.(first.datasetIndex)?.data?.[first.index]?.x;
        const area = chart.chartArea;
        if (!Number.isFinite(x) || !area || !chart.ctx) return;
        const { ctx } = chart;
        ctx.save();
        ctx.strokeStyle = currentChartColors().outline;
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(x, area.top);
        ctx.lineTo(x, area.bottom);
        ctx.stroke();
        ctx.restore();
    },
};

export const legendSpacingPlugin = {
    id: 'adasiLegendSpacing',
    beforeLayout(chart) {
        const legend = chart?.legend;
        if (!legend || typeof legend.fit !== 'function' || legend.$adasiSpaced) return;
        const originalFit = legend.fit;
        legend.fit = function fitWithBottomGap() {
            originalFit.call(this);
            if (this.options?.display !== false && this.position === 'top' && this.height > 0) {
                this.height += LEGEND_BOTTOM_GAP;
            }
        };
        legend.$adasiSpaced = true;
    },
};

export const tooltipShadowPlugin = {
    id: 'adasiTooltipShadow',
    beforeTooltipDraw(chart, args) {
        const tooltip = args?.tooltip;
        const tooltipOpts = tooltip?.options || chart?.options?.plugins?.tooltip;
        const colors = currentChartColors();
        if (
            !tooltip?.opacity
            || tooltipOpts?.enabled === false
            || typeof tooltipOpts?.external === 'function'
            || colors.forced
            || !chart?.ctx
        ) return;
        if (!Number.isFinite(tooltip.x) || !Number.isFinite(tooltip.y) || !(tooltip.width > 0) || !(tooltip.height > 0)) return;
        const { ctx } = chart;
        ctx.save();
        ctx.globalAlpha = tooltip.opacity;
        ctx.fillStyle = colors.tooltipBg;
        ctx.shadowColor = colors.tooltipShadow;
        ctx.shadowBlur = 16;
        ctx.shadowOffsetX = 0;
        ctx.shadowOffsetY = 4;
        ctx.beginPath();
        if (typeof ctx.roundRect === 'function') {
            ctx.roundRect(tooltip.x, tooltip.y, tooltip.width, tooltip.height, 8);
        } else {
            ctx.rect(tooltip.x, tooltip.y, tooltip.width, tooltip.height);
        }
        ctx.fill();
        ctx.restore();
    },
};

/**
 * Configure global Chart.js defaults if Chart instance exists
 */
export function applyGlobalChartDefaults(ChartInstance = window.Chart) {
    if (!ChartInstance?.defaults) return;

    const colors = currentChartColors();
    const baseCallbacks = getNativeTooltipCallbacks(ChartInstance)
        || ChartInstance.defaults.plugins?.tooltip?.callbacks
        || {};
    ChartInstance.defaults.font.family = chartFontFamily();
    ChartInstance.defaults.font.size = 12;
    ChartInstance.defaults.color = colors.onSurfaceVariant;
    ChartInstance.defaults.responsive = true;
    ChartInstance.defaults.maintainAspectRatio = false;
    ChartInstance.defaults.interaction = {
        ...(ChartInstance.defaults.interaction || {}),
        mode: 'index',
        intersect: false,
    };
    const nextTooltip = getChartTooltip();
    ChartInstance.defaults.plugins ??= {};
    ChartInstance.defaults.plugins.tooltip = {
        ...(ChartInstance.defaults.plugins.tooltip || {}),
        ...nextTooltip,
        callbacks: {
            ...baseCallbacks,
            ...(ChartInstance.defaults.plugins.tooltip?.callbacks || {}),
            ...nextTooltip.callbacks,
        },
    };
    if (prefersReducedMotion()) {
        ChartInstance.defaults.animation = false;
    } else {
        if (ChartInstance.defaults.animation && typeof ChartInstance.defaults.animation === 'object') {
            ChartInstance.defaults.animation.duration = 450;
            ChartInstance.defaults.animation.easing = 'easeOutCubic';
        }
        if (ChartInstance.defaults.transitions?.active?.animation && typeof ChartInstance.defaults.transitions.active.animation === 'object') {
            ChartInstance.defaults.transitions.active.animation.duration = 150;
        }
    }
    if (typeof ChartInstance.register === 'function' && !ChartInstance.$adasiA11yRegistered) {
        ChartInstance.register(a11yPlugin, hoverFocusPlugin, tooltipShadowPlugin, legendSpacingPlugin);
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
            if (scale.grid && typeof scale.grid.color !== 'function') scale.grid.color = colors.gridLine;
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
                footerColor: colors.tooltipMuted,
                borderColor: colors.tooltipBorder,
            };
        }

        if (chart?.config?.type === 'bar' && chart.canvas?.closest?.('[data-adasi-chart-scroll]')) {
            fitChartScrollWidth(chart.canvas, {
                categories: chart.data?.labels?.length || 0,
                series: chart.data?.datasets?.length || 0,
            });
        }
        chart.update('none');
        writeSummary(chart);
    });
}

/**
 * Expand a scrollable chart's inner plot width when category count exceeds `threshold`,
 * keeping bars legible and enabling horizontal scroll without squeezing labels.
 */
export function fitChartScrollWidth(canvas, {
    categories = 0,
    series = 1,
    threshold = 8,
    minCategoryWidth = 96,
    perSeriesWidth = 30,
    padding = 36,
} = {}) {
    const plotEl = canvas?.closest?.('[data-adasi-chart-plot]');
    const scrollEl = canvas?.closest?.('[data-adasi-chart-scroll]');
    if (!plotEl || !scrollEl) return 0;

    if (!Number.isFinite(categories) || categories <= threshold) {
        plotEl.style.minWidth = '';
        scrollEl.scrollLeft = 0;
        return 0;
    }

    const perCategory = Math.max(minCategoryWidth, Math.max(1, series) * perSeriesWidth + padding);
    const targetWidth = Math.round(categories * perCategory);
    const containerWidth = scrollEl.clientWidth || 0;

    if (containerWidth > 0 && targetWidth <= containerWidth) {
        plotEl.style.minWidth = '';
        scrollEl.scrollLeft = 0;
        return 0;
    }

    plotEl.style.minWidth = `${targetWidth}px`;
    return targetWidth;
}

// Attach to window object for global availability
const AdasiChart = {
    BAR_STYLE,
    LINE_STYLE,
    getColors: currentChartColors,
    getSeriesPalette,
    getSeriesStyle,
    seriesFill,
    barDataset,
    lineDataset,
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
    formatCompact,
    compactTicks,
    t: chartText,
    describe: describeChart,
    applyDefaults: applyGlobalChartDefaults,
    refreshTheme: refreshChartTheme,
    fitScrollWidth: fitChartScrollWidth,
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

// Canvas text is painted once with whatever face is ready; repaint when the web font arrives.
const fontSet = globalThis.document?.fonts;
if (fontSet && typeof fontSet.load === 'function') {
    const family = chartFontFamily();
    Promise.all(['400', '600'].map((weight) => fontSet.load(`${weight} 12px ${family}`)))
        .then(() => refreshChartTheme())
        .catch(() => {});
}
