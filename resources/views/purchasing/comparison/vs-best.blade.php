@extends('layouts.app')
@section('title', 'vs Harga Terbaik - ADASI Portal')
@section('page-title', 'Perbandingan Harga')

@php
    $formatRupiah = fn ($value) => $value !== null ? 'Rp ' . number_format($value, 0, ',', '.') : '-';
    $formatNumber = fn ($value, $decimals = 1) => $value !== null ? number_format($value, $decimals, ',', '.') : '-';
@endphp

@section('content')
<ul class="nav nav-pills mb-4 gap-2">
    <li class="nav-item"><a class="nav-link" href="{{ \App\Support\PurchasingNavigation::listUrl('purchasing.comparison.inter-supplier') }}"><i class="bi bi-people me-1"></i> Antar Supplier</a></li>
    <li class="nav-item"><a class="nav-link" href="{{ \App\Support\PurchasingNavigation::listUrl('purchasing.comparison.historical') }}"><i class="bi bi-graph-up me-1"></i> Historis</a></li>
    <li class="nav-item"><a class="nav-link active" href="{{ \App\Support\PurchasingNavigation::listUrl('purchasing.comparison.vs-best') }}"><i class="bi bi-trophy me-1"></i> vs Harga Terbaik</a></li>
</ul>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-3">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
            <div>
                <h6 class="mb-1 fw-bold"><i class="bi bi-trophy me-1"></i> Harga Saat Ini vs Harga Terbaik Histori</h6>
                <div class="text-muted small">Data tabel diproses server-side. Pembanding memakai harga IDR/kg setelah konversi kurs. Status kompetitif aman jika selisih maksimal {{ $formatNumber($competitiveThreshold) }}%.</div>
            </div>
            <form method="GET" action="{{ route('purchasing.comparison.vs-best') }}" class="d-flex gap-2 align-items-center">
                <label class="form-label small fw-medium mb-0 text-nowrap">Periode:</label>
                <select name="period_id" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
                    @foreach($periods as $period)
                        <option value="{{ $period->id }}" {{ $selectedPeriodId == $period->id ? 'selected' : '' }}>{{ $period->name }}</option>
                    @endforeach
                </select>
            </form>
        </div>
    </div>

    <div class="card-body border-bottom">
        <div class="row g-3">
            <div class="col-md-3">
                <div class="p-3 rounded bg-light h-100">
                    <div class="text-muted small">Total Data Dibandingkan</div>
                    <div class="fs-4 fw-bold text-dark" id="vsBestTotalRows">{{ $summary['total_rows'] }}</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="p-3 rounded bg-success bg-opacity-10 h-100">
                    <div class="text-muted small">Kompetitif / Aman</div>
                    <div class="fs-4 fw-bold text-success" id="vsBestCompetitiveCount">{{ $summary['competitive_count'] }}</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="p-3 rounded bg-warning bg-opacity-10 h-100">
                    <div class="text-muted small">Di Atas Histori</div>
                    <div class="fs-4 fw-bold text-warning" id="vsBestAboveCount">{{ $summary['above_count'] }}</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="p-3 rounded bg-primary bg-opacity-10 h-100">
                    <div class="text-muted small">Potensi Selisih Total</div>
                    <div class="fs-4 fw-bold text-primary" id="vsBestPotentialTotal">{{ $formatRupiah($summary['total_potential_difference_idr']) }}</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="vsBestTable" style="font-size:.85rem; width:100%;">
                <thead class="table-light text-center">
                    <tr>
                        <th class="text-start">Material</th>
                        <th>Harga Saat Ini</th>
                        <th>Harga Terbaik Histori</th>
                        <th>Selisih IDR/kg</th>
                        <th>Potensi Selisih Total</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
$(document).ready(function() {
    const summaryFallback = @json($summary);

    function formatRupiah(value) {
        if (value === null || value === undefined || value === '') return '-';
        return 'Rp ' + Number(value).toLocaleString('id-ID', { maximumFractionDigits: 0 });
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

    $('#vsBestTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: '{{ route("purchasing.comparison.vs-best.data") }}',
            data: function(d) {
                d.period_id = '{{ $selectedPeriodId }}';
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
        language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/id.json' },
        pageLength: 25,
        order: [[4, 'desc']]
    });
});
</script>
@endpush
