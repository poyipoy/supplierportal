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
                                $po->status === 'claim_needed' => 'bg-danger',
                                $po->status === 'completed' => 'bg-success',
                                default => 'bg-secondary'
                            };
                            $statusLabel = match(true) {
                                $po->is_overdue => 'Overdue',
                                $po->status === 'active' => 'Active',
                                $po->status === 'waiting_qc' => 'Waiting QC',
                                $po->status === 'claim_needed' => 'Claim Needed',
                                $po->status === 'completed' => 'Completed',
                                default => ucwords(str_replace('_', ' ', $po->status)),
                            };
                            $pendingClaim = $po->materialClaims
                                ->where('status', 'pending')
                                ->sortByDesc('created_at')
                                ->first();
                            $latestClaim = $po->materialClaims
                                ->sortByDesc('created_at')
                                ->first();
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
                                <div class="d-inline-flex gap-1 justify-content-end flex-wrap">
                                    @if($pendingClaim)
                                        <a href="{{ route('supplier.claims.show', $pendingClaim->id) }}" class="btn btn-sm btn-danger">
                                            <i class="bi bi-reply me-1"></i> Respons Klaim
                                        </a>
                                    @elseif($latestClaim)
                                        <a href="{{ route('supplier.claims.show', $latestClaim->id) }}" class="btn btn-sm btn-outline-danger">
                                            <i class="bi bi-exclamation-octagon me-1"></i> Lihat Klaim
                                        </a>
                                    @endif
                                    <a href="{{ route('supplier.purchase-orders.show', $po->id) }}" class="btn btn-sm btn-outline-info">
                                        <i class="bi bi-eye"></i> Detail
                                    </a>
                                </div>
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
