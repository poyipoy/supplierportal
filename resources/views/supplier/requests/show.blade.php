@extends('layouts.app')

@section('title', __('supplier_requests.detail.title', ['number' => $pr->pr_number ?? '-']))
@section('page-title', __('supplier_requests.detail.title', ['number' => $pr->pr_number ?? '-']))

@php
    $quotation = $action['quotation'];
    $statusChipTones = [
        'draft' => 'neutral',
        'revision_requested' => 'warning',
        'submitted' => 'success',
        'accepted' => 'info',
        'rejected' => 'error',
        'all_unavailable' => 'neutral',
    ];
    $dimensionOrder = \App\Models\PrItem::FIXED_DIMENSION_ORDER;
@endphp

@section('content')
<div class="tw:grid tw:gap-4">
    <x-ui.breadcrumb :items="[
        __('purchasing.breadcrumbs.dashboard') => route('supplier.dashboard'),
        __('supplier_requests.title') => route('supplier.requests.index'),
        ($pr->pr_number ?? '-') => null,
    ]" />

    <x-ui.page-header
        :title="__('supplier_requests.detail.title', ['number' => $pr->pr_number ?? '-'])"
        :eyebrow="__('supplier_requests.eyebrow')"
    >
        <x-slot:actions>
            <x-ui.button :href="route('supplier.requests.index')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>{{ __('supplier_requests.detail.back') }}</span>
            </x-ui.button>
            @if($action['url'])
                <x-ui.button :href="$action['url']" size="sm" data-supplier-request-action="{{ $action['type'] }}">
                    <span>{{ __('supplier_requests.actions.'.$action['type']) }}</span>
                </x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.form-section :title="__('supplier.copy.procurement_context')">
        <div class="tw:grid tw:gap-3 tw:sm:grid-cols-2 tw:lg:grid-cols-3">
            <div class="tw:p-2.5 tw:bg-surface-low border rounded">
                <div class="tw:text-on-surface-variant tw:text-ui-xs fw-semibold tw:uppercase">{{ __('supplier.copy.procurement_period') }}</div>
                <div class="fw-bold tw:text-on-surface tw:text-ui-sm tw:mt-0.5">{{ $pr->period?->display_label ?? '-' }}</div>
            </div>
            <div class="tw:p-2.5 tw:bg-surface-low border rounded">
                <div class="tw:text-on-surface-variant tw:text-ui-xs fw-semibold tw:uppercase">{{ __('supplier.copy.requisition_reference') }}</div>
                <div class="fw-bold text-primary tw:text-ui-sm tw:mt-0.5">{{ $pr->pr_number ?? '-' }}</div>
            </div>
            <div class="tw:sm:col-span-2 tw:lg:col-span-1 tw:p-2.5 tw:bg-surface-low border rounded">
                <div class="tw:text-on-surface-variant tw:text-ui-xs fw-semibold tw:uppercase">{{ __('supplier.copy.purchasing_instructions') }}</div>
                <div class="tw:text-on-surface tw:text-ui-xs tw:mt-0.5">{{ $pr->notes ?: __('supplier.copy.no_additional_notes_provided') }}</div>
            </div>
        </div>
        <div class="tw:mt-3">
            @include('partials.procurement-logistics', ['port' => $pr->port_of_discharge, 'incoterm' => $pr->incoterm])
        </div>
    </x-ui.form-section>

    <x-ui.form-section :title="__('supplier_requests.detail.my_quotation')" :description="__('supplier_requests.detail.my_quotation_description')">
        <dl class="tw:m-0 tw:grid tw:gap-3 tw:sm:grid-cols-2">
            <div>
                <dt class="tw:text-ui-xs tw:font-semibold tw:uppercase tw:text-on-surface-variant">{{ __('supplier.copy.my_quotation_status') }}</dt>
                <dd class="tw:m-0 tw:mt-1">
                    @if($quotation)
                        <span class="ui-status-chip ui-status-chip--{{ $statusChipTones[$quotation->status] ?? 'neutral' }}">{{ \App\Support\StatusHelper::quotationLabel($quotation->status) }}</span>
                    @else
                        <span class="ui-status-chip ui-status-chip--error">{{ __('supplier_requests.not_responded') }}</span>
                    @endif
                </dd>
            </div>
            <div>
                <dt class="tw:text-ui-xs tw:font-semibold tw:uppercase tw:text-on-surface-variant">{{ __('supplier_requests.columns.estimated_delivery') }}</dt>
                <dd class="tw:m-0 tw:mt-1 tw:text-ui-sm tw:text-on-surface ui-tabular-nums">{{ $quotation?->estimated_delivery ? $regionalFormatter->date($quotation->estimated_delivery) : '-' }}</dd>
            </div>
        </dl>
        @unless($action['url'])
            <p class="tw:m-0 tw:mt-3 tw:text-ui-xs tw:text-on-surface-variant">{{ __('supplier_requests.no_action') }}</p>
        @endunless
    </x-ui.form-section>

    <x-ui.data-table :title="__('supplier_requests.detail.items')" :description="__('supplier_requests.detail.items_description')">
        <table class="table align-middle mb-0 tw:text-ui-xs w-100" id="supplierRequestItemsTable">
            <thead class="table-light">
                <tr>
                    <th scope="col" class="text-center">{{ __('supplier.copy.no') }}</th>
                    <th scope="col">{{ __('supplier.copy.material') }}</th>
                    <th scope="col" class="text-end">{{ __('supplier.copy.qty') }}</th>
                    @foreach($dimensionOrder as $dimension)
                        <th scope="col" class="text-end">{{ \App\Models\PrItem::dimensionLabel($dimension) }}</th>
                    @endforeach
                    <th scope="col" class="text-end">{{ __('supplier.copy.kg_per_unit') }}</th>
                    <th scope="col" class="text-end">{{ __('supplier.copy.total_kg') }}</th>
                    <th scope="col">{{ __('supplier.copy.notes') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($pr->items as $index => $item)
                    @php
                        $relevant = \App\Models\PrItem::relevantDimensionFields($item->shape);
                    @endphp
                    <tr>
                        <td class="text-center ui-tabular-nums tw:text-on-surface-variant">{{ $index + 1 }}</td>
                        <td>
                            <div class="fw-bold tw:text-on-surface">{{ $item->material_name }}</div>
                            <div class="tw:text-on-surface-variant tw:text-ui-xs tw:mt-0.5">{{ $item->hs_code ? 'HS '.$item->hs_code.' · ' : '' }}{{ $item->shape ?: '-' }}</div>
                        </td>
                        <td class="text-end ui-tabular-nums fw-semibold">{{ $item->quantity_value }}</td>
                        @foreach($dimensionOrder as $dimension)
                            <td class="text-end ui-tabular-nums">{{ in_array($dimension, $relevant, true) ? \App\Support\NumberFormat::maxDecimals($item->{$dimension}) : '—' }}</td>
                        @endforeach
                        <td class="text-end ui-tabular-nums">{{ \App\Support\NumberFormat::maxDecimals($item->weight_needed) }}</td>
                        <td class="text-end ui-tabular-nums fw-semibold">{{ \App\Support\NumberFormat::maxDecimals($item->total_weight) }}</td>
                        <td class="tw:text-on-surface-variant">{{ $item->remark ?: '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-ui.data-table>
</div>
@endsection
