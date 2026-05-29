@extends('layouts.app')
@section('title', 'Harga Historis - ADASI Portal')
@section('page-title', 'Perbandingan Harga')

@section('content')
@php
    $formatPct = function ($value) {
        if ($value === null) return '-';

        return ($value > 0 ? '+' : '') . number_format($value, 2, ',', '.') . '%';
    };

    $changeBadge = function ($value) {
        if ($value === null) {
            return '<span class="text-muted">-</span>';
        }

        if ($value > 0) {
            return '<span class="text-danger fw-bold">▲ ' . number_format($value, 2, ',', '.') . '%</span>';
        }

        if ($value < 0) {
            return '<span class="text-success fw-bold">▼ ' . number_format(abs($value), 2, ',', '.') . '%</span>';
        }

        return '<span class="text-muted fw-bold">— 0%</span>';
    };
@endphp

@push('styles')
<style>
    .hover-underline:hover {
        text-decoration: underline !important;
    }
</style>
@endpush

<ul class="nav nav-pills mb-4 gap-2">
    <li class="nav-item"><a class="nav-link" href="{{ \App\Support\PurchasingNavigation::listUrl('purchasing.comparison.inter-supplier') }}"><i class="bi bi-people me-1"></i> Antar Supplier</a></li>
    <li class="nav-item"><a class="nav-link active" href="{{ \App\Support\PurchasingNavigation::listUrl('purchasing.comparison.historical') }}"><i class="bi bi-graph-up me-1"></i> Historis</a></li>
    <li class="nav-item"><a class="nav-link" href="{{ \App\Support\PurchasingNavigation::listUrl('purchasing.comparison.vs-best') }}"><i class="bi bi-trophy me-1"></i> vs Harga Terbaik</a></li>
