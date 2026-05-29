@extends('layouts.app')
@section('title', 'Dashboard Purchasing — ADASI Portal')
@section('page-title', 'Dashboard Purchasing')

@section('content')
{{-- Card Statistik --}}
<div class="row g-4 mb-4">
    <div class="col-md-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100 border-start border-4 border-primary">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div><div class="text-muted small fw-medium mb-1">PERMINTAAN AKTIF</div><h3 class="fw-bold mb-0">{{ $prAktif }}</h3></div>
                    <div class="bg-primary bg-opacity-10 rounded-circle p-3"><i class="bi bi-clipboard-data text-primary fs-4"></i></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100 border-start border-4 border-warning">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div><div class="text-muted small fw-medium mb-1">MENUNGGU PENAWARAN</div><h3 class="fw-bold mb-0 text-warning">{{ $menungguPenawaran }}</h3></div>
                    <div class="bg-warning bg-opacity-10 rounded-circle p-3"><i class="bi bi-hourglass-split text-warning fs-4"></i></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100 border-start border-4 border-success">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div><div class="text-muted small fw-medium mb-1">PO BERJALAN</div><h3 class="fw-bold mb-0 text-success">{{ $poBerjalan }}</h3></div>
                    <div class="bg-success bg-opacity-10 rounded-circle p-3"><i class="bi bi-receipt text-success fs-4"></i></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100 border-start border-4 border-info">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div><div class="text-muted small fw-medium mb-1">TIBA MINGGU INI</div><h3 class="fw-bold mb-0 text-info">{{ $materialMingguIni }}</h3></div>
                    <div class="bg-info bg-opacity-10 rounded-circle p-3"><i class="bi bi-truck text-info fs-4"></i></div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Grafik --}}
<div class="row g-4 mb-4">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3"><h6 class="mb-0 fw-bold">Permintaan Material per Bulan</h6></div>
            <div class="card-body"><canvas id="prChart" height="260"></canvas></div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3"><h6 class="mb-0 fw-bold">Distribusi Status PO</h6></div>
            <div class="card-body d-flex align-items-center justify-content-center">
                @if(count($poStatusDist) > 0)
                    <div style="width:220px;height:220px;"><canvas id="poDonut"></canvas></div>
                @else
                    <div class="text-muted text-center small">Belum ada data PO.</div>
                @endif
            </div>
        </div>
    </div>
</div>

{{-- Tabel + Kurs --}}
<div class="row g-4">
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold">5 PR Terbaru</h6>
                <a href="{{ route('purchasing.requirements.index') }}" class="btn btn-sm btn-light">Semua</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size:.85rem">
                        <thead class="table-light"><tr><th>No. PR</th><th>Periode</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                            @forelse($prTerbaru as $pr)
                            <tr>
                                <td class="fw-bold">{{ $pr->pr_number ?? 'DRAFT' }}</td>
                                <td>{{ $pr->period->name }}</td>
                                <td>@php $c=match($pr->status){'draft'=>'bg-secondary','submitted'=>'bg-primary','bidding'=>'bg-warning text-dark','completed'=>'bg-success',default=>'bg-secondary'};@endphp<span class="badge {{ $c }} text-uppercase" style="font-size:.65rem">{{ ucwords(str_replace('_', ' ', $pr->status)) }}</span></td>
                                <td class="text-end"><a href="{{ \App\Support\PurchasingNavigation::toRoute('purchasing.requirements.show', $pr->id) }}" class="btn btn-sm btn-outline-info py-0"><i class="bi bi-eye"></i></a></td>
                            </tr>
                            @empty<tr><td colspan="4" class="text-center text-muted py-3">Belum ada data.</td></tr>@endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        {{-- Kurs --}}
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold"><i class="bi bi-currency-exchange me-1"></i> Kurs Hari Ini</h6>
                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#kursModal"><i class="bi bi-pencil-square"></i> Update</button>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    @foreach(\App\Models\ExchangeRate::CURRENCIES as $currency)
                        @php
                            $rate = $latestRates[$currency] ?? null;
                        @endphp
                        <div class="col-6">
                            <div class="p-3 bg-light rounded text-center h-100">
                                <div class="text-muted small mb-1">{{ $currency }} → IDR</div>
                                <h5 class="fw-bold mb-0">Rp {{ $rate ? number_format($rate->rate_to_idr, 0, ',', '.') : '-' }}</h5>
                            </div>
                        </div>
                    @endforeach
                </div>
                @php
                    $lastRateUpdated = $latestRates->filter()->sortByDesc('valid_from')->first()?->valid_from;
                @endphp
                @if($lastRateUpdated)
                    <div class="text-muted text-center mt-2" style="font-size:.7rem">Update kurs terbaru: {{ $lastRateUpdated->format('d M Y') }}</div>
                @endif
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold">PO - Kedatangan Terdekat</h6>
                <a href="{{ route('purchasing.purchase-orders.index') }}" class="btn btn-sm btn-light">Semua PO</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size:.85rem">
                        <thead class="table-light"><tr><th>No. PO</th><th>Supplier</th><th>Estimasi Tiba</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                            @forelse($poTerdekat as $po)
                            <tr>
                                <td class="fw-bold">{{ $po->po_number }}</td>
                                <td>{{ $po->supplier->name }}</td>
                                <td>{{ \Carbon\Carbon::parse($po->estimated_arrival)->format('d M Y') }}</td>
                                <td><span class="badge bg-primary text-uppercase" style="font-size:.65rem">{{ ucwords(str_replace('_', ' ', $po->status)) }}</span></td>
                                <td class="text-end"><a href="{{ \App\Support\PurchasingNavigation::toRoute('purchasing.purchase-orders.show', $po->id) }}" class="btn btn-sm btn-outline-info py-0"><i class="bi bi-eye"></i></a></td>
                            </tr>
                            @empty<tr><td colspan="5" class="text-center text-muted py-3">Tidak ada PO aktif.</td></tr>@endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Modal Kurs --}}
