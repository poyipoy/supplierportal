@extends('layouts.app')

@section('title', 'Klaim Material — ADASI Portal')
@section('page-title', 'Klaim Material')

@section('content')
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white pt-3 pb-0 border-bottom-0">
        <ul class="nav nav-tabs border-bottom-0" id="claimTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active fw-medium px-4 pb-3" id="action-tab" data-bs-toggle="tab" data-bs-target="#action" type="button" role="tab">
                    Perlu Tindakan <span class="badge bg-danger ms-2">{{ $actionNeeded->count() }}</span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link fw-medium px-4 pb-3" id="history-tab" data-bs-toggle="tab" data-bs-target="#history" type="button" role="tab">
                    Riwayat Klaim
                </button>
            </li>
        </ul>
    </div>
    <div class="card-body border-top">
        <div class="tab-content" id="claimTabsContent">
            {{-- Tab: Perlu Tindakan --}}
            <div class="tab-pane fade show active" id="action" role="tabpanel">
                <div class="alert alert-info small mb-4">
                    <i class="bi bi-info-circle-fill me-1"></i> Daftar PO di bawah ini telah diinspeksi oleh QC dan berstatus NG (Not Good). Silakan ajukan klaim kepada supplier terkait.
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle" id="actionTable" style="width: 100%;">
                        <thead class="table-light">
                            <tr>
                                <th>Nomor PO</th>
                                <th>Supplier</th>
                                <th>Tanggal Inspeksi</th>
                                <th class="text-center">Status PO</th>
                                <th class="text-end">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($actionNeeded as $po)
                            <tr>
                                <td class="fw-bold">{{ $po->po_number }}</td>
                                <td>{{ $po->quotation->supplier->name }}</td>
                                <td>{{ $po->qcInspections->last()->inspected_at->format('d M Y') }}</td>
                                <td class="text-center"><span class="badge bg-danger text-uppercase">{{ str_replace('_', ' ', $po->status) }}</span></td>
                                <td class="text-end">
                                    <a href="{{ route('purchasing.claims.create', $po->qcInspections->last()->id) }}" class="btn btn-sm btn-danger">
                                        <i class="bi bi-exclamation-octagon me-1"></i> Buat Klaim
                                    </a>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Tab: Riwayat Klaim --}}
            <div class="tab-pane fade" id="history" role="tabpanel">
                <div class="table-responsive">
                    <table class="table table-hover align-middle" id="historyTable" style="width: 100%;">
                        <thead class="table-light">
                            <tr>
                                <th>ID Klaim</th>
                                <th>Nomor PO</th>
                                <th>Supplier</th>
                                <th>Tanggal Diajukan</th>
                                <th class="text-center">Status Klaim</th>
                                <th class="text-end">Aksi</th>
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
                            <tr>
                                <td class="fw-medium">#{{ $claim->id }}</td>
                                <td>{{ $claim->purchaseOrder->po_number }}</td>
                                <td>{{ $claim->purchaseOrder->quotation->supplier->name }}</td>
                                <td>{{ $claim->created_at->format('d M Y') }}</td>
                                <td class="text-center"><span class="badge {{ $badgeClass }} text-uppercase">{{ ucwords(str_replace('_', ' ', $claim->status)) }}</span></td>
                                <td class="text-end">
                                    <a href="{{ route('purchasing.claims.show', $claim->id) }}" class="btn btn-sm btn-outline-primary">
                                        Detail
                                    </a>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    $(document).ready(function() {
        $('#actionTable, #historyTable').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/id.json' },
            pageLength: 25,
            ordering: false
        });
    });
</script>
@endpush
