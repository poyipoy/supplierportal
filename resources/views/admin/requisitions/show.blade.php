@extends('layouts.app')

@section('title', 'Purchase Requisition Details - ADASI Portal')
@section('page-title', 'Purchase Requisition Details')

@section('content')
@php($totalRequestedWeight = $pr->items->sum(fn ($item) => (float) $item->total_weight))
<div class="tw-grid tw-gap-4">
    <x-ui.breadcrumb :items="['Dashboard' => route('admin.dashboard'), ($pr->pr_number ?? 'Purchase Requisition') => null]" />

    <x-ui.page-header :title="$pr->pr_number ?? 'Purchase Requisition'" description="Review the approved requisition context and requested material lines without editing procurement data." eyebrow="Purchase Requisition Details">
        <x-slot:meta>
            <x-status-badge type="pr" :status="$pr->status" size="lg" />
            <x-ui.status-chip tone="neutral" icon="lock">Read-only Admin View</x-ui.status-chip>
        </x-slot:meta>
        <x-slot:actions>
            <x-ui.button :href="route('admin.dashboard')" variant="ghost" size="sm"><x-ui.icon name="arrow-left" /> Back to Dashboard</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <section class="tw-border-y tw-border-outline-variant tw-bg-surface" aria-labelledby="pr-summary-title">
        <h2 id="pr-summary-title" class="tw-sr-only">Requisition summary</h2>
        <dl class="tw-m-0 tw-grid tw-grid-cols-2 xl:tw-grid-cols-4">
            <div class="tw-border-b tw-border-r tw-border-outline-variant tw-p-4 xl:tw-border-b-0">
                <dt class="tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wide tw-text-on-surface-variant">Procurement Period</dt>
                <dd class="tw-m-0 tw-mt-1 tw-font-semibold">{{ $pr->period->display_label ?? $pr->period->name ?? '-' }}</dd>
            </div>
            <div class="tw-border-b tw-border-outline-variant tw-p-4 xl:tw-border-b-0 xl:tw-border-r">
                <dt class="tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wide tw-text-on-surface-variant">Total Requested Weight</dt>
                <dd class="ui-tabular-nums tw-m-0 tw-mt-1 tw-font-semibold">{{ number_format($totalRequestedWeight, 2) }} kg</dd>
            </div>
            <div class="tw-border-r tw-border-outline-variant tw-p-4">
                <dt class="tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wide tw-text-on-surface-variant">Created By</dt>
                <dd class="tw-m-0 tw-mt-1 tw-font-semibold">{{ $pr->creator->name ?? '-' }}</dd>
            </div>
            <div class="tw-p-4">
                <dt class="tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wide tw-text-on-surface-variant">Date Created</dt>
                <dd class="tw-m-0 tw-mt-1 tw-font-semibold">{{ $pr->created_at?->format('d M Y, H:i') ?? '-' }}</dd>
            </div>
        </dl>
    </section>

    <section class="tw-border tw-border-outline-variant tw-bg-surface" aria-labelledby="pr-notes-title">
        <header class="tw-border-b tw-border-outline-variant tw-px-4 tw-py-3">
            <h2 id="pr-notes-title" class="tw-m-0 tw-text-ui-sm tw-font-semibold">Requisition Instructions</h2>
        </header>
        <div class="tw-p-4 tw-text-ui-sm tw-whitespace-pre-line {{ $pr->notes ? 'tw-text-on-surface' : 'tw-text-on-surface-variant' }}">{{ $pr->notes ?: 'No additional instructions were provided.' }}</div>
    </section>

    <x-ui.data-table :title="'Material Requirements (' . $pr->items->count() . ' items)'" description="Required specifications, shapes, quantities, and computed weights.">
        <div class="ui-data-table__scroll tw-overflow-x-auto">
            <table class="table table-hover align-middle tw-m-0 tw-w-full tw-text-ui-xs">
                <thead class="table-light text-center">
                    <tr>
                        <th scope="col">No</th>
                        <th scope="col">HS Code</th>
                        <th scope="col">Material</th>
                        <th scope="col">Shape &amp; Dimensions (mm)</th>
                        <th scope="col">Qty</th>
                        <th scope="col" class="text-end">Weight / Unit (kg)</th>
                        <th scope="col" class="text-end">Total Weight (kg)</th>
                        <th scope="col">Remark</th>
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
                            <td class="ui-tabular-nums text-end">{{ number_format($item->weight_needed, 4) }}</td>
                            <td class="ui-tabular-nums text-end fw-semibold text-primary">{{ number_format($item->total_weight, 4) }}</td>
                            <td>{{ $item->remark ?: '-' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8"><x-ui.empty-state icon="package" title="No material records" description="This requisition has no requested material lines." /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.data-table>
</div>
@endsection
