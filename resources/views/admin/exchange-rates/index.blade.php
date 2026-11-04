@extends('layouts.app')
@section('title', __('admin.copy.exchange_rates_adasi_portal'))
@section('page-title', __('admin.copy.exchange_rates'))

@php
    $masterRoutePrefix = $masterRoutePrefix ?? 'admin';
@endphp

@section('content')
<div class="tw:grid tw:gap-6">
    <x-ui.page-header :title="__('admin.copy.exchange_rates')" :description="__('annual_rates.workspace_description')" :eyebrow="$masterRoutePrefix === 'purchasing.master-data' ? __('navigation.master_data') : __('admin.copy.admin_finance')">
        <x-slot:actions><span data-exchange-rates-effective-action @if($activeTab !== 'effective') hidden @endif><x-ui.button type="button" size="sm" data-bs-toggle="modal" data-bs-target="#rateModal"><x-ui.icon name="plus" /> {{ __('admin.copy.add_effective_rate') }}</x-ui.button></span></x-slot:actions>
    </x-ui.page-header>

    <div id="exchangeRatesWorkspace" class="tw:grid tw:min-w-0 tw:gap-6" data-server-tabs-container data-exchange-rates-workspace>
        <nav class="tw:inline-flex tw:w-full tw:sm:w-auto tw:gap-1.5 tw:overflow-x-auto tw:rounded-ui-md tw:border tw:border-outline tw:bg-surface-container tw:p-1.5 tw:shadow-xs" data-server-tabs-nav aria-label="{{ __('annual_rates.tabs_label') }}">
            @include('admin.exchange-rates._tabs')
        </nav>
        <div class="tw:min-w-0 tw:max-w-full" data-server-tabs-content>
            @include('admin.exchange-rates._content')
        </div>
        <script type="application/json" data-exchange-rates-config>@json(['refreshLabel' => __('annual_rates.refresh_list'), 'refreshFailed' => __('annual_rates.saved_refresh_failed'), 'saveUnknown' => __('annual_rates.save_unknown')])</script>
    </div>
</div>

<div class="modal fade" id="rateModal" tabindex="-1" aria-labelledby="rateModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form action="{{ route($masterRoutePrefix.'.exchange-rates.store') }}" method="POST" class="modal-content" data-exchange-rates-save data-async-files-hint="false">
            @csrf
            <div class="modal-header"><div><h2 class="modal-title fs-6 fw-bold" id="rateModalTitle">{{ __('admin.copy.add_effective_rate') }}</h2><p class="mb-0 mt-1 small text-muted">{{ __('admin.copy.a_new_record_is_appended_existing_values_remain_unchanged') }}</p></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('admin.copy.close') }}"></button></div>
            <div class="modal-body tw:grid tw:gap-4"><x-ui.select name="currency" :label="__('admin.copy.currency')" :options="\App\Models\ExchangeRate::currencyOptions()" :value="old('currency')" required /><x-ui.input name="rate_to_idr" type="number" :label="__('admin.copy.rate_to_idr')" step="0.01" min="1" :placeholder="__('admin.copy.example_15500')" required /><x-ui.date-picker name="valid_from" :label="__('admin.copy.effective_date')" :value="old('valid_from', date('Y-m-d'))" required /></div>
            <div class="modal-footer"><x-ui.button type="button" variant="ghost" data-bs-dismiss="modal">{{ __('admin.copy.cancel') }}</x-ui.button><x-ui.button type="submit"><x-ui.icon name="save" /> {{ __('admin.copy.save_rate') }}</x-ui.button></div>
        </form>
    </div>
</div>
@endsection

@if($activeTab === 'effective' && $errors->getBag('default')->any())
    @push('scripts')<script>document.addEventListener('DOMContentLoaded', () => bootstrap.Modal.getOrCreateInstance(document.getElementById('rateModal')).show());</script>@endpush
@endif