</ul>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-3">
        <form method="GET" action="{{ route('purchasing.comparison.historical') }}" class="row g-3 align-items-end" id="historicalFilterForm">
            <div class="col-md-4">
                <label class="form-label small fw-bold">Supplier</label>
                <select name="supplier_id" class="form-select form-select-sm" id="historicalSupplierSelect" required>
                    <option value="">Pilih Supplier</option>
                    @foreach($suppliers as $supplier)
                        <option value="{{ $supplier->id }}" {{ (string) $selectedSupplierId === (string) $supplier->id ? 'selected' : '' }}>{{ $supplier->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-bold">Material</label>
                <select name="material_name" class="form-select form-select-sm" id="historicalMaterialSelect" required {{ $selectedSupplierId ? '' : 'disabled' }}>
                    <option value="">{{ $selectedSupplierId ? 'Pilih Material' : 'Pilih Supplier terlebih dahulu' }}</option>
                    @foreach($materials as $material)
                        <option value="{{ $material }}" {{ $selectedMaterialName === $material ? 'selected' : '' }}>{{ $material }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-bold">Periode Waktu</label>
                <select name="range" class="form-select form-select-sm" id="historicalRangeSelect">
                    @foreach($rangeOptions as $value => $label)
                        <option value="{{ $value }}" {{ $range === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-bold">Tampilan</label>
                <div class="btn-group btn-group-sm w-100" role="group">
                    <input type="radio" class="btn-check" name="period_view" id="periodViewMonthly" value="monthly" {{ $periodView === 'monthly' ? 'checked' : '' }}>
                    <label class="btn btn-outline-primary" for="periodViewMonthly">Per Bulan</label>

                    <input type="radio" class="btn-check" name="period_view" id="periodViewYearly" value="yearly" {{ $periodView === 'yearly' ? 'checked' : '' }}>
                    <label class="btn btn-outline-primary" for="periodViewYearly">Per Tahun</label>
                </div>
            </div>
        </form>
    </div>
</div>

<div id="historicalResults">
@if($chartData)
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3">
            <h6 class="mb-0 fw-bold" id="historicalChartTitle">
                <i class="bi bi-graph-up me-1"></i> Tren Harga "{{ $selectedMaterialName }}" - {{ $suppliers->firstWhere('id', (int) $selectedSupplierId)->name ?? '' }}
            </h6>
        </div>
        <div class="card-body">
            <canvas id="historicalChart" height="300"></canvas>
        </div>
    </div>

    <div class="row g-3 mb-4" id="historicalSummary">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Rata-rata kenaikan per periode</div>
                    <div class="fs-4 fw-bold {{ ($summary['average_change_pct'] ?? null) > 0 ? 'text-danger' : ((($summary['average_change_pct'] ?? null) < 0) ? 'text-success' : 'text-muted') }}" id="averageChangeValue">
                        {{ $formatPct($summary['average_change_pct'] ?? null) }}
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Total perubahan (awal → terbaru)</div>
                    <div class="fs-4 fw-bold {{ ($summary['total_change_pct'] ?? null) > 0 ? 'text-danger' : ((($summary['total_change_pct'] ?? null) < 0) ? 'text-success' : 'text-muted') }}" id="totalChangeValue">
                        {{ $formatPct($summary['total_change_pct'] ?? null) }}
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3"><h6 class="mb-0 fw-bold">Data Pendukung</h6></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" style="font-size:.85rem">
                    <thead class="table-light text-center" id="historicalTableHead">
                        @if($periodView === 'yearly')
                            <tr>
                                <th>Tahun</th>
                                <th>Rata-rata IDR/Kg</th>
                                <th>Harga Terendah</th>
                                <th>Harga Tertinggi</th>
                                <th>Perubahan dari Periode Sebelumnya</th>
                            </tr>
                        @else
                            <tr>
                                <th>No. PR</th>
                                <th>Supplier</th>
                                <th>Harga/Kg</th>
                                <th>Total Material IDR</th>
                                <th>Tgl Diajukan</th>
                                <th>% Perubahan</th>
                            </tr>
                        @endif
                    </thead>
                    <tbody id="historicalTableBody">
                        @foreach($tableData as $row)
                            @if($periodView === 'yearly')
                                <tr>
                                    <td class="text-center fw-medium">{{ $row['period'] }}</td>
                                    <td class="text-end text-primary fw-bold">Rp {{ number_format($row['price_idr'], 0, ',', '.') }}</td>
                                    <td class="text-end">Rp {{ number_format($row['min_idr'], 0, ',', '.') }}</td>
                                    <td class="text-end">Rp {{ number_format($row['max_idr'], 0, ',', '.') }}</td>
                                    <td class="text-center">{!! $changeBadge($row['change_pct'] ?? null) !!}</td>
                                </tr>
                            @else
                                <tr>
                                    <td class="text-center fw-medium">
                                        @if(!empty($row['pr_id']) && !empty($row['pr_url']))
                                            <a href="{{ $row['pr_url'] }}"
                                               class="text-primary text-decoration-none hover-underline"
                                               style="color:#1F5FA6 !important;">
                                                {{ $row['pr_number'] }}
                                                <i class="bi bi-arrow-right-short ms-1" style="font-size: 0.85rem;"></i>
                                            </a>
                                        @else
                                            {{ $row['pr_number'] ?? '-' }}
                                        @endif
                                    </td>
                                    <td class="text-center">{{ $row['supplier'] ?? '-' }}</td>
                                    <td class="text-end">
                                        {{ number_format($row['price_per_kg'], 2, ',', '.') }}
                                        <span class="badge bg-dark ms-1">{{ $row['currency'] }}</span>
                                    </td>
                                    <td class="text-end text-primary fw-bold">{{ $row['total_idr'] ? 'Rp ' . number_format($row['total_idr'], 0, ',', '.') : '-' }}</td>
                                    <td class="text-center">
                                        @if(!empty($row['submitted_at_display']))
                                            {{ $row['submitted_at_display'] }}
                                        @else
                                            <span class="badge bg-secondary">Draft</span>
                                        @endif
                                    </td>
                                    <td class="text-center">{!! $changeBadge($row['change_pct'] ?? null) !!}</td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@elseif($selectedSupplierId && $selectedMaterialName)
    <div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1"></i> Tidak ditemukan data penawaran untuk kombinasi supplier dan material ini.</div>
@else
    <div class="card border-0 shadow-sm">
        <div class="card-body text-center py-5 text-muted">
            <i class="bi bi-graph-up" style="font-size:3rem;opacity:.5"></i>
            <p class="mt-3 mb-0">Pilih supplier dan material di atas untuk melihat tren harga historis.</p>
        </div>
    </div>
@endif
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const filterForm = document.getElementById('historicalFilterForm');
    const supplierSelect = document.getElementById('historicalSupplierSelect');
    const materialSelect = document.getElementById('historicalMaterialSelect');
    const rangeSelect = document.getElementById('historicalRangeSelect');
    const resultsContainer = document.getElementById('historicalResults');
    const periodViewInputs = document.querySelectorAll('input[name="period_view"]');
    const materialsUrl = @json(route('purchasing.comparison.historical.materials'));
    const rangeOptionSets = {
        monthly: @json($monthlyRangeOptions),
        yearly: @json($yearlyRangeOptions),
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
            ? 'Pilih Supplier terlebih dahulu'
            : (materials.length > 0 ? 'Pilih Material' : 'Tidak ada material historis');

        materialSelect.innerHTML = [
            `<option value="">${escapeOptionText(placeholder)}</option>`,
            ...materials.map((material) => (
                `<option value="${escapeOptionText(material)}"${material === selectedValue ? ' selected' : ''}>${escapeOptionText(material)}</option>`
            )),
        ].join('');
        materialSelect.disabled = !supplierSelect.value || materials.length === 0;
    }

    function clearHistoricalResults(message) {
        if (!resultsContainer) return;

        resultsContainer.innerHTML = `
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center py-5 text-muted">
                    <i class="bi bi-graph-up" style="font-size:3rem;opacity:.5"></i>
                    <p class="mt-3 mb-0">${escapeOptionText(message)}</p>
                </div>
            </div>
        `;
    }

    async function loadMaterialsForSupplier() {
        const supplierId = supplierSelect.value;
        const previousMaterial = materialSelect.value;

        renderMaterialOptions([], '');

        if (!supplierId) {
            clearHistoricalResults('Pilih supplier dan material di atas untuk melihat tren harga historis.');
            return;
        }

        materialSelect.innerHTML = '<option value="">Memuat material...</option>';
        materialSelect.disabled = true;
        clearHistoricalResults('Pilih material dari supplier yang dipilih untuk melihat tren harga historis.');

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
                throw new Error('Gagal memuat material');
            }

            const data = await response.json();
            const materials = data.materials || [];
            const selectedMaterial = materials.includes(previousMaterial) ? previousMaterial : '';
            renderMaterialOptions(materials, selectedMaterial);

            if (selectedMaterial && typeof window.loadHistoricalPayloadFromFilters === 'function') {
                window.loadHistoricalPayloadFromFilters();
            } else if (selectedMaterial) {
                filterForm.submit();
            }
        } catch (error) {
            renderMaterialOptions([], '');
            clearHistoricalResults('Gagal memuat daftar material. Coba pilih supplier kembali.');
        }
    }

    periodViewInputs.forEach((input) => {
        input.addEventListener('change', () => {
            renderRangeOptions(input.value, rangeSelect.value);
            if (materialSelect.value && typeof window.loadHistoricalPayloadFromFilters === 'function') {
                window.loadHistoricalPayloadFromFilters();
            } else {
                filterForm.submit();
            }
        });
    });

    supplierSelect.addEventListener('change', loadMaterialsForSupplier);

    materialSelect.addEventListener('change', () => {
        if (materialSelect.value) {
            if (typeof window.loadHistoricalPayloadFromFilters === 'function') {
                window.loadHistoricalPayloadFromFilters();
            } else {
                filterForm.submit();
            }
        } else {
            clearHistoricalResults('Pilih material dari supplier yang dipilih untuk melihat tren harga historis.');
        }
    });

    rangeSelect.addEventListener('change', () => {
        if (materialSelect.value && typeof window.loadHistoricalPayloadFromFilters === 'function') {
            window.loadHistoricalPayloadFromFilters();
        } else {
            filterForm.submit();
        }
    });

    const activeView = document.querySelector('input[name="period_view"]:checked')?.value || 'monthly';
    renderRangeOptions(activeView, rangeSelect.value);
});
</script>
@if($chartData)
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const initialHistoricalPayload = @json($payload);
let historicalChart = null;
const historicalDataUrl = @json(route('purchasing.comparison.historical'));

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

function formatPercent(value) {
    if (value === null || value === undefined) return '-';
    return (Number(value) > 0 ? '+' : '') + Number(value).toLocaleString('id-ID', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }) + '%';
}

