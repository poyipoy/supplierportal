@extends('layouts.app')

@section('title', 'QC Inspection Details: ' . $inspection->purchaseOrder->po_number . ' - ADASI Portal')
@section('page-title', 'QC Inspection Details')

@section('content')
<x-breadcrumb :items="[
    'Dashboard' => route('qc.dashboard'),
    'QC Inspection' => route('qc.inspections.index'),
    'PO Inspection ' . $inspection->purchaseOrder->po_number => '#'
]" />
<div class="mb-3">
    <a href="{{ route('qc.inspections.index') }}" class="text-decoration-none text-muted small">
        <i class="bi bi-arrow-left me-1"></i> Back to Inspection List
    </a>
</div>

{{-- Header Card --}}
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="fw-bold mb-0">PO Inspection: {{ $inspection->purchaseOrder->po_number }}</h5>
            <div>
                @if($inspection->status === 'ok')
                    <span class="badge bg-success fs-6 px-3 py-2 me-2">STATUS: OK</span>
                @else
                    <span class="badge bg-danger fs-6 px-3 py-2 me-2">STATUS: NG</span>
                @endif
                <a href="{{ route('purchasing.pdf.qc-inspection', $inspection->id) }}" class="btn btn-sm btn-outline-danger" target="_blank" title="Cetak Report QC">
                    <i class="bi bi-file-earmark-pdf"></i> Cetak PDF
                </a>
            </div>
        </div>
        <div class="row g-3">
            <div class="col-md-3">
                <div class="text-muted small">Supplier</div>
                <div class="fw-medium">{{ $inspection->purchaseOrder->supplier->company_name ?? $inspection->purchaseOrder->supplier->name ?? '-' }}</div>
            </div>
            <div class="col-md-3">
                <div class="text-muted small">Inspected By</div>
                <div class="fw-medium">{{ $inspection->inspector->name }}</div>
            </div>
            <div class="col-md-3">
                <div class="text-muted small">Inspection Time</div>
                <div class="fw-medium">{{ $inspection->inspected_at->format('d M Y, H:i') }}</div>
            </div>
            <div class="col-md-3">
                <div class="text-muted small">Date Material Arrived</div>
                <div class="fw-medium">{{ $inspection->purchaseOrder->actual_arrival ? $inspection->purchaseOrder->actual_arrival->format('d M Y') : '-' }}</div>
            </div>
        </div>
    </div>
</div>

@php
    if (!function_exists('compareValues')) {
        function compareValues($actual, $expected) {
            if ($actual === null || $expected === null) return ['val' => $actual ?? '-', 'class' => ''];
            
            $act = (float) $actual;
            $exp = (float) $expected;
            if ($exp > 0) {
                $diff = abs($act - $exp) / $exp;
                if ($diff > 0.05) return ['val' => $actual, 'class' => 'text-danger fw-bold'];
            }
            return ['val' => $actual, 'class' => ''];
        }
    }
@endphp

