@extends('layouts.app')
@section('title', 'QC Dashboard — ADASI Portal')
@section('page-title', __('Dashboard Quality Control'))

@section('content')
<div class="row g-4 mb-4">
    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100 border-start border-4 border-primary">
            <div class="card-body"><div class="text-muted small fw-medium mb-1">{{ __('TOTAL INSPEKSI (BLN INI)') }}</div><h3 class="fw-bold mb-0">{{ $totalInspections }}</h3></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100 border-start border-4 border-success">
            <div class="card-body"><div class="text-muted small fw-medium mb-1">{{ __('MATERIAL OK') }}</div><h3 class="fw-bold mb-0 text-success">{{ $totalOk }}</h3></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100 border-start border-4 border-danger">
            <div class="card-body"><div class="text-muted small fw-medium mb-1">{{ __('MATERIAL NG') }}</div><h3 class="fw-bold mb-0 text-danger">{{ $totalNg }}</h3></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100 border-start border-4 border-warning">
            <div class="card-body d-flex justify-content-between align-items-center">
                <div><div class="text-muted small fw-medium mb-1">{{ __('MENUNGGU INSPEKSI') }}</div><h3 class="fw-bold mb-0 text-warning">{{ $waitingInspections }}</h3></div>
                @if($firstWaitingPo)
                <a href="{{ route('qc.inspections.create', $firstWaitingPo->id) }}" class="btn btn-warning btn-sm text-dark"><i class="bi bi-play-fill"></i> {{ __('Mulai') }}</a>
                @endif
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3"><h6 class="mb-0 fw-bold">{{ __('Rasio Kualitas (Bulan Ini)') }}</h6></div>
            <div class="card-body d-flex align-items-center justify-content-center">
                @if($totalInspections > 0)
                    <div style="width:220px;height:220px;"><canvas id="qualityChart"></canvas></div>
                @else
                    <div class="text-muted text-center">{{ __('Belum ada data bulan ini.') }}</div>
                @endif
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3"><h6 class="mb-0 fw-bold">{{ __('Tren OK vs NG (3 Bulan Terakhir)') }}</h6></div>
            <div class="card-body"><canvas id="trendChart" height="200"></canvas></div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-bold">{{ __('Inspeksi Terbaru') }}</h6>
        <a href="{{ route('qc.inspections.index') }}" class="btn btn-sm btn-light">{{ __('Lihat Semua') }}</a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>{{ __('No. PO') }}</th><th>{{ __('Supplier') }}</th><th>{{ __('Tanggal Inspeksi') }}</th><th class="text-center">{{ __('Status') }}</th><th class="text-end">{{ __('Aksi') }}</th></tr></thead>
                <tbody>
                    @forelse($recentInspections as $insp)
                    <tr>
                        <td class="fw-bold">{{ $insp->purchaseOrder->po_number }}</td>
                        <td>{{ $insp->purchaseOrder->quotation->supplier->name }}</td>
                        <td>{{ $insp->inspected_at->format('d M Y') }}</td>
                        <td class="text-center"><span class="badge bg-{{ $insp->status==='ok'?'success':'danger' }}">{{ strtoupper($insp->status) }}</span></td>
                        <td class="text-end"><a href="{{ route('qc.inspections.show', $insp->id) }}" class="btn btn-sm btn-outline-info">{{ __('Detail') }}</a></td>
                    </tr>
                    @empty<tr><td colspan="5" class="text-center text-muted py-3">{{ __('Tidak ada data.') }}</td></tr>@endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
@if($totalInspections > 0)
new Chart(document.getElementById('qualityChart'),{type:'doughnut',data:{labels:['OK','NG'],datasets:[{data:[{{ $totalOk }},{{ $totalNg }}],backgroundColor:['#198754','#dc3545'],borderWidth:0}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom'}},cutout:'70%'}});
@endif
new Chart(document.getElementById('trendChart'),{type:'line',data:{labels:{!! json_encode(array_column($trendData,'label')) !!},datasets:[{label:'OK',data:{!! json_encode(array_column($trendData,'ok')) !!},borderColor:'#198754',backgroundColor:'rgba(25,135,84,0.1)',fill:true,tension:.3,pointRadius:5,pointBackgroundColor:'#198754'},{label:'NG',data:{!! json_encode(array_column($trendData,'ng')) !!},borderColor:'#dc3545',backgroundColor:'rgba(220,53,69,0.1)',fill:true,tension:.3,pointRadius:5,pointBackgroundColor:'#dc3545'}]},options:{responsive:true,maintainAspectRatio:false,scales:{y:{beginAtZero:true,ticks:{stepSize:1}}},plugins:{legend:{position:'bottom'}}}});
</script>
@endpush
