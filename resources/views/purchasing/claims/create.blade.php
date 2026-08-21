@extends('layouts.app')

@section('title', 'Create Material Claim - ADASI Portal')
@section('page-title', 'Submit Material Claim')

@section('content')
<div class="tw-grid tw-gap-4">
    {{-- Breadcrumb & Compact Page Header --}}
    <x-ui.breadcrumb :items="[
        'Material Claims' => \App\Support\PurchasingNavigation::backUrl('purchasing.claims.index'),
        'Submit Claim' => null,
    ]" />

    <x-ui.page-header
        title="Submit Material Claim"
        eyebrow="Quality Discrepancy Action"
        :description="'Create a formal supplier claim from the NG inspection result for ' . $inspection->purchaseOrder->po_number . '.'"
    >
        <x-slot:actions>
            <x-ui.button :href="\App\Support\PurchasingNavigation::backUrl('purchasing.claims.index')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>Back to Claim List</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <form action="{{ route('purchasing.claims.store') }}" method="POST" id="claimForm">
        @csrf
        <input type="hidden" name="return_url" value="{{ request('return_url') }}">
        <input type="hidden" name="inspection_id" value="{{ $inspection->id }}">

        <div class="tw-grid tw-items-start tw-gap-4 lg:tw-grid-cols-[minmax(0,2fr)_minmax(19rem,1fr)]">
            {{-- Form Column --}}
            <div class="tw-grid tw-gap-4">
                <x-ui.form-section
                    title="Claim Particulars"
                    description="Detail the material defect, the desired compensatory resolution, and the deadline for supplier response."
                >
                    <div class="tw-grid tw-gap-4">
                        <x-ui.textarea
                            name="description"
                    label="Problem Description and Discrepancy"
                            :rows="4"
                            required
                            placeholder="Detail exactly which material failed inspection, dimensions/visual flaws observed, and operational impact..."
                        />
                        <x-ui.textarea
                            name="resolution_expected"
                            label="Expected Resolution / Remedy"
                            :rows="3"
                            required
                            placeholder="Example: Full replacement of defective lot, credit note refund, or supplier repair on site..."
                        />
                        <div class="tw-grid tw-gap-3 sm:tw-grid-cols-2">
                            <x-ui.input
                                type="date"
                                name="deadline"
                                label="Supplier Response Deadline"
                                :min="date('Y-m-d', strtotime('+1 day'))"
                                helper="Provide reasonable business days for supplier investigation and response."
                                required
                            />
                        </div>
                    </div>
                </x-ui.form-section>

                <x-ui.action-bar class="tw-mt-2">
                    <x-slot:left>
                        <x-ui.button :href="\App\Support\PurchasingNavigation::backUrl('purchasing.claims.index')" variant="ghost" size="sm">
                            <x-ui.icon name="arrow-left" size="sm" />
                            <span>Cancel</span>
                        </x-ui.button>
                    </x-slot:left>
                    <x-slot:right>
                        <x-ui.button type="submit" variant="danger" size="sm">
                            <x-ui.icon name="send" size="sm" />
                            <span>Send Claim to Supplier</span>
                        </x-ui.button>
                    </x-slot:right>
                </x-ui.action-bar>
            </div>

            {{-- QC Reference Column --}}
            <aside class="tw-grid tw-gap-4">
                <x-ui.card title="QC Inspection Reference">
                    <x-slot:actions>
                        <x-ui.button :href="route('qc.inspections.show', $inspection)" target="_blank" variant="ghost" size="sm">
                            <x-ui.icon name="external-link" size="sm" />
                        </x-ui.button>
                    </x-slot:actions>

                    <div class="tw-grid tw-gap-2.5 mb-3">
                        <div class="p-2 tw-bg-surface-low border rounded">
                            <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">PO Number</div>
                            <div class="fw-bold tw-text-on-surface tw-text-ui-sm tw-mt-0.5">{{ $inspection->purchaseOrder->po_number }}</div>
                        </div>
                        <div class="p-2 tw-bg-surface-low border rounded">
                            <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">Supplier</div>
                            <div class="fw-semibold tw-text-on-surface tw-text-ui-sm tw-mt-0.5">{{ $inspection->purchaseOrder->supplier->name }}</div>
                        </div>
                        <div class="p-2 tw-bg-surface-low border rounded">
                            <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">Inspection Date</div>
                            <div class="fw-semibold tw-text-on-surface tw-text-ui-sm tw-mt-0.5">{{ $inspection->inspected_at->format('d M Y') }}</div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <div class="fw-bold text-danger tw-text-ui-xs tw-uppercase mb-1">Defective Items (NG)</div>
                        <ul class="list-group list-group-flush border rounded overflow-hidden">
                            @foreach($inspection->items->where('status', 'ng') as $item)
                                <li class="list-group-item py-2 tw-px-2.5 tw-text-ui-xs">
                                    <span class="fw-bold tw-text-on-surface d-block">{{ $item->prItem->material_name }}</span>
                                    @if($item->notes)
                                        <span class="tw-text-on-surface-variant fst-italic">QC Remarks: {{ $item->notes }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>

                    @if($inspection->attachments->count() > 0)
                        <div>
                            <div class="fw-bold tw-text-on-surface-variant tw-text-ui-xs tw-uppercase mb-1">QC Evidence Photos</div>
                            <div class="row g-2">
                                @foreach($inspection->attachments as $att)
                                    <div class="col-4">
                                        <a href="{{ route('attachments.show', $att->id) }}" target="_blank" class="d-block border rounded overflow-hidden tw-h-20 tw-bg-surface-low">
                                            <img src="{{ route('attachments.show', $att->id) }}" alt="{{ $att->file_name }}" class="w-100 h-100 tw-object-cover">
                                        </a>
                                    </div>
                                @endforeach
                            </div>
                            <div class="tw-text-outline tw-text-ui-xs tw-mt-1.5">Evidence photos are automatically attached to supplier claim notice.</div>
                        </div>
                    @endif
                </x-ui.card>
            </aside>
        </div>
    </form>
</div>
@endsection
