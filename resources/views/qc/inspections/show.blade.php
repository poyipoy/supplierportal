@extends('layouts.app')

@section('title', 'QC Inspection Details: ' . ($inspection->purchaseOrder->po_number ?? 'N/A') . ' - ADASI Portal')
@section('page-title', 'QC Inspection Details')

@php
    $returnUrl = request()->query('return_url') ?? request()->input('return_url');
    $isFromClaim = $returnUrl && (str_contains($returnUrl, '/claims') || str_contains($returnUrl, 'claims'));
    $safeReturnUrl = \App\Support\PurchasingNavigation::isSafeUrl($returnUrl) ? $returnUrl : null;

    $backUrl = $safeReturnUrl ?: (auth()->user()->role === 'purchasing'
        ? route('purchasing.purchase-orders.show', $inspection->purchaseOrder)
        : route('qc.inspections.index'));

    $backLabel = $isFromClaim
        ? 'Back to Material Claim'
        : (auth()->user()->role === 'purchasing' ? 'Back to PO' : 'Back to List');

    $breadcrumbDashboard = auth()->user()->role === 'purchasing' ? route('purchasing.dashboard') : route('qc.dashboard');
    $breadcrumbParentUrl = $isFromClaim
        ? ($safeReturnUrl ?: route('purchasing.claims.index'))
        : (auth()->user()->role === 'purchasing' ? route('purchasing.purchase-orders.index') : route('qc.inspections.index'));
    $breadcrumbParentLabel = $isFromClaim ? 'Material Claims' : 'QC Inspections';
@endphp