function changeHtml(value) {
    if (value === null || value === undefined) return '<span class="text-muted">-</span>';
    const numberValue = Number(value);

    if (numberValue > 0) {
        return `<span class="text-danger fw-bold">▲ ${formatNumber(numberValue)}%</span>`;
    }

    if (numberValue < 0) {
        return `<span class="text-success fw-bold">▼ ${formatNumber(Math.abs(numberValue))}%</span>`;
    }

    return '<span class="text-muted fw-bold">— 0%</span>';
}

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (char) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;',
    }[char]));
}

function emptyHistoricalResultHtml(message, alertClass = 'card') {
    if (alertClass === 'warning') {
        return `<div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1"></i> ${escapeHtml(message)}</div>`;
    }

    return `
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-5 text-muted">
                <i class="bi bi-graph-up" style="font-size:3rem;opacity:.5"></i>
                <p class="mt-3 mb-0">${escapeHtml(message)}</p>
            </div>
        </div>
    `;
}

function historicalResultShellHtml() {
    return `
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-bold" id="historicalChartTitle"></h6>
            </div>
            <div class="card-body">
                <canvas id="historicalChart" height="300"></canvas>
            </div>
        </div>

        <div class="row g-3 mb-4" id="historicalSummary">
            <div class="col-md-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted small">Rata-rata kenaikan per periode</div>
                        <div class="fs-4 fw-bold text-muted" id="averageChangeValue">-</div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted small">Total perubahan (awal â†’ terbaru)</div>
                        <div class="fs-4 fw-bold text-muted" id="totalChangeValue">-</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3"><h6 class="mb-0 fw-bold">Data Pendukung</h6></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size:.85rem">
                        <thead class="table-light text-center" id="historicalTableHead"></thead>
                        <tbody id="historicalTableBody"></tbody>
                    </table>
                </div>
            </div>
        </div>
    `;
}

