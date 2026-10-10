@extends('layouts.app')
@section('title', __('local_procurement.closure.po_title'))
@section('page-title', __('local_procurement.closure.po_title'))

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        :title="__('local_procurement.list.title')"
        :description="__('local_procurement.review.po_help')"
        :eyebrow="__('local_procurement.review.local')"
    >
        <x-slot:actions>
            <x-ui.button :href="route('local-supplier.dashboard')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>{{ __('common.labels_review.dashboard') }}</span>
            </x-ui.button>
            <x-ui.button :href="route('local-supplier.invoices.create')" :disabled="(bool) ($supplierAuditInvoiceBlock ?? null)" :title="($supplierAuditInvoiceBlock ?? null) ? __('supplier_audit.invoice_block.short') : null" variant="primary" size="sm">
                <x-ui.icon name="plus" size="sm" />
                <span>{{ __('local_invoice.actions.submit') }}</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Filter Toolbar --}}
    <form method="GET" action="{{ route('local-supplier.purchase-orders.index') }}" id="supplierPoFilterForm" class="tw-m-0">
        <x-ui.toolbar aria-label="{{ __('common.accessibility.po_filters') }}">
            <x-slot:search>
                <div class="tw-relative tw-w-full">
                    <input
                        type="search"
                        name="q"
                        id="supplier-po-search"
                        value="{{ request('q') }}"
                        placeholder="{{ __('local_procurement.filters.po_search') }}"
                        class="form-control form-control-sm tw-text-ui-xs"
                        maxlength="100"
                        autocomplete="off"
                    >
                </div>
            </x-slot:search>

            <x-slot:filters>
                <div class="tw-w-full sm:tw-w-44">
                    <select
                        name="status"
                        id="supplier-po-status"
                        class="form-select form-select-sm tw-text-ui-xs"
                        onchange="this.form.submit()"
                    >
                        <option value="">{{ __('local_invoice.labels.all_statuses') }}</option>
                        <option value="OPEN" {{ request('status') === 'OPEN' ? 'selected' : '' }}>{{ __('status.finance.open') }}</option>
                        <option value="CLOSED" {{ request('status') === 'CLOSED' ? 'selected' : '' }}>{{ __('status.claim.closed') }}</option>
                        <option value="CANCELLED" {{ request('status') === 'CANCELLED' ? 'selected' : '' }}>{{ __('local_invoice.labels.cancelled') }}</option>
                    </select>
                </div>

                <div class="tw-w-full sm:tw-w-40">
                    <x-ui.date-picker
                        name="date_from"
                        id="supplier_po_date_from"
                        value="{{ request('date_from') }}"
                        :placeholder="__('local_invoice.labels.from_date')"
                    />
                </div>

                <div class="tw-w-full sm:tw-w-40">
                    <x-ui.date-picker
                        name="date_to"
                        id="supplier_po_date_to"
                        value="{{ request('date_to') }}"
                        :placeholder="__('local_invoice.labels.to_date')"
                    />
                </div>

                <x-ui.button type="submit" size="sm" variant="secondary">
                    <x-ui.icon name="filter" size="sm" />
                    <span>{{ __('common.labels_review.filter') }}</span>
                </x-ui.button>

                @if(request()->hasAny(['q', 'status', 'date_from', 'date_to']))
                    <x-ui.button :href="route('local-supplier.purchase-orders.index')" size="sm" variant="ghost">
                        <x-ui.icon name="rotate-ccw" size="sm" />
                        <span>{{ __('common.actions.reset') }}</span>
                    </x-ui.button>
                @endif
            </x-slot:filters>
        </x-ui.toolbar>
    </form>

    {{-- PO Data Table --}}
    <x-ui.data-table
        :title="__('local_procurement.review.registered')"
        :description="__('local_procurement.review.po_count', ['count' => $purchaseOrders->total()])"
    >
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 tw-text-ui-xs">
                <thead class="table-light">
                    <tr>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">{{ __('local_invoice.receipt.po_number') }}</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">{{ __('local_procurement.labels.po_date') }}</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">{{ __('local_procurement.review.ceiling') }}</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">{{ __('local_procurement.review.gr_actual') }}</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold text-center">{{ __('local_invoice.labels.status') }}</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold text-center">{{ __('local_procurement.labels.po_document') }}</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">{{ __('local_invoice.labels.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($purchaseOrders as $po)
                        <tr>
                            <td>
                                <a href="{{ route('local-supplier.purchase-orders.show', $po) }}" class="tw-font-mono tw-font-bold tw-text-primary hover:tw-text-primary-hover tw-no-underline hover:tw-underline">
                                    {{ $po->po_number }}
                                </a>
                            </td>
                            <td>
                                <span class="tw-text-ui-xs tw-text-on-surface-variant">
                                    {{ $regionalFormatter->date($po->po_date, 'human') ?? '—' }}
                                </span>
                            </td>
                            <td class="text-end tw-font-mono tw-font-bold tw-text-on-surface">
                                Rp {{ number_format($po->total_amount, 2, ',', '.') }}
                            </td>
                            <td class="text-end">
                                <div class="tw-font-mono tw-font-semibold tw-text-on-surface">
                                    {{ $po->active_gr_count }} GR
                                </div>
                                <div class="tw-text-[11px] tw-text-on-surface-variant">
                                    {{ __('local_procurement.review.gr_count', ['count' => $po->active_gr_count]) }}
                                </div>
                            </td>
                            <td class="text-center">
                                <x-ui.status-chip :tone="\App\Support\StatusHelper::localFinanceTone($po->status)">
                                    {{ \App\Support\StatusHelper::localFinanceLabel($po->status) }}
                                </x-ui.status-chip>
                            </td>
                            <td class="text-center">
                                @if($po->latestPoDocument())
                                    <x-ui.button :href="route('attachments.show', $po->latestPoDocument())" target="_blank" size="sm" variant="outline">
                                        <x-ui.icon name="file-text" size="xs" />
                                        <span>PDF</span>
                                    </x-ui.button>
                                @else
                                    <span class="tw-text-on-surface-variant tw-text-[11px] tw-italic">{{ __('local_invoice.empty.not_uploaded') }}</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <x-ui.button :href="route('local-supplier.purchase-orders.show', $po)" size="sm" variant="outline">
                                    <x-ui.icon name="eye" size="sm" />
                                    <span>{{ __('local_invoice.actions.detail') }}</span>
                                </x-ui.button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="tw-py-8 tw-text-center tw-text-on-surface-variant tw-text-ui-sm">
                                {{ __('local_procurement.empty.po') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($purchaseOrders->hasPages())
            <x-slot:pagination>
                {{ $purchaseOrders->links() }}
            </x-slot:pagination>
        @endif
    </x-ui.data-table>
</div>
@endsection
