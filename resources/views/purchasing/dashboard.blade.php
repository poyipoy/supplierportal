@extends('layouts.app')
@section('title', 'Dashboard Purchasing — ADASI Portal')
@section('page-title', __('Dashboard Purchasing'))

@section('content')
{{-- Card Statistik --}}
<div class="row g-4 mb-4">
    <div class="col-md-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100 border-start border-4 border-primary">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div><div class="text-muted small fw-medium mb-1">{{ __('PERMINTAAN AKTIF') }}</div><h3 class="fw-bold mb-0">{{ $prAktif }}</h3></div>
                    <div class="bg-primary bg-opacity-10 rounded-circle p-3"><i class="bi bi-clipboard-data text-primary fs-4"></i></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100 border-start border-4 border-warning">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div><div class="text-muted small fw-medium mb-1">{{ __('MENUNGGU PENAWARAN') }}</div><h3 class="fw-bold mb-0 text-warning">{{ $menungguPenawaran }}</h3></div>
                    <div class="bg-warning bg-opacity-10 rounded-circle p-3"><i class="bi bi-hourglass-split text-warning fs-4"></i></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100 border-start border-4 border-success">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div><div class="text-muted small fw-medium mb-1">{{ __('PO BERJALAN') }}</div><h3 class="fw-bold mb-0 text-success">{{ $poBerjalan }}</h3></div>
                    <div class="bg-success bg-opacity-10 rounded-circle p-3"><i class="bi bi-receipt text-success fs-4"></i></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100 border-start border-4 border-info">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div><div class="text-muted small fw-medium mb-1">{{ __('TIBA MINGGU INI') }}</div><h3 class="fw-bold mb-0 text-info">{{ $materialMingguIni }}</h3></div>
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
            <div class="card-header bg-white py-3"><h6 class="mb-0 fw-bold">{{ __('Permintaan Material per Bulan') }}</h6></div>
            <div class="card-body"><canvas id="prChart" height="260"></canvas></div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3"><h6 class="mb-0 fw-bold">{{ __('Distribusi Status PO') }}</h6></div>
            <div class="card-body d-flex align-items-center justify-content-center">
                @if(count($poStatusDist) > 0)
                    <div style="width:220px;height:220px;"><canvas id="poDonut"></canvas></div>
                @else
                    <div class="text-muted text-center small">{{ __('Belum ada data PO.') }}</div>
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
                <h6 class="mb-0 fw-bold">{{ __('5 PR Terbaru') }}</h6>
                <a href="{{ route('purchasing.requirements.index') }}" class="btn btn-sm btn-light">{{ __('Semua') }}</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size:.85rem">
                        <thead class="table-light"><tr><th>{{ __('No. PR') }}</th><th>{{ __('Periode') }}</th><th>{{ __('Status') }}</th><th></th></tr></thead>
                        <tbody>
                            @forelse($prTerbaru as $pr)
                            <tr>
                                <td class="fw-bold">{{ $pr->pr_number ?? 'DRAFT' }}</td>
                                <td>{{ $pr->period->name }}</td>
                                <td>@php $c=match($pr->status){'draft'=>'bg-secondary','submitted'=>'bg-primary','bidding'=>'bg-warning text-dark','completed'=>'bg-success',default=>'bg-secondary'};@endphp<span class="badge {{ $c }} text-uppercase" style="font-size:.65rem">{{ __(ucwords(str_replace('_', ' ', $pr->status))) }}</span></td>
                                <td class="text-end"><a href="{{ route('purchasing.requirements.show', $pr->id) }}" class="btn btn-sm btn-outline-info py-0"><i class="bi bi-eye"></i></a></td>
                            </tr>
                            @empty<tr><td colspan="4" class="text-center text-muted py-3">{{ __('Belum ada data.') }}</td></tr>@endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        {{-- Kurs --}}
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold"><i class="bi bi-currency-exchange me-1"></i> {{ __('Kurs Hari Ini') }}</h6>
                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#kursModal"><i class="bi bi-pencil-square"></i> {{ __('Update') }}</button>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-6">
                        <div class="p-3 bg-light rounded text-center">
                            <div class="text-muted small mb-1">USD → IDR</div>
                            <h5 class="fw-bold mb-0">Rp {{ $kursUsd ? number_format($kursUsd->rate_to_idr, 0, ',', '.') : '-' }}</h5>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-3 bg-light rounded text-center">
                            <div class="text-muted small mb-1">JPY → IDR</div>
                            <h5 class="fw-bold mb-0">Rp {{ $kursJpy ? number_format($kursJpy->rate_to_idr, 0, ',', '.') : '-' }}</h5>
                        </div>
                    </div>
                </div>
                @if($kursUsd)<div class="text-muted text-center mt-2" style="font-size:.7rem">{{ __('Terakhir update') }}: {{ $kursUsd->valid_from->format('d M Y') }}</div>@endif
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold">{{ __('PO - Kedatangan Terdekat') }}</h6>
                <a href="{{ route('purchasing.purchase-orders.index') }}" class="btn btn-sm btn-light">{{ __('Semua PO') }}</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size:.85rem">
                        <thead class="table-light"><tr><th>{{ __('No. PO') }}</th><th>{{ __('Supplier') }}</th><th>{{ __('Estimasi Tiba') }}</th><th>{{ __('Status') }}</th><th></th></tr></thead>
                        <tbody>
                            @forelse($poTerdekat as $po)
                            <tr>
                                <td class="fw-bold">{{ $po->po_number }}</td>
                                <td>{{ $po->quotation->supplier->name }}</td>
                                <td>{{ \Carbon\Carbon::parse($po->estimated_arrival)->format('d M Y') }}</td>
                                <td><span class="badge bg-primary text-uppercase" style="font-size:.65rem">{{ __(ucwords(str_replace('_', ' ', $po->status))) }}</span></td>
                                <td class="text-end"><a href="{{ route('purchasing.purchase-orders.show', $po->id) }}" class="btn btn-sm btn-outline-info py-0"><i class="bi bi-eye"></i></a></td>
                            </tr>
                            @empty<tr><td colspan="5" class="text-center text-muted py-3">{{ __('Tidak ada PO aktif.') }}</td></tr>@endforelse
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
        <div class="modal-header"><h6 class="modal-title fw-bold">{{ __('Update Kurs') }}</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="mb-3"><label class="form-label small fw-bold">{{ __('Mata Uang') }}</label><select name="currency" class="form-select form-select-sm" required><option value="USD">USD</option><option value="JPY">JPY</option></select></div>
            <div class="mb-3"><label class="form-label small fw-bold">{{ __('Rate ke IDR') }}</label><input type="number" step="0.01" name="rate_to_idr" class="form-control form-control-sm" required placeholder="16500"></div>
        </div>
        <div class="modal-footer"><button type="submit" class="btn btn-primary btn-sm w-100">{{ __('Simpan') }}</button></div>
    </form>
</div></div></div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
new Chart(document.getElementById('prChart'),{type:'bar',data:{labels:{!! json_encode(array_column($prPerBulan,'label')) !!},datasets:[{label:@json(__('Jumlah PR')),data:{!! json_encode(array_column($prPerBulan,'count')) !!},backgroundColor:'rgba(31,95,166,0.7)',borderRadius:6}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{stepSize:1}}}}});
@if(count($poStatusDist)>0)
new Chart(document.getElementById('poDonut'),{type:'doughnut',data:{labels:{!! json_encode(array_map('ucfirst',array_keys($poStatusDist))) !!},datasets:[{data:{!! json_encode(array_values($poStatusDist)) !!},backgroundColor:['#1F5FA6','#ffc107','#198754','#dc3545','#0dcaf0','#6c757d'],borderWidth:0}]},options:{responsive:true,maintainAspectRatio:false,cutout:'65%',plugins:{legend:{position:'bottom',labels:{boxWidth:12}}}}});
@endif
</script>
@endpush
