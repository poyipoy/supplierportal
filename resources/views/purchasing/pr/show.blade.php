@extends('layouts.app')

@section('title', 'Material Requisition Details - ADASI Portal')
@section('page-title', 'Requisition Details Material')

@push('styles')
<style>
    .pr-detail-list > div {
        border-bottom: 1px solid var(--md-outline-variant);
        display: grid;
        gap: .35rem;
        padding-block: .85rem;
    }

    .pr-detail-list > div:last-child {
        border-bottom: 0;
        padding-bottom: 0;
    }

    .pr-detail-list > div:first-child {
        padding-top: 0;
    }

    .pr-detail-list dt {
        color: var(--md-on-surface-variant);
        font-size: var(--ui-font-size-xs);
        font-weight: 700;
        letter-spacing: .025em;
        margin: 0;
        text-transform: uppercase;
    }

    .pr-detail-list dd {
        color: var(--md-on-surface);
        margin: 0;
        min-width: 0;
    }

    .pr-best-quotation > * {
        background: var(--md-success-container) !important;
        color: var(--md-on-success-container);
    }

    .pr-timeline {
        list-style: none;
        margin: 0;
        padding: 0;
        position: relative;
    }

    .pr-timeline::before {
        background: var(--md-outline-variant);
        content: '';
        inset-block: .6rem 1.25rem;
        inset-inline-start: .6rem;
        position: absolute;
        width: 1px;
    }

    .pr-timeline-item {
        min-height: 3.5rem;
        padding-inline-start: 2.25rem;
        position: relative;
    }

    .pr-timeline-marker {
        background: var(--md-surface);
        border: 2px solid var(--md-outline-strong);
        border-radius: var(--md-shape-full);
        height: 1.25rem;
        inset-block-start: .15rem;
        inset-inline-start: 0;
        position: absolute;
        width: 1.25rem;
        z-index: 1;
    }

    .pr-timeline-item.is-complete .pr-timeline-marker {
        background: var(--md-primary);
        border-color: var(--md-primary);
        box-shadow: inset 0 0 0 3px var(--md-surface);
    }

    .pr-timeline-item.is-current .pr-timeline-marker {
        background: var(--md-warning);
        border-color: var(--md-warning);
        box-shadow: inset 0 0 0 3px var(--md-surface);
    }

    @media (min-width: 768px) {
        .pr-detail-list > div {
            grid-template-columns: minmax(9rem, .7fr) minmax(0, 1.3fr);
        }
    }
</style>
@endpush

@section('content')
@php
    $hasReachedSubmitted = in_array($pr->status, ['submitted', 'rejected', 'bidding', 'completed'], true);
    $hasReachedBidding = in_array($pr->status, ['bidding', 'completed'], true);
@endphp

