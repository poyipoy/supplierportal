@extends('layouts.app')

@section('title', __('qc.titles.detail', ['number' => $inspection->purchaseOrder->po_number ?? 'N/A']))
@section('page-title', __('qc.copy.qc_inspection_details'))

@php
    $returnUrl = request()->query('return_url') ?? request()->input('return_url');
    $isFromClaim = $returnUrl && (str_contains($returnUrl, '/claims') || str_contains($returnUrl, 'claims'));
    $safeReturnUrl = \App\Support\PurchasingNavigation::isSafeUrl($returnUrl) ? $returnUrl : null;

    $backUrl = $safeReturnUrl ?: (auth()->user()->role === 'purchasing'
        ? route('purchasing.purchase-orders.show', $inspection->purchaseOrder)
        : route('qc.inspections.index'));

    $backLabel = $isFromClaim
        ? __('qc.copy.back_to_material_claim')
        : (auth()->user()->role === 'purchasing' ? __('qc.copy.back_to_po') : __('qc.copy.back_to_list'));

    $breadcrumbDashboard = auth()->user()->role === 'purchasing' ? route('purchasing.dashboard') : route('qc.dashboard');
    $breadcrumbParentUrl = $isFromClaim
        ? ($safeReturnUrl ?: route('purchasing.claims.index'))
        : (auth()->user()->role === 'purchasing' ? route('purchasing.purchase-orders.index') : route('qc.inspections.index'));
    $breadcrumbParentLabel = $isFromClaim ? __('qc.copy.material_claims') : __('qc.copy.qc_inspections');
@endphp