function historicalChartConfig(payload) {
    const chartData = payload.chartData || {};

    if (payload.periodView === 'yearly') {
        return {
            type: 'line',
            data: {
                labels: chartData.labels || [],
                datasets: [{
                    label: 'Rata-rata Harga/Kg (IDR)',
                    data: chartData.pricesIdr || [],
                    borderColor: '#1F5FA6',
                    backgroundColor: 'rgba(31,95,166,0.1)',
                    fill: true,
                    tension: 0.3,
                    pointRadius: 6,
                    pointBackgroundColor: '#1F5FA6',
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            label: (context) => 'Rata-rata: ' + formatRupiah(context.parsed.y),
                            afterLabel: (context) => {
                                const index = context.dataIndex;
                                return [
                                    'Tertinggi: ' + formatRupiah(chartData.maxIdr?.[index]),
                                    'Terendah: ' + formatRupiah(chartData.minIdr?.[index]),
                                ];
                            },
                        },
                    },
                },
                scales: {
                    y: { beginAtZero: true, ticks: { callback: (value) => 'Rp ' + Number(value).toLocaleString('id-ID') } },
                },
            },
        };
    }

    return {
        type: 'line',
        data: {
            labels: chartData.labels || [],
            datasets: [
                {
                    label: 'Harga/Kg (Original)',
                    data: chartData.prices || [],
                    borderColor: '#1F5FA6',
                    backgroundColor: 'rgba(31,95,166,0.1)',
                    fill: true,
                    tension: 0.3,
                    pointRadius: 6,
                    pointBackgroundColor: '#1F5FA6',
                    yAxisID: 'y',
                },
                {
                    label: 'Harga/Kg (IDR)',
                    data: chartData.pricesIdr || [],
                    borderColor: '#C0392B',
                    backgroundColor: 'rgba(192,57,43,0.1)',
                    fill: true,
                    tension: 0.3,
                    pointRadius: 6,
                    pointBackgroundColor: '#C0392B',
                    yAxisID: 'y1',
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { position: 'bottom' } },
            scales: {
                y: { type: 'linear', position: 'left', title: { display: true, text: 'Original Currency' } },
                y1: { type: 'linear', position: 'right', title: { display: true, text: 'IDR' }, grid: { drawOnChartArea: false }, ticks: { callback: (value) => 'Rp ' + Number(value).toLocaleString('id-ID') } },
            },
        },
    };
}

function renderHistoricalChart(payload) {
    if (historicalChart) {
        historicalChart.destroy();
    }

    historicalChart = new Chart(document.getElementById('historicalChart'), historicalChartConfig(payload));
}

function updateSummaryClass(element, value) {
    element.classList.remove('text-danger', 'text-success', 'text-muted');
    element.classList.add(value > 0 ? 'text-danger' : (value < 0 ? 'text-success' : 'text-muted'));
}

function renderSummary(summary) {
    const averageChange = document.getElementById('averageChangeValue');
    const totalChange = document.getElementById('totalChangeValue');
    averageChange.textContent = formatPercent(summary.average_change_pct);
    totalChange.textContent = formatPercent(summary.total_change_pct);
    updateSummaryClass(averageChange, summary.average_change_pct);
    updateSummaryClass(totalChange, summary.total_change_pct);
}