<div class="modal fade" id="kursModal" tabindex="-1"><div class="modal-dialog modal-sm"><div class="modal-content">
    <form action="{{ route('purchasing.kurs.update') }}" method="POST">@csrf
        <div class="modal-header"><h6 class="modal-title fw-bold">Update Kurs</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="mb-3">
                <label class="form-label small fw-bold">Mata Uang</label>
                <select name="currency" class="form-select form-select-sm" required>
                    @foreach(\App\Models\ExchangeRate::CURRENCY_LABELS as $code => $label)
                        <option value="{{ $code }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="mb-3"><label class="form-label small fw-bold">Rate ke IDR</label><input type="number" step="0.01" name="rate_to_idr" class="form-control form-control-sm" required placeholder="16500"></div>
        </div>
        <div class="modal-footer"><button type="submit" class="btn btn-primary btn-sm w-100">Simpan</button></div>
    </form>
</div></div></div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
new Chart(document.getElementById('prChart'),{type:'bar',data:{labels:{!! json_encode(array_column($prPerBulan,'label')) !!},datasets:[{label:@json('Jumlah PR'),data:{!! json_encode(array_column($prPerBulan,'count')) !!},backgroundColor:'rgba(31,95,166,0.7)',borderRadius:6}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{stepSize:1}}}}});
@if(count($poStatusDist)>0)
@php
    $statusColors = [
        'active' => '#0d6efd',
        'waiting_qc' => '#ffc107',
        'completed' => '#198754',
        'overdue' => '#dc3545',
        'claim_needed' => '#dc3545',
        'cancelled' => '#6c757d',
    ];
    $chartLabels = [];
    $chartData = [];
    $chartColors = [];
    foreach($poStatusDist as $status => $count) {
        $chartLabels[] = ucwords(str_replace('_', ' ', $status));
        $chartData[] = $count;
        $chartColors[] = $statusColors[$status] ?? '#6c757d';
    }
@endphp
new Chart(document.getElementById('poDonut'),{type:'doughnut',data:{labels:{!! json_encode($chartLabels) !!},datasets:[{data:{!! json_encode($chartData) !!},backgroundColor:{!! json_encode($chartColors) !!},borderWidth:0}]},options:{responsive:true,maintainAspectRatio:false,cutout:'65%',plugins:{legend:{position:'bottom',labels:{boxWidth:12}}}}});
@endif
</script>
@endpush
