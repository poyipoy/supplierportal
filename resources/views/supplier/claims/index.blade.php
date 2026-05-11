@extends('layouts.app')

@section('title', 'Klaim Material — ADASI Portal')
@section('page-title', __('Klaim Material'))

@section('content')
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3">
        <h5 class="mb-0 fw-semibold">{{ __('Daftar Klaim Material dari ADASI') }}</h5>
    </div>
    <div class="card-body">
        <div class="alert alert-warning small mb-4">
            <i class="bi bi-exclamation-triangle-fill me-1"></i> {!! __('Daftar di bawah adalah klaim material NG (Not Good) yang diajukan oleh tim Purchasing ADASI. Harap segera merespons klaim yang berstatus <strong>PENDING</strong> sebelum batas waktu (deadline).') !!}
        </div>
        
        <div class="table-responsive">
            <table class="table table-hover align-middle" id="claimTable" style="width: 100%;">
                <thead class="table-light">
                    <tr>
                        <th>{{ __('ID Klaim') }}</th>
                        <th>{{ __('Nomor PO') }}</th>
                        <th>{{ __('Tanggal Diajukan') }}</th>
                        <th>{{ __('Deadline') }}</th>
                        <th class="text-center">{{ __('Status') }}</th>
                        <th class="text-end">{{ __('Aksi') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($claims as $claim)
                    @php
                        $badgeClass = match($claim->status) {
                            'pending' => 'bg-warning text-dark',
                            'responded' => 'bg-info',
                            'resolved' => 'bg-success',
                            'escalated' => 'bg-danger',
                            default => 'bg-secondary'
                        };
                    @endphp
                    <tr class="{{ $claim->status === 'pending' ? 'table-warning bg-opacity-10' : '' }}">
                        <td class="fw-medium">#{{ $claim->id }}</td>
                        <td class="fw-bold">{{ $claim->purchaseOrder->po_number }}</td>
                        <td>{{ $claim->created_at->format('d M Y') }}</td>
                        <td class="{{ $claim->status === 'pending' && $claim->deadline->isPast() ? 'text-danger fw-bold' : '' }}">
                            {{ $claim->deadline->format('d M Y') }}
                        </td>
                        <td class="text-center"><span class="badge {{ $badgeClass }} text-uppercase">{{ __(ucwords(str_replace('_', ' ', $claim->status))) }}</span></td>
                        <td class="text-end">
                            <a href="{{ route('supplier.claims.show', $claim->id) }}" class="btn btn-sm btn-primary" style="background-color: var(--adasi-blue);">
                                @if($claim->status === 'pending')
                                    {{ __('Beri Respons') }}
                                @else
                                    {{ __('Lihat Detail') }}
                                @endif
                            </a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    $(document).ready(function() {
        $('#claimTable').DataTable({
            language: { url: @json(app()->getLocale() === 'id' ? '//cdn.datatables.net/plug-ins/1.13.6/i18n/id.json' : '//cdn.datatables.net/plug-ins/1.13.6/i18n/en-GB.json') },
            pageLength: 25,
            ordering: false
        });
    });
</script>
@endpush
