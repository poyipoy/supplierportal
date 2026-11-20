@extends('layouts.app')

@section('title', __('supplier_requests.title'))
@section('page-title', __('supplier_requests.title'))

@php
    $statusChipTones = [
        'draft' => 'neutral',
        'revision_requested' => 'warning',
        'submitted' => 'success',
        'accepted' => 'info',
        'rejected' => 'error',
        'all_unavailable' => 'neutral',
    ];
@endphp

@section('content')
<div class="tw:grid tw:gap-4">
    <x-ui.breadcrumb :items="[
        __('purchasing.breadcrumbs.dashboard') => route('supplier.dashboard'),
        __('supplier_requests.title') => null,
    ]" />

    <x-ui.page-header
        :title="__('supplier_requests.title')"
        :eyebrow="__('supplier_requests.eyebrow')"
        :description="__('supplier_requests.description')"
    />

    <x-ui.data-table
        :title="__('supplier_requests.list_title')"
        :description="__('supplier_requests.list_description')"
        :empty="$requisitions->isEmpty()"
    >
        <x-slot:filters>
            @php
                $supplierReqResetUrl = route('supplier.requests.index');
                $supplierReqRemoveUrl = static function (array $dropKeys) use ($supplierReqResetUrl): string {
                    $query = request()->query();
                    foreach ($dropKeys as $key) {
                        unset($query[$key]);
                    }

                    return $query === [] ? $supplierReqResetUrl : $supplierReqResetUrl.'?'.http_build_query($query);
                };

                $supplierReqChips = [];
                if (! empty($filters['search'])) {
                    $supplierReqChips[] = [
                        'key' => 'search',
                        'label' => __('supplier_requests.search'),
                        'value' => (string) $filters['search'],
                        'removeUrl' => $supplierReqRemoveUrl(['search']),
                    ];
                }
                if (! empty($filters['period_id'])) {
                    $selectedReqPeriod = $periods->firstWhere('id', (int) $filters['period_id']);
                    $supplierReqChips[] = [
                        'key' => 'period_id',
                        'label' => __('supplier_requests.period'),
                        'value' => $selectedReqPeriod ? $selectedReqPeriod->display_label : (string) $filters['period_id'],
                        'removeUrl' => $supplierReqRemoveUrl(['period_id']),
                    ];
                }
                if (! empty($filters['status'])) {
                    $supplierReqStatusVal = $filters['status'] === 'unresponded'
                        ? __('supplier.copy.awaiting_quotation')
                        : \App\Support\StatusHelper::quotationLabel((string) $filters['status']);
                    $supplierReqChips[] = [
                        'key' => 'status',
                        'label' => __('supplier.copy.my_quotation_status'),
                        'value' => $supplierReqStatusVal,
                        'removeUrl' => $supplierReqRemoveUrl(['status']),
                    ];
                }
            @endphp

            <x-ui.filter-bar
                id="supplierRequestFilterForm"
                :action="$supplierReqResetUrl"
                variant="embedded"
                :has-active-filters="count($supplierReqChips) > 0"
                :reset-url="$supplierReqResetUrl"
                :reset-label="__('supplier.copy.reset_filters')"
                :submit-label="__('supplier.copy.filter')"
            >
                <x-slot:search>
                    <label for="filter_request_search" class="tw:sr-only">{{ __('supplier_requests.search') }}</label>
                    <x-ui.icon name="search" size="sm" class="ui-filter-bar__search-icon" aria-hidden="true" />
                    <input
                        type="search"
                        id="filter_request_search"
                        name="search"
                        maxlength="100"
                        class="form-control form-control-sm ui-filter-bar__search-input"
                        value="{{ $filters['search'] ?? '' }}"
                        placeholder="{{ __('supplier.copy.e_g_req_05_2026_001') }}"
                    >
                </x-slot:search>

                <x-slot:filters>
                    <label for="filter_request_period" class="tw:sr-only">{{ __('supplier_requests.period') }}</label>
                    <select id="filter_request_period" name="period_id" class="form-select form-select-sm tw:w-full tw:min-w-0 tw:sm:w-48">
                        <option value="">{{ __('supplier_requests.all_periods') }}</option>
                        @foreach($periods as $period)
                            <option value="{{ $period->id }}" @selected((string) ($filters['period_id'] ?? '') === (string) $period->id)>{{ $period->display_label }}</option>
                        @endforeach
                    </select>

                    <label for="filter_request_status" class="tw:sr-only">{{ __('supplier.copy.my_quotation_status') }}</label>
                    <select id="filter_request_status" name="status" class="form-select form-select-sm tw:w-full tw:min-w-0 tw:sm:w-48">
                        <option value="">{{ __('supplier.copy.all_statuses') }}</option>
                        <option value="unresponded" @selected(($filters['status'] ?? '') === 'unresponded')>{{ __('supplier.copy.awaiting_quotation') }}</option>
                        @foreach(['draft', 'revision_requested', 'submitted', 'accepted', 'rejected', 'all_unavailable'] as $status)
                            <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ \App\Support\StatusHelper::quotationLabel($status) }}</option>
                        @endforeach
                    </select>
                </x-slot:filters>

                <x-slot:chips>
                    <x-ui.filter-chips :items="$supplierReqChips" :clear-url="$supplierReqResetUrl" />
                </x-slot:chips>
            </x-ui.filter-bar>
        </x-slot:filters>

        <x-slot:emptyState>
            <x-ui.empty-state :title="__('supplier_requests.empty_title')" :description="__('supplier_requests.empty_description')" />
        </x-slot:emptyState>

        <table class="table table-hover align-middle mb-0 tw:text-ui-xs w-100" id="supplierRequestTable">
            <thead class="table-light">
                <tr>
                    <th scope="col">{{ __('supplier.copy.pr_number') }}</th>
                    <th scope="col">{{ __('supplier_requests.columns.period') }}</th>
                    <th scope="col">{{ __('supplier_requests.columns.updated') }}</th>
                    <th scope="col" class="text-center">{{ __('supplier.copy.items') }}</th>
                    <th scope="col" class="text-center">{{ __('supplier_requests.columns.my_quotation') }}</th>
                    <th scope="col" class="text-end">{{ __('supplier.copy.action') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($requisitions as $pr)
                    @php
                        $quotation = $pr->quotations->first();
                        $status = $quotation?->status;
                    @endphp
                    <tr>
                        <td class="fw-bold">
                            <a href="{{ route('supplier.requests.show', $pr) }}" class="text-primary ui-focus-ring">{{ $pr->pr_number ?? '-' }}</a>
                        </td>
                        <td>{{ $pr->period?->display_label ?? '-' }}</td>
                        <td class="ui-tabular-nums tw:text-on-surface-variant">@bizdt($pr->updated_at)</td>
                        <td class="text-center ui-tabular-nums">{{ trans_choice('supplier_requests.item_count', $pr->items_count, ['count' => $pr->items_count]) }}</td>
                        <td class="text-center">
                            @if($status)
                                <span class="ui-status-chip ui-status-chip--{{ $statusChipTones[$status] ?? 'neutral' }}">{{ \App\Support\StatusHelper::quotationLabel($status) }}</span>
                            @else
                                <span class="ui-status-chip ui-status-chip--error">{{ __('supplier_requests.not_responded') }}</span>
                            @endif
                        </td>
                        <td class="text-end">
                            <a href="{{ route('supplier.requests.show', $pr) }}" class="ui-data-action ui-data-action--primary ui-focus-ring">{{ __('supplier_requests.actions.detail') }}</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if($requisitions->hasPages())
            <x-slot:pagination>
                <div class="tw:w-full tw:px-3 tw:py-2">{{ $requisitions->onEachSide(1)->links('pagination::bootstrap-5') }}</div>
            </x-slot:pagination>
        @endif
    </x-ui.data-table>
</div>
@endsection
