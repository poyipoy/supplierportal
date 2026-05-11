@extends('layouts.app')
@section('title', 'Harga Historis — ADASI Portal')
@section('page-title', 'Perbandingan Harga')

@section('content')
{{-- Tabs --}}
<ul class="nav nav-pills mb-4 gap-2">
    <li class="nav-item"><a class="nav-link" href="{{ route('purchasing.comparison.inter-supplier') }}"><i class="bi bi-people me-1"></i> Antar Supplier</a></li>
    <li class="nav-item"><a class="nav-link active" href="{{ route('purchasing.comparison.historical') }}"><i class="bi bi-graph-up me-1"></i> Historis</a></li>
    <li class="nav-item"><a class="nav-link" href="{{ route('purchasing.comparison.vs-best') }}"><i class="bi bi-trophy me-1"></i> vs Harga Terbaik</a></li>
</ul>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-3">
        <form method="GET" action="{{ route('purchasing.comparison.historical') }}" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label small fw-bold">Supplier</label>
                <select name="supplier_id" class="form-select form-select-sm" required>
                    <option value="">— Pilih Supplier —</option>
                    @foreach($suppliers as $sup)
                        <option value="{{ $sup->id }}" {{ request('supplier_id') == $sup->id ? 'selected' : '' }}>{{ $sup->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-5">
                <label class="form-label small fw-bold">Material</label>
                <select name="material_name" class="form-select form-select-sm" required>
                    <option value="">— Pilih Material —</option>
                    @foreach($materials as $mat)
                        <option value="{{ $mat }}" {{ request('material_name') == $mat ? 'selected' : '' }}>{{ $mat }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary btn-sm w-100" style="background-color:var(--adasi-blue)"><i class="bi bi-search me-1"></i>Tampilkan</button>
            </div>
        </form>
    </div>
</div>

@if($chartData)
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3">
            <h6 class="mb-0 fw-bold"><i class="bi bi-graph-up me-1"></i> Tren Harga "{{ request('material_name') }}" — {{ $suppliers->find(request('supplier_id'))->name ?? '' }}</h6>
        </div>
        <div class="card-body">
            <canvas id="historicalChart" height="300"></canvas>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3"><h6 class="mb-0 fw-bold">Data Pendukung</h6></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" style="font-size:.85rem">
                    <thead class="table-light text-center">
                        <tr><th>Periode</th><th>Harga/Kg</th><th>Mata Uang</th><th>Harga/Kg (IDR)</th><th>Tanggal Kirim</th></tr>
                    </thead>
                    <tbody>
                        @foreach($tableData as $row)
                        <tr>
                            <td class="text-center fw-medium">{{ $row['period'] }}</td>
                            <td class="text-end">{{ number_format($row['price_per_kg'], 2) }}</td>
                            <td class="text-center"><span class="badge bg-dark">{{ $row['currency'] }}</span></td>
                            <td class="text-end text-primary fw-bold">{{ $row['price_idr'] ? 'Rp ' . number_format($row['price_idr'], 0, ',', '.') : '-' }}</td>
                            <td class="text-center">{{ $row['submitted_at'] ?? '-' }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@elseif(request('supplier_id') && request('material_name'))
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
new Chart(document.getElementById('historicalChart'), {
    type: 'line',
    data: {
        labels: {!! json_encode($chartData['labels']) !!},
        datasets: [
            {
                label: 'Harga/Kg (Original)',
                data: {!! json_encode($chartData['prices']) !!},
                borderColor: '#1F5FA6',
                backgroundColor: 'rgba(31,95,166,0.1)',
                fill: true,
                tension: 0.3,
                pointRadius: 6,
                pointBackgroundColor: '#1F5FA6',
                yAxisID: 'y'
            },
            {
                label: 'Harga/Kg (IDR)',
                data: {!! json_encode($chartData['pricesIdr']) !!},
                borderColor: '#C0392B',
                backgroundColor: 'rgba(192,57,43,0.1)',
                fill: true,
                tension: 0.3,
                pointRadius: 6,
                pointBackgroundColor: '#C0392B',
                yAxisID: 'y1'
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: { legend: { position: 'bottom' } },
        scales: {
            y: { type: 'linear', position: 'left', title: { display: true, text: 'Original Currency' } },
            y1: { type: 'linear', position: 'right', title: { display: true, text: 'IDR' }, grid: { drawOnChartArea: false }, ticks: { callback: v => 'Rp ' + v.toLocaleString('id-ID') } }
        }
    }
});
</script>
@endif
@endpush
