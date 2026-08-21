@extends('layouts.app')

@section('title', 'Purchase Requisition Details - ADASI Portal')
@section('page-title', 'Purchase Requisition Details')

@section('content')
<div class="tw-grid tw-gap-6">
    <x-ui.breadcrumb :items="[
        'Dashboard' => route('admin.dashboard'),
        ($pr->pr_number ?? 'Purchase Requisition') => null,
    ]" />

    <x-ui.page-header title="{{ $pr->pr_number ?? 'Purchase Requisition' }}" description="Read-only requisition details and requested material lines." eyebrow="Admin">
        <x-slot:meta><x-status-badge type="pr" :status="$pr->status" size="lg" /></x-slot:meta>
    </x-ui.page-header>

    <x-ui.card title="Requisition Summary">
        <dl class="tw-m-0 tw-grid tw-gap-4 md:tw-grid-cols-2">
            <div><dt class="tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wide tw-text-on-surface-variant">Period</dt><dd class="tw-m-0 tw-mt-1 tw-font-medium">{{ $pr->period->display_label ?? $pr->period->name ?? '-' }}</dd></div>
            <div><dt class="tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wide tw-text-on-surface-variant">Created By</dt><dd class="tw-m-0 tw-mt-1 tw-font-medium">{{ $pr->creator->name ?? '-' }}</dd></div>
            <div><dt class="tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wide tw-text-on-surface-variant">Date Created</dt><dd class="tw-m-0 tw-mt-1 tw-font-medium">{{ $pr->created_at?->format('d F Y, H:i') ?? '-' }}</dd></div>
            <div><dt class="tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wide tw-text-on-surface-variant">Status</dt><dd class="tw-m-0 tw-mt-1 tw-font-medium">{{ ucwords(str_replace('_', ' ', $pr->status)) }}</dd></div>
            <div class="md:tw-col-span-2"><dt class="tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wide tw-text-on-surface-variant">Header Notes</dt><dd class="tw-m-0 tw-mt-1 tw-font-medium">{{ $pr->notes ?: '-' }}</dd></div>
        </dl>
    </x-ui.card>

    <x-ui.data-table title="Material List" description="{{ $pr->items->count() }} requested item(s).">
        <table class="table table-hover align-middle tw-m-0 tw-w-full tw-text-ui-sm">
            <thead class="table-light text-center">
                <tr><th scope="col">No</th><th scope="col">HS Code</th><th scope="col">Material</th><th scope="col">Shape &amp; Dimensions (mm)</th><th scope="col">Qty</th><th scope="col">Weight/Unit (Kg)</th><th scope="col">Total Weight (Kg)</th><th scope="col">Remark</th></tr>
            </thead>
            <tbody>
                @forelse($pr->items as $index => $item)
                    <tr>
                        <td class="text-center">{{ $index + 1 }}</td>
                        <td class="text-center">{{ $item->hs_code ?: '-' }}</td>
                        <td>{{ $item->material_name }}</td>
                        <td class="text-center">@if($item->shape)<x-ui.status-chip tone="neutral">{{ $item->shape }}</x-ui.status-chip><div class="tw-mt-1 tw-text-ui-xs tw-text-on-surface-variant">{{ $item->dimension_label }}</div>@else - @endif</td>
                        <td class="ui-tabular-nums text-center">{{ number_format($item->quantity_value, 0) }}</td>
                        <td class="ui-tabular-nums text-end">{{ number_format($item->weight_needed, 2) }}</td>
                        <td class="ui-tabular-nums text-end fw-semibold">{{ number_format($item->total_weight, 2) }}</td>
                        <td>{{ $item->remark ?: '-' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8"><x-ui.empty-state icon="package" title="No material data" /></td></tr>
                @endforelse
            </tbody>
        </table>
        <x-slot:pagination>
            <div class="tw-flex tw-justify-end"><x-ui.button :href="route('admin.dashboard')" variant="ghost"><x-ui.icon name="arrow-left" /> Back to Dashboard</x-ui.button></div>
        </x-slot:pagination>
    </x-ui.data-table>
</div>
@endsection
