@extends('layouts.app')

@section('title', __('purchasing.outstanding_materials.title').' - ADASI Portal')
@section('page-title', __('purchasing.outstanding_materials.title'))

@section('content')
<div class="tw:grid tw:grid-cols-[minmax(0,1fr)] tw:min-w-0 tw:gap-4">
    <x-ui.breadcrumb :items="[
        __('purchasing.breadcrumbs.dashboard') => route('purchasing.dashboard'),
        __('purchasing.outstanding_materials.title') => null,
    ]" />

    <x-ui.page-header
        :title="__('purchasing.outstanding_materials.title')"
        :eyebrow="__('purchasing.outstanding_materials.eyebrow')"
        :description="__('purchasing.outstanding_materials.subtitle')"
    />

    @php
        $outstandingResetUrl = route('purchasing.outstanding-materials.index');
        $outstandingRemoveUrl = static function (array $dropKeys) use ($outstandingResetUrl): string {
            $query = request()->query();
            foreach ($dropKeys as $key) {
                unset($query[$key]);
            }

            return $query === [] ? $outstandingResetUrl : $outstandingResetUrl.'?'.http_build_query($query);
        };

        $outstandingChips = [];
        if (! empty($filters['search'])) {
            $outstandingChips[] = [
                'key' => 'search',
                'label' => __('purchasing.copy.search'),
                'value' => (string) $filters['search'],
                'removeUrl' => $outstandingRemoveUrl(['search']),
            ];
        }
        if (! empty($filters['status'])) {
            $outstandingChips[] = [
                'key' => 'status',
                'label' => __('purchasing.outstanding_materials.col_status'),
                'value' => $statusOptions[$filters['status']] ?? (string) $filters['status'],
                'removeUrl' => $outstandingRemoveUrl(['status']),
            ];
        }
        if (! empty($filters['supplier_id'])) {
            $selectedOutSupplier = $suppliers->first(fn ($s) => (string) $s->getRouteKey() === (string) $filters['supplier_id']);
            $outstandingChips[] = [
                'key' => 'supplier_id',
                'label' => __('purchasing.outstanding_materials.col_supplier'),
                'value' => $selectedOutSupplier ? $selectedOutSupplier->name : (string) $filters['supplier_id'],
                'removeUrl' => $outstandingRemoveUrl(['supplier_id']),
            ];
        }
    @endphp

    <x-ui.filter-bar
        id="outstandingFilterForm"
        :action="$outstandingResetUrl"
        :sticky="true"
        :has-active-filters="count($outstandingChips) > 0"
        :reset-url="$outstandingResetUrl"
        :reset-label="__('purchasing.outstanding_materials.reset_button')"
        :submit-label="__('purchasing.outstanding_materials.filter_button')"
    >
        <x-slot:search>
            <label for="outstandingSearchInput" class="tw:sr-only">{{ __('purchasing.outstanding_materials.search_placeholder') }}</label>
            <x-ui.icon name="search" size="sm" class="ui-filter-bar__search-icon" aria-hidden="true" />
            <input
                id="outstandingSearchInput"
                type="search"
                name="search"
                class="ui-input ui-input--sm ui-filter-bar__search-input"
                value="{{ $filters['search'] ?? '' }}"
                placeholder="{{ __('purchasing.outstanding_materials.search_placeholder') }}"
                aria-label="{{ __('purchasing.outstanding_materials.search_placeholder') }}"
            >
        </x-slot:search>

        <x-slot:filters>
            <label for="outstandingStatusFilter" class="tw:sr-only">{{ __('purchasing.outstanding_materials.col_status') }}</label>
            <select id="outstandingStatusFilter" name="status" class="ui-select ui-select--sm tw:w-full tw:min-w-0 tw:sm:w-44" aria-label="{{ __('purchasing.outstanding_materials.col_status') }}">
                <option value="">{{ __('purchasing.outstanding_materials.all_statuses') }}</option>
                @foreach($statusOptions as $key => $label)
                    <option value="{{ $key }}" @selected(($filters['status'] ?? '') === $key)>{{ $label }}</option>
                @endforeach
            </select>

            <label for="outstandingSupplierFilter" class="tw:sr-only">{{ __('purchasing.outstanding_materials.col_supplier') }}</label>
            <select id="outstandingSupplierFilter" name="supplier_id" class="ui-select ui-select--sm tw:w-full tw:min-w-0 tw:sm:w-52" aria-label="{{ __('purchasing.outstanding_materials.col_supplier') }}">
                <option value="">{{ __('purchasing.outstanding_materials.all_suppliers') }}</option>
                @foreach($suppliers as $supplier)
                    <option value="{{ $supplier->getRouteKey() }}" @selected((string) ($filters['supplier_id'] ?? '') === (string) $supplier->getRouteKey())>
                        {{ $supplier->name }}
                    </option>
                @endforeach
            </select>
        </x-slot:filters>

        <x-slot:chips>
            <x-ui.filter-chips :items="$outstandingChips" :clear-url="$outstandingResetUrl" />
        </x-slot:chips>
    </x-ui.filter-bar>

    @include('shared.outstanding-materials._table', [
        'rows' => $rows,
        'poShowRouteName' => 'purchasing.purchase-orders.show',
    ])
</div>
@endsection
