@extends('layouts.app')
@section('title', __('local_procurement.register.title'))
@section('page-title', __('local_procurement.register.title'))

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        :title="__('local_procurement.register.title')"
        :description="__('local_procurement.register.help')"
        :eyebrow="__('local_procurement.register.eyebrow')"
    >
        <x-slot:actions>
            <x-ui.button type="button" variant="outline" size="sm" data-bs-toggle="modal" data-bs-target="#uploadPoDocumentModal">
                <x-ui.icon name="file-up" size="sm" />
                <span>{{ __('local_procurement.actions.upload_po') }}</span>
            </x-ui.button>
            <x-ui.button type="button" variant="outline" size="sm" data-bs-toggle="modal" data-bs-target="#localPoImportModal">
                <x-ui.icon name="file-spreadsheet" size="sm" />
                <span>{{ __('local_procurement.import.po') }}</span>
            </x-ui.button>
            <x-ui.button type="button" variant="outline" size="sm" data-bs-toggle="modal" data-bs-target="#localGrImportModal">
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
    <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-2 lg:tw-grid-cols-4 tw-gap-4">
        <x-ui.metric-card
            :label="__('local_procurement.labels.master_total')"
            :value="number_format($metrics['total_pos'] ?? 0)"
            icon="layers"
            tone="neutral"
            :meta="__('local_procurement.register.all_active')"
        />

        <x-ui.metric-card
            :label="__('local_procurement.labels.po_total')"
            :value="'Rp ' . number_format($metrics['total_po_amount'] ?? 0, 0, ',', '.')"
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
    <form method="GET" action="{{ route($routePrefix.'.index') }}" id="localPoFilterForm" class="tw-m-0">
        <x-ui.toolbar class="tw-flex-col sm:tw-flex-row" aria-label="{{ __('local_procurement.register.filters') }}">
            <x-slot:search>
                <div class="tw-relative tw-w-full">
                    <input
                        type="search"
                        name="q"
                        id="local-po-search"
                        value="{{ request('q') }}"
                        placeholder="{{ __('local_procurement.filters.po_search') }}"
                        class="form-control form-control-sm tw-text-ui-xs"
                        maxlength="100"
                        autocomplete="off"
                    >
                </div>
            </x-slot:search>

            <x-slot:filters>
                <div class="tw-flex tw-w-full tw-max-w-full tw-flex-wrap tw-items-center tw-gap-2.5">
                    <div class="tw-w-full sm:tw-w-48">
                        <label for="po-supplier" class="visually-hidden">{{ __('local_invoice.labels.supplier') }}</label>
                        <select id="po-supplier" name="supplier_id" class="form-select form-select-sm tw-text-ui-xs">
                            <option value="">{{ __('local_invoice.labels.all_suppliers') }}</option>
                            @foreach($suppliers as $supplier)
                                <option value="{{ $supplier->hash }}" @selected((string) request('supplier_id') === (string) $supplier->hash)>
                                    {{ $supplier->supplier?->company_name ?: $supplier->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="tw-w-full sm:tw-w-36">
                        <label for="po-status" class="visually-hidden">{{ __('local_procurement.labels.po_status') }}</label>
                        <select id="po-status" name="status" class="form-select form-select-sm tw-text-ui-xs">
                            <option value="">{{ __('local_invoice.labels.all_statuses') }}</option>
                            @foreach(['OPEN', 'CLOSED', 'CANCELLED'] as $status)
                                <option value="{{ $status }}" @selected(request('status') === $status)>{{ \App\Support\StatusHelper::localFinanceLabel($status) }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="tw-w-full sm:tw-w-64">
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
                </div>
            </x-slot:filters>

            <x-slot:actions>
                <x-ui.button type="submit" size="sm" variant="primary">
                    <x-ui.icon name="filter" size="sm" />
                    <span>{{ __('common.labels_review.filter') }}</span>
                </x-ui.button>
                @if(request()->hasAny(['q', 'supplier_id', 'status', 'date_from', 'date_to']))
                    <x-ui.button :href="route($routePrefix.'.index')" size="sm" variant="ghost">
                        <x-ui.icon name="rotate-ccw" size="sm" />
                        <span>{{ __('common.actions.reset') }}</span>
                    </x-ui.button>
                @endif
            </x-slot:actions>
        </x-ui.toolbar>
    </form>

    {{-- Data Table --}}
    <x-ui.data-table
        :title="__('local_procurement.list.local')"
        :description="__('local_procurement.register.po_count', ['count' => $purchaseOrders->total()])"
    >
        <div class="table-responsive">
            <table class="table table-hover align-middle tw-m-0 tw-text-ui-sm w-100">
                <thead class="table-light">
                    <tr>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">{{ __('local_invoice.receipt.po_number') }}</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">{{ __('local_invoice.labels.supplier') }}</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold">{{ __('local_procurement.labels.po_date') }}</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">{{ __('local_procurement.register.po_value') }}</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">{{ __('local_procurement.register.gr_actual') }}</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold text-center">{{ __('local_invoice.labels.status') }}</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold text-center">{{ __('local_invoice.labels.source') }}</th>
                        <th scope="col" class="tw-text-ui-xs tw-font-semibold text-end">{{ __('local_invoice.labels.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($purchaseOrders as $po)
                        <tr>
                            <td>
                                <div class="d-flex align-items-center tw-gap-1.5">
                                    <a href="{{ route($routePrefix.'.show', $po) }}" class="tw-font-mono tw-font-semibold tw-text-primary hover:tw-text-primary-hover tw-no-underline hover:tw-underline">
                                        {{ $po->po_number }}
                                    </a>
                                    @if($po->latestPoDocument())
                                        <a href="{{ route('attachments.show', $po->latestPoDocument()) }}" target="_blank" class="badge bg-light text-primary border tw-no-underline hover:tw-bg-primary hover:tw-text-white tw-transition-colors" title="{{ __('local_procurement.actions.view_po') }}">
                                            <x-ui.icon name="file-text" size="xs" />
                                            <span>PDF</span>
                                        </a>
                                    @endif
                                </div>
                            </td>
                            <td>
                                <div class="tw-font-medium tw-text-on-surface">
                                    {{ $po->supplier->supplier?->company_name ?: $po->supplier->name }}
                                </div>
                                <div class="tw-text-[11px] tw-text-on-surface-variant">
                                    {{ \App\Support\StatusHelper::vendorCategoryLabel($po->supplier->supplier?->vendor_category ?: $po->supplier->supplier?->category ?: __('local_invoice.labels.supplier')) }}
                                </div>
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
                                    {{ __('local_procurement.register.gr_files', ['count' => $po->active_gr_count]) }}
                                </div>
                            </td>
                            <td class="text-center">
                                <x-ui.status-chip :tone="\App\Support\StatusHelper::localFinanceTone($po->status)">
                                    {{ \App\Support\StatusHelper::localFinanceLabel($po->status) }}
                                </x-ui.status-chip>
                            </td>
                            <td class="text-center">
                                <span class="tw-inline-flex tw-items-center tw-px-2 tw-py-0.5 tw-rounded tw-text-[10px] tw-font-semibold {{ $po->source === 'IMPORT' ? 'tw-bg-secondary/10 tw-text-secondary' : 'tw-bg-surface-high tw-text-on-surface' }}">
                                    {{ __('local_procurement.sources.'.strtolower($po->source ?: 'MANUAL')) }}
                                </span>
                            </td>
                            <td class="text-end">
                                <x-ui.button :href="route($routePrefix.'.show', $po)" size="sm" variant="outline">
                                    <x-ui.icon name="eye" size="sm" />
                                    <span>{{ __('local_invoice.actions.detail') }}</span>
                                </x-ui.button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="tw-py-8 tw-text-center tw-text-on-surface-variant tw-text-ui-sm">
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
</div>

@include('finance.local-procurement._import_po_modal')
@include('finance.local-procurement._import_gr_modal')
@include('finance.local-procurement._upload_po_modal')
@endsection
