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
<div class="tw:grid tw:gap-4">
    {{-- 1. Breadcrumb & Page Header --}}
    <x-ui.breadcrumb :items="[
        __('purchasing.breadcrumbs.dashboard') => route('purchasing.dashboard'),
        __('purchasing.breadcrumbs.shipments') => route('purchasing.shipments.index'),
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
        :eyebrow="__('shipments.copy.consignment_logistics_receiving')"
        :description="__('shipments.copy.physical_delivery_verification_consolidated_shipping_documents_and_receiving_status')"
    >
        <x-slot:actions>
            <div class="tw:flex tw:flex-wrap tw:gap-2 align-items-center">
                <x-ui.status-chip :tone="\App\Support\StatusHelper::shipmentTone($shipment->status)" size="md">
                    {{ \App\Support\StatusHelper::shipmentLabel($shipment->status) }}
                </x-ui.status-chip>

                @if($isSubmitted)
                    <button type="button" class="btn btn-primary btn-sm d-inline-flex align-items-center gap-1.5" data-bs-toggle="modal" data-bs-target="#confirmArrivalModal">
                        <x-ui.icon name="check-circle" size="sm" />
                        <span>{{ __('shipments.copy.confirm_physical_arrival') }}</span>
                    </button>
                @endif
            </div>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- 2. Visual Lifecycle Tracking Stepper --}}
    <div class="shipment-tracking-strip" aria-label="{{ __('shipments.copy.shipment_fulfillment_progress') }}">
        @if($isCancelled)
            <div class="shipment-tracking-step is-cancelled">
                <div class="tw:text-ui-xs fw-semibold text-danger">{{ __('shipments.copy.status') }}</div>
                <div class="fw-bold fs-6 text-danger">{{ __('shipments.copy.cancelled') }}</div>
                <div class="tw:text-ui-xs text-danger mt-1">{{ __('shipments.copy.delivery_batch_was_revoked') }}</div>
            </div>
        @else
            {{-- Step 1: Draft --}}
            <div class="shipment-tracking-step {{ $isDraft ? 'is-active' : 'is-completed' }}">
                <div class="d-flex align-items-center justify-content-between">
                    <span class="tw:text-ui-xs fw-semibold tw:text-on-surface-variant">{{ __('shipments.copy.step_1') }}</span>
                    <x-ui.icon :name="$isDraft ? 'circle-dot' : 'check'" size="sm" :class="$isDraft ? 'text-primary' : 'text-success'" />
                </div>
                <div class="fw-bold fs-6 tw:text-on-surface">{{ __('shipments.copy.draft_allocation') }}</div>
                <div class="tw:text-ui-xs tw:text-on-surface-variant mt-1">
                    {{ $regionalFormatter->timestamp($shipment->created_at, 'date') }}
                </div>
            </div>

            {{-- Step 2: Dispatched / In Transit --}}
            <div class="shipment-tracking-step {{ $isSubmitted ? 'is-active' : ($isArrived ? 'is-completed' : '') }}">
                <div class="d-flex align-items-center justify-content-between">
                    <span class="tw:text-ui-xs fw-semibold tw:text-on-surface-variant">{{ __('shipments.copy.step_2') }}</span>
                    @if($isArrived)
                        <x-ui.icon name="check" size="sm" class="text-success" />
                    @elseif($isSubmitted)
                        <x-ui.icon name="truck" size="sm" class="text-primary" />
                    @else
                        <x-ui.icon name="circle" size="sm" class="tw:text-on-surface-variant" />
                    @endif
                </div>
                <div class="fw-bold fs-6 tw:text-on-surface">{{ __('shipments.copy.in_transit') }}</div>
                <div class="tw:text-ui-xs tw:text-on-surface-variant mt-1">
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
                    <span class="tw:text-ui-xs fw-semibold tw:text-on-surface-variant">{{ __('shipments.copy.step_3') }}</span>
                    @if($isArrived && $hasQc)
                        <x-ui.icon name="check" size="sm" class="text-success" />
                    @elseif($isArrived)
                        <x-ui.icon name="package-check" size="sm" class="text-primary" />
                    @else
                        <x-ui.icon name="circle" size="sm" class="tw:text-on-surface-variant" />
                    @endif
                </div>
                <div class="fw-bold fs-6 tw:text-on-surface">{{ __('shipments.copy.arrived_at_plant') }}</div>
                <div class="tw:text-ui-xs tw:text-on-surface-variant mt-1">
                    @if($shipment->actual_arrival_date)
                        {{ __('common.final_copy.arrived') }} {{ $regionalFormatter->date($shipment->actual_arrival_date, 'human') }}
                    @elseif($shipment->estimated_arrival_date)
                        {{ __('purchasing.copy.estimated_arrival') }}: {{ $regionalFormatter->date($shipment->estimated_arrival_date, 'human') }}
                    @else
                        {{ __('shipments.audit_ui.eta_pending') }}
                    @endif
                </div>
            </div>

            {{-- Step 4: Quality Control --}}
            <div class="shipment-tracking-step {{ $hasQc ? 'is-completed' : '' }}">
                <div class="d-flex align-items-center justify-content-between">
                    <span class="tw:text-ui-xs fw-semibold tw:text-on-surface-variant">{{ __('shipments.copy.step_4') }}</span>
                    @if($hasQc)
                        @php $allOk = $shipment->qcInspections->every(fn($i) => $i->status === 'ok'); @endphp
                        <x-ui.icon :name="$allOk ? 'check-circle' : 'alert-circle'" size="sm" :class="$allOk ? 'text-success' : 'text-danger'" />
                    @else
                        <x-ui.icon name="circle" size="sm" class="tw:text-on-surface-variant" />
                    @endif
                </div>
                <div class="fw-bold fs-6 tw:text-on-surface">{{ __('shipments.copy.qc_inspection') }}</div>
                <div class="tw:text-ui-xs tw:text-on-surface-variant mt-1">
                    @if($hasQc)
                        {{ trans_choice('shipments.audit_ui.inspection_reports', $shipment->qcInspections->count(), ['count' => $shipment->qcInspections->count()]) }}
                    @elseif($isArrived)
                        {{ __('shipments.audit_ui.pending_qc') }}
                    @else
                        {{ __('shipments.audit_ui.awaiting_delivery') }}
                    @endif
                </div>
            </div>
        @endif
    </div>

    {{-- 3. Delivery Package Overview Card --}}
    <x-ui.card :title="__('shipments.copy.delivery_package_overview')">
        <div class="tw:grid tw:gap-3 tw:sm:grid-cols-2 tw:lg:grid-cols-5">
            <div class="p-3 tw:bg-surface-low border tw:border-outline-variant rounded">
                <div class="tw:text-on-surface-variant tw:text-ui-xs fw-semibold tw:uppercase">{{ __('shipments.copy.supplier') }}</div>
                <div class="fw-bold tw:text-on-surface fs-6 mt-1">
                    {{ $shipment->supplier->company_name ?? $shipment->supplier->name }}
                </div>
            </div>
            <div class="p-3 tw:bg-surface-low border tw:border-outline-variant rounded">
                <div class="tw:text-on-surface-variant tw:text-ui-xs fw-semibold tw:uppercase">{{ __('shipments.copy.consolidated_pos') }}</div>
                <div class="fw-bold tw:text-on-surface fs-6 mt-1 d-flex flex-wrap gap-1">
                    @forelse($pos as $po)
                        <a href="{{ route('purchasing.purchase-orders.show', $po) }}" class="text-primary text-decoration-none">
                            <span class="ui-status-chip ui-status-chip--neutral">{{ $po->po_number }}</span>
                        </a>
                    @empty
                        <span class="tw:text-on-surface-variant">-</span>
                    @endforelse
                </div>
            </div>
            <div class="p-3 tw:bg-surface-low border tw:border-outline-variant rounded">
                <div class="tw:text-on-surface-variant tw:text-ui-xs fw-semibold tw:uppercase">{{ __('shipments.copy.total_consignment_qty') }}</div>
                <div class="fw-bold text-primary fs-6 mt-1 ui-tabular-nums">
                    {{ $regionalFormatter->number(number_format($totalQty), 'international') }} pcs
                </div>
            </div>
            <div class="p-3 tw:bg-surface-low border tw:border-outline-variant rounded">
                <div class="tw:text-on-surface-variant tw:text-ui-xs fw-semibold tw:uppercase">{{ __('shipments.copy.actual_weight') }}</div>
                <div class="fw-semibold tw:text-on-surface fs-6 mt-1 ui-tabular-nums">
                    {{ $regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($totalWeight), 'decimal') }} Kg
                </div>
            </div>
            <div class="p-3 tw:bg-surface-low border tw:border-outline-variant rounded">
                <div class="tw:text-on-surface-variant tw:text-ui-xs fw-semibold tw:uppercase">{{ __('shipments.copy.logistics_timeline') }}</div>
                <div class="fw-semibold tw:text-on-surface fs-6 mt-1">
                    @if($shipment->actual_arrival_date)
                        <span class="text-success fw-bold">{{ __('common.final_copy.arrived') }} {{ $regionalFormatter->date($shipment->actual_arrival_date, 'human') }}</span>
                    @elseif($shipment->estimated_arrival_date)
                        <span>{{ __('purchasing.copy.estimated_arrival') }}: {{ $regionalFormatter->date($shipment->estimated_arrival_date, 'human') }}</span>
                    @else
                        <span>{{ __('common.final_copy.departed') }} {{ $shipment->shipment_date ? $regionalFormatter->date($shipment->shipment_date, 'human') : '-' }}</span>
                    @endif
                </div>
            </div>
        </div>

        @if($shipment->notes)
            <div class="mt-3 p-3 tw:bg-surface-container tw:border tw:border-outline-variant rounded tw:text-ui-xs">
                <strong>{{ __('shipments.copy.logistics_notes') }}</strong> {{ $shipment->notes }}
            </div>
        @endif
    </x-ui.card>

    {{-- 4. Shared Shipping Documents Hub --}}
    <x-ui.card
        :title="__('shipments.copy.shipping_import_documents')"
        :description="__('shipments.copy.verify_and_update_shipping_documentation_commercial_invoice_packing_list_bill_of_lading_form_e_for_t')"
    >
        @if(in_array($shipment->status, \App\Support\Procurement\CustomsDocumentationProgressCalculator::ACTIVE_SHIPMENT_STATUSES, true))
            <x-slot:actions>
                <div class="tw:flex tw:items-center tw:gap-2">
                    @php
                        $canVerifyAll = $shipment->documents->contains(fn ($d) => ! in_array($d->status, ['verified', 'done'], true) && $d->latestAttachment !== null);
                    @endphp
                    @if($canVerifyAll)
                        <x-ui.button type="button" variant="primary" size="sm" id="btnVerifyAllDocs">
                            <x-ui.icon name="check-check" size="sm" class="me-1" />
                            <span>{{ __('shipments.copy.verify_all_documents') }}</span>
                        </x-ui.button>
                    @endif
                    <x-ui.button type="button" variant="outline" size="sm" id="btnSaveAllDocStatuses" disabled>
                        <x-ui.icon name="save" size="sm" class="me-1" />
                        <span>{{ __('shipments.copy.save_all_statuses') }}</span>
                    </x-ui.button>
                </div>
            </x-slot:actions>
        @endif

        <div class="tw:grid tw:gap-3 tw:sm:grid-cols-2 tw:lg:grid-cols-4">
            @foreach($shipment->documents as $doc)
                @php
                    $attachment = $doc->latestAttachment;
                    $docLabel = match($doc->doc_type) {
                        'invoice' => __('shipments.copy.commercial_invoice'),
                        'packing_list' => __('shipments.copy.packing_list'),
                        'bl' => __('shipments.copy.bill_of_lading_bl'),
                        'form_e' => 'Form E / COO',
                        default => strtoupper($doc->doc_type),
                    };
                @endphp
                <div class="p-3 border tw:border-outline-variant rounded tw:bg-surface-low d-flex flex-column justify-content-between gap-2" data-purchasing-doc-card="{{ $doc->doc_type }}">
                    <div>
                        <div class="d-flex align-items-center justify-content-between gap-1">
                            <span class="fw-bold tw:text-ui-xs tw:text-on-surface">{{ $docLabel }}</span>
                            <x-ui.status-chip :tone="\App\Support\StatusHelper::shipmentDocTone($doc->status)" size="sm">
                                {{ \App\Support\StatusHelper::shipmentDocLabel($doc->status) }}
                            </x-ui.status-chip>
                        </div>

                        <div class="mt-2 tw:text-ui-xs">
                            @if($doc->document_number)
                                <div class="tw:text-on-surface-variant mb-1">
                                    <span class="fw-semibold">{{ __('common.labels_review.reference') }}</span> {{ $doc->document_number }}
                                </div>
                            @endif

                            @if($attachment)
                                <a href="{{ route('attachments.show', $attachment) }}" target="_blank" class="text-primary text-decoration-none d-inline-flex align-items-center gap-1 fw-medium">
                                    <x-ui.icon name="paperclip" size="sm" />
                                    <span class="text-truncate" style="max-width: 170px;" title="{{ $attachment->file_name }}">
                                        {{ $attachment->file_name }}
                                    </span>
                                </a>
                            @else
                                <span class="tw:text-on-surface-variant fst-italic">{{ __('shipments.copy.no_file_uploaded_yet') }}</span>
                            @endif
                        </div>
                    </div>

                    {{-- Status Update Form: same rules as the PO page dialog --}}
                    @if(in_array($shipment->status, \App\Support\Procurement\CustomsDocumentationProgressCalculator::ACTIVE_SHIPMENT_STATUSES, true))
                        <form method="POST" action="{{ route('purchasing.shipments.documents.status', ['id' => $shipment, 'document_id' => $doc->id]) }}" class="pt-2 border-top tw:border-outline-variant" data-shipment-doc-status-form data-doc-id="{{ $doc->id }}" data-doc-type="{{ $doc->doc_type }}">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="expected_status" value="{{ $doc->status }}">
                            <input type="hidden" name="expected_attachment" value="{{ $attachment?->hash }}">
                            <div class="input-group input-group-sm">
                                <select name="status" class="form-select form-select-sm" aria-label="{{ __('documents.a11y.change_status', ['document' => $docLabel]) }}">
                                    @php
                                        $docTargets = \App\Support\Procurement\CustomsDocumentationProgressCalculator::ALLOWED_TARGETS[$doc->doc_type] ?? [];
                                    @endphp
                                    @unless(in_array($doc->status, $docTargets, true))
                                        {{-- Historical status outside the current workflow: shown, never resubmitted. --}}
                                        <option value="" disabled selected>{{ \App\Support\StatusHelper::shipmentDocLabel($doc->status) }}</option>
                                    @endunless
                                    @foreach($docTargets as $st)
                                        <option value="{{ $st }}" @selected($doc->status === $st)>
                                            {{ \App\Support\StatusHelper::shipmentDocLabel($st) }}
                                        </option>
                                    @endforeach
                                </select>
                                <button type="submit" class="btn btn-outline-secondary btn-sm" title="{{ __('shipments.copy.save_status') }}">
                                    {{ __('shipments.copy.update') }}
                                </button>
                            </div>
                            <label for="doc-reason-{{ $doc->id }}" class="form-label tw:text-ui-xs tw:text-on-surface-variant mt-2 mb-1">{{ __('documents.sync.reason_label') }}</label>
                            <textarea id="doc-reason-{{ $doc->id }}" name="reason" rows="2" maxlength="1000" class="form-control form-control-sm tw:text-ui-xs" placeholder="{{ __('documents.sync.reason_placeholder') }}" aria-describedby="doc-reason-hint-{{ $doc->id }}"></textarea>
                            <p id="doc-reason-hint-{{ $doc->id }}" class="tw:m-0 tw:mt-1 tw:text-ui-xs tw:text-on-surface-variant">{{ __('documents.sync.reason_required_hint') }}</p>
                        </form>
                    @else
                        <p class="pt-2 border-top tw:border-outline-variant tw:m-0 tw:text-ui-xs tw:text-on-surface-variant">
                            {{ __('documents.sync.reasons.invalid_shipment_status') }}
                        </p>
                    @endif
                </div>
            @endforeach
        </div>
    </x-ui.card>

    {{-- 5. Consignment Line Items Table --}}
    <x-ui.card
        :title="__('shipments.copy.consignment_line_items')"
        :description="__('shipments.copy.material_batches_delivered_in_this_physical_consignment')"
    >
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 tw:text-ui-xs">
                <thead class="table-light">
                    <tr>
                        <th scope="col">{{ __('shipments.copy.po_number') }}</th>
                        <th scope="col">{{ __('shipments.copy.pr_reference') }}</th>
                        <th scope="col">{{ __('shipments.copy.material_name') }}</th>
                        <th scope="col">{{ __('shipments.copy.shape_specs') }}</th>
                        <th scope="col" class="text-end">{{ __('shipments.copy.shipped_qty') }}</th>
                        <th scope="col" class="text-end">{{ __('shipments.copy.actual_weight') }}</th>
                        <th scope="col">{{ __('shipments.copy.item_notes') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($shipment->items as $item)
                        @php
                            $po = $item->purchaseOrder;
                            $prItem = $item->quotationItem?->prItem;
                            $pr = $prItem?->purchaseRequisition;
                        @endphp
                        <tr>
                            <td class="fw-bold">
                                <a href="{{ route('purchasing.purchase-orders.show', $po) }}" class="text-primary text-decoration-none">
                                    {{ $po->po_number }}
                                </a>
                            </td>
                            <td>
                                @if($pr)
                                    <span class="ui-status-chip ui-status-chip--neutral">{{ $pr->pr_number }}</span>
                                @else
                                    <span class="tw:text-on-surface-variant">-</span>
                                @endif
                            </td>
                            <td class="fw-semibold tw:text-on-surface">
                                {{ $prItem->material_name ?? __('shipments.copy.material_item') }}
                            </td>
                            <td class="tw:text-on-surface-variant">
                                {{ __('purchasing.copy.shape') }}: {{ $prItem->shape ?? '-' }}
                                @if($prItem?->thickness) | T: {{ $prItem->thickness }}mm @endif
                                @if($prItem?->width) | W: {{ $prItem->width }}mm @endif
                                @if($prItem?->d_outer) | OD: {{ $prItem->d_outer }}mm @endif
                                @if($prItem?->length) | L: {{ $prItem->length }}mm @endif
                            </td>
                            <td class="text-end fw-bold text-primary ui-tabular-nums">
                                {{ $regionalFormatter->number(number_format($item->shipped_qty), 'international') }} pcs
                            </td>
                            <td class="text-end ui-tabular-nums tw:text-on-surface-variant">
                                {{ $regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($item->actual_weight_kg), 'decimal') }} Kg
                            </td>
                            <td class="tw:text-on-surface-variant">
                                {{ $item->notes ?? '-' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-ui.card>

    {{-- 6. Quality Control (QC) Status --}}
    @if($isArrived)
        <x-ui.card
            :title="__('shipments.copy.quality_control_qc_inspections')"
            :description="__('shipments.copy.incoming_quality_inspection_events_recorded_for_this_shipment_consignment')"
        >
            @if($shipment->qcInspections->isEmpty())
                <div class="alert alert-warning d-flex align-items-center gap-2 mb-0 tw:text-ui-xs">
                    <x-ui.icon name="triangle-alert" size="sm" />
                    <div>
                        {{ __('shipments.copy.material_arrival_has_been_confirmed_inspection_is_currently_queued_and_awaiting_action_from_the_qual') }}
                    </div>
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 tw:text-ui-xs">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">{{ __('shipments.copy.inspection_date') }}</th>
                                <th scope="col">{{ __('shipments.copy.inspector') }}</th>
                                <th scope="col" class="text-center">{{ __('shipments.copy.qc_result') }}</th>
                                <th scope="col" class="text-end">{{ __('shipments.copy.action') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($shipment->qcInspections as $insp)
                                <tr>
                                    <td class="ui-tabular-nums">
                                        {{ $insp->inspected_at ? $regionalFormatter->timestamp($insp->inspected_at, 'datetime_comma') : '-' }}
                                    </td>
                                    <td class="fw-semibold">
                                        {{ $insp->inspector->name ?? '-' }}
                                    </td>
                                    <td class="text-center">
                                        <x-ui.status-chip :tone="$insp->status === 'ok' ? 'success' : 'error'">
                                            {{ \App\Support\StatusHelper::qcLabel($insp->status) }}
                                        </x-ui.status-chip>
                                    </td>
                                    <td class="text-end">
                                        <x-ui.button :href="route('qc.inspections.show', $insp)" variant="outline" size="sm">
                                            {{ __('shipments.copy.view_report') }}
                                        </x-ui.button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui.card>
    @endif

    @if(\App\Support\Procurement\ProcurementFeatures::shipmentMtcEnabled() || $shipment->mtcAttachments->isNotEmpty())
        @include('shipments._mtc_section', ['shipment' => $shipment, 'manageable' => false])
    @endif
</div>

{{-- Confirm Arrival Modal --}}
@if($isSubmitted)
<div class="modal fade" id="confirmArrivalModal" tabindex="-1" aria-labelledby="confirmArrivalModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('purchasing.shipments.confirm-arrival', $shipment) }}">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title tw:text-ui-base fw-bold" id="confirmArrivalModalLabel">{{ __('shipments.copy.confirm_physical_material_arrival') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('shipments.copy.close') }}"></button>
                </div>
                <div class="modal-body">
                    <p class="tw:text-ui-sm tw:text-on-surface-variant mb-3">
                        {{ __('shipments.copy.recording_physical_arrival_confirms_that_consignment') }} <strong>{{ $shipment->shipment_number }}</strong> {{ __('shipments.copy.has_physically_reached_the_plant_warehouse_this_will_notify_the_qc_department_to_conduct_incoming_ma') }}
                    </p>
                    <div class="mb-3">
                        <x-ui.date-picker
                            name="actual_arrival_date"
                            id="actual_arrival_date"
                            :label="__('shipments.copy.arrival_date')"
                            value="{{ now()->toDateString() }}"
                            max="{{ now()->toDateString() }}"
                            required
                        />
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">{{ __('shipments.copy.cancel') }}</button>
                    <button type="submit" class="btn btn-primary btn-sm">{{ __('shipments.copy.confirm_arrival_notify_qc') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const verifyAllBtn = document.getElementById('btnVerifyAllDocs');
    const saveAllBtn = document.getElementById('btnSaveAllDocStatuses');
    const batchStatusUrl = @js(route('purchasing.shipments.documents.batch-status', $shipment));
    const verifyAllUrl = @js(route('purchasing.shipments.documents.verify-all', $shipment));
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || @js(csrf_token());

    verifyAllBtn?.addEventListener('click', async () => {
        const title = @js(__('shipments.copy.verify_all_confirm_title'));
        const text = @js(__('shipments.copy.verify_all_confirm_desc'));

        const doVerify = async () => {
            window.AdasiButton?.startLoading(verifyAllBtn);
            verifyAllBtn.disabled = true;

            try {
                const resp = await fetch(verifyAllUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({}),
                });

                const data = await resp.json();

                if (! resp.ok) {
                    window.AdasiButton?.stopLoading(verifyAllBtn);
                    verifyAllBtn.disabled = false;
                    const err = data.message || 'Verification failed.';
                    if (window.AdasiAlert) {
                        window.AdasiAlert.alert({ tone: 'error', title: 'Error', text: err });
                    } else {
                        alert(err);
                    }
                    return;
                }

                window.location.reload();
            } catch (err) {
                window.AdasiButton?.stopLoading(verifyAllBtn);
                verifyAllBtn.disabled = false;
                alert(err.message || 'An error occurred.');
            }
        };

        if (window.AdasiAlert) {
            window.AdasiAlert.confirm({
                title: title,
                text: text,
                confirmText: @js(__('shipments.copy.verify_all_documents')),
                confirmTone: 'primary',
            }).then(res => {
                if (res.isConfirmed) doVerify();
            });
        } else if (confirm(`${title}\n\n${text}`)) {
            doVerify();
        }
    });

    function checkDocStatusChanges() {
        let hasChanges = false;
        const forms = document.querySelectorAll('[data-shipment-doc-status-form]');
        forms.forEach(form => {
            const statusSelect = form.querySelector('select[name="status"]');
            const reasonInput = form.querySelector('textarea[name="reason"]');
            const expectedStatus = form.querySelector('input[name="expected_status"]')?.value;
            if (statusSelect && statusSelect.value && statusSelect.value !== expectedStatus) {
                hasChanges = true;
            }
            if (reasonInput && reasonInput.value.trim().length > 0) {
                hasChanges = true;
            }
        });
        if (saveAllBtn) {
            saveAllBtn.disabled = !hasChanges;
        }
    }

    document.querySelectorAll('[data-shipment-doc-status-form]').forEach(form => {
        form.querySelector('select[name="status"]')?.addEventListener('change', checkDocStatusChanges);
        form.querySelector('textarea[name="reason"]')?.addEventListener('input', checkDocStatusChanges);
    });
    checkDocStatusChanges();

    saveAllBtn?.addEventListener('click', async () => {
        const forms = document.querySelectorAll('[data-shipment-doc-status-form]');
        const documents = [];

        forms.forEach(form => {
            const statusSelect = form.querySelector('select[name="status"]');
            const reasonInput = form.querySelector('textarea[name="reason"]');
            const expectedStatus = form.querySelector('input[name="expected_status"]')?.value;
            const expectedAttachment = form.querySelector('input[name="expected_attachment"]')?.value;
            const docId = form.dataset.docId;
            const docType = form.dataset.docType;

            const isStatusChanged = statusSelect && statusSelect.value && statusSelect.value !== expectedStatus;
            const hasReason = reasonInput && reasonInput.value.trim().length > 0;

            if (isStatusChanged || hasReason) {
                documents.push({
                    document_id: docId,
                    doc_type: docType,
                    status: statusSelect.value,
                    reason: reasonInput ? reasonInput.value.trim() : '',
                    expected_status: expectedStatus,
                    expected_attachment: expectedAttachment,
                });
            }
        });

        if (documents.length === 0) return;

        window.AdasiButton?.startLoading(saveAllBtn);
        saveAllBtn.disabled = true;

        try {
            const resp = await fetch(batchStatusUrl, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ documents }),
            });

            const data = await resp.json();

            if (! resp.ok) {
                window.AdasiButton?.stopLoading(saveAllBtn);
                saveAllBtn.disabled = false;
                const err = data.message || 'Failed to update statuses.';
                if (window.AdasiAlert) {
                    window.AdasiAlert.alert({ tone: 'error', title: 'Update Error', text: err });
                } else {
                    alert(err);
                }
                return;
            }

            window.location.reload();
        } catch (err) {
            window.AdasiButton?.stopLoading(saveAllBtn);
            saveAllBtn.disabled = false;
            alert(err.message || 'An error occurred.');
        }
    });
});
</script>
@endpush
