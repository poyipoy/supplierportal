@extends('layouts.app')

@section('title', 'Daftar Permintaan: ' . $period->name . ' — ADASI Portal')
@section('page-title', __('Permintaan Material') . ': ' . $period->name)

@section('content')
<div class="mb-3">
    <a href="{{ route('supplier.quotations.index') }}" class="text-decoration-none text-muted small">
        <i class="bi bi-arrow-left me-1"></i> {{ __('Kembali ke Daftar Periode') }}
    </a>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3">
        <h5 class="mb-0 fw-semibold">{{ __('Daftar Permintaan Pembelian') }}</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle" id="prTable">
                <thead class="table-light">
                    <tr>
                        <th>{{ __('No') }}</th>
                        <th>{{ __('Tanggal Diajukan') }}</th>
                        <th>{{ __('Jumlah Item') }}</th>
                        <th>{{ __('Status Penawaran Saya') }}</th>
                        <th class="text-end">{{ __('Aksi') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($requirements as $index => $pr)
                        @php
                            $quotation = $pr->quotations->first();
                            $status = $quotation ? $quotation->status : 'unresponded';
                        @endphp
                        <tr>
                            <td>{{ $index + 1 }}</td>
                            <td>{{ $pr->updated_at->format('d M Y, H:i') }}</td>
                            <td>{{ $pr->items->count() }} {{ __('Item') }}</td>
                            <td>
                                @if($status === 'unresponded')
                                    <span class="badge bg-danger">{{ __('Belum Direspons') }}</span>
                                @elseif($status === 'draft')
                                    <span class="badge bg-secondary">{{ __('Draft') }}</span>
                                @elseif($status === 'submitted')
                                    <span class="badge bg-success">{{ __('Terkirim') }} ({{ $quotation->submitted_at->format('d M Y H:i') }})</span>
                                @elseif($status === 'rejected')
                                    <span class="badge bg-dark">{{ __('Ditolak') }}</span>
                                @endif
                            </td>
                            <td class="text-end">
                                @if($status === 'unresponded')
                                    <a href="{{ route('supplier.quotations.create', $pr->id) }}" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-pencil-square me-1"></i> {{ __('Buat Penawaran') }}
                                    </a>
                                @elseif($status === 'draft')
                                    <a href="{{ route('supplier.quotations.create', $pr->id) }}" class="btn btn-sm btn-outline-secondary">
                                        <i class="bi bi-pencil me-1"></i> {{ __('Lanjutkan') }}
                                    </a>
                                @elseif($status === 'submitted' || $status === 'rejected')
                                    <a href="{{ route('supplier.quotations.show', $quotation->id) }}" class="btn btn-sm btn-outline-success">
                                        <i class="bi bi-eye me-1"></i> {{ __('Lihat') }}
                                    </a>
                                @endif
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
        $('#prTable').DataTable({
            language: { url: @json(app()->getLocale() === 'id' ? '//cdn.datatables.net/plug-ins/1.13.6/i18n/id.json' : '//cdn.datatables.net/plug-ins/1.13.6/i18n/en-GB.json') },
            pageLength: 25,
            ordering: false
        });
    });
</script>
@endpush
