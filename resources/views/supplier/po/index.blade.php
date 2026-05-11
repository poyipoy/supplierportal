@extends('layouts.app')

@section('title', 'Daftar Purchase Order — ADASI Portal')
@section('page-title', __('Purchase Order Saya'))

@section('content')
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3">
        <h5 class="mb-0 fw-semibold">{{ __('Daftar Purchase Order yang Diterima') }}</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle" id="poTable">
                <thead class="table-light">
                    <tr>
                        <th>{{ __('Nomor PO') }}</th>
                        <th>{{ __('Periode') }}</th>
                        <th class="text-end">{{ __('Total') }}</th>
                        <th class="text-center">{{ __('Status') }}</th>
                        <th>{{ __('Estimasi Kedatangan') }}</th>
                        <th class="text-end">{{ __('Aksi') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($purchaseOrders as $po)
                        @php
                            $rate = $po->quotation->exchange_rate;
                            $totalIdr = 0;
                            foreach($po->quotation->items as $item) {
                                $totalIdr += $item->amount * ($rate ? $rate->rate_to_idr : 1);
                            }
                            $badgeClass = match($po->status) {
                                'active' => 'bg-primary',
                                'waiting_qc' => 'bg-warning text-dark',
                                'completed' => 'bg-success',
                                default => 'bg-secondary'
                            };
                            $statusLabel = match($po->status) {
                                'active' => __('Active'),
                                'waiting_qc' => __('Waiting QC'),
                                'completed' => __('Completed'),
                                default => __(ucwords(str_replace('_', ' ', $po->status))),
                            };
                        @endphp
                        <tr>
                            <td class="fw-bold">{{ $po->po_number }}</td>
                            <td>{{ $po->quotation->purchaseRequirement->period->name ?? '-' }}</td>
                            <td class="text-end fw-medium">Rp {{ number_format($totalIdr, 0, ',', '.') }}</td>
                            <td class="text-center">
                                <span class="badge {{ $badgeClass }} text-uppercase" style="font-size: 0.7rem;">{{ $statusLabel }}</span>
                            </td>
                            <td>{{ $po->estimated_arrival ? $po->estimated_arrival->format('d M Y') : '-' }}</td>
                            <td class="text-end">
                                <a href="{{ route('supplier.purchase-orders.show', $po->id) }}" class="btn btn-sm btn-outline-info">
                                    <i class="bi bi-eye"></i> {{ __('Detail') }}
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
        $('#poTable').DataTable({
            language: { url: @json(app()->getLocale() === 'id' ? '//cdn.datatables.net/plug-ins/1.13.6/i18n/id.json' : '//cdn.datatables.net/plug-ins/1.13.6/i18n/en-GB.json') },
            pageLength: 25,
            ordering: false
        });
    });
</script>
@endpush
