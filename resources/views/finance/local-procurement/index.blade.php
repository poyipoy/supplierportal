@extends('layouts.app')
@section('title', __('local_procurement.register.title'))
@section('page-title', __('local_procurement.register.title'))

@section('content')
<div class="tw:grid tw:gap-6 tw:pb-16">
    <x-ui.page-header
        :title="__('local_procurement.register.title')"
        :description="__('local_procurement.register.help')"
        :eyebrow="__('local_procurement.register.eyebrow')"
    >
        <x-slot:actions>
            <x-ui.button type="button" variant="outline" size="sm" data-ui-modal-open="#uploadPoDocumentModal">
                <x-ui.icon name="file-up" size="sm" />
                <span>{{ __('local_procurement.actions.upload_po') }}</span>
            </x-ui.button>
            <x-ui.button type="button" variant="outline" size="sm" data-ui-modal-open="#uploadGrBulkModal">
                <x-ui.icon name="file-up" size="sm" />
                <span>{{ __('local_procurement.gr_documents.action') }}</span>
            </x-ui.button>
            <x-ui.button type="button" variant="outline" size="sm" data-ui-modal-open="#localPoImportModal">
                <x-ui.icon name="file-spreadsheet" size="sm" />
                <span>{{ __('local_procurement.import.po') }}</span>
            </x-ui.button>
            <x-ui.button type="button" variant="outline" size="sm" data-ui-modal-open="#localGrImportModal">
                <x-ui.icon name="package-check" size="sm" />
                <span>{{ __('local_procurement.import.gr') }}</span>
            </x-ui.button>
            <x-ui.button :href="route($routePrefix.'.create')" size="sm" variant="primary">
                <x-ui.icon name="plus" size="sm" />
                <span>{{ __('local_procurement.actions.new_po') }}</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- KPI Metric Cards Grid --}}
    <div class="tw:grid tw:grid-cols-1 tw:sm:grid-cols-2 tw:lg:grid-cols-4 tw:gap-4">
        <x-ui.metric-card
            :label="__('local_procurement.labels.master_total')"
            :value="number_format($metrics['total_pos'] ?? 0)"
            icon="layers"
            tone="neutral"
            :meta="__('local_procurement.register.all_active')"
        />

        <x-ui.metric-card
            :label="__('local_procurement.labels.po_total')"
            :value="'Rp ' . $regionalFormatter->money($metrics['total_po_amount'] ?? 0, 0)"
            icon="banknote"
            tone="primary"
            :meta="__('local_procurement.register.commitment')"
        />

        <x-ui.metric-card
            :label="__('local_procurement.register.open_pos')"
            :value="number_format($metrics['open_pos_count'] ?? 0)"
            icon="clock"
            tone="warning"
            :meta="__('local_procurement.register.can_gr')"
            :href="route($routePrefix.'.index', ['status' => 'OPEN'])"
        />

        <x-ui.metric-card
            :label="__('local_procurement.labels.gr_total')"
            :value="number_format($metrics['active_gr_count'] ?? 0)"
            icon="package-check"
            tone="success"
            :meta="__('local_procurement.register.active_grs', ['count' => number_format($metrics['active_gr_count'] ?? 0)])"
        />
    </div>

    {{-- Filter Toolbar --}}
    @php
        $localPoResetUrl = route($routePrefix.'.index');
        $localPoRemoveUrl = static function (array $dropKeys) use ($localPoResetUrl): string {
            $query = request()->query();
            foreach ($dropKeys as $key) {
                unset($query[$key]);
            }

            return $query === [] ? $localPoResetUrl : $localPoResetUrl.'?'.http_build_query($query);
        };

        $localPoAdvancedCount = collect([
            request()->filled('supplier_id'),
            request()->filled('date_from') || request()->filled('date_to'),
        ])->filter()->count();

        $localPoActiveChips = [];
        if (request()->filled('q')) {
            $localPoActiveChips[] = [
                'key' => 'q',
                'label' => __('local_invoice.actions.search'),
                'value' => (string) request('q'),
                'removeUrl' => $localPoRemoveUrl(['q']),
            ];
        }
        if (request()->filled('status')) {
            $localPoActiveChips[] = [
                'key' => 'status',
                'label' => __('local_procurement.labels.po_status'),
                'value' => \App\Support\StatusHelper::localFinanceLabel((string) request('status')),
                'removeUrl' => $localPoRemoveUrl(['status']),
            ];
        }
        if (request()->filled('supplier_id')) {
            $selectedLocalPoSupplier = $suppliers->firstWhere('hash', request('supplier_id'));
            $localPoSupplierName = $selectedLocalPoSupplier
                ? ($selectedLocalPoSupplier->supplier?->company_name ?: $selectedLocalPoSupplier->name)
                : (string) request('supplier_id');
            $localPoActiveChips[] = [
                'key' => 'supplier_id',
                'label' => __('local_invoice.labels.supplier'),
                'value' => $localPoSupplierName,
                'removeUrl' => $localPoRemoveUrl(['supplier_id']),
            ];
        }
        if (request()->filled('date_from') || request()->filled('date_to')) {
            $localPoDateVal = request()->filled('date_from') && request()->filled('date_to')
                ? request('date_from').' - '.request('date_to')
                : (string) (request('date_from') ?: request('date_to'));
            $localPoActiveChips[] = [
                'key' => 'date_range',
                'label' => __('local_invoice.labels.date'),
                'value' => $localPoDateVal,
                'removeUrl' => $localPoRemoveUrl(['date_from', 'date_to']),
            ];
        }
    @endphp

    <x-ui.filter-bar
        id="localPoFilterForm"
        :action="$localPoResetUrl"
        :aria-label="__('local_procurement.register.filters')"
        advanced-id="localPoMoreFilters"
        :advanced-count="$localPoAdvancedCount"
        :advanced-open="$localPoAdvancedCount > 0"
        :has-active-filters="count($localPoActiveChips) > 0"
        :reset-url="$localPoResetUrl"
        :reset-label="__('common.actions.reset')"
        :submit-label="__('common.labels_review.filter')"
    >
        <x-slot:search>
            <label for="local-po-search" class="tw:sr-only">{{ __('local_invoice.actions.search') }}</label>
            <x-ui.icon name="search" size="sm" class="ui-filter-bar__search-icon" aria-hidden="true" />
            <input
                type="search"
                name="q"
                id="local-po-search"
                value="{{ request('q') }}"
                placeholder="{{ __('local_procurement.filters.po_search') }}"
                class="ui-input ui-input--sm ui-filter-bar__search-input"
                maxlength="100"
                autocomplete="off"
            >
        </x-slot:search>

        <x-slot:filters>
            <label for="po-status" class="tw:sr-only">{{ __('local_procurement.labels.po_status') }}</label>
            <select id="po-status" name="status" class="ui-select ui-select--sm tw:w-full tw:min-w-0 tw:sm:w-40">
                <option value="">{{ __('local_invoice.labels.all_statuses') }}</option>
                @foreach(['OPEN', 'CLOSED', 'CANCELLED'] as $status)
                    <option value="{{ $status }}" @selected(request('status') === $status)>{{ \App\Support\StatusHelper::localFinanceLabel($status) }}</option>
                @endforeach
            </select>
        </x-slot:filters>

        <x-slot:advanced>
            <div>
                <label for="po-supplier" class="tw:block tw:text-ui-xs tw:font-semibold tw:text-on-surface tw:mb-1.5">{{ __('local_invoice.labels.supplier') }}</label>
                <select id="po-supplier" name="supplier_id" class="ui-select ui-select--sm tw:w-full">
                    <option value="">{{ __('local_invoice.labels.all_suppliers') }}</option>
                    @foreach($suppliers as $supplier)
                        <option value="{{ $supplier->hash }}" @selected((string) request('supplier_id') === (string) $supplier->hash)>
                            {{ $supplier->supplier?->company_name ?: $supplier->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="tw:block tw:text-ui-xs tw:font-semibold tw:text-on-surface tw:mb-1.5">{{ __('local_invoice.labels.date') }}</label>
                <x-ui.date-range-picker
                    id="local-po-date-range"
                    start-name="date_from"
                    end-name="date_to"
                    :start-label="__('local_invoice.labels.from_date')"
                    :end-label="__('local_invoice.labels.to_date')"
                    :start-value="request('date_from')"
                    :end-value="request('date_to')"
                    :compact="true"
                />
            </div>
        </x-slot:advanced>

        <x-slot:chips>
            <x-ui.filter-chips :items="$localPoActiveChips" :clear-url="$localPoResetUrl" />
        </x-slot:chips>
    </x-ui.filter-bar>

    {{-- Multi Main Table Workspace (Master + Satellite Preview) --}}
    <x-ui.mmt-workspace
        id="localPoMmtWorkspace"
        satellite-id="local-po-mmt-satellite"
        :master-label="__('local_procurement.mmt.master_label')"
        :satellite-title="__('local_procurement.mmt.satellite_title')"
        :satellite-empty-title="__('local_procurement.mmt.satellite_empty_title')"
        :satellite-empty="__('local_procurement.mmt.satellite_empty')"
        :master-frameless="true"
    >
        <x-ui.data-table
            :title="__('local_procurement.list.local')"
            :description="__('local_procurement.register.po_count', ['count' => $purchaseOrders->total()])"
        >
        <div class="ui-table-responsive">
            <table class="ui-table ui-table--hover tw:align-middle tw:m-0 tw:text-ui-sm tw:w-full">
                <thead>
                    <tr>
                        <th scope="col" class="tw:text-ui-xs tw:font-semibold">{{ __('local_invoice.receipt.po_number') }}</th>
                        <th scope="col" class="tw:text-ui-xs tw:font-semibold">{{ __('local_procurement.pr.label') }}</th>
                        <th scope="col" class="tw:text-ui-xs tw:font-semibold">{{ __('local_invoice.labels.supplier') }}</th>
                        <th scope="col" class="tw:text-ui-xs tw:font-semibold">{{ __('local_procurement.labels.po_date') }}</th>
                        <th scope="col" class="tw:text-ui-xs tw:font-semibold tw:text-end">{{ __('local_procurement.register.po_value') }}</th>
                        <th scope="col" class="tw:text-ui-xs tw:font-semibold tw:text-end">{{ __('local_procurement.register.gr_actual') }}</th>
                        <th scope="col" class="tw:text-ui-xs tw:font-semibold tw:text-center">{{ __('local_invoice.labels.status') }}</th>
                        <th scope="col" class="tw:text-ui-xs tw:font-semibold tw:text-center">{{ __('local_invoice.labels.source') }}</th>
                        <th scope="col" class="tw:text-ui-xs tw:font-semibold tw:text-end">{{ __('common.table.action') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($purchaseOrders as $po)
                        <tr
                            data-mmt-row-url="{{ \App\Support\PortalPath::routePath($routePrefix.'.satellite', $po) }}"
                            data-mmt-row-ref="{{ $po->po_number }}"
                            tabindex="0"
                            aria-selected="false"
                        >
                            <td>
                                <div class="tw:flex tw:items-center tw:gap-1.5">
                                    <a href="{{ route($routePrefix.'.show', $po) }}" class="ui-tabular-nums tw:font-semibold tw:text-primary tw:hover:text-primary tw:no-underline tw:hover:underline">
                                        {{ $po->po_number }}
                                    </a>
                                    @if($po->latestPoDocument())
                                        <a href="{{ route('attachments.show', $po->latestPoDocument()) }}" target="_blank" class="ui-status-chip ui-status-chip--info tw:border tw:border-outline-variant tw:no-underline tw:hover:bg-primary tw:hover:text-white tw:transition-colors" title="{{ __('local_procurement.actions.view_po') }}">
                                            <x-ui.icon name="file-text" size="xs" />
                                            <span>PDF</span>
                                        </a>
                                    @endif
                                </div>
                            </td>
                            <td>
                                <div class="ui-tabular-nums tw:text-on-surface" style="max-width: 16rem; overflow-wrap: anywhere;">
                                    {{ filled($po->pr_number) ? $po->pr_number : __('local_procurement.pr.empty') }}
                                </div>
                            </td>
                            <td>
                                <div class="tw:font-medium tw:text-on-surface">
                                    {{ $po->supplier->supplier?->company_name ?: $po->supplier->name }}
                                </div>
                                <div class="tw:text-[11px] tw:text-on-surface-variant">
                                    {{ \App\Support\StatusHelper::vendorCategoryLabel($po->supplier->supplier?->vendor_category ?: $po->supplier->supplier?->category ?: __('local_invoice.labels.supplier')) }}
                                </div>
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
                                    {{ __('local_procurement.register.gr_files', ['count' => $po->active_gr_count]) }}
                                </div>
                            </td>
                            <td class="tw:text-center">
                                <x-ui.status-chip :tone="\App\Support\StatusHelper::localFinanceTone($po->status)">
                                    {{ \App\Support\StatusHelper::localFinanceLabel($po->status) }}
                                </x-ui.status-chip>
                            </td>
                            <td class="tw:text-center">
                                <span class="tw:inline-flex tw:items-center tw:px-2 tw:py-0.5 tw:rounded-sm tw:text-[10px] tw:font-semibold {{ $po->source === 'IMPORT' ? 'tw:bg-secondary/10 tw:text-secondary' : 'tw:bg-surface-high tw:text-on-surface' }}">
                                    {{ __('local_procurement.sources.'.strtolower($po->source ?: 'MANUAL')) }}
                                </span>
                            </td>
                            <td class="tw:text-end">
                                <x-ui.row-action
                                    :href="route($routePrefix.'.show', $po)"
                                    :context="$po->po_number"
                                />
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="tw:py-8 tw:text-center tw:text-on-surface-variant tw:text-ui-sm">
                                {{ __('local_procurement.empty.po_filtered') }}
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
    </x-ui.mmt-workspace>
</div>

@include('finance.local-procurement._import_po_modal')
@include('finance.local-procurement._import_gr_modal')
@include('finance.local-procurement._upload_po_modal')
@include('finance.local-procurement._upload_gr_modal')
@endsection
