/**
 * ADASI Modern ERP Chart Theme Engine
 * Provides unified theme tokens, vertical gradients, dark floating pill tooltips,
 * and clean ERP scales across all Chart.js instances in ADASI Portal.
 */

export function resolveChartThemeColors() {
    const rootStyle = getComputedStyle(document.documentElement);
    const getVar = (name, fallback) => rootStyle.getPropertyValue(name).trim() || fallback;

    return {
        primary: getVar('--md-primary', '#1F5FA6'),
        primaryContainer: getVar('--md-primary-container', '#D4E3F5'),
        secondary: getVar('--md-secondary', '#476072'),
        secondaryContainer: getVar('--md-secondary-container', '#E2E8F0'),
        success: getVar('--md-success', '#1E8449'),
        successContainer: getVar('--md-success-container', '#D4EDDA'),
        error: getVar('--md-error', '#C0392B'),
        errorContainer: getVar('--md-error-container', '#FADBD8'),
        warning: getVar('--md-warning', '#D35400'),
        warningContainer: getVar('--md-warning-container', '#FDEBD0'),
        info: getVar('--md-info', '#2980B9'),
        surface: getVar('--md-surface', '#FFFFFF'),
        surfaceContainer: getVar('--md-surface-container', '#F0F4F9'),
        surfaceContainerLow: getVar('--md-surface-container-low', '#F8FAFC'),
        onSurface: getVar('--md-on-surface', '#1A202C'),
        onSurfaceVariant: getVar('--md-on-surface-variant', '#64748B'),
        outline: getVar('--md-outline', '#CBD5E1'),
        outlineVariant: getVar('--md-outline-variant', '#E2E8F0'),
        gridLine: 'rgba(226, 232, 240, 0.75)',
        tooltipBg: 'rgba(15, 23, 42, 0.94)',
        tooltipText: '#F8FAFC',
        tooltipMuted: '#94A3B8',
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
    const colors = resolveChartThemeColors();

    return {
        enabled: true,
        backgroundColor: colors.tooltipBg,
        titleColor: colors.tooltipText,
        bodyColor: colors.tooltipText,
        titleFont: { family: 'Inter', size: 12, weight: '600' },
        bodyFont: { family: 'Inter', size: 11, weight: '400' },
        padding: { top: 8, bottom: 8, left: 12, right: 12 },
        cornerRadius: 8,
        boxPadding: 4,
        displayColors: true,
        usePointStyle: true,
        boxWidth: 8,
        boxHeight: 8,
        borderColor: 'rgba(255, 255, 255, 0.08)',
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
    const colors = resolveChartThemeColors();
    const {
        yTitle = null,
        xTitle = null,
        yMaxTicks = 5,
        yBeginAtZero = true,
        yFormat = (val) => Number(val).toLocaleString('id-ID'),
        xGrid = false,
        yGrid = true,
    } = options;

    return {
        x: {
            grid: {
                display: xGrid,
                color: colors.gridLine,
                drawBorder: false,
            },
            border: {
                display: false,
            },
            ticks: {
                font: { family: 'Inter', size: 11, weight: '500' },
                color: colors.onSurfaceVariant,
                padding: 6,
            },
            title: xTitle ? {
                display: true,
                text: xTitle,
                font: { family: 'Inter', size: 11, weight: '600' },
                color: colors.onSurfaceVariant,
            } : { display: false },
        },
        y: {
            beginAtZero: yBeginAtZero,
            grid: {
                display: yGrid,
                color: colors.gridLine,
                drawBorder: false,
                borderDash: [3, 3],
            },
            border: {
                display: false,
            },
            ticks: {
                maxTicksLimit: yMaxTicks,
                font: { family: 'Inter', size: 11, weight: '500' },
                color: colors.onSurfaceVariant,
                padding: 8,
                callback: yFormat,
            },
            title: yTitle ? {
                display: true,
                text: yTitle,
                font: { family: 'Inter', size: 11, weight: '600' },
                color: colors.onSurfaceVariant,
            } : { display: false },
        },
    };
}

/**
 * Configure global Chart.js defaults if Chart instance exists
 */
export function applyGlobalChartDefaults(ChartInstance = window.Chart) {
    if (!ChartInstance?.defaults) return;

    const colors = resolveChartThemeColors();
    ChartInstance.defaults.font.family = 'Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
    ChartInstance.defaults.color = colors.onSurfaceVariant;
    ChartInstance.defaults.responsive = true;
    ChartInstance.defaults.maintainAspectRatio = false;
    ChartInstance.defaults.plugins.tooltip = {
        ...ChartInstance.defaults.plugins.tooltip,
        ...getChartTooltip(),
    };
}

// Attach to window object for global availability
const AdasiChart = {
    getColors: resolveChartThemeColors,
    hexToRgba,
    createBarGradient,
    createAreaGradient,
    getTooltip: getChartTooltip,
    getScales: getChartScales,
    applyDefaults: applyGlobalChartDefaults,
};

window.AdasiChart = AdasiChart;
export default AdasiChart;
