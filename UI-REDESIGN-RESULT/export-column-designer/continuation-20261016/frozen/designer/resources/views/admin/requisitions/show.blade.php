@extends('layouts.app')

@section('title', __('admin.copy.purchase_requisition_details').': '.($pr->pr_number ?? __('admin.copy.draft')).' - ADASI Portal')
@section('page-title', __('admin.copy.purchase_requisition_details').': '.($pr->pr_number ?? __('admin.copy.draft')))

@section('content')
@php($totalRequestedWeight = $pr->items->sum(fn ($item) => (float) $item->total_weight))
<div class="tw-grid tw-gap-4">
    <x-ui.breadcrumb :items="[__('purchasing.breadcrumbs.dashboard') => route('admin.dashboard'), ($pr->pr_number ?? __('terms.purchase_requisition')) => null]" />

    <x-ui.page-header :title="$pr->pr_number ?? __('admin.copy.purchase_requisition')" :description="__('admin.copy.review_the_approved_requisition_context_and_requested_material_lines_without_editing_procurement_dat')" :eyebrow="__('admin.copy.purchase_requisition_details')">
        <x-slot:meta>
            <x-status-badge type="pr" :status="$pr->status" size="lg" />
            <x-ui.status-chip tone="neutral" icon="lock">{{ __('admin.copy.read_only_admin_view') }}</x-ui.status-chip>
        </x-slot:meta>
        <x-slot:actions>
            <x-ui.button :href="route('admin.dashboard')" variant="ghost" size="sm"><x-ui.icon name="arrow-left" /> {{ __('admin.copy.back_to_dashboard') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <section class="tw-border-y tw-border-outline tw-bg-surface-container" aria-labelledby="pr-summary-title">
        <h2 id="pr-summary-title" class="tw-sr-only">{{ __('admin.copy.requisition_summary') }}</h2>
        <dl class="tw-m-0 tw-grid tw-grid-cols-2 xl:tw-grid-cols-4">
            <div class="tw-border-b tw-border-r tw-border-outline-variant tw-p-4 xl:tw-border-b-0">
                <dt class="tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wide tw-text-on-surface-variant">{{ __('admin.copy.procurement_period') }}</dt>
                <dd class="tw-m-0 tw-mt-1 tw-font-semibold">{{ $pr->period->display_label ?? $pr->period->name ?? '-' }}</dd>
            </div>
            <div class="tw-border-b tw-border-outline-variant tw-p-4 xl:tw-border-b-0 xl:tw-border-r">
                <dt class="tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wide tw-text-on-surface-variant">{{ __('admin.copy.total_requested_weight') }}</dt>
                <dd class="ui-tabular-nums tw-m-0 tw-mt-1 tw-font-semibold">{{ number_format($totalRequestedWeight, 2) }} kg</dd>
            </div>
            <div class="tw-border-r tw-border-outline-variant tw-p-4">
                <dt class="tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wide tw-text-on-surface-variant">{{ __('admin.copy.created_by') }}</dt>
                <dd class="tw-m-0 tw-mt-1 tw-font-semibold">{{ $pr->creator->name ?? '-' }}</dd>
            </div>
            <div class="tw-p-4">
                <dt class="tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wide tw-text-on-surface-variant">{{ __('admin.copy.date_created') }}</dt>
                <dd class="tw-m-0 tw-mt-1 tw-font-semibold">{{ $pr->created_at ? $regionalFormatter->timestamp($pr->created_at, 'datetime_comma') : '-' }}</dd>
            </div>
        </dl>
    </section>

    <section class="tw-border tw-border-outline tw-bg-surface" aria-labelledby="pr-notes-title">
        <header class="tw-border-b tw-border-outline-variant tw-bg-surface-container tw-px-4 tw-py-3">
            <h2 id="pr-notes-title" class="tw-m-0 tw-text-ui-sm tw-font-semibold">{{ __('admin.copy.requisition_instructions') }}</h2>
        </header>
        <div class="tw-p-4 tw-text-ui-sm tw-whitespace-pre-line {{ $pr->notes ? 'tw-text-on-surface' : 'tw-text-on-surface-variant' }}">{{ $pr->notes ?: __('admin.copy.no_additional_instructions_were_provided') }}</div>
    </section>

    <x-ui.data-table :title="trans_choice('admin.audit_ui.material_count', $pr->items->count(), ['count' => $pr->items->count()])" :description="__('admin.copy.required_specifications_shapes_quantities_and_computed_weights')">
        <div class="ui-data-table__scroll tw-overflow-x-auto">
            <table class="table table-hover align-middle tw-m-0 tw-w-full tw-text-ui-xs">
                <thead class="table-light text-center">
                    <tr>
                        <th scope="col">{{ __('admin.copy.no') }}</th>
                        <th scope="col">HS Code</th>
                        <th scope="col">{{ __('admin.copy.material') }}</th>
                        <th scope="col">{{ __('admin.copy.shape_dimensions_mm') }}</th>
                        <th scope="col">{{ __('admin.copy.qty') }}</th>
                        <th scope="col" class="text-end">{{ __('admin.copy.weight_unit_kg') }}</th>
                        <th scope="col" class="text-end">{{ __('admin.copy.total_weight_kg') }}</th>
                        <th scope="col">{{ __('admin.copy.remark') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($pr->items as $index => $item)
                        <tr>
                            <td class="ui-tabular-nums text-center text-muted">{{ $index + 1 }}</td>
                            <td class="font-monospace text-center fw-semibold">{{ $item->hs_code ?: '-' }}</td>
                            <td class="fw-semibold">{{ $item->material_name }}</td>
                            <td class="text-center">
                                @if($item->shape)
                                    <span class="tw-font-medium">{{ $item->shape }}</span>
                                    <div class="tw-mt-1 tw-text-on-surface-variant">{{ $item->dimension_label }}</div>
                                @else
                                    -
                                @endif
                            </td>
                            <td class="ui-tabular-nums text-center fw-semibold">{{ number_format($item->quantity_value, 0) }}</td>
                            <td class="ui-tabular-nums text-end">{{ \App\Support\NumberFormat::maxDecimals($item->weight_needed) }}</td>
                            <td class="ui-tabular-nums text-end fw-semibold text-primary">{{ \App\Support\NumberFormat::maxDecimals($item->total_weight) }}</td>
                            <td>{{ $item->remark ?: '-' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8"><x-ui.empty-state icon="package" :title="__('admin.copy.no_material_records')" :description="__('admin.copy.this_requisition_has_no_requested_material_lines')" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.data-table>
</div>
@endsection
