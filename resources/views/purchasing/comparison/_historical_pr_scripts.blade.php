{{-- Gate 1 Historical comparison (PR mode): charts with one declared basis (C5, E1) and the read-only drawer (E3). --}}
<script>
(function() {
    // <historical-quotation-chart> Pure config builders (tests/js/historical-quotation-chart.test.mjs).
    const toNumber = (value) => (value === null || value === undefined || value === '' ? null : Number(value));

    // Shared series tokens (AdasiChart) when present; each supplier also gets its own point shape and dash.
    function palette(colors) {
        return Array.isArray(colors.series) && colors.series.length
            ? colors.series
            : [colors.primary, colors.warning, colors.secondary, colors.success, colors.info, colors.error, colors.onSurfaceVariant];
    }
    const POINT_STYLES = ['circle', 'rect', 'triangle', 'rectRot', 'crossRot', 'star'];
    const LINE_DASHES = [[], [6, 4], [2, 3], [10, 3, 2, 3], [4, 4], [1, 3]];
    const STROKE_ONLY_STYLES = ['cross', 'crossRot', 'star', 'line', 'dash'];

    function merge(base, extra) {
        const out = { ...base };
        Object.keys(extra || {}).forEach((key) => {
            const a = out[key];
            const b = extra[key];
            out[key] = a && b && typeof a === 'object' && typeof b === 'object' && !Array.isArray(a) && !Array.isArray(b) ? merge(a, b) : b;
        });
        return out;
    }

    const hasValue = (value) => value !== null && value !== undefined && (typeof value !== 'object' || (value.y !== null && value.y !== undefined));
    const axisTitleFont = { family: 'Inter', size: 11, weight: '600' };

    // A series with nothing plotted keeps no legend entry; the excluded list explains why.
    // Swatches are always filled in the series color (an Indicative first point must not make the
    // legend hollow); line charts keep each series' own point shape so legend and markers match.
    function legend(shape = 'rectRounded') {
        return {
            position: 'top',
            align: 'start',
            labels: {
                usePointStyle: true,
                ...(shape ? { pointStyle: shape } : {}),
                boxWidth: 10,
                boxHeight: 10,
                padding: 14,
                filter: (item, data) => (data.datasets[item.datasetIndex].data || []).some(hasValue),
                generateLabels: (chart) => {
                    const base = window.Chart ? window.Chart.defaults.plugins.legend.labels.generateLabels(chart) : [];
                    return base.map((item) => {
                        const dataset = chart.data.datasets[item.datasetIndex] || {};
                        const color = dataset.legendColor || dataset.borderColor || item.strokeStyle;
                        const style = shape || dataset.pointStyle || item.pointStyle;
                        // cross/star/line shapes are stroke-only: a zero line width would draw nothing.
                        return { ...item, fillStyle: color, strokeStyle: color, lineWidth: STROKE_ONLY_STYLES.includes(style) ? 2 : 1, lineDash: [], pointStyle: style };
                    });
                },
            },
        };
    }

    // Short axis title; the full basis sentence stays in the caption above the chart.
    const axisTitle = (currency) => (currency || 'IDR') + '/kg';

    // Shared ADASI scales/tooltip (AdasiChart) under the builder's own axis title and callbacks.
    function themed(options, theme, beginAtZero) {
        if (!theme || !theme.scales) return options;
        const base = theme.scales({ yBeginAtZero: beginAtZero });

        return {
            ...options,
            scales: { x: merge(base.x, options.scales.x), y: merge(base.y, options.scales.y) },
            plugins: { ...options.plugins, tooltip: theme.tooltip ? theme.tooltip(options.plugins.tooltip || {}) : options.plugins.tooltip },
        };
    }

    function tooltipLines(meta, valueText, i18n) {
        if (!meta) return [valueText];
        return [
            valueText,
            meta.original + (meta.rate ? ' · ' + meta.rate : ''),
            [meta.pr_number, meta.date, meta.status].filter(Boolean).join(' · '),
            meta.indicative ? i18n.indicative : null,
        ].filter(Boolean);
    }

    function currentPrevious(chart, i18n, colors, format, theme) {
        const previous = chart.series.previous.map(toNumber);
        const current = chart.series.current.map(toNumber);
        const indicative = (chart.tooltips.previous || []).map((meta) => Boolean(meta && meta.indicative));
        const plotted = [...previous, ...current].filter((value) => value !== null).length;

        return {
            empty: plotted === 0,
            plotted,
            config: {
                type: 'bar',
                data: {
                    // Indicative is spelled out under the supplier name, never shown as a hollow bar.
                    labels: chart.labels.map((label, index) => (indicative[index] && previous[index] !== null ? [label, '(' + i18n.indicative + ')'] : label)),
                    datasets: [
                        {
                            label: i18n.previous,
                            data: previous,
                            backgroundColor: colors.secondary,
                            borderColor: colors.secondary,
                            borderWidth: 0,
                            borderRadius: 3,
                            maxBarThickness: 56,
                            tooltipMeta: chart.tooltips.previous,
                        },
                        {
                            label: i18n.current,
                            data: current,
                            backgroundColor: colors.primary,
                            borderColor: colors.primary,
                            borderWidth: 0,
                            borderRadius: 3,
                            maxBarThickness: 56,
                            tooltipMeta: chart.tooltips.current,
                        },
                    ],
                },
                options: themed({
                    responsive: true,
                    maintainAspectRatio: false,
                    skipNull: true,
                    animation: false,
                    scales: {
                        x: { grid: { display: false } },
                        y: { beginAtZero: true, title: { display: true, text: axisTitle(chart.basis), font: axisTitleFont }, ticks: { callback: (value) => format(value, chart.basis) } },
                    },
                    plugins: {
                        legend: legend(),
                        tooltip: {
                            callbacks: {
                                label: (context) => tooltipLines(
                                    context.dataset.tooltipMeta[context.dataIndex],
                                    context.dataset.label + ': ' + format(context.parsed.y, chart.basis) + '/kg',
                                    i18n,
                                ),
                            },
                        },
                    },
                }, theme, true),
            },
        };
    }

    function trend(chart, i18n, colors, format, formatDate, theme) {
        const colorsList = palette(colors);
        const datasets = chart.series.map((series, index) => {
            const color = colorsList[index % colorsList.length];
            const cycle = Math.floor(index / colorsList.length);
            return {
                label: series.supplier,
                data: series.points.map((point) => ({ x: Number(point.x), y: toNumber(point.y) })),
                borderColor: color,
                backgroundColor: color,
                pointStyle: POINT_STYLES[index % POINT_STYLES.length],
                borderDash: LINE_DASHES[(index + cycle) % LINE_DASHES.length],
                borderWidth: 2,
                pointBackgroundColor: series.points.map((point) => (point.tooltip && point.tooltip.indicative ? 'transparent' : color)),
                pointBorderColor: color,
                pointRadius: 4,
                pointHoverRadius: 6,
                fill: false,
                spanGaps: false,
                tension: 0,
                tooltipMeta: series.points.map((point) => point.tooltip),
            };
        });
        const plotted = datasets.reduce((total, dataset) => total + dataset.data.length, 0);

        return {
            empty: plotted === 0,
            plotted,
            config: {
                type: 'line',
                data: { datasets },
                options: themed({
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: false,
                    parsing: false,
                    scales: {
                        x: { type: 'linear', ticks: { callback: (value) => formatDate(value), maxTicksLimit: 6 } },
                        y: { title: { display: true, text: axisTitle(chart.basis), font: axisTitleFont }, ticks: { callback: (value) => format(value, chart.basis) } },
                    },
                    plugins: {
                        legend: legend(null),
                        tooltip: {
                            callbacks: {
                                title: (items) => (items.length ? formatDate(items[0].parsed.x) : ''),
                                label: (context) => tooltipLines(
                                    context.dataset.tooltipMeta[context.dataIndex],
                                    context.dataset.label + ': ' + format(context.parsed.y, chart.basis) + '/kg',
                                    i18n,
                                ),
                            },
                        },
                    },
                }, theme, false),
            },
        };
    }

    function purchaseHistory(po, i18n, colors, format, theme) {
        const chartData = po && po.chartData;
        const values = chartData ? chartData.pricesIdr.map(toNumber) : [];
        const plotted = values.filter((value) => value !== null).length;

        return {
            empty: plotted === 0,
            plotted,
            config: {
                type: 'line',
                data: {
                    labels: chartData ? chartData.labels : [],
                    datasets: [{
                        label: i18n.weightedAverage,
                        data: values,
                        borderColor: colors.primary,
                        backgroundColor: colors.primary,
                        pointRadius: 4,
                        fill: false,
                        spanGaps: false,
                        tension: 0,
                    }],
                },
                options: themed({
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: false,
                    scales: {
                        x: { grid: { display: false } },
                        y: { title: { display: true, text: axisTitle(po ? po.currency : null), font: axisTitleFont }, ticks: { callback: (value) => format(value, po ? po.currency : null) } },
                    },
                    plugins: { legend: legend() },
                }, theme, false),
            },
        };
    }

    window.AdasiHistoricalQuotationChart = { toNumber, currentPrevious, trend, purchaseHistory };
    // </historical-quotation-chart>

    function fallbackColors() {
        const css = getComputedStyle(document.documentElement);
        const token = (name) => css.getPropertyValue(name).trim();
        return {
            primary: token('--md-chart-primary'),
            warning: token('--md-chart-warning'),
            secondary: token('--md-secondary'),
            success: token('--md-success'),
            info: token('--md-info'),
            error: token('--md-error'),
            onSurfaceVariant: token('--md-on-surface-variant'),
            series: [1, 2, 3, 4, 5, 6].map((slot) => token('--md-chart-series-' + slot)),
        };
    }

    window.initHistoricalPrComparison = function() {
        const configEl = document.getElementById('historicalPrConfig');
        if (!configEl) return;
        let config;
        try {
            config = JSON.parse(configEl.textContent);
        } catch (error) {
            return;
        }

        const lib = window.AdasiHistoricalQuotationChart;
        const i18n = config.i18n;
        const canvas = document.getElementById('historicalPrChart');
        const basisEl = document.getElementById('historicalPrChartBasis');
        const emptyEl = document.getElementById('historicalPrChartEmpty');
        const excludedEl = document.getElementById('historicalPrChartExcluded');
        const poSummaryEl = document.getElementById('historicalPrPoSummary');
        const itemSelect = document.querySelector('[data-historical-chart-item]');
        const guard = window.createComparisonRequestGuard();
        const state = { mode: config.chart, item: config.chartItem, label: config.chartItemLabel, charts: config.charts, po: config.poHistory };
        const format = (value, currency) => (value === null || value === undefined ? '-' : window.formatAnalyticMoney(value, currency || 'IDR', 4));
        const dateFormatter = new Intl.DateTimeFormat(config.locale || document.documentElement.lang || 'en', { day: '2-digit', month: 'short', year: 'numeric', timeZone: config.timezone });
        const formatDate = (value) => dateFormatter.format(new Date(Number(value)));

        function setList(element, lines) {
            if (!element) return;
            element.replaceChildren();
            if (!lines.length) return;
            const title = document.createElement('p');
            title.className = 'tw:m-0 tw:font-semibold';
            title.textContent = i18n.notPlotted;
            const list = document.createElement('ul');
            list.className = 'tw:m-0 tw:mt-1 tw:grid tw:gap-0.5 tw:ps-4';
            lines.forEach((line) => {
                const item = document.createElement('li');
                item.textContent = line;
                list.append(item);
            });
            element.append(title, list);
        }

        function render() {
            window.destroyComparisonActiveInstances();
            document.querySelectorAll('[data-historical-chart-mode]').forEach((button) => {
                button.setAttribute('aria-pressed', button.dataset.historicalChartMode === state.mode ? 'true' : 'false');
            });
            if (!state.charts) return;

            const colors = window.AdasiChart ? window.AdasiChart.getColors() : fallbackColors();
            const theme = window.AdasiChart ? { scales: window.AdasiChart.getScales, tooltip: window.AdasiChart.getTooltip } : null;
            let built;
            let excluded = [];
            let basis = '';
            if (state.mode === 'quote_trend') {
                built = lib.trend(state.charts.quote_trend, i18n, colors, format, formatDate, theme);
                basis = state.charts.quote_trend.axis_label + (state.charts.quote_trend.series.some((series) => series.points.some((point) => point.tooltip && point.tooltip.indicative)) ? ' · ' + i18n.indicativeNote : '');
                excluded = state.charts.quote_trend.excluded.map((entry) => entry.supplier + ': ' + entry.reason_label);
            } else if (state.mode === 'po_history') {
                built = lib.purchaseHistory(state.po, i18n, colors, format, theme);
                basis = i18n.poBasis;
            } else {
                built = lib.currentPrevious(state.charts.current_previous, i18n, colors, format, theme);
                basis = state.charts.current_previous.axis_label;
                excluded = state.charts.current_previous.excluded.map((entry) => entry.supplier + (entry.series === 'previous' ? ' (' + i18n.previous + ')' : '') + ': ' + entry.reason_label);
            }

            if (basisEl) basisEl.textContent = basis;
            setList(excludedEl, excluded);
            if (poSummaryEl) {
                const summary = state.po && state.po.summary;
                const hasSummary = Boolean(summary && summary.weighted_average !== null && summary.weighted_average !== undefined);
                // With no PO history the centered empty state already says so; no second message below.
                poSummaryEl.hidden = state.mode !== 'po_history' || built.empty || !hasSummary;
                poSummaryEl.textContent = hasSummary
                    ? i18n.weightedAverage + ': ' + format(summary.weighted_average, state.po.currency) + ' · ' + i18n.historicalBest + ': ' + format(summary.best, state.po.currency)
                    : '';
            }
            if (emptyEl) {
                emptyEl.hidden = !built.empty;
                const emptyTitle = emptyEl.querySelector('h3');
                if (emptyTitle) emptyTitle.textContent = state.mode === 'po_history' ? i18n.poEmpty : i18n.chartEmpty;
            }
            // The empty state is an overlay inside the plot box; the keyboard hint only applies to a drawn chart.
            const caption = canvas ? canvas.closest('figure')?.querySelector('figcaption') : null;
            if (caption) caption.hidden = built.empty;
            if (canvas) {
                canvas.hidden = built.empty;
                canvas.setAttribute('aria-label', i18n.chartSummary.replace(':material', state.label || '').replace(':count', String(built.plotted)).replace(':basis', basis));
            }
            if (!built.empty && canvas && typeof Chart !== 'undefined') {
                // Keyboard readout in the declared basis; quotation points are read by their real date.
                const basisCurrency = state.mode === 'po_history'
                    ? (state.po && state.po.currency)
                    : (state.mode === 'quote_trend' ? state.charts.quote_trend.basis : state.charts.current_previous.basis);
                built.config.options.plugins.adasiA11y = {
                    format: (value) => format(value, basisCurrency) + '/kg',
                    ...(state.mode === 'quote_trend' ? { formatLabel: (x) => formatDate(x) } : {}),
                };
                window._activeComparisonChart = new Chart(canvas, built.config);
            }
        }

        function syncUrl() {
            const url = new URL(window.location.href);
            url.searchParams.set('chart', state.mode);
            if (state.item) url.searchParams.set('pr_item', state.item);
            window.syncComparisonState(url.toString());
        }

        document.querySelectorAll('[data-historical-chart-mode]').forEach((button) => {
            button.addEventListener('click', () => {
                state.mode = button.dataset.historicalChartMode;
                render();
                syncUrl();
            });
        });

        itemSelect?.addEventListener('change', async () => {
            const request = guard.start();
            const url = new URL(config.dataUrl, window.location.origin);
            url.search = new URL(window.location.href).search;
            url.searchParams.set('view', 'json');
            url.searchParams.set('pr_item', itemSelect.value);
            url.searchParams.set('chart', state.mode);
            try {
                const response = await fetch(url.toString(), { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, signal: request.signal });
                if (!response.ok) throw new Error(String(response.status));
                const payload = await response.json();
                if (!guard.isCurrent(request.id)) return;
                state.item = itemSelect.value;
                state.label = payload.quotationHistory.chart_item ? payload.quotationHistory.chart_item.label : '';
                state.charts = payload.quotationHistory.charts;
                state.po = payload.poHistory;
                render();
                syncUrl();
            } catch (error) {
                if (error.name === 'AbortError' || !guard.isCurrent(request.id)) return;
                setList(excludedEl, [i18n.loadError]);
            }
        });

        const drawerBody = document.getElementById('historicalDrawerBody');
        const drawerSubtitle = document.getElementById('historicalDrawerSubtitle');
        const drawerGuard = window.createComparisonRequestGuard();

        function drawerMessage(text, retry) {
            drawerBody.replaceChildren();
            const message = document.createElement('p');
            message.className = 'tw:m-0 tw:text-ui-sm tw:text-on-surface-variant';
            message.textContent = text;
            drawerBody.append(message);
            if (retry) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'ui-data-action ui-focus-ring tw:mt-2';
                button.textContent = i18n.retry;
                button.addEventListener('click', retry);
                drawerBody.append(button);
            }
        }

        // Price history: one compact row per earlier quotation; every value is set with textContent.
        const drawerText = i18n.drawer;
        const drawerClasses = (document.getElementById('historicalDrawerClasses') || { dataset: {} }).dataset;
        let drawerTab = 'same';

        function node(tag, className, text) {
            const element = document.createElement(tag);
            if (className) element.className = className;
            if (text !== undefined && text !== null) element.textContent = text;
            return element;
        }

        function drawerDiffCell(record) {
            const cell = node('td', 'tw:px-2 tw:py-2 tw:text-end tw:align-top');
            if (record.diff_display !== null) {
                const value = Number(record.diff_pct);
                const tone = value > 0 ? drawerClasses.up : (value < 0 ? drawerClasses.down : '');
                cell.append(node('div', 'ui-tabular-nums tw:font-semibold ' + (tone || ''), record.diff_display));
                cell.append(node('div', 'tw:text-ui-xs tw:text-on-surface-variant', value > 0 ? drawerText.higher : (value < 0 ? drawerText.lower : drawerText.unchanged)));
                if (record.diff_converted) cell.append(node('div', 'tw:text-ui-xs tw:text-on-surface-variant', drawerText.inclFx));
            } else if (record.basis_note) {
                cell.append(node('div', 'tw:text-ui-xs tw:text-on-surface-variant', drawerText.otherTerms.replace(':terms', record.basis_note)));
            } else {
                cell.append(node('span', 'tw:text-on-surface-variant', '-'));
                cell.setAttribute('aria-label', drawerText.noDiff);
            }
            return cell;
        }

        function drawerTable(records, other) {
            if (!records.length) {
                return node('p', 'tw:m-0 tw:py-3 tw:text-ui-sm tw:text-on-surface-variant', other ? drawerText.noneOther : drawerText.noneSame);
            }
            // No horizontal scroll wrapper on desktop, so the header can stick to the dialog's scroll area.
            const wrap = node('div', 'tw:max-sm:overflow-x-auto');
            const table = node('table', 'tw:w-full tw:border-collapse tw:text-ui-sm');
            table.append(node('caption', 'visually-hidden', drawerText.caption));
            const head = node('thead', 'tw:sticky tw:-top-5 tw:z-10 tw:bg-surface');
            const headRow = node('tr', 'tw:border-b tw:border-outline-variant tw:text-ui-xs tw:text-on-surface-variant');
            const columns = other
                ? [[drawerText.colDate, false], [drawerText.colPo, false], [drawerText.colSize, false], [drawerText.colPrice, true]]
                : [[drawerText.colDate, false], [drawerText.colPo, false], [drawerText.colPrice, true], [drawerText.colDiff, true]];
            columns.forEach(([label, numeric]) => {
                const th = node('th', 'tw:px-2 tw:py-1.5 tw:font-semibold ' + (numeric ? 'tw:text-end' : 'tw:text-start'), label);
                th.scope = 'col';
                headRow.append(th);
            });
            head.append(headRow);
            table.append(head);
            const body = node('tbody', null);
            records.forEach((record) => {
                const row = node('tr', 'tw:border-b tw:border-outline-variant ' + (record.selected ? (drawerClasses.rowSelected || '') : ''));
                row.append(node('td', 'tw:px-2 tw:py-2 tw:align-top tw:whitespace-nowrap', record.date));
                const prCell = node('td', 'tw:px-2 tw:py-2 tw:align-top');
                const link = node('a', 'ui-focus-ring ui-tabular-nums tw:font-semibold tw:text-primary', record.po_number);
                link.href = record.detail_url;
                link.setAttribute('data-return-url', '');
                prCell.append(link, node('div', 'ui-tabular-nums tw:text-ui-xs tw:text-on-surface-variant', drawerText.fromPr.replace(':pr', record.pr_number)));
                if (record.selected) prCell.append(node('div', 'tw:text-ui-xs tw:font-semibold tw:text-on-surface', i18n.drawerSelected));
                row.append(prCell);
                if (other) row.append(node('td', 'tw:px-2 tw:py-2 tw:align-top tw:whitespace-nowrap', record.key || '-'));
                const priceCell = node('td', 'ui-tabular-nums tw:px-2 tw:py-2 tw:text-end tw:align-top tw:font-semibold', record.price);
                if (record.original) priceCell.append(node('div', 'tw:text-ui-xs tw:font-normal tw:text-on-surface-variant', record.original));
                if (record.rate) priceCell.append(node('div', 'tw:text-ui-xs tw:font-normal tw:text-on-surface-variant', record.rate));
                row.append(priceCell);
                if (!other) row.append(drawerDiffCell(record));
                body.append(row);
            });
            table.append(body);
            wrap.append(table);
            if (other) wrap.append(node('p', 'tw:m-0 tw:mt-2 tw:text-ui-xs tw:text-on-surface-variant', drawerText.otherNote));
            return wrap;
        }

        function renderDrawer(payload) {
            drawerBody.replaceChildren();
            if (!payload.records.length) {
                drawerMessage(i18n.drawerEmpty);
                return;
            }
            const same = payload.records.filter((record) => record.spec_equal);
            const other = payload.records.filter((record) => !record.spec_equal);
            if (!same.length) drawerTab = 'other';

            const summary = node('div', 'tw:mb-3 tw:grid tw:gap-0.5');
            if (payload.current) {
                summary.append(node('div', 'ui-tabular-nums tw:text-ui-sm tw:font-semibold tw:text-on-surface', drawerText.current.replace(':price', payload.current.price).replace(':date', payload.current.date)));
                summary.append(node('div', 'tw:text-ui-xs tw:text-on-surface-variant', [payload.current.original, payload.current.rate].filter(Boolean).join(' · ')));
            }
            if (payload.summary) {
                summary.append(node('div', 'ui-tabular-nums tw:text-ui-xs tw:text-on-surface-variant', drawerText.range
                    .replace(':lowest_date', payload.summary.lowest_date).replace(':lowest', payload.summary.lowest)
                    .replace(':highest_date', payload.summary.highest_date).replace(':highest', payload.summary.highest)
                    .replace(':count', String(payload.summary.count))));
            }

            const tabs = node('div', 'tw:mb-2 tw:flex tw:flex-wrap tw:gap-1.5');
            tabs.setAttribute('role', 'group');
            tabs.setAttribute('aria-label', drawerText.tabsLabel);
            const note = node('p', 'tw:m-0 tw:mb-1 tw:text-ui-xs tw:text-on-surface-variant');
            const content = node('div', null);
            const buttons = {};
            const show = (tab) => {
                drawerTab = tab;
                Object.entries(buttons).forEach(([key, button]) => button.setAttribute('aria-pressed', String(key === tab)));
                const records = tab === 'same' ? same : other;
                const total = tab === 'same' ? payload.comparable_total : payload.other_total;
                const pinned = tab === 'same' && payload.pinned;
                const shown = records.length - (pinned ? 1 : 0);
                note.textContent = records.length && shown < total
                    ? (pinned ? i18n.drawerShowingPinned : i18n.drawerShowing).replace(':shown', String(shown)).replace(':total', String(total))
                    : '';
                content.replaceChildren(drawerTable(records, tab === 'other'));
            };
            [
                ['same', drawerText.tabSame.replace(':key', payload.current && payload.current.key ? payload.current.key : '').replace(':count', String(payload.comparable_total))],
                ['other', drawerText.tabOther.replace(':count', String(payload.other_total))],
            ].forEach(([key, label]) => {
                const button = node('button', drawerClasses.toggle || '', label);
                button.type = 'button';
                button.addEventListener('click', () => show(key));
                buttons[key] = button;
                tabs.append(button);
            });

            drawerBody.append(summary, tabs, note, content);
            show(drawerTab);
        }

        async function loadDrawer(button) {
            if (!drawerBody) return;
            const request = drawerGuard.start();
            drawerSubtitle.textContent = button.dataset.title || '';
            drawerBody.setAttribute('aria-busy', 'true');
            drawerMessage(i18n.drawerLoading);
            const url = new URL(config.dataUrl, window.location.origin);
            url.searchParams.set('pr_id', config.prId);
            url.searchParams.set('pr_item', button.dataset.prItem);
            url.searchParams.set('supplier_id', button.dataset.supplier);
            url.searchParams.set('view', 'json');
            url.searchParams.set('history', '1');
            try {
                const response = await fetch(url.toString(), { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, signal: request.signal });
                if (!response.ok) throw new Error(String(response.status));
                const payload = await response.json();
                if (!drawerGuard.isCurrent(request.id)) return;
                drawerTab = 'same';
                renderDrawer(payload);
            } catch (error) {
                if (error.name === 'AbortError' || !drawerGuard.isCurrent(request.id)) return;
                drawerMessage(i18n.drawerError, () => loadDrawer(button));
            } finally {
                if (drawerGuard.isCurrent(request.id)) drawerBody.setAttribute('aria-busy', 'false');
            }
        }

        document.querySelectorAll('[data-historical-drawer]').forEach((button) => {
            button.addEventListener('click', () => {
                window.dispatchEvent(new CustomEvent('open-ui-dialog', { detail: 'historical-quotation-dialog' }));
                loadDrawer(button);
            });
        });

        render();
    };
})();
</script>
