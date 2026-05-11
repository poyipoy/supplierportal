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

<ul class="nav nav-pills mb-4 gap-2">
    <li class="nav-item"><a class="nav-link" href="{{ route('purchasing.comparison.inter-supplier') }}"><i class="bi bi-people me-1"></i> Antar Supplier</a></li>
    <li class="nav-item"><a class="nav-link active" href="{{ route('purchasing.comparison.historical') }}"><i class="bi bi-graph-up me-1"></i> Historis</a></li>
    <li class="nav-item"><a class="nav-link" href="{{ route('purchasing.comparison.vs-best') }}"><i class="bi bi-trophy me-1"></i> vs Harga Terbaik</a></li>
</ul>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-3">
        <form method="GET" action="{{ route('purchasing.comparison.historical') }}" class="row g-3 align-items-end" id="historicalFilterForm">
            <div class="col-md-3">
                <label class="form-label small fw-bold">Supplier</label>
                <select name="supplier_id" class="form-select form-select-sm" id="historicalSupplierSelect" required onchange="this.form.submit()">
                    <option value="">Pilih Supplier</option>
                    @foreach($suppliers as $supplier)
                        <option value="{{ $supplier->id }}" {{ (string) $selectedSupplierId === (string) $supplier->id ? 'selected' : '' }}>{{ $supplier->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-bold">Material</label>
                <select name="material_name" class="form-select form-select-sm" id="historicalMaterialSelect" required>
                    <option value="">Pilih Material</option>
                    @foreach($materials as $material)
                        <option value="{{ $material }}" {{ $selectedMaterialName === $material ? 'selected' : '' }}>{{ $material }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-bold">Periode Waktu</label>
                <select name="range" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="6m" {{ $range === '6m' ? 'selected' : '' }}>6 Bulan</option>
                    <option value="1y" {{ $range === '1y' ? 'selected' : '' }}>1 Tahun</option>
                    <option value="2y" {{ $range === '2y' ? 'selected' : '' }}>2 Tahun</option>
                    <option value="all" {{ $range === 'all' ? 'selected' : '' }}>Semua</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-bold">Tampilan</label>
                <div class="btn-group btn-group-sm w-100" role="group">
                    <input type="radio" class="btn-check" name="period_view" id="periodViewMonthly" value="monthly" {{ $periodView === 'monthly' ? 'checked' : '' }} onchange="this.form.submit()">
                    <label class="btn btn-outline-primary" for="periodViewMonthly">Per Bulan</label>

                    <input type="radio" class="btn-check" name="period_view" id="periodViewYearly" value="yearly" {{ $periodView === 'yearly' ? 'checked' : '' }} onchange="this.form.submit()">
                    <label class="btn btn-outline-primary" for="periodViewYearly">Per Tahun</label>
                </div>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary btn-sm w-100" style="background-color:var(--adasi-blue)">
                    <i class="bi bi-search me-1"></i>Tampilkan
                </button>
            </div>
        </form>
    </div>
</div>

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
                                <th>Periode</th>
                                <th>Harga/Kg</th>
                                <th>Mata Uang</th>
                                <th>Harga/Kg (IDR)</th>
                                <th>Perubahan dari Periode Sebelumnya</th>
                                <th>Tanggal Kirim</th>
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
                                    <td class="text-center fw-medium">{{ $row['period'] }}</td>
                                    <td class="text-end">{{ number_format($row['price_per_kg'], 2, ',', '.') }}</td>
                                    <td class="text-center"><span class="badge bg-dark">{{ $row['currency'] }}</span></td>
                                    <td class="text-end text-primary fw-bold">{{ $row['price_idr'] ? 'Rp ' . number_format($row['price_idr'], 0, ',', '.') : '-' }}</td>
                                    <td class="text-center">{!! $changeBadge($row['change_pct'] ?? null) !!}</td>
                                    <td class="text-center">{{ $row['submitted_at'] ?? '-' }}</td>
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
@endsection

@push('scripts')
@if($chartData)
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const initialHistoricalPayload = @json($payload);
const historicalForm = document.getElementById('historicalFilterForm');
const historicalMaterialSelect = document.getElementById('historicalMaterialSelect');
let historicalChart = null;

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
            <th>Periode</th>
            <th>Harga/Kg</th>
            <th>Mata Uang</th>
            <th>Harga/Kg (IDR)</th>
            <th>Perubahan dari Periode Sebelumnya</th>
            <th>Tanggal Kirim</th>
        </tr>
    `;
    body.innerHTML = rows.map((row) => `
        <tr>
            <td class="text-center fw-medium">${escapeHtml(row.period)}</td>
            <td class="text-end">${formatNumber(row.price_per_kg)}</td>
            <td class="text-center"><span class="badge bg-dark">${escapeHtml(row.currency)}</span></td>
            <td class="text-end text-primary fw-bold">${formatRupiah(row.price_idr)}</td>
            <td class="text-center">${changeHtml(row.change_pct)}</td>
            <td class="text-center">${escapeHtml(row.submitted_at || '-')}</td>
        </tr>
    `).join('');
}

function renderPayload(payload) {
    renderHistoricalChart(payload);
    renderSummary(payload.summary || {});
    renderTable(payload);
    document.getElementById('historicalChartTitle').innerHTML =
        `<i class="bi bi-graph-up me-1"></i> Tren Harga "${escapeHtml(payload.materialName)}" - ${escapeHtml(payload.supplierName)}`;
}

renderHistoricalChart(initialHistoricalPayload);

historicalMaterialSelect.addEventListener('change', function() {
    if (!this.value || !historicalChart) {
        historicalForm.submit();
        return;
    }

    const params = new URLSearchParams(new FormData(historicalForm));
    params.set('view', 'json');

    fetch(historicalForm.action + '?' + params.toString(), {
        headers: { 'Accept': 'application/json' },
    })
        .then((response) => response.json())
        .then((payload) => {
            if (!payload.chartData || !payload.tableData || payload.tableData.length === 0) {
                historicalForm.submit();
                return;
            }

            renderPayload(payload);
            params.delete('view');
            history.replaceState(null, '', historicalForm.action + '?' + params.toString());
        });
});
</script>
@endif
@endpush
