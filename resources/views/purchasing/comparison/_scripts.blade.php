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
        chart: @json($comparisonIcon('chart-no-axes-combined', 'md')),
        chartEmpty: @json($comparisonIcon('chart-no-axes-combined', 'lg', 'tw:size-8')),
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
                return {
                    label: dataset.label,
                    data: relative ? percents[index] : prices[index],
                    prices: prices[index],
                    percents: percents[index],
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
            });

            const scales = theme && theme.scales
                ? theme.scales({ yMaxTicks: 5, yBeginAtZero: true, yFormat })
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
                                position: 'top',
                                align: 'start',
                                labels: {
                                    usePointStyle: true,
                                    pointStyle: 'rectRounded',
                                    boxWidth: 10,
                                    boxHeight: 10,
                                    padding: 14,
                                    font: { family: 'Inter', size: 12, weight: '500' },
                                    color: colors.onSurfaceVariant,
                                },
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
        document.querySelectorAll('#purchasingComparisonContainer nav [data-server-tab][data-tab-name]').forEach((tab) => {
            const href = new URL(tab.getAttribute('href'), window.location.origin);
            comparisonSharedKeys.forEach((key) => {
                const value = target.searchParams.get(key);
                value === null || value === '' ? href.searchParams.delete(key) : href.searchParams.set(key, value);
            });
            comparisonLocalKeys.forEach((key) => href.searchParams.delete(key));
            const prId = target.searchParams.get('pr_id');
            if (prId) {
                href.searchParams.set('pr_id', prId);
            } else if (tab.dataset?.tabName !== 'inter-supplier') {
                href.searchParams.delete('pr_id');
            }
            tab.setAttribute('href', href.pathname + href.search);
        });
        // Gate 1 C6 (N7): detail links return to the state the user is looking at now.
        document.querySelectorAll('#purchasingComparisonContainer [data-return-url]').forEach((link) => {
            const href = new URL(link.getAttribute('href'), window.location.origin);
            href.searchParams.set('return_url', target.pathname + target.search);
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
    window.createComparisonRequestGuard = function() {
        let controller = null;
        let sequence = 0;
        return {
            start() {
                controller?.abort();
                controller = new AbortController();
                return { signal: controller.signal, id: ++sequence };
            },
            isCurrent(id) {
                return id === sequence;
            },
        };
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

    window.destroyComparisonActiveInstances = function() {
        if (window._activeComparisonChart) {
            try {
                window._activeComparisonChart.destroy();
            } catch (e) {
                console.warn('Error destroying chart:', e);
            }
            window._activeComparisonChart = null;
        }

        if (window._activeVsBestTable) {
            try {
                if (typeof $ !== 'undefined' && $.fn.DataTable && $.fn.DataTable.isDataTable('#vsBestTable')) {
                    $('#vsBestTable').DataTable().destroy();
                }
            } catch (e) {
                console.warn('Error destroying DataTable:', e);
            }
            window._activeVsBestTable = null;
        }

        if (window.AdasiCalendar && typeof window.AdasiCalendar.closeActive === 'function') {
            try {
                window.AdasiCalendar.closeActive();
            } catch (e) {
                console.warn('Error closing active calendar:', e);
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
            console.error('Invalid interSupplierConfig JSON', e);
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
            if (comparisonPrSuggestions) comparisonPrSuggestions.classList.add('d-none');
        };

        const toggleComparisonClear = () => {
            if (comparisonPrClear) {
                comparisonPrClear.classList.toggle('d-none', comparisonPrSearch.value.trim() === '');
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

            comparisonPrSuggestions.innerHTML = '';

            if (matches.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'list-group-item small text-muted';
                empty.textContent = @json(__('purchasing.copy.no_matching_pr_was_found'));
                comparisonPrSuggestions.appendChild(empty);
                comparisonPrSuggestions.classList.remove('d-none');
                return;
            }

            matches.forEach((option) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'list-group-item list-group-item-action py-2';

                const title = document.createElement('div');
                title.className = 'fw-semibold small';
                title.textContent = option.prNumber;

                const meta = document.createElement('div');
                meta.className = 'text-muted';
                meta.style.fontSize = '.75rem';
                meta.textContent = `${option.period} - ${option.quotationCountLabel}`;

                const preview = document.createElement('div');
                preview.className = 'text-secondary mt-1';
                preview.style.fontSize = '.7rem';
                preview.innerHTML = `<span class="me-1">${comparisonIcons.package}</span>${option.previewMaterials || ''}`;

                button.appendChild(title);
                button.appendChild(meta);
                button.appendChild(preview);
                button.addEventListener('mousedown', (event) => {
                    event.preventDefault();
                    selectComparisonPr(option);
                });

                comparisonPrSuggestions.appendChild(button);
            });

            comparisonPrSuggestions.classList.remove('d-none');
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
                    theme: window.AdasiChart ? { scales: window.AdasiChart.getScales, tooltip: window.AdasiChart.getTooltip, hatch: window.AdasiChart.hatch } : null,
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
                window._activeComparisonChart = new Chart(chartCanvas, built.config);
                chartCanvas.setAttribute('aria-label', [chartI18n.title, chartLib.summary(data, chartI18n, formatPrice)].filter(Boolean).join('. '));
                scaleButtons.forEach((button) => {
                    button.setAttribute('aria-pressed', button.dataset.comparisonChartScale === chartState.mode ? 'true' : 'false');
                });
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
                        row.classList.toggle('d-none', materialId !== '' && row.dataset.materialId !== materialId);
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
                        materialFilter.dispatchEvent(new Event('change'));
                    }
                }
            }
        }

        // Award previews & PO grouping
        const updateAwardPreviews = () => {
            const radios = document.querySelectorAll('.award-radio:checked');
            const previewContainer = document.getElementById('awardPreviewItems');
            const groupContainer = document.getElementById('supplierGroupPreview');

            if (!previewContainer || !groupContainer) return;

            const textElement = (tag, className, text) => {
                const element = document.createElement(tag);
                element.className = className;
                element.textContent = text;
                return element;
            };

            if (radios.length === 0) {
                previewContainer.replaceChildren(textElement('span', 'tw:text-on-surface-variant', @json(__('purchasing.copy.no_items_selected_yet_select_supplier_offers_above'))));
                groupContainer.replaceChildren(textElement('span', 'tw:text-on-surface-variant', @json(__('purchasing.copy.1_po_will_be_created_per_selected_supplier_group_upon_confirmation'))));
                return;
            }

            const itemsList = textElement('ul', 'tw:list-disc tw:ps-4 tw:space-y-1', '');
            const supplierMap = new Map();

            radios.forEach(radio => {
                const itemName = radio.dataset.prItemName;
                const supplierName = radio.dataset.supplierName;
                const supplierId = radio.dataset.supplierId;
                const price = radio.dataset.priceDisplay;

                const item = document.createElement('li');
                item.append(
                    textElement('strong', '', itemName),
                    document.createTextNode(' → '),
                    textElement('span', 'text-primary fw-semibold', supplierName),
                    document.createTextNode(` (${price})`),
                );
                itemsList.append(item);

                if (!supplierMap.has(supplierId)) {
                    supplierMap.set(supplierId, { name: supplierName, items: [] });
                }
                supplierMap.get(supplierId).items.push(itemName);
            });
            previewContainer.replaceChildren(itemsList);

            const groups = textElement('div', 'tw:space-y-2', '');
            let poCount = 0;
            for (const supplier of supplierMap.values()) {
                poCount++;
                const group = textElement('div', 'tw:p-2 tw:rounded-sm tw:bg-surface tw:border tw:border-outline-variant', '');
                group.append(
                    textElement('div', 'fw-bold tw:text-ui-xs text-primary', `PO #${poCount} • ${supplier.name}`),
                    textElement('div', 'tw:text-on-surface-variant tw:text-ui-xs', `${window.AdasiI18n.choice('purchasing.js.item_count', supplier.items.length, { count: supplier.items.length })}: ${supplier.items.join(', ')}`),
                );
                groups.append(group);
            }
            groupContainer.replaceChildren(groups);

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
                warningEl.classList.toggle('d-none', !isEarlier);
            }
        };

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
            console.error('Invalid historicalConfig JSON', e);
            return;
        }

        const filterForm = document.getElementById('historicalFilterForm');
        const supplierSelect = document.getElementById('historicalSupplierSelect');
        const materialSelect = document.getElementById('historicalMaterialSelect');
        const rangeSelect = document.getElementById('historicalRangeSelect');
        const resultsContainer = document.getElementById('historicalResults');
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
        }

        // The Shape filter only narrows this list and is never submitted (it has no name): the
        // analysed shape always comes from the chosen material option, via #historicalShapeInput.
        const shapeFilter = document.getElementById('historicalShapeFilter');
        let materialCatalog = [];
        // True while the Shape filter is parked on a mismatch and the "no data" placeholder is showing
        // instead of the locked material's real results.
        let shapeMismatchShown = false;

        function catalogFromDom() {
            return Array.from(materialSelect.options).filter((option) => option.value).map((option) => {
                let keyOptions = [];
                try { keyOptions = JSON.parse(option.dataset.keyOptions || '[]'); } catch (e) { keyOptions = []; }
                return { name: option.value, shape: option.dataset.shape || '', key_options: keyOptions, label: option.textContent.trim() };
            });
        }

        function renderMaterialOptions(materials, selected = null) {
            materialCatalog = materials;
            const shape = shapeFilter ? shapeFilter.value : '';
            const visible = shape ? materials.filter((material) => (material.shape || '') === shape) : materials;

            // Mission 07: the supplier is optional (decision 15). PUR-07: one option per (material, shape).
            const placeholder = visible.length > 0
                ? window.AdasiI18n.t('purchasing.js.history_select_material')
                : window.AdasiI18n.t('purchasing.js.history_no_material');

            materialSelect.innerHTML = [
                `<option value="">${escapeOptionText(placeholder)}</option>`,
                ...visible.map((material) => {
                    const name = material.name || '';
                    const materialShape = material.shape || '';
                    const isSelected = selected && name === selected.name && materialShape === (selected.shape || '') ? ' selected' : '';
                    const options = escapeOptionText(JSON.stringify(material.key_options || []));
                    return `<option value="${escapeOptionText(name)}" data-shape="${escapeOptionText(materialShape)}" data-key-options="${options}"${isSelected}>${escapeOptionText(material.label || name)}</option>`;
                }),
            ].join('');
            materialSelect.disabled = visible.length === 0;
        }

        // PUR-07 (§3-G): shape-driven key dimension with recorded values written into key_min/key_max.
        const shapeInput = document.getElementById('historicalShapeInput');
        const keyDimension = document.getElementById('historicalKeyDimension');
        const keyDimensionLabel = document.getElementById('historicalKeyDimensionLabel');
        const keyOption = document.getElementById('historicalKeyOption');
        const keyMin = document.getElementById('historicalKeyMin');
        const keyMax = document.getElementById('historicalKeyMax');
        const keyLabels = keyDimension ? { Flat: keyDimension.dataset.labelFlat, Round: keyDimension.dataset.labelRound, Hollow: keyDimension.dataset.labelHollow } : {};

        function selectedMaterial() {
            const option = materialSelect.selectedOptions[0];
            if (!option || !option.value) return null;
            let keyOptions = [];
            try { keyOptions = JSON.parse(option.dataset.keyOptions || '[]'); } catch (e) { keyOptions = []; }
            return { name: option.value, shape: option.dataset.shape || '', keyOptions };
        }

        function displayKey(value) {
            const text = String(Number(value));
            return window.AdasiPreferences?.displayNumber ? window.AdasiPreferences.displayNumber(text, 'decimal') : text;
        }

        function syncKeyOption() {
            if (!keyOption || !keyMin || !keyMax) return;
            const material = selectedMaterial();
            // While parked on a Shape filter mismatch (locked material, no record under that shape), there
            // is nothing recorded for the shape the panel is now describing: treat it as having no options.
            const options = (material && !shapeMismatchShown) ? (material.keyOptions || []) : [];
            const min = keyMin.value.trim();
            const max = keyMax.value.trim();
            // Recorded values and the range exist per material only: nothing to enter until one is chosen.
            keyMin.disabled = keyMax.disabled = !material || shapeMismatchShown;
            if (!material) {
                keyOption.innerHTML = `<option value="">${escapeOptionText(keyOption.dataset.labelAll)}</option>`;
                keyOption.disabled = true;
                return;
            }
            if (shapeMismatchShown || options.length === 0) {
                keyOption.innerHTML = `<option value="">${escapeOptionText(keyOption.dataset.labelNone)}</option>`;
                keyOption.disabled = true;
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
        }

        function applyShape() {
            const material = selectedMaterial();
            // The applied shape (hidden input, what is actually queried) always comes from the material
            // itself, so it never points at data that does not exist. The dimension panel LABEL is cosmetic
            // and, while parked on a Shape filter mismatch, describes that shape instead (Round/Hollow show
            // their diameter label even with nothing recorded yet); otherwise it follows the material.
            const appliedShape = material?.shape || '';
            const displayShape = shapeMismatchShown ? (shapeFilter?.value || appliedShape) : (appliedShape || shapeFilter?.value || '');
            if (shapeInput) shapeInput.value = appliedShape;
            if (keyDimension) keyDimension.hidden = Boolean(material) && !keyLabels[displayShape];
            if (keyDimensionLabel) keyDimensionLabel.textContent = keyLabels[displayShape] || keyLabels.Flat;
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
                if (String(value).trim() !== '') url.searchParams.set(key, String(value).trim());
            }
            window.syncComparisonState(url.toString());
        }

        const materialsGuard = window.createComparisonRequestGuard();
        const payloadGuard = window.createComparisonRequestGuard();

        function clearHistorycalResults(message) {
            if (!resultsContainer) return;
            resultsContainer.innerHTML = `
                <div class="tw:rounded-ui-md tw:border tw:border-outline tw:bg-surface">
                    <div class="tw:flex tw:flex-col tw:items-center tw:justify-center tw:px-4 tw:py-8 tw:text-center">
                        <span class="tw:text-on-surface-variant">${comparisonIcons.chartEmpty}</span>
                        <p class="tw:m-0 tw:mt-3 tw:text-ui-sm tw:text-on-surface-variant">${escapeOptionText(message)}</p>
                    </div>
                </div>
            `;
        }

        async function loadMaterialsForSupplier() {
            const supplierId = supplierSelect.value;
            const previous = selectedMaterial();
            const previousBounds = { min: keyMin?.value.trim() || '', max: keyMax?.value.trim() || '' };
            const request = materialsGuard.start();
            shapeMismatchShown = false;

            renderMaterialOptions([], null);

            materialSelect.innerHTML = '<option value="">' + @json(__('purchasing.copy.comparison_loading_materials')) + '</option>';
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
                // Keep the (material, shape) when this supplier still offers it; keep the bounds
                // only if an exact value is still among its recorded values (or it is a custom range).
                const kept = previous && materials.find((material) => material.name === previous.name && (material.shape || '') === previous.shape);
                renderMaterialOptions(materials, kept ? previous : null);
                // Nothing in the chosen shape for this supplier: say so instead of "select a material".
                if (materialSelect.disabled) clearHistorycalResults(window.AdasiI18n.t('purchasing.js.history_no_material'));
                const exact = previousBounds.min !== '' && previousBounds.min === previousBounds.max;
                if (!kept || (exact && !(kept.key_options || []).some((value) => Number(value) === Number(previousBounds.min)))) {
                    clearKeyBounds();
                }
                applyShape();

                if (kept && typeof window.loadHistorycalPayloadFromFilters === 'function') {
                    window.loadHistorycalPayloadFromFilters();
                } else if (kept) {
                    filterForm.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
                } else {
                    // Nothing to load: the URL still has to describe what is on screen.
                    syncUrlFromForm();
                }
            } catch (error) {
                if (error?.name === 'AbortError' || !materialsGuard.isCurrent(request.id)) return;
                renderMaterialOptions([], null);
                clearHistorycalResults(@json(__('purchasing.copy.comparison_failed_material_list')));
            } finally {
                if (materialsGuard.isCurrent(request.id)) resultsContainer?.setAttribute('aria-busy', 'false');
            }
        }

        // Monthly and yearly views (the only period views the controller serves) chart the weighted
        // average per period on one axis; highest and lowest stay in the tooltip and the table.
        function historicalChartConfig(payload) {
            const chartData = payload.chartData || {};
            const theme = window.AdasiChart;
            const labels = {
                average: @json(__('charts.series.average')),
                highest: @json(__('charts.series.highest')),
                lowest: @json(__('charts.series.lowest')),
            };

            return {
                type: 'line',
                data: {
                    labels: chartData.labels || [],
                    datasets: [{
                        label: window.AdasiI18n.t('purchasing.js.weighted_average_price_kg') + ' (' + historicalCurrency + ')',
                        data: chartData.pricesIdr || [],
                        borderColor: theme.color('series-1'),
                        backgroundColor: theme.color('series-1'),
                        pointBorderColor: theme.color('surface'),
                        pointStyle: 'circle',
                        borderWidth: 2,
                        fill: false,
                        tension: 0,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        pointBorderWidth: 2,
                    }],
                },
                options: {
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: theme.getLegend({ position: 'bottom', align: 'center' }),
                        adasiA11y: { format: (value) => formatRupiah(value) },
                        tooltip: theme.getTooltip({
                            callbacks: {
                                label: (context) => ' ' + labels.average + ': ' + formatRupiah(context.parsed.y),
                                afterLabel: (context) => {
                                    const index = context.dataIndex;
                                    return [
                                        ' ' + labels.highest + ': ' + formatRupiah(chartData.maxIdr?.[index]),
                                        ' ' + labels.lowest + ': ' + formatRupiah(chartData.minIdr?.[index]),
                                    ];
                                },
                            },
                        }),
                    },
                    scales: theme.getScales({
                        yMaxTicks: 5,
                        yBeginAtZero: true,
                        yFormat: (value) => formatRupiah(value),
                    }),
                },
            };
        }

        function renderHistorycalChart(payload) {
            if (window._activeComparisonChart) {
                window._activeComparisonChart.destroy();
                window._activeComparisonChart = null;
            }

            const canvas = document.getElementById('historicalChart');
            if (!canvas || !window.AdasiChart) return;

            window._activeComparisonChart = new Chart(canvas, historicalChartConfig(payload));
        }

        function updateSummaryClass(element, value) {
            if (!element) return;
            element.classList.remove('tw:text-error', 'tw:text-success', 'tw:text-on-surface-variant');
            element.classList.add(value > 0 ? 'tw:text-error' : (value < 0 ? 'tw:text-success' : 'tw:text-on-surface-variant'));
        }

        function renderSummary(summary) {
            const weighted = document.getElementById('historicalWeightedAverage');
            if (weighted) {
                weighted.textContent = summary.weighted_average != null ? formatRupiah(summary.weighted_average) : '—';
            }
            const basis = document.getElementById('historicalWeightedBasis');
            if (basis) {
                basis.textContent = window.AdasiI18n.t('purchasing.js.history_basis', { pos: summary.po_count || 0, kg: formatNumber(summary.total_kg || 0) })
                    + ' · ' + window.AdasiI18n.t('purchasing.js.historical_best') + ': ' + (summary.best != null ? formatRupiah(summary.best) : '—');
            }
            const excluded = document.getElementById('historicalExcludedNote');
            if (excluded) {
                const lines = summary.excluded?.lines || 0;
                excluded.classList.toggle('d-none', lines === 0);
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

            if (payload.periodView === 'yearly') {
                head.innerHTML = `
                    <tr>
                        <th scope="col" class="ui-sticky-col">${window.AdasiI18n.t('purchasing.js.year')}</th>
                        <th scope="col">${window.AdasiI18n.t('purchasing.js.weighted_average_price_kg')} (${escapeOptionText(historicalCurrency)})</th>
                        <th scope="col">${window.AdasiI18n.t('purchasing.js.lowest_price')}</th>
                        <th scope="col">${window.AdasiI18n.t('purchasing.js.highest_price')}</th>
                        <th scope="col">${window.AdasiI18n.t('purchasing.js.change_from_previous_period')}</th>
                    </tr>
                `;
                body.innerHTML = rows.map((row) => `
                    <tr>
                        <td class="text-center fw-medium ui-sticky-col">${escapeOptionText(row.period)}</td>
                        <td class="text-end text-primary fw-bold">${formatRupiah(row.price_idr)}</td>
                        <td class="text-end">${formatRupiah(row.min_idr)}</td>
                        <td class="text-end">${formatRupiah(row.max_idr)}</td>
                        <td class="text-center">${changeHtml(row.change_pct)}</td>
                    </tr>
                `).join('');
                return;
            }

            head.innerHTML = `
                <tr>
                    <th scope="col" class="ui-sticky-col">${window.AdasiI18n.t('purchasing.js.pr_number')}</th>
                    <th scope="col">${window.AdasiI18n.t('purchasing.js.supplier')}</th>
                    <th scope="col">${window.AdasiI18n.t('purchasing.js.price_kg')}</th>
                    <th scope="col">${window.AdasiI18n.t('purchasing.js.converted_total')} (${escapeOptionText(historicalCurrency)})</th>
                    <th scope="col">${window.AdasiI18n.t('purchasing.js.po_date')}</th>
                    <th scope="col">${window.AdasiI18n.t('purchasing.js.change')}</th>
                </tr>
            `;
            body.innerHTML = rows.map((row) => `
                <tr>
                    <td class="text-center fw-medium ui-sticky-col">
                        ${row.pr_url
                            ? `<a href="${escapeOptionText(row.pr_url)}" class="text-primary text-decoration-none tw:hover:underline">${escapeOptionText(row.pr_number || '-')} →</a>`
                            : escapeOptionText(row.pr_number || '-')}
                    </td>
                    <td class="text-center">${escapeOptionText(row.supplier || '-')}</td>
                    <td class="text-end ui-tabular-nums">${formatMaxDecimals(row.price_per_kg, 4)} <span class="ui-status-chip ui-status-chip--neutral tw:ms-1">${escapeOptionText(row.currency)}</span><div class="tw:text-ui-xs tw:text-on-surface-variant">${escapeOptionText(row.annual_price_display || '')}</div></td>
                    <td class="text-end text-primary fw-bold ui-tabular-nums">${formatRupiah(row.total_idr)}</td>
                    <td class="text-center">${row.purchase_order_at_display ? escapeOptionText(row.purchase_order_at_display) : '<span class="ui-status-chip ui-status-chip--neutral">' + escapeOptionText(@json(__('purchasing.copy.draft'))) + '</span>'}</td>
                    <td class="text-center">${changeHtml(row.change_pct)}</td>
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
                container.classList.add('d-none');
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
            container.classList.remove('d-none');
        }

        function historicalResultShellHtml() {
            return `
                <section class="tw:overflow-hidden tw:rounded-ui-md tw:border tw:border-outline tw:bg-surface" aria-labelledby="historicalChartTitle">
                    <header class="tw:border-b tw:border-outline-variant tw:bg-surface-container tw:px-4 tw:py-3">
                        <h2 class="tw:m-0 tw:flex tw:items-center tw:gap-2 tw:text-ui-sm tw:font-semibold tw:text-on-surface" id="historicalChartTitle"></h2>
                    </header>
                    <div class="tw:p-4">
                        <figure class="ui-chart tw:m-0 tw:min-w-0" data-adasi-chart>
                            <div class="tw:relative tw:w-full tw:h-64">
                                <canvas id="historicalChart" role="img" tabindex="0" aria-label="${escapeOptionText(@js(__('common.accessibility.history_trend')))}" aria-describedby="historicalChart-summary historicalChart-hint" data-adasi-chart-focusable>${escapeOptionText(@js(__('common.accessibility.history_chart')))}</canvas>
                                <span class="tw:sr-only" aria-live="polite" data-adasi-chart-live></span>
                            </div>
                            <figcaption class="tw:mt-2 tw:grid tw:gap-0.5 tw:text-ui-xs tw:text-on-surface-variant">
                                <span id="historicalChart-summary" class="tw:text-on-surface" data-adasi-chart-summary="auto"></span>
                                <span id="historicalChart-hint">${escapeOptionText(@js(__('charts.keyboard_hint')))}</span>
                            </figcaption>
                        </figure>
                    </div>
                </section>

                <section class="tw:grid tw:overflow-hidden tw:rounded-ui-md tw:border tw:border-outline tw:bg-surface-container tw:sm:grid-cols-2 tw:sm:divide-x tw:sm:divide-outline-variant" id="historicalSummary" aria-label="${escapeOptionText(@js(__('common.accessibility.history_summary')))}">
                    <div class="tw:p-4">
                        <div class="tw:text-ui-xs tw:font-semibold tw:uppercase tw:tracking-wider tw:text-on-surface-variant">${escapeOptionText(@js(__('common.accessibility.average_change')))}</div>
                        <div class="ui-tabular-nums tw:mt-1 tw:text-ui-2xl tw:font-semibold tw:text-on-surface-variant" id="averageChangeValue">-</div>
                    </div>
                    <div class="tw:border-t tw:border-outline-variant tw:p-4 tw:sm:border-t-0">
                        <div class="tw:text-ui-xs tw:font-semibold tw:uppercase tw:tracking-wider tw:text-on-surface-variant">${escapeOptionText(@js(__('common.accessibility.initial_latest')))}</div>
                        <div class="ui-tabular-nums tw:mt-1 tw:text-ui-2xl tw:font-semibold tw:text-on-surface-variant" id="totalChangeValue">-</div>
                    </div>
                </section>

                <section class="ui-data-table tw:overflow-hidden tw:rounded-ui-md tw:border tw:border-outline tw:bg-surface tw:shadow-none">
                    <header class="tw:border-b tw:border-outline-variant tw:bg-surface-container tw:px-4 tw:py-3">
                        <h2 class="tw:m-0 tw:text-sm tw:font-bold tw:text-on-surface">Supporting Data</h2>
                        <p class="tw:m-0 tw:mt-0.5 tw:text-ui-xs tw:text-on-surface-variant">Quotation values and period changes for the selected supplier and exact material specification.</p>
                    </header>
                    <div class="ui-data-table__scroll ui-table-sticky tw:w-full tw:min-w-0 tw:max-w-full" role="region" tabindex="0" aria-label="${escapeOptionText(@js(__('purchasing.copy.supporting_data')))}">
                        <table class="table table-hover align-middle mb-0 tw:text-ui-sm">
                            <thead class="table-light text-center" id="historicalTableHead"></thead>
                            <tbody id="historicalTableBody"></tbody>
                        </table>
                    </div>
                    <div id="historicalPagination" class="d-none tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-3 tw:border-t tw:border-outline-variant tw:bg-surface-low tw:px-4 tw:py-3">
                        <span id="historicalPaginationSummary" class="tw:text-ui-xs tw:text-on-surface-variant"></span>
                        <div class="tw:flex tw:items-center tw:gap-2">
                            <button type="button" id="historicalPreviousPage" data-history-page="1" class="ui-button ui-motion ui-focus-ring tw:inline-flex tw:min-h-[var(--ui-control-height-sm)] tw:items-center tw:justify-center tw:rounded-ui-sm tw:border tw:border-outline tw:bg-transparent tw:px-2.5 tw:py-1 tw:text-ui-xs tw:font-semibold tw:text-on-surface tw:disabled:cursor-not-allowed tw:disabled:opacity-50">${escapeOptionText(@js(__('pagination.previous')))}</button>
                            <span id="historicalPaginationPage" class="tw:min-w-20 tw:text-center tw:text-ui-xs tw:font-semibold tw:text-on-surface"></span>
                            <button type="button" id="historicalNextPage" data-history-page="1" class="ui-button ui-motion ui-focus-ring tw:inline-flex tw:min-h-[var(--ui-control-height-sm)] tw:items-center tw:justify-center tw:rounded-ui-sm tw:border tw:border-outline tw:bg-transparent tw:px-2.5 tw:py-1 tw:text-ui-xs tw:font-semibold tw:text-on-surface tw:disabled:cursor-not-allowed tw:disabled:opacity-50">${escapeOptionText(@js(__('pagination.next')))}</button>
                        </div>
                    </div>
                </section>
            `;
        }

        function renderPayload(payload) {
            historicalCurrency = payload.currency || historicalCurrency;
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
                if (window._activeComparisonChart) {
                    window._activeComparisonChart.destroy();
                    window._activeComparisonChart = null;
                }

                if (resultsContainer) {
                    resultsContainer.innerHTML = `
                        <div class="tw:rounded-ui-md tw:border tw:border-outline tw:bg-surface">
                            <div class="tw:flex tw:flex-col tw:items-center tw:justify-center tw:px-4 tw:py-8 tw:text-center">
                                <span class="tw:text-on-surface-variant">${comparisonIcons.chartEmpty}</span>
                                <p class="tw:m-0 tw:mt-3 tw:text-ui-sm tw:text-on-surface-variant">
                                    ${escapeOptionText(payload.materialName
                                        ? @json(__('purchasing.copy.comparison_no_quotation_data'))
                                        : @json(__('purchasing.copy.comparison_select_supplier_and_material')))}
                                </p>
                            </div>
                        </div>
                    `;
                }
                return;
            }

            if (!document.getElementById('historicalChart') && resultsContainer) {
                resultsContainer.innerHTML = historicalResultShellHtml();
            }

            renderHistorycalChart(payload);
            renderSummary(payload.summary || {});
            renderTable(payload);
            renderPagination(payload.pagination || null);
            const titleEl = document.getElementById('historicalChartTitle');
            if (titleEl) {
                const title = @json(__('purchasing.copy.comparison_price_trend_title', ['material' => ':material', 'supplier' => ':supplier']))
                    .replace(':material', escapeOptionText(payload.materialName))
                    .replace(':supplier', escapeOptionText(payload.supplierName || @json(__('purchasing.comparison_analytics.all_suppliers'))));
                titleEl.innerHTML = `<span class="tw:text-primary me-1">${comparisonIcons.chart}</span><span>${title}</span>`;
            }
        }

        window.loadHistorycalPayloadFromFilters = async function(historyPage = 1) {
            if (!materialSelect.value) {
                return;
            }

            const url = new URL(historicalDataUrl, window.location.origin);
            const formData = new FormData(filterForm);
            for (const [key, value] of formData.entries()) {
                if (value.trim() !== '') {
                    url.searchParams.set(key, value.trim());
                }
            }
            url.searchParams.set('view', 'json');
            if (Number(historyPage) > 1) {
                url.searchParams.set('history_page', String(historyPage));
            }
            const request = payloadGuard.start();
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
                // A slower, older response must never overwrite the latest selection.
                if (!payloadGuard.isCurrent(request.id)) return;
                renderPayload(payload);

                url.searchParams.delete('view');
                const resolvedPage = Number(payload.pagination?.current_page || 1);
                if (resolvedPage > 1) {
                    url.searchParams.set('history_page', String(resolvedPage));
                } else {
                    url.searchParams.delete('history_page');
                }
                window.syncComparisonState(url.toString());
            } catch (error) {
                if (error?.name === 'AbortError' || !payloadGuard.isCurrent(request.id)) return;
                if (resultsContainer) {
                    resultsContainer.innerHTML = `
                        <div class="tw:flex tw:items-start tw:gap-3 tw:rounded-ui-sm tw:border-s-4 tw:border-warning tw:bg-warning-container tw:p-4 tw:text-warning-container-foreground" role="status">
                            <div class="tw:text-ui-sm">${escapeOptionText(@json(__('purchasing.copy.comparison_failed_historical_data')))}</div>
                        </div>
                    `;
                }
            } finally {
                if (payloadGuard.isCurrent(request.id)) {
                    if (submitBtn && window.AdasiButton && typeof window.AdasiButton.stopLoading === 'function') {
                        window.AdasiButton.stopLoading(submitBtn);
                    }
                    resultsContainer?.setAttribute('aria-busy', 'false');
                }
            }
        };

        periodViewInputs.forEach((input) => {
            input.addEventListener('change', () => {
                renderRangeOptions(input.value, rangeSelect.value);
                if (materialSelect.value) {
                    window.loadHistorycalPayloadFromFilters();
                } else {
                    filterForm.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
                }
            });
        });

        supplierSelect.addEventListener('change', loadMaterialsForSupplier);

        // The material name, once chosen, always stays locked: the Shape filter never resets it, and it is
        // never narrowed away. The Shape filter itself always follows the click. With a material locked:
        //   - its own shape, or "All shapes": no-op, the material and its results are untouched;
        //   - a shape this same material name also has on file: switch to that (name, shape) record;
        //   - a shape this material has no record under: say so, nothing else changes (no refetch, URL kept).
        // With no material chosen, the filter only narrows the list and the dimension panel.
        shapeFilter?.addEventListener('change', () => {
            const current = selectedMaterial();
            const shape = shapeFilter.value;

            if (!current) {
                renderMaterialOptions(materialCatalog, null);
                clearHistorycalResults(window.AdasiI18n.t(materialSelect.disabled ? 'purchasing.js.history_no_material' : 'purchasing.js.history_select_material'));
                clearKeyBounds();
                applyShape();
                return;
            }

            if (shape === '' || shape === current.shape) {
                // Back to a shape that matches: restore the dimension panel, and the real results if a
                // mismatch message was showing.
                const wasMismatched = shapeMismatchShown;
                shapeMismatchShown = false;
                applyShape();
                if (wasMismatched) window.loadHistorycalPayloadFromFilters();
                return;
            }

            const match = materialCatalog.find((material) => material.name === current.name && (material.shape || '') === shape);
            if (match) {
                const option = Array.from(materialSelect.options).find((o) => o.value === match.name && (o.dataset.shape || '') === match.shape);
                if (option) option.selected = true;
                shapeMismatchShown = false;
                clearKeyBounds();
                applyShape();
                window.loadHistorycalPayloadFromFilters();
                return;
            }

            // No record for this material under the clicked shape: the panel still describes that shape
            // (Round/Hollow show their diameter label) with nothing to pick, and the results say so.
            shapeMismatchShown = true;
            clearKeyBounds();
            applyShape();
            clearHistorycalResults(window.AdasiI18n.t('purchasing.js.history_no_material'));
        });

        materialSelect.addEventListener('change', () => {
            // A different (material, shape) starts from an unfiltered key dimension.
            shapeMismatchShown = false;
            clearKeyBounds();
            applyShape();
            if (materialSelect.value) {
                window.loadHistorycalPayloadFromFilters();
            } else {
                clearHistorycalResults(@json(__('purchasing.copy.comparison_select_material_from_supplier')));
            }
        });

        keyOption?.addEventListener('change', () => {
            if (keyOption.value === '__custom') return;
            if (keyMin) keyMin.value = keyOption.value;
            if (keyMax) keyMax.value = keyOption.value;
            syncKeyOption();
            if (materialSelect.value) window.loadHistorycalPayloadFromFilters();
        });

        // Typing a range keeps working; the recorded-values control then shows "Custom range".
        [keyMin, keyMax].forEach((input) => input?.addEventListener('input', syncKeyOption));

        rangeSelect.addEventListener('change', () => {
            if (materialSelect.value) {
                window.loadHistorycalPayloadFromFilters();
            } else {
                filterForm.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
            }
        });

        filterForm.addEventListener('submit', (e) => {
            if (materialSelect.value) {
                e.preventDefault();
                window.loadHistorycalPayloadFromFilters();
            }
        });

        // Shape Dimension Visibility
        const dimensionFields = document.querySelectorAll('.dimension-field');
        const relevantDimensions = {
            'Flat': ['thickness', 'width', 'length'],
            'Round': ['d_outer', 'length'],
            'Hollow': ['d_inner', 'd_outer', 'length']
        };

        function updateDimensionVisibility() {
            if (!materialSelect || !materialSelect.selectedOptions.length) return;
            const selectedOption = materialSelect.selectedOptions[0];
            const shape = selectedOption.dataset.shape || '';
            const allowed = relevantDimensions[shape] || ['thickness', 'd_inner', 'd_outer', 'width', 'length'];

            dimensionFields.forEach(field => {
                const dim = field.dataset.dim;
                if (allowed.includes(dim)) {
                    field.style.display = '';
                } else {
                    field.style.display = 'none';
                    const input = field.querySelector('input');
                    if (input) input.value = '';
                }
            });
        }

        // The server renders the complete material list; apply the initial Shape filter to it.
        materialCatalog = catalogFromDom();
        if (shapeFilter?.value) renderMaterialOptions(materialCatalog, selectedMaterial());

        materialSelect.addEventListener('change', updateDimensionVisibility);
        updateDimensionVisibility();
        applyShape();

        const activeView = document.querySelector('input[name="period_view"]:checked')?.value || 'monthly';
        renderRangeOptions(activeView, rangeSelect.value);

        // Render initial chart if chartData exists
        if (config.chartData && typeof Chart !== 'undefined') {
            renderHistorycalChart({
                chartData: config.chartData,
                periodView: config.periodView,
                materialName: config.selectedMaterialName,
                supplierName: '',
            });
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
            console.error('Invalid vsBestConfig JSON', e);
            return;
        }

        const summaryFallback = config.summaryFallback || {};
        const filterParams = Object.assign({}, config.filterParams || {});
        const tableDataUrl = config.tableDataUrl;

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
            $('#vsBestTotalRows').text(formatInteger(data.total_rows));
            $('#vsBestCompetitiveCount').text(formatInteger(data.competitive_count));
            $('#vsBestAboveCount').text(formatInteger(data.above_count));
            $('#vsBestPotentialTotal').text(formatRupiah(data.total_potential_difference_idr));
        }

        if (typeof $ !== 'undefined' && $.fn.DataTable && $('#vsBestTable').length) {
            if ($.fn.DataTable.isDataTable('#vsBestTable')) {
                $('#vsBestTable').DataTable().destroy();
            }

            window._activeVsBestTable = $('#vsBestTable').DataTable({
                processing: true,
                serverSide: true,
                // PUR-04: only the table scrolls (sticky header and Material column); the length
                // menu, search, info and paging stay outside the scroll region.
                dom: "<'row'<'col-sm-12 col-md-6'l><'col-sm-12 col-md-6'f>>"
                    + "<'row dt-row'<'col-sm-12'<'ui-data-table__scroll ui-table-sticky'tr>>>"
                    + "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
                initComplete: function() {
                    const region = this.api().table().container().querySelector('.ui-table-sticky');
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
                        console.error('[vsBestTable] AJAX error:', error, thrown);
                        if (window.AdasiToast && typeof window.AdasiToast.error === 'function') {
                            window.AdasiToast.error('Gagal memuat data tabel perbandingan harga.');
                        }
                        $('#vsBestTable_processing').hide();
                    }
                },
                columns: [
                    { data: 'material_display', name: 'current_pr_items.material_name', className: 'text-start ui-sticky-col' },
                    { data: 'current_price_display', name: 'current_price_idr', className: 'text-end', searchable: false },
                    { data: 'best_price_display', name: 'best_price_idr', className: 'text-end', searchable: false },
                    { data: 'diff_display', name: 'diff_idr_per_kg', className: 'text-center', searchable: false },
                    { data: 'potential_difference_display', name: 'potential_difference_idr', className: 'text-end', searchable: false },
                    { data: 'status_badge', name: 'diff_percent', className: 'text-center', searchable: false },
                    { data: 'action', name: 'action', className: 'text-center', orderable: false, searchable: false }
                ],

                order: [[4, 'desc']]
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
            filterParams.material_name = vsBestMaterial.value || null;
            filterParams.shape = vsBestMaterial.value ? (option?.dataset.shape || '') : null;
            window.syncComparisonState(vsBestUrl());
            if (window._activeVsBestTable) {
                window._activeVsBestTable.ajax.reload();
            }
        });
    };

    // ─── 4. GLOBAL CONTAINER COORDINATION ────────────────────────────────────
    window.initCurrentComparisonTab = function() {
        if (window.AdasiCalendar && typeof window.AdasiCalendar.initialize === 'function') {
            const contentContainer = document.getElementById('purchasingComparisonContent');
            if (contentContainer) {
                window.AdasiCalendar.initialize(contentContainer);
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
            window.loadHistorycalPayloadFromFilters(Number(button.dataset.historyPage || 1));
        }
    });

    function ensureComparisonServerTabs() {
        if (typeof AdasiServerTabs !== 'undefined' && typeof AdasiServerTabs.init === 'function') {
            AdasiServerTabs.init('#purchasingComparisonContainer');
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
