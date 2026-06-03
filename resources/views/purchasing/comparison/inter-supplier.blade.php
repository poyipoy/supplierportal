@extends('layouts.app')
@section('title', 'Perbandingan Antar Supplier - ADASI Portal')
@section('page-title', 'Perbandingan Harga')

@section('content')
{{-- Tabs --}}
<ul class="nav nav-pills mb-4 gap-2">
    <li class="nav-item"><a class="nav-link active" href="{{ \App\Support\PurchasingNavigation::listUrl('purchasing.comparison.inter-supplier') }}"><i class="bi bi-people me-1"></i> Antar Supplier</a></li>
    <li class="nav-item"><a class="nav-link" href="{{ \App\Support\PurchasingNavigation::listUrl('purchasing.comparison.historical') }}"><i class="bi bi-graph-up me-1"></i> Historis</a></li>
    <li class="nav-item"><a class="nav-link" href="{{ \App\Support\PurchasingNavigation::listUrl('purchasing.comparison.vs-best') }}"><i class="bi bi-trophy me-1"></i> vs Harga Terbaik</a></li>
</ul>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-3">
        <form method="GET" action="{{ route('purchasing.comparison.inter-supplier') }}" class="row g-3 align-items-end" id="interSupplierFilterForm">
            <div class="col-md-9">
                <label class="form-label small fw-bold">Pilih Permintaan Material (PR dengan minimal 2 penawaran)</label>
                <div class="position-relative">
                    <input type="hidden" name="pr_id" id="comparisonPrId" value="{{ $selectedPrOption['id'] ?? '' }}">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                        <input type="text"
                               class="form-control"
                               id="comparisonPrSearch"
                               value="{{ $selectedPrOption['label'] ?? '' }}"
                               placeholder="Ketik nomor PR atau periode..."
                               autocomplete="off">
                        <button type="button"
                                class="btn btn-outline-secondary {{ $selectedPrOption ? '' : 'd-none' }}"
                                id="comparisonPrClear"
                                title="Hapus pilihan">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                    <div class="list-group position-absolute w-100 shadow-sm d-none"
                         id="comparisonPrSuggestions"
                         style="z-index: 1050; max-height: 260px; overflow-y: auto;"></div>
                </div>
                <div class="form-text">Ketik untuk menampilkan beberapa opsi PR, lalu pilih salah satu opsi.</div>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary btn-sm w-100" style="background-color:var(--adasi-blue)"><i class="bi bi-search me-1"></i>Bandingkan</button>
            </div>
        </form>
    </div>
</div>

