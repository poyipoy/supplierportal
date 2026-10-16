@extends('layouts.app')
@section('title', __('finance.closure.invoice_register_title'))
@section('page-title', $title ?? __('local_invoice.list.title'))

@section('content')
<div class="tw-grid tw-gap-6">
    <x-ui.page-header
        :title="$title ?? __('local_invoice.list.title')"
        :description="__('finance.labels.invoice_list_help')"
        :eyebrow="__('finance.labels.finance_ap')"
    >
        <x-slot:actions>
            <x-ui.button :href="route('finance.dashboard')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>{{ __('common.labels_review.dashboard') }}</span>
            </x-ui.button>
            <x-ui.button :href="route('finance.drp.supplier')" variant="outline" size="sm">
                <x-ui.icon name="wallet" size="sm" />
                <span>{{ __('finance.copy_review.manage_drp') }}</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @include('local-invoices.filters')

    <x-ui.data-table
        :title="$title ?? __('local_invoice.list.title')"
        :description="trans_choice('local_invoice.list.summary', $invoices->total())"
    >
        @include('local-invoices.table', ['invoices' => $invoices, 'portal' => 'finance', 'payments' => true])

        @if($invoices->hasPages())
            <x-slot:pagination>
                {{ $invoices->links() }}
            </x-slot:pagination>
        @endif
    </x-ui.data-table>
</div>
@endsection
