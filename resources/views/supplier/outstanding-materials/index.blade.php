@extends('layouts.app')

@section('title', __('purchasing.outstanding_materials.title').' - ADASI Portal')
@section('page-title', __('purchasing.outstanding_materials.title'))

@section('content')
<div class="tw:grid tw:grid-cols-[minmax(0,1fr)] tw:min-w-0 tw:gap-4">
    <x-ui.breadcrumb :items="[
        __('purchasing.breadcrumbs.dashboard') => route('supplier.dashboard'),
        __('purchasing.outstanding_materials.title') => null,
    ]" />

    <x-ui.page-header
        :title="__('purchasing.outstanding_materials.title')"
        :eyebrow="__('purchasing.outstanding_materials.eyebrow')"
        :description="__('purchasing.outstanding_materials.subtitle')"
    />

    <x-ui.toolbar :sticky="true">
        <form method="GET" action="{{ route('supplier.outstanding-materials.index') }}" class="d-flex flex-wrap align-items-center gap-2 w-100" id="supplierOutstandingFilterForm">
            <div class="input-group input-group-sm" style="max-width: 320px;">
                <span class="input-group-text tw:bg-surface border-end-0 tw:text-on-surface-variant">
                    <x-ui.icon name="search" size="sm" />
                </span>
                <input
                    type="text"
                    name="search"
                    class="form-control border-start-0 ps-0"
                    value="{{ $filters['search'] ?? '' }}"
                    placeholder="{{ __('purchasing.outstanding_materials.search_placeholder') }}"
                    aria-label="{{ __('purchasing.outstanding_materials.search_placeholder') }}"
                >
            </div>

            <div style="min-width: 170px;">
                <select name="status" class="form-select form-select-sm" aria-label="{{ __('purchasing.outstanding_materials.col_status') }}">
                    <option value="">{{ __('purchasing.outstanding_materials.all_statuses') }}</option>
                    @foreach($statusOptions as $key => $label)
                        <option value="{{ $key }}" @selected(($filters['status'] ?? '') === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <x-ui.button type="submit" size="sm" variant="primary">
                <span>{{ __('purchasing.outstanding_materials.filter_button') }}</span>
            </x-ui.button>

            @if(! empty($filters['search']) || ! empty($filters['status']))
                <x-ui.button :href="route('supplier.outstanding-materials.index')" size="sm" variant="ghost">
                    <x-ui.icon name="rotate-ccw" size="sm" />
                    <span>{{ __('purchasing.outstanding_materials.reset_button') }}</span>
                </x-ui.button>
            @endif
        </form>
    </x-ui.toolbar>

    @include('shared.outstanding-materials._table', [
        'rows' => $rows,
        'poShowRouteName' => 'supplier.purchase-orders.show',
    ])
</div>
@endsection