function renderTable(payload) {
    const head = document.getElementById('historicalTableHead');
    const body = document.getElementById('historicalTableBody');
    const rows = payload.tableData || [];

    if (payload.periodView === 'yearly') {
        head.innerHTML = `
            <tr>
                <th>Tahun</th>
                <th>Rata-rata IDR/Kg</th>
                <th>Harga Terendah</th>
                <th>Harga Tertinggi</th>
                <th>Perubahan dari Periode Sebelumnya</th>
            </tr>
        `;
        body.innerHTML = rows.map((row) => `
            <tr>
                <td class="text-center fw-medium">${escapeHtml(row.period)}</td>
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
            <th>No. PR</th>
            <th>Supplier</th>
            <th>Harga/Kg</th>
            <th>Total Material IDR</th>
            <th>Tgl Diajukan</th>
            <th>% Perubahan</th>
        </tr>
    `;
    body.innerHTML = rows.map((row) => `
        <tr>
            <td class="text-center fw-medium">
                ${row.pr_url
                    ? `<a href="${escapeHtml(row.pr_url)}" class="text-primary text-decoration-none hover-underline" style="color:#1F5FA6 !important;">${escapeHtml(row.pr_number || '-')}<i class="bi bi-arrow-right-short ms-1" style="font-size: 0.85rem;"></i></a>`
                    : escapeHtml(row.pr_number || '-')}
            </td>
            <td class="text-center">${escapeHtml(row.supplier || '-')}</td>
            <td class="text-end">${formatNumber(row.price_per_kg)} <span class="badge bg-dark ms-1">${escapeHtml(row.currency)}</span></td>
            <td class="text-end text-primary fw-bold">${formatRupiah(row.total_idr)}</td>
            <td class="text-center">${row.submitted_at_display ? escapeHtml(row.submitted_at_display) : '<span class="badge bg-secondary">Draft</span>'}</td>
            <td class="text-center">${changeHtml(row.change_pct)}</td>
        </tr>
    `).join('');
}

function renderPayload(payload) {
    const resultsContainer = document.getElementById('historicalResults');

    if (!payload.chartData) {
        if (historicalChart) {
            historicalChart.destroy();
            historicalChart = null;
        }

        if (resultsContainer) {
            resultsContainer.innerHTML = emptyHistoricalResultHtml(
                payload.materialName
                    ? 'Tidak ditemukan data penawaran untuk kombinasi supplier dan material ini.'
                    : 'Pilih supplier dan material di atas untuk melihat tren harga historis.',
                payload.materialName ? 'warning' : 'card'
            );
        }
        return;
    }

    if (!document.getElementById('historicalChart') && resultsContainer) {
        resultsContainer.innerHTML = historicalResultShellHtml();
    }

    renderHistoricalChart(payload);
    renderSummary(payload.summary || {});
    renderTable(payload);
    document.getElementById('historicalChartTitle').innerHTML =
        `<i class="bi bi-graph-up me-1"></i> Tren Harga "${escapeHtml(payload.materialName)}" - ${escapeHtml(payload.supplierName)}`;
}

window.loadHistoricalPayloadFromFilters = async function () {
    const supplierSelect = document.getElementById('historicalSupplierSelect');
    const materialSelect = document.getElementById('historicalMaterialSelect');
    const rangeSelect = document.getElementById('historicalRangeSelect');
    const periodView = document.querySelector('input[name="period_view"]:checked')?.value || 'monthly';

    if (!supplierSelect?.value || !materialSelect?.value) {
        renderPayload({ chartData: null, materialName: null });
        return;
    }

    const url = new URL(historicalDataUrl, window.location.origin);
    url.searchParams.set('supplier_id', supplierSelect.value);
    url.searchParams.set('material_name', materialSelect.value);
    url.searchParams.set('range', rangeSelect.value);
    url.searchParams.set('period_view', periodView);
    url.searchParams.set('view', 'json');

    try {
        const response = await fetch(url.toString(), {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (!response.ok) {
            throw new Error('Gagal memuat data historis');
        }

        const payload = await response.json();
        renderPayload(payload);

        url.searchParams.delete('view');
        window.history.replaceState(null, '', url.toString());
    } catch (error) {
        const resultsContainer = document.getElementById('historicalResults');
        if (resultsContainer) {
            resultsContainer.innerHTML = emptyHistoricalResultHtml('Gagal memuat data historis. Coba pilih filter kembali.', 'warning');
        }
    }
};

renderHistoricalChart(initialHistoricalPayload);
</script>
@endif
@endpush
