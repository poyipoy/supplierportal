@extends('layouts.app')
@section('title', __('local_procurement.closure.po_title'))
@section('page-title', __('local_procurement.closure.po_title'))

@section('content')
<div class="tw:grid tw:gap-6 tw:pb-16">
    <x-ui.page-header
        :title="__('local_procurement.list.title')"
        :description="__('local_procurement.review.po_help')"
        :eyebrow="__('local_procurement.review.local')"
    >
        <x-slot:actions>
            <x-ui.button :href="route('local-supplier.dashboard')" variant="outline" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>{{ __('common.labels_review.dashboard') }}</span>
            </x-ui.button>
            <x-ui.button :href="route('local-supplier.invoices.create')" :disabled="(bool) ($supplierAuditInvoiceBlock ?? null)" :title="($supplierAuditInvoiceBlock ?? null) ? __('supplier_audit.invoice_block.short') : null" variant="primary" size="sm">
                <x-ui.icon name="plus" size="sm" />
                <span>{{ __('local_invoice.actions.submit') }}</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Filter Bar --}}
    @php
        $supplierPoResetUrl = route('local-supplier.purchase-orders.index');
        $supplierPoRemoveUrl = static function (array $dropKeys) use ($supplierPoResetUrl): string {
            $query = request()->query();
            foreach ($dropKeys as $key) {
                unset($query[$key]);
            }

            return $query === [] ? $supplierPoResetUrl : $supplierPoResetUrl.'?'.http_build_query($query);
        };

        $supplierPoChips = [];
        if (request()->filled('q')) {
            $supplierPoChips[] = [
                'key' => 'q',
                'label' => __('local_invoice.actions.search'),
                'value' => (string) request('q'),
                'removeUrl' => $supplierPoRemoveUrl(['q']),
            ];
        }
        if (request()->filled('status')) {
            $supplierPoStatusVal = match (request('status')) {
                'OPEN' => __('status.finance.open'),
                'CLOSED' => __('status.claim.closed'),
                'CANCELLED' => __('local_invoice.labels.cancelled'),
                default => (string) request('status'),
            };
            $supplierPoChips[] = [
                'key' => 'status',
                'label' => __('local_invoice.labels.status'),
                'value' => $supplierPoStatusVal,
                'removeUrl' => $supplierPoRemoveUrl(['status']),
            ];
        }
        if (request()->filled('date_from') || request()->filled('date_to')) {
            $supplierPoDateVal = request()->filled('date_from') && request()->filled('date_to')
                ? request('date_from').' - '.request('date_to')
                : (string) (request('date_from') ?: request('date_to'));
            $supplierPoChips[] = [
                'key' => 'date_range',
                'label' => __('local_invoice.labels.date'),
                'value' => $supplierPoDateVal,
                'removeUrl' => $supplierPoRemoveUrl(['date_from', 'date_to']),
            ];
        }
    @endphp

    <x-ui.filter-bar
        id="supplierPoFilterForm"
        :action="$supplierPoResetUrl"
        :aria-label="__('common.accessibility.po_filters')"
        :has-active-filters="count($supplierPoChips) > 0"
        :reset-url="$supplierPoResetUrl"
        :reset-label="__('common.actions.reset')"
        :submit-label="__('common.labels_review.filter')"
    >
        <x-slot:search>
            <label for="supplier-po-search" class="tw:sr-only">{{ __('local_invoice.actions.search') }}</label>
            <x-ui.icon name="search" size="sm" class="ui-filter-bar__search-icon" aria-hidden="true" />
            <input
                type="search"
                name="q"
                id="supplier-po-search"
                value="{{ request('q') }}"
                placeholder="{{ __('local_procurement.filters.po_search') }}"
                class="ui-input ui-input--sm ui-filter-bar__search-input"
                maxlength="100"
                autocomplete="off"
            >
        </x-slot:search>

        <x-slot:filters>
            <label for="supplier-po-status" class="tw:sr-only">{{ __('local_invoice.labels.status') }}</label>
            <select
                name="status"
                id="supplier-po-status"
                class="ui-select ui-select--sm tw:w-full tw:min-w-0 tw:sm:w-44"
            >
                <option value="">{{ __('local_invoice.labels.all_statuses') }}</option>
                <option value="OPEN" {{ request('status') === 'OPEN' ? 'selected' : '' }}>{{ __('status.finance.open') }}</option>
                <option value="CLOSED" {{ request('status') === 'CLOSED' ? 'selected' : '' }}>{{ __('status.claim.closed') }}</option>
                <option value="CANCELLED" {{ request('status') === 'CANCELLED' ? 'selected' : '' }}>{{ __('local_invoice.labels.cancelled') }}</option>
            </select>

            <div class="tw:w-full tw:min-w-0 tw:sm:w-64">
                <x-ui.date-range-picker
                    id="supplier-po-date-range"
                    start-name="date_from"
                    end-name="date_to"
                    start-id="supplier_po_date_from"
                    end-id="supplier_po_date_to"
                    :start-label="__('local_invoice.labels.from_date')"
                    :end-label="__('local_invoice.labels.to_date')"
                    :start-value="request('date_from')"
                    :end-value="request('date_to')"
                    :compact="true"
                />
            </div>
        </x-slot:filters>

        <x-slot:chips>
            <x-ui.filter-chips :items="$supplierPoChips" :clear-url="$supplierPoResetUrl" />
        </x-slot:chips>
    </x-ui.filter-bar>

    {{-- PO Data Table --}}
    <x-ui.data-table
        :title="__('local_procurement.review.registered')"
        :description="__('local_procurement.review.po_count', ['count' => $purchaseOrders->total()])"
    >
        <div class="ui-table-scroll">
            <table class="ui-table ui-table--hover tw:align-middle tw:m-0 tw:text-ui-xs">
                <thead>
                    <tr>
                        <th scope="col" class="tw:text-ui-xs tw:font-semibold">{{ __('local_invoice.receipt.po_number') }}</th>
                        <th scope="col" class="tw:text-ui-xs tw:font-semibold">{{ __('local_procurement.pr.label') }}</th>
                        <th scope="col" class="tw:text-ui-xs tw:font-semibold">{{ __('local_procurement.labels.po_date') }}</th>
                        <th scope="col" class="tw:text-ui-xs tw:font-semibold tw:text-end">{{ __('local_procurement.review.ceiling') }}</th>
                        <th scope="col" class="tw:text-ui-xs tw:font-semibold tw:text-end">{{ __('local_procurement.review.gr_actual') }}</th>
                        <th scope="col" class="tw:text-ui-xs tw:font-semibold tw:text-center">{{ __('local_invoice.labels.status') }}</th>
                        <th scope="col" class="tw:text-ui-xs tw:font-semibold tw:text-center">{{ __('local_procurement.labels.po_document') }}</th>
                        <th scope="col" class="tw:text-ui-xs tw:font-semibold tw:text-end">{{ __('common.table.action') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($purchaseOrders as $po)
                        <tr>
                            <td>
                                <a href="{{ route('local-supplier.purchase-orders.show', $po) }}" class="ui-tabular-nums tw:font-bold tw:text-primary tw:hover:text-primary tw:no-underline tw:hover:underline">
                                    {{ $po->po_number }}
                                </a>
                            </td>
                            <td>
                                <span class="tw:block ui-tabular-nums tw:text-ui-xs tw:text-on-surface" style="max-width: 16rem; overflow-wrap: anywhere;">
                                    {{ filled($po->pr_number) ? $po->pr_number : __('local_procurement.pr.empty') }}
                                </span>
                            </td>
                            <td>
                                <span class="tw:text-ui-xs tw:text-on-surface-variant">
                                    {{ $regionalFormatter->date($po->po_date, 'human') ?? '—' }}
                                </span>
                            </td>
                            <td class="tw:text-end ui-tabular-nums tw:font-bold tw:text-on-surface">
                                Rp {{ $regionalFormatter->money($po->total_amount, 2) }}
                            </td>
                            <td class="tw:text-end">
                                <div class="ui-tabular-nums tw:font-semibold tw:text-on-surface">
                                    {{ $po->active_gr_count }} GR
                                </div>
                                <div class="tw:text-[11px] tw:text-on-surface-variant">
                                    {{ __('local_procurement.review.gr_count', ['count' => $po->active_gr_count]) }}
                                </div>
                            </td>
                            <td class="tw:text-center">
                                <x-ui.status-chip :tone="\App\Support\StatusHelper::localFinanceTone($po->status)">
                                    {{ \App\Support\StatusHelper::localFinanceLabel($po->status) }}
                                </x-ui.status-chip>
                            </td>
                            <td class="tw:text-center">
                                @if($po->latestPoDocument())
                                    <x-ui.button :href="route('attachments.show', $po->latestPoDocument())" target="_blank" size="sm" variant="outline">
                                        <x-ui.icon name="file-text" size="xs" />
                                        <span>PDF</span>
                                    </x-ui.button>
                                @else
                                    <span class="tw:text-on-surface-variant tw:text-[11px] tw:italic">{{ __('local_invoice.empty.not_uploaded') }}</span>
                                @endif
                            </td>
                            <td class="tw:text-end">
                                <x-ui.row-action
                                    :href="route('local-supplier.purchase-orders.show', $po)"
                                    :context="$po->po_number"
                                />
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="tw:py-8 tw:text-center tw:text-on-surface-variant tw:text-ui-sm">
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
