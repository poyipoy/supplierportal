@extends('layouts.app')
@section('title', __('accounting.labels.invoice_register'))
@section('page-title', $title)

@section('content')
<div class="tw-grid tw-gap-6">
    <x-ui.page-header
        :title="$title"
        :description="__('finance.labels.invoice_filter_help')"
        :eyebrow="__('accounting.review.audience')"
    >
        <x-slot:actions>
            <x-ui.button :href="route('accounting.dashboard')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>{{ __('common.labels_review.dashboard') }}</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @include('local-invoices.filters')

    <x-ui.data-table
        :title="$title"
        :description="trans_choice('local_invoice.list.summary', $invoices->total())"
    >
        @include('local-invoices.table', ['portal'=>'accounting'])

        @if($invoices->hasPages())
            <x-slot:pagination>
                {{ $invoices->links() }}
            </x-slot:pagination>
        @endif
    </x-ui.data-table>
</div>
@endsection