<div class="tw-grid tw-gap-6">
    <x-ui.breadcrumb :items="[
        'Dashboard' => route('purchasing.dashboard'),
        'Purchase Requisition' => \App\Support\PurchasingNavigation::backUrl('purchasing.requisitions.index'),
        ($pr->pr_number ?? 'Draft') => null,
    ]" />

    <x-ui.page-header
        :title="$pr->pr_number ?? 'Requisition draft'"
        eyebrow="Purchase requisition"
        description="Review material requirements, invited suppliers, quotation responses, and workflow progress."
    >
        <x-slot:actions>
            <x-ui.button :href="route('purchasing.export.requisitions.detail', $pr)" variant="secondary" size="sm" data-async-export>
                <x-slot:leading><i class="bi bi-file-earmark-excel" aria-hidden="true"></i></x-slot:leading>
                Export Excel
            </x-ui.button>
            <x-status-badge type="pr" :status="$pr->status" size="lg" />
        </x-slot:actions>
    </x-ui.page-header>

    <div class="tw-grid tw-items-start tw-gap-6 xl:tw-grid-cols-[minmax(0,2fr)_minmax(19rem,1fr)]">
        <div class="tw-grid tw-min-w-0 tw-gap-6">
            <x-ui.card title="Requisition overview" description="Core procurement context and supplier audience.">
                <dl class="pr-detail-list tw-m-0">
                    <div>
                        <dt>Period</dt>
                        <dd class="tw-font-semibold">{{ $pr->period->name }} ({{ str_pad($pr->period->month, 2, '0', STR_PAD_LEFT) }}/{{ $pr->period->year }})</dd>
                    </div>
                    <div>
                        <dt>Date created</dt>
                        <dd class="ui-tabular-nums tw-font-medium">{{ $pr->created_at->format('d F Y, H:i') }}</dd>
                    </div>
                    <div>
                        <dt>Created by</dt>
                        <dd class="tw-font-medium">{{ $pr->creator->name ?? '-' }}</dd>
                    </div>
                    <div>
                        <dt>Invited suppliers</dt>
                        <dd>
                            @if($pr->invitedSuppliers->isEmpty())
                                <span class="tw-inline-flex tw-rounded-ui-full tw-bg-surface-container tw-px-2.5 tw-py-1 tw-text-ui-xs tw-font-semibold">All registered suppliers</span>
                            @else
                                <div class="tw-flex tw-flex-wrap tw-gap-1.5">
                                    @foreach($pr->invitedSuppliers as $supplier)
                                        <span class="tw-inline-flex tw-rounded-ui-full tw-bg-primary-container tw-px-2.5 tw-py-1 tw-text-ui-xs tw-font-semibold tw-text-primary-container-foreground">
                                            {{ $supplier->supplier->company_name ?? $supplier->name }}
                                        </span>
                                    @endforeach
                                </div>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt>Additional notes</dt>
                        <dd>
                            @if($pr->notes)
                                <span class="tw-whitespace-pre-line">{{ $pr->notes }}</span>
                            @else
                                <span class="tw-text-on-surface-variant">No notes</span>
                            @endif
                        </dd>
                    </div>
                </dl>
            </x-ui.card>

            <x-ui.data-table
                :title="'Material list (' . $pr->items->count() . ' items)'"
                description="Calculated unit and total weights are shown with their HS-code resolution source."
            >
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light text-center">
                        <tr>
                            <th>No</th>
                            <th>HS Code</th>
                            <th>Material Name</th>
                            <th>Shape &amp; Dimensions (mm)</th>
                            <th>Qty</th>
                            <th>KG / Unit</th>
                            <th>Total Weight (Kg)</th>
                            <th>Remark</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($pr->items as $index => $item)
                            <tr>
                                <td class="text-center ui-tabular-nums">{{ $index + 1 }}</td>
                                <td class="text-center">
                                    <div class="tw-font-medium">{{ $item->hs_code ?? '-' }}</div>
                                    <div class="tw-mt-1">
                                        @if($item->hs_code_source === 'manual')
                                            <x-ui.status-chip tone="warning">Manual</x-ui.status-chip>
                                        @elseif($item->hs_code_resolution_status === 'matched')
                                            <x-ui.status-chip tone="success">Auto</x-ui.status-chip>
                                        @elseif($item->hs_code_source === 'legacy')
                                            <x-ui.status-chip tone="neutral">Legacy</x-ui.status-chip>
                                        @else
                                            <x-ui.status-chip tone="neutral">{{ str_replace('_', ' ', $item->hs_code_resolution_status ?? 'unresolved') }}</x-ui.status-chip>
                                        @endif
                                    </div>
                                </td>
                                <td class="fw-medium">{{ $item->material_name }}</td>
                                <td class="text-center">
                                    @if($item->shape)
                                        <span class="tw-inline-flex tw-rounded-ui-full tw-bg-surface-container tw-px-2 tw-py-1 tw-text-ui-xs tw-font-semibold">{{ $item->shape }}</span>
                                        <div class="tw-mt-1 tw-text-ui-xs tw-text-on-surface-variant">{{ $item->dimension_label }}</div>
                                    @else
                                        -
                                    @endif
                                </td>
                                <td class="text-center fw-bold ui-tabular-nums">{{ number_format($item->quantity_value, 0) }}</td>
                                <td class="text-end ui-tabular-nums">{{ number_format($item->weight_needed, 4) }}</td>
                                <td class="text-end fw-bold text-primary ui-tabular-nums">{{ number_format($item->total_weight, 4) }}</td>
                                <td>{{ $item->remark ?: '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-ui.data-table>

            <x-ui.data-table
                title="Incoming quotations"
                :description="$quotations->count() . ' supplier responses associated with this requisition.'"
                :empty="$quotations->isEmpty()"
            >
                <x-slot:emptyState>
                    <x-ui.empty-state
                        icon="bi-inbox"
                        title="No quotations received"
                        description="Supplier responses will appear here after a quotation is submitted."
                    />
                </x-slot:emptyState>

                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light text-center">
                        <tr>
                            <th>Supplier</th>
                            <th>Currency</th>
                            <th>Total Price</th>
                            <th>Estimated IDR</th>
                            <th>Est. Delivery</th>
                            <th>Date Submitted</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($quotations as $quotation)
                            @php
                                $isLowest = $lowestTotalIdr !== null
                                    && $quotation->total_idr !== null
                                    && abs((float) $quotation->total_idr - (float) $lowestTotalIdr) < 0.01;
                                $supplierName = $quotation->supplier->supplier->company_name ?? $quotation->supplier->name ?? '-';
                            @endphp
                            <tr class="{{ $isLowest ? 'pr-best-quotation' : '' }}">
                                <td class="fw-medium">{{ $supplierName }}</td>
                                <td class="text-center"><x-ui.status-chip tone="neutral">{{ $quotation->currency }}</x-ui.status-chip></td>
                                <td class="text-end ui-tabular-nums">{{ number_format($quotation->total_amount, 2, ',', '.') }}</td>
                                <td class="text-end fw-bold ui-tabular-nums">
                                    @if($quotation->total_idr !== null)
                                        Rp {{ number_format($quotation->total_idr, 0, ',', '.') }}
                                        @if($isLowest)<i class="bi bi-check-circle-fill tw-ms-1 tw-text-success" aria-label="Lowest estimated total"></i>@endif
                                    @else
                                        <span class="tw-text-on-surface-variant">-</span>
                                    @endif
                                </td>
                                <td class="text-center ui-tabular-nums">{{ $quotation->estimated_delivery ? date('d M Y', strtotime($quotation->estimated_delivery)) : '-' }}</td>
                                <td class="text-center ui-tabular-nums">{{ $quotation->submitted_at ? $quotation->submitted_at->format('d M Y, H:i') : '-' }}</td>
                                <td class="text-center"><x-status-badge type="quotation" :status="$quotation->status" /></td>
                                <td>
                                    <div class="tw-flex tw-flex-wrap tw-justify-end tw-gap-2">
                                        <x-ui.button
                                            :href="route('purchasing.quotations.show', [$quotation, \App\Support\PurchasingNavigation::RETURN_URL_KEY => request()->fullUrl()])"
                                            variant="ghost"
                                            size="sm"
                                        >
                                            <x-slot:leading><i class="bi bi-eye" aria-hidden="true"></i></x-slot:leading>
                                            View details
                                        </x-ui.button>
                                        @if($submittedQuotationCount >= 2)
                                            <x-ui.button
                                                :href="\App\Support\PurchasingNavigation::toRoute('purchasing.comparison.inter-supplier', ['pr_id' => $pr])"
                                                variant="secondary"
                                                size="sm"
                                            >
                                                <x-slot:leading><i class="bi bi-bar-chart" aria-hidden="true"></i></x-slot:leading>
                                                Compare
                                            </x-ui.button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-ui.data-table>
        </div>

        <aside class="tw-grid tw-gap-6" aria-label="Requisition actions and progress">
            <x-ui.card title="Action & status" description="Available actions follow creator ownership and workflow state.">
                <div class="tw-grid tw-gap-4">
                    @if($pr->created_by !== auth()->id())
                        <x-ui.alert tone="info" title="Read-only access">
                            You are viewing a requisition created by {{ $pr->creator->name ?? 'another purchasing user' }}. Edit and delete actions are only available to the PR creator.
                        </x-ui.alert>
                    @elseif($pr->status === 'draft')
                        <x-ui.alert tone="info" title="Draft requisition">Edit any incomplete details, then submit when the material list is ready.</x-ui.alert>
                        <div class="tw-grid tw-gap-2">
                            <x-ui.button :href="\App\Support\PurchasingNavigation::toRoute('purchasing.requisitions.edit', $pr)" variant="secondary">Edit draft</x-ui.button>
                            <form action="{{ route('purchasing.requisitions.submit', $pr) }}" method="POST">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="return_url" value="{{ request('return_url') }}">
                                <x-ui.button type="button" class="btn-submit tw-w-full">Submit requisition</x-ui.button>
                            </form>
                        </div>
                    @elseif($pr->status === 'rejected')
                        <x-ui.alert tone="error" title="Revision required">The requisition was rejected by Admin. Review the notes and revise it before resubmitting.</x-ui.alert>
                        <x-ui.button :href="\App\Support\PurchasingNavigation::toRoute('purchasing.requisitions.edit', $pr)" variant="danger">Revise &amp; resubmit</x-ui.button>
                    @else
                        <x-ui.alert tone="success" title="Processing started">This requisition has been processed and can no longer be edited.</x-ui.alert>
                    @endif

                    <a href="{{ \App\Support\PurchasingNavigation::backUrl('purchasing.requisitions.index') }}" class="ui-focus-ring tw-justify-self-center tw-rounded-ui-xs tw-text-ui-sm tw-font-semibold tw-text-primary tw-no-underline hover:tw-underline">
                        <i class="bi bi-arrow-left tw-me-1" aria-hidden="true"></i>Back to list
                    </a>
                </div>
            </x-ui.card>

            @if($pr->quotations && $pr->quotations->whereIn('status', ['submitted', 'revision_requested', 'accepted'])->count() > 0)
                <x-ui.card title="Negotiation & chat" description="Open the supplier thread without leaving this requisition.">
                    <div class="tw-grid tw-gap-2">
                        @foreach($pr->quotations->whereIn('status', ['submitted', 'revision_requested', 'accepted'])->unique('supplier_id') as $quotation)
                            <form action="{{ route('purchasing.conversations.start.pr', ['pr_id' => $pr, 'supplier_id' => $quotation->supplier]) }}" method="POST" data-chat-start-form>
                                @csrf
                                <input type="hidden" name="return_url" value="{{ \App\Support\PurchasingNavigation::currentUrlForReturn() }}">
                                <x-ui.button type="submit" variant="ghost" class="tw-w-full tw-justify-start">
                                    <x-slot:leading><i class="bi bi-chat-dots" aria-hidden="true"></i></x-slot:leading>
                                    {{ $quotation->supplier->supplier->company_name ?? $quotation->supplier->name }}
                                </x-ui.button>
                            </form>
                        @endforeach
                    </div>
                </x-ui.card>
            @endif

            <x-ui.card title="Timeline" description="High-level requisition workflow progress.">
                <ol class="pr-timeline">
                    <li class="pr-timeline-item is-complete">
                        <span class="pr-timeline-marker" aria-hidden="true"></span>
                        <div class="tw-text-ui-sm tw-font-semibold tw-text-primary">Created (draft)</div>
                        <time class="ui-tabular-nums tw-text-ui-xs tw-text-on-surface-variant" datetime="{{ $pr->created_at->toIso8601String() }}">{{ $pr->created_at->format('d M Y, H:i') }}</time>
                    </li>
                    <li class="pr-timeline-item {{ $hasReachedSubmitted ? 'is-complete' : '' }}">
                        <span class="pr-timeline-marker" aria-hidden="true"></span>
                        <div class="tw-text-ui-sm tw-font-semibold {{ $hasReachedSubmitted ? 'tw-text-primary' : 'tw-text-on-surface-variant' }}">Submitted</div>
                        @if($hasReachedSubmitted)
                            <time class="ui-tabular-nums tw-text-ui-xs tw-text-on-surface-variant" datetime="{{ $pr->updated_at->toIso8601String() }}">{{ $pr->updated_at->format('d M Y, H:i') }}</time>
                        @endif
                    </li>
                    <li class="pr-timeline-item {{ $hasReachedBidding ? 'is-current' : '' }}">
                        <span class="pr-timeline-marker" aria-hidden="true"></span>
                        <div class="tw-text-ui-sm tw-font-semibold {{ $hasReachedBidding ? 'tw-text-warning-container-foreground' : 'tw-text-on-surface-variant' }}">Supplier quotation (bidding)</div>
                    </li>
                </ol>
            </x-ui.card>
        </aside>
    </div>
</div>
@endsection

@push('scripts')
<script>
    $('.btn-submit').on('click', function() {
        const form = $(this).closest('form');
        AdasiAlert.confirm({
            title: @json('Submit Requisition?'),
            text: @json('Status will change to Submitted and cannot be edited anymore.'),
            confirmText: @json('Yes, Submit!'),
            cancelText: @json('Cancel')
        }).then((result) => {
            if (result.isConfirmed) {
                form.submit();
            }
        });
    });
</script>
@endpush
