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
            <form method="GET" action="{{ route('supplier.requests.index') }}" class="row g-2 align-items-end w-100" id="supplierRequestFilterForm">
                <div class="col-md-4 col-lg-3">
                    <label class="form-label small fw-semibold tw:text-on-surface mb-1" for="filter_request_search">{{ __('supplier_requests.search') }}</label>
                    <input type="text" id="filter_request_search" name="search" maxlength="100" class="form-control form-control-sm" value="{{ $filters['search'] ?? '' }}" placeholder="{{ __('supplier.copy.e_g_req_05_2026_001') }}">
                </div>
                <div class="col-md-4 col-lg-3">
                    <label class="form-label small fw-semibold tw:text-on-surface mb-1" for="filter_request_period">{{ __('supplier_requests.period') }}</label>
                    <select id="filter_request_period" name="period_id" class="form-select form-select-sm">
                        <option value="">{{ __('supplier_requests.all_periods') }}</option>
                        @foreach($periods as $period)
                            <option value="{{ $period->id }}" @selected((string) ($filters['period_id'] ?? '') === (string) $period->id)>{{ $period->display_label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4 col-lg-3">
                    <label class="form-label small fw-semibold tw:text-on-surface mb-1" for="filter_request_status">{{ __('supplier.copy.my_quotation_status') }}</label>
                    <select id="filter_request_status" name="status" class="form-select form-select-sm">
                        <option value="">{{ __('supplier.copy.all_statuses') }}</option>
                        <option value="unresponded" @selected(($filters['status'] ?? '') === 'unresponded')>{{ __('supplier.copy.awaiting_quotation') }}</option>
                        @foreach(['draft', 'revision_requested', 'submitted', 'accepted', 'rejected', 'all_unavailable'] as $status)
                            <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ \App\Support\StatusHelper::quotationLabel($status) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-12 col-lg-3 d-flex tw:gap-1.5">
                    <x-ui.button type="submit" size="sm" class="tw:flex-1">
                        <x-ui.icon name="search" size="sm" class="me-1" />{{ __('supplier.copy.filter') }}
                    </x-ui.button>
                    <x-ui.icon-button icon="rotate-ccw" :label="__('supplier.copy.reset_filters')" variant="outline" size="sm" :href="route('supplier.requests.index')" />
                </div>
            </form>
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
