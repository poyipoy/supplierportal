@extends('layouts.app')
@section('title', 'Perbandingan Antar Supplier — ADASI Portal')
@section('page-title', 'Perbandingan Harga')

@section('content')
{{-- Tabs --}}
<ul class="nav nav-pills mb-4 gap-2">
    <li class="nav-item"><a class="nav-link active" href="{{ route('purchasing.comparison.inter-supplier') }}"><i class="bi bi-people me-1"></i> Antar Supplier</a></li>
    <li class="nav-item"><a class="nav-link" href="{{ route('purchasing.comparison.historical') }}"><i class="bi bi-graph-up me-1"></i> Historis</a></li>
    <li class="nav-item"><a class="nav-link" href="{{ route('purchasing.comparison.vs-best') }}"><i class="bi bi-trophy me-1"></i> vs Harga Terbaik</a></li>
</ul>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-3">
        <form method="GET" action="{{ route('purchasing.comparison.inter-supplier') }}" class="row g-3 align-items-end">
            <div class="col-md-8">
                <label class="form-label small fw-bold">Pilih Permintaan Material (PR dengan ≥2 penawaran)</label>
                <select name="pr_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">— Pilih PR —</option>
                    @foreach($eligiblePrs as $pr)
                        <option value="{{ $pr->id }}" {{ request('pr_id') == $pr->id ? 'selected' : '' }}>
                            {{ $pr->pr_number ?? 'DRAFT' }} — {{ $pr->period->name ?? '-' }} ({{ $pr->quotations->count() }} penawaran)
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-primary btn-sm w-100" style="background-color:var(--adasi-blue)"><i class="bi bi-search me-1"></i>Bandingkan</button>
            </div>
        </form>
    </div>
</div>

@if($comparison)
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
            <h6 class="mb-0 fw-bold"><i class="bi bi-table me-1"></i> Tabel Perbandingan — {{ $selectedPr->pr_number }}</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle mb-0" style="font-size:.8rem">
                    <thead class="table-light text-center">
                        <tr>
                            <th rowspan="2" class="align-middle">Material</th>
                            <th rowspan="2" class="align-middle">Berat (Kg)</th>
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
                                // Find min IDR price for highlighting
                                $idrPrices = collect($row['prices'])->pluck('price_idr')->filter()->values();
                                $minIdr = $idrPrices->count() > 0 ? $idrPrices->min() : null;
                            @endphp
                            <tr data-comparison-row data-material-id="{{ $row['item']->id }}">
                                <td class="fw-medium">{{ $row['item']->material_name }}</td>
                                <td class="text-center">{{ number_format($row['item']->weight_needed, 2) }}</td>
                                @foreach($comparison['suppliers'] as $sup)
                                    @php $p = $row['prices'][$sup['quotation_id']] ?? null; @endphp
                                    @if($p && $p['price_per_kg'])
                                        <td class="text-end">{{ number_format($p['price_per_kg'], 2) }}</td>
                                        <td class="text-end fw-bold {{ ($p['price_idr'] && $minIdr && $p['price_idr'] <= $minIdr) ? 'text-success bg-success bg-opacity-10' : '' }}">
                                            Rp {{ number_format($p['price_idr'], 0, ',', '.') }}
                                            @if($p['price_idr'] && $minIdr && $p['price_idr'] <= $minIdr)
                                                <i class="bi bi-check-circle-fill ms-1"></i>
                                            @endif
                                        </td>
                                    @else
                                        <td class="text-center text-muted" colspan="2">— tidak menawar —</td>
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
@if($chartData)
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const comparisonChartData = {!! json_encode($chartData) !!};
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
