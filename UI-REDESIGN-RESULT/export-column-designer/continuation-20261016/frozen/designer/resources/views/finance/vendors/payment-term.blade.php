@extends('layouts.app')
@php $company = $supplier->company_name ?: $vendor->name; @endphp
@section('title', __('local_procurement.payment_term.title').' - '.$company)
@section('page-title', __('local_procurement.payment_term.title'))

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-24">
    <x-ui.page-header
        :title="__('local_procurement.payment_term.title')"
        :description="$company"
    >
        <x-slot:actions>
            <x-ui.button :href="route('finance.vendor-master.show', $vendor)" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>{{ __('local_procurement.payment_term.back') }}</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <form action="{{ route('finance.vendor-master.payment-term.update', $vendor) }}" method="POST" novalidate>
        @csrf
        @method('PUT')

        <x-ui.form-section
            :title="__('local_procurement.payment_term.section')"
            :description="__('local_procurement.payment_term.section_help')"
        >
            <div class="tw-grid tw-gap-4 tw-max-w-xl">
                <div>
                    <span class="tw-block tw-text-ui-xs tw-text-on-surface-variant">{{ __('local_procurement.payment_term.current') }}</span>
                    <strong class="tw-text-ui-sm tw-text-on-surface">
                        {{ $supplier->payment_term_days ? trans_choice('local_invoice.table.term_days', $supplier->payment_term_days) : '—' }}
                    </strong>
                </div>

                <x-ui.input
                    name="payment_term_days"
                    type="number"
                    :label="__('local_procurement.payment_term.field').' ('.__('local_procurement.payment_term.days_suffix').')'"
                    :value="$supplier->payment_term_days"
                    :helper="__('local_procurement.payment_term.field_help')"
                    :required="true"
                    min="1"
                    max="365"
                    step="1"
                    inputmode="numeric"
                    class="tw-max-w-[12rem]"
                />

                <x-ui.textarea
                    name="reason"
                    :label="__('local_procurement.payment_term.reason')"
                    :helper="__('local_procurement.payment_term.reason_help')"
                    :required="true"
                    :rows="3"
                    maxlength="500"
                />

                <x-ui.alert tone="info">
                    {{ $affectedInvoices > 0
                        ? __('local_procurement.payment_term.impact', ['count' => $affectedInvoices])
                        : __('local_procurement.payment_term.impact_none') }}
                </x-ui.alert>
            </div>
        </x-ui.form-section>

        <x-ui.action-bar>
            <x-slot:right>
                <x-ui.button :href="route('finance.vendor-master.show', $vendor)" variant="ghost">
                    {{ __('local_invoice.actions.cancel') }}
                </x-ui.button>
                <x-ui.button type="submit">
                    <x-ui.icon name="check" size="sm" />
                    <span>{{ __('local_procurement.payment_term.save') }}</span>
                </x-ui.button>
            </x-slot:right>
        </x-ui.action-bar>
    </form>
</div>
@endsection
