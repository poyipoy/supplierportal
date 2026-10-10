@extends('layouts.app')
@section('title', __('local_procurement.closure.po_detail_title', ['number' => $purchaseOrder->po_number]))
@section('page-title', __('local_procurement.list.detail_short'))

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        :title="$purchaseOrder->po_number"
        :description="__('local_procurement.review.po_document', ['date' => $regionalFormatter->date($purchaseOrder->po_date, 'human') ?? '-', 'amount' => number_format($purchaseOrder->total_amount, 2, ',', '.')])"
        :eyebrow="__('local_procurement.list.detail_short')"
    >
        <x-slot:actions>
            <x-ui.button :href="route('local-supplier.purchase-orders.index')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>{{ __('local_invoice.form.back_list') }}</span>
            </x-ui.button>

            @if($purchaseOrder->latestPoDocument())
                <x-ui.button :href="route('attachments.show', $purchaseOrder->latestPoDocument())" target="_blank" variant="outline" size="sm">
                    <x-ui.icon name="file-text" size="sm" />
                    <span>{{ __('local_procurement.actions.download_po') }}</span>
                </x-ui.button>
            @endif

            @if($purchaseOrder->status === 'OPEN')
                <x-ui.button :href="route('local-supplier.invoices.create')" :disabled="(bool) ($supplierAuditInvoiceBlock ?? null)" :title="($supplierAuditInvoiceBlock ?? null) ? __('supplier_audit.invoice_block.short') : null" variant="primary" size="sm">
                    <x-ui.icon name="plus" size="sm" />
                    <span>{{ __('local_invoice.actions.submit') }}</span>
                </x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Summary Cards Grid --}}
    @php
        $activeGrs = $purchaseOrder->goodsReceipts->where('status', '!=', \App\Models\LocalGoodsReceipt::STATUS_CANCELLED);
        $invoicedAmount = (float) $purchaseOrder->invoices->whereNotIn('status', [\App\Models\LocalInvoice::STATUS_REJECTED, \App\Models\LocalInvoice::STATUS_CANCELLED])->sum('invoice_amount');
        $remainingPoCeiling = max(0, (float) $purchaseOrder->total_amount - $invoicedAmount);
    @endphp

    <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-2 lg:tw-grid-cols-4 tw-gap-4">
        <x-ui.metric-card
            :label="__('local_procurement.review.ceiling_value')"
            :value="'Rp ' . number_format($purchaseOrder->total_amount, 0, ',', '.')"
            icon="banknote"
            tone="primary"
            :meta="__('local_procurement.closure.budget_commitment')"
        />

        <x-ui.metric-card
            :label="__('local_procurement.labels.gr_total')"
            :value="number_format($activeGrs->count())"
            icon="package-check"
            tone="success"
            :meta="trans_choice('local_procurement.closure.gr_record_count', $activeGrs->count())"
        />

        <x-ui.metric-card
            :label="__('local_procurement.labels.remaining_ceiling')"
            :value="'Rp ' . number_format($remainingPoCeiling, 0, ',', '.')"
            icon="layers"
            :tone="$remainingPoCeiling > 0 ? 'neutral' : 'warning'"
            :meta="__('local_procurement.closure.remaining_capacity')"
        />

        <x-ui.metric-card
            :label="__('ga.dashboard.claim_total')"
            :value="'Rp ' . number_format($invoicedAmount, 0, ',', '.')"
            icon="receipt"
            tone="neutral"
            :meta="trans_choice('local_procurement.closure.invoice_record_count', $purchaseOrder->invoices->count())"
        />
    </div>

    {{-- PO Meta Card --}}
    <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface-container tw-p-4 tw-space-y-4">
        <div class="tw-flex tw-flex-col sm:tw-flex-row sm:tw-items-center sm:tw-justify-between tw-gap-3">
            <div class="tw-flex tw-items-center tw-gap-3">
                <div class="tw-w-10 tw-h-10 tw-rounded-full tw-bg-primary/10 tw-text-primary tw-flex tw-items-center tw-justify-center tw-shrink-0">
                    <x-ui.icon name="file-text" size="md" />
                </div>
                <div>
                    <div class="tw-text-ui-base tw-font-bold tw-font-mono tw-text-on-surface">{{ $purchaseOrder->po_number }}</div>
                    <div class="tw-text-ui-xs tw-text-on-surface-variant">
                        {{ __('common.final_review.po_document_date', ['date' => $regionalFormatter->date($purchaseOrder->po_date, 'human') ?? '-']) }}
                    </div>
                </div>
            </div>
            <div class="tw-flex tw-items-center tw-gap-2">
                <x-ui.status-chip :tone="\App\Support\StatusHelper::localFinanceTone($purchaseOrder->status)">
                    {{ __('local_invoice.labels.status') }}: {{ \App\Support\StatusHelper::localFinanceLabel($purchaseOrder->status) }}
                </x-ui.status-chip>
            </div>
        </div>

        <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-2 tw-gap-3 tw-border-t tw-border-outline-variant tw-pt-3 tw-text-ui-xs">
            <div>
                <span class="tw-text-on-surface-variant">{{ __('local_procurement.labels.po_description_notes') }}:</span>
                <p class="tw-text-ui-xs tw-text-on-surface tw-mt-0.5 tw-mb-0">
                    {{ $purchaseOrder->description === 'Imported via Infor ERP Purchase Order' ? __('local_procurement.provenance.imported_po') : ($purchaseOrder->description ?: __('local_procurement.closure.po_no_notes')) }}
                </p>
            </div>
            <div>
                <span class="tw-text-on-surface-variant">{{ __('local_invoice.labels.attached_documents') }}:</span>
                <div class="tw-mt-1">
                    @if($purchaseOrder->latestPoDocument())
                        <a href="{{ route('attachments.show', $purchaseOrder->latestPoDocument()) }}" target="_blank" class="tw-inline-flex tw-items-center tw-gap-1.5 tw-text-primary tw-font-semibold tw-no-underline hover:tw-underline">
                            <x-ui.icon name="file-text" size="sm" />
                            <span>{{ $purchaseOrder->latestPoDocument()->file_name }}</span>
                        </a>
                    @else
                        <span class="tw-text-on-surface-variant tw-italic">{{ __('local_procurement.empty.po_document') }}</span>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Goods Receipts (GR) Table --}}
    <x-ui.data-table
        :title="__('local_procurement.labels.gr_list')"
        :description="__('local_procurement.review.gr_po', ['po' => $purchaseOrder->po_number])"
    >
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 tw-text-ui-xs">
                <thead class="table-light">
                    <tr>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">{{ __('local_procurement.review.gr_number') }}</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">{{ __('local_procurement.labels.gr_date') }}</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">{{ __('common.fields.qty') }}</th>
                        <th scope="col">{{ __('local_procurement.labels.uom') }}</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">{{ __('ga.labels.claim_description') }}</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold text-center">{{ __('local_procurement.labels.gr_status') }}</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold text-center">{{ __('local_invoice.labels.billing_status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($purchaseOrder->goodsReceipts as $gr)
                        <tr>
                            <td class="tw-font-mono tw-font-bold tw-text-on-surface">
                                {{ $gr->gr_number }}
                            </td>
                            <td>
                                <span class="tw-text-ui-xs tw-text-on-surface-variant">
                                    {{ $regionalFormatter->date($gr->gr_date, 'human') ?? '—' }}
                                </span>
                            </td>
                            <td class="text-end tw-font-mono">
                                {{ $gr->qty ? \App\Models\LocalGoodsReceipt::formatQuantity($gr->qty) : '-' }}
                            </td>
                            <td>{{ $gr->uom ?? '—' }}</td>
                            <td>
                                <span class="tw-text-ui-xs tw-text-on-surface">
                                    {{ $gr->description ?: '-' }}
                                </span>
                            </td>
                            <td class="text-center">
                                <x-ui.status-chip :tone="\App\Support\StatusHelper::localFinanceTone($gr->status)">
                                    {{ \App\Support\StatusHelper::localFinanceLabel($gr->status) }}
                                </x-ui.status-chip>
                            </td>
                            <td class="text-center">
                                @if($gr->currentInvoice)
                                    <a href="{{ route('local-supplier.invoices.show', $gr->currentInvoice) }}" class="badge bg-success-subtle text-success border border-success-subtle tw-no-underline hover:tw-underline">
                                        {{ __('local_procurement.closure.invoiced_number', ['number' => $gr->currentInvoice->invoice_number]) }}
                                    </a>
                                @else
                                    <span class="badge bg-light text-secondary border">
                                        {{ __('local_procurement.labels.not_invoiced') }}
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="tw-py-8 tw-text-center tw-text-on-surface-variant tw-text-ui-sm">
                                {{ __('local_procurement.empty.gr_po') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.data-table>

    {{-- Associated Invoices Table --}}
    @if($purchaseOrder->invoices->isNotEmpty())
        <x-ui.data-table
            :title="__('local_procurement.labels.invoice_history')"
            :description="__('local_procurement.review.invoice_po', ['po' => $purchaseOrder->po_number])"
        >
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 tw-text-ui-xs">
                    <thead class="table-light">
                        <tr>
                            <th scope="col" class="tw-text-ui-xs tw-font-semibold">{{ __('local_invoice.receipt.invoice_number') }}</th>
                            <th scope="col" class="tw-text-ui-xs tw-font-semibold">{{ __('local_invoice.labels.invoice_date') }}</th>
                            <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">{{ __('local_procurement.review.dpp') }}</th>
                            <th scope="col" class="tw-text-ui-xs tw-font-semibold text-center">{{ __('local_invoice.labels.status') }}</th>
                            <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">{{ __('local_invoice.labels.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($purchaseOrder->invoices as $inv)
                            <tr>
                                <td class="tw-font-mono tw-font-bold tw-text-on-surface">
                                    {{ $inv->invoice_number }}
                                </td>
                                <td>
                                    <span class="tw-text-ui-xs tw-text-on-surface-variant">
                                        {{ $regionalFormatter->date($inv->invoice_date, 'human') ?? '—' }}
                                    </span>
                                </td>
                                <td class="text-end tw-font-mono tw-font-bold tw-text-on-surface">
                                    Rp {{ number_format($inv->invoice_amount, 2, ',', '.') }}
                                </td>
                                <td class="text-center">
                                    <x-ui.status-chip :tone="\App\Support\StatusHelper::localInvoiceTone($inv->status)">
                                        {{ \App\Support\StatusHelper::localInvoiceLabel($inv->status) }}
                                    </x-ui.status-chip>
                                </td>
                                <td class="text-end">
                                    <x-ui.button :href="route('local-supplier.invoices.show', $inv)" size="sm" variant="outline">
                                        <x-ui.icon name="eye" size="sm" />
                                        <span>{{ __('local_invoice.actions.detail') }}</span>
                                    </x-ui.button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.data-table>
    @endif
</div>
@endsection
