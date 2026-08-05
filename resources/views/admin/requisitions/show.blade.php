@extends('layouts.app')

@section('title', 'Purchase Requisition Details - ADASI Portal')
@section('page-title', 'Purchase Requisition Details')

@section('content')
<x-breadcrumb :items="[
    'Dashboard' => route('admin.dashboard'),
    ($pr->pr_number ?? 'Purchase Requisition') => '#',
]" />

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-bold">{{ $pr->pr_number ?? 'Purchase Requisition' }}</h6>
        <x-status-badge type="pr" :status="$pr->status" size="lg" />
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6">
                <div class="text-muted small">Period</div>
                <div class="fw-medium">{{ $pr->period->name ?? '-' }}</div>
            </div>
            <div class="col-md-6">
                <div class="text-muted small">Created By</div>
                <div class="fw-medium">{{ $pr->creator->name ?? '-' }}</div>
            </div>
            <div class="col-md-6">
                <div class="text-muted small">Date Created</div>
                <div class="fw-medium">{{ $pr->created_at?->format('d F Y, H:i') ?? '-' }}</div>
            </div>
            <div class="col-md-6">
                <div class="text-muted small">Status</div>
                <div class="fw-medium">{{ ucwords(str_replace('_', ' ', $pr->status)) }}</div>
            </div>
            <div class="col-12">
                <div class="text-muted small">Header Notes</div>
                <div class="fw-medium">{{ $pr->notes ?: '-' }}</div>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3">
        <h6 class="mb-0 fw-bold">Material List ({{ $pr->items->count() }} Item)</h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light text-center">
                    <tr>
                        <th>No</th>
                        <th>HS Code</th>
                        <th>Material</th>
                        <th>Shape & Dimensions (mm)</th>
                        <th>Qty</th>
                        <th>Weight/Unit (Kg)</th>
                        <th>Total Weight (Kg)</th>
                        <th>Remark</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($pr->items as $index => $item)
                        <tr>
                            <td class="text-center">{{ $index + 1 }}</td>
                            <td class="text-center">{{ $item->hs_code ?: '-' }}</td>
                            <td>{{ $item->material_name }}</td>
                            <td class="text-center">
                                @if($item->shape)
                                    <span class="badge bg-light text-dark border">{{ $item->shape }}</span><br>
                                    <span class="small text-muted">{{ $item->dimension_label }}</span>
                                @else
                                    -
                                @endif
                            </td>
                            <td class="text-center">{{ number_format($item->quantity_value, 0) }}</td>
                            <td class="text-end">{{ number_format($item->weight_needed, 2) }}</td>
                            <td class="text-end fw-semibold">{{ number_format($item->total_weight, 2) }}</td>
                            <td>{{ $item->remark ?: '-' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-muted py-4">No material data.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer bg-white text-end">
        <a href="{{ route('admin.dashboard') }}" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Back to Dashboard
        </a>
    </div>
</div>
@endsection
