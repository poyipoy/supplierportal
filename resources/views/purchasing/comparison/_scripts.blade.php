<script>
(function() {
    window._activeComparisonChart = null;
    window._activeVsBestTable = null;

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
                empty.textContent = 'No matching PR was found.';
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
                meta.textContent = `${option.period} - ${option.quotationCount} quotation(s)`;

                const preview = document.createElement('div');
                preview.className = 'text-secondary mt-1';
                preview.style.fontSize = '.7rem';
                preview.innerHTML = `<span class="me-1">&#128230;</span>${option.previewMaterials || ''}`;

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

        // Comparison Bar Chart
        const chartCanvas = document.getElementById('comparisonChart');
        if (comparisonChartData && chartCanvas && typeof Chart !== 'undefined') {
            const colors = window.AdasiChart ? window.AdasiChart.getColors() : {
                primary: '#1F5FA6',
                secondary: '#476072',
                success: '#1E8449',
                warning: '#D35400',
                onSurfaceVariant: '#64748B',
                gridLine: 'rgba(226, 232, 240, 0.75)',
            };
            const comparisonPalette = [
                colors.primary,
                '#2563EB',
                colors.secondary,
                '#0D9488',
                '#D97706',
                '#7C3AED',
            ];
            const clonedChartData = JSON.parse(JSON.stringify(comparisonChartData));
            clonedChartData.datasets.forEach((dataset, index) => {
                const baseColor = comparisonPalette[index % comparisonPalette.length];
                dataset.backgroundColor = (context) => window.AdasiChart?.createBarGradient(context, baseColor, 0.92, 0.35) || baseColor;
                dataset.borderColor = baseColor;
                dataset.borderWidth = 1;
                dataset.borderRadius = 6;
                dataset.borderSkipped = false;
                dataset.maxBarThickness = 36;
            });

            window._activeComparisonChart = new Chart(chartCanvas, {
                type: 'bar',
                data: clonedChartData,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: { duration: 400 },
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                boxWidth: 8,
                                boxHeight: 8,
                                usePointStyle: true,
                                font: { family: 'Inter', size: 11, weight: '500' },
                                color: colors.onSurfaceVariant,
                                padding: 12,
                            },
                        },
                        tooltip: window.AdasiChart?.getTooltip({
                            callbacks: {
                                label: (ctx) => ' ' + ctx.dataset.label + ': Rp ' + Number(ctx.parsed.y).toLocaleString('id-ID'),
                            }
                        }) || {},
                    },
                    scales: window.AdasiChart?.getScales({
                        yMaxTicks: 5,
                        yBeginAtZero: true,
                        yFormat: (v) => 'Rp ' + Number(v).toLocaleString('id-ID'),
                    }) || {},
                }
            });

            const materialFilter = document.getElementById('comparisonMaterialFilter');
            if (materialFilter) {
                materialFilter.addEventListener('change', function() {
                    const materialId = this.value;
                    document.querySelectorAll('[data-comparison-row]').forEach((row) => {
                        row.classList.toggle('d-none', materialId !== '' && row.dataset.materialId !== materialId);
                    });

                    if (!window._activeComparisonChart) return;

                    if (materialId === '') {
                        window._activeComparisonChart.data = JSON.parse(JSON.stringify(clonedChartData));
                        window._activeComparisonChart.update();
                        return;
                    }

                    const materialIndex = comparisonMaterialIds.indexOf(materialId);
                    if (materialIndex === -1) return;

                    window._activeComparisonChart.data.labels = [clonedChartData.labels[materialIndex]];
                    window._activeComparisonChart.data.datasets = clonedChartData.datasets.map((dataset) => ({
                        ...dataset,
                        data: [dataset.data[materialIndex] ?? 0],
                    }));
                    window._activeComparisonChart.update();
                });
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
                previewContainer.replaceChildren(textElement('span', 'tw-text-on-surface-variant', 'No items selected yet. Select supplier offers above.'));
                groupContainer.replaceChildren(textElement('span', 'tw-text-on-surface-variant', '1 PO will be created per selected supplier group upon confirmation.'));
                return;
            }

            const itemsList = textElement('ul', 'tw-list-disc tw-ps-4 tw-space-y-1', '');
            const supplierMap = new Map();

            radios.forEach(radio => {
                const itemName = radio.dataset.prItemName;
                const supplierName = radio.dataset.supplierName;
                const supplierId = radio.dataset.supplierId;
                const price = radio.dataset.price;

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

            const groups = textElement('div', 'tw-space-y-2', '');
            let poCount = 0;
            for (const supplier of supplierMap.values()) {
                poCount++;
                const group = textElement('div', 'tw-p-2 tw-rounded tw-bg-surface tw-border tw-border-outline-variant', '');
                group.append(
                    textElement('div', 'fw-bold tw-text-ui-xs text-primary', `PO #${poCount} • ${supplier.name}`),
                    textElement('div', 'tw-text-on-surface-variant tw-text-ui-xs', `${supplier.items.length} item(s): ${supplier.items.join(', ')}`),
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

        document.querySelectorAll('.award-radio').forEach(radio => {
            radio.addEventListener('change', updateAwardPreviews);
        });

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

        function formatRupiah(value) {
            if (value === null || value === undefined || value === '') return '-';
            return 'Rp ' + Number(value).toLocaleString('id-ID');
        }

        function formatNumber(value, decimals = 2) {
            if (value === null || value === undefined || value === '') return '-';
            return Number(value).toLocaleString('id-ID', {
                minimumFractionDigits: decimals,
                maximumFractionDigits: decimals,
            });
        }

        function formatMaxDecimals(value, maxDecimals = 2) {
            if (value === null || value === undefined || value === '') return '-';
            return Number(value).toLocaleString('id-ID', {
                minimumFractionDigits: 0,
                maximumFractionDigits: maxDecimals,
            });
        }

        function formatPercent(value) {
            if (value === null || value === undefined) return '-';
            return (Number(value) > 0 ? '+' : '') + Number(value).toLocaleString('id-ID', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            }) + '%';
        }

        function changeHtml(value) {
            if (value === null || value === undefined) return '<span class="tw-text-on-surface-variant">-</span>';
            const numberValue = Number(value);

            if (numberValue > 0) {
                return `<span class="tw-font-semibold tw-text-error">+${formatNumber(numberValue)}%</span>`;
            }

            if (numberValue < 0) {
                return `<span class="tw-font-semibold tw-text-success">&minus;${formatNumber(Math.abs(numberValue))}%</span>`;
            }

            return '<span class="tw-font-semibold tw-text-on-surface-variant">0%</span>';
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

        function renderMaterialOptions(materials, selectedValue = '') {
            const placeholder = !supplierSelect.value
                ? 'Select Supplier first'
                : (materials.length > 0 ? 'Select Material' : 'No historical material');

            materialSelect.innerHTML = [
                `<option value="">${escapeOptionText(placeholder)}</option>`,
                ...materials.map((material) => {
                    const name = typeof material === 'string' ? material : (material.name || '');
                    const shape = typeof material === 'string' ? '' : (material.shape || '');
                    const selected = name === selectedValue ? ' selected' : '';
                    return `<option value="${escapeOptionText(name)}" data-shape="${escapeOptionText(shape)}"${selected}>${escapeOptionText(name)}</option>`;
                }),
            ].join('');
            materialSelect.disabled = !supplierSelect.value || materials.length === 0;
        }

        function clearHistorycalResults(message) {
            if (!resultsContainer) return;
            resultsContainer.innerHTML = `
                <div class="tw-rounded-ui-md tw-border tw-border-outline tw-bg-surface">
                    <div class="tw-flex tw-flex-col tw-items-center tw-justify-center tw-px-4 tw-py-8 tw-text-center">
                        <span class="tw-text-on-surface-variant" style="font-size: 2rem;">&#128200;</span>
                        <p class="tw-m-0 tw-mt-3 tw-text-ui-sm tw-text-on-surface-variant">${escapeOptionText(message)}</p>
                    </div>
                </div>
            `;
        }

        async function loadMaterialsForSupplier() {
            const supplierId = supplierSelect.value;
            const previousMaterial = materialSelect.value;

            renderMaterialOptions([], '');

            if (!supplierId) {
                clearHistorycalResults('Select a supplier and material above to view the historical price trend.');
                return;
            }

            materialSelect.innerHTML = '<option value="">Loading materials...</option>';
            materialSelect.disabled = true;
            clearHistorycalResults('Select a material from the selected supplier to view the historical price trend.');
            resultsContainer?.setAttribute('aria-busy', 'true');

            try {
                const url = new URL(materialsUrl, window.location.origin);
                url.searchParams.set('supplier_id', supplierId);

                const response = await fetch(url.toString(), {
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (!response.ok) {
                    throw new Error('Failed to load material');
                }

                const data = await response.json();
                const materials = data.materials || [];
                const selectedMaterial = materials.includes(previousMaterial) ? previousMaterial : '';
                renderMaterialOptions(materials, selectedMaterial);

                if (selectedMaterial && typeof window.loadHistorycalPayloadFromFilters === 'function') {
                    window.loadHistorycalPayloadFromFilters();
                } else if (selectedMaterial) {
                    filterForm.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
                }
            } catch (error) {
                renderMaterialOptions([], '');
                clearHistorycalResults('Failed to load material list. Try selecting a supplier again.');
            } finally {
                resultsContainer?.setAttribute('aria-busy', 'false');
            }
        }

        function historicalChartConfig(payload) {
            const chartData = payload.chartData || {};
            const colors = window.AdasiChart ? window.AdasiChart.getColors() : {
                primary: '#1F5FA6',
                error: '#C0392B',
                surface: '#FFFFFF',
                onSurfaceVariant: '#64748B',
                gridLine: 'rgba(226, 232, 240, 0.75)',
            };
            const primaryColor = colors.primary;
            const errorColor = colors.error;

            if (payload.periodView === 'yearly') {
                return {
                    type: 'line',
                    data: {
                        labels: chartData.labels || [],
                        datasets: [{
                            label: 'Average Price/Kg (IDR)',
                            data: chartData.pricesIdr || [],
                            borderColor: primaryColor,
                            backgroundColor: (ctx) => window.AdasiChart?.createAreaGradient(ctx, primaryColor, 0.18, 0.01) || 'transparent',
                            borderWidth: 2.5,
                            fill: true,
                            tension: 0.3,
                            pointRadius: 3.5,
                            pointHoverRadius: 6,
                            pointBackgroundColor: primaryColor,
                            pointBorderColor: colors.surface,
                            pointBorderWidth: 2,
                        }],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        animation: { duration: 400 },
                        interaction: { mode: 'index', intersect: false },
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    boxWidth: 8,
                                    boxHeight: 8,
                                    usePointStyle: true,
                                    font: { family: 'Inter', size: 11, weight: '500' },
                                    color: colors.onSurfaceVariant,
                                    padding: 12,
                                }
                            },
                            tooltip: window.AdasiChart?.getTooltip({
                                callbacks: {
                                    label: (context) => ' Average: ' + formatRupiah(context.parsed.y),
                                    afterLabel: (context) => {
                                        const index = context.dataIndex;
                                        return [
                                            ' Highest: ' + formatRupiah(chartData.maxIdr?.[index]),
                                            ' Lowest: ' + formatRupiah(chartData.minIdr?.[index]),
                                        ];
                                    },
                                },
                            }) || {},
                        },
                        scales: window.AdasiChart?.getScales({
                            yMaxTicks: 5,
                            yBeginAtZero: true,
                            yFormat: (value) => 'Rp ' + Number(value).toLocaleString('id-ID'),
                        }) || {},
                    },
                };
            }

            return {
                type: 'line',
                data: {
                    labels: chartData.labels || [],
                    datasets: [
                        {
                            label: 'Price/Kg (Original)',
                            data: chartData.prices || [],
                            borderColor: primaryColor,
                            backgroundColor: (ctx) => window.AdasiChart?.createAreaGradient(ctx, primaryColor, 0.16, 0.01) || 'transparent',
                            borderWidth: 2.5,
                            fill: true,
                            tension: 0.3,
                            pointRadius: 3.5,
                            pointHoverRadius: 6,
                            pointBackgroundColor: primaryColor,
                            pointBorderColor: colors.surface,
                            pointBorderWidth: 2,
                            yAxisID: 'y',
                        },
                        {
                            label: 'Price/Kg (IDR)',
                            data: chartData.pricesIdr || [],
                            borderColor: errorColor,
                            backgroundColor: 'transparent',
                            borderWidth: 2.5,
                            fill: false,
                            tension: 0.3,
                            pointRadius: 3.5,
                            pointHoverRadius: 6,
                            pointBackgroundColor: errorColor,
                            pointBorderColor: colors.surface,
                            pointBorderWidth: 2,
                            yAxisID: 'y1',
                        },
                    ],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: { duration: 400 },
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                boxWidth: 8,
                                boxHeight: 8,
                                usePointStyle: true,
                                font: { family: 'Inter', size: 11, weight: '500' },
                                color: colors.onSurfaceVariant,
                                padding: 12,
                            }
                        },
                        tooltip: window.AdasiChart?.getTooltip({
                            callbacks: {
                                label: (ctx) => {
                                    const unit = ctx.datasetIndex === 0 ? '' : 'Rp ';
                                    return ' ' + ctx.dataset.label + ': ' + unit + Number(ctx.parsed.y).toLocaleString('id-ID');
                                }
                            }
                        }) || {},
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            border: { display: false },
                            ticks: { font: { family: 'Inter', size: 11, weight: '500' }, color: colors.onSurfaceVariant },
                        },
                        y: {
                            type: 'linear',
                            position: 'left',
                            beginAtZero: true,
                            title: { display: true, text: 'Original Currency', font: { family: 'Inter', size: 11, weight: '600' }, color: colors.onSurfaceVariant },
                            grid: { color: colors.gridLine, drawBorder: false, borderDash: [3, 3] },
                            border: { display: false },
                            ticks: { maxTicksLimit: 5, font: { family: 'Inter', size: 11, weight: '500' }, color: colors.onSurfaceVariant },
                        },
                        y1: {
                            type: 'linear',
                            position: 'right',
                            beginAtZero: true,
                            title: { display: true, text: 'IDR', font: { family: 'Inter', size: 11, weight: '600' }, color: colors.onSurfaceVariant },
                            grid: { drawOnChartArea: false, drawBorder: false },
                            border: { display: false },
                            ticks: {
                                maxTicksLimit: 5,
                                font: { family: 'Inter', size: 11, weight: '500' },
                                color: colors.onSurfaceVariant,
                                callback: (value) => 'Rp ' + Number(value).toLocaleString('id-ID'),
                            },
                        },
                    },
                },
            };
        }

        function renderHistorycalChart(payload) {
            if (window._activeComparisonChart) {
                window._activeComparisonChart.destroy();
                window._activeComparisonChart = null;
            }

            const canvas = document.getElementById('historicalChart');
            if (!canvas) return;

            window._activeComparisonChart = new Chart(canvas, historicalChartConfig(payload));
        }

        function updateSummaryClass(element, value) {
            if (!element) return;
            element.classList.remove('tw-text-error', 'tw-text-success', 'tw-text-on-surface-variant');
            element.classList.add(value > 0 ? 'tw-text-error' : (value < 0 ? 'tw-text-success' : 'tw-text-on-surface-variant'));
        }

        function renderSummary(summary) {
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
                        <th scope="col">Year</th>
                        <th scope="col">Average IDR/Kg</th>
                        <th scope="col">Lowest Price</th>
                        <th scope="col">Highest Price</th>
                        <th scope="col">Change from Previous Period</th>
                    </tr>
                `;
                body.innerHTML = rows.map((row) => `
                    <tr>
                        <td class="text-center fw-medium">${escapeOptionText(row.period)}</td>
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
                    <th scope="col">PR Number</th>
                    <th scope="col">Supplier</th>
                    <th scope="col">Price/Kg</th>
                    <th scope="col">Total Price IDR</th>
                    <th scope="col">PO Date</th>
                    <th scope="col">Change</th>
                </tr>
            `;
            body.innerHTML = rows.map((row) => `
                <tr>
                    <td class="text-center fw-medium">
                        ${row.pr_url
                            ? `<a href="${escapeOptionText(row.pr_url)}" class="text-primary text-decoration-none hover:tw-underline">${escapeOptionText(row.pr_number || '-')} →</a>`
                            : escapeOptionText(row.pr_number || '-')}
                    </td>
                    <td class="text-center">${escapeOptionText(row.supplier || '-')}</td>
                    <td class="text-end ui-tabular-nums">${formatMaxDecimals(row.price_per_kg, 4)} <span class="ui-status-chip ui-status-chip--neutral tw-ms-1">${escapeOptionText(row.currency)}</span></td>
                    <td class="text-end text-primary fw-bold ui-tabular-nums">${formatRupiah(row.total_idr)}</td>
                    <td class="text-center">${row.purchase_order_at_display ? escapeOptionText(row.purchase_order_at_display) : '<span class="ui-status-chip ui-status-chip--neutral">Draft</span>'}</td>
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
            summary.textContent = `Showing ${pagination.from || 0}-${pagination.to || 0} of ${pagination.total} records`;
            pageLabel.textContent = `Page ${currentPage} of ${lastPage}`;
            previous.disabled = currentPage <= 1;
            previous.dataset.historyPage = String(Math.max(1, currentPage - 1));
            next.disabled = currentPage >= lastPage;
            next.dataset.historyPage = String(Math.min(lastPage, currentPage + 1));
            container.classList.remove('d-none');
        }

        function historicalResultShellHtml() {
            return `
                <section class="tw-overflow-hidden tw-rounded-ui-md tw-border tw-border-outline tw-bg-surface" aria-labelledby="historicalChartTitle">
                    <header class="tw-border-b tw-border-outline-variant tw-bg-surface-container tw-px-4 tw-py-3">
                        <h2 class="tw-m-0 tw-flex tw-items-center tw-gap-2 tw-text-ui-sm tw-font-semibold tw-text-on-surface" id="historicalChartTitle"></h2>
                    </header>
                    <div class="tw-h-72 tw-p-4">
                        <canvas id="historicalChart" role="img" aria-label="Historical material price trend">Historical material price trend chart.</canvas>
                    </div>
                </section>

                <section class="tw-grid tw-overflow-hidden tw-rounded-ui-md tw-border tw-border-outline tw-bg-surface-container sm:tw-grid-cols-2 sm:tw-divide-x sm:tw-divide-outline-variant" id="historicalSummary" aria-label="Historical price summary">
                    <div class="tw-p-4">
                        <div class="tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wider tw-text-on-surface-variant">Average Change per Period</div>
                        <div class="ui-tabular-nums tw-mt-1 tw-text-ui-2xl tw-font-semibold tw-text-on-surface-variant" id="averageChangeValue">-</div>
                    </div>
                    <div class="tw-border-t tw-border-outline-variant tw-p-4 sm:tw-border-t-0">
                        <div class="tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wider tw-text-on-surface-variant">Total Change, Initial to Latest</div>
                        <div class="ui-tabular-nums tw-mt-1 tw-text-ui-2xl tw-font-semibold tw-text-on-surface-variant" id="totalChangeValue">-</div>
                    </div>
                </section>

                <section class="ui-data-table tw-overflow-hidden tw-rounded-ui-md tw-border tw-border-outline tw-bg-surface tw-shadow-none">
                    <header class="tw-border-b tw-border-outline-variant tw-bg-surface-container tw-px-4 tw-py-3">
                        <h2 class="tw-m-0 tw-text-sm tw-font-bold tw-text-on-surface">Supporting Data</h2>
                        <p class="tw-m-0 tw-mt-0.5 tw-text-ui-xs tw-text-on-surface-variant">Quotation values and period changes for the selected supplier and exact material specification.</p>
                    </header>
                    <div class="ui-data-table__scroll tw-overflow-x-auto tw-w-full">
                        <table class="table table-hover align-middle mb-0 tw-text-ui-sm">
                            <thead class="table-light text-center" id="historicalTableHead"></thead>
                            <tbody id="historicalTableBody"></tbody>
                        </table>
                    </div>
                    <div id="historicalPagination" class="d-none tw-flex tw-flex-wrap tw-items-center tw-justify-between tw-gap-3 tw-border-t tw-border-outline-variant tw-bg-surface-low tw-px-4 tw-py-3">
                        <span id="historicalPaginationSummary" class="tw-text-ui-xs tw-text-on-surface-variant"></span>
                        <div class="tw-flex tw-items-center tw-gap-2">
                            <button type="button" id="historicalPreviousPage" data-history-page="1" class="ui-button ui-motion ui-focus-ring tw-inline-flex tw-min-h-[var(--ui-control-height-sm)] tw-items-center tw-justify-center tw-rounded-ui-sm tw-border tw-border-outline tw-bg-transparent tw-px-2.5 tw-py-1 tw-text-ui-xs tw-font-semibold tw-text-on-surface disabled:tw-cursor-not-allowed disabled:tw-opacity-50">Previous</button>
                            <span id="historicalPaginationPage" class="tw-min-w-20 tw-text-center tw-text-ui-xs tw-font-semibold tw-text-on-surface"></span>
                            <button type="button" id="historicalNextPage" data-history-page="1" class="ui-button ui-motion ui-focus-ring tw-inline-flex tw-min-h-[var(--ui-control-height-sm)] tw-items-center tw-justify-center tw-rounded-ui-sm tw-border tw-border-outline tw-bg-transparent tw-px-2.5 tw-py-1 tw-text-ui-xs tw-font-semibold tw-text-on-surface disabled:tw-cursor-not-allowed disabled:tw-opacity-50">Next</button>
                        </div>
                    </div>
                </section>
            `;
        }

        function renderPayload(payload) {
            if (!payload.chartData) {
                if (window._activeComparisonChart) {
                    window._activeComparisonChart.destroy();
                    window._activeComparisonChart = null;
                }

                if (resultsContainer) {
                    resultsContainer.innerHTML = `
                        <div class="tw-rounded-ui-md tw-border tw-border-outline tw-bg-surface">
                            <div class="tw-flex tw-flex-col tw-items-center tw-justify-center tw-px-4 tw-py-8 tw-text-center">
                                <span class="tw-text-on-surface-variant" style="font-size: 2rem;">&#128200;</span>
                                <p class="tw-m-0 tw-mt-3 tw-text-ui-sm tw-text-on-surface-variant">
                                    ${escapeOptionText(payload.materialName
                                        ? 'No quotation data found for this supplier and material combination.'
                                        : 'Select a supplier and material above to view the historical price trend.')}
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
                titleEl.innerHTML = `<span class="tw-text-primary me-1">&#128200;</span><span>Price Trend: ${escapeOptionText(payload.materialName)} — ${escapeOptionText(payload.supplierName)}</span>`;
            }
        }

        window.loadHistorycalPayloadFromFilters = async function(historyPage = 1) {
            if (!supplierSelect.value || !materialSelect.value) {
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
                });

                if (!response.ok) {
                    throw new Error('Failed to load historical data');
                }

                const payload = await response.json();
                renderPayload(payload);

                url.searchParams.delete('view');
                const resolvedPage = Number(payload.pagination?.current_page || 1);
                if (resolvedPage > 1) {
                    url.searchParams.set('history_page', String(resolvedPage));
                } else {
                    url.searchParams.delete('history_page');
                }
                window.history.replaceState(null, '', url.toString());
            } catch (error) {
                if (resultsContainer) {
                    resultsContainer.innerHTML = `
                        <div class="tw-flex tw-items-start tw-gap-3 tw-rounded-ui-sm tw-border-s-4 tw-border-warning tw-bg-warning-container tw-p-4 tw-text-warning-container-foreground" role="status">
                            <div class="tw-text-ui-sm">Failed to load historical data. Try selecting filters again.</div>
                        </div>
                    `;
                }
            } finally {
                if (submitBtn && window.AdasiButton && typeof window.AdasiButton.stopLoading === 'function') {
                    window.AdasiButton.stopLoading(submitBtn);
                }
                resultsContainer?.setAttribute('aria-busy', 'false');
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

        materialSelect.addEventListener('change', () => {
            if (materialSelect.value) {
                window.loadHistorycalPayloadFromFilters();
            } else {
                clearHistorycalResults('Select a material from the selected supplier to view the historical price trend.');
            }
        });

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

        materialSelect.addEventListener('change', updateDimensionVisibility);
        updateDimensionVisibility();

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

        function formatRupiah(value) {
            if (value === null || value === undefined || value === '') return '-';
            return 'Rp ' + Number(value).toLocaleString('id-ID', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
        }

        function formatInteger(value) {
            return Number(value || 0).toLocaleString('id-ID');
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
                ajax: {
                    url: tableDataUrl,
                    data: function(d) {
                        Object.assign(d, filterParams);
                    },
                    dataSrc: function(json) {
                        updateSummary(json.summary);
                        return json.data || [];
                    }
                },
                columns: [
                    { data: 'material_display', name: 'current_pr_items.material_name', className: 'text-start' },
                    { data: 'current_price_display', name: 'current_price_idr', className: 'text-end', searchable: false },
                    { data: 'best_price_display', name: 'best_price_idr', className: 'text-end', searchable: false },
                    { data: 'diff_display', name: 'diff_idr_per_kg', className: 'text-center', searchable: false },
                    { data: 'potential_difference_display', name: 'potential_difference_idr', className: 'text-end', searchable: false },
                    { data: 'status_badge', name: 'diff_percent', className: 'text-center', searchable: false },
                    { data: 'action', name: 'action', className: 'text-center', orderable: false, searchable: false }
                ],
                pageLength: 25,
                order: [[4, 'desc']]
            });
        }

        const dateRangeEl = document.getElementById('vsBestMonthRange');
        if (dateRangeEl) {
            dateRangeEl.addEventListener('adasi:date-range-commit', (event) => {
                filterParams.date_from = event.detail.start;
                filterParams.date_to = event.detail.end;
                const form = document.getElementById('vsBestFilterForm');
                const url = new URL(form?.action || window.location.href, window.location.origin);
                if (event.detail.start) url.searchParams.set('date_from', event.detail.start);
                if (event.detail.end) url.searchParams.set('date_to', event.detail.end);
                window.history.replaceState({}, '', url);
                if (window._activeVsBestTable) {
                    window._activeVsBestTable.ajax.reload();
                }
            });
        }
    };

    // ─── 4. GLOBAL CONTAINER COORDINATION ────────────────────────────────────
    window.initCurrentComparisonTab = function() {
        if (window.AdasiCalendar && typeof window.AdasiCalendar.initialize === 'function') {
            const contentContainer = document.getElementById('purchasingComparisonContent');
            if (contentContainer) {
                window.AdasiCalendar.initialize(contentContainer);
            }
        }

        if (document.getElementById('interSupplierConfig')) {
            window.initInterSupplierComparison();
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
