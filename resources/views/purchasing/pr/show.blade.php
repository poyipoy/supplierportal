@extends('layouts.app')

@section('title', 'Material Requisition Details - ADASI Portal')
@section('page-title', 'Purchase Requisition Details')

@push('styles')
<style>
    .pr-best-quotation > * {
        background: var(--md-success-container) !important;
        color: var(--md-on-success-container) !important;
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
        inset-block: 0.6rem 1.25rem;
        inset-inline-start: 0.6rem;
        position: absolute;
        width: 2px;
    }

    .pr-timeline-item {
        min-height: 3.25rem;
        padding-inline-start: 2rem;
        position: relative;
    }

    .pr-timeline-marker {
        background: var(--md-surface);
        border: 2px solid var(--md-outline-strong);
        border-radius: var(--md-shape-full);
        height: 1.15rem;
        inset-block-start: 0.15rem;
        inset-inline-start: 0.05rem;
        position: absolute;
        width: 1.15rem;
        z-index: 1;
    }

    .pr-timeline-item.is-complete .pr-timeline-marker {
        background: var(--md-primary);
        border-color: var(--md-primary);
        box-shadow: inset 0 0 0 2px var(--md-surface);
    }

    .pr-timeline-item.is-current .pr-timeline-marker {
        background: var(--md-warning);
        border-color: var(--md-warning);
        box-shadow: inset 0 0 0 2px var(--md-surface);
    }
</style>
@endpush

@section('content')
@php
    $hasReachedSubmitted = in_array($pr->status, ['submitted', 'rejected', 'bidding', 'completed'], true);
    $hasReachedBidding = in_array($pr->status, ['bidding', 'completed'], true);
@endphp