{{-- Items --}}
@foreach($inspection->items as $index => $item)
    @php
        $prItem = $item->prItem;
        
        $thick = compareValues($item->actual_thickness, $prItem->thickness);
        $dInner = compareValues($item->actual_d_inner, $prItem->d_inner);
        $dOuter = compareValues($item->actual_d_outer, $prItem->d_outer);
        $width = compareValues($item->actual_width, $prItem->width);
        $length = compareValues($item->actual_length, $prItem->length);
        $weight = compareValues($item->actual_weight, $prItem->weight_needed);
    @endphp

    <div class="card border-{{ $item->status === 'ng' ? 'danger' : 'success' }} shadow-sm mb-4">
        <div class="card-header py-3 d-flex justify-content-between align-items-center {{ $item->status === 'ng' ? 'bg-danger bg-opacity-10' : 'bg-success bg-opacity-10' }}">
            <h6 class="mb-0 fw-bold {{ $item->status === 'ng' ? 'text-danger' : 'text-success' }}">Item #{{ $index + 1 }}: {{ $prItem->material_name }}</h6>
            @if($item->status === 'ok')
                <span class="badge bg-success"><i class="bi bi-check-circle-fill me-1"></i>OK</span>
            @else
                <span class="badge bg-danger"><i class="bi bi-x-circle-fill me-1"></i>NG</span>
            @endif
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered align-middle mb-0" style="font-size: 0.85rem;">
                    <thead class="table-light text-center">
                        <tr>
                            <th>Parameter</th>
                            <th>Shape</th>
                            <th>Thickness (mm)</th>
                            <th>Inner Dia. (mm)</th>
                            <th>Outer Dia. (mm)</th>
                            <th>Width (mm)</th>
                            <th>Length (mm)</th>
                            <th>Qty</th>
                            <th>Weight/Unit (Kg)</th>
                        </tr>
                    </thead>
                    <tbody>
                        {{-- Requested --}}
                        <tr class="text-center">
                            <td class="text-start text-muted fw-medium bg-light">Requested</td>
                            <td>{{ $prItem->shape ?? '-' }}</td>
                            <td>{{ $prItem->thickness ?? '-' }}</td>
                            <td>{{ $prItem->d_inner ?? '-' }}</td>
                            <td>{{ $prItem->d_outer ?? '-' }}</td>
                            <td>{{ $prItem->width ?? '-' }}</td>
                            <td>{{ $prItem->length ?? '-' }}</td>
                            <td>{{ number_format($prItem->quantity_value, 0) }}</td>
                            <td>{{ $prItem->weight_needed ?? '-' }}</td>
                        </tr>
                        {{-- Actual --}}
                        <tr class="text-center">
                            <td class="text-start fw-bold text-primary bg-light">Actual</td>
                            <td>{{ $prItem->shape ?? '-' }}</td>
                            <td class="{{ $thick['class'] }}">{{ $thick['val'] }}</td>
                            <td class="{{ $dInner['class'] }}">{{ $dInner['val'] }}</td>
                            <td class="{{ $dOuter['class'] }}">{{ $dOuter['val'] }}</td>
                            <td class="{{ $width['class'] }}">{{ $width['val'] }}</td>
                            <td class="{{ $length['class'] }}">{{ $length['val'] }}</td>
                            <td>{{ number_format($prItem->quantity_value, 0) }}</td>
                            <td class="{{ $weight['class'] }}">{{ $weight['val'] }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            @if($item->notes)
            <div class="p-3 border-top bg-light">
                <div class="small text-muted fw-medium mb-1">Notes:</div>
                <p class="mb-0 small">{{ $item->notes }}</p>
            </div>
            @endif
        </div>
    </div>
@endforeach

{{-- Photos if NG --}}
@if($inspection->status === 'ng')
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-3">
        <h6 class="mb-0 fw-bold">NG Evidence Photos</h6>
    </div>
    <div class="card-body">
        @if($inspection->attachments->count() > 0)
            <div class="row g-3">
                @foreach($inspection->attachments as $att)
                    <div class="col-6 col-md-4 col-lg-3">
                        <a href="{{ route('attachments.show', $att->id) }}" target="_blank" class="d-block border rounded overflow-hidden shadow-sm" style="height: 150px;">
                            <img src="{{ route('attachments.show', $att->id) }}" alt="{{ $att->file_name }}" class="w-100 h-100" style="object-fit: cover;">
                        </a>
                    </div>
                @endforeach
            </div>
        @else
            <div class="alert alert-warning small">
                <i class="bi bi-exclamation-triangle me-1"></i>
                No NG evidence photos for this inspection yet.
            </div>
        @endif

        @if(auth()->user()->role === 'qc')
            <form action="{{ route('qc.inspections.attachments.store', $inspection->id) }}" method="POST" enctype="multipart/form-data" class="border-top mt-3 pt-3">
                @csrf
                <label class="form-label fw-medium small">Add NG Evidence Photo</label>
                <input type="file" name="attachments[]" class="form-control @error('attachments') is-invalid @enderror @error('attachments.*') is-invalid @enderror" accept=".jpg,.jpeg,.png" multiple required>
                <div class="form-text">JPG, JPEG, or PNG format. Maximum 10MB per file.</div>
                @error('attachments')
                    <div class="text-danger small mt-1">{{ $message }}</div>
                @enderror
                @error('attachments.*')
                    <div class="text-danger small mt-1">{{ $message }}</div>
                @enderror
                <div class="text-end mt-3">
                    <button type="submit" class="btn btn-sm btn-danger">
                        <i class="bi bi-upload me-1"></i>Upload Photo
                    </button>
                </div>
            </form>
        @endif
    </div>
</div>
@endif

@endsection
