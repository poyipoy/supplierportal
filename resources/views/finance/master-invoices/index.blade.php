@extends('layouts.app')
@section('title', __('finance.closure.master_invoice_title'))
@section('page-title', __('common.final_review.master_reporting'))

@section('content')
<div id="masterInvoicesContainer" class="tw:grid tw:gap-6 tw:pb-16" data-server-tabs-container>
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
            <x-export.advanced-modal export-key="finance.local-invoices" :action="route('finance.master-invoices.export')" :suppliers="$suppliers" :filter-selectors="['q' => '#invoice-search', 'status' => '#invoice-status', 'payment_status' => '#invoice-payment-status', 'supplier' => '#invoice-supplier', 'overpayment_status' => '#invoice-overpayment-status', 'overdue' => '#overdue', 'from' => '#from', 'to' => '#to', 'due_from' => '#due_from', 'due_to' => '#due_to']" />
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Filter Quick Tabs: All, Unpaid, Paid --}}
    <nav aria-label="{{ __('common.final_review.master_reporting') }}" data-server-tabs-nav>
        @include('finance.master-invoices._status_nav')
    </nav>

    <div id="masterInvoicesContent" data-server-tabs-content>
        @include('finance.master-invoices._content')
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (window.AdasiServerTabs) {
        window.AdasiServerTabs.init('#masterInvoicesContainer', {
            cacheTtlMs: 30000,
            onLoaded: function (_data, context) {
                window.AdasiCalendar?.initialize(context?.content || document);
            },
        });
    }
});
</script>
@endpush
