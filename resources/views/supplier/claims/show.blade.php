@extends('layouts.app')

@section('title', 'Claim Details #' . $claim->id . ' - ADASI Portal')
@section('page-title', 'Material Claim PO: ' . $claim->purchaseOrder->po_number)

@section('content')
<x-breadcrumb :items="[
    'Dashboard' => route('supplier.dashboard'),
    'Material Claim' => route('supplier.claims.index'),
    'Claim #' . $claim->id => '#'
]" />
<div class="mb-3">
    <a href="{{ route('supplier.claims.index') }}" class="text-decoration-none text-muted small">
        <i class="bi bi-arrow-left me-1"></i> Back to Claim List
    </a>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        {{-- Info Claim Demand --}}
        <div class="card border-0 shadow-sm mb-4 border-top border-4 border-danger">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold mb-0 text-danger"><i class="bi bi-exclamation-octagon me-2"></i>Claim Demand</h5>
                    <x-status-badge type="claim" :status="$claim->status" size="lg" />
                </div>
                
                <div class="row mb-3">
                    <div class="col-md-3 text-muted small">Submitted Date</div>
                    <div class="col-md-9 fw-medium">{{ $claim->created_at->format('d F Y') }}</div>
                </div>
                <div class="row mb-4">
                    <div class="col-md-3 text-muted small">Deadline Response</div>
                    <div class="col-md-9 fw-bold text-danger">{{ $claim->deadline->format('d F Y') }}</div>
                </div>

                <div class="mb-3">
                    <h6 class="fw-bold small text-muted text-uppercase mb-2">Problem Description (Based on QC Report)</h6>
                    <div class="p-3 bg-light rounded border">{{ $claim->description }}</div>
                </div>
                
                <div class="mb-4">
                    <h6 class="fw-bold small text-muted text-uppercase mb-2">Expected Resolution from ADASI</h6>
                    <div class="p-3 bg-light rounded border">{{ $claim->resolution_expected }}</div>
                </div>

                @if($claim->inspection->attachments->count() > 0)
                    <h6 class="fw-bold small text-muted text-uppercase mb-2">QC Photo Evidence from ADASI</h6>
                    <div class="row g-2">
                        @foreach($claim->inspection->attachments as $att)
                            <div class="col-4 col-md-3 col-lg-2">
                                <a href="{{ route('attachments.show', $att->id) }}" target="_blank" class="d-block border rounded overflow-hidden shadow-sm" style="height: 100px;">
                                    <img src="{{ route('attachments.show', $att->id) }}" alt="{{ $att->file_name }}" class="w-100 h-100" style="object-fit: cover;">
                                </a>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        {{-- Form Response Supplier --}}
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-bold">Response Supplier</h6>
            </div>
            <div class="card-body">
                @if($claim->status === 'pending')
                    <form action="{{ route('supplier.claims.respond', $claim) }}" method="POST" enctype="multipart/form-data" id="respondForm">
                        @csrf
                        <div class="mb-3">
                            <label class="form-label fw-medium">Response & Explanation <span class="text-danger">*</span></label>
                            <textarea name="supplier_response" class="form-control @error('supplier_response') is-invalid @enderror" rows="5" required placeholder="Write your response or agreed resolution..."></textarea>
                            @error('supplier_response') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-medium">Supporting Documents/Photos (Optional)</label>
                            <input type="file" name="attachments[]" class="form-control" multiple accept=".jpg,.jpeg,.png,.pdf,.doc,.docx,.xls,.xlsx">
                            <div class="form-text small">Upload an official letter, refund transfer evidence, or replacement shipment receipt (max 10MB/file).</div>
                            @error('attachments.*') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                        </div>

                        <div class="d-flex justify-content-end">
                            <button type="button" class="btn btn-primary" style="background-color: var(--adasi-blue);" id="btnSubmitRespond">
                                <i class="bi bi-send me-1"></i> Send Response
                            </button>
                        </div>
                    </form>
                @else
                    <div class="mb-2 text-muted small">You have responded to this claim on: {{ $claim->updated_at->format('d M Y, H:i') }}</div>
                    <div class="p-3 bg-light rounded border mb-3">
                        {{ $claim->supplier_response }}
                    </div>

                    @if($claim->attachments && $claim->attachments->count() > 0)
                        <h6 class="fw-bold small text-uppercase text-muted mb-2">Attached Documents/Photos</h6>
                        <div class="row g-2">
                            @foreach($claim->attachments as $att)
                                <div class="col-4 col-md-3 col-lg-2">
                                    <a href="{{ route('attachments.show', $att->id) }}" target="_blank" class="d-block border rounded text-center py-3 text-decoration-none shadow-sm h-100 bg-white">
                                        <i class="bi bi-file-earmark-text fs-3 text-primary d-block mb-1"></i>
                                        <span class="small text-truncate d-block px-2">{{ $att->file_name }}</span>
                                    </a>
                                </div>
                            @endforeach
                        </div>
                    @endif
                @endif
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        {{-- Item Material NG --}}
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-bold">Problem Material List</h6>
            </div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush">
                    @foreach($claim->inspection->items->where('status', 'ng') as $item)
                        <li class="list-group-item">
                            <span class="fw-bold d-block">{{ $item->prItem->material_name }}</span>
                            @if($item->notes)
                                <span class="text-muted small fst-italic">QC Notes: {{ $item->notes }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>

        <div class="alert alert-light border small text-muted">
            <i class="bi bi-info-circle me-1"></i> If you need details about actual specifications versus requested specifications, contact the related ADASI Purchasing team.
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    $('#btnSubmitRespond').on('click', function() {
        const form = $('#respondForm')[0];
        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }

        Swal.fire({
            title: 'Send Claim Response?',
            text: "Ensure your response and offered resolution are appropriate. Responses cannot be changed after submission.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: 'var(--adasi-blue)',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, Send!',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                form.submit();
            }
        });
    });
</script>
@endpush
