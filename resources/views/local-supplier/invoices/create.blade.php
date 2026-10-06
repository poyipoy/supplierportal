@extends('layouts.app')
@section('title', __('local_invoice.actions.submit'))
@section('page-title', __('local_invoice.actions.submit'))

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        :title="__('local_invoice.actions.submit_local')"
        :description="__('local_invoice.form.invoice_details_help')"
        :eyebrow="__('local_invoice.form.portal')"
    >
        <x-slot:actions>
            <x-ui.button :href="route('local-supplier.invoices.index')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>{{ __('local_invoice.form.back_list') }}</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @include('local-invoices.form')
</div>
@endsection