@section('content')
<div class="tw-grid tw-gap-4">
    {{-- Breadcrumb & Compact Page Header --}}
    <x-ui.breadcrumb :items="[
        'Dashboard' => $breadcrumbDashboard,
        $breadcrumbParentLabel => $breadcrumbParentUrl,
        ('PO ' . ($inspection->purchaseOrder->po_number ?? 'N/A')) => null,
    ]" />

    <x-ui.page-header
        :title="'Inspection Report — ' . ($inspection->purchaseOrder->po_number ?? 'N/A')"
        eyebrow="Quality Control Inspection"
        description="Technical measurement verification, tolerance comparison, and photographic evidence."
    >
        <x-slot:actions>
            <div class="tw-flex tw-flex-wrap tw-items-center tw-gap-2">
                @if($inspection->status === 'ok')
                    <span class="ui-status-chip ui-status-chip--success">
                    <x-ui.icon name="circle-check" size="sm" />
                        <span>OUTCOME: OK (PASSED)</span>
                    </span>
                @else
                    <span class="ui-status-chip ui-status-chip--error">
                    <x-ui.icon name="circle-x" size="sm" />
                        <span>OUTCOME: NG (DEFECTIVE)</span>
                    </span>
                @endif

                <x-ui.button
                    :href="route('shared.pdf.qc-inspection', $inspection)"
                    variant="outline"
                    size="sm"
                    target="_blank"
                    title="Print Official QC Inspection Report"
                    data-pdf-confirm
                >
                    <x-ui.icon name="printer" size="sm" />
                    <span>Print PDF</span>
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
        <x-ui.card title="Inspection and Arrival Summary">
        <div class="tw-grid tw-gap-3 sm:tw-grid-cols-2 lg:tw-grid-cols-4">
            <div class="tw-p-2.5 tw-bg-surface-container border rounded">
                <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">Supplier</div>
                <div class="fw-bold tw-text-on-surface tw-text-ui-xs tw-mt-0.5">{{ $inspection->purchaseOrder->supplier->company_name ?? $inspection->purchaseOrder->supplier->name ?? '-' }}</div>
            </div>
            <div class="tw-p-2.5 tw-bg-surface-container border rounded">
                <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">Inspected By</div>
                <div class="fw-bold tw-text-on-surface tw-text-ui-xs tw-mt-0.5">{{ $inspection->inspector->name ?? '-' }}</div>
            </div>
            <div class="tw-p-2.5 tw-bg-surface-container border rounded">
                <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">Inspection Timestamp</div>
                <div class="fw-bold tw-text-on-surface tw-text-ui-xs tw-mt-0.5 ui-tabular-nums">{{ $inspection->inspected_at ? $inspection->inspected_at->format('d M Y, H:i') : '-' }}</div>
            </div>
            <div class="tw-p-2.5 tw-bg-surface-container border rounded">
                <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">Material Arrival Date</div>
                <div class="fw-bold tw-text-on-surface tw-text-ui-xs tw-mt-0.5 ui-tabular-nums">{{ $inspection->purchaseOrder->actual_arrival ? $inspection->purchaseOrder->actual_arrival->format('d M Y') : '-' }}</div>
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
                        <span class="ui-status-chip {{ $item->status === 'ng' ? 'ui-status-chip--error' : 'ui-status-chip--info' }}">Item #{{ $index + 1 }}</span>
                        <span>{{ $prItem->material_name }}</span>
                    </div>
                    @if($item->status === 'ok')
                        <span class="ui-status-chip ui-status-chip--success">
                                    <x-ui.icon name="circle-check" size="sm" />
                            <span>OK</span>
                        </span>
                    @else
                        <span class="ui-status-chip ui-status-chip--error">
                                    <x-ui.icon name="circle-x" size="sm" />
                            <span>NG (DEFECTIVE)</span>
                        </span>
                    @endif
                </div>

                <div class="p-0">
                    <div class="table-responsive">
                        <table class="table table-bordered align-middle mb-0 tw-text-ui-xs w-100">
                            <thead class="table-light text-center">
                                <tr>
                                    <th scope="col" class="text-start" style="width: 110px;">Parameter</th>
                                    <th scope="col">Shape</th>
                                    <th scope="col">Thickness (mm)</th>
                                    <th scope="col">Inner Dia. (mm)</th>
                                    <th scope="col">Outer Dia. (mm)</th>
                                    <th scope="col">Width (mm)</th>
                                    <th scope="col">Length (mm)</th>
                                    <th scope="col">Qty</th>
                                    <th scope="col">Weight/Unit (Kg)</th>
                                </tr>
                            </thead>
                            <tbody>
                                {{-- Requested Specification Row --}}
                                <tr class="text-center">
                                    <td class="text-start tw-text-on-surface-variant fw-semibold tw-bg-surface-container">Requested</td>
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
                                    <td class="text-start fw-bold text-primary tw-bg-surface-container">Actual</td>
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
                            <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase mb-1">Inspector Notes:</div>
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
            title="NG Photographic Evidence"
            description="Visual documentation attached to justify defective material findings."
            class="border-danger"
        >
            <x-slot:actions>
                <span class="ui-status-chip ui-status-chip--error">Required for NG</span>
            </x-slot:actions>

            @if($inspection->attachments->count() > 0)
                <div class="row g-3">
                    @foreach($inspection->attachments as $att)
                        <div class="col-6 col-md-4 col-lg-3">
                            <a
                                href="{{ route('attachments.show', $att->id) }}"
                                class="tw-relative tw-block tw-h-40 tw-overflow-hidden tw-rounded-ui-sm tw-border tw-border-outline-variant tw-bg-surface-container hover:tw-opacity-95 image-preview-trigger"
                                title="{{ $att->file_name }}"
                            >
                                <img
                                    src="{{ route('attachments.show', $att->id) }}"
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
                <x-ui.alert tone="warning">No NG evidence photos have been uploaded for this inspection yet.</x-ui.alert>
            @endif

            {{-- Allow QC inspector to upload additional photos --}}
            @if(auth()->user()->role === 'qc')
                <div class="border-top mt-4 pt-3">
                    <form action="{{ route('qc.inspections.attachments.store', $inspection) }}" method="POST" enctype="multipart/form-data">
                        @csrf
                        <label class="tw-text-on-surface tw-text-ui-xs fw-semibold mb-1" for="inspection-attachments">Add Supplemental NG Evidence Photos</label>
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
                                    JPG, JPEG, or PNG format. Maximum 10MB per file.
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
                                    <span>Upload Photos</span>
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
