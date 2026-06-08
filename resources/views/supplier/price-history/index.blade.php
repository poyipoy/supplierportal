@extends('layouts.app')
@section('title', 'Price History - ADASI Portal')
@section('page-title', 'Price History Material')

@section('content')
<div class="card border-0 shadow-sm mb-4 text-white overflow-hidden position-relative animate-fade-in" style="background: linear-gradient(135deg, #1F5FA6 0%, #15457a 100%);">
    <div class="position-absolute top-0 end-0 h-100 w-50 opacity-25" style="background: radial-gradient(circle at top right, #ffffff, transparent);"></div>
    <div class="card-body p-4 position-relative z-1">
        <div class="row align-items-center">
            <div class="col-md-8">
                <h4 class="fw-bold mb-2"><i class="bi bi-graph-up-arrow me-2"></i>Price History Quotation</h4>
                <p class="mb-0 text-white-50">Monitor and analyze price trends for materials you have quoted to ADASI.</p>
            </div>
            <div class="col-md-4 text-end d-none d-md-block">
                <i class="bi bi-tags fs-1 text-white opacity-50"></i>
            </div>
        </div>
    </div>
</div>

<ul class="nav nav-pills mb-4 gap-2">
    <li class="nav-item">
        <a class="nav-link active" href="{{ route('supplier.price-history.index') }}">
            <i class="bi bi-list-ul me-1"></i> Ringkasan Material
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="{{ route('supplier.price-history.historical') }}">
            <i class="bi bi-graph-up me-1"></i> Tren per Material
        </a>
    </li>
</ul>

<div class="row g-4 mb-4">
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100 border-start border-4 border-primary">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small fw-medium mb-1">TOTAL MATERIAL DITAWARKAN</div>
                        <h3 class="fw-bold mb-0">{{ number_format($stats['total_materials'] ?? 0, 0, ',', '.') }}</h3>
                    </div>
                    <div class="bg-primary bg-opacity-10 rounded-circle p-3">
                        <i class="bi bi-box-seam text-primary fs-4"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100 border-start border-4 border-success">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small fw-medium mb-1">TOTAL PENAWARAN (ITEM)</div>
                        <h3 class="fw-bold mb-0 text-success">{{ number_format($stats['total_quotations'] ?? 0, 0, ',', '.') }}</h3>
                    </div>
                    <div class="bg-success bg-opacity-10 rounded-circle p-3">
                        <i class="bi bi-file-earmark-check text-success fs-4"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3">
        <h6 class="mb-0 fw-bold">Material List & Latest Price</h6>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 w-100" id="overviewTable" style="font-size:.85rem">
                <thead class="table-light">
                    <tr>
                        <th>Material</th>
                        <th>Total Quotations</th>
                        <th>Price Terakhir (IDR) & Range Price</th>
                        <th>Submit Date Terakhir</th>
                        <th>Latest Status</th>
                        <th class="text-center" style="width: 120px;">Action</th>
                    </tr>
                </thead>
            </table>
        </div>
    </div>
</div>
@endsection

@push('styles')
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<style>
    .dataTables_wrapper .dataTables_length select { padding-right: 2rem; }
    #overviewTable th { white-space: nowrap; }
</style>
@endpush

@push('scripts')
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script>
    $(document).ready(function() {
        $('#overviewTable').DataTable({
            processing: true,
            serverSide: true,
            ajax: '{{ route('supplier.price-history.index') }}',
            columns: [
                { data: 'material_name', name: 'material_name', className: 'fw-bold' },
                { data: 'total_quotations', name: 'total_quotations', searchable: false },
                { data: 'price_info', name: 'price_info', orderable: false, searchable: false },
                { 
                    data: 'last_submitted_at', 
                    name: 'last_submitted_at',
                    render: function(data) {
                        return data ? new Date(data).toLocaleDateString('id-ID', {day: '2-digit', month: 'short', year: 'numeric'}) : '-';
                    }
                },
                { data: 'latest_status_badge', name: 'latest_status_badge', orderable: false, searchable: false },
                { data: 'action', name: 'action', orderable: false, searchable: false, className: 'text-center' }
            ],
            order: [[0, 'asc']],
            
        });
    });
</script>
@endpush
