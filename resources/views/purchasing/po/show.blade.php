@extends('layouts.app')

@section('title', 'PO Details: ' . $po->po_number . ' - ADASI Portal')
@section('page-title', 'Purchase Order Details')

@push('styles')
<style>
    .po-tracking-strip {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 0.75rem;
    }

    .po-tracking-step {
        padding: 0.75rem 1rem;
        background: var(--md-surface);
        border: 1px solid var(--md-outline-variant);
        border-radius: var(--md-shape-sm);
        position: relative;
    }

    .po-tracking-step.is-active {
        border-color: var(--md-primary);
        background: var(--md-primary-container);
    }

    .po-nav-pills .nav-link {
        font-size: var(--ui-font-size-sm);
        font-weight: 600;
        padding: 0.35rem 0.85rem;
        color: var(--md-on-surface-variant);
        border-radius: var(--md-shape-full);
        transition: all 0.15s ease;
    }

    .po-nav-pills .nav-link.active {
        background-color: var(--md-primary) !important;
        color: var(--md-on-primary) !important;
    }

    .po-doc-card {
        transition: border-color 0.15s ease;
    }

    .po-doc-card:hover {
        border-color: var(--md-primary);
    }
</style>
@endpush

@section('content')
<div class="tw-grid tw-gap-4">
    {{-- Breadcrumb & Compact Page Header --}}
    <x-ui.breadcrumb :items="[
        'Dashboard' => route('purchasing.dashboard'),
        'Purchase Orders' => route('purchasing.purchase-orders.index'),
        $po->po_number => null,
    ]" />

    <x-ui.page-header
        :title="$po->po_number"
        eyebrow="Purchase Order Details"
        :description="'Purchase order for ' . $po->supplier->name . ' with arrival, QC, document, and claim tracking.'"
    >
        <x-slot:actions>
            <x-status-badge type="po" :status="$po->status" :is-overdue="$po->is_overdue" size="lg" />
            <x-ui.button :href="route('purchasing.export.purchase-orders.detail', $po)" variant="outline" size="sm" data-async-export>
                <x-ui.icon name="file-spreadsheet" />
                <span>Export Excel</span>
            </x-ui.button>
            <x-ui.button :href="route('shared.pdf.purchase-order', $po)" variant="danger" size="sm" target="_blank" title="Print Purchase Order" data-pdf-confirm>
                <x-ui.icon name="file-text" />
                <span>Print PDF</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- 4-Key Tracking Dates Strip --}}
    @php
        $primaryPr = $po->quotations->first()?->purchaseRequisition;
        $prDate = $primaryPr?->created_at;
    @endphp
    <div class="po-tracking-strip">
        <div class="po-tracking-step {{ $prDate ? 'is-active' : '' }}">
            <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">1. PR Created</div>
            <div class="fw-bold tw-text-on-surface tw-text-ui-sm tw-mt-0.5">{{ $prDate ? $prDate->format('d M Y') : '-' }}</div>
            <div class="tw-text-outline tw-text-ui-xs">{{ $primaryPr?->pr_number ?? 'Requisition' }}</div>
        </div>
        <div class="po-tracking-step is-active">
            <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">2. PO Created</div>
            <div class="fw-bold tw-text-on-surface tw-text-ui-sm tw-mt-0.5">{{ $po->created_at->format('d M Y') }}</div>
            <div class="tw-text-outline tw-text-ui-xs">{{ $po->created_at->format('H:i') }} WIB</div>
        </div>
        <div class="po-tracking-step {{ $po->estimated_arrival ? 'is-active' : '' }}">
            <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">3. Estimated Arrival</div>
            <div class="fw-bold {{ $po->is_overdue ? 'text-danger' : 'tw-text-on-surface' }} tw-text-ui-sm tw-mt-0.5">
                {{ $po->estimated_arrival ? $po->estimated_arrival->format('d M Y') : '-' }}
            </div>
            <div class="tw-text-outline tw-text-ui-xs">
                {{ $po->is_overdue ? 'Overdue' : 'Target delivery' }}
            </div>
        </div>
        <div class="po-tracking-step {{ $po->actual_arrival ? 'is-active' : '' }}">
            <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">4. Actual Arrival</div>
            <div class="fw-bold {{ $po->actual_arrival ? 'text-success' : 'tw-text-outline' }} tw-text-ui-sm tw-mt-0.5">
                @if($po->actual_arrival)
                    <x-ui.icon name="circle-check" size="sm" class="me-1 text-success" />
                    {{ $po->actual_arrival->format('d M Y') }}
                @else
                    Pending Delivery
                @endif
            </div>
            <div class="tw-text-outline tw-text-ui-xs">
                {{ $po->actual_arrival ? 'Received at plant' : 'Waiting arrival' }}
            </div>
        </div>
    </div>

    {{-- Sticky In-Page Navigation Bar --}}
    <div class="tw-sticky tw-top-2 tw-z-sticky tw-border tw-border-outline-variant tw-bg-surface tw-p-1.5">
        <ul class="nav po-nav-pills gap-1" id="po-section-nav">
            <li class="nav-item"><a class="nav-link active" href="#sec-info">Order Info</a></li>
            <li class="nav-item"><a class="nav-link" href="#sec-material">Materials &amp; Commercials</a></li>
            @if($po->qcInspections->isNotEmpty())
                <li class="nav-item"><a class="nav-link" href="#sec-inspection">QC Inspection</a></li>
            @endif
            <li class="nav-item"><a class="nav-link" href="#sec-document">Import Documents</a></li>
            @if($po->status === 'claim_needed')
                <li class="nav-item"><a class="nav-link text-danger" href="#sec-claim">Material Claim</a></li>
            @endif
            <li class="nav-item"><a class="nav-link" href="#sec-timeline">Timeline</a></li>
        </ul>
    </div>

    <div class="tw-grid tw-items-start tw-gap-4 lg:tw-grid-cols-[minmax(0,2fr)_minmax(19rem,1fr)]">
        {{-- Main Column --}}
        <div class="tw-grid tw-min-w-0 tw-gap-4">
            {{-- Order Info Card --}}
            <x-ui.card title="Order Information" id="sec-info" class="tw-scroll-mt-24">
                <div class="tw-grid tw-gap-px tw-overflow-hidden tw-border tw-border-outline-variant tw-bg-outline-variant sm:tw-grid-cols-2 lg:tw-grid-cols-3">
                    <div class="tw-bg-surface tw-p-2.5">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">Supplier</div>
                        <div class="fw-bold tw-text-on-surface tw-text-ui-sm tw-mt-0.5">{{ $po->supplier->name }}</div>
                    </div>
                    <div class="tw-bg-surface tw-p-2.5">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">Reference (No. PR)</div>
                        <div class="fw-bold text-primary tw-text-ui-sm tw-mt-0.5">
                            @php $prs = $po->purchaseRequisitions(); @endphp
                            @if($prs->isEmpty())
                                -
                            @else
                                @foreach($prs as $pr)
                                    <a href="{{ \App\Support\PurchasingNavigation::toRoute('purchasing.requisitions.show', $pr) }}" class="text-primary text-decoration-none tw-me-1.5">
                                        {{ $pr->pr_number ?? '-' }}
                                    </a>
                                @endforeach
                                @if($prs->count() > 1)
                                    <span class="ui-status-chip ui-status-chip--info">{{ $prs->count() }} PRs</span>
                                @endif
                            @endif
                        </div>
                    </div>
                    <div class="tw-bg-surface tw-p-2.5">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">Procurement Period</div>
                        <div class="fw-semibold tw-text-on-surface tw-text-ui-sm tw-mt-0.5">
                            @php $periods = $prs->map(fn($pr) => $pr->period?->display_label ?? '-')->unique(); @endphp
                            {{ $periods->implode(', ') }}
                        </div>
                    </div>
                    <div class="tw-bg-surface tw-p-2.5">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">Locked Currency</div>
                        <div class="fw-bold tw-text-on-surface tw-text-ui-sm tw-mt-0.5">
                            <span class="ui-status-chip ui-status-chip--neutral">{{ $po->currency }}</span>
                        </div>
                    </div>
                    <div class="tw-bg-surface tw-p-2.5 sm:tw-col-span-2">
                        <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">PO Notes &amp; Remark</div>
                        <div class="tw-text-on-surface tw-text-ui-sm tw-mt-0.5">{{ $po->notes ?: '-' }}</div>
                    </div>
                </div>
            </x-ui.card>

            {{-- Material Details Table --}}
            <x-ui.data-table
            title="Materials and Commercial Breakdown"
                description="Line items grouped by quotation and reference PR."
                id="sec-material"
                class="tw-scroll-mt-24"
            >
                <table class="table table-hover align-middle mb-0 tw-text-ui-xs w-100">
                    <thead class="table-light text-center">
                        <tr>
                            <th scope="col" style="width: 35px;">No</th>
                            <th scope="col">Material</th>
                            <th scope="col">Specification</th>
                            <th scope="col">Qty</th>
                            <th scope="col" class="text-end">Weight/Unit</th>
                            <th scope="col" class="text-end">Total Weight</th>
                            <th scope="col" class="text-end">Price/Kg</th>
                            <th scope="col" class="text-end">Amount</th>
                            <th scope="col" class="text-end">Converted IDR</th>
                            <th scope="col">Reference (No. PR)</th>
                            <th scope="col">Remark</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php
                            $globalNo = 1;
                            $grandTotalAmount = 0;
                            $grandTotalIdr = 0;
                            $poRemark = trim((string) $po->notes);
                        @endphp
                        @foreach($po->quotations as $quotation)
                            @php $rate = $quotationRates[$quotation->id] ?? null; @endphp
                            @if($po->quotations->count() > 1)
                                <tr class="bg-primary-subtle text-primary border-top border-bottom">
                                    <td colspan="11" class="fw-bold py-2 ps-3">
                                        <x-ui.icon name="folder" size="sm" class="me-1" />
                                        {{ $quotation->purchaseRequisition->pr_number ?? 'PR -' }}
                                        <span class="tw-text-on-surface-variant fw-normal ms-2">
                                            ({{ $quotation->purchaseRequisition->period->display_label ?? $quotation->purchaseRequisition->period->name ?? '-' }})
                                            @if($rate)
                                                &bull; Locked Exchange Rate: 1 {{ $quotation->currency }} = Rp {{ number_format($rate->rate_to_idr, 0, ',', '.') }}
                                            @endif
                                        </span>
                                    </td>
                                </tr>
                            @endif
                            @foreach($quotation->items as $item)
                                @php
                                    $amount = $item->resolved_amount;
                                    $idr = $amount * ($rate ? $rate->rate_to_idr : 1);
                                    $grandTotalAmount += $amount;
                                    $grandTotalIdr += $idr;
                                @endphp
                                <tr>
                                    <td class="text-center tw-text-on-surface-variant ui-tabular-nums">{{ $globalNo++ }}</td>
                                    <td class="fw-bold tw-text-on-surface">{{ $item->prItem->material_name }}</td>
                                    <td class="text-center">
                                        @if($item->prItem->shape)
                                            <span class="ui-status-chip ui-status-chip--neutral">{{ $item->prItem->shape }}</span>
                                            <div class="tw-text-on-surface-variant tw-text-ui-xs tw-mt-0.5">{{ $item->prItem->dimension_label }}</div>
                                        @else
                                            <span class="tw-text-outline">-</span>
                                        @endif
                                    </td>
                                    <td class="text-center ui-tabular-nums">{{ number_format($item->prItem->quantity_value, 0) }}</td>
                                    <td class="text-end ui-tabular-nums tw-text-on-surface-variant">{{ number_format($item->prItem->weight_needed, 2) }}</td>
                                    <td class="text-end fw-bold text-primary ui-tabular-nums">{{ number_format($item->prItem->total_weight, 2) }}</td>
                                    <td class="text-end ui-tabular-nums tw-text-on-surface-variant">{{ number_format($item->price_per_kg, 4) }}</td>
                                    <td class="text-end fw-semibold ui-tabular-nums">{{ number_format($amount, 2) }}</td>
                                    <td class="text-end fw-bold tw-text-on-surface ui-tabular-nums">Rp {{ number_format($idr, 0, ',', '.') }}</td>
                                    <td class="text-nowrap">
                                        @if($quotation->purchaseRequisition)
                                            <a href="{{ \App\Support\PurchasingNavigation::toRoute('purchasing.requisitions.show', $quotation->purchaseRequisition) }}" class="text-primary text-decoration-none fw-medium" title="Open PR detail">
                                                {{ $quotation->purchaseRequisition->pr_number ?? '-' }}
                                            </a>
                                        @else
                                            <span class="tw-text-outline">-</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($poRemark !== '')
                                            <span class="d-inline-block text-truncate tw-max-w-[220px]" title="{{ $poRemark }}">
                                                {{ \Illuminate\Support\Str::limit($poRemark, 80) }}
                                            </span>
                                        @else
                                            -
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                    <tfoot class="table-light fw-bold border-top">
                        <tr>
                            <td colspan="7" class="text-end tw-text-on-surface">GRAND TOTAL</td>
                            <td class="text-end tw-text-on-surface ui-tabular-nums">{{ number_format($grandTotalAmount, 2) }} {{ $po->currency }}</td>
                            <td class="text-end text-primary ui-tabular-nums fs-6">Rp {{ number_format($grandTotalIdr, 0, ',', '.') }}</td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </x-ui.data-table>

            {{-- QC Inspection Results --}}
            @php
                $latestInspection = $po->qcInspections->sortByDesc('inspected_at')->first();
                $latestNgInspection = $po->qcInspections->where('status', 'ng')->sortByDesc('inspected_at')->first();
                $activeClaim = $po->materialClaims->whereIn('status', ['pending', 'responded', 'escalated'])->sortByDesc('created_at')->first();
            @endphp
            @if($latestInspection)
                <x-ui.card
                    title="QC Inspection Report"
                    description="Incoming quality verification by ADASI QC team."
                    id="sec-inspection"
                    class="tw-scroll-mt-24"
                >
                    <x-slot:actions>
                        <span class="ui-status-chip {{ $latestInspection->status === 'ng' ? 'ui-status-chip--error' : 'ui-status-chip--success' }}">
                            Status: {{ $latestInspection->status }}
                        </span>
                    </x-slot:actions>

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">Inspection Date</div>
                            <div class="fw-semibold tw-text-on-surface tw-text-ui-sm tw-mt-0.5">{{ $latestInspection->inspected_at?->format('d M Y, H:i') ?? '-' }}</div>
                        </div>
                        <div class="col-md-4">
                            <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">Inspected By</div>
                            <div class="fw-semibold tw-text-on-surface tw-text-ui-sm tw-mt-0.5">{{ $latestInspection->inspector->name ?? '-' }}</div>
                        </div>
                        <div class="col-md-4">
                            <div class="tw-text-on-surface-variant tw-text-ui-xs fw-semibold tw-uppercase">Defective (NG) Items</div>
                            <div class="fw-bold {{ $latestInspection->items->where('status', 'ng')->count() > 0 ? 'text-danger' : 'text-success' }} tw-text-ui-sm tw-mt-0.5">
                                {{ $latestInspection->items->where('status', 'ng')->count() }} Item(s)
                            </div>
                        </div>
                    </div>

                    @if($latestInspection->items->where('status', 'ng')->count() > 0)
                        <div class="mb-3">
                            <div class="fw-bold text-danger tw-text-ui-xs tw-uppercase tw-mb-1.5">Problematic Materials (NG)</div>
                            <ul class="list-group list-group-flush border rounded overflow-hidden">
                                @foreach($latestInspection->items->where('status', 'ng') as $item)
                                    <li class="list-group-item py-2 px-3 tw-text-ui-xs">
                                        <span class="fw-bold tw-text-on-surface d-block">{{ $item->prItem->material_name }}</span>
                                        @if($item->notes)
                                            <span class="tw-text-on-surface-variant fst-italic">QC Remarks: {{ $item->notes }}</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if($latestInspection->attachments->count() > 0)
                        <div>
                            <div class="fw-bold tw-text-on-surface-variant tw-text-ui-xs tw-uppercase tw-mb-1.5">QC Photo Evidence</div>
                            <div class="row g-2">
                                @foreach($latestInspection->attachments as $att)
                                    <div class="col-4 col-md-3">
                                        <a href="{{ route('attachments.show', $att->id) }}" target="_blank" class="d-block border rounded overflow-hidden tw-bg-surface-low tw-h-24">
                                            <img src="{{ route('attachments.show', $att->id) }}" alt="{{ $att->file_name }}" class="w-100 h-100 tw-object-cover">
                                        </a>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </x-ui.card>
            @endif

            {{-- Import Document Tracking --}}
            <x-ui.card
                title="Import Document Tracking"
                description="Status tracking for 4 mandatory import customs documents: Invoice, Bill of Lading, Packing List, and Form-E."
                id="sec-document"
                class="tw-scroll-mt-24"
            >
                <x-slot:actions>
                    @php
                        $docProgressTone = str_contains($docProgress['class'], 'success') ? 'success' : (str_contains($docProgress['class'], 'danger') ? 'error' : 'warning');
                    @endphp
                    <span class="ui-status-chip ui-status-chip--{{ $docProgressTone }}" id="docProgressBadge" data-bs-toggle="tooltip" data-bs-title="{{ $docProgress['description'] }}">
                        {{ $docProgress['label'] }}
                    </span>
                </x-slot:actions>

                {{-- Progress Bar --}}
                <div class="progress mb-3" style="height: 6px;">
                    <div class="progress-bar tw-bg-success" role="progressbar" id="docProgressBar"
                         aria-label="Import document completion"
                         aria-valuemin="0"
                         aria-valuemax="100"
                         aria-valuenow="{{ $totalDocs > 0 ? round($completedDocs / $totalDocs * 100) : 0 }}"
                         style="width: {{ $totalDocs > 0 ? ($completedDocs/$totalDocs*100) : 0 }}%"></div>
                </div>

                @if($allDocsComplete)
                    <x-ui.alert id="allDocsAlert" tone="success" title="Import Documents Complete" class="tw-mb-3">
                        All mandatory import customs documents have been fully verified.
                    </x-ui.alert>
                @endif
                <template id="allDocsAlertTemplate">
                    <x-ui.alert id="allDocsAlert" tone="success" title="Import Documents Complete" class="tw-mb-3">
                        All mandatory import customs documents have been fully verified.
                    </x-ui.alert>
                </template>

                <div class="tw-grid tw-gap-px tw-overflow-hidden tw-border tw-border-outline-variant tw-bg-outline-variant sm:tw-grid-cols-2 xl:tw-grid-cols-4">
                    @php
                        $docConfig = [
                            'invoice' => ['label' => 'Invoice', 'icon' => 'receipt', 'statuses' => ['pending' => 'Not Available', 'received' => 'Accepted', 'verified' => 'Verified']],
                            'bl' => ['label' => 'Bill of Lading', 'icon' => 'truck', 'statuses' => ['pending' => 'Not Available', 'issued' => 'Issued', 'done' => 'Accepted']],
                            'packing_list' => ['label' => 'Packing List', 'icon' => 'list-checks', 'statuses' => ['pending' => 'Not Available', 'received' => 'Accepted', 'verified' => 'Verified']],
                            'form_e' => ['label' => 'Form-E', 'icon' => 'file-badge', 'statuses' => ['pending' => 'Not Available', 'processing' => 'Processing', 'done' => 'Completed']],
                        ];
                    @endphp

                    @foreach($po->documents as $doc)
                        @php
                            $config = $docConfig[$doc->doc_type] ?? ['label' => $doc->doc_type, 'icon' => 'file', 'statuses' => []];
                            $statusLabel = $config['statuses'][$doc->status] ?? $doc->status;
                            $statusTone = match($doc->status) {
                                'pending' => 'neutral',
                                'received', 'issued', 'processing' => 'info',
                                'verified', 'done' => 'success',
                                default => 'neutral'
                            };
                        @endphp
                        <div class="tw-bg-surface">
                            <div class="po-doc-card tw-h-full tw-bg-surface" id="doc-card-{{ $doc->id }}">
                                <div class="text-center p-3">
                                    <x-ui.icon :name="$config['icon']" size="lg" class="tw-mb-1.5 d-block {{ $doc->status === 'pending' ? 'tw-text-outline' : 'text-primary' }}" />
                                    <h6 class="fw-bold tw-text-on-surface tw-text-ui-sm mb-1">{{ $config['label'] }}</h6>
                                    <span class="ui-status-chip ui-status-chip--{{ $statusTone }} tw-mb-1.5 doc-status-badge" id="doc-badge-{{ $doc->id }}" data-status="{{ $doc->status }}">{{ $statusLabel }}</span>
                                    <div class="tw-text-on-surface-variant tw-text-ui-xs mb-2" id="doc-date-{{ $doc->id }}">
                                        {{ $doc->status !== 'pending' ? $doc->updated_at->format('d M Y, H:i') : '' }}
                                    </div>
                                    <x-ui.button type="button" variant="outline" size="sm" class="btn-update-doc tw-w-full"
                                            data-doc-id="{{ $doc->id }}"
                                            data-doc-type="{{ $doc->doc_type }}"
                                            data-doc-label="{{ $config['label'] }}"
                                            data-doc-status="{{ $doc->status }}"
                                            :data-doc-statuses="json_encode($config['statuses'])">
                                        <x-ui.icon name="square-pen" size="sm" class="me-1" /> Update Status
                                    </x-ui.button>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-ui.card>
        </div>

        {{-- Sidebar Column --}}
        <aside class="tw-grid tw-gap-4" aria-label="PO operations and timeline">
            {{-- Supplier Chat Channel --}}
            <x-ui.card title="Supplier Negotiation">
                <form action="{{ route('purchasing.conversations.start.po', $po) }}" method="POST" data-chat-start-form>
                    @csrf
                    <input type="hidden" name="return_url" value="{{ \App\Support\PurchasingNavigation::currentUrlForReturn() }}">
                    <x-ui.button type="submit" variant="outline" size="sm" class="tw-w-full tw-justify-between">
                        <span class="d-inline-flex align-items-center gap-2">
                            <x-ui.icon name="message-square" size="sm" />
                            <span class="fw-semibold">Chat with Supplier</span>
                        </span>
                        <x-ui.icon name="chevron-right" size="sm" />
                    </x-ui.button>
                </form>
            </x-ui.card>

            {{-- Material Claim Alert (if status claim_needed) --}}
            @if($po->status === 'claim_needed')
                <x-ui.card title="Defect Claim Follow-Up" id="sec-claim" class="tw-scroll-mt-24 border-danger">
                    <x-slot:actions><span class="ui-status-chip ui-status-chip--error">NG</span></x-slot:actions>
                    <p class="text-danger tw-text-ui-xs fw-medium mb-3">
                        Quality inspection failed (status NG). Immediate claim action is required.
                    </p>

                    @if($activeClaim)
                        <x-ui.button :href="\App\Support\PurchasingNavigation::toRoute('purchasing.claims.show', $activeClaim)" variant="danger" size="sm" class="tw-w-full tw-justify-between">
                            <span><x-ui.icon name="octagon-alert" size="sm" class="me-1" /> View Active Claim</span>
                            <x-ui.icon name="chevron-right" size="sm" />
                        </x-ui.button>
                    @elseif($latestNgInspection)
                        <x-ui.button :href="\App\Support\PurchasingNavigation::toRoute('purchasing.claims.create', $latestNgInspection)" variant="danger" size="sm" class="tw-w-full tw-justify-between">
                            <span><x-ui.icon name="plus-circle" size="sm" class="me-1" /> Submit Material Claim</span>
                            <x-ui.icon name="chevron-right" size="sm" />
                        </x-ui.button>
                    @else
                        <x-ui.button :href="\App\Support\PurchasingNavigation::toRoute('purchasing.claims.index')" variant="outline" size="sm" class="tw-w-full tw-justify-between">
                            <span><x-ui.icon name="folder-open" size="sm" class="me-1" /> Open Claim List</span>
                            <x-ui.icon name="chevron-right" size="sm" />
                        </x-ui.button>
                    @endif
                </x-ui.card>
            @endif

            {{-- Confirm Arrival Action Button --}}
            @if(in_array($po->status, ['active', 'overdue']) && !$po->actual_arrival)
                <x-ui.card title="Delivery Status Action">
                    <form action="{{ route('purchasing.purchase-orders.confirm-arrival', $po) }}" method="POST" id="arrivalForm">
                        @csrf
                        <x-ui.button type="button" size="sm" class="tw-w-full" id="btnConfirmArrival">
                            <x-ui.icon name="package-check" size="sm" />
                            <span>Confirm Material Arrival</span>
                        </x-ui.button>
                    </form>
                </x-ui.card>
            @endif

            {{-- Timeline History --}}
            <x-ui.card title="Order Timeline" id="sec-timeline" class="tw-scroll-mt-24">
                <ol class="pr-timeline">
                    <li class="pr-timeline-item is-complete">
                        <span class="pr-timeline-marker" aria-hidden="true"></span>
                        <div class="tw-text-ui-sm fw-bold text-primary">PO Created</div>
                        <time class="ui-tabular-nums tw-text-on-surface-variant tw-text-ui-xs" datetime="{{ $po->created_at->toIso8601String() }}">{{ $po->created_at->format('d M Y, H:i') }}</time>
                    </li>

                    @foreach($po->documents->sortBy('updated_at') as $doc)
                        @if($doc->status !== 'pending')
                            @php
                                $docLabels = [
                                    'invoice' => 'Invoice',
                                    'bl' => 'Bill of Lading',
                                    'packing_list' => 'Packing List',
                                    'form_e' => 'Form-E',
                                ];
                            @endphp
                            <li class="pr-timeline-item is-complete">
                                <span class="pr-timeline-marker" aria-hidden="true"></span>
                                <div class="tw-text-ui-sm fw-bold tw-text-on-surface">{{ $docLabels[$doc->doc_type] ?? $doc->doc_type }}: {{ ucfirst($doc->status) }}</div>
                                <time class="ui-tabular-nums tw-text-on-surface-variant tw-text-ui-xs" datetime="{{ $doc->updated_at->toIso8601String() }}">{{ $doc->updated_at->format('d M Y, H:i') }}</time>
                            </li>
                        @endif
                    @endforeach

                    <li class="pr-timeline-item {{ $po->estimated_arrival && $po->estimated_arrival->isPast() ? 'is-current' : '' }}">
                        <span class="pr-timeline-marker" aria-hidden="true"></span>
                        <div class="tw-text-ui-sm fw-bold {{ $po->estimated_arrival && $po->estimated_arrival->isPast() ? 'text-warning' : 'tw-text-on-surface-variant' }}">Estimated Arrival</div>
                        <time class="ui-tabular-nums tw-text-on-surface-variant tw-text-ui-xs">{{ $po->estimated_arrival ? $po->estimated_arrival->format('d M Y') : '-' }}</time>
                    </li>

                    <li class="pr-timeline-item {{ $po->actual_arrival ? 'is-complete' : '' }}">
                        <span class="pr-timeline-marker" aria-hidden="true"></span>
                        <div class="tw-text-ui-sm fw-bold {{ $po->actual_arrival ? 'text-success' : 'tw-text-outline' }}">Material Arrival</div>
                        @if($po->actual_arrival)
                            <time class="ui-tabular-nums tw-text-on-surface-variant tw-text-ui-xs">{{ $po->actual_arrival->format('d M Y') }}</time>
                        @endif
                    </li>
                </ol>
            </x-ui.card>
        </aside>
    </div>
