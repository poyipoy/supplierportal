@extends('layouts.app')
@section('title', __('local_procurement.closure.po_detail_title', ['number' => $purchaseOrder->po_number]))
@section('uses-datatables', true)
@section('page-title', __('local_procurement.list.detail_short'))

@section('content')
<div class="tw:grid tw:gap-6 tw:pb-16">
    <x-ui.page-header
        :title="$purchaseOrder->po_number"
        :description="__('local_procurement.review.po_document', ['date' => $regionalFormatter->date($purchaseOrder->po_date, 'human') ?? '-', 'amount' => $regionalFormatter->money($purchaseOrder->total_amount)])"
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
        $activeGrCount = $purchaseOrder->active_gr_count;
        $invoicedAmount = \App\Support\Money::sum($purchaseOrder->invoices->whereNotIn('status', [\App\Models\LocalInvoice::STATUS_REJECTED, \App\Models\LocalInvoice::STATUS_CANCELLED])->pluck('invoice_amount'));
        $remainingPoCeiling = \App\Support\Money::atLeastZero(\App\Support\Money::subtract($purchaseOrder->total_amount, $invoicedAmount));
    @endphp

    <div class="tw:grid tw:grid-cols-1 tw:sm:grid-cols-2 tw:lg:grid-cols-4 tw:gap-4">
        <x-ui.metric-card
            :label="__('local_procurement.review.ceiling_value')"
            :value="'Rp ' . $regionalFormatter->money($purchaseOrder->total_amount, 0)"
            icon="banknote"
            tone="primary"
            :meta="__('local_procurement.closure.budget_commitment')"
        />

        <x-ui.metric-card
            :label="__('local_procurement.labels.gr_total')"
            :value="number_format($activeGrCount)"
            icon="package-check"
            tone="success"
            :meta="trans_choice('local_procurement.closure.gr_record_count', $activeGrCount)"
        />

        <x-ui.metric-card
            :label="__('local_procurement.labels.remaining_ceiling')"
            :value="'Rp ' . $regionalFormatter->money($remainingPoCeiling, 0)"
            icon="layers"
            :tone="\App\Support\Money::compare($remainingPoCeiling, \App\Support\Money::ZERO) > 0 ? 'neutral' : 'warning'"
            :meta="__('local_procurement.closure.remaining_capacity')"
        />

        <x-ui.metric-card
            :label="__('ga.dashboard.claim_total')"
            :value="'Rp ' . $regionalFormatter->money($invoicedAmount, 0)"
            icon="receipt"
            tone="neutral"
            :meta="trans_choice('local_procurement.closure.invoice_record_count', $purchaseOrder->invoices->count())"
        />
    </div>

    {{-- PO Meta Card --}}
    <div class="tw:rounded-ui-md tw:border tw:border-outline-variant tw:bg-surface-container tw:p-4 tw:space-y-4">
        <div class="tw:flex tw:flex-col tw:sm:flex-row tw:sm:items-center tw:sm:justify-between tw:gap-3">
            <div class="tw:flex tw:items-center tw:gap-3">
                <div class="tw:w-10 tw:h-10 tw:rounded-full tw:bg-primary/10 tw:text-primary tw:flex tw:items-center tw:justify-center tw:shrink-0">
                    <x-ui.icon name="file-text" size="md" />
                </div>
                <div>
                    <div class="tw:text-ui-base tw:font-bold tw:font-mono tw:text-on-surface">{{ $purchaseOrder->po_number }}</div>
                    <div class="tw:text-ui-xs tw:text-on-surface-variant">
                        {{ __('common.final_review.po_document_date', ['date' => $regionalFormatter->date($purchaseOrder->po_date, 'human') ?? '-']) }}
                    </div>
                </div>
            </div>
            <div class="tw:flex tw:items-center tw:gap-2">
                <x-ui.status-chip :tone="\App\Support\StatusHelper::localFinanceTone($purchaseOrder->status)">
                    {{ __('local_invoice.labels.status') }}: {{ \App\Support\StatusHelper::localFinanceLabel($purchaseOrder->status) }}
                </x-ui.status-chip>
            </div>
        </div>

        <div class="tw:grid tw:grid-cols-1 tw:sm:grid-cols-2 tw:gap-3 tw:border-t tw:border-outline-variant tw:pt-3 tw:text-ui-xs">
            <div class="tw:sm:col-span-2 tw:min-w-0">
                <span class="tw:text-on-surface-variant">{{ __('local_procurement.pr.label') }}:</span>
                <span class="tw:block tw:font-mono tw:text-on-surface tw:mt-0.5" style="overflow-wrap: anywhere;">{{ filled($purchaseOrder->pr_number) ? $purchaseOrder->pr_number : __('local_procurement.pr.empty') }}</span>
            </div>
            <div>
                <span class="tw:text-on-surface-variant">{{ __('local_procurement.labels.po_description_notes') }}:</span>
                <p class="tw:text-ui-xs tw:text-on-surface tw:mt-0.5 tw:mb-0">
                    {{ $purchaseOrder->description === 'Imported via Infor ERP Purchase Order' ? __('local_procurement.provenance.imported_po') : ($purchaseOrder->description ?: __('local_procurement.closure.po_no_notes')) }}
                </p>
            </div>
            <div>
                <span class="tw:text-on-surface-variant">{{ __('local_invoice.labels.attached_documents') }}:</span>
                <div class="tw:mt-1">
                    @if($purchaseOrder->latestPoDocument())
                        <a href="{{ route('attachments.show', $purchaseOrder->latestPoDocument()) }}" target="_blank" class="tw:inline-flex tw:items-center tw:gap-1.5 tw:text-primary tw:font-semibold tw:no-underline tw:hover:underline">
                            <x-ui.icon name="file-text" size="sm" />
                            <span>{{ $purchaseOrder->latestPoDocument()->file_name }}</span>
                        </a>
                    @else
                        <span class="tw:text-on-surface-variant tw:italic">{{ __('local_procurement.empty.po_document') }}</span>
                    @endif
                </div>
            </div>
        </div>
    </div>

    @include('finance.local-procurement._gr_table', ['routePrefix' => 'local-supplier.purchase-orders', 'readOnly' => true])

    {{-- Associated Invoices Table --}}
    @if($purchaseOrder->invoices->isNotEmpty())
        <x-ui.data-table
            :title="__('local_procurement.labels.invoice_history')"
            :description="__('local_procurement.review.invoice_po', ['po' => $purchaseOrder->po_number])"
        >
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 tw:text-ui-xs">
                    <thead class="table-light">
                        <tr>
                            <th scope="col" class="tw:text-ui-xs tw:font-semibold">{{ __('local_invoice.receipt.invoice_number') }}</th>
                            <th scope="col" class="tw:text-ui-xs tw:font-semibold">{{ __('local_invoice.labels.invoice_date') }}</th>
                            <th scope="col" class="tw:text-ui-xs tw:font-semibold text-end">{{ __('local_procurement.review.dpp') }}</th>
                            <th scope="col" class="tw:text-ui-xs tw:font-semibold text-center">{{ __('local_invoice.labels.status') }}</th>
                            <th scope="col" class="tw:text-ui-xs tw:font-semibold text-end">{{ __('local_invoice.labels.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($purchaseOrder->invoices as $inv)
                            <tr>
                                <td class="tw:font-mono tw:font-bold tw:text-on-surface">
                                    {{ $inv->invoice_number }}
                                </td>
                                <td>
                                    <span class="tw:text-ui-xs tw:text-on-surface-variant">
                                        {{ $regionalFormatter->date($inv->invoice_date, 'human') ?? '—' }}
                                    </span>
                                </td>
                                <td class="text-end tw:font-mono tw:font-bold tw:text-on-surface">
                                    Rp {{ $regionalFormatter->money($inv->invoice_amount) }}
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