@section('content')
<div class="tw-grid tw-gap-4">
    {{-- Breadcrumb & Compact Page Header --}}
    <x-ui.breadcrumb :items="[
        __('purchasing.breadcrumbs.dashboard') => $breadcrumbDashboard,
        $breadcrumbParentLabel => $breadcrumbParentUrl,
        ('PO ' . ($inspection->purchaseOrder->po_number ?? 'N/A')) => null,
    ]" />

    <x-ui.page-header
        :title="__('qc.page.report_title', ['po' => $inspection->purchaseOrder->po_number ?? 'N/A'])"
        :eyebrow="__('qc.copy.quality_control_inspection')"
        :description="__('qc.copy.technical_measurement_verification_tolerance_comparison_and_photographic_evidence')"
    >
        <x-slot:actions>
            <div class="tw-flex tw-flex-wrap tw-items-center tw-gap-2">
                @if($inspection->status === 'ok')
                    <span class="ui-status-chip ui-status-chip--success">
                    <x-ui.icon name="circle-check" size="sm" />
                        <span>{{ __('qc.copy.outcome_ok_passed') }}</span>
                    </span>
                @else
                    <span class="ui-status-chip ui-status-chip--error">
                    <x-ui.icon name="circle-x" size="sm" />
                        <span>{{ __('qc.copy.outcome_ng_defective') }}</span>
                    </span>
                @endif

                <x-ui.button
                    :href="route('shared.pdf.qc-inspection', $inspection)"
                    variant="outline"
                    size="sm"
                    target="_blank"
                    :title="__('qc.copy.print_official_qc_inspection_report')"
                    data-pdf-confirm
                >
                    <x-ui.icon name="printer" size="sm" />
                    <span>{{ __('qc.copy.print_pdf') }}</span>
                </x-ui.button>

                <x-ui.button
                    :href="$backUrl"
                    variant="ghost"
                    size="sm"
                >
                    <x-ui.icon name="arrow-left" size="sm" />
                    <span>{{ $backLabel }}</span>
                </x-ui.button>
            </div>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Inspection Metadata & Order Overview --}}
        <x-ui.card :title="__('qc.copy.inspection_and_arrival_summary')">
        <div class="tw-grid tw-gap-3 sm:tw-grid-cols-2 lg:tw-grid-cols-5">
            <div class="tw-p-2.5 tw-bg-surface-container border rounded">
                <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('qc.copy.supplier') }}</div>
                <div class="fw-bold tw-text-on-surface tw-text-ui-xs tw-mt-0.5">{{ $inspection->purchaseOrder->supplier->company_name ?? $inspection->purchaseOrder->supplier->name ?? '-' }}</div>
            </div>
            <div class="tw-p-2.5 tw-bg-surface-container border rounded">
                <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('qc.copy.shipment_package') }}</div>
                <div class="fw-bold text-primary tw-text-ui-xs tw-mt-0.5">
                    {{ $inspection->shipment ? $inspection->shipment->shipment_number : __('qc.copy.direct_delivery_legacy') }}
                </div>
            </div>
            <div class="tw-p-2.5 tw-bg-surface-container border rounded">
                <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('qc.copy.inspected_by') }}</div>
                <div class="fw-bold tw-text-on-surface tw-text-ui-xs tw-mt-0.5">{{ $inspection->inspector->name ?? '-' }}</div>
            </div>
            <div class="tw-p-2.5 tw-bg-surface-container border rounded">
                <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('qc.copy.inspection_timestamp') }}</div>
                <div class="fw-bold tw-text-on-surface tw-text-ui-xs tw-mt-0.5 ui-tabular-nums">{{ $inspection->inspected_at ? $regionalFormatter->timestamp($inspection->inspected_at, 'datetime_comma') : '-' }}</div>
            </div>
            <div class="tw-p-2.5 tw-bg-surface-container border rounded">
                <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">{{ __('qc.copy.material_arrival_date') }}</div>
                <div class="fw-bold tw-text-on-surface tw-text-ui-xs tw-mt-0.5 ui-tabular-nums">{{ $inspection->purchaseOrder->actual_arrival ? $regionalFormatter->date($inspection->purchaseOrder->actual_arrival, 'human') : '-' }}</div>
            </div>
        </div>
    </x-ui.card>

    @php
        if (!function_exists('compareValues')) {
            function compareValues($actual, $expected) {
                if ($actual === null || $expected === null) return ['val' => $actual ?? '-', 'class' => ''];

                $act = (float) $actual;
                $exp = (float) $expected;
                if ($exp > 0) {
                    $diff = abs($act - $exp) / $exp;
                    if ($diff > 0.05) return ['val' => $actual, 'class' => 'text-danger fw-bold'];
                }
                return ['val' => $actual, 'class' => ''];
            }
        }
    @endphp

    {{-- Line Items Inspection Matrix --}}
    <div class="tw-grid tw-gap-4">
        @foreach($inspection->items as $index => $item)
            @php
                $prItem = $item->prItem;

                $thick = compareValues($item->actual_thickness, $prItem->thickness);
                $dInner = compareValues($item->actual_d_inner, $prItem->d_inner);
                $dOuter = compareValues($item->actual_d_outer, $prItem->d_outer);
                $width = compareValues($item->actual_width, $prItem->width);
                $length = compareValues($item->actual_length, $prItem->length);
                $weight = compareValues($item->actual_weight, $prItem->weight_needed);
            @endphp

            <div class="tw-overflow-hidden tw-rounded-ui-sm tw-border tw-bg-surface {{ $item->status === 'ng' ? 'tw-border-error' : 'tw-border-outline' }}">
                <div class="tw-flex tw-items-center tw-justify-between tw-border-b tw-px-3.5 tw-py-2.5 {{ $item->status === 'ng' ? 'tw-border-error/30 tw-bg-error-container' : 'tw-border-outline-variant tw-bg-surface-container' }}">
                    <div class="tw-flex tw-items-center tw-gap-2 tw-text-ui-xs tw-font-bold {{ $item->status === 'ng' ? 'tw-text-error' : 'tw-text-on-surface' }}">
                        <span class="ui-status-chip {{ $item->status === 'ng' ? 'ui-status-chip--error' : 'ui-status-chip--info' }}">{{ __('common.final_copy.item_number', ['number' => $index + 1]) }}</span>
                        <span>{{ $prItem->material_name }}</span>
                        @if($item->shipmentItem)
                            <span class="ui-status-chip ui-status-chip--neutral">{{ __('common.final_copy.consignment_quantity', ['count' => number_format($item->shipmentItem->shipped_qty)]) }}</span>
                            <span class="ui-status-chip ui-status-chip--neutral">{{ __('qc.page.actual_weight', ['weight' => \App\Support\NumberFormat::maxDecimals($item->shipmentItem->actual_weight_kg)]) }}</span>
                        @endif
                    </div>
                    @if($item->status === 'ok')
                        <span class="ui-status-chip ui-status-chip--success">
                                    <x-ui.icon name="circle-check" size="sm" />
                            <span>OK</span>
                        </span>
                    @else
                        <span class="ui-status-chip ui-status-chip--error">
                                    <x-ui.icon name="circle-x" size="sm" />
                            <span>{{ __('qc.copy.ng_defective') }}</span>
                        </span>
                    @endif
                </div>

                <div class="p-0">
                    <div class="table-responsive">
                        <table class="table table-bordered align-middle mb-0 tw-text-ui-xs w-100">
                            <thead class="table-light text-center">
                                <tr>
                                    <th scope="col" class="text-start" style="width: 110px;">{{ __('qc.copy.parameter') }}</th>
                                    <th scope="col">{{ __('qc.copy.shape') }}</th>
                                    <th scope="col">{{ __('qc.copy.thickness_mm') }}</th>
                                    <th scope="col">{{ __('qc.copy.inner_dia_mm') }}</th>
                                    <th scope="col">{{ __('qc.copy.outer_dia_mm') }}</th>
                                    <th scope="col">{{ __('qc.copy.width_mm') }}</th>
                                    <th scope="col">{{ __('qc.copy.length_mm') }}</th>
                                    <th scope="col">{{ __('qc.copy.qty') }}</th>
                                    <th scope="col">{{ __('qc.copy.weight_unit_kg') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {{-- Requested Specification Row --}}
                                <tr class="text-center">
                                    <td class="text-start tw-text-on-surface-variant fw-semibold tw-bg-surface-container">{{ __('qc.copy.requested') }}</td>
                                    <td class="tw-text-on-surface">{{ $prItem->shape ?? '-' }}</td>
                                    <td class="tw-text-on-surface ui-tabular-nums">{{ $prItem->thickness ?? '-' }}</td>
                                    <td class="tw-text-on-surface ui-tabular-nums">{{ $prItem->d_inner ?? '-' }}</td>
                                    <td class="tw-text-on-surface ui-tabular-nums">{{ $prItem->d_outer ?? '-' }}</td>
                                    <td class="tw-text-on-surface ui-tabular-nums">{{ $prItem->width ?? '-' }}</td>
                                    <td class="tw-text-on-surface ui-tabular-nums">{{ $prItem->length ?? '-' }}</td>
                                    <td class="tw-text-on-surface ui-tabular-nums">{{ number_format($prItem->quantity_value, 0) }}</td>
                                    <td class="tw-text-on-surface ui-tabular-nums">{{ $prItem->weight_needed ?? '-' }}</td>
                                </tr>
                                {{-- Actual Inspected Row --}}
                                <tr class="text-center">
                                    <td class="text-start fw-bold text-primary tw-bg-surface-container">{{ __('qc.copy.actual') }}</td>
                                    <td class="tw-text-on-surface">{{ $prItem->shape ?? '-' }}</td>
                                    <td class="ui-tabular-nums {{ $thick['class'] }}">{{ $thick['val'] }}</td>
                                    <td class="ui-tabular-nums {{ $dInner['class'] }}">{{ $dInner['val'] }}</td>
                                    <td class="ui-tabular-nums {{ $dOuter['class'] }}">{{ $dOuter['val'] }}</td>
                                    <td class="ui-tabular-nums {{ $width['class'] }}">{{ $width['val'] }}</td>
                                    <td class="ui-tabular-nums {{ $length['class'] }}">{{ $length['val'] }}</td>
                                    <td class="tw-text-on-surface ui-tabular-nums">{{ number_format($prItem->quantity_value, 0) }}</td>
                                    <td class="ui-tabular-nums {{ $weight['class'] }}">{{ $weight['val'] }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    @if($item->notes)
                        <div class="p-3 border-top tw-bg-surface-container">
                            <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase mb-1">{{ __('qc.copy.inspector_notes') }}</div>
                            <p class="mb-0 tw-text-on-surface tw-text-ui-xs">{{ $item->notes }}</p>
                        </div>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    {{-- NG Photographic Evidence Section --}}
    @if($inspection->status === 'ng')
        <x-ui.card
            :title="__('qc.copy.ng_photographic_evidence')"
            :description="__('qc.copy.visual_documentation_attached_to_justify_defective_material_findings')"
            class="border-danger"
        >
            <x-slot:actions>
                <span class="ui-status-chip ui-status-chip--error">{{ __('qc.copy.required_for_ng') }}</span>
            </x-slot:actions>

            @if($inspection->attachments->count() > 0)
                <div class="row g-3">
                    @foreach($inspection->attachments as $att)
                        <div class="col-6 col-md-4 col-lg-3">
                            <a
                                href="{{ route('attachments.show', $att) }}"
                                class="tw-relative tw-block tw-h-40 tw-overflow-hidden tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface-container hover:tw-opacity-95 image-preview-trigger"
                                title="{{ $att->file_name }}"
                            >
                                <img
                                    src="{{ route('attachments.show', $att) }}"
                                    alt="{{ $att->file_name }}"
                                    class="tw-h-full tw-w-full tw-object-cover"
                                >
                            </a>
                            <div class="tw-mt-1 tw-truncate tw-text-ui-xs tw-text-on-surface-variant" title="{{ $att->file_name }}">
                                {{ $att->file_name }}
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <x-ui.alert tone="warning">{{ __('qc.copy.no_ng_evidence_photos_have_been_uploaded_for_this_inspection_yet') }}</x-ui.alert>
            @endif

            {{-- Allow QC inspector to upload additional photos --}}
            @if(auth()->user()->role === 'qc')
                <div class="border-top mt-4 pt-3">
                    <form action="{{ route('qc.inspections.attachments.store', $inspection) }}" method="POST" enctype="multipart/form-data" data-async-submit>
                        @csrf
                        <label class="tw-text-on-surface tw-text-ui-xs fw-semibold mb-1" for="inspection-attachments">{{ __('qc.copy.add_supplemental_ng_evidence_photos') }}</label>
                        <div class="d-flex flex-column flex-sm-row gap-2">
                            <div class="flex-grow-1">
                                <input
                                    type="file"
                                    id="inspection-attachments"
                                    name="attachments[]"
                                    class="form-control form-control-sm @error('attachments') is-invalid @enderror @error('attachments.*') is-invalid @enderror"
                                    accept=".jpg,.jpeg,.png"
                                    multiple
                                    required
                                    aria-describedby="inspection-attachments-help"
                                >
                                <div class="tw-text-on-surface-variant tw-text-ui-xs mt-1" id="inspection-attachments-help">
                                    {{ __('qc.copy.jpg_jpeg_or_png_format_maximum_10mb_per_file') }}
                                </div>
                                @error('attachments')
                                    <div class="text-danger tw-text-ui-xs mt-1">{{ $message }}</div>
                                @enderror
                                @error('attachments.*')
                                    <div class="text-danger tw-text-ui-xs mt-1">{{ $message }}</div>
                                @enderror
                            </div>
                            <div>
                                <x-ui.button type="submit" variant="danger" size="sm" class="tw-w-full sm:tw-w-auto">
                                    <x-ui.icon name="upload" size="sm" />
                                    <span>{{ __('qc.copy.upload_photos') }}</span>
                                </x-ui.button>
                            </div>
                        </div>
                    </form>
                </div>
            @endif
        </x-ui.card>
    @endif
</div>
@endsection
