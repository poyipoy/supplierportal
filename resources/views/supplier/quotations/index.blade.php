@extends('layouts.app')

@section('title', 'Daftar Periode Penawaran — ADASI Portal')
@section('page-title', __('Periode Penawaran'))

@section('content')
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3">
        <h5 class="mb-0 fw-semibold">{{ __('Pilih Periode') }}</h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>{{ __('Periode') }}</th>
                        <th class="text-center">{{ __('Status') }}</th>
                        <th class="text-center">{{ __('Belum Direspons') }}</th>
                        <th class="text-center">{{ __('Sudah Dikirim') }}</th>
                        <th class="text-end pe-4">{{ __('Aksi') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($periods as $period)
                        <tr>
                            <td class="fw-medium ps-3">{{ $period->name }} ({{ str_pad($period->month, 2, '0', STR_PAD_LEFT) }}/{{ $period->year }})</td>
                            <td class="text-center">
                                <span class="badge bg-success">OPEN</span>
                            </td>
                            <td class="text-center">
                                @if($period->unresponded_prs > 0)
                                    <span class="badge bg-danger rounded-pill px-3">{{ $period->unresponded_prs }} PR</span>
                                @else
                                    <span class="text-muted small">-</span>
                                @endif
                            </td>
                            <td class="text-center">
                                @if($period->responded_prs > 0)
                                    <span class="badge bg-success rounded-pill px-3">{{ $period->responded_prs }} PR</span>
                                @else
                                    <span class="text-muted small">-</span>
                                @endif
                            </td>
                            <td class="text-end pe-4">
                                <a href="{{ route('supplier.quotations.period', $period->id) }}" class="btn btn-sm btn-primary" style="background-color: var(--adasi-blue);">
                                    {{ __('Lihat Permintaan') }} <i class="bi bi-arrow-right ms-1"></i>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center py-4 text-muted">{{ __('Belum ada periode penawaran yang dibuka.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
