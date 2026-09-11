@extends('layouts.app')
@section('title', 'Track Invoices - Local Supplier')
@section('page-title', 'Track Invoices')

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        title="Track All Invoices"
        description="Monitor status, review verification notes, and check scheduled payment dates."
        eyebrow="Local Supplier Invoicing"
    >
        <x-slot:actions>
            <x-ui.button :href="route('local-supplier.invoices.create')" variant="primary" size="sm">
                <x-ui.icon name="plus" size="sm" />
                <span>Submit New Invoice</span>
            </x-ui.button>
            <x-ui.button :href="route('local-supplier.dashboard')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>Dashboard</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @include('local-invoices.filters')

    <x-ui.data-table
        title="My Invoices"
        :description="'Displaying '.$invoices->total().' invoice record(s).'"
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
