@extends('layouts.app')
@section('title', 'Laporan — ADASI Portal')
@section('page-title', 'Laporan & Export')

@section('content')
<div class="row g-4">
    {{-- Card 1: Laporan Permintaan Material --}}
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3 border-bottom-0">
                <div class="d-flex align-items-center gap-3">
                    <div class="bg-primary bg-opacity-10 rounded-3 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                        <i class="bi bi-clipboard-data text-primary fs-4"></i>
                    </div>
                    <div>
                        <h6 class="mb-0 fw-bold">Permintaan Material</h6>
                        <small class="text-muted">Export rekap PR beserta status dan item</small>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <form action="{{ route('purchasing.export.requirements') }}" method="GET">
                    <div class="mb-3">
                        <label class="form-label small fw-medium text-muted">Filter Periode</label>
                        <select name="period_id" class="form-select">
                            <option value="">-- Semua Periode --</option>
                            @foreach($periods as $period)
                                <option value="{{ $period->id }}">{{ $period->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-4">
                        <label class="form-label small fw-medium text-muted">Filter Status</label>
                        <select name="status" class="form-select">
                            <option value="">-- Semua Status --</option>
                            <option value="draft">Draft</option>
                            <option value="submitted">Submitted</option>
                            <option value="rejected">Rejected</option>
                            <option value="bidding">Bidding</option>
                            <option value="completed">Completed</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-outline-success w-100 fw-medium">
                        <i class="bi bi-file-earmark-excel me-2"></i>Download Excel
                    </button>
                </form>
            </div>
        </div>
    </div>

    {{-- Card 2: Laporan Purchase Order --}}
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3 border-bottom-0">
                <div class="d-flex align-items-center gap-3">
                    <div class="bg-success bg-opacity-10 rounded-3 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                        <i class="bi bi-receipt text-success fs-4"></i>
                    </div>
                    <div>
                        <h6 class="mb-0 fw-bold">Purchase Order (PO)</h6>
                        <small class="text-muted">Export data PO dan status kedatangan</small>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <form action="{{ route('purchasing.export.purchase-orders') }}" method="GET">
                    <div class="mb-3">
                        <label class="form-label small fw-medium text-muted">Filter Supplier</label>
                        <select name="supplier_id" class="form-select">
                            <option value="">-- Semua Supplier --</option>
                            @foreach($suppliers as $supplier)
                                <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="row g-2 mb-4">
                        <div class="col-6">
                            <label class="form-label small fw-medium text-muted">Tanggal Mulai</label>
                            <input type="date" name="start_date" class="form-control">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-medium text-muted">Tanggal Selesai</label>
                            <input type="date" name="end_date" class="form-control">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-outline-success w-100 fw-medium">
                        <i class="bi bi-file-earmark-excel me-2"></i>Download Excel
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
