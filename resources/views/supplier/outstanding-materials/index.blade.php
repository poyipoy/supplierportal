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

    @php
        $supplierOutResetUrl = route('supplier.outstanding-materials.index');
        $supplierOutRemoveUrl = static function (array $dropKeys) use ($supplierOutResetUrl): string {
            $query = request()->query();
            foreach ($dropKeys as $key) {
                unset($query[$key]);
            }

            return $query === [] ? $supplierOutResetUrl : $supplierOutResetUrl.'?'.http_build_query($query);
        };

        $supplierOutChips = [];
        if (! empty($filters['search'])) {
            $supplierOutChips[] = [
                'key' => 'search',
                'label' => __('purchasing.copy.search'),
                'value' => (string) $filters['search'],
                'removeUrl' => $supplierOutRemoveUrl(['search']),
            ];
        }
        if (! empty($filters['status'])) {
            $supplierOutChips[] = [
                'key' => 'status',
                'label' => __('purchasing.outstanding_materials.col_status'),
                'value' => $statusOptions[$filters['status']] ?? (string) $filters['status'],
                'removeUrl' => $supplierOutRemoveUrl(['status']),
            ];
        }
    @endphp

    <x-ui.filter-bar
        id="supplierOutstandingFilterForm"
        :action="$supplierOutResetUrl"
        :sticky="true"
        :has-active-filters="count($supplierOutChips) > 0"
        :reset-url="$supplierOutResetUrl"
        :reset-label="__('purchasing.outstanding_materials.reset_button')"
        :submit-label="__('purchasing.outstanding_materials.filter_button')"
    >
        <x-slot:search>
            <label for="supplierOutstandingSearch" class="tw:sr-only">{{ __('purchasing.outstanding_materials.search_placeholder') }}</label>
            <x-ui.icon name="search" size="sm" class="ui-filter-bar__search-icon" aria-hidden="true" />
            <input
                id="supplierOutstandingSearch"
                type="search"
                name="search"
                class="form-control form-control-sm ui-filter-bar__search-input"
                value="{{ $filters['search'] ?? '' }}"
                placeholder="{{ __('purchasing.outstanding_materials.search_placeholder') }}"
                aria-label="{{ __('purchasing.outstanding_materials.search_placeholder') }}"
            >
        </x-slot:search>

        <x-slot:filters>
            <label for="supplierOutstandingStatus" class="tw:sr-only">{{ __('purchasing.outstanding_materials.col_status') }}</label>
            <select id="supplierOutstandingStatus" name="status" class="form-select form-select-sm tw:w-full tw:min-w-0 tw:sm:w-44" aria-label="{{ __('purchasing.outstanding_materials.col_status') }}">
                <option value="">{{ __('purchasing.outstanding_materials.all_statuses') }}</option>
                @foreach($statusOptions as $key => $label)
                    <option value="{{ $key }}" @selected(($filters['status'] ?? '') === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </x-slot:filters>

        <x-slot:chips>
            <x-ui.filter-chips :items="$supplierOutChips" :clear-url="$supplierOutResetUrl" />
        </x-slot:chips>
    </x-ui.filter-bar>

    @include('shared.outstanding-materials._table', [
        'rows' => $rows,
        'poShowRouteName' => 'supplier.purchase-orders.show',
    ])
</div>
@endsection
