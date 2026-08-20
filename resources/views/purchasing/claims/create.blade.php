@extends('layouts.app')

@section('title', 'Create Material Claim - ADASI Portal')
@section('page-title', 'Claim Submission Form')

@section('content')
<div class="tw-grid tw-gap-6">
    <x-ui.page-header title="Submit Material Claim" :description="'Create a supplier claim from the NG inspection for ' . $inspection->purchaseOrder->po_number . '.'" eyebrow="Purchasing">
        <x-slot:actions><x-ui.button :href="\App\Support\PurchasingNavigation::backUrl('purchasing.claims.index')" variant="ghost" size="sm"><i class="bi bi-arrow-left"></i> Back to Claim List</x-ui.button></x-slot:actions>
    </x-ui.page-header>

<form action="{{ route('purchasing.claims.store') }}" method="POST" id="claimForm">
    @csrf
    <input type="hidden" name="return_url" value="{{ request('return_url') }}">
    <input type="hidden" name="inspection_id" value="{{ $inspection->id }}">

    <div class="row g-4">
        <div class="col-lg-7">
            {{-- Form Claim --}}
            <x-ui.card title="Claim Details" description="Describe the issue, expected resolution, and response deadline clearly.">
                <div class="tw-grid tw-gap-4">
                    <x-ui.textarea name="description" label="Problem Description" :rows="4" required placeholder="Explain in detail which material has a problem and why..." />
                    <x-ui.textarea name="resolution_expected" label="Expected Resolution" :rows="3" required placeholder="Example: replacement goods, refund, etc." />
                    <x-ui.input type="date" name="deadline" label="Response Deadline" :min="date('Y-m-d', strtotime('+1 day'))" helper="Give reasonable time for the supplier to respond to this claim." required />
                </div>
            </x-ui.card>

            <div class="tw-mt-4 tw-flex tw-flex-wrap tw-justify-end tw-gap-2">
                <x-ui.button :href="\App\Support\PurchasingNavigation::backUrl('purchasing.claims.index')" variant="ghost">Cancel</x-ui.button>
                <x-ui.button type="submit" variant="danger"><i class="bi bi-send"></i> Send Claim to Supplier</x-ui.button>
            </div>
        </div>

        <div class="col-lg-5">
            {{-- QC Reference Info --}}
            <x-ui.card title="QC Inspection Reference" variant="tonal">
                <x-slot:actions><x-ui.button :href="route('qc.inspections.show', $inspection)" target="_blank" variant="ghost" size="sm">QC Details</x-ui.button></x-slot:actions>
                    <div class="mb-2">
                        <div class="text-muted small">Number PO</div>
                        <div class="fw-bold">{{ $inspection->purchaseOrder->po_number }}</div>
                    </div>
                    <div class="mb-2">
                        <div class="text-muted small">Supplier</div>
                        <div class="fw-medium">{{ $inspection->purchaseOrder->supplier->name }}</div>
                    </div>
                    <div class="mb-3">
                        <div class="text-muted small">Inspection Date</div>
                        <div class="fw-medium">{{ $inspection->inspected_at->format('d M Y') }}</div>
                    </div>

                    <h6 class="fw-bold small text-danger text-uppercase mb-2">Item NG (Not Good)</h6>
                    <ul class="list-group list-group-flush border rounded mb-3">
                        @foreach($inspection->items->where('status', 'ng') as $item)
                            <li class="list-group-item bg-transparent py-2 px-3 small">
                                <span class="fw-bold d-block">{{ $item->prItem->material_name }}</span>
                                @if($item->notes)
                                    <span class="text-muted fst-italic">QC Notes: {{ $item->notes }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>

                    @if($inspection->attachments->count() > 0)
                        <h6 class="fw-bold small text-muted text-uppercase mb-2">QC Photo Evidence</h6>
                        <div class="row g-2">
                            @foreach($inspection->attachments as $att)
                                <div class="col-4">
                                    <a href="{{ route('attachments.show', $att->id) }}" target="_blank" class="d-block border rounded overflow-hidden shadow-sm tw-h-20">
                                        <img src="{{ route('attachments.show', $att->id) }}" alt="{{ $att->file_name }}" class="w-100 h-100 tw-object-cover">
                                    </a>
                                </div>
                            @endforeach
                        </div>
                        <div class="form-text small mt-2">These photos will automatically be attached to the supplier page.</div>
                    @endif
            </x-ui.card>
        </div>
    </div>
</form>
</div>
@endsection
