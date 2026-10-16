@extends('layouts.app')
@section('title', __('local_invoice.list.page_title'))
@section('page-title', __('local_invoice.list.title'))

@section('content')
<div class="tw-grid tw-gap-6">
    <x-ui.page-header
        :title="__('local_invoice.list.all')"
        :description="__('local_invoice.list.description')"
        :eyebrow="__('local_invoice.labels.local_invoice')"
    >
        <x-slot:actions>
            <x-ui.button :href="route('local-supplier.invoices.create')" :disabled="(bool) ($supplierAuditInvoiceBlock ?? null)" :title="($supplierAuditInvoiceBlock ?? null) ? __('supplier_audit.invoice_block.short') : null" variant="primary" size="sm">
                <x-ui.icon name="plus" size="sm" />
                <span>{{ __('local_invoice.actions.submit') }}</span>
            </x-ui.button>
            <x-ui.button :href="route('local-supplier.dashboard')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>{{ __('common.labels_review.dashboard') }}</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @include('local-supplier.supplier-audits._invoice-block-banner')

    @include('local-invoices.filters')

    <x-ui.data-table
        :title="__('local_invoice.list.mine')"
        :description="trans_choice('local_invoice.list.summary', $invoices->total())"
    >
        @include('local-invoices.table', ['portal'=>'local-supplier'])

        @if($invoices->hasPages())
            <x-slot:pagination>
                {{ $invoices->links() }}
            </x-slot:pagination>
        @endif
    </x-ui.data-table>
</div>
@endsection
