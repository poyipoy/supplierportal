@extends('layouts.app')
@section('title', __('finance.closure.master_invoice_title'))
@section('page-title', __('common.final_review.master_reporting'))

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        :title="__('common.final_review.master_reporting')"
        :description="__('finance.copy_review.master_help')"
        :eyebrow="__('finance.labels.finance_ap')"
    >
        <x-slot:actions>
            <x-ui.button :href="route('finance.dashboard')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>{{ __('common.labels_review.dashboard') }}</span>
            </x-ui.button>
            <x-ui.button :href="route('finance.master-invoices.export', request()->all())" variant="outline" size="sm">
                <x-ui.icon name="file-spreadsheet" size="sm" />
                <span>{{ __('exports.actions.excel') }}</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Filter Quick Tabs: All, Unpaid, Paid --}}
    <div class="tw-flex tw-flex-wrap tw-items-center tw-gap-2">
        <a href="{{ route('finance.master-invoices') }}" class="btn btn-sm {{ empty(request('payment_status')) ? 'btn-primary' : 'btn-outline-secondary' }}">
            {{ __('local_invoice.filters.all_invoices') }}
        </a>
        <a href="{{ route('finance.master-invoices', array_merge(request()->except('page'), ['payment_status' => 'UNPAID'])) }}" class="btn btn-sm {{ request('payment_status') === 'UNPAID' ? 'btn-primary' : 'btn-outline-secondary' }}">
            {{ __('finance.drp.unpaid_label') }}
        </a>
        <a href="{{ route('finance.master-invoices', array_merge(request()->except('page'), ['payment_status' => 'PAID'])) }}" class="btn btn-sm {{ request('payment_status') === 'PAID' ? 'btn-primary' : 'btn-outline-secondary' }}">
            {{ __('finance.drp.paid_label') }}
        </a>
    </div>

    @include('local-invoices.filters')

    <x-ui.data-table
        :title="__('finance.labels.master_invoice')"
        :description="trans_choice('finance.closure.invoice_matches', $invoices->total())"
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
