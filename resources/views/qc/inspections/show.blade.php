@extends('layouts.app')

@section('title', 'Detail Inspeksi QC: ' . $inspection->purchaseOrder->po_number . ' — ADASI Portal')
@section('page-title', __('Detail Inspeksi QC'))

@section('content')
<div class="mb-3">
    <a href="{{ route('qc.inspections.index') }}" class="text-decoration-none text-muted small">
        <i class="bi bi-arrow-left me-1"></i> {{ __('Kembali ke Daftar Inspeksi') }}
    </a>
</div>

{{-- Header Card --}}
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="fw-bold mb-0">{{ __('Inspeksi PO') }}: {{ $inspection->purchaseOrder->po_number }}</h5>
            @if($inspection->status === 'ok')
                <span class="badge bg-success fs-6 px-3 py-2">{{ __('STATUS') }}: OK</span>
            @else
                <span class="badge bg-danger fs-6 px-3 py-2">{{ __('STATUS') }}: NG</span>
            @endif
        </div>
        <div class="row">
            <div class="col-md-3">
                <div class="text-muted small">{{ __('Supplier') }}</div>
                <div class="fw-medium">{{ $inspection->purchaseOrder->quotation->supplier->name }}</div>
            </div>
            <div class="col-md-3">
                <div class="text-muted small">{{ __('Diinspeksi Oleh') }}</div>
                <div class="fw-medium">{{ $inspection->inspector->name }}</div>
            </div>
            <div class="col-md-3">
                <div class="text-muted small">{{ __('Waktu Inspeksi') }}</div>
                <div class="fw-medium">{{ $inspection->inspected_at->format('d M Y, H:i') }}</div>
            </div>
            <div class="col-md-3">
                <div class="text-muted small">{{ __('Tanggal Material Tiba') }}</div>
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

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h6 class="mb-0 fw-bold">Item #{{ $index + 1 }}: {{ $prItem->material_name }}</h6>
            @if($item->status === 'ok')
                <span class="badge bg-success">{{ __('Item OK') }}</span>
            @else
                <span class="badge bg-danger">{{ __('Item NG') }}</span>
            @endif
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered align-middle mb-0" style="font-size: 0.85rem;">
                    <thead class="table-light text-center">
                        <tr>
                            <th>{{ __('Parameter') }}</th>
                            <th>{{ __('Shape') }}</th>
                            <th>{{ __('Thickness') }} (mm)</th>
                            <th>{{ __('Inner Dia.') }} (mm)</th>
                            <th>{{ __('Outer Dia.') }} (mm)</th>
                            <th>{{ __('Width') }} (mm)</th>
                            <th>{{ __('Length') }} (mm)</th>
                            <th>{{ __('Weight (Kg)') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {{-- Requested --}}
                        <tr class="text-center">
                            <td class="text-start text-muted fw-medium bg-light">{{ __('Diminta') }}</td>
                            <td>{{ $prItem->shape ?? '-' }}</td>
                            <td>{{ $prItem->thickness ?? '-' }}</td>
                            <td>{{ $prItem->d_inner ?? '-' }}</td>
                            <td>{{ $prItem->d_outer ?? '-' }}</td>
                            <td>{{ $prItem->width ?? '-' }}</td>
                            <td>{{ $prItem->length ?? '-' }}</td>
                            <td>{{ $prItem->weight_needed ?? '-' }}</td>
                        </tr>
                        {{-- Actual --}}
                        <tr class="text-center">
                            <td class="text-start fw-bold text-primary bg-light">{{ __('Aktual') }}</td>
                            <td class="text-muted">N/A</td>
                            <td class="{{ $thick['class'] }}">{{ $thick['val'] }}</td>
                            <td class="{{ $dInner['class'] }}">{{ $dInner['val'] }}</td>
                            <td class="{{ $dOuter['class'] }}">{{ $dOuter['val'] }}</td>
                            <td class="{{ $width['class'] }}">{{ $width['val'] }}</td>
                            <td class="{{ $length['class'] }}">{{ $length['val'] }}</td>
                            <td class="{{ $weight['class'] }}">{{ $weight['val'] }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            @if($item->notes)
            <div class="p-3 border-top bg-light">
                <div class="small text-muted fw-medium mb-1">{{ __('Catatan') }}:</div>
                <p class="mb-0 small">{{ $item->notes }}</p>
            </div>
            @endif
        </div>
    </div>
@endforeach

{{-- Photos if NG --}}
@if($inspection->attachments->count() > 0)
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-3">
        <h6 class="mb-0 fw-bold">{{ __('Foto Bukti NG') }}</h6>
    </div>
    <div class="card-body">
        <div class="row g-3">
            @foreach($inspection->attachments as $att)
                <div class="col-6 col-md-4 col-lg-3">
                    <a href="{{ route('attachments.show', $att->id) }}" target="_blank" class="d-block border rounded overflow-hidden shadow-sm" style="height: 150px;">
                        <img src="{{ route('attachments.show', $att->id) }}" alt="{{ $att->file_name }}" class="w-100 h-100" style="object-fit: cover;">
                    </a>
                </div>
            @endforeach
        </div>
    </div>
</div>
@endif

@endsection
