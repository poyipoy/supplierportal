@php
    // Lucide markup for strings built in JS. Same renderer as <x-ui.icon>.
    $comparisonIcon = static fn (string $name, string $size, string $class = ''): string => \App\View\Components\Ui\Icon::html(
        new \Illuminate\View\ComponentAttributeBag(['name' => $name, 'size' => $size, 'class' => $class]),
    );
@endphp
{{-- Chart copy for charts that are first rendered by fetch (historical results); <x-ui.chart> shares the once-key. --}}
@once('adasi-chart-i18n')
<script type="application/json" data-adasi-chart-i18n>@json(__('charts.js'))</script>
@endonce
<script>
(function() {
    window._activeComparisonChart = null;
    window._activeVsBestTable = null;
    const comparisonIcons = {
        package: @json($comparisonIcon('package', 'xs')),
        close: @json($comparisonIcon('x', 'xs')),
        chart: @json($comparisonIcon('chart-no-axes-combined', 'md')),
        chartEmpty: @json($comparisonIcon('chart-no-axes-combined', 'lg', 'tw:size-8')),
        warning: @json($comparisonIcon('triangle-alert', 'sm', 'tw:mt-0.5 tw:shrink-0 tw:text-warning')),
        sortDesc: @json($comparisonIcon('arrow-down', 'xs', 'tw:shrink-0 tw:text-current')),
    };

    // Mission 07: analytics amounts in the selected analysis currency (IDR keeps Rp).
    window.formatAnalyticMoney = function(value, currency, maxDecimals) {
        if (value === null || value === undefined || value === '') return '-';
        const code = currency || 'IDR';
        const decimals = maxDecimals ?? (code === 'IDR' ? 0 : 4);
        const number = Number(value).toLocaleString('id-ID', { minimumFractionDigits: 0, maximumFractionDigits: decimals });
        // Transcode the numeric segment only; the prefix keeps its place and spacing.
        const display = window.AdasiPreferences?.displayNumber ? window.AdasiPreferences.displayNumber(number, 'indonesian') : number;
        return (code === 'IDR' ? 'Rp ' : code + ' ') + display;
    };

    // <inter-supplier-chart> Pure config builders (tests/js/inter-supplier-chart.test.mjs).
    // A missing offer stays null (never 0, I-8); the lowest price per material carries a
    // non-color cue (star marker + "Lowest" text) and an optional % scale makes materials
    // with very different prices comparable.
    (function() {
        const isPrice = (value) => value !== null && value !== undefined && value !== '' && Number.isFinite(Number(value));

        function axisLabels(chartData) {
            const names = chartData.labels || [];
            const details = chartData.label_details || [];
            const counts = {};
            names.forEach((name) => { counts[name] = (counts[name] || 0) + 1; });

            return names.map((name, index) => (counts[name] > 1 && details[index] ? [name, details[index]] : name));
        }

        // Dataset index of the lowest price per material, or null when fewer than two offers compete.
        function lowestIndexes(priceRows, length) {
            return Array.from({ length }, (_, index) => {
                let best = null;
                let offers = 0;
                priceRows.forEach((prices, datasetIndex) => {
                    const value = prices ? prices[index] : null;
                    if (!isPrice(value)) return;
                    offers++;
                    if (best === null || Number(value) < Number(priceRows[best][index])) best = datasetIndex;
                });

                return offers > 1 ? best : null;
            });
        }

        function percentAboveLowest(priceRows, length) {
            const lowest = Array.from({ length }, (_, index) => {
                const values = priceRows.map((prices) => prices[index]).filter(isPrice).map(Number);
                return values.length ? Math.min(...values) : null;
            });

            return priceRows.map((prices) => prices.map((value, index) => (
                isPrice(value) && lowest[index] > 0 ? ((Number(value) - lowest[index]) / lowest[index]) * 100 : null
            )));
        }

        function subset(chartData, index) {
            return {
                ...chartData,
                labels: [chartData.labels[index]],
                label_details: chartData.label_details ? [chartData.label_details[index]] : [],
                datasets: chartData.datasets.map((dataset) => ({ ...dataset, data: [dataset.data[index] ?? null] })),
            };
        }

        function summary(chartData, i18n, format) {
            const prices = chartData.datasets.map((dataset) => dataset.data);
            const items = lowestIndexes(prices, chartData.labels.length)
                .map((best, index) => (best === null ? null
                    : chartData.labels[index] + ' ' + chartData.datasets[best].label + ' ' + format(prices[best][index])))
                .filter(Boolean);

            return items.length ? i18n.summary.replace(':items', items.join('; ')) : '';
        }

        function build(chartData, options) {
            const { colors, palette, i18n, format, formatPercent, mode = 'price', theme = null } = options;
            const relative = mode === 'relative';
            const length = chartData.labels.length;
            const prices = chartData.datasets.map((dataset) => dataset.data.map((value) => (isPrice(value) ? Number(value) : null)));
            const percents = percentAboveLowest(prices, length);
            const lowest = lowestIndexes(prices, length);
            const yFormat = relative ? (value) => formatPercent(value) : (value) => format(value);

            const datasets = chartData.datasets.map((dataset, index) => {
                const color = palette[index % palette.length];
                // A 7th+ supplier reuses a hue, so its bars are hatched to stay distinct (never a generated hue).
                const cycle = Math.floor(index / palette.length);
                const fill = cycle > 0 && theme && theme.hatch ? theme.hatch(color, cycle) : color;
                const style = theme && theme.barDataset
                    ? theme.barDataset({ index, color, fill, maxBarThickness: 36 })
                    : {
                        backgroundColor: fill,
                        borderColor: color,
                        borderWidth: 0,
                        borderRadius: 3,
                        borderSkipped: 'start',
                        minBarLength: 2,
                        maxBarThickness: 40,
                        categoryPercentage: 0.72,
                        barPercentage: 0.86,
                    };
                return {
                    label: dataset.label,
                    data: relative ? percents[index] : prices[index],
                    prices: prices[index],
                    percents: percents[index],
                    ...style,
                };
            });

            const scales = theme && theme.scales
                ? theme.scales({ yMaxTicks: 5, yBeginAtZero: true, yFormat, xMaxRotation: 45 })
                : { x: { grid: { display: false } }, y: { beginAtZero: true, ticks: { callback: yFormat } } };
            const tooltip = {
                mode: 'index',
                intersect: false,
                itemSort: (a, b) => (a.dataset.prices[a.dataIndex] ?? Infinity) - (b.dataset.prices[b.dataIndex] ?? Infinity),
                callbacks: {
                    label: (context) => {
                        const price = context.dataset.prices[context.dataIndex];
                        const percent = context.dataset.percents[context.dataIndex];
                        const parts = [context.dataset.label + ': ' + format(price)];
                        if (lowest[context.dataIndex] === context.datasetIndex) {
                            parts.push(i18n.lowest);
                        } else if (percent !== null && lowest[context.dataIndex] !== null) {
                            parts.push(i18n.aboveLowest.replace(':percent', formatPercent(percent)));
                        }
                        return ' ' + parts.join(' · ');
                    },
                    afterBody: (items) => {
                        if (!items.length) return [];
                        const index = items[0].dataIndex;
                        return datasets
                            .filter((dataset) => dataset.prices[index] === null)
                            .map((dataset) => dataset.label + ': ' + i18n.noOffer);
                    },
                },
            };

            return {
                lowest,
                config: {
                    type: 'bar',
                    data: { labels: axisLabels(chartData), datasets },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        animation: { duration: 250 },
                        layout: { padding: { top: 16 } },
                        interaction: { mode: 'index', intersect: false },
                        plugins: {
                            legend: {
                                display: false,
                            },
                            tooltip: theme && theme.tooltip ? theme.tooltip(tooltip) : tooltip,
                        },
                        scales,
                    },
                },
            };
        }

        // Star above the lowest visible bar of each material (drawn path, not a glyph).
        function lowestMarkerPlugin(color) {
            const star = (ctx, x, y, radius) => {
                ctx.beginPath();
                for (let point = 0; point < 10; point++) {
                    const r = point % 2 === 0 ? radius : radius * 0.45;
                    const angle = (Math.PI / 5) * point - Math.PI / 2;
                    ctx.lineTo(x + r * Math.cos(angle), y + r * Math.sin(angle));
                }
                ctx.closePath();
                ctx.fill();
            };

            return {
                id: 'adasiLowestMarker',
                afterDatasetsDraw(chart) {
                    const visible = chart.data.datasets.map((dataset, index) => (chart.isDatasetVisible(index) ? dataset.prices : []));
                    const lowest = lowestIndexes(visible, chart.data.labels.length);
                    const ctx = chart.ctx;
                    ctx.save();
                    ctx.fillStyle = color;
                    lowest.forEach((datasetIndex, index) => {
                        if (datasetIndex === null) return;
                        const bar = chart.getDatasetMeta(datasetIndex).data[index];
                        if (bar) star(ctx, bar.x, bar.y - 9, 5);
                    });
                    ctx.restore();
                },
            };
        }

        window.AdasiInterSupplierChart = { axisLabels, lowestIndexes, percentAboveLowest, subset, summary, build, lowestMarkerPlugin };
    })();
    // </inter-supplier-chart>

    // <historical-standalone-chart> Pure config builder (tests/js/historical-standalone-chart.test.mjs).
    // Supports two X-axis modes:
    //   - 'period' (default for 1 material): X = Month/Year, Series = Material, Line | Bar switch shown.
    //   - 'material' (Smart Default for 2+ materials): X = Material Name, Series = Year/Month (Grouped Bar,
    //     chronological left-to-right, capped to latest 6 periods so palette & bar thickness stay readable).
    (function() {
        const MAX_MATERIAL_AXIS_PERIODS = 6;

        function resolveChartType(periodCount, preferredType = null) {
            if (preferredType === 'line' || preferredType === 'bar') {
                return { type: preferredType, showSwitch: true };
            }

            const count = Number(periodCount || 0);
            const defaultType = Number.isFinite(count) && count >= 3 ? 'bar' : 'line';

            return {
                type: defaultType,
                showSwitch: true,
            };
        }

        function resolveAxisMode(materialCount, preferredAxisMode = null) {
            if (preferredAxisMode === 'period' || preferredAxisMode === 'material') {
                return preferredAxisMode;
            }

            return Number(materialCount || 0) >= 2 ? 'material' : 'period';
        }

        function normalizeSeries(chartData, fallbackLabel) {
            if (Array.isArray(chartData?.series) && chartData.series.length > 0) {
                return chartData.series;
            }

            return [{
                index: 0,
                label: fallbackLabel,
                pricesIdr: chartData?.pricesIdr || [],
                minIdr: chartData?.minIdr || [],
                maxIdr: chartData?.maxIdr || [],
                poCounts: [],
                totalKgs: [],
            }];
        }

        function transposeToMaterialAxis(periodLabels, seriesList, maxPeriods = MAX_MATERIAL_AXIS_PERIODS) {
            const rawPeriods = Array.isArray(periodLabels) ? periodLabels : [];
            const materials = Array.isArray(seriesList) ? seriesList : [];
            const cap = Math.max(1, Number(maxPeriods) || MAX_MATERIAL_AXIS_PERIODS);
            const totalPeriods = rawPeriods.length;
            const truncatedPeriods = totalPeriods > cap;
            const startIdx = truncatedPeriods ? totalPeriods - cap : 0;
            const activePeriods = rawPeriods.slice(startIdx);
            const labels = materials.map((mat, mIdx) => String(mat?.label || mat?.material_name || ('Material ' + (mIdx + 1))));

            const datasetsMeta = activePeriods.map((periodLabel, relIdx) => {
                const pIdx = startIdx + relIdx;
                return {
                    index: relIdx,
                    label: String(periodLabel),
                    data: materials.map((mat) => {
                        const val = mat?.pricesIdr?.[pIdx];
                        return val === null || val === undefined || val === '' ? null : Number(val);
                    }),
                    minIdr: materials.map((mat) => mat?.minIdr?.[pIdx] ?? null),
                    maxIdr: materials.map((mat) => mat?.maxIdr?.[pIdx] ?? null),
                    poCounts: materials.map((mat) => Number(mat?.poCounts?.[pIdx] || 0)),
                    totalKgs: materials.map((mat) => Number(mat?.totalKgs?.[pIdx] || 0)),
                };
            });

            return {
                labels,
                datasetsMeta,
                truncatedPeriods,
                shownPeriods: activePeriods.length,
                totalPeriods,
            };
        }

        function buildBarAppearance(idx, isMulti, theme, fallbackPalette) {
            if (theme?.barDataset) {
                return theme.barDataset({
                    index: idx,
                    dimMode: 'bar',
                    minBarLength: 6,
                    maxBarThickness: 36,
                    categoryPercentage: isMulti ? 0.74 : 0.62,
                });
            }
            const style = theme?.getSeriesStyle
                ? theme.getSeriesStyle(idx)
                : {
                    color: fallbackPalette[idx % fallbackPalette.length],
                    hatched: idx >= fallbackPalette.length,
                    pattern: Math.floor(idx / fallbackPalette.length),
                };
            const fill = theme?.seriesFill
                ? theme.seriesFill(idx)
                : (style.hatched && theme?.hatch ? theme.hatch(style.color, style.pattern || 1) : style.color);

            return {
                backgroundColor: fill,
                borderColor: style.color,
                borderWidth: 0,
                borderRadius: 6,
                borderSkipped: 'start',
                minBarLength: 6,
                maxBarThickness: 36,
                categoryPercentage: isMulti ? 0.74 : 0.62,
                barPercentage: 0.84,
                adasiDim: true,
                adasiDimMode: 'bar',
            };
        }

        function finiteNumber(value) {
            if (value === null || value === undefined || value === '') return null;
            const num = Number(value);
            return Number.isFinite(num) ? num : null;
        }

        function resolveBarSwatchColor(dataset, dataIndex, datasetIndex, fallbackPalette) {
            const raw = dataset?.borderColor ?? dataset?.backgroundColor;
            const evaluated = typeof raw === 'function'
                ? raw({ type: 'dataset', dataset, datasetIndex, dataIndex, chart: { $adasiActiveIndex: null, $adasiActiveDatasetIndex: null } })
                : raw;
            const picked = Array.isArray(evaluated) ? (evaluated[dataIndex] ?? evaluated[0]) : evaluated;
            if (typeof picked === 'string' && picked.trim() !== '') {
                return picked;
            }
            return fallbackPalette[datasetIndex % fallbackPalette.length];
        }

        function formatDeltaPercent(pct) {
            if (!Number.isFinite(pct)) return '';
            const rounded = Math.round(pct * 10) / 10;
            if (Math.abs(rounded) < 0.05) return '0%';
            const sign = rounded > 0 ? '+' : '';
            const text = Math.abs(rounded).toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
            const display = typeof window !== 'undefined' && typeof window.AdasiPreferences?.displayNumber === 'function'
                ? window.AdasiPreferences.displayNumber(text, 'indonesian')
                : text;
            return sign + (rounded < 0 ? '-' : '') + display + '%';
        }

        /**
         * Pure view-model builder for Historical Bar Chart single-bar spotlight tooltip.
         * Computes primary bar details, chronological delta vs previous period, and 1-line cluster context.
         */
        function buildBarTooltipModel(chart, datasetIndex, dataIndex, ctx = {}) {
            const datasets = chart?.data?.datasets || [];
            const labels = chart?.data?.labels || [];
            const dataset = datasets[datasetIndex];
            if (!dataset) return null;

            const value = finiteNumber(dataset.data?.[dataIndex]);
            if (value === null) return null;

            const {
                axisMode = 'period',
                isMultiSeries = datasets.length > 1,
                formatMoney = (v) => String(v ?? '-'),
                formatNumber = (v) => String(v ?? '0'),
                i18n = {},
                fallbackPalette = ['#1F5FA6', '#D95926', '#14967A', '#B88600', '#C2477A', '#6250D6'],
            } = ctx;

            const isMaterialAxis = axisMode === 'material';
            const categoryLabel = String(labels[dataIndex] ?? '');
            const datasetLabel = String(dataset.label ?? '');
            const title = isMaterialAxis ? categoryLabel : (isMultiSeries ? datasetLabel : categoryLabel);
            const periodTag = isMaterialAxis ? datasetLabel : (isMultiSeries ? categoryLabel : '');
            const swatchColor = resolveBarSwatchColor(dataset, dataIndex, datasetIndex, fallbackPalette);

            // Chronological delta vs previous non-null period for the exact same material.
            let delta = null;
            if (isMaterialAxis) {
                for (let d = datasetIndex - 1; d >= 0; d--) {
                    const prevVal = finiteNumber(datasets[d]?.data?.[dataIndex]);
                    if (prevVal !== null && prevVal > 0) {
                        const pct = ((value - prevVal) / prevVal) * 100;
                        const direction = Math.abs(pct) < 0.05 ? 'flat' : (pct > 0 ? 'up' : 'down');
                        const prevLabel = String(datasets[d]?.label ?? '');
                        delta = {
                            pct: Math.round(pct * 10) / 10,
                            direction,
                            text: formatDeltaPercent(pct),
                            vsLabel: String(i18n.vsPrev || 'vs :period').replace(':period', prevLabel),
                        };
                        break;
                    }
                }
            } else {
                for (let p = dataIndex - 1; p >= 0; p--) {
                    const prevVal = finiteNumber(dataset.data?.[p]);
                    if (prevVal !== null && prevVal > 0) {
                        const pct = ((value - prevVal) / prevVal) * 100;
                        const direction = Math.abs(pct) < 0.05 ? 'flat' : (pct > 0 ? 'up' : 'down');
                        const prevLabel = String(labels[p] ?? '');
                        delta = {
                            pct: Math.round(pct * 10) / 10,
                            direction,
                            text: formatDeltaPercent(pct),
                            vsLabel: String(i18n.vsPrev || 'vs :period').replace(':period', prevLabel),
                        };
                        break;
                    }
                }
            }

            const minVal = finiteNumber(dataset._metaMin?.[dataIndex]);
            const maxVal = finiteNumber(dataset._metaMax?.[dataIndex]);
            const poCount = Number(dataset._metaPoCounts?.[dataIndex] || 0);
            const totalKg = finiteNumber(dataset._metaTotalKgs?.[dataIndex]) ?? 0;

            const metrics = [];
            if (minVal !== null) {
                metrics.push({ label: i18n.lowest || 'Lowest', value: formatMoney(minVal), tone: 'low' });
            }
            if (maxVal !== null) {
                metrics.push({ label: i18n.highest || 'Highest', value: formatMoney(maxVal), tone: 'high' });
            }

            const basisText = poCount > 0 && i18n.historyBasis
                ? String(i18n.historyBasis).replace(':pos', String(poCount)).replace(':kg', formatNumber(totalKg))
                : '';

            // 1-line cluster context footer (only when >= 2 visible bars exist in the same X-axis category).
            const isVisible = (idx) => (typeof chart?.isDatasetVisible === 'function'
                ? chart.isDatasetVisible(idx)
                : !datasets[idx]?.hidden);
            const clusterEntries = [];
            datasets.forEach((ds, dIdx) => {
                if (!isVisible(dIdx)) return;
                const v = finiteNumber(ds?.data?.[dataIndex]);
                if (v === null) return;
                clusterEntries.push({
                    datasetIndex: dIdx,
                    label: String(ds?.label ?? ''),
                    value: v,
                });
            });

            let groupContext = null;
            if (clusterEntries.length >= 2) {
                let low = clusterEntries[0];
                let high = clusterEntries[0];
                for (let i = 1; i < clusterEntries.length; i++) {
                    if (clusterEntries[i].value < low.value) low = clusterEntries[i];
                    if (clusterEntries[i].value > high.value) high = clusterEntries[i];
                }
                const isLowest = Math.abs(value - low.value) < 1e-6;
                const isHighest = !isLowest && Math.abs(value - high.value) < 1e-6;
                groupContext = {
                    count: clusterEntries.length,
                    isLowest,
                    isHighest,
                    badgeText: isLowest
                        ? (i18n.groupLowestBadge || 'Lowest in group')
                        : (isHighest ? (i18n.groupHighestBadge || 'Highest in group') : ''),
                    lowLabel: low.label,
                    lowValue: formatMoney(low.value),
                    highLabel: high.label,
                    highValue: formatMoney(high.value),
                };
            }

            return {
                title,
                periodTag,
                swatchColor,
                averageLabel: i18n.average || 'Average',
                averageValue: formatMoney(value),
                delta,
                metrics,
                basisText,
                groupContext,
            };
        }

        /**
         * Viewport-aware, WCAG 1.4.13 compliant HTML external tooltip for Historical Bar charts.
         * Uses top-layer `popover="manual"` when supported (never clipped by scroll containers or canvas),
         * with fixed-position fallback, auto block-flip, hover grace, Escape dismissal, and clean destroy teardown.
         */
        function createBarExternalTooltipHandler(ctx) {
            const supportsPopover = typeof HTMLElement !== 'undefined' && Object.hasOwn(HTMLElement.prototype, 'popover');

            function ensureTooltipState(chart) {
                if (chart.$adasiBarTooltip) return chart.$adasiBarTooltip;

                const el = document.createElement('div');
                el.className = 'ui-chart-bar-tooltip';
                el.setAttribute('role', 'tooltip');
                el.setAttribute('aria-hidden', 'true');
                el.dataset.placement = 'top';
                if (supportsPopover) {
                    el.setAttribute('popover', 'manual');
                }
                el.hidden = true;
                document.body.appendChild(el);

                const state = {
                    el,
                    visible: false,
                    pointerInside: false,
                    hideTimer: null,
                    lastKey: '',
                };

                const hideNow = () => {
                    if (state.hideTimer) {
                        clearTimeout(state.hideTimer);
                        state.hideTimer = null;
                    }
                    if (!state.visible && el.hidden) return;
                    state.visible = false;
                    state.lastKey = '';
                    el.setAttribute('aria-hidden', 'true');
                    if (supportsPopover) {
                        try {
                            if (el.matches(':popover-open')) el.hidePopover();
                        } catch (e) {}
                    }
                    el.hidden = true;
                };

                const scheduleHide = () => {
                    if (state.hideTimer) clearTimeout(state.hideTimer);
                    state.hideTimer = setTimeout(() => {
                        if (!state.pointerInside) hideNow();
                    }, 120);
                };

                const onMouseEnter = () => {
                    state.pointerInside = true;
                    if (state.hideTimer) {
                        clearTimeout(state.hideTimer);
                        state.hideTimer = null;
                    }
                };
                const onMouseLeave = () => {
                    state.pointerInside = false;
                    scheduleHide();
                };
                const onKeyDown = (event) => {
                    if (event.key === 'Escape' && state.visible) {
                        hideNow();
                    }
                };
                const onScroll = () => {
                    if (state.visible) hideNow();
                };

                el.addEventListener('mouseenter', onMouseEnter);
                el.addEventListener('mouseleave', onMouseLeave);
                document.addEventListener('keydown', onKeyDown, true);
                window.addEventListener('scroll', onScroll, true);

                state.hideNow = hideNow;
                state.scheduleHide = scheduleHide;
                state.destroy = () => {
                    hideNow();
                    el.removeEventListener('mouseenter', onMouseEnter);
                    el.removeEventListener('mouseleave', onMouseLeave);
                    document.removeEventListener('keydown', onKeyDown, true);
                    window.removeEventListener('scroll', onScroll, true);
                    el.remove();
                    chart.$adasiBarTooltip = null;
                };

                chart.$adasiBarTooltip = state;
                return state;
            }

            function renderModelInto(el, model) {
                el.replaceChildren();

                const header = document.createElement('div');
                header.className = 'ui-chart-bar-tooltip__header';

                const titleWrap = document.createElement('div');
                titleWrap.className = 'ui-chart-bar-tooltip__title-wrap';

                const swatch = document.createElement('span');
                swatch.className = 'ui-chart-bar-tooltip__swatch';
                swatch.style.backgroundColor = model.swatchColor;
                swatch.setAttribute('aria-hidden', 'true');
                titleWrap.appendChild(swatch);

                const title = document.createElement('span');
                title.className = 'ui-chart-bar-tooltip__title';
                title.textContent = model.title;
                titleWrap.appendChild(title);
                header.appendChild(titleWrap);

                if (model.periodTag) {
                    const tag = document.createElement('span');
                    tag.className = 'ui-chart-bar-tooltip__tag';
                    tag.textContent = model.periodTag;
                    header.appendChild(tag);
                }
                el.appendChild(header);

                const primary = document.createElement('div');
                primary.className = 'ui-chart-bar-tooltip__primary';

                const avgValue = document.createElement('div');
                avgValue.className = 'ui-chart-bar-tooltip__value ui-tabular-nums';
                avgValue.textContent = model.averageValue;
                primary.appendChild(avgValue);

                if (model.delta) {
                    const deltaWrap = document.createElement('div');
                    deltaWrap.className = 'ui-chart-bar-tooltip__delta';

                    const deltaPct = document.createElement('span');
                    deltaPct.className = 'ui-chart-bar-tooltip__delta-pct ui-tabular-nums ui-chart-bar-tooltip__delta--' + model.delta.direction;
                    deltaPct.textContent = model.delta.text;
                    deltaWrap.appendChild(deltaPct);

                    const deltaVs = document.createElement('span');
                    deltaVs.className = 'ui-chart-bar-tooltip__delta-vs';
                    deltaVs.textContent = model.delta.vsLabel;
                    deltaWrap.appendChild(deltaVs);

                    primary.appendChild(deltaWrap);
                }
                el.appendChild(primary);

                if (model.metrics.length > 0) {
                    const grid = document.createElement('dl');
                    grid.className = 'ui-chart-bar-tooltip__metrics';
                    model.metrics.forEach((entry) => {
                        const row = document.createElement('div');
                        row.className = 'ui-chart-bar-tooltip__metric-row';
                        const dt = document.createElement('dt');
                        dt.textContent = entry.label;
                        const dd = document.createElement('dd');
                        dd.className = 'ui-tabular-nums';
                        dd.textContent = entry.value;
                        row.appendChild(dt);
                        row.appendChild(dd);
                        grid.appendChild(row);
                    });
                    el.appendChild(grid);
                }

                if (model.basisText) {
                    const basis = document.createElement('div');
                    basis.className = 'ui-chart-bar-tooltip__basis ui-tabular-nums';
                    basis.textContent = model.basisText;
                    el.appendChild(basis);
                }

                if (model.groupContext) {
                    const footer = document.createElement('div');
                    footer.className = 'ui-chart-bar-tooltip__footer';

                    if (model.groupContext.badgeText) {
                        const badge = document.createElement('span');
                        badge.className = 'ui-chart-bar-tooltip__rank ' + (model.groupContext.isLowest ? 'ui-chart-bar-tooltip__rank--low' : 'ui-chart-bar-tooltip__rank--high');
                        badge.textContent = model.groupContext.badgeText;
                        footer.appendChild(badge);
                    }

                    const rangeLine = document.createElement('div');
                    rangeLine.className = 'ui-chart-bar-tooltip__range ui-tabular-nums';
                    const lowLabel = ctx.i18n?.lowest || 'Lowest';
                    const highLabel = ctx.i18n?.highest || 'Highest';
                    rangeLine.textContent = lowLabel + ': ' + model.groupContext.lowLabel + ' (' + model.groupContext.lowValue + ') · ' + highLabel + ': ' + model.groupContext.highLabel + ' (' + model.groupContext.highValue + ')';
                    footer.appendChild(rangeLine);

                    el.appendChild(footer);
                }
            }

            return function externalBarTooltip({ chart, tooltip }) {
                if (typeof document === 'undefined' || !chart?.canvas) return;
                const state = ensureTooltipState(chart);
                const el = state.el;

                if (!tooltip || tooltip.opacity === 0 || !Array.isArray(tooltip.dataPoints) || tooltip.dataPoints.length === 0) {
                    state.scheduleHide();
                    return;
                }

                if (state.hideTimer) {
                    clearTimeout(state.hideTimer);
                    state.hideTimer = null;
                }

                const point = tooltip.dataPoints[0];
                const model = buildBarTooltipModel(chart, point.datasetIndex, point.dataIndex, ctx);
                if (!model) {
                    state.hideNow();
                    return;
                }

                const key = point.datasetIndex + ':' + point.dataIndex + ':' + model.averageValue;
                if (key !== state.lastKey) {
                    renderModelInto(el, model);
                    state.lastKey = key;
                }

                el.hidden = false;
                el.setAttribute('aria-hidden', 'false');
                if (supportsPopover) {
                    try {
                        if (!el.matches(':popover-open')) el.showPopover();
                    } catch (e) {}
                }
                state.visible = true;

                const canvasRect = chart.canvas.getBoundingClientRect();
                const barEl = point.element || chart.getDatasetMeta?.(point.datasetIndex)?.data?.[point.dataIndex];
                const anchorX = canvasRect.left + (Number.isFinite(barEl?.x) ? barEl.x : tooltip.caretX);
                const barTopY = canvasRect.top + (Number.isFinite(barEl?.y) ? barEl.y : tooltip.caretY);
                const barBaseY = canvasRect.top + (Number.isFinite(barEl?.base) ? barEl.base : (tooltip.caretY + 12));

                const tipRect = el.getBoundingClientRect();
                const margin = 12;
                const gap = 10;
                const vw = window.innerWidth || document.documentElement.clientWidth || 1024;
                const vh = window.innerHeight || document.documentElement.clientHeight || 768;

                // Prefer above the bar; flip below when viewport top lacks room.
                let placement = 'top';
                let top = barTopY - tipRect.height - gap;
                if (top < margin) {
                    const belowTop = Math.min(barTopY + gap + 8, barBaseY + gap);
                    if (belowTop + tipRect.height + margin <= vh) {
                        placement = 'bottom';
                        top = belowTop;
                    } else {
                        top = Math.max(margin, Math.min(vh - tipRect.height - margin, barTopY - tipRect.height / 2));
                    }
                }

                const left = Math.max(margin, Math.min(vw - tipRect.width - margin, anchorX - tipRect.width / 2));
                el.dataset.placement = placement;
                el.style.left = Math.round(left) + 'px';
                el.style.top = Math.round(top) + 'px';
            };
        }

        const barTooltipCleanupPlugin = {
            id: 'adasiHistoricalBarTooltipCleanup',
            afterDestroy(chart) {
                chart?.$adasiBarTooltip?.destroy?.();
            },
        };

        /**
         * Pure structured summary builder for the compact footer below the Historical chart.
         * Computes overall highest/lowest plus per-series breakdown (omitting chronological "latest" on Material axis).
         */
        function buildChartSummaryModel(chartOrConfig, ctx = {}) {
            const data = chartOrConfig?.data || {};
            const labels = Array.isArray(data.labels) ? data.labels : [];
            const datasets = Array.isArray(data.datasets) ? data.datasets : [];
            const isVisible = typeof chartOrConfig?.isDatasetVisible === 'function'
                ? (idx) => chartOrConfig.isDatasetVisible(idx)
                : (idx) => !datasets[idx]?.hidden;

            const {
                axisMode = 'period',
                formatMoney = (v) => String(v ?? '-'),
                fallbackPalette = ['#1F5FA6', '#D95926', '#14967A', '#B88600', '#C2477A', '#6250D6'],
            } = ctx;
            const isMaterialAxis = axisMode === 'material';

            let overallHigh = null;
            let overallLow = null;
            const seriesRows = [];

            datasets.forEach((ds, dIdx) => {
                if (!isVisible(dIdx)) return;
                const points = [];
                (ds.data || []).forEach((raw, pIdx) => {
                    const num = finiteNumber(raw);
                    if (num === null) return;
                    const categoryLabel = String(labels[pIdx] ?? '');
                    points.push({ value: num, index: pIdx, categoryLabel });
                });
                if (points.length === 0) return;

                let high = points[0];
                let low = points[0];
                for (let i = 1; i < points.length; i++) {
                    if (points[i].value > high.value) high = points[i];
                    if (points[i].value < low.value) low = points[i];
                }
                const last = points[points.length - 1];
                const seriesLabel = String(ds.label ?? '');
                const swatchColor = resolveBarSwatchColor(ds, 0, dIdx, fallbackPalette);

                if (overallHigh === null || high.value > overallHigh.value) {
                    overallHigh = {
                        value: high.value,
                        formatted: formatMoney(high.value),
                        seriesLabel,
                        categoryLabel: high.categoryLabel,
                        context: isMaterialAxis
                            ? (high.categoryLabel + ' · ' + seriesLabel)
                            : (datasets.length > 1 ? (seriesLabel + ' · ' + high.categoryLabel) : high.categoryLabel),
                    };
                }
                if (overallLow === null || low.value < overallLow.value) {
                    overallLow = {
                        value: low.value,
                        formatted: formatMoney(low.value),
                        seriesLabel,
                        categoryLabel: low.categoryLabel,
                        context: isMaterialAxis
                            ? (low.categoryLabel + ' · ' + seriesLabel)
                            : (datasets.length > 1 ? (seriesLabel + ' · ' + low.categoryLabel) : low.categoryLabel),
                    };
                }

                seriesRows.push({
                    label: seriesLabel,
                    swatchColor,
                    highestValue: formatMoney(high.value),
                    highestLabel: high.categoryLabel,
                    lowestValue: formatMoney(low.value),
                    lowestLabel: low.categoryLabel,
                    latestValue: isMaterialAxis ? null : formatMoney(last.value),
                    latestLabel: isMaterialAxis ? null : last.categoryLabel,
                });
            });

            return {
                overallHigh,
                overallLow,
                seriesRows,
            };
        }

        function buildLineAppearance(idx, isMultiSeries, labelsCount, theme, fallbackPalette, fallbackPoints, fallbackDashes, extra = {}) {
            if (theme?.lineDataset) {
                return theme.lineDataset({
                    index: idx,
                    multi: isMultiSeries,
                    area: !isMultiSeries,
                    ...(labelsCount === 1 ? { pointRadius: 5 } : {}),
                    ...extra,
                });
            }
            const style = theme?.getSeriesStyle
                ? theme.getSeriesStyle(idx)
                : {
                    color: fallbackPalette[idx % fallbackPalette.length],
                    pointStyle: fallbackPoints[idx % fallbackPoints.length],
                    borderDash: fallbackDashes[idx % fallbackDashes.length],
                };
            return {
                borderColor: style.color,
                backgroundColor: style.color,
                pointBorderColor: theme?.color ? theme.color('surface') : '#FFFFFF',
                pointStyle: style.pointStyle,
                borderDash: isMultiSeries ? style.borderDash : [],
                borderWidth: 2,
                fill: false,
                tension: 0,
                spanGaps: false,
                pointRadius: labelsCount === 1 ? 6 : 4,
                pointHoverRadius: labelsCount === 1 ? 8 : 6,
                pointBorderWidth: 2,
                ...extra,
            };
        }

        function buildConfig(chartData, preferredType, options = {}) {
            const {
                theme = null,
                currency = 'IDR',
                formatMoney = (value) => String(value ?? '-'),
                formatNumber = (value) => String(value ?? '0'),
                i18n = {},
                axisMode: requestedAxisMode = 'period',
                maxMaterialPeriods = MAX_MATERIAL_AXIS_PERIODS,
                animationDuration = null,
            } = options;
            const rawPeriodLabels = Array.isArray(chartData?.labels) ? chartData.labels : [];
            const seriesList = normalizeSeries(chartData, i18n.weightedAverage || 'Weighted Average');
            let axisMode = requestedAxisMode === 'material'
                ? 'material'
                : (requestedAxisMode === 'auto' ? resolveAxisMode(seriesList.length, null) : 'period');
            // Line charts represent chronological trends, so selecting Line always places Period (Year/Month) on the X-axis.
            if (preferredType === 'line' && axisMode === 'material') {
                axisMode = 'period';
            }
            const isMaterialAxis = axisMode === 'material';

            const fallbackPalette = ['#1F5FA6', '#D95926', '#14967A', '#B88600', '#C2477A', '#6250D6'];
            const fallbackPoints = ['circle', 'rect', 'triangle', 'rectRot', 'crossRot', 'star'];
            const fallbackDashes = [[], [6, 4], [2, 3], [10, 3, 2, 3], [4, 4], [1, 3]];

            let labels = rawPeriodLabels;
            let type = 'bar';
            const showSwitch = true;
            let truncatedPeriods = false;
            let shownPeriods = rawPeriodLabels.length;
            const totalPeriods = rawPeriodLabels.length;
            let isMultiSeries = false;
            let datasets = [];

            if (isMaterialAxis) {
                const transposed = transposeToMaterialAxis(rawPeriodLabels, seriesList, maxMaterialPeriods);
                labels = transposed.labels;
                truncatedPeriods = transposed.truncatedPeriods;
                shownPeriods = transposed.shownPeriods;
                type = 'bar';
                isMultiSeries = transposed.datasetsMeta.length > 1;
                datasets = transposed.datasetsMeta.map((periodEntry, idx) => ({
                    label: periodEntry.label,
                    data: periodEntry.data,
                    _metaMin: periodEntry.minIdr,
                    _metaMax: periodEntry.maxIdr,
                    _metaPoCounts: periodEntry.poCounts,
                    _metaTotalKgs: periodEntry.totalKgs,
                    ...buildBarAppearance(idx, isMultiSeries, theme, fallbackPalette),
                }));
            } else {
                const resolved = resolveChartType(rawPeriodLabels.length, preferredType);
                type = resolved.type;
                const isBar = type === 'bar';
                isMultiSeries = seriesList.length > 1;

                datasets = seriesList.map((series, idx) => {
                    const label = isMultiSeries
                        ? String(series.label || (i18n.weightedAverage || 'Series ' + (idx + 1)))
                        : String(i18n.weightedAverage || series.label || 'Weighted Average') + ' (' + currency + ')';
                    const data = (series.pricesIdr || []).map((val) => (val === null || val === undefined || val === '' ? null : Number(val)));
                    const base = {
                        label,
                        data,
                        _metaMin: series.minIdr || [],
                        _metaMax: series.maxIdr || [],
                        _metaPoCounts: series.poCounts || [],
                        _metaTotalKgs: series.totalKgs || [],
                    };

                    if (isBar) {
                        return {
                            ...base,
                            ...buildBarAppearance(idx, isMultiSeries, theme, fallbackPalette),
                        };
                    }

                    return {
                        ...base,
                        ...buildLineAppearance(idx, isMultiSeries, labels.length, theme, fallbackPalette, fallbackPoints, fallbackDashes),
                    };
                });
            }

            const isBarChart = type === 'bar';
            const useCompactMeta = isMaterialAxis || isMultiSeries;
            const tooltipOptions = {
                ...(isBarChart ? {
                    enabled: false,
                    external: createBarExternalTooltipHandler({
                        axisMode,
                        isMultiSeries,
                        formatMoney,
                        formatNumber,
                        i18n,
                        fallbackPalette,
                    }),
                } : {}),
                callbacks: {
                    label: (context) => {
                        const yVal = context.parsed?.y;
                        const prefix = (isMaterialAxis || isMultiSeries)
                            ? context.dataset.label
                            : (i18n.average || 'Average');
                        return ' ' + prefix + ': ' + formatMoney(yVal);
                    },
                    afterLabel: (context) => {
                        const idx = context.dataIndex;
                        const minVal = context.dataset._metaMin?.[idx];
                        const maxVal = context.dataset._metaMax?.[idx];
                        const pos = Number(context.dataset._metaPoCounts?.[idx] || 0);
                        const kg = context.dataset._metaTotalKgs?.[idx];
                        if (useCompactMeta) {
                            const parts = [];
                            if (minVal !== null && minVal !== undefined) parts.push((i18n.lowest || 'Lowest') + ': ' + formatMoney(minVal));
                            if (maxVal !== null && maxVal !== undefined) parts.push((i18n.highest || 'Highest') + ': ' + formatMoney(maxVal));
                            if (pos > 0 && i18n.historyBasis) {
                                parts.push(i18n.historyBasis.replace(':pos', String(pos)).replace(':kg', formatNumber(kg || 0)));
                            }
                            return parts.length ? ['   ' + parts.join(' · ')] : [];
                        }
                        const lines = [
                            ' ' + (i18n.highest || 'Highest') + ': ' + formatMoney(maxVal),
                            ' ' + (i18n.lowest || 'Lowest') + ': ' + formatMoney(minVal),
                        ];
                        if (pos > 0 && i18n.historyBasis) {
                            lines.push(' ' + i18n.historyBasis.replace(':pos', String(pos)).replace(':kg', formatNumber(kg || 0)));
                        }
                        return lines;
                    },
                },
            };

            const legend = theme?.getLegend
                ? theme.getLegend()
                : { position: 'top', align: 'start' };
            const padSparseLine = !isBarChart && labels.length <= 2;
            const scales = theme?.getScales
                ? theme.getScales({
                    yMaxTicks: 5,
                    yBeginAtZero: isBarChart,
                    yGrace: isBarChart ? null : '12%',
                    yFormat: (value) => formatMoney(value),
                    xGrid: false,
                    xOffset: padSparseLine,
                    xMaxRotation: 45,
                    xAutoSkip: !isMaterialAxis,
                })
                : {
                    x: {
                        ...(padSparseLine ? { offset: true } : {}),
                        grid: { display: false },
                        ticks: { autoSkip: !isMaterialAxis },
                    },
                    y: {
                        beginAtZero: isBarChart,
                        ...(!isBarChart ? { grace: '12%' } : {}),
                        ticks: { callback: (value) => formatMoney(value) },
                    },
                };
            const tooltip = theme?.getTooltip ? theme.getTooltip(tooltipOptions) : tooltipOptions;
            const interaction = isBarChart
                ? { mode: 'nearest', axis: 'xy', intersect: true }
                : { mode: 'index', intersect: false };

            const animationOpt = animationDuration !== null && Number.isFinite(Number(animationDuration))
                ? { animation: Number(animationDuration) <= 0 ? false : { duration: Number(animationDuration), easing: 'easeOutCubic' } }
                : {};

            return {
                type,
                axisMode,
                showSwitch,
                truncatedPeriods,
                shownPeriods,
                totalPeriods,
                config: {
                    type,
                    data: { labels, datasets },
                    ...(isBarChart ? { plugins: [barTooltipCleanupPlugin] } : {}),
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        ...animationOpt,
                        interaction,
                        plugins: {
                            legend,
                            adasiA11y: {
                                format: (value) => formatMoney(value),
                                ...(isBarChart ? { perBarStops: true } : {}),
                            },
                            tooltip,
                        },
                        scales,
                    },
                },
            };
        }

        window.AdasiHistoricalChart = {
            MAX_MATERIAL_AXIS_PERIODS,
            resolveChartType,
            resolveAxisMode,
            transposeToMaterialAxis,
            normalizeSeries,
            buildBarTooltipModel,
            buildChartSummaryModel,
            buildConfig,
        };
    })();
    // </historical-standalone-chart>

    // <historical-supporting-filters> Pure state helpers (tests/js/historical-supporting-filters.test.mjs).
    (function() {
        const VALID_DIRECTIONS = new Set(['increased', 'decreased', 'unchanged']);
        const DATE_RE = /^\d{4}-\d{2}-\d{2}$/;

        function emptyState() {
            return {
                categories: [],
                doc_search: '',
                source_currencies: [],
                start_date: null,
                end_date: null,
                price_min: null,
                price_max: null,
                change_direction: null,
            };
        }

        function cleanArray(values, { uppercase = false } = {}) {
            const list = Array.isArray(values) ? values : (values ? [values] : []);
            const seen = new Set();
            const out = [];
            list.forEach((entry) => {
                const raw = String(entry ?? '').trim();
                const val = uppercase ? raw.toUpperCase() : raw;
                if (val !== '' && !seen.has(val)) {
                    seen.add(val);
                    out.push(val);
                }
            });
            return out;
        }

        function cleanDate(value) {
            const trimmed = String(value ?? '').trim();
            return DATE_RE.test(trimmed) ? trimmed : null;
        }

        function cleanPrice(value) {
            const trimmed = String(value ?? '').trim();
            if (trimmed === '') return null;
            const parsed = Number(trimmed);
            if (!Number.isFinite(parsed) || parsed < 0) return null;
            return trimmed;
        }

        function normalize(raw = {}) {
            const categories = cleanArray(raw?.categories);
            const docSearch = String(raw?.doc_search ?? '').trim().slice(0, 100);
            const sourceCurrencies = cleanArray(raw?.source_currencies, { uppercase: true });
            let startDate = cleanDate(raw?.start_date);
            let endDate = cleanDate(raw?.end_date);
            if (startDate && endDate && startDate > endDate) {
                [startDate, endDate] = [endDate, startDate];
            }
            let priceMin = cleanPrice(raw?.price_min);
            let priceMax = cleanPrice(raw?.price_max);
            if (priceMin !== null && priceMax !== null && Number(priceMin) > Number(priceMax)) {
                [priceMin, priceMax] = [priceMax, priceMin];
            }
            const rawDir = String(raw?.change_direction ?? '').trim().toLowerCase();
            const changeDirection = VALID_DIRECTIONS.has(rawDir) ? rawDir : null;

            return {
                categories,
                doc_search: docSearch,
                source_currencies: sourceCurrencies,
                start_date: startDate,
                end_date: endDate,
                price_min: priceMin,
                price_max: priceMax,
                change_direction: changeDirection,
            };
        }

        function countActive(state) {
            const s = normalize(state);
            let count = 0;
            if (s.categories.length > 0) count++;
            if (s.doc_search !== '') count++;
            if (s.source_currencies.length > 0) count++;
            if (s.start_date !== null || s.end_date !== null) count++;
            if (s.price_min !== null || s.price_max !== null) count++;
            if (s.change_direction !== null) count++;
            return count;
        }

        function clearKey(state, key) {
            const next = normalize(state);
            if (key === 'categories') return { ...next, categories: [] };
            if (key === 'doc_search') return { ...next, doc_search: '' };
            if (key === 'source_currencies') return { ...next, source_currencies: [] };
            if (key === 'date_range') return { ...next, start_date: null, end_date: null };
            if (key === 'price_range') return { ...next, price_min: null, price_max: null };
            if (key === 'change_direction') return { ...next, change_direction: null };
            return next;
        }

        function buildChips(state, options = {}) {
            const s = normalize(state);
            const chips = [];
            const chipLabels = options.chips || {};
            const categoryLabels = options.categoryLabels || {};
            const changeLabels = options.changeLabels || {};
            const currency = options.currency || 'IDR';
            const formatPrice = options.formatPrice || ((v) => String(v));

            if (s.categories.length > 0) {
                chips.push({
                    key: 'categories',
                    label: chipLabels.categories || 'Category',
                    value: s.categories.map((key) => categoryLabels[key] || key).join(', '),
                });
            }
            if (s.doc_search !== '') {
                chips.push({
                    key: 'doc_search',
                    label: chipLabels.doc_search || 'PR / PO',
                    value: s.doc_search,
                });
            }
            if (s.source_currencies.length > 0) {
                chips.push({
                    key: 'source_currencies',
                    label: chipLabels.source_currencies || 'Currency',
                    value: s.source_currencies.join(', '),
                });
            }
            if (s.start_date !== null || s.end_date !== null) {
                chips.push({
                    key: 'date_range',
                    label: chipLabels.date_range || 'PO Date',
                    value: `${s.start_date || '...'} - ${s.end_date || '...'}`,
                });
            }
            if (s.price_min !== null || s.price_max !== null) {
                const minText = s.price_min !== null ? formatPrice(s.price_min) : '...';
                const maxText = s.price_max !== null ? formatPrice(s.price_max) : '...';
                chips.push({
                    key: 'price_range',
                    label: chipLabels.price_range || 'Price/kg',
                    value: `${currency} ${minText} - ${maxText}`,
                });
            }
            if (s.change_direction !== null) {
                chips.push({
                    key: 'change_direction',
                    label: chipLabels.change_direction || 'Change',
                    value: changeLabels[s.change_direction] || s.change_direction,
                });
            }
            return chips;
        }

        function appendToParams(params, state) {
            const s = normalize(state);
            s.categories.forEach((key) => params.append('categories[]', key));
            if (s.doc_search !== '') params.set('doc_search', s.doc_search);
            s.source_currencies.forEach((code) => params.append('source_currencies[]', code));
            if (s.start_date !== null) params.set('start_date', s.start_date);
            if (s.end_date !== null) params.set('end_date', s.end_date);
            if (s.price_min !== null) params.set('price_min', s.price_min);
            if (s.price_max !== null) params.set('price_max', s.price_max);
            if (s.change_direction !== null) params.set('change_direction', s.change_direction);
        }

        function hasOpenInnerPopover(dialogEl) {
            if (!dialogEl || typeof dialogEl.querySelectorAll !== 'function') return false;
            return Array.from(dialogEl.querySelectorAll('[id$="-popover"]')).some((el) => {
                if (el.hidden || el.hasAttribute?.('hidden')) return false;
                if (el.style?.display === 'none') return false;
                if ('offsetParent' in el) return el.offsetParent !== null;
                return true;
            });
        }

        window.AdasiHistoricalSupportingFilters = {
            emptyState,
            normalize,
            countActive,
            clearKey,
            buildChips,
            appendToParams,
            hasOpenInnerPopover,
        };
    })();
    // </historical-supporting-filters>

    // <historical-po-date-sort> Pure helpers for Monthly PO Date ASC/DESC sorting (tests/js/historical-supporting-filters.test.mjs).
    (function() {
        function normalizeDir(value) {
            return String(value || '').trim().toLowerCase() === 'asc' ? 'asc' : 'desc';
        }

        function toggleDir(current) {
            return normalizeDir(current) === 'asc' ? 'desc' : 'asc';
        }

        function ariaSortFor(dir) {
            return normalizeDir(dir) === 'asc' ? 'ascending' : 'descending';
        }

        function canReorderClientSide(pagination, renderedRowCount) {
            if (!pagination || renderedRowCount <= 1) return false;
            const lastPage = Number(pagination.last_page || 1);
            const total = Number(pagination.total || 0);
            return lastPage === 1 && total === renderedRowCount;
        }

        function reorderRows(tbody, orderedRows) {
            if (!tbody || !Array.isArray(orderedRows) || orderedRows.length === 0) return;
            const useMoveBefore = typeof tbody.moveBefore === 'function';
            for (const row of orderedRows) {
                if (useMoveBefore) {
                    tbody.moveBefore(row, null);
                } else {
                    tbody.append(row);
                }
            }
        }

        function formatAnnouncement(template, { column, directionLabel, count }) {
            const base = String(template || 'Sorted by :column, :direction, :count rows.');
            return base
                .replace(':column', String(column || ''))
                .replace(':direction', String(directionLabel || ''))
                .replace(':count', String(count ?? 0));
        }

        window.AdasiHistoricalPoDateSort = {
            normalizeDir,
            toggleDir,
            ariaSortFor,
            canReorderClientSide,
            reorderRows,
            formatAnnouncement,
        };
    })();
    // </historical-po-date-sort>

    // PUR-05: one place keeps the URL, the history entry (with the server-tabs marker so
    // Back/Forward still swaps tabs), the tab links and the currency form in step.
    const comparisonSharedKeys = ['material_name', 'shape', 'currency'];
    // Gate 1 C6: Historical-local focus never travels; the active PR follows the current URL only.
    const comparisonLocalKeys = ['pr_item', 'chart'];
    window.syncComparisonState = function(url) {
        const target = new URL(url, window.location.origin);
        window.history.replaceState(
            { ...(window.history.state || {}), _serverTabs: '#purchasingComparisonContainer', url: target.toString() },
            '',
            target.toString(),
        );
        const derivedShared = {
            material_name: target.searchParams.get('material_name'),
            shape: target.searchParams.get('shape'),
            currency: target.searchParams.get('currency'),
        };
        if (!derivedShared.material_name) {
            const multiMats = target.searchParams.getAll('materials[]').filter(Boolean);
            if (multiMats.length === 1) {
                const pipeIdx = multiMats[0].lastIndexOf('|');
                derivedShared.material_name = pipeIdx >= 0 ? multiMats[0].slice(0, pipeIdx) : multiMats[0];
                derivedShared.shape = pipeIdx >= 0 ? multiMats[0].slice(pipeIdx + 1) : '';
            } else if (multiMats.length > 1) {
                derivedShared.material_name = null;
                derivedShared.shape = null;
            }
        }
        const keepEmbed = document.documentElement?.dataset?.workspaceEmbed === 'true';
        document.querySelectorAll('#purchasingComparisonContainer nav [data-server-tab][data-tab-name]').forEach((tab) => {
            const href = new URL(tab.getAttribute('href'), window.location.origin);
            comparisonSharedKeys.forEach((key) => {
                const value = derivedShared[key];
                value === null || value === '' ? href.searchParams.delete(key) : href.searchParams.set(key, value);
            });
            comparisonLocalKeys.forEach((key) => href.searchParams.delete(key));
            const prId = target.searchParams.get('pr_id');
            if (prId) {
                href.searchParams.set('pr_id', prId);
            } else if (tab.dataset?.tabName !== 'inter-supplier') {
                href.searchParams.delete('pr_id');
            }
            if (keepEmbed) {
                href.searchParams.set('_embed', '1');
            } else {
                href.searchParams.delete('_embed');
            }
            tab.setAttribute('href', href.pathname + href.search);
        });
        // Gate 1 C6 (N7): detail links return to the state the user is looking at now.
        const returnTarget = new URL(target.toString());
        returnTarget.searchParams.delete('_embed');
        document.querySelectorAll('#purchasingComparisonContainer [data-return-url]').forEach((link) => {
            const href = new URL(link.getAttribute('href'), window.location.origin);
            href.searchParams.set('return_url', returnTarget.pathname + returnTarget.search);
            link.setAttribute('href', href.pathname + href.search);
        });
        document.querySelectorAll('#purchasingComparisonContainer [data-evaluation-header] form').forEach((form) => {
            form.querySelectorAll('input[type="hidden"]').forEach((input) => input.remove());
            target.searchParams.forEach((value, key) => {
                if (['currency', 'history_page', 'view', '_'].includes(key) || value === '') return;
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = key;
                input.value = value;
                form.prepend(input);
            });
        });
    };

    // Latest-request-wins guard: aborts the previous fetch and ignores late responses.
    const activeComparisonGuards = new Set();
    window.createComparisonRequestGuard = function() {
        let controller = null;
        let sequence = 0;
        const guard = {
            start() {
                controller?.abort();
                controller = new AbortController();
                return { signal: controller.signal, id: ++sequence };
            },
            abort() {
                controller?.abort();
                controller = null;
                sequence += 1;
            },
            isCurrent(id) {
                return id === sequence;
            },
        };
        activeComparisonGuards.add(guard);
        return guard;
    };

    if (!window._analysisCurrencyListener) {
        window._analysisCurrencyListener = true;
        document.addEventListener('change', (event) => {
            const select = event.target.closest?.('[data-analysis-currency]');
            if (select && select.form) {
                select.form.requestSubmit ? select.form.requestSubmit() : select.form.submit();
            }
        });
    }

    window.abortComparisonPendingRequests = function() {
        if (window._vsBestSearchTimer) {
            clearTimeout(window._vsBestSearchTimer);
            window._vsBestSearchTimer = null;
        }

        activeComparisonGuards.forEach((guard) => {
            try {
                guard.abort();
            } catch (e) {
                window.AdasiDebug?.warn('Error aborting comparison request guard:', e);
            }
        });

        if (window._activeVsBestTable) {
            try {
                const settingsList = typeof window._activeVsBestTable.settings === 'function'
                    ? window._activeVsBestTable.settings()
                    : null;
                const dtSettings = settingsList?.[0];
                if (dtSettings?.jqXHR && typeof dtSettings.jqXHR.abort === 'function') {
                    dtSettings.jqXHR.aborted = true;
                    dtSettings.jqXHR.abort();
                }
            } catch (e) {
                window.AdasiDebug?.warn('Error aborting Vs Best request:', e);
            }
        }
    };

    window.destroyComparisonActiveInstances = function() {
        window.abortComparisonPendingRequests();

        if (window._activeComparisonChart) {
            try {
                window._activeComparisonChart.destroy();
            } catch (e) {
                window.AdasiDebug?.warn('Error destroying chart:', e);
            }
            window._activeComparisonChart = null;
        }

        if (window._activeVsBestTable) {
            try {
                if (window.DataTable?.isDataTable?.('#vsBestTable')) {
                    window._activeVsBestTable.destroy();
                }
            } catch (e) {
                window.AdasiDebug?.warn('Error destroying DataTable:', e);
            }
            window._activeVsBestTable = null;
        }

        if (window.AdasiCalendar && typeof window.AdasiCalendar.closeActive === 'function') {
            try {
                window.AdasiCalendar.closeActive();
            } catch (e) {
                window.AdasiDebug?.warn('Error closing active calendar:', e);
            }
        }
    };

    // ─── 1. INTER-SUPPLIER LOGIC ──────────────────────────────────────────────
    window.initInterSupplierComparison = function() {
        const configEl = document.getElementById('interSupplierConfig');
        if (!configEl) return;

        let config;
        try {
            config = JSON.parse(configEl.textContent);
        } catch (e) {
            window.AdasiDebug?.error('Invalid interSupplierConfig JSON', e);
            return;
        }

        const eligiblePrOptions = config.eligiblePrOptions || [];
        const comparisonChartData = config.chartData;
        const comparisonMaterialIds = config.chartMaterialIds || [];

        // PUR-04: the two-row header stacks under the first row's real height (it wraps with
        // long supplier names), so the second row never slides under the first.
        const stickyScroll = document.querySelector('.comparison-matrix-table')?.closest('.ui-table-sticky');
        const firstHeadRow = stickyScroll?.querySelector('thead tr');
        window._comparisonHeadObserver?.disconnect();
        if (stickyScroll && firstHeadRow) {
            const setHeadOffset = () => stickyScroll.style.setProperty('--ui-table-sticky-head-row', `${firstHeadRow.getBoundingClientRect().height}px`);
            setHeadOffset();
            if ('ResizeObserver' in window) {
                window._comparisonHeadObserver = new ResizeObserver(setHeadOffset);
                window._comparisonHeadObserver.observe(firstHeadRow);
            }
        }

        const comparisonFilterForm = document.getElementById('interSupplierFilterForm');
        const comparisonPrId = document.getElementById('comparisonPrId');
        const comparisonPrSearch = document.getElementById('comparisonPrSearch');
        const comparisonPrSuggestions = document.getElementById('comparisonPrSuggestions');
        const comparisonPrClear = document.getElementById('comparisonPrClear');

        if (!comparisonFilterForm || !comparisonPrId || !comparisonPrSearch) return;

        const normalizeComparisonKeyword = (value) => String(value || '').toLowerCase().trim();

        const hideComparisonPrSuggestions = () => {
            if (comparisonPrSuggestions) comparisonPrSuggestions.classList.add('tw:hidden');
        };

        const toggleComparisonClear = () => {
            if (comparisonPrClear) {
                comparisonPrClear.classList.toggle('tw:hidden', comparisonPrSearch.value.trim() === '');
            }
        };

        const selectComparisonPr = (option) => {
            comparisonPrId.value = option.id;
            comparisonPrSearch.value = option.label;
            comparisonPrSearch.classList.remove('is-invalid');
            toggleComparisonClear();
            hideComparisonPrSuggestions();
            comparisonFilterForm.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        };

        const renderComparisonPrSuggestions = () => {
            if (!comparisonPrSuggestions) return;
            const keyword = normalizeComparisonKeyword(comparisonPrSearch.value);
            const matches = (keyword === ''
                ? eligiblePrOptions
                : eligiblePrOptions.filter((option) => option.search && option.search.includes(keyword))
            ).slice(0, 8);

            comparisonPrSuggestions.replaceChildren();

            if (matches.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'tw:block tw:w-full tw:border-b tw:border-outline-variant tw:px-3 tw:py-2 tw:text-left tw:text-ui-xs tw:text-on-surface-variant';
                empty.textContent = @json(__('purchasing.copy.no_matching_pr_was_found'));
                comparisonPrSuggestions.appendChild(empty);
                comparisonPrSuggestions.classList.remove('tw:hidden');
                return;
            }

            matches.forEach((option) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'ui-focus-ring tw:block tw:w-full tw:border-b tw:border-outline-variant tw:bg-surface tw:px-3 tw:py-2 tw:text-left tw:transition-colors tw:hover:bg-surface-container tw:cursor-pointer';

                const title = document.createElement('div');
                title.className = 'tw:font-semibold tw:text-ui-xs tw:text-on-surface';
                title.textContent = option.prNumber;

                const meta = document.createElement('div');
                meta.className = 'tw:text-on-surface-variant';
                meta.style.fontSize = '.75rem';
                meta.textContent = `${option.period} - ${option.quotationCountLabel}`;

                const preview = document.createElement('div');
                preview.className = 'tw:text-on-surface-variant tw:mt-1';
                preview.style.fontSize = '.7rem';
                preview.innerHTML = `<span class="tw:me-1">${comparisonIcons.package}</span>${option.previewMaterials || ''}`;

                button.appendChild(title);
                button.appendChild(meta);
                button.appendChild(preview);
                button.addEventListener('mousedown', (event) => {
                    event.preventDefault();
                    selectComparisonPr(option);
                });

                comparisonPrSuggestions.appendChild(button);
            });

            comparisonPrSuggestions.classList.remove('tw:hidden');
        };

        comparisonPrSearch.addEventListener('focus', renderComparisonPrSuggestions);
        comparisonPrSearch.addEventListener('input', () => {
            comparisonPrId.value = '';
            comparisonPrSearch.classList.remove('is-invalid');
            toggleComparisonClear();
            renderComparisonPrSuggestions();
        });

        comparisonPrSearch.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                hideComparisonPrSuggestions();
            }
        });

        if (comparisonPrClear) {
            comparisonPrClear.addEventListener('click', () => {
                comparisonPrId.value = '';
                comparisonPrSearch.value = '';
                comparisonPrSearch.classList.remove('is-invalid');
                toggleComparisonClear();
                renderComparisonPrSuggestions();
                comparisonPrSearch.focus();
            });
        }

        comparisonFilterForm.addEventListener('submit', (event) => {
            if (comparisonPrId.value) {
                return;
            }

            const keyword = normalizeComparisonKeyword(comparisonPrSearch.value);
            const exact = eligiblePrOptions.find((option) =>
                normalizeComparisonKeyword(option.label) === keyword
                || normalizeComparisonKeyword(option.prNumber) === keyword
            );

            if (exact) {
                comparisonPrId.value = exact.id;
                return;
            }

            event.preventDefault();
            comparisonPrSearch.classList.add('is-invalid');
            renderComparisonPrSuggestions();
        });

        document.addEventListener('click', (event) => {
            if (comparisonPrSuggestions && !comparisonPrSuggestions.contains(event.target) && event.target !== comparisonPrSearch) {
                hideComparisonPrSuggestions();
            }
        });

        // Comparison Bar Chart (builders: <inter-supplier-chart> above)
        const chartCanvas = document.getElementById('comparisonChart');
        if (comparisonChartData && chartCanvas && typeof Chart !== 'undefined' && window.AdasiInterSupplierChart) {
            const chartLib = window.AdasiInterSupplierChart;
            const chartI18n = config.chartI18n || {};
            const formatPrice = (value) => window.formatAnalyticMoney(value, comparisonChartData.currency);
            const formatPercent = (value) => {
                const number = Number(value).toLocaleString('id-ID', { maximumFractionDigits: 1 });
                const display = window.AdasiPreferences?.displayNumber ? window.AdasiPreferences.displayNumber(number, 'indonesian') : number;
                return (Number(value) > 0 ? '+' : '') + display + '%';
            };
            const tokenColors = () => {
                const css = getComputedStyle(document.documentElement);
                const token = (name) => css.getPropertyValue(name).trim();
                return {
                    primary: token('--md-chart-primary'),
                    warning: token('--md-chart-warning') || token('--md-warning'),
                    secondary: token('--md-secondary'),
                    success: token('--md-success'),
                    info: token('--md-info'),
                    error: token('--md-error'),
                    onSurfaceVariant: token('--md-on-surface-variant'),
                    series: [1, 2, 3, 4, 5, 6].map((slot) => token('--md-chart-series-' + slot)),
                };
            };
            const scaleButtons = document.querySelectorAll('[data-comparison-chart-scale]');
            const chartState = {
                materialIndex: -1,
                mode: new URLSearchParams(window.location.search).get('chart_scale') === 'relative' ? 'relative' : 'price',
            };

            const renderComparisonChart = () => {
                if (!document.body.contains(chartCanvas)) return;
                const colors = window.AdasiChart ? window.AdasiChart.getColors() : tokenColors();
                const palette = window.AdasiChart?.getSeriesPalette
                    ? window.AdasiChart.getSeriesPalette(colors)
                    : colors.series;
                const data = chartState.materialIndex === -1
                    ? comparisonChartData
                    : chartLib.subset(comparisonChartData, chartState.materialIndex);
                const built = chartLib.build(data, {
                    colors,
                    palette,
                    i18n: chartI18n,
                    format: formatPrice,
                    formatPercent,
                    mode: chartState.mode,
                    theme: window.AdasiChart ? { scales: window.AdasiChart.getScales, tooltip: window.AdasiChart.getTooltip, hatch: window.AdasiChart.hatch, barDataset: window.AdasiChart.barDataset } : null,
                });
                built.config.plugins = [chartLib.lowestMarkerPlugin(colors.success)];
                // Keyboard readout reads prices (also in % mode) and names the lowest offer, like the tooltip.
                built.config.options.plugins.adasiA11y = {
                    valueText: (datasetIndex, index, instance) => {
                        const price = instance.data.datasets[datasetIndex].prices[index];
                        if (price === null || price === undefined) return chartI18n.noOffer;
                        return formatPrice(price) + (built.lowest[index] === datasetIndex ? ' · ' + chartI18n.lowest : '');
                    },
                };

                window._activeComparisonChart?.destroy();
                window.AdasiChart?.fitScrollWidth?.(chartCanvas, {
                    categories: data.labels?.length || 0,
                    series: data.datasets?.length || 0,
                });
                window._activeComparisonChart = new Chart(chartCanvas, built.config);
                chartCanvas.setAttribute('aria-label', [chartI18n.title, chartLib.summary(data, chartI18n, formatPrice)].filter(Boolean).join('. '));
                scaleButtons.forEach((button) => {
                    button.setAttribute('aria-pressed', button.dataset.comparisonChartScale === chartState.mode ? 'true' : 'false');
                });

                // B3: Render supplier legend items with Total Price/kg (IDR) below each supplier icon
                const legendContainer = document.getElementById('comparisonChartLegend');
                if (legendContainer) {
                    legendContainer.replaceChildren();
                    const rawDatasets = comparisonChartData.datasets || [];
                    rawDatasets.forEach((rawDs, dsIdx) => {
                        const listItem = document.createElement('div');
                        listItem.setAttribute('role', 'listitem');
                        listItem.className = 'tw:flex';

                        const itemBtn = document.createElement('button');
                        itemBtn.type = 'button';
                        itemBtn.setAttribute('aria-pressed', 'true');
                        const isBest = Boolean(rawDs.is_best_total);
                        itemBtn.className = 'ui-focus-ring tw:flex tw:flex-col tw:items-start tw:gap-1 tw:rounded-ui-sm tw:border tw:px-3 tw:py-2 tw:text-left tw:transition-colors '
                            + (isBest ? 'ui-best-price-cell tw:border-success' : 'tw:border-outline-variant tw:bg-surface-container-low tw:hover:bg-surface-container');

                        const topRow = document.createElement('div');
                        topRow.className = 'tw:flex tw:items-center tw:gap-2';

                        const swatch = document.createElement('span');
                        swatch.className = 'tw:inline-block tw:h-3 tw:w-3 tw:shrink-0 tw:rounded-xs';
                        swatch.style.backgroundColor = palette[dsIdx % palette.length] || colors.primary;

                        const nameEl = document.createElement('span');
                        nameEl.className = 'tw:text-ui-xs tw:font-bold' + (isBest ? '' : ' tw:text-on-surface');
                        nameEl.textContent = rawDs.label || ('Supplier ' + (dsIdx + 1));

                        topRow.appendChild(swatch);
                        topRow.appendChild(nameEl);

                        if (isBest) {
                            const bestChip = document.createElement('span');
                            bestChip.className = 'ui-status-chip ui-status-chip--success tw:ms-1';
                            bestChip.textContent = @json(__('purchasing.copy.best_total'));
                            topRow.appendChild(bestChip);
                        }

                        const bottomRow = document.createElement('div');
                        bottomRow.className = 'ui-tabular-nums tw:text-ui-xs tw:font-semibold' + (isBest ? '' : ' tw:text-on-surface-variant');
                        const totalPerKg = rawDs.total_price_per_kg_idr;
                        bottomRow.textContent = totalPerKg != null && totalPerKg > 0
                            ? (@json(__('purchasing.copy.total_price_per_kg_idr')) + ': ' + window.formatAnalyticMoney(totalPerKg, 'IDR'))
                            : '-';

                        itemBtn.appendChild(topRow);
                        itemBtn.appendChild(bottomRow);

                        itemBtn.addEventListener('click', () => {
                            const chartInstance = window._activeComparisonChart;
                            if (!chartInstance) return;
                            const visible = chartInstance.isDatasetVisible(dsIdx);
                            const nextVisible = !visible;
                            chartInstance.setDatasetVisibility(dsIdx, nextVisible);
                            chartInstance.update();
                            itemBtn.setAttribute('aria-pressed', nextVisible ? 'true' : 'false');
                            itemBtn.style.opacity = nextVisible ? '1' : '0.45';
                        });

                        listItem.appendChild(itemBtn);
                        legendContainer.appendChild(listItem);
                    });
                }
            };

            // Bar colors are resolved at build time, so a theme/accent switch rebuilds the chart.
            window._interSupplierChartRender = renderComparisonChart;
            if (!window._interSupplierChartThemeListener) {
                window._interSupplierChartThemeListener = true;
                ['adasi:theme-change', 'adasi:accent-change'].forEach((name) => {
                    window.addEventListener(name, () => window._interSupplierChartRender?.());
                });
            }

            scaleButtons.forEach((button) => {
                button.addEventListener('click', () => {
                    chartState.mode = button.dataset.comparisonChartScale === 'relative' ? 'relative' : 'price';
                    renderComparisonChart();
                    const url = new URL(window.location.href);
                    if (chartState.mode === 'relative') {
                        url.searchParams.set('chart_scale', 'relative');
                    } else {
                        url.searchParams.delete('chart_scale');
                    }
                    window.syncComparisonState(url.toString());
                });
            });

            renderComparisonChart();

            window._comparisonChartResizeObserver?.disconnect();
            if ('ResizeObserver' in window && chartCanvas.parentElement) {
                window._comparisonChartResizeObserver = new ResizeObserver((entries) => {
                    for (const entry of entries) {
                        if (entry.contentRect.width > 0 && entry.contentRect.height > 0 && window._activeComparisonChart) {
                            window._activeComparisonChart.resize();
                        }
                    }
                });
                window._comparisonChartResizeObserver.observe(chartCanvas.parentElement);
            }

            const materialFilter = document.getElementById('comparisonMaterialFilter');
            if (materialFilter) {
                // PUR-05: the shared material state travels in the URL across tabs.
                materialFilter.addEventListener('change', function() {
                    const option = this.selectedOptions[0];
                    const url = new URL(window.location.href);
                    if (this.value && option?.dataset.materialName) {
                        url.searchParams.set('material_name', option.dataset.materialName);
                        url.searchParams.set('shape', option.dataset.shape || '');
                    } else {
                        url.searchParams.delete('material_name');
                        url.searchParams.delete('shape');
                    }
                    window.syncComparisonState(url.toString());
                });
                materialFilter.addEventListener('change', function() {
                    const materialId = this.value;
                    document.querySelectorAll('[data-comparison-row]').forEach((row) => {
                        row.classList.toggle('tw:hidden', materialId !== '' && row.dataset.materialId !== materialId);
                    });

                    // A supplier without an offer for the material stays a gap (subset keeps null).
                    chartState.materialIndex = materialId === '' ? -1 : comparisonMaterialIds.indexOf(materialId);
                    renderComparisonChart();
                });

                // Preselect from the shared URL state (another tab may have chosen the material).
                const params = new URLSearchParams(window.location.search);
                const wanted = params.get('material_name');
                if (wanted) {
                    const options = [...materialFilter.options];
                    const match = options.find((o) => o.dataset.materialName === wanted && (o.dataset.shape || '') === (params.get('shape') || ''))
                        || options.find((o) => o.dataset.materialName === wanted);
                    if (match) {
                        materialFilter.value = match.value;
                        if (typeof window.dispatchEvent === 'function' && typeof CustomEvent === 'function') {
                            window.dispatchEvent(new CustomEvent('set-select-value', {
                                detail: { id: 'comparisonMaterialFilter', value: match.value, silent: true },
                            }));
                        }
                        materialFilter.dispatchEvent(new Event('change'));
                    }
                }
            }
        }

        // B5: Price Benchmark Year & Month filter controls
        const benchmarkYearSelect = document.getElementById('benchmarkYearSelect');
        const benchmarkMonthSelect = document.getElementById('benchmarkMonthSelect');
        const benchmarkResetBtn = document.getElementById('benchmarkPeriodResetBtn');
        const benchmarkYearInput = document.getElementById('comparisonBenchmarkYearInput');
        const benchmarkMonthInput = document.getElementById('comparisonBenchmarkMonthInput');

        const submitBenchmarkPeriodFilter = () => {
            if (benchmarkYearInput && benchmarkYearSelect) {
                benchmarkYearInput.value = benchmarkYearSelect.value;
            }
            if (benchmarkMonthInput && benchmarkMonthSelect) {
                benchmarkMonthInput.value = benchmarkMonthSelect.value;
            }
            comparisonFilterForm.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        };

        benchmarkYearSelect?.addEventListener('change', submitBenchmarkPeriodFilter);
        benchmarkMonthSelect?.addEventListener('change', submitBenchmarkPeriodFilter);
        benchmarkResetBtn?.addEventListener('click', () => {
            if (benchmarkYearSelect) benchmarkYearSelect.value = '';
            if (benchmarkMonthSelect) benchmarkMonthSelect.value = '';
            if (typeof window.dispatchEvent === 'function' && typeof CustomEvent === 'function') {
                window.dispatchEvent(new CustomEvent('set-select-value', { detail: { id: 'benchmarkYearSelect', value: '', silent: true } }));
                window.dispatchEvent(new CustomEvent('set-select-value', { detail: { id: 'benchmarkMonthSelect', value: '', silent: true } }));
            }
            submitBenchmarkPeriodFilter();
        });

        // Award previews & PO grouping
        const expandedSupplierGroups = new Set();
        const previewI18n = {
            emptyOffers: @json(__('purchasing.copy.no_items_selected_yet_select_supplier_offers_above')),
            emptyGroups: @json(__('purchasing.copy.1_po_will_be_created_per_selected_supplier_group_upon_confirmation')),
            noMatches: @json(__('purchasing.copy.no_matching_selected_offers')),
            colMaterial: @json(__('purchasing.copy.material')),
            colSupplier: @json(__('purchasing.copy.supplier')),
            colPrice: @json(__('purchasing.copy.price_kg_idr')),
            showLess: @json(__('purchasing.copy.show_less_materials')),
            moreTemplate: @json(__('purchasing.copy.more_materials_count')),
            summaryTemplate: @json(__('purchasing.copy.selected_offers_summary')),
            summaryEmpty: @json(__('purchasing.copy.selected_offers_summary_empty')),
        };
        const MAX_COLLAPSED_GROUP_ITEMS = 5;

        const applyPreviewSearchFilter = () => {
            const searchInput = document.getElementById('awardPreviewSearch');
            const previewContainer = document.getElementById('awardPreviewItems');
            if (!searchInput || !previewContainer) return;

            const query = searchInput.value.trim().toLowerCase();
            const rows = previewContainer.querySelectorAll('tbody tr[data-preview-row]');
            if (rows.length === 0) return;

            let visibleCount = 0;
            rows.forEach(row => {
                const haystack = row.dataset.searchText || '';
                const matched = query === '' || haystack.includes(query);
                row.hidden = !matched;
                if (matched) visibleCount++;
            });

            const emptyFilterRow = previewContainer.querySelector('[data-preview-empty-filter]');
            if (emptyFilterRow) {
                emptyFilterRow.hidden = visibleCount > 0;
            }
        };

        const updateAwardPreviews = () => {
            const radios = document.querySelectorAll('.award-radio:checked');
            const previewContainer = document.getElementById('awardPreviewItems');
            const groupContainer = document.getElementById('supplierGroupPreview');
            const searchInput = document.getElementById('awardPreviewSearch');
            const countBadge = document.getElementById('awardPreviewCountBadge');
            const groupCountBadge = document.getElementById('supplierGroupCountBadge');
            const footerSummary = document.getElementById('awardPreviewFooterSummary');

            if (!previewContainer || !groupContainer) return;

            const textElement = (tag, className, text) => {
                const element = document.createElement(tag);
                if (className) element.className = className;
                element.textContent = text;
                return element;
            };

            if (radios.length === 0) {
                if (searchInput) {
                    searchInput.value = '';
                    searchInput.disabled = true;
                }
                if (countBadge) countBadge.classList.add('tw:hidden');
                if (groupCountBadge) groupCountBadge.classList.add('tw:hidden');
                if (footerSummary) footerSummary.textContent = previewI18n.summaryEmpty;

                previewContainer.replaceChildren(textElement('div', 'tw:p-3 tw:text-on-surface-variant', previewI18n.emptyOffers));
                groupContainer.replaceChildren(textElement('div', 'tw:p-0.5 tw:text-on-surface-variant', previewI18n.emptyGroups));
                return;
            }

            if (searchInput) {
                searchInput.disabled = false;
            }

            const itemsList = textElement('table', 'ui-table ui-table--sm ui-table--hover tw:align-middle tw:mb-0 tw:text-ui-xs', '');
            const thead = textElement('thead', 'tw:bg-surface-container-low tw:sticky tw:top-0 tw:z-1', '');
            const headRow = document.createElement('tr');
            const thNum = textElement('th', 'tw:text-end tw:w-8', '#');
            thNum.scope = 'col';
            const thMaterial = textElement('th', '', previewI18n.colMaterial);
            thMaterial.scope = 'col';
            const thSupplier = textElement('th', '', previewI18n.colSupplier);
            thSupplier.scope = 'col';
            const thPrice = textElement('th', 'tw:text-end tw:whitespace-nowrap', previewI18n.colPrice);
            thPrice.scope = 'col';
            headRow.append(thNum, thMaterial, thSupplier, thPrice);
            thead.append(headRow);

            const tbody = document.createElement('tbody');
            const supplierMap = new Map();

            radios.forEach((radio, index) => {
                const itemName = radio.dataset.prItemName || '';
                const supplierName = radio.dataset.supplierName || '';
                const supplierId = radio.dataset.supplierId || '';
                const price = radio.dataset.priceDisplay || '';

                const tr = document.createElement('tr');
                tr.dataset.previewRow = '1';
                tr.dataset.searchText = `${itemName} ${supplierName}`.toLowerCase();

                const tdNum = textElement('td', 'tw:text-end tw:text-on-surface-variant ui-tabular-nums', String(index + 1));
                const tdMaterial = textElement('td', 'tw:font-semibold tw:text-on-surface', itemName);
                const tdSupplier = textElement('td', 'tw:text-primary tw:font-semibold', supplierName);
                const tdPrice = textElement('td', 'tw:text-end ui-tabular-nums tw:whitespace-nowrap tw:text-on-surface', price);
                tr.append(tdNum, tdMaterial, tdSupplier, tdPrice);
                tbody.append(tr);

                if (!supplierMap.has(supplierId)) {
                    supplierMap.set(supplierId, { id: supplierId, name: supplierName, items: [] });
                }
                supplierMap.get(supplierId).items.push(itemName);
            });

            const emptyFilterRow = document.createElement('tr');
            emptyFilterRow.dataset.previewEmptyFilter = '1';
            emptyFilterRow.hidden = true;
            const emptyFilterCell = textElement('td', 'tw:text-center tw:py-4 tw:text-on-surface-variant', previewI18n.noMatches);
            emptyFilterCell.colSpan = 4;
            emptyFilterRow.append(emptyFilterCell);
            tbody.append(emptyFilterRow);

            itemsList.append(thead, tbody);
            previewContainer.replaceChildren(itemsList);
            applyPreviewSearchFilter();

            const groups = textElement('div', 'tw:space-y-2', '');
            let poCount = 0;
            for (const supplier of supplierMap.values()) {
                poCount++;
                const group = textElement('div', 'tw:p-2.5 tw:rounded-ui-sm tw:bg-surface tw:border tw:border-outline-variant', '');

                const header = textElement('div', 'tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-2 tw:mb-2', '');
                const title = textElement('div', 'tw:font-bold tw:text-ui-xs tw:text-primary', `PO #${poCount} · ${supplier.name}`);
                const itemCountChip = textElement(
                    'span',
                    'ui-status-chip ui-status-chip--neutral',
                    window.AdasiI18n.choice('purchasing.js.item_count', supplier.items.length, { count: supplier.items.length })
                );
                header.append(title, itemCountChip);

                const chipsWrap = textElement('div', 'tw:flex tw:flex-wrap tw:gap-1', '');
                const hasOverflow = supplier.items.length > MAX_COLLAPSED_GROUP_ITEMS;
                const isExpanded = expandedSupplierGroups.has(supplier.id);
                const visibleItems = hasOverflow && !isExpanded
                    ? supplier.items.slice(0, MAX_COLLAPSED_GROUP_ITEMS)
                    : supplier.items;

                visibleItems.forEach(itemName => {
                    chipsWrap.append(
                        textElement('span', 'tw:inline-flex tw:items-center tw:rounded-ui-xs tw:border tw:border-outline-variant tw:bg-surface-low tw:px-1.5 tw:py-0.5 tw:text-ui-xs tw:text-on-surface', itemName)
                    );
                });

                group.append(header, chipsWrap);

                if (hasOverflow) {
                    const toggleBtn = document.createElement('button');
                    toggleBtn.type = 'button';
                    toggleBtn.className = 'ui-focus-ring tw:border-0 tw:bg-transparent tw:p-0 tw:mt-1.5 tw:text-ui-xs tw:font-semibold tw:text-primary-text tw:hover:underline tw:cursor-pointer';
                    toggleBtn.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
                    toggleBtn.textContent = isExpanded
                        ? previewI18n.showLess
                        : previewI18n.moreTemplate.replace(':count', String(supplier.items.length - MAX_COLLAPSED_GROUP_ITEMS));
                    toggleBtn.addEventListener('click', () => {
                        if (expandedSupplierGroups.has(supplier.id)) {
                            expandedSupplierGroups.delete(supplier.id);
                        } else {
                            expandedSupplierGroups.add(supplier.id);
                        }
                        updateAwardPreviews();
                    });
                    group.append(toggleBtn);
                }

                groups.append(group);
            }
            groupContainer.replaceChildren(groups);

            if (countBadge) {
                countBadge.textContent = window.AdasiI18n.choice('purchasing.js.item_count', radios.length, { count: radios.length });
                countBadge.classList.remove('tw:hidden');
            }
            if (groupCountBadge) {
                groupCountBadge.textContent = window.AdasiI18n.choice('purchasing.js.purchase_order_count', poCount, { count: poCount });
                groupCountBadge.classList.remove('tw:hidden');
            }
            if (footerSummary) {
                footerSummary.textContent = previewI18n.summaryTemplate
                    .replace(':items', String(radios.length))
                    .replace(':pos', String(poCount));
            }

            const arrivalInput = document.getElementById('poEstimatedArrival');
            const warningEl = document.getElementById('targetArrivalWarning');
            if (arrivalInput && warningEl) {
                const arrivalVal = arrivalInput.value;
                let isEarlier = false;
                if (arrivalVal) {
                    radios.forEach(radio => {
                        const readyDate = radio.dataset.supplierEstimatedReady;
                        if (readyDate && arrivalVal < readyDate) {
                            isEarlier = true;
                        }
                    });
                }
                warningEl.classList.toggle('tw:hidden', !isEarlier);
            }
        };

        const awardPreviewSearchInput = document.getElementById('awardPreviewSearch');
        if (awardPreviewSearchInput) {
            awardPreviewSearchInput.addEventListener('input', applyPreviewSearchFilter);
        }

        // M04-W: only the chosen offer's Order Qty / acceptance controls are
        // active; disabled inputs are not submitted, so one value per PR item.
        const syncOrderQtyControls = () => {
            document.querySelectorAll('.award-radio').forEach(radio => {
                const control = radio.closest('td')?.querySelector('[data-order-qty-control]');
                if (!control) return;
                control.hidden = !radio.checked;
                control.querySelectorAll('input').forEach(input => { input.disabled = !radio.checked; });
            });
        };
        const refreshOrderQtyHint = (input) => {
            const control = input.closest('[data-order-qty-control]');
            const hint = control?.querySelector('[data-order-qty-hint]');
            if (!hint) return;
            const value = Number(input.value);
            const delta = Number.isInteger(value) ? value - Number(control.dataset.requested) : null;
            hint.textContent = hint.dataset.template.replace('__DELTA__', delta === null ? '—' : (delta > 0 ? `+${delta}` : String(delta)));
        };
        document.querySelectorAll('[data-order-qty-input]').forEach(input => {
            input.addEventListener('input', () => refreshOrderQtyHint(input));
        });

        document.querySelectorAll('.award-radio').forEach(radio => {
            radio.addEventListener('change', updateAwardPreviews);
            radio.addEventListener('change', syncOrderQtyControls);
        });
        syncOrderQtyControls();

        const poEstimatedArrivalInput = document.getElementById('poEstimatedArrival');
        if (poEstimatedArrivalInput) {
            poEstimatedArrivalInput.addEventListener('change', updateAwardPreviews);
            poEstimatedArrivalInput.addEventListener('input', updateAwardPreviews);
        }

        updateAwardPreviews();
    };

    // ─── 2. HISTORICAL LOGIC ──────────────────────────────────────────────────
    window.initHistoricalComparison = function() {
        const configEl = document.getElementById('historicalConfig');
        if (!configEl) return;

        let config;
        try {
            config = JSON.parse(configEl.textContent);
        } catch (e) {
            window.AdasiDebug?.error('Invalid historicalConfig JSON', e);
            return;
        }

        const filterForm = document.getElementById('historicalFilterForm');
        const supplierSelect = document.getElementById('historicalSupplierSelect');
        const materialSelect = document.getElementById('historicalMaterialSelect');
        const rangeSelect = document.getElementById('historicalRangeSelect');
        const resultsContainer = document.getElementById('historicalResults');
        const resultsStatus = document.getElementById('historicalResultsStatus');
        const periodViewInputs = document.querySelectorAll('input[name="period_view"]');
        const materialsUrl = config.materialsUrl;
        const historicalDataUrl = config.historicalDataUrl;

        const rangeOptionSets = {
            monthly: config.monthlyRangeOptions || {},
            yearly: config.yearlyRangeOptions || {},
        };
        const rangeAliases = {
            monthly: { '1y': '12m', '2y': '24m' },
            yearly: { '3m': '1y', '6m': '1y', '12m': '1y', '24m': '2y' },
        };

        if (!filterForm || !supplierSelect || !materialSelect || !rangeSelect || periodViewInputs.length === 0) {
            return;
        }

        function announceHistoricalStatus(message) {
            if (!resultsStatus) return;
            resultsStatus.textContent = '';
            if (!message) return;
            const announce = () => {
                resultsStatus.textContent = message;
            };
            if (typeof window.requestAnimationFrame === 'function') {
                window.requestAnimationFrame(announce);
            } else {
                announce();
            }
        }

        function escapeOptionText(value) {
            return String(value ?? '').replace(/[&<>"']/g, (char) => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;',
            }[char]));
        }

        let historicalCurrency = config.currency || 'IDR';
        const preferredChartTypeByAxis = { period: null, material: null };
        let currentRenderedChartType = null;
        let preferredAxisMode = null;
        let currentRenderedAxisMode = null;
        let chartSwitchFrame = null;
        let latestHistoricalPayload = null;
        const sortLib = window.AdasiHistoricalPoDateSort;
        const sortDirInput = document.getElementById('historicalSortDirInput');
        const supportingTable = document.getElementById('historicalSupportingTable');
        let currentSortDir = sortLib ? sortLib.normalizeDir(config.sortDir || sortDirInput?.value) : 'desc';
        let latestPagination = config.tablePagination || null;
        const supportingLib = window.AdasiHistoricalSupportingFilters;
        const supportingLabels = config.supportingFilterLabels || {};
        let appliedSupportingFilters = supportingLib
            ? supportingLib.normalize(config.supportingFilters || {})
            : { categories: [], doc_search: '', source_currencies: [], start_date: null, end_date: null, price_min: null, price_max: null, change_direction: null };
        let committingSupportingModal = false;

        const supportingModal = document.getElementById('historicalSupportingFilterModal');
        const supportingForm = document.getElementById('historicalSupportingFilterForm');
        const supportingCountEl = document.getElementById('historicalSupportingFilterCount');
        const supportingChipsEl = document.getElementById('historicalSupportingFilterChips');
        const supportingResetBtn = document.getElementById('historicalSupportingFilterResetBtn');
        const supportingNoDataActions = document.getElementById('historicalNoDataFilterActions');
        const supportingNoDataResetBtn = document.getElementById('historicalNoDataResetFiltersBtn');
        const supportingPriceRangeLabel = document.getElementById('historicalFilterPriceRangeLabel');
        const supportingCategoriesEl = document.getElementById('historicalFilterCategories');
        const supportingDocSearchEl = document.getElementById('historicalFilterDocSearch');
        const supportingCurrenciesEl = document.getElementById('historicalFilterSourceCurrencies');
        const supportingDateRangeWrapper = document.getElementById('historicalFilterPoDateRange');
        const supportingStartDateEl = document.getElementById('historicalFilterStartDate');
        const supportingEndDateEl = document.getElementById('historicalFilterEndDate');
        const supportingPriceMinEl = document.getElementById('historicalFilterPriceMin');
        const supportingPriceMaxEl = document.getElementById('historicalFilterPriceMax');

        function readSupportingFormValues() {
            const categories = supportingCategoriesEl
                ? Array.from(supportingCategoriesEl.selectedOptions || []).map((o) => o.value).filter(Boolean)
                : [];
            const sourceCurrencies = supportingCurrenciesEl
                ? Array.from(supportingCurrenciesEl.selectedOptions || []).map((o) => o.value).filter(Boolean)
                : [];
            const checkedDir = supportingForm?.querySelector('input[name="change_direction"]:checked')?.value || '';
            const raw = {
                categories,
                doc_search: supportingDocSearchEl?.value || '',
                source_currencies: sourceCurrencies,
                start_date: supportingStartDateEl?.value || null,
                end_date: supportingEndDateEl?.value || null,
                price_min: supportingPriceMinEl?.value || null,
                price_max: supportingPriceMaxEl?.value || null,
                change_direction: checkedDir || null,
            };
            return supportingLib ? supportingLib.normalize(raw) : raw;
        }

        function writeSupportingFormValues(state) {
            const s = supportingLib ? supportingLib.normalize(state) : state;
            if (supportingCategoriesEl) {
                Array.from(supportingCategoriesEl.options || []).forEach((opt) => {
                    opt.selected = s.categories.includes(String(opt.value));
                });
                window.dispatchEvent(new CustomEvent('set-multi-value', {
                    detail: { id: 'historicalFilterCategories', values: s.categories, silent: true },
                }));
            }
            if (supportingDocSearchEl) supportingDocSearchEl.value = s.doc_search || '';
            if (supportingCurrenciesEl) {
                Array.from(supportingCurrenciesEl.options || []).forEach((opt) => {
                    opt.selected = s.source_currencies.includes(String(opt.value));
                });
                window.dispatchEvent(new CustomEvent('set-multi-value', {
                    detail: { id: 'historicalFilterSourceCurrencies', values: s.source_currencies, silent: true },
                }));
            }
            if (supportingStartDateEl) {
                supportingStartDateEl.value = s.start_date || '';
                supportingStartDateEl.dispatchEvent(new Event('change', { bubbles: true }));
            }
            if (supportingEndDateEl) {
                supportingEndDateEl.value = s.end_date || '';
                supportingEndDateEl.dispatchEvent(new Event('change', { bubbles: true }));
            }
            supportingDateRangeWrapper?.dispatchEvent(new CustomEvent('adasi:calendar-reset'));
            if (supportingPriceMinEl) supportingPriceMinEl.value = s.price_min ?? '';
            if (supportingPriceMaxEl) supportingPriceMaxEl.value = s.price_max ?? '';
            const targetDir = s.change_direction || '';
            supportingForm?.querySelectorAll('input[name="change_direction"]').forEach((radio) => {
                radio.checked = radio.value === targetDir;
            });
        }

        function syncSupportingCurrencyLabel() {
            if (!supportingPriceRangeLabel) return;
            const tmpl = supportingLabels.priceRangeLabelTemplate || 'Price/kg Range (:currency)';
            supportingPriceRangeLabel.textContent = tmpl.replace(':currency', historicalCurrency);
        }

        function renderSupportingFilterUI() {
            const count = supportingLib ? supportingLib.countActive(appliedSupportingFilters) : 0;
            if (supportingCountEl) {
                supportingCountEl.textContent = String(count);
                supportingCountEl.classList.toggle('tw:hidden', count === 0);
            }
            if (supportingNoDataActions) {
                supportingNoDataActions.classList.toggle('tw:hidden', count === 0);
            }
            syncSupportingCurrencyLabel();
            if (!supportingChipsEl || !supportingLib) return;
            const chips = supportingLib.buildChips(appliedSupportingFilters, {
                chips: supportingLabels.chips || {},
                categoryLabels: supportingLabels.categoryLabels || {},
                changeLabels: supportingLabels.changeLabels || {},
                currency: historicalCurrency,
                formatPrice: (val) => formatMaxDecimals(val, 4),
            });
            supportingChipsEl.classList.toggle('tw:hidden', chips.length === 0);
            if (chips.length === 0) {
                supportingChipsEl.replaceChildren();
                return;
            }
            const removeTemplate = supportingLabels.removeTemplate || 'Remove filter :label';
            const chipHtml = chips.map((chip) => {
                const ariaRemove = escapeOptionText(removeTemplate.replace(':label', `${chip.label}: ${chip.value}`));
                return `
                    <span class="ui-filter-chip" data-filter-chip-key="${escapeOptionText(chip.key)}">
                        <span class="ui-filter-chip__key">${escapeOptionText(chip.label)}:</span>
                        <span class="ui-filter-chip__value">${escapeOptionText(chip.value)}</span>
                        <button type="button" class="ui-filter-chip__remove ui-focus-ring" data-supporting-chip-remove="${escapeOptionText(chip.key)}" aria-label="${ariaRemove}">
                            ${comparisonIcons.close}
                        </button>
                    </span>
                `;
            }).join('');
            supportingChipsEl.innerHTML = `
                <span class="ui-filter-chips__label">${escapeOptionText(supportingLabels.active || 'Active filters:')}</span>
                ${chipHtml}
                <button type="button" class="ui-filter-chips__clear ui-focus-ring" data-supporting-chips-clear>${escapeOptionText(supportingLabels.clearAll || 'Clear all')}</button>
            `;
        }

        function applySupportingStateAndReload(nextState) {
            appliedSupportingFilters = supportingLib ? supportingLib.normalize(nextState) : nextState;
            writeSupportingFormValues(appliedSupportingFilters);
            renderSupportingFilterUI();
            if (hasSelectedMaterials()) {
                window.loadHistorycalPayloadFromFilters(1);
            } else {
                syncUrlFromForm();
            }
        }

        function formatRupiah(value) {
            return window.formatAnalyticMoney(value, historicalCurrency);
        }

        function formatNumber(value, decimals = 2) {
            if (value === null || value === undefined || value === '') return '-';
            const text = Number(value).toLocaleString('id-ID', {
                minimumFractionDigits: decimals,
                maximumFractionDigits: decimals,
            });
            return window.AdasiPreferences?.displayNumber ? window.AdasiPreferences.displayNumber(text, 'indonesian') : text;
        }

        function formatMaxDecimals(value, maxDecimals = 2) {
            if (value === null || value === undefined || value === '') return '-';
            const text = Number(value).toLocaleString('id-ID', {
                minimumFractionDigits: 0,
                maximumFractionDigits: maxDecimals,
            });
            return window.AdasiPreferences?.displayNumber ? window.AdasiPreferences.displayNumber(text, 'indonesian') : text;
        }

        function formatPercent(value) {
            if (value === null || value === undefined) return '-';
            const text = Number(value).toLocaleString('id-ID', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            });
            const formatted = window.AdasiPreferences?.displayNumber ? window.AdasiPreferences.displayNumber(text, 'indonesian') : text;
            return (Number(value) > 0 ? '+' : '') + formatted + '%';
        }

        function changeHtml(value) {
            if (value === null || value === undefined) return '<span class="tw:text-on-surface-variant">-</span>';
            const numberValue = Number(value);

            if (numberValue > 0) {
                return `<span class="tw:font-semibold tw:text-error">+${formatNumber(numberValue)}%</span>`;
            }

            if (numberValue < 0) {
                return `<span class="tw:font-semibold tw:text-success">&minus;${formatNumber(Math.abs(numberValue))}%</span>`;
            }

            return '<span class="tw:font-semibold tw:text-on-surface-variant">0%</span>';
        }

        function seriesDotHtml(seriesIndex, sizeClass = 'tw:size-2.5') {
            const slot = ((Number(seriesIndex) || 0) % 6) + 1;
            const hiddenAttr = currentRenderedAxisMode === 'material' ? ' hidden' : '';
            return `<span data-historical-material-dot${hiddenAttr} class="tw:inline-block ${sizeClass} tw:shrink-0 tw:rounded-full" style="background-color: var(--md-chart-series-${slot});" aria-hidden="true"></span>`;
        }

        function parseMaterialKey(raw) {
            const text = String(raw || '').trim();
            const pipeIdx = text.lastIndexOf('|');
            if (pipeIdx < 0) {
                return { name: text, shape: '' };
            }
            return {
                name: text.slice(0, pipeIdx).trim(),
                shape: text.slice(pipeIdx + 1).trim(),
            };
        }

        function encodeMaterialKey(name, shape) {
            return String(name || '') + '|' + String(shape || '');
        }

        function dispatchSearchableSelectOptions(id, options, { value = undefined, disabled = undefined, placeholder = undefined, silent = true } = {}) {
            if (typeof window.dispatchEvent === 'function' && typeof CustomEvent === 'function') {
                window.dispatchEvent(new CustomEvent('set-select-options', {
                    detail: { id, options, value, disabled, placeholder, silent },
                }));
            }
        }

        function dispatchMultiSelectOptions(id, options, { selected = [], emptyPlaceholder = null, silent = true } = {}) {
            if (typeof window.dispatchEvent === 'function' && typeof CustomEvent === 'function') {
                window.dispatchEvent(new CustomEvent('set-multi-options', {
                    detail: { id, options, selected, emptyPlaceholder, autoSelect: false, silent },
                }));
            }
        }

        function renderRangeOptions(view, preferredValue) {
            const options = rangeOptionSets[view] || {};
            const values = Object.keys(options);
            const aliasedValue = (rangeAliases[view] && rangeAliases[view][preferredValue])
                ? rangeAliases[view][preferredValue]
                : preferredValue;
            const selectedValue = values.includes(aliasedValue)
                ? aliasedValue
                : (values.includes('all') ? 'all' : values[0]);

            rangeSelect.innerHTML = values.map((value) => (
                `<option value="${escapeOptionText(value)}"${value === selectedValue ? ' selected' : ''}>${escapeOptionText(options[value])}</option>`
            )).join('');
            dispatchSearchableSelectOptions(
                'historicalRangeSelect',
                values.map((value) => ({ value, label: options[value] })),
                { value: selectedValue, silent: true },
            );
        }

        const shapeFilter = document.getElementById('historicalShapeFilter');
        let materialCatalog = [];
        let shapeMismatchShown = false;

        function catalogFromDom() {
            return Array.from(materialSelect.options || []).filter((option) => option && option.value).map((option) => {
                let keyOptions = [];
                try { keyOptions = JSON.parse(option.dataset?.keyOptions || '[]'); } catch (e) { keyOptions = []; }
                const parsed = parseMaterialKey(option.value);
                const name = option.dataset?.name || parsed.name;
                const shape = option.dataset?.shape !== undefined ? option.dataset.shape : parsed.shape;
                return {
                    key: option.value.includes('|') ? option.value : encodeMaterialKey(name, shape),
                    name,
                    shape,
                    key_options: keyOptions,
                    label: (option.textContent || name).trim(),
                };
            });
        }

        function shapeDisplayLabel(shape) {
            if (!shapeFilter || !shape) return shape || '';
            const key = 'label' + shape.charAt(0).toUpperCase() + shape.slice(1).toLowerCase();
            return shapeFilter.dataset?.[key] || shape;
        }

        function renderMaterialOptions(materials, selectedKeys = []) {
            materialCatalog = Array.isArray(materials) ? materials.map((material) => {
                const name = material.name || '';
                const materialShape = material.shape || '';
                return {
                    ...material,
                    key: material.key || encodeMaterialKey(name, materialShape),
                    name,
                    shape: materialShape,
                    label: material.label || name,
                    key_options: Array.isArray(material.key_options) ? material.key_options : [],
                };
            }) : [];
            const shape = shapeFilter ? shapeFilter.value : '';
            const visible = shape ? materialCatalog.filter((material) => (material.shape || '') === shape) : materialCatalog;
            const wantedKeys = Array.isArray(selectedKeys) ? selectedKeys.map((k) => String(k)) : [];
            const keptKeys = visible.map((m) => m.key).filter((key) => wantedKeys.includes(key));

            const emptyPlaceholder = visible.length > 0
                ? window.AdasiI18n.t('purchasing.js.history_select_material')
                : window.AdasiI18n.t('purchasing.js.history_no_material');

            materialSelect.innerHTML = visible.map((material) => {
                const isSelected = keptKeys.includes(material.key) ? ' selected' : '';
                const keyOptionsJson = escapeOptionText(JSON.stringify(material.key_options || []));
                return `<option value="${escapeOptionText(material.key)}" data-name="${escapeOptionText(material.name)}" data-shape="${escapeOptionText(material.shape)}" data-key-options="${keyOptionsJson}"${isSelected}>${escapeOptionText(material.label)}</option>`;
            }).join('');
            materialSelect.disabled = visible.length === 0;

            const multiOptionsPayload = visible.map((material) => ({
                value: material.key,
                label: material.label,
                sublabel: material.shape ? shapeDisplayLabel(material.shape) : null,
                dataset: {
                    name: material.name,
                    shape: material.shape,
                    keyOptions: JSON.stringify(material.key_options || []),
                },
            }));

            dispatchMultiSelectOptions(
                'historicalMaterialSelect',
                multiOptionsPayload,
                {
                    selected: keptKeys,
                    emptyPlaceholder,
                    silent: true,
                },
            );
            dispatchMultiSelectOptions(
                'advanced-export-purchasing-price-history-materials',
                materialCatalog.map((material) => ({
                    value: material.key,
                    label: material.label,
                    sublabel: material.shape ? shapeDisplayLabel(material.shape) : null,
                })),
                {
                    selected: keptKeys,
                    silent: true,
                },
            );
        }

        const shapeInput = document.getElementById('historicalShapeInput');
        const keyDimension = document.getElementById('historicalKeyDimension');
        const keyDimensionLabel = document.getElementById('historicalKeyDimensionLabel');
        const keyOption = document.getElementById('historicalKeyOption');
        const keyMin = document.getElementById('historicalKeyMin');
        const keyMax = document.getElementById('historicalKeyMax');
        const keyHint = document.getElementById('historicalKeyHint');
        const keyLabels = keyDimension ? {
            Flat: keyDimension.dataset.labelFlat,
            Round: keyDimension.dataset.labelRound,
            Hollow: keyDimension.dataset.labelHollow,
            Mixed: keyDimension.dataset.labelMixed,
        } : {};

        function selectedMaterials() {
            const opts = Array.from(materialSelect.selectedOptions || []).filter((opt) => opt && opt.value);
            return opts.map((option) => {
                let keyOptions = [];
                try { keyOptions = JSON.parse(option.dataset?.keyOptions || '[]'); } catch (e) { keyOptions = []; }
                const parsed = parseMaterialKey(option.value);
                const name = option.dataset?.name || parsed.name;
                const shape = option.dataset?.shape !== undefined ? option.dataset.shape : parsed.shape;
                return {
                    key: option.value.includes('|') ? option.value : encodeMaterialKey(name, shape),
                    name,
                    shape,
                    label: (option.textContent || name).trim(),
                    keyOptions,
                };
            });
        }

        function hasSelectedMaterials() {
            return selectedMaterials().length > 0;
        }

        function displayKey(value) {
            const text = String(Number(value));
            return window.AdasiPreferences?.displayNumber ? window.AdasiPreferences.displayNumber(text, 'decimal') : text;
        }

        function mergedKeyOptions(materials) {
            const seen = new Map();
            materials.forEach((mat) => {
                (mat.keyOptions || []).forEach((val) => {
                    const num = Number(val);
                    if (Number.isFinite(num) && !seen.has(num)) {
                        seen.set(num, String(val));
                    }
                });
            });
            return Array.from(seen.entries()).sort((a, b) => a[0] - b[0]).map(([, str]) => str);
        }

        function syncKeyOption() {
            if (!keyOption || !keyMin || !keyMax) return;
            const chosen = selectedMaterials();
            const distinctShapes = [...new Set(chosen.map((m) => m.shape).filter((s) => Boolean(keyLabels[s])))];
            const isMixed = distinctShapes.length > 1;
            const isNone = chosen.length > 0 && distinctShapes.length === 0;
            const options = (chosen.length > 0 && !isMixed && !isNone && !shapeMismatchShown)
                ? mergedKeyOptions(chosen)
                : [];
            const min = keyMin.value.trim();
            const max = keyMax.value.trim();

            keyMin.disabled = keyMax.disabled = chosen.length === 0 || isNone || shapeMismatchShown;

            if (chosen.length === 0) {
                keyOption.innerHTML = `<option value="">${escapeOptionText(keyOption.dataset.labelAll)}</option>`;
                keyOption.disabled = true;
                dispatchSearchableSelectOptions('historicalKeyOption', [{ value: '', label: keyOption.dataset.labelAll || '' }], { value: '', disabled: true, silent: true });
                return;
            }
            if (isMixed) {
                keyOption.innerHTML = `<option value="">${escapeOptionText(keyOption.dataset.labelAll)}</option>`;
                keyOption.disabled = true;
                dispatchSearchableSelectOptions('historicalKeyOption', [{ value: '', label: keyOption.dataset.labelAll || '' }], { value: '', disabled: true, silent: true });
                return;
            }
            if (shapeMismatchShown || options.length === 0) {
                keyOption.innerHTML = `<option value="">${escapeOptionText(keyOption.dataset.labelNone)}</option>`;
                keyOption.disabled = true;
                dispatchSearchableSelectOptions('historicalKeyOption', [{ value: '', label: keyOption.dataset.labelNone || '' }], { value: '', disabled: true, silent: true });
                return;
            }
            const exact = min !== '' && min === max ? options.find((value) => Number(value) === Number(min)) : null;
            const custom = (min !== '' || max !== '') && !exact;
            keyOption.innerHTML = [
                `<option value="">${escapeOptionText(keyOption.dataset.labelAll)}</option>`,
                custom ? `<option value="__custom" selected>${escapeOptionText(keyOption.dataset.labelCustom)}</option>` : '',
                ...options.map((value) => `<option value="${escapeOptionText(value)}"${value === exact ? ' selected' : ''}>${escapeOptionText(displayKey(value))}</option>`),
            ].join('');
            keyOption.disabled = false;
            const searchableOpts = [
                { value: '', label: keyOption.dataset.labelAll || '' },
                ...(custom ? [{ value: '__custom', label: keyOption.dataset.labelCustom || '' }] : []),
                ...options.map((value) => ({ value: String(value), label: displayKey(value) })),
            ];
            dispatchSearchableSelectOptions('historicalKeyOption', searchableOpts, {
                value: custom ? '__custom' : (exact !== null && exact !== undefined ? String(exact) : ''),
                disabled: false,
                silent: true,
            });
        }

        function applyShape() {
            const chosen = selectedMaterials();
            const distinctShapes = [...new Set(chosen.map((m) => m.shape).filter(Boolean))];
            const keyShapes = distinctShapes.filter((s) => Boolean(keyLabels[s]));
            const isMixed = keyShapes.length > 1;
            const appliedShape = distinctShapes.length === 1 ? distinctShapes[0] : '';

            if (shapeInput) shapeInput.value = appliedShape;
            if (keyDimension) {
                keyDimension.hidden = chosen.length > 0 && keyShapes.length === 0 && !shapeMismatchShown;
            }
            if (keyDimensionLabel) {
                if (isMixed) {
                    keyDimensionLabel.textContent = keyLabels.Mixed || keyLabels.Flat;
                } else {
                    const displayShape = shapeMismatchShown
                        ? (shapeFilter?.value || appliedShape)
                        : (appliedShape || shapeFilter?.value || '');
                    keyDimensionLabel.textContent = keyLabels[displayShape] || keyLabels.Flat;
                }
            }
            if (keyHint && keyDimension) {
                keyHint.textContent = isMixed
                    ? (keyDimension.dataset.hintMixed || keyDimension.dataset.hintDefault || '')
                    : (keyDimension.dataset.hintDefault || '');
            }
            syncKeyOption();
        }

        function clearKeyBounds() {
            if (keyMin) keyMin.value = '';
            if (keyMax) keyMax.value = '';
        }

        // The URL has to describe what is on screen even when there is nothing to load.
        function syncUrlFromForm() {
            const url = new URL(filterForm.action, window.location.origin);
            for (const [key, value] of new FormData(filterForm).entries()) {
                const trimmed = String(value).trim();
                if (trimmed !== '') {
                    url.searchParams.append(key, trimmed);
                }
            }
            if (currentSortDir === 'desc') {
                url.searchParams.delete('sort_dir');
            } else {
                url.searchParams.set('sort_dir', 'asc');
            }
            supportingLib?.appendToParams(url.searchParams, appliedSupportingFilters);
            window.syncComparisonState(url.toString());
        }

        const materialsGuard = window.createComparisonRequestGuard();
        const payloadGuard = window.createComparisonRequestGuard();

        function showHistoricalState(mode, message = '') {
            const dataWrapper = document.getElementById('historicalDataWrapper');
            const noDataAlert = document.getElementById('historicalNoDataAlert');
            const noDataMsg = document.getElementById('historicalNoDataMessage');
            const emptyState = document.getElementById('historicalEmptyState');
            const emptyMsg = document.getElementById('historicalEmptyMessage');
            const errorState = document.getElementById('historicalErrorState');
            const errorMsg = document.getElementById('historicalErrorMessage');

            if (mode !== 'data' && window._activeComparisonChart) {
                try { window._activeComparisonChart.destroy(); } catch (e) {}
                window._activeComparisonChart = null;
            }

            if (dataWrapper) {
                dataWrapper.classList.toggle('tw:hidden', mode !== 'data');
                noDataAlert?.classList.toggle('tw:hidden', mode !== 'no-data');
                emptyState?.classList.toggle('tw:hidden', mode !== 'empty');
                errorState?.classList.toggle('tw:hidden', mode !== 'error');
                if (mode === 'no-data' && noDataMsg && message) noDataMsg.textContent = message;
                if (mode === 'empty' && emptyMsg && message) emptyMsg.textContent = message;
                if (mode === 'error' && errorMsg && message) errorMsg.textContent = message;
            } else if (resultsContainer && mode !== 'data') {
                resultsContainer.innerHTML = `<div class="tw:rounded-ui-md tw:border tw:border-outline tw:bg-surface tw:p-6 tw:text-center tw:text-ui-sm tw:text-on-surface-variant">${escapeOptionText(message)}</div>`;
            }

            if (message) {
                announceHistoricalStatus(message);
            }
        }

        function clearHistorycalResults(message) {
            latestHistoricalPayload = null;
            showHistoricalState('empty', message);
        }

        async function loadMaterialsForSupplier() {
            const supplierId = supplierSelect.value;
            const previousKeys = selectedMaterials().map((m) => m.key);
            const previousBounds = { min: keyMin?.value.trim() || '', max: keyMax?.value.trim() || '' };
            const request = materialsGuard.start();
            shapeMismatchShown = false;

            renderMaterialOptions([], []);
            materialSelect.disabled = true;
            clearHistorycalResults(@json(__('purchasing.copy.comparison_select_material_from_supplier')));
            resultsContainer?.setAttribute('aria-busy', 'true');

            try {
                const url = new URL(materialsUrl, window.location.origin);
                if (supplierId) {
                    url.searchParams.set('supplier_id', supplierId);
                }

                const response = await fetch(url.toString(), {
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    signal: request.signal,
                });

                if (!response.ok) {
                    throw new Error();
                }

                const data = await response.json();
                if (!materialsGuard.isCurrent(request.id)) return;
                const materials = data.materials || [];
                renderMaterialOptions(materials, previousKeys);

                const kept = selectedMaterials();
                if (materialSelect.disabled) {
                    clearHistorycalResults(window.AdasiI18n.t('purchasing.js.history_no_material'));
                }
                const exact = previousBounds.min !== '' && previousBounds.min === previousBounds.max;
                const mergedOpts = mergedKeyOptions(kept);
                if (kept.length === 0 || (exact && !mergedOpts.some((value) => Number(value) === Number(previousBounds.min)))) {
                    clearKeyBounds();
                }
                applyShape();

                if (kept.length > 0 && typeof window.loadHistorycalPayloadFromFilters === 'function') {
                    await window.loadHistorycalPayloadFromFilters();
                } else {
                    syncUrlFromForm();
                }
            } catch (error) {
                if (error?.name === 'AbortError' || !materialsGuard.isCurrent(request.id)) return;
                renderMaterialOptions([], []);
                clearHistorycalResults(@json(__('purchasing.copy.comparison_failed_material_list')));
            } finally {
                if (materialsGuard.isCurrent(request.id)) resultsContainer?.setAttribute('aria-busy', 'false');
            }
        }

        function syncMaterialSeriesDots(activeAxisMode) {
            const hideDots = activeAxisMode === 'material';
            document.querySelectorAll('#historicalResults [data-historical-material-dot]').forEach((dot) => {
                dot.hidden = hideDots;
            });
        }

        function updateChartAxisSwitchUI(activeAxisMode) {
            const axisSwitchEl = document.getElementById('historicalChartAxisSwitch');
            if (!axisSwitchEl) return;
            axisSwitchEl.querySelectorAll('[data-historical-axis-mode]').forEach((btn) => {
                const isActive = btn.dataset.historicalAxisMode === activeAxisMode;
                btn.setAttribute('aria-pressed', isActive ? 'true' : 'false');
                btn.classList.toggle('tw:bg-primary', isActive);
                btn.classList.toggle('tw:text-primary-foreground', isActive);
                btn.classList.toggle('tw:hover:bg-[var(--ui-primary-hover)]', isActive);
                btn.classList.toggle('tw:hover:text-primary-foreground', isActive);
                btn.classList.toggle('tw:bg-transparent', !isActive);
                btn.classList.toggle('tw:text-on-surface-variant', !isActive);
                btn.classList.toggle('tw:hover:bg-surface-container', !isActive);
                btn.classList.toggle('tw:hover:text-on-surface', !isActive);
            });
        }

        function updateChartAxisCapNote(built) {
            const noteEl = document.getElementById('historicalChartAxisCapNote');
            if (!noteEl) return;
            if (!built?.truncatedPeriods) {
                noteEl.hidden = true;
                noteEl.textContent = '';
                return;
            }
            const template = @json(__('purchasing.comparison_analytics.chart_axis_capped_note'));
            noteEl.textContent = String(template)
                .replace(':shown', String(built.shownPeriods))
                .replace(':total', String(built.totalPeriods));
            noteEl.hidden = false;
        }

        function updateChartTypeSwitchUI(showSwitch, activeType) {
            const switchEl = document.getElementById('historicalChartTypeSwitch');
            if (!switchEl) return;
            switchEl.hidden = !showSwitch;
            switchEl.classList.remove('tw:hidden');
            switchEl.querySelectorAll('[data-historical-chart-type]').forEach((btn) => {
                const isActive = btn.dataset.historicalChartType === activeType;
                btn.setAttribute('aria-pressed', isActive ? 'true' : 'false');
                btn.classList.toggle('tw:bg-primary', isActive);
                btn.classList.toggle('tw:text-primary-foreground', isActive);
                btn.classList.toggle('tw:hover:bg-[var(--ui-primary-hover)]', isActive);
                btn.classList.toggle('tw:hover:text-primary-foreground', isActive);
                btn.classList.toggle('tw:bg-transparent', !isActive);
                btn.classList.toggle('tw:text-on-surface-variant', !isActive);
                btn.classList.toggle('tw:hover:bg-surface-container', !isActive);
                btn.classList.toggle('tw:hover:text-on-surface', !isActive);
            });
        }

        function renderHistoricalChartSummary(chartOrConfig, axisMode) {
            const footer = document.getElementById('historicalChartFooter');
            const lead = document.getElementById('historicalChartSummaryLead');
            const details = document.getElementById('historicalChartSummaryDetails');
            const toggleText = document.getElementById('historicalChartSummaryToggleText');
            const list = document.getElementById('historicalChartSummaryList');
            const builder = window.AdasiHistoricalChart;
            if (!footer || !lead || !builder?.buildChartSummaryModel) return;

            const summaryI18n = {
                highest: @json(__('purchasing.comparison_analytics.chart_summary_overall_highest')),
                lowest: @json(__('purchasing.comparison_analytics.chart_summary_overall_lowest')),
                latest: @json(__('purchasing.comparison_analytics.chart_summary_latest')),
                breakdownToggle: @json(__('purchasing.comparison_analytics.chart_summary_breakdown_toggle')),
            };

            const model = builder.buildChartSummaryModel(chartOrConfig, {
                axisMode,
                formatMoney: formatRupiah,
            });

            if (!model.overallHigh && !model.overallLow) {
                lead.replaceChildren();
                if (details) details.hidden = true;
                const capNote = document.getElementById('historicalChartAxisCapNote');
                footer.hidden = !capNote || capNote.hidden;
                return;
            }

            lead.replaceChildren();
            const appendStat = (label, value, context) => {
                const stat = document.createElement('span');
                stat.className = 'ui-chart-summary-bar__stat';
                const lbl = document.createElement('span');
                lbl.className = 'ui-chart-summary-bar__stat-label';
                lbl.textContent = label + ':';
                const val = document.createElement('span');
                val.className = 'ui-chart-summary-bar__stat-value';
                val.textContent = value;
                stat.appendChild(lbl);
                stat.appendChild(val);
                if (context) {
                    const ctxEl = document.createElement('span');
                    ctxEl.className = 'ui-chart-summary-bar__stat-context';
                    ctxEl.textContent = '(' + context + ')';
                    stat.appendChild(ctxEl);
                }
                lead.appendChild(stat);
            };

            if (model.overallHigh) {
                appendStat(summaryI18n.highest, model.overallHigh.formatted, model.overallHigh.context);
            }
            if (model.overallLow) {
                appendStat(summaryI18n.lowest, model.overallLow.formatted, model.overallLow.context);
            }

            if (details && toggleText && list) {
                if (model.seriesRows.length > 0) {
                    toggleText.textContent = String(summaryI18n.breakdownToggle).replace(':count', String(model.seriesRows.length));
                    list.replaceChildren();
                    model.seriesRows.forEach((row) => {
                        const card = document.createElement('div');
                        card.className = 'ui-chart-summary-item ui-tabular-nums';

                        const head = document.createElement('div');
                        head.className = 'ui-chart-summary-item__head';
                        const swatch = document.createElement('span');
                        swatch.className = 'ui-chart-summary-item__swatch';
                        swatch.style.backgroundColor = row.swatchColor;
                        swatch.setAttribute('aria-hidden', 'true');
                        const title = document.createElement('span');
                        title.textContent = row.label;
                        head.appendChild(swatch);
                        head.appendChild(title);
                        card.appendChild(head);

                        const rows = document.createElement('div');
                        rows.className = 'ui-chart-summary-item__rows';
                        const addLine = (k, v, ctxLabel) => {
                            const line = document.createElement('div');
                            line.className = 'ui-chart-summary-item__row';
                            const left = document.createElement('span');
                            left.textContent = k;
                            const right = document.createElement('strong');
                            right.textContent = ctxLabel ? (v + ' (' + ctxLabel + ')') : v;
                            line.appendChild(left);
                            line.appendChild(right);
                            rows.appendChild(line);
                        };
                        addLine(summaryI18n.highest, row.highestValue, row.highestLabel);
                        addLine(summaryI18n.lowest, row.lowestValue, row.lowestLabel);
                        if (row.latestValue !== null) {
                            addLine(summaryI18n.latest, row.latestValue, row.latestLabel);
                        }
                        card.appendChild(rows);
                        list.appendChild(card);
                    });
                    details.hidden = false;
                } else {
                    details.hidden = true;
                }
            }

            footer.hidden = false;
        }

        function renderHistorycalChart(payload, { isSwitch = false } = {}) {
            const builder = window.AdasiHistoricalChart;
            const materialCount = Array.isArray(payload?.chartData?.series) && payload.chartData.series.length > 0
                ? payload.chartData.series.length
                : 1;
            const resolvedAxisMode = builder?.resolveAxisMode
                ? builder.resolveAxisMode(materialCount, preferredAxisMode)
                : (materialCount >= 2 ? 'material' : 'period');
            currentRenderedAxisMode = resolvedAxisMode;
            updateChartAxisSwitchUI(resolvedAxisMode);
            syncMaterialSeriesDots(resolvedAxisMode);

            const canvas = document.getElementById('historicalChart');
            if (!canvas || !payload?.chartData || !window.AdasiChart || typeof Chart === 'undefined' || !builder) {
                if (window._activeComparisonChart) {
                    try { window._activeComparisonChart.destroy(); } catch (e) {}
                    window._activeComparisonChart = null;
                }
                if (!payload?.chartData) {
                    updateChartAxisCapNote(null);
                    const footer = document.getElementById('historicalChartFooter');
                    if (footer) footer.hidden = true;
                }
                return;
            }

            const preferredForAxis = preferredChartTypeByAxis[resolvedAxisMode] ?? null;
            const reduced = Boolean(window.AdasiChart?.reducedMotion?.());
            const built = builder.buildConfig(payload.chartData, preferredForAxis, {
                theme: window.AdasiChart,
                currency: historicalCurrency,
                formatMoney: formatRupiah,
                formatNumber: (val) => formatNumber(val),
                axisMode: resolvedAxisMode,
                animationDuration: reduced ? 0 : (isSwitch ? 180 : null),
                i18n: {
                    weightedAverage: window.AdasiI18n.t('purchasing.js.weighted_average_price_kg'),
                    average: @json(__('charts.series.average')),
                    highest: @json(__('charts.series.highest')),
                    lowest: @json(__('charts.series.lowest')),
                    historyBasis: @json(__('purchasing.comparison_analytics.history_basis')),
                    vsPrev: @json(__('purchasing.comparison_analytics.tooltip_vs_prev')),
                    groupLowestBadge: @json(__('purchasing.comparison_analytics.tooltip_group_lowest_badge')),
                    groupHighestBadge: @json(__('purchasing.comparison_analytics.tooltip_group_highest_badge')),
                },
            });

            const summarySyncPlugin = {
                id: 'adasiHistoricalSummarySync',
                afterUpdate(chart) {
                    renderHistoricalChartSummary(chart, built.axisMode);
                },
            };
            built.config.plugins = [...(built.config.plugins || []), summarySyncPlugin];

            currentRenderedChartType = built.type;
            currentRenderedAxisMode = built.axisMode;
            updateChartAxisSwitchUI(built.axisMode);
            updateChartTypeSwitchUI(true, built.type);
            updateChartAxisCapNote(built);
            syncMaterialSeriesDots(built.axisMode);

            const plotEl = canvas.closest?.('[data-adasi-chart-plot]');
            const mountChart = () => {
                if (window._activeComparisonChart) {
                    try { window._activeComparisonChart.destroy(); } catch (e) {}
                    window._activeComparisonChart = null;
                }
                window.AdasiChart?.fitScrollWidth?.(canvas, {
                    categories: built.config.data?.labels?.length || 0,
                    series: built.config.data?.datasets?.length || 1,
                });
                window._activeComparisonChart = new Chart(canvas, built.config);
                renderHistoricalChartSummary(window._activeComparisonChart, built.axisMode);
            };

            if (chartSwitchFrame) {
                cancelAnimationFrame(chartSwitchFrame);
                chartSwitchFrame = null;
            }

            if (isSwitch && plotEl && !reduced) {
                plotEl.classList.add('is-switching');
                chartSwitchFrame = requestAnimationFrame(() => {
                    mountChart();
                    chartSwitchFrame = requestAnimationFrame(() => {
                        plotEl.classList.remove('is-switching');
                        chartSwitchFrame = null;
                    });
                });
            } else {
                plotEl?.classList.remove('is-switching');
                mountChart();
            }
        }

        window._historicalChartRender = () => {
            if (latestHistoricalPayload?.chartData && document.getElementById('historicalChart')) {
                renderHistorycalChart(latestHistoricalPayload, { isSwitch: false });
            }
        };
        if (!window._historicalChartThemeListener && typeof window.addEventListener === 'function') {
            window._historicalChartThemeListener = true;
            ['adasi:theme-change', 'adasi:accent-change'].forEach((name) => {
                window.addEventListener(name, () => window._historicalChartRender?.());
            });
        }

        const chartAxisSwitch = document.getElementById('historicalChartAxisSwitch');
        chartAxisSwitch?.querySelectorAll('[data-historical-axis-mode]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const nextAxis = btn.dataset.historicalAxisMode === 'material' ? 'material' : 'period';
                if (currentRenderedAxisMode === nextAxis && preferredAxisMode === nextAxis) return;
                preferredAxisMode = nextAxis;
                if (nextAxis === 'material') {
                    preferredChartTypeByAxis.material = 'bar';
                }
                if (latestHistoricalPayload?.chartData) {
                    renderHistorycalChart(latestHistoricalPayload, { isSwitch: true });
                } else {
                    currentRenderedAxisMode = nextAxis;
                    updateChartAxisSwitchUI(nextAxis);
                    const nextFallbackType = nextAxis === 'material'
                        ? 'bar'
                        : (preferredChartTypeByAxis.period || currentRenderedChartType || 'line');
                    currentRenderedChartType = nextFallbackType;
                    updateChartTypeSwitchUI(true, nextFallbackType);
                    if (nextAxis !== 'material') {
                        updateChartAxisCapNote(null);
                    }
                    syncMaterialSeriesDots(nextAxis);
                }
            });
        });

        const chartTypeSwitch = document.getElementById('historicalChartTypeSwitch');
        chartTypeSwitch?.querySelectorAll('[data-historical-chart-type]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const nextType = btn.dataset.historicalChartType === 'bar' ? 'bar' : 'line';
                // Line chart always places Year/Month (period) on X-axis.
                const targetAxis = nextType === 'line'
                    ? 'period'
                    : (currentRenderedAxisMode === 'material' ? 'material' : 'period');
                if (currentRenderedChartType === nextType && currentRenderedAxisMode === targetAxis && preferredChartTypeByAxis[targetAxis] === nextType) return;
                if (nextType === 'line') {
                    preferredAxisMode = 'period';
                }
                preferredChartTypeByAxis[targetAxis] = nextType;
                if (latestHistoricalPayload?.chartData) {
                    renderHistorycalChart(latestHistoricalPayload, { isSwitch: true });
                } else {
                    currentRenderedAxisMode = targetAxis;
                    currentRenderedChartType = nextType;
                    updateChartAxisSwitchUI(targetAxis);
                    updateChartTypeSwitchUI(true, nextType);
                    if (targetAxis !== 'material') {
                        updateChartAxisCapNote(null);
                    }
                    syncMaterialSeriesDots(targetAxis);
                }
            });
        });

        function updateSummaryClass(element, value) {
            if (!element) return;
            element.classList.remove('tw:text-error', 'tw:text-success', 'tw:text-on-surface-variant');
            element.classList.add(value > 0 ? 'tw:text-error' : (value < 0 ? 'tw:text-success' : 'tw:text-on-surface-variant'));
        }

        function renderSummary(summary) {
            const singleSection = document.getElementById('historicalSingleSummary');
            const multiSection = document.getElementById('historicalMultiSummary');
            const multiBody = document.getElementById('historicalMultiSummaryBody');
            const multiExcluded = document.getElementById('historicalMultiExcludedNote');
            const isMulti = Boolean(summary?.is_multi && Array.isArray(summary?.per_material) && summary.per_material.length > 1);

            singleSection?.classList.toggle('tw:hidden', isMulti);
            multiSection?.classList.toggle('tw:hidden', !isMulti);

            if (isMulti) {
                if (multiBody) {
                    multiBody.innerHTML = summary.per_material.map((mat) => `
                        <tr>
                            <td class="tw:text-start tw:font-semibold tw:text-on-surface">
                                <span class="tw:inline-flex tw:items-center tw:gap-2">
                                    ${seriesDotHtml(mat.index)}
                                    <span>${escapeOptionText(mat.label || mat.material_name)}</span>
                                </span>
                            </td>
                            <td class="tw:text-end tw:font-semibold tw:text-success ui-tabular-nums tw:whitespace-nowrap">${mat.best != null ? formatRupiah(mat.best) : '-'}</td>
                            <td class="tw:text-end tw:font-bold tw:text-primary ui-tabular-nums tw:whitespace-nowrap">${mat.weighted_average != null ? formatRupiah(mat.weighted_average) : '-'}</td>
                            <td class="tw:text-end ui-tabular-nums tw:whitespace-nowrap">${changeHtml(mat.average_change_pct)}</td>
                            <td class="tw:text-end ui-tabular-nums tw:whitespace-nowrap">${changeHtml(mat.total_change_pct)}</td>
                            <td class="tw:text-end tw:text-ui-xs tw:text-on-surface-variant ui-tabular-nums tw:whitespace-nowrap">${escapeOptionText(window.AdasiI18n.t('purchasing.js.history_basis', { pos: mat.po_count || 0, kg: formatNumber(mat.total_kg || 0) }))}</td>
                        </tr>
                    `).join('');
                }
                if (multiExcluded) {
                    const lines = summary.excluded?.lines || 0;
                    multiExcluded.classList.toggle('tw:hidden', lines === 0);
                    multiExcluded.textContent = lines === 0 ? '' : window.AdasiI18n.t('purchasing.js.history_excluded', { lines, kg: formatNumber(summary.excluded.kg), currencies: (summary.excluded.currencies || []).join(', ') });
                }
                return;
            }

            const bestEl = document.getElementById('historicalBestPriceValue');
            if (bestEl) {
                bestEl.textContent = summary.best != null ? formatRupiah(summary.best) : '-';
            }
            const bestBasis = document.getElementById('historicalBestBasis');
            if (bestBasis) {
                bestBasis.textContent = window.AdasiI18n.t('purchasing.js.history_basis', { pos: summary.po_count || 0, kg: formatNumber(summary.total_kg || 0) });
            }
            const weighted = document.getElementById('historicalWeightedAverage');
            if (weighted) {
                weighted.textContent = summary.weighted_average != null ? formatRupiah(summary.weighted_average) : '-';
            }
            const basis = document.getElementById('historicalWeightedBasis');
            if (basis) {
                basis.textContent = @json(__('purchasing.copy.highest_price')) + ': ' + (summary.highest != null ? formatRupiah(summary.highest) : '-');
            }
            const excluded = document.getElementById('historicalExcludedNote');
            if (excluded) {
                const lines = summary.excluded?.lines || 0;
                excluded.classList.toggle('tw:hidden', lines === 0);
                excluded.textContent = lines === 0 ? '' : window.AdasiI18n.t('purchasing.js.history_excluded', { lines, kg: formatNumber(summary.excluded.kg), currencies: (summary.excluded.currencies || []).join(', ') });
            }
            const averageChange = document.getElementById('averageChangeValue');
            const totalChange = document.getElementById('totalChangeValue');
            if (averageChange) {
                averageChange.textContent = formatPercent(summary.average_change_pct);
                updateSummaryClass(averageChange, summary.average_change_pct);
            }
            if (totalChange) {
                totalChange.textContent = formatPercent(summary.total_change_pct);
                updateSummaryClass(totalChange, summary.total_change_pct);
            }
        }

        function renderTable(payload) {
            const head = document.getElementById('historicalTableHead');
            const body = document.getElementById('historicalTableBody');
            if (!head || !body) return;
            const rows = payload.tableData || [];
            const isMulti = Boolean(payload.chartData?.isMultiMaterial || payload.summary?.is_multi || (payload.chartData?.series && payload.chartData.series.length > 1));

            if (payload.periodView === 'yearly') {
                head.innerHTML = `
                    <tr>
                        <th scope="col" class="tw:text-start ui-sticky-col tw:whitespace-nowrap">${window.AdasiI18n.t('purchasing.js.year')}</th>
                        <th scope="col" class="tw:text-start">${escapeOptionText(@json(__('purchasing.copy.material')))}</th>
                        <th scope="col" class="tw:text-end tw:whitespace-nowrap">${escapeOptionText(@json(__('purchasing.comparison_analytics.volume')))}</th>
                        <th scope="col" class="tw:text-end tw:whitespace-nowrap">${window.AdasiI18n.t('purchasing.js.lowest_price')}</th>
                        <th scope="col" class="tw:text-end tw:whitespace-nowrap">${window.AdasiI18n.t('purchasing.js.weighted_average_price_kg')} (${escapeOptionText(historicalCurrency)})</th>
                        <th scope="col" class="tw:text-end tw:whitespace-nowrap">${window.AdasiI18n.t('purchasing.js.highest_price')}</th>
                        <th scope="col" class="tw:text-end tw:whitespace-nowrap">${escapeOptionText(@json(__('purchasing.comparison_analytics.yoy_change')))}</th>
                    </tr>
                `;
                body.innerHTML = rows.length === 0
                    ? `<tr><td colspan="7" class="tw:py-6 tw:text-center tw:text-ui-sm tw:text-on-surface-variant">${escapeOptionText(supportingLabels.noMatchingRows || 'No matching rows.')}</td></tr>`
                    : rows.map((row) => `
                    <tr>
                        <td class="tw:text-start tw:font-semibold ui-sticky-col ui-tabular-nums tw:whitespace-nowrap">${escapeOptionText(row.period)}</td>
                        <td class="tw:text-start tw:font-semibold ui-col-data-name">
                            <span class="tw:inline-flex tw:items-center tw:gap-2">
                                ${seriesDotHtml(row.series_index)}
                                <span>${escapeOptionText(row.material_label || row.material_name || payload.materialName || '-')}</span>
                            </span>
                        </td>
                        <td class="tw:text-end tw:text-ui-xs tw:text-on-surface-variant ui-tabular-nums tw:whitespace-nowrap">${escapeOptionText(window.AdasiI18n.t('purchasing.js.history_basis', { pos: row.po_count || 0, kg: formatNumber(row.total_kg || 0) }))}</td>
                        <td class="tw:text-end tw:font-semibold tw:text-success ui-tabular-nums tw:whitespace-nowrap">${row.min_idr != null ? formatRupiah(row.min_idr) : '-'}</td>
                        <td class="tw:text-end tw:text-primary tw:font-bold ui-tabular-nums tw:whitespace-nowrap">${row.price_idr != null ? formatRupiah(row.price_idr) : '-'}</td>
                        <td class="tw:text-end ui-tabular-nums tw:whitespace-nowrap">${row.max_idr != null ? formatRupiah(row.max_idr) : '-'}</td>
                        <td class="tw:text-end ui-tabular-nums tw:whitespace-nowrap">${changeHtml(row.change_pct)}</td>
                    </tr>
                `).join('');
                return;
            }

            const ariaSortValue = sortLib ? sortLib.ariaSortFor(currentSortDir) : (currentSortDir === 'asc' ? 'ascending' : 'descending');
            const convertedTotalHeaderText = `${window.AdasiI18n.t('purchasing.js.converted_total')} (${historicalCurrency})`;
            const existingSortBtn = head.querySelector('#historicalSortPoDateBtn');
            if (existingSortBtn) {
                existingSortBtn.closest('th')?.setAttribute('aria-sort', ariaSortValue);
                const convertedHeader = head.querySelector('[data-converted-total-header]');
                if (convertedHeader) {
                    convertedHeader.textContent = convertedTotalHeaderText;
                }
            } else {
                head.innerHTML = `
                    <tr>
                        <th scope="col" class="tw:text-start ui-sticky-col tw:whitespace-nowrap">${window.AdasiI18n.t('purchasing.js.pr_number')}</th>
                        <th scope="col" class="tw:text-start">${escapeOptionText(@json(__('purchasing.copy.material')))}</th>
                        <th scope="col" class="tw:text-start">${escapeOptionText(@json(__('purchasing.copy.dimensions')))}</th>
                        <th scope="col" class="tw:text-start">${escapeOptionText(@json(__('materials.reference_prices.rules.category_name')))}</th>
                        <th scope="col" class="tw:text-start">${window.AdasiI18n.t('purchasing.js.supplier')}</th>
                        <th scope="col" class="tw:text-end tw:whitespace-nowrap">${window.AdasiI18n.t('purchasing.js.price_kg')}</th>
                        <th scope="col" data-converted-total-header class="tw:text-end tw:whitespace-nowrap">${escapeOptionText(convertedTotalHeaderText)}</th>
                        <th scope="col" aria-sort="${ariaSortValue}" class="tw:text-start tw:whitespace-nowrap"><button type="button" id="historicalSortPoDateBtn" class="ui-table-sort-btn" commandfor="historicalSupportingTable" command="--sort-po-date"><span>${window.AdasiI18n.t('purchasing.js.po_date')}</span><span class="ui-table-sort-icon" aria-hidden="true">${comparisonIcons.sortDesc}</span></button></th>
                        <th scope="col" class="tw:text-end tw:whitespace-nowrap">${window.AdasiI18n.t('purchasing.js.change')}</th>
                    </tr>
                `;
            }
            body.innerHTML = rows.length === 0
                ? `<tr><td colspan="9" class="tw:py-6 tw:text-center tw:text-ui-sm tw:text-on-surface-variant">${escapeOptionText(supportingLabels.noMatchingRows || 'No matching rows.')}</td></tr>`
                : rows.map((row) => `
                <tr>
                    <td class="tw:text-start tw:font-semibold ui-sticky-col tw:whitespace-nowrap">
                        ${row.pr_url
                            ? `<a href="${escapeOptionText(row.pr_url)}" class="ui-focus-ring tw:rounded-ui-xs tw:text-primary tw:no-underline tw:hover:underline">${escapeOptionText(row.pr_number || '-')} &rarr;</a>`
                            : escapeOptionText(row.pr_number || '-')}
                    </td>
                    <td class="tw:text-start tw:font-semibold ui-col-data-name">
                        <span class="tw:inline-flex tw:items-center tw:gap-1.5">
                            ${isMulti ? seriesDotHtml(row.series_index, 'tw:size-2') : ''}
                            <span>${escapeOptionText(row.material_name || payload.materialName || '-')}</span>
                        </span>
                    </td>
                    <td class="tw:text-start ui-tabular-nums tw:whitespace-nowrap">${escapeOptionText(row.dimension_display || '-')}</td>
                    <td class="tw:text-start"><span class="ui-status-chip ui-status-chip--neutral">${escapeOptionText(row.range_classification || '-')}</span></td>
                    <td class="tw:text-start ui-col-data-name">${escapeOptionText(row.supplier || '-')}</td>
                    <td class="tw:text-end ui-tabular-nums tw:whitespace-nowrap">${formatMaxDecimals(row.price_per_kg, 4)} <span class="ui-status-chip ui-status-chip--neutral tw:ms-1">${escapeOptionText(row.currency)}</span><div class="tw:text-ui-xs tw:text-on-surface-variant">${escapeOptionText(row.annual_price_display || '')}</div></td>
                    <td class="tw:text-end tw:text-primary tw:font-bold ui-tabular-nums tw:whitespace-nowrap">${row.total_idr !== null ? formatRupiah(row.total_idr) : escapeOptionText(@json(__('purchasing.comparison_analytics.rate_unavailable')))}</td>
                    <td class="tw:text-start tw:whitespace-nowrap">${row.purchase_order_at_display ? escapeOptionText(row.purchase_order_at_display) : '<span class="ui-status-chip ui-status-chip--neutral">' + escapeOptionText(@json(__('purchasing.copy.draft'))) + '</span>'}</td>
                    <td class="tw:text-end ui-tabular-nums tw:whitespace-nowrap">${changeHtml(row.change_pct)}</td>
                </tr>
            `).join('');
        }

        function renderPagination(pagination) {
            const container = document.getElementById('historicalPagination');
            const summary = document.getElementById('historicalPaginationSummary');
            const pageLabel = document.getElementById('historicalPaginationPage');
            const previous = document.getElementById('historicalPreviousPage');
            const next = document.getElementById('historicalNextPage');

            if (!container || !summary || !pageLabel || !previous || !next) {
                return;
            }

            if (!pagination || Number(pagination.total || 0) === 0) {
                container.classList.add('tw:hidden');
                return;
            }

            const currentPage = Number(pagination.current_page || 1);
            const lastPage = Number(pagination.last_page || 1);
            summary.textContent = window.AdasiI18n.t('purchasing.js.pagination_summary', { from: pagination.from || 0, to: pagination.to || 0, total: pagination.total });
            pageLabel.textContent = window.AdasiI18n.t('purchasing.js.pagination_page', { current: currentPage, last: lastPage });
            previous.disabled = currentPage <= 1;
            previous.dataset.historyPage = String(Math.max(1, currentPage - 1));
            next.disabled = currentPage >= lastPage;
            next.dataset.historyPage = String(Math.min(lastPage, currentPage + 1));
            container.classList.remove('tw:hidden');
        }

        function syncSortDirState() {
            if (sortDirInput) {
                sortDirInput.value = currentSortDir;
            }
            const sortBtn = document.getElementById('historicalSortPoDateBtn');
            const sortTh = sortBtn?.closest('th');
            if (sortTh) {
                sortTh.setAttribute('aria-sort', sortLib ? sortLib.ariaSortFor(currentSortDir) : (currentSortDir === 'asc' ? 'ascending' : 'descending'));
            }
        }

        function announcePoDateSort(rowCount) {
            const column = window.AdasiI18n.t('purchasing.js.po_date');
            const directionLabel = currentSortDir === 'asc'
                ? (supportingLabels.sortPoDateAsc || 'Oldest first')
                : (supportingLabels.sortPoDateDesc || 'Newest first');
            const message = sortLib
                ? sortLib.formatAnnouncement(supportingLabels.sortAnnouncementTemplate, { column, directionLabel, count: rowCount })
                : `Sorted by ${column}, ${directionLabel}, ${rowCount} rows.`;
            announceHistoricalStatus(message);
        }

        function reorderSupportingRows(tbody, orderedRows) {
            if (sortLib) {
                sortLib.reorderRows(tbody, orderedRows);
            } else {
                for (const row of orderedRows) tbody.append(row);
            }
        }

        function handlePoDateSortToggle(button) {
            currentSortDir = sortLib ? sortLib.toggleDir(currentSortDir) : (currentSortDir === 'asc' ? 'desc' : 'asc');
            syncSortDirState();

            const tbody = document.getElementById('historicalTableBody');
            const dataRows = tbody
                ? Array.from(tbody.rows).filter((row) => !row.querySelector('td[colspan]'))
                : [];

            if (sortLib?.canReorderClientSide(latestPagination, dataRows.length)) {
                // Scrolled .ui-table-sticky containers must reorder in place without ViewTransition snapshots,
                // which lift tbody rows into a top-layer overlay above sticky headers.
                reorderSupportingRows(tbody, [...dataRows].reverse());
                if (button && document.activeElement !== button) {
                    button.focus({ preventScroll: true });
                }
                syncUrlFromForm();
                announcePoDateSort(dataRows.length);
                return;
            }

            if (dataRows.length <= 1 && (!latestPagination || Number(latestPagination.last_page || 1) === 1)) {
                if (button && document.activeElement !== button) {
                    button.focus({ preventScroll: true });
                }
                syncUrlFromForm();
                announcePoDateSort(dataRows.length);
                return;
            }

            if (hasSelectedMaterials()) {
                window.loadHistorycalPayloadFromFilters(1, { announceSort: true });
            } else {
                syncUrlFromForm();
                announcePoDateSort(0);
            }
        }

        function renderPayload(payload, options = {}) {
            historicalCurrency = payload.currency || historicalCurrency;
            latestPagination = payload.pagination || null;
            renderSupportingFilterUI();
            const annualRateList = document.querySelector('[data-annual-rate-list]');
            if (annualRateList && payload.evaluation?.annual_labels) {
                annualRateList.replaceChildren(...payload.evaluation.annual_labels.map((rate) => {
                    const item = document.createElement('li');
                    item.className = 'tw:list-none';
                    item.textContent = rate.label;
                    if (!rate.available) item.dataset.rateUnavailable = rate.currency;
                    return item;
                }));
            }
            if (!payload.chartData) {
                latestHistoricalPayload = null;
                latestPagination = null;
                const hasSelection = Boolean(payload.materialName || (payload.summary?.per_material && payload.summary.per_material.length > 0));
                const activeSupportingCount = supportingLib ? supportingLib.countActive(appliedSupportingFilters) : 0;
                const emptyMessage = hasSelection
                    ? (activeSupportingCount > 0 && supportingLabels.noDataWithFilters
                        ? supportingLabels.noDataWithFilters
                        : @json(__('purchasing.copy.comparison_no_quotation_data')))
                    : @json(__('purchasing.copy.comparison_select_supplier_and_material'));
                showHistoricalState(hasSelection ? 'no-data' : 'empty', emptyMessage);
                return;
            }

            latestHistoricalPayload = payload;
            showHistoricalState('data');
            renderHistorycalChart(payload);
            renderSummary(payload.summary || {});
            renderTable(payload);
            renderPagination(payload.pagination || null);

            const titleTextEl = document.getElementById('historicalChartTitleText');
            const seriesCount = Array.isArray(payload.chartData?.series) ? payload.chartData.series.length : 0;
            const matCount = seriesCount > 0 ? seriesCount : (payload.materialName ? 1 : 0);
            const materialLabel = matCount > 1
                ? @json(__('purchasing.comparison_analytics.multi_material_title')).replace(':count', String(matCount))
                : (payload.materialName || '');
            const titleText = @json(__('purchasing.copy.comparison_price_trend_title', ['material' => ':material', 'supplier' => ':supplier']))
                .replace(':material', materialLabel)
                .replace(':supplier', payload.supplierName || @json(__('purchasing.comparison_analytics.all_suppliers')));
            if (titleTextEl) {
                titleTextEl.textContent = titleText;
            }

            if (options.announceSort) {
                announcePoDateSort(Number(payload.pagination?.total ?? (payload.tableData || []).length));
                return;
            }

            const statusSummary = [
                titleText,
                payload.pagination?.total
                    ? window.AdasiI18n.t('purchasing.js.pagination_summary', {
                        from: payload.pagination.from || 0,
                        to: payload.pagination.to || 0,
                        total: payload.pagination.total,
                    })
                    : '',
            ].filter(Boolean).join('. ');
            announceHistoricalStatus(statusSummary);
        }

        window.loadHistorycalPayloadFromFilters = async function(historyPage = 1, options = {}) {
            if (!hasSelectedMaterials()) {
                return;
            }

            const url = new URL(historicalDataUrl, window.location.origin);
            const formData = new FormData(filterForm);
            for (const [key, value] of formData.entries()) {
                const trimmed = String(value).trim();
                if (trimmed !== '') {
                    url.searchParams.append(key, trimmed);
                }
            }
            supportingLib?.appendToParams(url.searchParams, appliedSupportingFilters);
            url.searchParams.set('view', 'json');
            if (Number(historyPage) > 1) {
                url.searchParams.set('history_page', String(historyPage));
            }
            const request = payloadGuard.start();
            window.AdasiLoader?.setTableBusy?.(resultsContainer, true, { mode: 'region' });
            resultsContainer?.setAttribute('aria-busy', 'true');

            const submitBtn = filterForm?.querySelector('button[type="submit"]');
            if (submitBtn && window.AdasiButton && typeof window.AdasiButton.startLoading === 'function') {
                window.AdasiButton.startLoading(submitBtn);
            }

            try {
                const response = await fetch(url.toString(), {
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    signal: request.signal,
                });

                if (!response.ok) {
                    throw new Error();
                }

                const payload = await response.json();
                if (!payloadGuard.isCurrent(request.id)) return;
                renderPayload(payload, options);

                url.searchParams.delete('view');
                if (currentSortDir === 'desc') {
                    url.searchParams.delete('sort_dir');
                } else {
                    url.searchParams.set('sort_dir', 'asc');
                }
                const resolvedPage = Number(payload.pagination?.current_page || 1);
                if (resolvedPage > 1) {
                    url.searchParams.set('history_page', String(resolvedPage));
                } else {
                    url.searchParams.delete('history_page');
                }
                window.syncComparisonState(url.toString());
            } catch (error) {
                if (error?.name === 'AbortError' || !payloadGuard.isCurrent(request.id)) return;
                showHistoricalState('error', @json(__('purchasing.copy.comparison_failed_historical_data')));
            } finally {
                if (payloadGuard.isCurrent(request.id)) {
                    if (submitBtn && window.AdasiButton && typeof window.AdasiButton.stopLoading === 'function') {
                        window.AdasiButton.stopLoading(submitBtn);
                    }
                    window.AdasiLoader?.setTableBusy?.(resultsContainer, false, { mode: 'region' });
                    resultsContainer?.setAttribute('aria-busy', 'false');
                }
            }
        };

        periodViewInputs.forEach((input) => {
            input.addEventListener('change', () => {
                renderRangeOptions(input.value, rangeSelect.value);
                if (hasSelectedMaterials()) {
                    window.loadHistorycalPayloadFromFilters();
                } else {
                    syncUrlFromForm();
                }
            });
        });

        supplierSelect.addEventListener('change', loadMaterialsForSupplier);

        shapeFilter?.addEventListener('change', () => {
            const currentList = selectedMaterials();
            const shape = shapeFilter.value;
            const currentKeys = currentList.map((m) => m.key);

            if (currentList.length === 0) {
                renderMaterialOptions(materialCatalog, []);
                clearHistorycalResults(window.AdasiI18n.t(materialSelect.disabled ? 'purchasing.js.history_no_material' : 'purchasing.js.history_select_material'));
                clearKeyBounds();
                applyShape();
                return;
            }

            // Narrow dropdown options to the chosen shape while retaining compatible selections.
            // If a single material was chosen and that same material name exists under the new shape, switch smoothly.
            let nextSelectedKeys = shape === ''
                ? currentKeys
                : currentList.filter((m) => (m.shape || '') === shape).map((m) => m.key);

            if (nextSelectedKeys.length === 0 && currentList.length === 1 && shape !== '') {
                const sameNameMatch = materialCatalog.find((m) => m.name === currentList[0].name && (m.shape || '') === shape);
                if (sameNameMatch) {
                    nextSelectedKeys = [sameNameMatch.key];
                }
            }

            renderMaterialOptions(materialCatalog, nextSelectedKeys);
            shapeMismatchShown = nextSelectedKeys.length === 0;
            clearKeyBounds();
            applyShape();

            if (nextSelectedKeys.length > 0) {
                window.loadHistorycalPayloadFromFilters();
            } else {
                clearHistorycalResults(window.AdasiI18n.t('purchasing.js.history_no_material'));
                syncUrlFromForm();
            }
        });

        materialSelect.addEventListener('change', () => {
            shapeMismatchShown = false;
            clearKeyBounds();
            applyShape();
            if (hasSelectedMaterials()) {
                window.loadHistorycalPayloadFromFilters();
            } else {
                clearHistorycalResults(@json(__('purchasing.copy.comparison_select_material_from_supplier')));
                syncUrlFromForm();
            }
        });

        keyOption?.addEventListener('change', () => {
            if (keyOption.value === '__custom') return;
            const rawVal = String(keyOption.value || '').trim().replace(',', '.');
            if (rawVal !== '') {
                const parsed = Number(rawVal);
                if (!Number.isFinite(parsed) || parsed <= 0) {
                    if (keyMin) keyMin.value = '';
                    if (keyMax) keyMax.value = '';
                    syncKeyOption();
                    return;
                }
            }
            if (keyMin) keyMin.value = rawVal;
            if (keyMax) keyMax.value = rawVal;
            syncKeyOption();
            if (hasSelectedMaterials()) window.loadHistorycalPayloadFromFilters();
        });

        [keyMin, keyMax].forEach((input) => input?.addEventListener('input', syncKeyOption));

        rangeSelect.addEventListener('change', () => {
            if (hasSelectedMaterials()) {
                window.loadHistorycalPayloadFromFilters();
            } else {
                syncUrlFromForm();
            }
        });

        filterForm.addEventListener('submit', (e) => {
            e.preventDefault();
            if (hasSelectedMaterials()) {
                window.loadHistorycalPayloadFromFilters();
                return;
            }
            syncUrlFromForm();
            if (!materialSelect.disabled) {
                materialSelect.focus();
            } else {
                supplierSelect.focus();
            }
            announceHistoricalStatus(@json(__('purchasing.comparison_analytics.select_material')));
        });

        supportingModal?.addEventListener('adasi:modal-show', () => {
            committingSupportingModal = false;
            syncSupportingCurrencyLabel();
            writeSupportingFormValues(appliedSupportingFilters);
        });

        supportingModal?.addEventListener('adasi:modal-hide', (event) => {
            if (event.detail?.reason === 'escape' && supportingLib?.hasOpenInnerPopover(supportingModal)) {
                event.preventDefault();
                window.dispatchEvent(new CustomEvent('adasi:dismiss-overlays'));
            }
        });

        supportingModal?.addEventListener('adasi:modal-hidden', () => {
            if (!committingSupportingModal) {
                writeSupportingFormValues(appliedSupportingFilters);
            }
            committingSupportingModal = false;
        });

        supportingForm?.addEventListener('submit', (e) => {
            e.preventDefault();
            committingSupportingModal = true;
            const nextState = readSupportingFormValues();
            if (supportingModal && window.AdasiModal) {
                window.AdasiModal.getOrCreateInstance(supportingModal)?.hide('apply');
            } else if (supportingModal?.open) {
                supportingModal.close('apply');
            }
            applySupportingStateAndReload(nextState);
        });

        supportingResetBtn?.addEventListener('click', () => {
            writeSupportingFormValues(supportingLib ? supportingLib.emptyState() : {});
        });

        supportingChipsEl?.addEventListener('click', (event) => {
            const removeBtn = event.target.closest?.('[data-supporting-chip-remove]');
            if (removeBtn && supportingLib) {
                const key = removeBtn.getAttribute('data-supporting-chip-remove');
                applySupportingStateAndReload(supportingLib.clearKey(appliedSupportingFilters, key));
                return;
            }
            if (event.target.closest?.('[data-supporting-chips-clear]') && supportingLib) {
                applySupportingStateAndReload(supportingLib.emptyState());
            }
        });

        supportingNoDataResetBtn?.addEventListener('click', () => {
            if (supportingLib) {
                applySupportingStateAndReload(supportingLib.emptyState());
            }
        });

        if (supportingTable) {
            supportingTable.addEventListener('command', (event) => {
                if (event.command === '--sort-po-date') {
                    handlePoDateSortToggle(event.source);
                }
            });
            // Only without invoker commands: in supporting browsers a click also fires `command`.
            if (!('commandForElement' in HTMLButtonElement.prototype)) {
                supportingTable.addEventListener('click', (event) => {
                    const sortBtn = event.target.closest?.('button[command="--sort-po-date"]');
                    if (sortBtn) {
                        handlePoDateSortToggle(sortBtn);
                    }
                });
            }
        }

        syncSortDirState();
        renderSupportingFilterUI();

        materialCatalog = catalogFromDom();
        if (shapeFilter?.value) {
            renderMaterialOptions(materialCatalog, selectedMaterials().map((m) => m.key));
        }

        applyShape();

        const activeView = document.querySelector('input[name="period_view"]:checked')?.value || 'monthly';
        renderRangeOptions(activeView, rangeSelect.value);

        if (config.chartData && typeof Chart !== 'undefined') {
            latestHistoricalPayload = {
                chartData: config.chartData,
                summary: config.summary || {},
                periodView: config.periodView,
                materialName: config.selectedMaterialName,
                selectedMaterials: config.selectedMaterials || [],
                supplierName: config.supplierName || '',
                currency: historicalCurrency,
            };
            renderHistorycalChart(latestHistoricalPayload);
        }
    };

    // ─── 3. VS BEST PRICE LOGIC ───────────────────────────────────────────────
    window.initVsBestComparison = function() {
        const configEl = document.getElementById('vsBestConfig');
        if (!configEl) return;

        let config;
        try {
            config = JSON.parse(configEl.textContent);
        } catch (e) {
            window.AdasiDebug?.error('Invalid vsBestConfig JSON', e);
            return;
        }

        if (window._vsBestSearchTimer) {
            clearTimeout(window._vsBestSearchTimer);
            window._vsBestSearchTimer = null;
        }

        const summaryFallback = config.summaryFallback || {};
        const filterParams = Object.assign({}, config.filterParams || {});
        const tableDataUrl = config.tableDataUrl;

        const vsBestSearchInput = document.getElementById('vsBestTableSearch');
        const vsBestSearchClear = document.getElementById('vsBestTableSearchClear');

        const allowedPageLengths = [10, 25, 50, 100];
        const prefPageSize = Number(window.AdasiPreferences?.pageSize);
        const initialPageLength = allowedPageLengths.includes(prefPageSize) ? prefPageSize : 25;

        const initialSearch = String(vsBestSearchInput?.value ?? '').trim();
        const syncSearchClearVisibility = () => {
            if (vsBestSearchClear && vsBestSearchInput) {
                vsBestSearchClear.hidden = vsBestSearchInput.value.length === 0;
            }
        };
        syncSearchClearVisibility();

        let vsBestCurrency = config.currency || 'IDR';

        function formatRupiah(value) {
            return window.formatAnalyticMoney(value, vsBestCurrency, vsBestCurrency === 'IDR' ? 2 : 4);
        }

        function formatInteger(value) {
            const text = Number(value || 0).toLocaleString('id-ID');
            return window.AdasiPreferences?.displayNumber ? window.AdasiPreferences.displayNumber(text, 'indonesian') : text;
        }

        function updateSummary(summary) {
            const data = summary || summaryFallback;
            const totalRowsEl = document.getElementById('vsBestTotalRows');
            const competitiveEl = document.getElementById('vsBestCompetitiveCount');
            const aboveEl = document.getElementById('vsBestAboveCount');
            const potentialEl = document.getElementById('vsBestPotentialTotal');
            if (totalRowsEl) totalRowsEl.textContent = formatInteger(data.total_rows);
            if (competitiveEl) competitiveEl.textContent = formatInteger(data.competitive_count);
            if (aboveEl) aboveEl.textContent = formatInteger(data.above_count);
            if (potentialEl) potentialEl.textContent = formatRupiah(data.total_potential_difference_idr);
        }

        const vsBestTableEl = document.getElementById('vsBestTable');
        if (typeof window.DataTable === 'function' && vsBestTableEl) {
            if (window.DataTable.isDataTable(vsBestTableEl)) {
                new window.DataTable(vsBestTableEl, { retrieve: true }).destroy();
            }

            const dtOptions = {
                processing: true,
                serverSide: true,
                pageLength: initialPageLength,
                // PUR-04: only the table scrolls (sticky header and Material column); info and
                // paging stay outside the scroll region, while search/length live in the card header.
                layout: {
                    topStart: null,
                    topEnd: null,
                    bottomStart: 'info',
                    bottomEnd: 'paging',
                },
                initComplete: function() {
                    const container = this.api().table().container();
                    const region = container?.querySelector('.dt-layout-table > .dt-layout-full')
                        || this.api().table().node()?.parentElement;
                    region?.classList.add('ui-data-table__scroll', 'ui-table-sticky');
                    region?.setAttribute('role', 'region');
                    region?.setAttribute('tabindex', '0');
                    region?.setAttribute('aria-label', @js(__('purchasing.copy.price_benchmark_details')));
                },
                ajax: {
                    url: tableDataUrl,
                    data: function(d) {
                        Object.assign(d, filterParams);
                    },
                    dataSrc: function(json) {
                        vsBestCurrency = json.currency || vsBestCurrency;
                        updateSummary(json.summary);
                        return json.data || [];
                    },
                    error: function(xhr, error, thrown) {
                        if (error === 'abort' || xhr?.aborted || xhr?.statusText === 'abort') return;
                        window.AdasiDebug?.error('[vsBestTable] AJAX error:', error, thrown);
                        if (window.AdasiToast && typeof window.AdasiToast.error === 'function') {
                            window.AdasiToast.error('Gagal memuat data tabel perbandingan harga.');
                        }
                    }
                },
                columns: [
                    { data: 'material_display', name: 'current_pr_items.material_name', className: 'tw:text-start ui-sticky-col ui-col-data-name' },
                    { data: 'dimensions_display', name: 'dimensions_display', className: 'tw:text-start ui-tabular-nums tw:whitespace-nowrap' },
                    { data: 'current_price_display', name: 'current_price_idr', className: 'tw:text-end', searchable: false },
                    { data: 'best_price_display', name: 'best_price_idr', className: 'tw:text-end', searchable: false },
                    { data: 'diff_display', name: 'diff_idr_per_kg', className: 'tw:text-center', searchable: false },
                    { data: 'potential_difference_display', name: 'potential_difference_idr', className: 'tw:text-end', searchable: false },
                    { data: 'status_badge', name: 'diff_percent', className: 'tw:text-center', searchable: false },
                    { data: 'action', name: 'action', className: 'tw:text-center', orderable: false, searchable: false }
                ],

                order: [[5, 'desc']]
            };
            if (initialSearch !== '') {
                dtOptions.search = { search: initialSearch };
            }

            window._activeVsBestTable = new DataTable(vsBestTableEl, dtOptions);
        }

        let appliedSearch = initialSearch;
        const commitVsBestSearch = (rawValue) => {
            if (window._vsBestSearchTimer) {
                clearTimeout(window._vsBestSearchTimer);
                window._vsBestSearchTimer = null;
            }
            const nextQuery = String(rawValue ?? '').trim();
            if (nextQuery === appliedSearch) return;
            appliedSearch = nextQuery;
            const tableApi = window._activeVsBestTable;
            if (tableApi && typeof tableApi.search === 'function') {
                tableApi.search(nextQuery).draw();
            }
        };

        if (vsBestSearchInput) {
            vsBestSearchInput.addEventListener('input', () => {
                syncSearchClearVisibility();
                if (window._vsBestSearchTimer) {
                    clearTimeout(window._vsBestSearchTimer);
                }
                window._vsBestSearchTimer = setTimeout(() => {
                    window._vsBestSearchTimer = null;
                    commitVsBestSearch(vsBestSearchInput.value);
                }, 250);
            });

            vsBestSearchInput.addEventListener('keydown', (event) => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    if (event.isComposing || event.keyCode === 229) {
                        return;
                    }
                    commitVsBestSearch(vsBestSearchInput.value);
                    return;
                }
                if (event.key === 'Escape' && vsBestSearchInput.value !== '') {
                    event.preventDefault();
                    event.stopPropagation();
                    vsBestSearchInput.value = '';
                    syncSearchClearVisibility();
                    commitVsBestSearch('');
                }
            });
        }

        if (vsBestSearchClear && vsBestSearchInput) {
            vsBestSearchClear.addEventListener('click', () => {
                vsBestSearchInput.value = '';
                syncSearchClearVisibility();
                commitVsBestSearch('');
                vsBestSearchInput.focus();
            });
        }

        // DataTables discards responses older than its latest draw, so a slow earlier
        // request can never overwrite a newer filter.
        function vsBestUrl() {
            const form = document.getElementById('vsBestFilterForm');
            const url = new URL(form?.action || window.location.href, window.location.origin);
            ['date_from', 'date_to', 'currency', 'material_name'].forEach((key) => {
                if (filterParams[key]) url.searchParams.set(key, filterParams[key]);
            });
            // Gate 1 C6: Vs Best carries the active PR so Historical keeps it.
            const activePr = new URL(window.location.href).searchParams.get('pr_id');
            if (activePr) url.searchParams.set('pr_id', activePr);
            if (filterParams.material_name) url.searchParams.set('shape', filterParams.shape || '');
            return url.toString();
        }

        const dateRangeEl = document.getElementById('vsBestMonthRange');
        if (dateRangeEl) {
            dateRangeEl.addEventListener('adasi:date-range-commit', (event) => {
                filterParams.date_from = event.detail.start;
                filterParams.date_to = event.detail.end;
                window.syncComparisonState(vsBestUrl());
                if (window._activeVsBestTable) {
                    window._activeVsBestTable.ajax.reload();
                }
            });
        }

        // PUR-05: material filter without a page reload.
        const vsBestMaterial = document.getElementById('vsBestMaterialSelect');
        vsBestMaterial?.addEventListener('change', () => {
            const option = vsBestMaterial.selectedOptions[0];
            const rawValue = String(vsBestMaterial.value || '');
            const splitParts = rawValue.includes('|') ? rawValue.split('|') : [rawValue, ''];
            const resolvedName = option?.dataset?.materialName || splitParts[0] || null;
            const resolvedShape = option?.dataset?.shape ?? splitParts[1] ?? '';
            filterParams.material_name = rawValue ? resolvedName : null;
            filterParams.shape = rawValue ? resolvedShape : null;
            window.syncComparisonState(vsBestUrl());
            if (window._activeVsBestTable) {
                window._activeVsBestTable.ajax.reload();
            }
        });
    };

    // ─── 4. GLOBAL CONTAINER COORDINATION ────────────────────────────────────
    window.initCurrentComparisonTab = function() {
        const contentContainer = document.getElementById('purchasingComparisonContent');
        if (contentContainer) {
            if (window.AdasiCalendar && typeof window.AdasiCalendar.initialize === 'function') {
                window.AdasiCalendar.initialize(contentContainer);
            }
            if (window.AdasiAdvancedExport && typeof window.AdasiAdvancedExport.initialize === 'function') {
                window.AdasiAdvancedExport.initialize(contentContainer);
            }
        }

        // Gate 1 C6: tab links carry the context of the URL shown now (initial load and every swap).
        window.syncComparisonState(window.location.href);

        if (document.getElementById('interSupplierConfig')) {
            window.initInterSupplierComparison();
        } else if (document.getElementById('historicalPrConfig')) {
            window.initHistoricalPrComparison();
        } else if (document.getElementById('historicalConfig')) {
            window.initHistoricalComparison();
        } else if (document.getElementById('vsBestConfig')) {
            window.initVsBestComparison();
        }
    };

    const container = document.getElementById('purchasingComparisonContainer');
    if (container) {
        container.addEventListener('adasi:server-tabs:loading', () => {
            window.abortComparisonPendingRequests();
        });

        container.addEventListener('adasi:server-tabs:before-swap', () => {
            window.destroyComparisonActiveInstances();
        });

        container.addEventListener('adasi:server-tabs:updated', () => {
            window.initCurrentComparisonTab();
        });
    }

    // Historical pagination button delegation
    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-history-page]');
        if (!button || button.disabled) {
            return;
        }

        event.preventDefault();
        if (typeof window.loadHistorycalPayloadFromFilters === 'function') {
            const buttonId = button.id;
            const wasFocused = document.activeElement === button;
            Promise.resolve(window.loadHistorycalPayloadFromFilters(Number(button.dataset.historyPage || 1))).then(() => {
                const updatedButton = buttonId ? document.getElementById(buttonId) : null;
                if (!updatedButton?.disabled) return;
                if (!wasFocused && document.activeElement !== document.body) return;
                const fallbackId = buttonId === 'historicalNextPage'
                    ? 'historicalPreviousPage'
                    : (buttonId === 'historicalPreviousPage' ? 'historicalNextPage' : null);
                const fallback = fallbackId ? document.getElementById(fallbackId) : null;
                if (fallback && !fallback.disabled) {
                    fallback.focus({ preventScroll: true });
                }
            });
        }
    });

    function ensureComparisonServerTabs() {
        if (typeof AdasiServerTabs !== 'undefined' && typeof AdasiServerTabs.init === 'function') {
            AdasiServerTabs.init('#purchasingComparisonContainer', { prefetch: false });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            ensureComparisonServerTabs();
            window.initCurrentComparisonTab();
        });
    } else {
        ensureComparisonServerTabs();
        window.initCurrentComparisonTab();
    }
})();
</script>
@include('purchasing.comparison._historical_pr_scripts')