@if($comparison)
    @php
        $supplierTotals = [];
        $supplierWins = [];
        foreach($comparison['suppliers'] as $sup) {
            $supplierTotals[$sup['quotation_id']] = ['name' => $sup['name'], 'total' => 0];
            $supplierWins[$sup['quotation_id']] = 0;
        }
        
        foreach($comparison['matrix'] as &$row) {
            $idrPrices = collect($row['prices'])->pluck('price_idr')->filter()->values();
            $minIdr = $idrPrices->count() > 0 ? $idrPrices->min() : null;
            $maxIdr = $idrPrices->count() > 0 ? $idrPrices->max() : null;
            
            $row['spread_pct'] = 0;
            if($minIdr && $minIdr > 0 && $maxIdr) {
                $row['spread_pct'] = (($maxIdr - $minIdr) / $minIdr) * 100;
            }

            foreach($comparison['suppliers'] as $sup) {
                $p = $row['prices'][$sup['quotation_id']] ?? null;
                if($p && $p['price_idr']) {
                    $supplierTotals[$sup['quotation_id']]['total'] += $p['price_idr'] * $row['item']->total_weight;
                    if($minIdr && $p['price_idr'] <= $minIdr) {
                        $supplierWins[$sup['quotation_id']]++;
                    }
                }
            }
        }
        unset($row);
        
        $validTotals = array_filter($supplierTotals, fn($v) => $v['total'] > 0);
        $recommendedSupId = null;
        if(count($validTotals) > 0) {
            $minTotal = min(array_column($validTotals, 'total'));
            foreach($validTotals as $qid => $data) {
                if($data['total'] == $minTotal) {
                    $recommendedSupId = $qid;
                    break;
                }
            }
        }
    @endphp

    {{-- Summary Card --}}
    @if(count($validTotals) > 0)
    <div class="row mb-4">
        @foreach($validTotals as $qid => $data)
            <div class="col-md-{{ max(3, floor(12/count($validTotals))) }}">
                <div class="card border-{{ $recommendedSupId === $qid ? 'success' : '0' }} shadow-sm h-100 {{ $recommendedSupId === $qid ? 'bg-success bg-opacity-10' : '' }}">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <h6 class="fw-bold mb-1">{{ $data['name'] }}</h6>
                            @if($recommendedSupId === $qid)
                                <span class="badge bg-success"><i class="bi bi-star-fill me-1"></i>Termurah</span>
                            @endif
                        </div>
                        <div class="text-muted small mb-2">Total Estimasi Harga</div>
                        <h5 class="fw-bold {{ $recommendedSupId === $qid ? 'text-success' : 'text-primary' }}">Rp {{ number_format($data['total'], 0, ',', '.') }}</h5>
                        <div class="small mt-2">
                            <span class="badge bg-light text-dark border">{{ $supplierWins[$qid] }} Material Termurah</span>
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
    @endif

    {{-- Grafik Batang --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3 d-flex flex-column flex-md-row gap-3 justify-content-between align-items-md-center">
            <h6 class="mb-0 fw-bold"><i class="bi bi-bar-chart me-1"></i> Grafik Perbandingan Harga per Material (IDR/Kg)</h6>
            <div style="min-width: 240px;">
                <label class="form-label small fw-bold mb-1">Material</label>
                <select class="form-select form-select-sm" id="comparisonMaterialFilter">
                    <option value="">Semua Material</option>
                    @foreach($materialOptions as $material)
                        <option value="{{ $material->id }}">{{ $material->material_name }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="card-body">
            <canvas id="comparisonChart" height="280"></canvas>
        </div>
    </div>

    {{-- Tabel Side-by-Side --}}
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3">
            <h6 class="mb-0 fw-bold"><i class="bi bi-table me-1"></i> Tabel Perbandingan - {{ $selectedPr->pr_number }}</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle mb-0" style="font-size:.8rem">
                    <thead class="table-light text-center">
                        <tr>
                            <th rowspan="2" class="align-middle">Material</th>
                            <th rowspan="2" class="align-middle">Qty</th>
                            <th rowspan="2" class="align-middle">Berat/Unit (Kg)</th>
                            <th rowspan="2" class="align-middle">Total Berat (Kg)</th>
                            @foreach($comparison['suppliers'] as $sup)
                                <th colspan="2" class="text-center">
                                    {{ $sup['name'] }}
                                    <div><span class="badge bg-{{ $sup['status'] === 'accepted' ? 'success' : ($sup['status'] === 'rejected' ? 'danger' : 'primary') }}" style="font-size:.55rem">{{ strtoupper($sup['status']) }}</span></div>
                                </th>
                            @endforeach
                        </tr>
                        <tr>
                            @foreach($comparison['suppliers'] as $sup)
                                <th class="text-center small">Harga/Kg ({{ $sup['currency'] }})</th>
                                <th class="text-center small">Harga/Kg (IDR)</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($comparison['matrix'] as $row)
                            @php
                                $idrPrices = collect($row['prices'])->pluck('price_idr')->filter()->values();
                                $minIdr = $idrPrices->count() > 0 ? $idrPrices->min() : null;
                            @endphp
                            <tr data-comparison-row data-material-id="{{ $row['item']->id }}" class="{{ ($row['spread_pct'] ?? 0) > 15 ? 'bg-warning bg-opacity-10' : '' }}">
                                <td class="fw-medium">
                                    {{ $row['item']->material_name }}
                                    @if(($row['spread_pct'] ?? 0) > 15)
                                        <div class="small text-danger mt-1" data-bs-toggle="tooltip" title="Spread harga tinggi (>15%)">
                                            <i class="bi bi-exclamation-triangle-fill me-1"></i>Spread {{ number_format($row['spread_pct'], 1) }}%
                                        </div>
                                    @endif
                                </td>
                                <td class="text-center">{{ number_format($row['item']->quantity_value, 0) }}</td>
                                <td class="text-center">{{ number_format($row['item']->weight_needed, 2) }}</td>
                                <td class="text-center fw-medium text-primary">{{ number_format($row['item']->total_weight, 2) }}</td>
                                @foreach($comparison['suppliers'] as $sup)
                                    @php $p = $row['prices'][$sup['quotation_id']] ?? null; @endphp
                                    @if($p && $p['price_per_kg'])
                                        <td class="text-end">{{ number_format($p['price_per_kg'], 2) }}</td>
                                        <td class="text-end fw-bold {{ ($p['price_idr'] && $minIdr && $p['price_idr'] <= $minIdr) ? 'text-success bg-success bg-opacity-10' : '' }}">
                                            Rp {{ number_format($p['price_idr'], 0, ',', '.') }}
                                            @if($p['price_idr'] && $minIdr && $p['price_idr'] <= $minIdr)
                                                <i class="bi bi-check-circle-fill ms-1"></i>
                                            @endif
                                            @if($p['detail_url'])
                                                <a href="{{ $p['detail_url'] }}" class="btn btn-sm btn-link p-0 ms-1" title="Lihat detail penawaran">
                                                    <i class="bi bi-box-arrow-up-right"></i>
                                                </a>
                                            @endif
                                        </td>
                                    @else
                                        <td class="text-center text-muted" colspan="2">- tidak menawar -</td>
                                    @endif
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@elseif(request('pr_id'))
    <div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1"></i> Data tidak ditemukan untuk PR yang dipilih.</div>
@else
    <div class="card border-0 shadow-sm">
        <div class="card-body text-center py-5 text-muted">
            <i class="bi bi-bar-chart-line" style="font-size:3rem;opacity:.5"></i>
            <p class="mt-3 mb-0">Pilih PR di atas untuk melihat perbandingan harga antar supplier.</p>
        </div>
    </div>
@endif
@endsection

@push('scripts')
<script>
const eligiblePrOptions = @json($eligiblePrOptions);
const comparisonFilterForm = document.getElementById('interSupplierFilterForm');
const comparisonPrId = document.getElementById('comparisonPrId');
const comparisonPrSearch = document.getElementById('comparisonPrSearch');
const comparisonPrSuggestions = document.getElementById('comparisonPrSuggestions');
const comparisonPrClear = document.getElementById('comparisonPrClear');

const normalizeComparisonKeyword = (value) => String(value || '').toLowerCase().trim();

const hideComparisonPrSuggestions = () => {
    comparisonPrSuggestions.classList.add('d-none');
};

const toggleComparisonClear = () => {
    comparisonPrClear.classList.toggle('d-none', comparisonPrSearch.value.trim() === '');
};

const selectComparisonPr = (option) => {
    comparisonPrId.value = option.id;
    comparisonPrSearch.value = option.label;
    comparisonPrSearch.classList.remove('is-invalid');
    toggleComparisonClear();
    hideComparisonPrSuggestions();
    comparisonFilterForm.submit();
};

const renderComparisonPrSuggestions = () => {
    const keyword = normalizeComparisonKeyword(comparisonPrSearch.value);
    const matches = (keyword === ''
        ? eligiblePrOptions
        : eligiblePrOptions.filter((option) => option.search.includes(keyword))
    ).slice(0, 8);

    comparisonPrSuggestions.innerHTML = '';

    if (matches.length === 0) {
        const empty = document.createElement('div');
        empty.className = 'list-group-item small text-muted';
        empty.textContent = 'Tidak ada PR yang cocok.';
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
        meta.textContent = `${option.period} - ${option.quotationCount} penawaran`;

        const preview = document.createElement('div');
        preview.className = 'text-secondary mt-1';
        preview.style.fontSize = '.7rem';
        preview.innerHTML = `<i class="bi bi-box-seam me-1"></i>${option.previewMaterials}`;

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

comparisonPrClear.addEventListener('click', () => {
    comparisonPrId.value = '';
    comparisonPrSearch.value = '';
    comparisonPrSearch.classList.remove('is-invalid');
    toggleComparisonClear();
    renderComparisonPrSuggestions();
    comparisonPrSearch.focus();
});

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
    if (!comparisonPrSuggestions.contains(event.target) && event.target !== comparisonPrSearch) {
        hideComparisonPrSuggestions();
    }
});
</script>

@if($chartData)
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const comparisonChartData = @json($chartData);
const comparisonMaterialIds = @json($chartMaterialIds);
const comparisonChart = new Chart(document.getElementById('comparisonChart'), {
    type: 'bar',
    data: JSON.parse(JSON.stringify(comparisonChartData)),
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom' } },
        scales: {
            y: { beginAtZero: true, ticks: { callback: v => 'Rp ' + v.toLocaleString('id-ID') } }
        }
    }
});

document.getElementById('comparisonMaterialFilter').addEventListener('change', function() {
    const materialId = this.value;
    document.querySelectorAll('[data-comparison-row]').forEach((row) => {
        row.classList.toggle('d-none', materialId !== '' && row.dataset.materialId !== materialId);
    });

    if (materialId === '') {
        comparisonChart.data = JSON.parse(JSON.stringify(comparisonChartData));
        comparisonChart.update();
        return;
    }

    const materialIndex = comparisonMaterialIds.indexOf(materialId);
    if (materialIndex === -1) return;

    comparisonChart.data.labels = [comparisonChartData.labels[materialIndex]];
    comparisonChart.data.datasets = comparisonChartData.datasets.map((dataset) => ({
        ...dataset,
        data: [dataset.data[materialIndex] ?? 0],
    }));
    comparisonChart.update();
});
</script>
@endif
@endpush
