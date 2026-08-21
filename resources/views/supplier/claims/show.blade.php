@extends('layouts.app')

@section('title', 'Claim Details #' . $claim->id . ' - ADASI Portal')
@section('page-title', 'Material Claim PO: ' . $claim->purchaseOrder->po_number)

@section('content')
<div class="tw-grid tw-gap-4">
    {{-- Breadcrumb & Compact Page Header --}}
    <x-ui.breadcrumb :items="[
        'Dashboard' => route('supplier.dashboard'),
        'Material Claims' => route('supplier.claims.index'),
        'Claim #' . $claim->id => null,
    ]" />

    <x-ui.page-header
        :title="'Claim #' . $claim->id"
        eyebrow="Quality Discrepancy"
        :description="'Review QC defect report and provide official response for order ' . $claim->purchaseOrder->po_number . '.' "
    >
        <x-slot:actions>
            <x-status-badge type="claim" :status="$claim->status" />
            <x-ui.button :href="route('supplier.claims.index')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" />
                <span>Back to Claims</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="tw-grid tw-items-start tw-gap-4 xl:tw-grid-cols-[minmax(0,2fr)_minmax(19rem,1fr)]">
        {{-- Main Column: Claim Demand & Supplier Response --}}
        <div class="tw-grid tw-min-w-0 tw-gap-4">
            {{-- ADASI Claim Demand Information --}}
            <x-ui.card title="ADASI Claim Request" description="Defect details and expected resolution recorded by Quality Control.">
                <div class="tw-grid tw-gap-3 sm:tw-grid-cols-2 mb-3">
                    <div class="tw-p-2.5 tw-bg-surface-low border rounded">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">Submitted Date</div>
                        <div class="fw-semibold tw-text-on-surface tw-text-ui-sm tw-mt-0.5">{{ $claim->created_at->format('d F Y') }}</div>
                    </div>
                    <div class="tw-p-2.5 tw-bg-surface-low border rounded">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">Response Deadline</div>
                        <div class="fw-bold text-danger tw-text-ui-sm tw-mt-0.5 d-flex align-items-center gap-1">
                        <x-ui.icon name="clock" size="sm" />
                            <span>{{ $claim->deadline->format('d F Y') }}</span>
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase tw-mb-1.5">Problem Description (QC Report)</div>
                    <div class="p-3 tw-bg-surface-low border rounded tw-text-on-surface tw-text-ui-xs leading-relaxed">
                        {{ $claim->description }}
                    </div>
                </div>

                <div class="mb-3">
                    <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase tw-mb-1.5">Expected Resolution from ADASI</div>
                    <div class="p-3 tw-bg-surface-low border rounded tw-text-on-surface tw-text-ui-xs leading-relaxed">
                        {{ $claim->resolution_expected }}
                    </div>
                </div>

                @if($claim->inspection->attachments->count() > 0)
                    <div>
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase mb-2">QC Photographic Evidence from ADASI</div>
                        <div class="tw-grid tw-grid-cols-2 sm:tw-grid-cols-3 md:tw-grid-cols-4 tw-gap-2">
                            @foreach($claim->inspection->attachments as $att)
                                <a href="{{ route('attachments.show', $att->id) }}" target="_blank" class="tw-block tw-h-24 tw-overflow-hidden tw-rounded-ui-sm tw-border tw-border-outline-variant tw-transition-opacity hover:tw-opacity-90">
                                    <img src="{{ route('attachments.show', $att->id) }}" alt="{{ $att->file_name }}" class="w-100 h-100 tw-object-cover">
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif
            </x-ui.card>

            {{-- Supplier Response Card / Form --}}
            <x-ui.card title="Supplier Response and Resolution" description="Your response is committed upon submission.">
                @if($claim->status === 'pending')
                    <form action="{{ route('supplier.claims.respond', $claim) }}" method="POST" enctype="multipart/form-data" id="respondForm" class="tw-grid tw-gap-3.5">
                        @csrf
                        <x-ui.textarea
                            name="supplier_response"
                            label="Official Explanation and Proposed Action"
                            :rows="4"
                            required
                            placeholder="Write your root-cause explanation or agreed corrective action (e.g. material replacement schedule, credit note)..."
                        />
                        <x-ui.input
                            type="file"
                            name="attachments[]"
                            label="Supporting Evidence / Official Letter (Optional)"
                            multiple
                            accept=".jpg,.jpeg,.png,.pdf,.doc,.docx,.xls,.xlsx"
                            helper="Official correspondence, replacement tracking, or test analysis; max 10MB per file."
                            :error="$errors->first('attachments.*')"
                        />

                        <div class="tw-flex tw-justify-end tw-pt-2 border-top">
                            <x-ui.button type="button" id="btnSubmitRespond">
                                <x-ui.icon name="send" size="sm" />
                                <span>Submit Official Response</span>
                            </x-ui.button>
                        </div>
                    </form>
                @else
                    <div class="tw-text-on-surface-variant tw-text-ui-xs mb-2">
                        Response submitted on: <strong>{{ $claim->updated_at->format('d M Y, H:i') }}</strong>
                    </div>
                    <div class="p-3 tw-bg-surface-low border rounded mb-3 tw-text-on-surface tw-text-ui-xs leading-relaxed">
                        {{ $claim->supplier_response }}
                    </div>

                    @if($claim->attachments && $claim->attachments->count() > 0)
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase mb-2">Attached Supplier Documents</div>
                        <div class="tw-grid tw-grid-cols-2 sm:tw-grid-cols-3 tw-gap-2">
                            @foreach($claim->attachments as $att)
                                <a href="{{ route('attachments.show', $att->id) }}" target="_blank" class="d-flex align-items-center gap-2 p-2 text-decoration-none tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface hover:tw-bg-surface-low tw-transition-colors">
                                    <x-ui.icon name="file-text" size="sm" class="text-primary flex-shrink-0" />
                                    <span class="tw-text-ui-xs tw-text-on-surface text-truncate">{{ $att->file_name }}</span>
                                </a>
                            @endforeach
                        </div>
                    @endif
                @endif
            </x-ui.card>
        </div>

        {{-- Sidebar Column: Problem Materials & Support --}}
        <aside class="tw-grid tw-gap-4">
            <x-ui.card title="Defective Items (QC NG)" padding="none">
                <div class="list-group list-group-flush">
                    @foreach($claim->inspection->items->where('status', 'ng') as $item)
                        <div class="list-group-item p-3">
                            <div class="fw-bold tw-text-on-surface tw-text-ui-xs">{{ $item->prItem->material_name }}</div>
                            @if($item->notes)
                                <div class="text-danger tw-text-ui-xs mt-1 d-flex align-items-start gap-1">
                <x-ui.icon name="circle-alert" size="sm" class="tw-mt-0.5 tw-shrink-0" />
                                    <span>QC Note: {{ $item->notes }}</span>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-ui.card>

            <x-ui.card title="Purchasing Support">
                <p class="tw-text-on-surface-variant tw-text-ui-xs mb-3">
                    If you require clarification regarding actual vs nominal dimensional discrepancies, contact the ADASI Purchasing officer.
                </p>
                <x-ui.button :href="route('supplier.conversations.index')" variant="outline" size="sm" class="tw-w-full">
                    <x-ui.icon name="message-square" size="sm" />
                    <span>Open Negotiation Chat</span>
                </x-ui.button>
            </x-ui.card>
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
            title: 'Send Official Response?',
            text: "Ensure your response and proposed resolution are accurate. Responses cannot be modified once sent.",
            type: 'warning',
            confirmText: 'Yes, Submit Response!',
            cancelText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                form.submit();
            }
        });
    });
</script>
@endpush
