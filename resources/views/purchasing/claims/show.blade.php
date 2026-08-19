@extends('layouts.app')

@section('title', 'Claim Details #' . $claim->id . ' - ADASI Portal')
@section('page-title', 'Material Claim Details')

@section('content')
<x-breadcrumb :items="[
    'Dashboard' => route('purchasing.dashboard'),
    'Material Claim' => route('purchasing.claims.index'),
    'Claim #' . $claim->id => '#'
]" />
<div class="tw-grid tw-gap-6">
    <x-ui.page-header :title="'Claim #' . $claim->id" :description="'Material claim for ' . $claim->purchaseOrder->po_number . ' from ' . $claim->purchaseOrder->supplier->name . '.'" eyebrow="Material Claim Details">
        <x-slot:actions><x-ui.button :href="\App\Support\PurchasingNavigation::backUrl('purchasing.claims.index')" variant="ghost" size="sm"><x-slot:leading><i class="bi bi-arrow-left"></i></x-slot:leading>Back to Claim List</x-ui.button></x-slot:actions>
    </x-ui.page-header>

<div class="tw-grid tw-gap-6 xl:tw-grid-cols-[minmax(0,2fr)_minmax(18rem,1fr)]">
    <div class="tw-grid tw-min-w-0 tw-content-start tw-gap-6">
        {{-- Claim Details --}}
        <x-ui.card :title="'Claim #' . $claim->id">
            <x-slot:actions><x-status-badge type="claim" :status="$claim->status" size="lg" /></x-slot:actions>
                <div class="row mb-3">
                    <div class="col-md-3 text-muted small">Number PO</div>
                    <div class="col-md-9 fw-bold">{{ $claim->purchaseOrder->po_number }}</div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-3 text-muted small">Supplier</div>
                    <div class="col-md-9 fw-medium">{{ $claim->purchaseOrder->supplier->name }}</div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-3 text-muted small">Submitted By</div>
                    <div class="col-md-9 fw-medium">{{ $claim->submitter->name }} <span class="text-muted small">({{ $claim->created_at->format('d M Y, H:i') }})</span></div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-3 text-muted small">Deadline</div>
                    <div class="col-md-9 fw-medium text-danger">{{ $claim->deadline->format('d F Y') }}</div>
                </div>
                
                <hr>

                <div class="mb-4">
                    <h6 class="fw-bold small text-uppercase text-muted mb-2">Problem Description</h6>
                    <div class="p-3 bg-light rounded border">{{ $claim->description }}</div>
                </div>
                
                <div class="mb-4">
                    <h6 class="fw-bold small text-uppercase text-muted mb-2">Expected Resolution</h6>
                    <div class="p-3 bg-light rounded border">{{ $claim->resolution_expected }}</div>
                </div>

                @if($claim->inspection->attachments->count() > 0)
                    <h6 class="fw-bold small text-uppercase text-muted mb-2">QC Evidence Attachments</h6>
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
        </x-ui.card>

        {{-- Supplier Response --}}
        @if($claim->status !== 'pending')
        <x-ui.card title="Supplier Response" variant="tonal">
                <div class="mb-2 text-muted small">Responded at: {{ $claim->updated_at->format('d M Y, H:i') }}</div>
                <div class="p-3 bg-light rounded border mb-3">
                    {{ $claim->supplier_response ?? 'No response text.' }}
                </div>

                @if($claim->attachments && $claim->attachments->count() > 0)
                    <h6 class="fw-bold small text-uppercase text-muted mb-2">Resolution Documents/Photos</h6>
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
        </x-ui.card>
        @endif

    </div>

    <aside class="tw-grid tw-min-w-0 tw-content-start tw-gap-6">
        {{-- Action Card --}}
        <x-ui.card title="Claim Action" description="Actions are available only for the current claim state.">
                @if($claim->status === 'pending')
                    <x-ui.alert tone="warning">Waiting for the supplier response. Deadline: {{ $claim->deadline->format('d M Y') }}</x-ui.alert>
                @elseif($claim->status === 'responded')
                    <x-ui.alert class="tw-mb-3">Supplier has provided a response. Is the solution acceptable?</x-ui.alert>
                    <form action="{{ route('purchasing.claims.resolve', $claim) }}" method="POST">
                        @csrf
                        <x-ui.button type="submit" class="tw-mb-2 tw-w-full"><x-slot:leading><i class="bi bi-check2-circle"></i></x-slot:leading>Mark Completed (Resolved)</x-ui.button>
                    </form>
                    <x-ui.button disabled variant="danger" class="tw-w-full" title="Escalation feature is not active yet"><x-slot:leading><i class="bi bi-exclamation-triangle"></i></x-slot:leading>Escalation</x-ui.button>
                @elseif($claim->status === 'resolved')
                    <x-ui.alert tone="success">This claim has been declared completed and resolved.</x-ui.alert>
                @endif
        </x-ui.card>

        {{-- QC Reference --}}
        <x-ui.card title="NG Material Items" padding="none">
                <ul class="list-group list-group-flush">
                    @foreach($claim->inspection->items->where('status', 'ng') as $item)
                        <li class="list-group-item small">
                            <span class="fw-bold d-block">{{ $item->prItem->material_name }}</span>
                            @if($item->notes)
                                <span class="text-muted fst-italic">{{ $item->notes }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
                <div class="p-3 text-center border-top">
                    <x-ui.button :href="route('qc.inspections.show', $claim->inspection)" target="_blank" variant="ghost" size="sm" class="tw-w-full">View QC Report Details</x-ui.button>
                </div>
        </x-ui.card>
    </aside>
</div>
</div>
@endsection
