@extends('layouts.app')
@section('title', 'Invoice Register - Accounting')
@section('page-title', $title)

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        :title="$title"
        description="Filter, track physical verification, and manage supplier payment schedules."
        eyebrow="Accounting & Finance"
    >
        <x-slot:actions>
            <x-ui.button :href="route('accounting.dashboard')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>Dashboard</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @include('local-invoices.filters')

    <x-ui.data-table
        :title="$title"
        :description="'Displaying '.$invoices->total().' invoice record(s).'"
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
