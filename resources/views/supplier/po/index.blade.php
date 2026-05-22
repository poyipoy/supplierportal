@extends('layouts.app')

@section('title', 'Daftar Purchase Order — ADASI Portal')
@section('page-title', 'Purchase Order Saya')

@section('content')
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3">
        <h5 class="mb-0 fw-semibold">Daftar Purchase Order yang Diterima</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle" id="poTable">
                <thead class="table-light">
                    <tr>
                        <th>Nomor PO</th>
                        <th>Periode</th>
                        <th class="text-end">Total</th>
                        <th class="text-center">Status</th>
                        <th>Estimasi Kedatangan</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    $(document).ready(function() {
        $('#poTable').DataTable({
            processing: true,
            serverSide: true,
            ajax: '{{ route("supplier.purchase-orders.index") }}',
            columns: [
                { data: 'po_number_display', name: 'po_number', className: 'fw-bold' },
                { data: 'period_name', name: 'period_name', orderable: false },
                { data: 'total_idr', name: 'total_idr', className: 'text-end fw-medium', orderable: false, searchable: false },
                { data: 'status_badge', name: 'status', className: 'text-center', searchable: false },
                { data: 'estimated_date', name: 'estimated_arrival' },
                { data: 'action', name: 'action', orderable: false, searchable: false, className: 'text-end' }
            ],
            language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/id.json' },
            pageLength: 25,
            order: []
        });
    });
</script>
@endpush
