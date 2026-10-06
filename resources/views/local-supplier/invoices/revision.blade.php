@extends('layouts.app')
@section('title', __('local_invoice.actions.revise'))
@section('page-title', __('local_invoice.actions.revise'))

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        :title="__('local_invoice.form.revision_heading', ['number' => $invoice->submission_number])"
        :description="__('local_invoice.form.revision_description')"
        :eyebrow="__('local_invoice.form.portal')"
    >
        <x-slot:actions>
            <x-ui.button :href="route('local-supplier.invoices.show', $invoice)" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>{{ __('local_invoice.form.back_detail') }}</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if($invoice->statusHistories->where('event', 'revision_requested')->last()?->notes)
        <x-ui.alert tone="error" :title="__('finance.labels.revision_notes')">
            {{ $invoice->statusHistories->where('event', 'revision_requested')->last()->notes }}
        </x-ui.alert>
    @endif

    @include('local-invoices.form')
</div>
@endsection