</div>

{{-- Update Document Modal --}}
<div class="modal fade" id="updateDocModal" tabindex="-1" aria-labelledby="modalDocTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title fw-bold" id="modalDocTitle">Update Document Status</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body tw-p-3.5">
                <input type="hidden" id="modalDocId">
                <div>
                    <label class="form-label small fw-semibold tw-text-on-surface" for="modalDocStatus">Select New Status</label>
                    <select class="form-select form-select-sm" id="modalDocStatus"></select>
                </div>
            </div>
            <div class="modal-footer tw-bg-surface-low border-top">
                <x-ui.button type="button" variant="ghost" size="sm" data-bs-dismiss="modal">Cancel</x-ui.button>
                <x-ui.button type="button" size="sm" id="btnSaveDocStatus">
                    <span class="spinner-border spinner-border-sm d-none me-1" id="docSpinner"></span>
                    Save Changes
                </x-ui.button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    $(document).ready(function() {
        const sections = $('div[id^="sec-"]');
        const navLinks = $('#po-section-nav .nav-link');
        let isScrolling = false;

        $(window).on('scroll', function() {
            if (isScrolling) return;

            let current = '';
            const scrollPosition = $(window).scrollTop() + 100;

            let matching = [];
            sections.each(function() {
                const sectionTop = $(this).offset().top;
                const sectionHeight = $(this).outerHeight();
                if (scrollPosition >= sectionTop && scrollPosition < (sectionTop + sectionHeight)) {
                    matching.push($(this));
                }
            });

            if (matching.length > 0) {
                current = matching[0].attr('id');
            }

            if(current) {
                navLinks.removeClass('active');
                $(`#po-section-nav .nav-link[href="#${current}"]`).addClass('active');
            }
        });

        navLinks.on('click', function(e) {
            e.preventDefault();
            const targetId = $(this).attr('href');

            navLinks.removeClass('active');
            $(this).addClass('active');

            const targetPosition = $(targetId).offset().top - 80;

            isScrolling = true;
            $('html, body').stop().animate({
                scrollTop: targetPosition
            }, 300);

            setTimeout(() => {
                isScrolling = false;
            }, 350);
        });
    });

    $('.btn-update-doc').on('click', function() {
        const docId = $(this).data('doc-id');
        const docLabel = $(this).data('doc-label');
        const currentStatus = $(this).data('doc-status');
        const statuses = $(this).data('doc-statuses');

        $('#modalDocId').val(docId);
        $('#modalDocTitle').text('Update Document: ' + docLabel);

        const select = $('#modalDocStatus');
        select.empty();
        for (const [key, label] of Object.entries(statuses)) {
            select.append(`<option value="${key}" ${key === currentStatus ? 'selected' : ''}>${label}</option>`);
        }

        const modal = new bootstrap.Modal(document.getElementById('updateDocModal'));
        modal.show();
    });

    $('#btnSaveDocStatus').on('click', function() {
        const docId = $('#modalDocId').val();
        const newStatus = $('#modalDocStatus').val();

        $('#docSpinner').removeClass('d-none');
        $(this).prop('disabled', true);

        $.ajax({
            url: '/purchasing/po-documents/' + docId,
            method: 'PUT',
            data: {
                _token: '{{ csrf_token() }}',
                status: newStatus
            },
            success: function(res) {
                if (res.success) {
                    const statusLabels = {
                        'pending': @json('Not Available'), 'received': @json('Accepted'), 'verified': @json('Verified'),
                        'issued': @json('Issued'), 'processing': @json('Processing'), 'done': @json('Completed')
                    };
                    const statusClasses = {
                        'pending': 'ui-status-chip--neutral', 'received': 'ui-status-chip--info', 'verified': 'ui-status-chip--success',
                        'issued': 'ui-status-chip--info', 'processing': 'ui-status-chip--info', 'done': 'ui-status-chip--success'
                    };

                    const badge = $('#doc-badge-' + docId);
                    badge.text(statusLabels[res.doc.status] || res.doc.status);
                    badge.attr('class', 'ui-status-chip tw-mb-1.5 doc-status-badge ' + (statusClasses[res.doc.status] || 'ui-status-chip--neutral'));
                    badge.attr('data-status', res.doc.status);
                    $('#doc-date-' + docId).text(res.doc.updated_at);

                    $(`.btn-update-doc[data-doc-id="${docId}"]`).data('doc-status', res.doc.status);

                    const completedStatuses = ['received', 'verified', 'done'];
                    let completed = 0;
                    const total = {{ $totalDocs }};
                    completed = $('.doc-status-badge').filter(function() {
                        return completedStatuses.includes($(this).attr('data-status'));
                    }).length;

                    const pct = total > 0 ? (completed / total * 100) : 0;
                    $('#docProgressBar')
                        .css('width', pct + '%')
                        .attr('aria-valuenow', Math.round(pct));
                    const docsComplete = completed >= total;
                    $('#docProgressBadge')
                        .text(completed + '/' + total + ' complete')
                        .attr('class', 'ui-status-chip ' + (docsComplete ? 'ui-status-chip--success' : 'ui-status-chip--warning'))
                        .attr('data-bs-title', docsComplete
                            ? 'All import documents are complete.'
                            : 'Some import documents still need to be completed or verified.');
                    window.initAdasiTooltips?.(document);

                    if (docsComplete) {
                        if ($('#allDocsAlert').length === 0) {
                            const alertTemplate = document.getElementById('allDocsAlertTemplate');
                            $('.progress').after(alertTemplate ? alertTemplate.innerHTML : '');
                        }
                    } else {
                        $('#allDocsAlert').remove();
                    }

                    bootstrap.Modal.getInstance(document.getElementById('updateDocModal')).hide();

                    AdasiToast.show({
                        type: 'success',
                        title: @json('Success!'),
                        message: res.message,
                        autoClose: 1500
                    });
                }
            },
            error: function(xhr) {
                AdasiToast.show({
                    type: 'error',
                    title: @json('Update Failed'),
                    message: @json('The document status could not be updated.'),
                    autoClose: 4000
                });
            },
            complete: function() {
                $('#docSpinner').addClass('d-none');
                $('#btnSaveDocStatus').prop('disabled', false);
            }
        });
    });

    $('#btnConfirmArrival').on('click', function() {
        AdasiAlert.confirm({
            title: @json('Confirm Material Arrival?'),
            text: @json('The arrival date will be set to today and QC will be notified.'),
            confirmTone: 'success',
            confirmText: @json('Yes, Confirm!'),
            cancelText: @json('Cancel')
        }).then((result) => {
            if (result.isConfirmed) {
                $('#arrivalForm').submit();
            }
        });
    });
</script>
@endpush
