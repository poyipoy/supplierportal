@extends('layouts.app')

@section('title', 'Claim Details #' . $claim->id . ' - ADASI Portal')
@section('page-title', 'Material Claim PO: ' . $claim->purchaseOrder->po_number)

@section('content')
<x-breadcrumb :items="[
    'Dashboard' => route('supplier.dashboard'),
    'Material Claim' => route('supplier.claims.index'),
    'Claim #' . $claim->id => '#'
]" />
<div class="tw-grid tw-gap-6">
    <x-ui.page-header :title="'Claim #' . $claim->id" :description="'Respond to the claim for ' . $claim->purchaseOrder->po_number . ' before the stated deadline.'" eyebrow="Supplier Portal">
        <x-slot:actions><x-ui.button :href="route('supplier.claims.index')" variant="ghost" size="sm"><x-ui.icon name="arrow-left" /> Back to Claim List</x-ui.button></x-slot:actions>
    </x-ui.page-header>

<div class="tw-grid tw-gap-6 xl:tw-grid-cols-[minmax(0,2fr)_minmax(18rem,1fr)]">
    <div class="tw-grid tw-min-w-0 tw-content-start tw-gap-6">
        {{-- Info Claim Demand --}}
        <x-ui.card title="Claim Request" description="Problem details and evidence submitted by ADASI.">
            <x-slot:actions><x-status-badge type="claim" :status="$claim->status" size="lg" /></x-slot:actions>
                
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
                                <a href="{{ route('attachments.show', $att->id) }}" target="_blank" class="d-block border rounded overflow-hidden shadow-sm tw-h-[100px]">
                                    <img src="{{ route('attachments.show', $att->id) }}" alt="{{ $att->file_name }}" class="w-100 h-100 tw-object-cover">
                                </a>
                            </div>
                        @endforeach
                    </div>
                @endif
        </x-ui.card>

        {{-- Form Response Supplier --}}
        <x-ui.card title="Supplier Response" description="Your response is final after submission.">
                @if($claim->status === 'pending')
                    <form action="{{ route('supplier.claims.respond', $claim) }}" method="POST" enctype="multipart/form-data" id="respondForm" class="tw-grid tw-gap-4">
                        @csrf
                        <x-ui.textarea name="supplier_response" label="Response & Explanation" :rows="5" required placeholder="Write your response or agreed resolution..." />
                        <x-ui.input type="file" name="attachments[]" label="Supporting Documents/Photos (Optional)" multiple accept=".jpg,.jpeg,.png,.pdf,.doc,.docx,.xls,.xlsx" helper="Official letter, transfer evidence, or replacement receipt; max 10MB per file." :error="$errors->first('attachments.*')" />

                        <div class="tw-flex tw-justify-end">
                            <x-ui.button type="button" id="btnSubmitRespond"><x-ui.icon name="send" /> Send Response</x-ui.button>
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
                                        <x-ui.icon name="file-text" size="lg" class="text-primary d-block mb-1" />
                                        <span class="small text-truncate d-block px-2">{{ $att->file_name }}</span>
                                    </a>
                                </div>
                            @endforeach
                        </div>
                    @endif
                @endif
        </x-ui.card>
    </div>

    <aside class="tw-grid tw-min-w-0 tw-content-start tw-gap-6">
        {{-- Item Material NG --}}
        <x-ui.card title="Problem Material List" padding="none">
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
        </x-ui.card>

        <x-ui.alert>If you need details about actual versus requested specifications, contact the related ADASI Purchasing team.</x-ui.alert>
    </aside>
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

        AdasiAlert.confirm({
            title: 'Send Claim Response?',
            text: "Ensure your response and offered resolution are appropriate. Responses cannot be changed after submission.",
            type: 'warning',
            confirmText: 'Yes, Send!',
            cancelText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                form.submit();
            }
        });
    });
</script>
@endpush
