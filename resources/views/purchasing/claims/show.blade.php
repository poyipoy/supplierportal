@extends('layouts.app')

@section('title', 'Claim Details #' . $claim->id . ' - ADASI Portal')
@section('page-title', 'Material Claim Details')

@section('content')
<div class="tw-grid tw-gap-4">
    {{-- Breadcrumb & Compact Page Header --}}
    <x-ui.breadcrumb :items="[
        'Dashboard' => route('purchasing.dashboard'),
        'Material Claims' => route('purchasing.claims.index'),
        'Claim #' . $claim->id => null,
    ]" />

    <x-ui.page-header
        :title="'Claim #' . $claim->id"
        eyebrow="Material Claim Details"
        :description="'Material claim for ' . $claim->purchaseOrder->po_number . ' from ' . $claim->purchaseOrder->supplier->name . '.'"
    >
        <x-slot:actions>
            <x-status-badge type="claim" :status="$claim->status" size="lg" />
            <x-ui.button :href="\App\Support\PurchasingNavigation::backUrl('purchasing.claims.index')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>Back to Claim List</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="tw-grid tw-items-start tw-gap-4 lg:tw-grid-cols-[minmax(0,2fr)_minmax(19rem,1fr)]">
        {{-- Main Column --}}
        <div class="tw-grid tw-min-w-0 tw-gap-4">
            {{-- Claim Details Card --}}
            <x-ui.card :title="'Claim Particulars #' . $claim->id">
                <div class="tw-grid tw-gap-3 sm:tw-grid-cols-2 lg:tw-grid-cols-4 mb-4">
                    <div class="tw-p-2.5 tw-bg-surface-low border rounded">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">PO Number</div>
                        <div class="fw-bold tw-text-on-surface tw-text-ui-sm tw-mt-0.5">{{ $claim->purchaseOrder->po_number }}</div>
                    </div>
                    <div class="tw-p-2.5 tw-bg-surface-low border rounded">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">Supplier</div>
                        <div class="fw-semibold tw-text-on-surface tw-text-ui-sm tw-mt-0.5">{{ $claim->purchaseOrder->supplier->name }}</div>
                    </div>
                    <div class="tw-p-2.5 tw-bg-surface-low border rounded">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">Submitted By</div>
                        <div class="fw-semibold tw-text-on-surface tw-text-ui-sm tw-mt-0.5">{{ $claim->submitter->name }}</div>
                        <div class="tw-text-outline tw-text-ui-xs">{{ $claim->created_at->format('d M Y, H:i') }}</div>
                    </div>
                    <div class="tw-p-2.5 tw-bg-surface-low border rounded">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">Response Deadline</div>
                        <div class="fw-bold text-danger tw-text-ui-sm tw-mt-0.5">{{ $claim->deadline->format('d F Y') }}</div>
                    </div>
                </div>

                <div class="mb-4">
                    <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase mb-1">Problem Description</div>
                    <div class="p-3 tw-bg-surface-low rounded border tw-text-on-surface tw-text-ui-sm tw-whitespace-pre-line">{{ $claim->description }}</div>
                </div>

                <div class="mb-4">
                    <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase mb-1">Expected Resolution</div>
                    <div class="p-3 tw-bg-surface-low rounded border tw-text-on-surface tw-text-ui-sm tw-whitespace-pre-line">{{ $claim->resolution_expected }}</div>
                </div>

                @if($claim->inspection->attachments->count() > 0)
                    <div>
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase mb-2">QC Evidence Attachments</div>
                        <div class="row g-2">
                            @foreach($claim->inspection->attachments as $att)
                                <div class="col-4 col-md-3 col-lg-2">
                                    <a href="{{ route('attachments.show', $att->id) }}" class="d-block border rounded overflow-hidden tw-h-24 tw-bg-surface-low image-preview-trigger" title="{{ $att->file_name }}">
                                        <img src="{{ route('attachments.show', $att->id) }}" alt="{{ $att->file_name }}" class="w-100 h-100 tw-object-cover">
                                    </a>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </x-ui.card>

            {{-- Supplier Response --}}
            @if($claim->status !== 'pending')
            <x-ui.card title="Supplier Response and Resolution" description="Formal reply and evidence submitted by supplier.">
                    <div class="tw-text-on-surface-variant tw-text-ui-xs mb-2">Responded: {{ $claim->updated_at->format('d M Y, H:i') }}</div>
                    <div class="p-3 tw-bg-surface-low rounded border tw-text-on-surface tw-text-ui-sm tw-whitespace-pre-line mb-3">
                        {{ $claim->supplier_response ?? 'No written response text provided.' }}
                    </div>

                    @if($claim->attachments && $claim->attachments->count() > 0)
                        <div>
                            <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase mb-2">Supplier Attachments</div>
                            <div class="row g-2">
                                @foreach($claim->attachments as $att)
                                    @php
                                        $isImage = str_starts_with($att->file_type ?? '', 'image/') || preg_match('/\.(jpe?g|png|webp|gif|bmp|svg)$/i', $att->file_name);
                                    @endphp
                                    <div class="col-6 col-md-4">
                                        <a href="{{ route('attachments.show', $att->id) }}" {{ $isImage ? 'class=image-preview-trigger' : 'target=_blank' }} class="d-flex align-items-center gap-2 tw-p-2.5 border rounded text-decoration-none tw-bg-surface hover:tw-bg-surface-low" title="{{ $att->file_name }}">
                                            <x-ui.icon :name="$isImage ? 'image' : 'file-text'" size="sm" class="text-primary flex-shrink-0" />
                                            <span class="tw-text-ui-xs text-truncate tw-text-on-surface fw-medium">{{ $att->file_name }}</span>
                                        </a>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </x-ui.card>
            @endif
        </div>

        {{-- Sidebar Column --}}
        <aside class="tw-grid tw-gap-4">
            {{-- Action Card --}}
            <x-ui.card title="Claim Resolution Action" description="Actions based on supplier response state.">
                @if($claim->status === 'pending')
                    <x-ui.alert tone="warning" title="Supplier response pending">Response deadline: {{ $claim->deadline->format('d M Y') }}.</x-ui.alert>
                @elseif($claim->status === 'responded')
                    <x-ui.alert tone="info" title="Supplier response received" class="tw-mb-3">Review the proposed remedy and mark the claim as resolved if it is satisfactory.</x-ui.alert>
                    <form action="{{ route('purchasing.claims.resolve', $claim) }}" method="POST">
                        @csrf
                        <x-ui.button type="submit" size="sm" class="tw-mb-2 tw-w-full">
                            <x-slot:leading><x-ui.icon name="circle-check" /></x-slot:leading>
                            Mark as Resolved
                        </x-ui.button>
                    </form>
                @elseif($claim->status === 'resolved')
                    <x-ui.alert tone="success" title="Claim resolved">This claim has been completed and marked as resolved.</x-ui.alert>
                @endif
            </x-ui.card>

            {{-- QC Reference NG Items --}}
            <x-ui.card title="Defective Items (NG)" padding="none">
                <ul class="list-group list-group-flush">
                    @foreach($claim->inspection->items->where('status', 'ng') as $item)
                        <li class="list-group-item py-2 px-3 tw-text-ui-xs">
                            <span class="fw-bold tw-text-on-surface d-block">{{ $item->prItem->material_name }}</span>
                            @if($item->notes)
                                <span class="tw-text-on-surface-variant fst-italic">QC Notes: {{ $item->notes }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
                <div class="tw-p-2.5 text-center border-top tw-bg-surface-low">
                    <x-ui.button :href="\App\Support\PurchasingNavigation::toRoute('qc.inspections.show', $claim->inspection)" variant="ghost" size="sm">
                        <x-ui.icon name="external-link" size="sm" class="me-1" />
                        View Full QC Inspection Report
                    </x-ui.button>
                </div>
            </x-ui.card>
        </aside>
    </div>
</div>
@endsection
