@extends('layouts.app')

@section('title', __('shipments.titles.detail', ['number' => $shipment->shipment_number]))
@section('page-title', __('shipments.copy.shipment_details'))

@push('styles')
<style>
    .shipment-tracking-strip {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 0.75rem;
    }

    .shipment-tracking-step {
        padding: 0.75rem 1rem;
        background: var(--md-surface);
        border: 1px solid var(--md-outline-variant);
        border-radius: var(--md-shape-sm);
        position: relative;
    }

    .shipment-tracking-step.is-completed {
        border-color: var(--md-success);
        background: var(--md-surface-container-low);
    }

    .shipment-tracking-step.is-active {
        border-color: var(--md-primary);
        background: var(--md-primary-container);
    }

    .shipment-tracking-step.is-cancelled {
        border-color: var(--md-error);
        background: var(--md-error-container);
    }
</style>
@endpush

@section('content')
<div class="tw-grid tw-gap-4">
    {{-- 1. Breadcrumb & Page Header --}}
    <x-ui.breadcrumb :items="[
        __('purchasing.breadcrumbs.dashboard') => route('supplier.dashboard'),
        __('purchasing.breadcrumbs.shipments') => route('supplier.shipments.index'),
        $shipment->shipment_number => null,
    ]" />

    @php
        $pos = $shipment->purchaseOrders();
        $totalQty = (int) $shipment->items->sum('shipped_qty');
        $totalWeight = (float) $shipment->items->sum('actual_weight_kg');
        $hasQc = $shipment->qcInspections->isNotEmpty();
        $isCancelled = $shipment->status === 'cancelled';
        $isArrived = $shipment->status === 'arrived';
        $isSubmitted = $shipment->status === 'submitted';
        $isDraft = $shipment->status === 'draft';
    @endphp

    <x-ui.page-header
        :title="__('shipments.closure.title', ['number' => $shipment->shipment_number])"
        :eyebrow="__('shipments.copy.delivery_package_logistics')"
        :description="__('shipments.copy.consolidated_shipment_batch_details_shipping_documentation_upload_and_receiving_status')"
    >
        <x-slot:actions>
            <div class="tw-flex tw-flex-wrap tw-gap-2 align-items-center">
                <x-ui.status-chip :tone="\App\Support\StatusHelper::shipmentTone($shipment->status)" size="md">
                    {{ \App\Support\StatusHelper::shipmentLabel($shipment->status) }}
                </x-ui.status-chip>

                @if($isDraft)
                    <x-ui.button :href="route('supplier.shipments.edit', $shipment)" variant="outline" size="sm">
                        <x-slot:leading><x-ui.icon name="pencil" size="sm" /></x-slot:leading>
                        {{ __('shipments.copy.edit_draft_64d64e') }}
                    </x-ui.button>

                    <form method="POST" action="{{ route('supplier.shipments.submit', $shipment) }}" class="d-inline" id="submitShipmentForm">
                        @csrf
                        <x-ui.button type="button" variant="primary" size="sm" id="btnConfirmSubmit">
                            <x-slot:leading><x-ui.icon name="truck" size="sm" /></x-slot:leading>
                            {{ __('shipments.copy.submit_shipment') }}
                        </x-ui.button>
                    </form>
                @endif

                @if(in_array($shipment->status, ['draft', 'submitted'], true))
                    <form method="POST" action="{{ route('supplier.shipments.cancel', $shipment) }}" class="d-inline" id="cancelShipmentForm">
                        @csrf
                        <x-ui.button type="button" variant="danger" size="sm" id="btnConfirmCancel">
                            <x-slot:leading><x-ui.icon name="x-circle" size="sm" /></x-slot:leading>
                            {{ __('shipments.copy.cancel_shipment_f1cc5e') }}
                        </x-ui.button>
                    </form>
                @endif
            </div>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- 2. Visual Lifecycle Tracking Stepper --}}
    <div class="shipment-tracking-strip" aria-label="{{ __('shipments.copy.shipment_delivery_progress') }}">
        @if($isCancelled)
            <div class="shipment-tracking-step is-cancelled">
                <div class="tw-text-ui-xs fw-semibold text-danger">{{ __('shipments.copy.status') }}</div>
                <div class="fw-bold fs-6 text-danger">{{ __('shipments.copy.cancelled') }}</div>
                <div class="tw-text-ui-xs text-danger mt-1">{{ __('shipments.copy.delivery_batch_was_cancelled_and_balances_released') }}</div>
            </div>
        @else
            {{-- Step 1: Draft --}}
            <div class="shipment-tracking-step {{ $isDraft ? 'is-active' : 'is-completed' }}">
                <div class="d-flex align-items-center justify-content-between">
                    <span class="tw-text-ui-xs fw-semibold tw-text-on-surface-variant">{{ __('shipments.copy.step_1') }}</span>
                    <x-ui.icon :name="$isDraft ? 'circle-dot' : 'check'" size="sm" :class="$isDraft ? 'text-primary' : 'text-success'" />
                </div>
                <div class="fw-bold fs-6 tw-text-on-surface">{{ __('shipments.copy.draft_allocation') }}</div>
                <div class="tw-text-ui-xs tw-text-on-surface-variant mt-1">
                    {{ $regionalFormatter->timestamp($shipment->created_at, 'date') }}
                </div>
            </div>

            {{-- Step 2: In Transit --}}
            <div class="shipment-tracking-step {{ $isSubmitted ? 'is-active' : ($isArrived ? 'is-completed' : '') }}">
                <div class="d-flex align-items-center justify-content-between">
                    <span class="tw-text-ui-xs fw-semibold tw-text-on-surface-variant">{{ __('shipments.copy.step_2') }}</span>
                    @if($isArrived)
                        <x-ui.icon name="check" size="sm" class="text-success" />
                    @elseif($isSubmitted)
                        <x-ui.icon name="truck" size="sm" class="text-primary" />
                    @else
                        <x-ui.icon name="circle" size="sm" class="tw-text-outline" />
                    @endif
                </div>
                <div class="fw-bold fs-6 tw-text-on-surface">{{ __('shipments.copy.in_transit') }}</div>
                <div class="tw-text-ui-xs tw-text-on-surface-variant mt-1">
                    @if($shipment->shipment_date)
                        {{ __('shipments.audit_ui.dispatched_on', ['date' => $regionalFormatter->date($shipment->shipment_date, 'human')]) }}
                    @else
                        {{ __('shipments.audit_ui.awaiting_dispatch') }}
                    @endif
                </div>
            </div>

            {{-- Step 3: Arrived at Plant --}}
            <div class="shipment-tracking-step {{ $isArrived && !$hasQc ? 'is-active' : ($isArrived && $hasQc ? 'is-completed' : '') }}">
                <div class="d-flex align-items-center justify-content-between">
                    <span class="tw-text-ui-xs fw-semibold tw-text-on-surface-variant">{{ __('shipments.copy.step_3') }}</span>
                    @if($isArrived && $hasQc)
                        <x-ui.icon name="check" size="sm" class="text-success" />
                    @elseif($isArrived)
                        <x-ui.icon name="package-check" size="sm" class="text-primary" />
                    @else
                        <x-ui.icon name="circle" size="sm" class="tw-text-outline" />
                    @endif
                </div>
                <div class="fw-bold fs-6 tw-text-on-surface">{{ __('shipments.copy.arrived_at_plant') }}</div>
                <div class="tw-text-ui-xs tw-text-on-surface-variant mt-1">
                    @if($shipment->actual_arrival_date)
                        {{ __('common.final_copy.arrived') }} {{ $regionalFormatter->date($shipment->actual_arrival_date, 'human') }}
                    @elseif($shipment->estimated_arrival_date)
                        {{ __('supplier.copy.estimated_arrival') }}: {{ $regionalFormatter->date($shipment->estimated_arrival_date, 'human') }}
                    @else
                        {{ __('shipments.audit_ui.eta_pending') }}
                    @endif
                </div>
            </div>

            {{-- Step 4: Quality Control --}}
            <div class="shipment-tracking-step {{ $hasQc ? 'is-completed' : '' }}">
                <div class="d-flex align-items-center justify-content-between">
                    <span class="tw-text-ui-xs fw-semibold tw-text-on-surface-variant">{{ __('shipments.copy.step_4') }}</span>
                    @if($hasQc)
                        @php $allOk = $shipment->qcInspections->every(fn($i) => $i->status === 'ok'); @endphp
                        <x-ui.icon :name="$allOk ? 'check-circle' : 'alert-circle'" size="sm" :class="$allOk ? 'text-success' : 'text-danger'" />
                    @else
                        <x-ui.icon name="circle" size="sm" class="tw-text-outline" />
                    @endif
                </div>
                <div class="fw-bold fs-6 tw-text-on-surface">{{ __('shipments.copy.qc_inspection') }}</div>
                <div class="tw-text-ui-xs tw-text-on-surface-variant mt-1">
                    @if($hasQc)
                        {{ trans_choice('shipments.audit_ui.reports_filed', $shipment->qcInspections->count(), ['count' => $shipment->qcInspections->count()]) }}
                    @elseif($isArrived)
                        {{ __('shipments.audit_ui.inspection_pending') }}
                    @else
                        {{ __('shipments.audit_ui.awaiting_arrival') }}
                    @endif
                </div>
            </div>
        @endif
    </div>

    {{-- 3. Shipment Overview Card --}}
    <x-ui.card :title="__('shipments.copy.shipment_logistics_overview')">
        <div class="tw-grid tw-gap-3 sm:tw-grid-cols-2 lg:tw-grid-cols-5">
            <div class="p-3 tw-bg-surface-low border tw-border-outline-variant rounded">
                <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('shipments.copy.consolidated_pos') }}</div>
                <div class="fw-bold tw-text-on-surface fs-6 mt-1 d-flex flex-wrap gap-1">
                    @forelse($pos as $po)
                        <a href="{{ route('supplier.purchase-orders.show', $po) }}" class="text-primary text-decoration-none">
                            <span class="ui-status-chip ui-status-chip--neutral">{{ $po->po_number }}</span>
                        </a>
                    @empty
                        <span class="tw-text-outline">-</span>
                    @endforelse
                </div>
            </div>
            <div class="p-3 tw-bg-surface-low border tw-border-outline-variant rounded">
                <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('shipments.copy.total_consignment_qty') }}</div>
                <div class="fw-bold text-primary fs-6 mt-1 ui-tabular-nums">
                    {{ $regionalFormatter->number(number_format($totalQty), 'international') }} pcs
                </div>
            </div>
            <div class="p-3 tw-bg-surface-low border tw-border-outline-variant rounded">
                <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('shipments.copy.actual_weight') }}</div>
                <div class="fw-semibold tw-text-on-surface fs-6 mt-1 ui-tabular-nums">
                    {{ $regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($totalWeight), 'decimal') }} Kg
                </div>
            </div>
            <div class="p-3 tw-bg-surface-low border tw-border-outline-variant rounded">
                <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('shipments.copy.shipment_dispatch_date') }}</div>
                <div class="fw-semibold tw-text-on-surface fs-6 mt-1">
                    {{ $shipment->shipment_date ? $regionalFormatter->date($shipment->shipment_date, 'human') : '-' }}
                </div>
            </div>
            <div class="p-3 tw-bg-surface-low border tw-border-outline-variant rounded">
                <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('shipments.copy.target_actual_arrival') }}</div>
                <div class="fw-semibold tw-text-on-surface fs-6 mt-1">
                    @if($shipment->actual_arrival_date)
                        <span class="text-success fw-bold">{{ __('common.final_copy.arrived') }} {{ $regionalFormatter->date($shipment->actual_arrival_date, 'human') }}</span>
                    @elseif($shipment->estimated_arrival_date)
                        <span>{{ __('supplier.copy.estimated_arrival') }}: {{ $regionalFormatter->date($shipment->estimated_arrival_date, 'human') }}</span>
                    @else
                        -
                    @endif
                </div>
            </div>
        </div>

        @if($shipment->notes)
            <div class="mt-3 p-3 tw-bg-surface-container tw-border tw-border-outline-variant rounded tw-text-ui-xs">
                <span class="fw-semibold">{{ __('shipments.copy.logistics_notes') }}</span> {{ $shipment->notes }}
            </div>
        @endif
    </x-ui.card>

    {{-- 4. Shipped Line Items Table --}}
    <x-ui.card
        :title="__('shipments.copy.included_material_items')"
        :description="__('shipments.copy.line_item_breakdown_of_quantities_dispatched_in_this_consignment')"
    >
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 tw-text-ui-xs">
                <thead class="table-light">
                    <tr>
                        <th scope="col" style="width: 44px;" class="text-center">{{ __('shipments.copy.no') }}</th>
                        <th scope="col">{{ __('shipments.copy.po_reference') }}</th>
                        <th scope="col">{{ __('shipments.copy.material_name') }}</th>
                        <th scope="col" class="text-end">{{ __('shipments.copy.shipped_qty') }}</th>
                        <th scope="col" class="text-end">{{ __('shipments.copy.actual_weight') }}</th>
                        <th scope="col">{{ __('shipments.copy.item_notes') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($shipment->items as $idx => $item)
                        <tr>
                            <td class="text-center tw-text-on-surface-variant">{{ $idx + 1 }}</td>
                            <td class="fw-bold">
                                <a href="{{ route('supplier.purchase-orders.show', $item->purchaseOrder) }}" class="text-primary text-decoration-none">
                                    {{ $item->purchaseOrder->po_number }}
                                </a>
                            </td>
                            <td class="fw-medium">
                                {{ $item->quotationItem->prItem->material_name ?? __('shipments.copy.material') }}
                            </td>
                            <td class="text-end fw-bold text-primary ui-tabular-nums">
                                {{ $regionalFormatter->number(number_format($item->shipped_qty), 'international') }} pcs
                            </td>
                            <td class="text-end ui-tabular-nums tw-text-on-surface-variant">
                                {{ $regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($item->actual_weight_kg), 'decimal') }} Kg
                            </td>
                            <td class="tw-text-on-surface-variant">
                                {{ $item->notes ?: '-' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-ui.card>

    {{-- 5. Shared Shipping Documents Hub --}}
    <x-ui.card
        :title="__('shipments.copy.shared_shipping_import_documents')"
        :description="__('shipments.copy.one_shared_set_of_documents_invoice_packing_list_bl_form_e_covers_all_purchase_orders_consolidated_i')"
    >
        <div class="tw-grid tw-gap-3 sm:tw-grid-cols-2 lg:tw-grid-cols-4">
            @foreach($shipment->documents as $doc)
                @php
                    $docLabel = match($doc->doc_type) {
                        'invoice' => __('shipments.copy.commercial_invoice'),
                        'packing_list' => __('shipments.copy.packing_list'),
                        'bl' => __('shipments.copy.bill_of_lading_bl'),
                        'form_e' => __('shipments.copy.form_e_certificate_of_origin'),
                        default => strtoupper($doc->doc_type),
                    };
                    $latestAtt = $doc->latestAttachment;
                @endphp
                <div class="p-3 border tw-border-outline-variant rounded tw-bg-surface-low d-flex flex-column justify-content-between gap-2">
                    <div>
                        <div class="d-flex align-items-center justify-content-between gap-1">
                            <span class="fw-bold tw-text-ui-xs tw-text-on-surface">{{ $docLabel }}</span>
                            <x-ui.status-chip :tone="\App\Support\StatusHelper::shipmentDocTone($doc->status)" size="sm">
                                {{ \App\Support\StatusHelper::shipmentDocLabel($doc->status) }}
                            </x-ui.status-chip>
                        </div>

                        <div class="mt-2 tw-text-ui-xs">
                            @if($doc->document_number)
                                <div class="tw-text-on-surface-variant mb-1">
                                    <span class="fw-semibold">{{ __('common.labels_review.reference') }}</span> {{ $doc->document_number }}
                                </div>
                            @endif

                            @if($latestAtt)
                                <a href="{{ route('attachments.show', $latestAtt) }}" target="_blank" class="text-primary text-decoration-none d-inline-flex align-items-center gap-1 fw-medium">
                                    <x-ui.icon name="file-text" size="sm" />
                                    <span class="text-truncate" style="max-width: 170px;" title="{{ $latestAtt->file_name }}">
                                        {{ $latestAtt->file_name }}
                                    </span>
                                </a>
                            @else
                                <span class="tw-text-outline fst-italic">{{ __('shipments.copy.no_file_uploaded') }}</span>
                            @endif
                        </div>
                    </div>

                    @if($shipment->status !== 'cancelled')
                        <form method="POST" action="{{ route('supplier.shipments.documents.upload', [$shipment, $doc]) }}" enctype="multipart/form-data" class="pt-2 border-top tw-border-outline-variant">
                            @csrf
                            <input
                                type="text"
                                name="document_number"
                                value="{{ old('document_number', $doc->document_number) }}"
                                class="form-control form-control-sm mb-1.5 tw-text-ui-xs"
                                placeholder="{{ __('shipments.copy.doc_ref_no_optional') }}"
                            >
                            <div class="input-group input-group-sm">
                                <input type="file" name="file" class="form-control form-control-sm" required accept=".pdf,.jpg,.jpeg,.png,.xlsx,.doc,.docx">
                                <button type="submit" class="btn btn-outline-primary btn-sm" title="{{ __('shipments.copy.upload_file') }}">
                                    <x-ui.icon name="upload" size="sm" />
                                </button>
                            </div>
                            <div class="tw-text-outline" style="font-size: 10px; margin-top: 3px;">
                                {{ __('shipments.copy.max_10mb_pdf_image_excel_word') }}
                            </div>
                        </form>
                    @endif
                </div>
            @endforeach
        </div>
    </x-ui.card>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    // Submit Confirmation via AdasiAlert
    const submitBtn = document.getElementById('btnConfirmSubmit');
    const submitForm = document.getElementById('submitShipmentForm');
    submitBtn?.addEventListener('click', () => {
        const title = @js(__('shipments.confirmations.submit.title'));
        const text = @js(__('shipments.confirmations.submit.body'));

        if (window.AdasiAlert) {
            window.AdasiAlert.confirm({
                title: title,
                text: text,
                confirmText: @json(__('shipments.copy.yes_submit_delivery')),
                confirmTone: 'primary'
            }).then(res => {
                if (res.isConfirmed) {
                    window.AdasiButton?.startLoading(submitBtn);
                    submitForm.submit();
                }
            });
        } else if (confirm(`${title}\n\n${text}`)) {
            window.AdasiButton?.startLoading(submitBtn);
            submitForm.submit();
        }
    });

    // Cancel Confirmation via AdasiAlert
    const cancelBtn = document.getElementById('btnConfirmCancel');
    const cancelForm = document.getElementById('cancelShipmentForm');
    cancelBtn?.addEventListener('click', () => {
        const title = @js(__('shipments.confirmations.cancel.title'));
        const text = @js(__('shipments.confirmations.cancel.body'));

        if (window.AdasiAlert) {
            window.AdasiAlert.confirm({
                title: title,
                text: text,
                confirmText: @json(__('shipments.copy.yes_cancel_shipment')),
                confirmTone: 'danger'
            }, true).then(res => {
                if (res.isConfirmed) {
                    window.AdasiButton?.startLoading(cancelBtn);
                    cancelForm.submit();
                }
            });
        } else if (confirm(`${title}\n\n${text}`)) {
            window.AdasiButton?.startLoading(cancelBtn);
            cancelForm.submit();
        }
    });
});
</script>
@endpush
