@extends('layouts.app')

@section('title', 'QC Inspections — ADASI Portal')
@section('page-title', 'QC Inspections')

@section('content')
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white pt-3 pb-0 border-bottom-0 d-flex justify-content-between align-items-start">
        <ul class="nav nav-tabs border-bottom-0" id="inspectionTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active fw-medium px-4 pb-3" id="waiting-tab" data-bs-toggle="tab" data-bs-target="#waiting" type="button" role="tab">
                    Menunggu Inspeksi <span class="badge bg-warning text-dark ms-2">{{ $waitingPOs->count() }}</span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link fw-medium px-4 pb-3" id="history-tab" data-bs-toggle="tab" data-bs-target="#history" type="button" role="tab">
                    Riwayat Inspeksi
                </button>
            </li>
        </ul>
        <a href="{{ route('qc.export.inspections', request()->all()) }}" class="btn btn-success btn-sm align-self-center">
            <i class="bi bi-file-earmark-excel me-1"></i> Export Excel
        </a>
    </div>
    <div class="card-body border-top">
        <div class="tab-content" id="inspectionTabsContent">
            {{-- Tab: Menunggu Inspeksi --}}
            <div class="tab-pane fade show active" id="waiting" role="tabpanel">
                <div class="table-responsive">
                    <table class="table table-hover align-middle" id="waitingTable" style="width: 100%;">
                        <thead class="table-light">
                            <tr>
                                <th>No. PO</th>
                                <th>Supplier</th>
                                <th>Tanggal Material Tiba</th>
                                <th>Jumlah Item</th>
                                <th class="text-end">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($waitingPOs as $po)
                            <tr>
                                <td class="fw-bold">{{ $po->po_number }}</td>
                                <td>{{ $po->quotation->supplier->name }}</td>
                                <td>{{ $po->actual_arrival ? $po->actual_arrival->format('d M Y') : '-' }}</td>
                                <td>{{ $po->quotation->items->count() }} Item</td>
                                <td class="text-end">
                                    <a href="{{ route('qc.inspections.create', $po->id) }}" class="btn btn-sm btn-primary" style="background-color: var(--adasi-blue);">
                                        <i class="bi bi-clipboard-check me-1"></i> Mulai Inspeksi
                                    </a>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Tab: Riwayat Inspeksi --}}
            <div class="tab-pane fade" id="history" role="tabpanel">
                <div class="table-responsive">
                    <table class="table table-hover align-middle" id="historyTable" style="width: 100%;">
                        <thead class="table-light">
                            <tr>
                                <th>No. PO</th>
                                <th>Supplier</th>
                                <th>Tanggal Inspeksi</th>
                                <th class="text-center">Status</th>
                                <th>Diinspeksi Oleh</th>
                                <th class="text-end">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($history as $insp)
                            <tr>
                                <td class="fw-bold">{{ $insp->purchaseOrder->po_number }}</td>
                                <td>{{ $insp->purchaseOrder->quotation->supplier->name }}</td>
                                <td>{{ $insp->inspected_at->format('d M Y, H:i') }}</td>
                                <td class="text-center">
                                    @if($insp->status === 'ok')
                                        <span class="badge bg-success">OK</span>
                                    @else
                                        <span class="badge bg-danger">NG</span>
                                    @endif
                                </td>
                                <td>{{ $insp->inspector->name }}</td>
                                <td class="text-end">
                                    <a href="{{ route('qc.inspections.show', $insp->id) }}" class="btn btn-sm btn-outline-info">
                                        <i class="bi bi-eye"></i> Detail
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
        $('#waitingTable, #historyTable').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/id.json' },
            pageLength: 25,
            ordering: false
        });
    });
</script>
@endpush
