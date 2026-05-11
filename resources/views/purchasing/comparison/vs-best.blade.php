@extends('layouts.app')
@section('title', 'vs Harga Terbaik — ADASI Portal')
@section('page-title', 'Perbandingan Harga')

@section('content')
{{-- Tabs --}}
<ul class="nav nav-pills mb-4 gap-2">
    <li class="nav-item"><a class="nav-link" href="{{ route('purchasing.comparison.inter-supplier') }}"><i class="bi bi-people me-1"></i> Antar Supplier</a></li>
    <li class="nav-item"><a class="nav-link" href="{{ route('purchasing.comparison.historical') }}"><i class="bi bi-graph-up me-1"></i> Historis</a></li>
    <li class="nav-item"><a class="nav-link active" href="{{ route('purchasing.comparison.vs-best') }}"><i class="bi bi-trophy me-1"></i> vs Harga Terbaik</a></li>
</ul>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-bold"><i class="bi bi-trophy me-1"></i> Harga Saat Ini vs Harga Terbaik Sepanjang Histori</h6>
        <form method="GET" action="{{ route('purchasing.comparison.vs-best') }}" class="d-flex gap-2 align-items-center">
            <label class="form-label small fw-medium mb-0 text-nowrap">Periode:</label>
            <select name="period_id" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
                @foreach($periods as $period)
                    <option value="{{ $period->id }}" {{ $selectedPeriodId == $period->id ? 'selected' : '' }}>{{ $period->name }}</option>
                @endforeach
            </select>
        </form>
    </div>
    <div class="card-body p-0">
        @if(count($data) > 0)
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size:.85rem">
                <thead class="table-light text-center">
                    <tr>
                        <th class="text-start">Material</th>
                        <th>Supplier Saat Ini</th>
                        <th>Harga/Kg (IDR) — Saat Ini</th>
                        <th>Harga/Kg (IDR) — Terbaik</th>
                        <th>Supplier Terbaik</th>
                        <th>Selisih</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($data as $row)
                    @php
                        $isAbove = $row['diff_percent'] !== null && $row['diff_percent'] > 0;
                        $isEqual = $row['diff_percent'] !== null && $row['diff_percent'] == 0;
                        $isBelow = $row['diff_percent'] !== null && $row['diff_percent'] < 0;
                    @endphp
                    <tr class="{{ $isAbove ? 'table-danger' : '' }}">
                        <td class="fw-medium">{{ $row['material_name'] }}</td>
                        <td class="text-center">{{ $row['supplier'] }}</td>
                        <td class="text-end fw-bold">
                            {{ $row['current_price_idr'] ? 'Rp ' . number_format($row['current_price_idr'], 0, ',', '.') : '-' }}
                            <div class="text-muted" style="font-size:.65rem">{{ number_format($row['current_price'], 2) }} {{ $row['current_currency'] }}</div>
                        </td>
                        <td class="text-end fw-bold text-success">
                            {{ $row['best_price_idr'] ? 'Rp ' . number_format($row['best_price_idr'], 0, ',', '.') : '-' }}
                            <div class="text-muted" style="font-size:.65rem">{{ number_format($row['best_price'], 2) }} {{ $row['best_currency'] }}</div>
                        </td>
                        <td class="text-center">{{ $row['best_supplier'] }}</td>
                        <td class="text-center fw-bold {{ $isAbove ? 'text-danger' : ($isBelow ? 'text-success' : 'text-muted') }}">
                            @if($row['diff_percent'] !== null)
                                @if($isAbove)
                                    <i class="bi bi-arrow-up-circle-fill me-1"></i>+{{ $row['diff_percent'] }}%
                                @elseif($isBelow)
                                    <i class="bi bi-arrow-down-circle-fill me-1"></i>{{ $row['diff_percent'] }}%
                                @else
                                    <i class="bi bi-dash-circle me-1"></i>0%
                                @endif
                            @else
                                -
                            @endif
                        </td>
                        <td class="text-center">
                            @if($isAbove)
                                <span class="badge bg-danger"><i class="bi bi-exclamation-triangle me-1"></i>Lebih Mahal</span>
                            @elseif($isEqual)
                                <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Harga Terbaik</span>
                            @elseif($isBelow)
                                <span class="badge bg-info"><i class="bi bi-star me-1"></i>Lebih Murah</span>
                            @else
                                <span class="badge bg-secondary">N/A</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @else
            <div class="text-center py-5 text-muted">
                <i class="bi bi-trophy" style="font-size:3rem;opacity:.5"></i>
                <p class="mt-3 mb-0">Tidak ada data penawaran untuk periode yang dipilih.</p>
            </div>
        @endif
    </div>
</div>

@if(count($data) > 0)
<div class="row g-4 mt-2">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm bg-success bg-opacity-10">
            <div class="card-body text-center">
                <h3 class="fw-bold text-success">{{ collect($data)->where('diff_percent', '<=', 0)->count() }}</h3>
                <div class="text-muted small">Harga Kompetitif / Terbaik</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm bg-danger bg-opacity-10">
            <div class="card-body text-center">
                <h3 class="fw-bold text-danger">{{ collect($data)->where('diff_percent', '>', 0)->count() }}</h3>
                <div class="text-muted small">Lebih Mahal dari Terbaik</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm bg-light">
            <div class="card-body text-center">
                @php
                    $avgDiff = collect($data)->pluck('diff_percent')->filter()->avg();
                @endphp
                <h3 class="fw-bold {{ $avgDiff > 0 ? 'text-danger' : 'text-success' }}">{{ $avgDiff !== null ? number_format($avgDiff, 1) . '%' : '-' }}</h3>
                <div class="text-muted small">Rata-Rata Selisih</div>
            </div>
        </div>
    </div>
</div>
@endif
@endsection
