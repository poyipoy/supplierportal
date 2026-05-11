@extends('layouts.app')

@section('title', 'Daftar Purchase Order — ADASI Portal')
@section('page-title', __('Purchase Order'))

@section('content')
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h5 class="mb-0 fw-semibold">{{ __('Daftar Purchase Order') }}</h5>
        <a href="{{ route('purchasing.export.purchase-orders', request()->all()) }}" class="btn btn-success btn-sm">
            <i class="bi bi-file-earmark-excel me-1"></i> {{ __('Export Excel') }}
        </a>
    </div>
    <div class="card-body">
        {{-- Filters --}}
        <form method="GET" action="{{ route('purchasing.purchase-orders.index') }}" class="row g-3 mb-4">
            <div class="col-md-3">
                <label class="form-label small fw-medium">{{ __('Status') }}</label>
                <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">{{ __('Semua Status') }}</option>
                    <option value="active" {{ request('status') == 'active' ? 'selected' : '' }}>{{ __('Active') }}</option>
                    <option value="waiting_qc" {{ request('status') == 'waiting_qc' ? 'selected' : '' }}>{{ __('Waiting QC') }}</option>
                    <option value="completed" {{ request('status') == 'completed' ? 'selected' : '' }}>{{ __('Completed') }}</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-medium">{{ __('Supplier') }}</label>
                <select name="supplier_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">{{ __('Semua Supplier') }}</option>
                    @foreach($suppliers as $s)
                        <option value="{{ $s->id }}" {{ request('supplier_id') == $s->id ? 'selected' : '' }}>{{ $s->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <a href="{{ route('purchasing.purchase-orders.index') }}" class="btn btn-light btn-sm w-100">{{ __('Reset') }}</a>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-hover align-middle" id="poTable">
                <thead class="table-light">
                    <tr>
                        <th>{{ __('Nomor PO') }}</th>
                        <th>{{ __('Supplier') }}</th>
                        <th>{{ __('Periode') }}</th>
                        <th class="text-end">{{ __('Total IDR') }}</th>
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
                            $badgeClass = match(true) {
                                $po->is_overdue => 'bg-danger',
                                $po->status === 'active' => 'bg-primary',
                                $po->status === 'waiting_qc' => 'bg-warning text-dark',
                                $po->status === 'completed' => 'bg-success',
                                default => 'bg-secondary'
                            };
                            $statusLabel = match(true) {
                                $po->is_overdue => __('Overdue'),
                                $po->status === 'active' => __('Active'),
                                $po->status === 'waiting_qc' => __('Waiting QC'),
                                $po->status === 'completed' => __('Completed'),
                                default => __(ucwords(str_replace('_', ' ', $po->status)))
                            };
                        @endphp
                        <tr>
                            <td class="fw-bold">{{ $po->po_number }}</td>
                            <td>{{ $po->quotation->supplier->name }}</td>
                            <td>{{ $po->quotation->purchaseRequirement->period->name ?? '-' }}</td>
                            <td class="text-end fw-medium">Rp {{ number_format($totalIdr, 0, ',', '.') }}</td>
                            <td class="text-center">
                                <span class="badge {{ $badgeClass }} text-uppercase" style="font-size: 0.7rem;">{{ $statusLabel }}</span>
                            </td>
                            <td>{{ $po->estimated_arrival ? $po->estimated_arrival->format('d M Y') : '-' }}</td>
                            <td class="text-end">
                                <a href="{{ route('purchasing.purchase-orders.show', $po->id) }}" class="btn btn-sm btn-outline-info">
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
            language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/id.json' },
            pageLength: 25,
            ordering: false
        });
    });
</script>
@endpush