<div class="tw-grid tw-gap-4">
    {{-- Breadcrumb & Compact Page Header --}}
    <x-ui.breadcrumb :items="[
        'Dashboard' => route('purchasing.dashboard'),
        'Purchase Requisition' => \App\Support\PurchasingNavigation::backUrl('purchasing.requisitions.index'),
        ($pr->pr_number ?? 'Draft') => null,
    ]" />

    <x-ui.page-header
        :title="$pr->pr_number ?? 'Requisition Draft'"
        eyebrow="Purchase Requisition Details"
        description="Review material requirements, invited suppliers, quotation responses, and workflow progress."
    >
        <x-slot:actions>
            <x-ui.button :href="route('purchasing.export.requisitions.detail', $pr)" variant="outline" size="sm" data-async-export data-export-source-singular="requisition" data-export-source-plural="requisitions" data-export-source-count="1" data-export-filtered="false" data-export-row-label="material rows" data-export-row-explanation="Each material item will be written as a separate Excel row.">
                <x-ui.icon name="file-spreadsheet" />
                <span>Export Excel</span>
            </x-ui.button>
            <x-status-badge type="pr" :status="$pr->status" size="lg" />
            @if($pr->status === 'bidding')
                <span
                    class="ui-tabular-nums tw-inline-flex tw-items-center tw-rounded-ui-xs tw-border tw-border-primary tw-bg-primary-container tw-px-2.5 tw-py-1.5 tw-text-ui-xs tw-font-semibold tw-text-primary-container-foreground"
                    title="{{ $submittedQuotationCount }} supplier quotations submitted"
                    aria-label="{{ $submittedQuotationCount }} supplier quotations submitted"
                >
                    <x-ui.icon name="users" size="sm" class="me-1" />
                    {{ $submittedQuotationCount }} Quotations
                </span>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    {{-- 4-Column Key Metrics Strip --}}
    <div class="tw-grid tw-gap-px tw-overflow-hidden tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-outline-variant sm:tw-grid-cols-2 xl:tw-grid-cols-4">
        <x-ui.metric-card
            flat
            label="Procurement Period"
            :value="$pr->period->display_label"
            icon="calendar"
            tone="neutral"
        />
        <x-ui.metric-card
            flat
            label="Total Requested Weight"
            :value="number_format((float) $totalKg, 2, '.', ',') . ' kg'"
            icon="weight"
            tone="primary"
        />
        <x-ui.metric-card
            flat
            label="Created By"
            :value="$pr->creator->name ?? '-'"
            icon="user"
            tone="neutral"
        />
        <x-ui.metric-card
            flat
            label="Date Created"
            :value="$pr->created_at->format('d M Y, H:i')"
            icon="clock"
            tone="neutral"
        />
    </div>

    <div class="tw-grid tw-items-start tw-gap-4 xl:tw-grid-cols-[minmax(0,2fr)_minmax(19rem,1fr)]">
        {{-- Main Column --}}
        <div class="tw-grid tw-min-w-0 tw-gap-4">
            {{-- Audience & Notes Card --}}
        <x-ui.card title="Audience and Instructions">
                <div class="row g-3">
                    <div class="col-md-6">
                        <span class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase d-block mb-1">Invited Suppliers</span>
                        @if($pr->invitedSuppliers->isEmpty())
                            <span class="ui-status-chip ui-status-chip--neutral">All Registered Suppliers</span>
                        @else
                            <div class="d-flex flex-wrap tw-gap-1.5">
                                @foreach($pr->invitedSuppliers as $supplier)
                                    <span class="ui-status-chip ui-status-chip--info">
                                        {{ $supplier->supplier->company_name ?? $supplier->name }}
                                    </span>
                                @endforeach
                            </div>
                        @endif
                    </div>
                    <div class="col-md-6">
                        <span class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase d-block mb-1">Requisition Notes</span>
                        @if($pr->notes)
                            <div class="tw-text-on-surface tw-text-ui-sm tw-whitespace-pre-line">{{ $pr->notes }}</div>
                        @else
                            <span class="tw-text-outline tw-text-ui-sm fst-italic">No additional instructions provided.</span>
                        @endif
                    </div>
                </div>
            </x-ui.card>

            {{-- Material List Table --}}
            <x-ui.data-table
                :title="'Material Requirements (' . $pr->items->count() . ' items)'"
                description="Required specifications, shapes, dimensions, and computed weights."
            >
                <table class="table table-hover align-middle mb-0 tw-text-ui-xs w-100">
                    <thead class="table-light text-center">
                        <tr>
                            <th scope="col" style="width: 40px;">No</th>
                            <th scope="col">HS Code</th>
                            <th scope="col">Material Name</th>
                            <th scope="col">Shape &amp; Dimensions (mm)</th>
                            <th scope="col">Qty</th>
                            <th scope="col" class="text-end">KG / Unit</th>
                            <th scope="col" class="text-end">Total Weight</th>
                            <th scope="col">Remark</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($pr->items as $index => $item)
                            <tr>
                                <td class="text-center tw-text-on-surface-variant ui-tabular-nums">{{ $index + 1 }}</td>
                                <td class="text-center">
                                    <div class="fw-semibold tw-text-on-surface">{{ $item->hs_code ?? '-' }}</div>
                                    <div class="tw-mt-0.5">
                                        @if($item->hs_code_source === 'manual')
                                            <span class="ui-status-chip ui-status-chip--warning">Manual</span>
                                        @elseif($item->hs_code_resolution_status === 'matched')
                                            <span class="ui-status-chip ui-status-chip--success">Auto</span>
                                        @elseif($item->hs_code_source === 'legacy')
                                            <span class="ui-status-chip ui-status-chip--neutral">Legacy</span>
                                        @else
                                            <span class="ui-status-chip ui-status-chip--neutral">{{ str_replace('_', ' ', $item->hs_code_resolution_status ?? 'unresolved') }}</span>
                                        @endif
                                    </div>
                                </td>
                                <td class="fw-bold tw-text-on-surface">{{ $item->material_name }}</td>
                                <td class="text-center">
                                    @if($item->shape)
                                        <span class="ui-status-chip ui-status-chip--neutral">{{ $item->shape }}</span>
                                        <div class="tw-text-on-surface-variant tw-text-ui-xs tw-mt-0.5">{{ $item->dimension_label }}</div>
                                    @else
                                        <span class="tw-text-outline">-</span>
                                    @endif
                                </td>
                                <td class="text-center fw-bold ui-tabular-nums">{{ number_format($item->quantity_value, 0) }}</td>
                                <td class="text-end ui-tabular-nums tw-text-on-surface">{{ number_format($item->weight_needed, 4) }}</td>
                                <td class="text-end fw-bold text-primary ui-tabular-nums">{{ number_format($item->total_weight, 4) }}</td>
                                <td class="tw-text-on-surface-variant">{{ $item->remark ?: '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-ui.data-table>

            {{-- Incoming Quotations Table --}}
            <x-ui.data-table
                title="Incoming Supplier Quotations"
                :description="$quotations->count() . ' supplier offers submitted for this requisition.'"
                :empty="$quotations->isEmpty()"
            >
                <x-slot:emptyState>
                    <x-ui.empty-state
                        icon="inbox"
                        title="No quotations received yet"
                        description="Supplier responses will appear here as soon as they submit their pricing."
                    />
                </x-slot:emptyState>

                <table class="table table-hover align-middle mb-0 tw-text-ui-xs w-100">
                    <thead class="table-light text-center">
                        <tr>
                            <th scope="col">Supplier</th>
                            <th scope="col">Curr</th>
                            <th scope="col" class="text-end">Total Price</th>
                            <th scope="col" class="text-end">Estimated IDR</th>
                            <th scope="col">Est. Delivery</th>
                            <th scope="col">Submitted</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="text-end" style="width: 140px;">Action</th>
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
                                <td class="fw-bold tw-text-on-surface">{{ $supplierName }}</td>
                                <td class="text-center"><span class="ui-status-chip ui-status-chip--neutral">{{ $quotation->currency }}</span></td>
                                <td class="text-end ui-tabular-nums fw-semibold">{{ number_format($quotation->total_amount, 2, ',', '.') }}</td>
                                <td class="text-end fw-bold ui-tabular-nums">
                                    @if($quotation->total_idr !== null)
                                        Rp {{ number_format($quotation->total_idr, 0, ',', '.') }}
                                        @if($isLowest)
                                            <x-ui.icon name="circle-check" class="ms-1 text-success" aria-label="Lowest estimated total" />
                                        @endif
                                    @else
                                        <span class="tw-text-outline">-</span>
                                    @endif
                                </td>
                                <td class="text-center ui-tabular-nums tw-text-on-surface-variant">{{ $quotation->estimated_delivery ? date('d M Y', strtotime($quotation->estimated_delivery)) : '-' }}</td>
                                <td class="text-center ui-tabular-nums tw-text-on-surface-variant">{{ $quotation->submitted_at ? $quotation->submitted_at->format('d M Y, H:i') : '-' }}</td>
                                <td class="text-center"><x-status-badge type="quotation" :status="$quotation->status" /></td>
                                <td class="text-end">
                                    <div class="d-inline-flex align-items-center tw-gap-1.5 justify-content-end">
                                        <x-ui.icon-button
                                            :href="route('purchasing.quotations.show', [$quotation, \App\Support\PurchasingNavigation::RETURN_URL_KEY => request()->fullUrl()])"
                                            icon="eye"
                                            label="View quotation details"
                                            size="sm"
                                        />
                                        @if($submittedQuotationCount >= 2)
                                            <x-ui.icon-button
                                                :href="\App\Support\PurchasingNavigation::toRoute('purchasing.comparison.inter-supplier', ['pr_id' => $pr])"
                                                icon="bar-chart-2"
                                                label="Launch side-by-side comparison"
                                                variant="secondary"
                                                size="sm"
                                            />
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-ui.data-table>
        </div>

        {{-- Sidebar Column --}}
        <aside class="tw-grid tw-gap-4" aria-label="Requisition actions and progress">
            {{-- Action Card --}}
            <x-ui.card title="Workflow Actions">
                <div class="tw-grid tw-gap-3">
                    @if($pr->created_by !== auth()->id())
                        <x-ui.alert tone="info" title="Read-only access">Created by {{ $pr->creator->name ?? 'another Purchasing user' }}.</x-ui.alert>
                    @elseif($pr->status === 'draft')
                        <x-ui.alert tone="warning" title="Draft requisition">Complete the material list before submitting this requisition.</x-ui.alert>
                        <div class="tw-grid tw-gap-2">
                            <x-ui.button :href="\App\Support\PurchasingNavigation::toRoute('purchasing.requisitions.edit', $pr)" variant="outline" size="sm">
                                <x-ui.icon name="square-pen" size="sm" />
                                <span>Edit Draft</span>
                            </x-ui.button>
                            <form action="{{ route('purchasing.requisitions.submit', $pr) }}" method="POST">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="return_url" value="{{ request('return_url') }}">
                                <x-ui.button type="button" class="btn-submit tw-w-full" size="sm">
                                    <x-ui.icon name="send" size="sm" />
                                    <span>Submit Requisition</span>
                                </x-ui.button>
                            </form>
                        </div>
                    @elseif($pr->status === 'rejected')
                        <x-ui.alert tone="error" title="Requisition rejected">Review the recorded notes and revise the requisition before resubmitting.</x-ui.alert>
                        <x-ui.button :href="\App\Support\PurchasingNavigation::toRoute('purchasing.requisitions.edit', $pr)" variant="danger" size="sm">
                            <x-ui.icon name="rotate-ccw" size="sm" />
                            <span>Revise &amp; Resubmit</span>
                        </x-ui.button>
                    @else
                        <x-ui.alert tone="success" title="Requisition active">This requisition has been submitted and is active in procurement.</x-ui.alert>
                    @endif
                </div>
            </x-ui.card>

            {{-- Supplier Chat Channels --}}
            @if($pr->quotations && $pr->quotations->whereIn('status', ['submitted', 'revision_requested', 'accepted'])->count() > 0)
                <x-ui.card title="Supplier Discussions" description="Open direct supplier chat threads for this PR.">
                    <div class="tw-grid tw-gap-2">
                        @foreach($pr->quotations->whereIn('status', ['submitted', 'revision_requested', 'accepted'])->unique('supplier_id') as $quotation)
                            <form action="{{ route('purchasing.conversations.start.pr', ['pr_id' => $pr, 'supplier_id' => $quotation->supplier]) }}" method="POST" data-chat-start-form>
                                @csrf
                                <input type="hidden" name="return_url" value="{{ \App\Support\PurchasingNavigation::currentUrlForReturn() }}">
                                <x-ui.button type="submit" variant="outline" size="sm" class="tw-w-full tw-justify-start">
                                    <x-ui.icon name="message-square" size="sm" class="text-primary flex-shrink-0" />
                                    <span class="text-truncate fw-medium">{{ $quotation->supplier->supplier->company_name ?? $quotation->supplier->name }}</span>
                                </x-ui.button>
                            </form>
                        @endforeach
                    </div>
                </x-ui.card>
            @endif

            {{-- Workflow Timeline --}}
            <x-ui.card title="Workflow Progress">
                <ol class="pr-timeline">
                    <li class="pr-timeline-item is-complete">
                        <span class="pr-timeline-marker" aria-hidden="true"></span>
                        <div class="tw-text-ui-sm fw-bold text-primary">Created (Draft)</div>
                        <time class="ui-tabular-nums tw-text-on-surface-variant tw-text-ui-xs" datetime="{{ $pr->created_at->toIso8601String() }}">{{ $pr->created_at->format('d M Y, H:i') }}</time>
                    </li>
                    <li class="pr-timeline-item {{ $hasReachedSubmitted ? 'is-complete' : '' }}">
                        <span class="pr-timeline-marker" aria-hidden="true"></span>
                        <div class="tw-text-ui-sm fw-bold {{ $hasReachedSubmitted ? 'text-primary' : 'tw-text-outline' }}">Submitted</div>
                        @if($hasReachedSubmitted)
                            <time class="ui-tabular-nums tw-text-on-surface-variant tw-text-ui-xs" datetime="{{ $pr->updated_at->toIso8601String() }}">{{ $pr->updated_at->format('d M Y, H:i') }}</time>
                        @endif
                    </li>
                    <li class="pr-timeline-item {{ $hasReachedBidding ? 'is-current' : '' }}">
                        <span class="pr-timeline-marker" aria-hidden="true"></span>
                        <div class="tw-text-ui-sm fw-bold {{ $hasReachedBidding ? 'text-warning' : 'tw-text-outline' }}">Supplier Bidding</div>
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
