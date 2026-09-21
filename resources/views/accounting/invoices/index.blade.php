@extends('layouts.app')
@section('title', 'Invoice Register - Accounting')
@section('page-title', $title)

@section('content')
<div class="tw-grid tw-gap-6">
    <x-ui.page-header
        :title="$title"
        description="Filter, pantau verifikasi dokumen fisik, dan kelola jadwal pembayaran invoice supplier."
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
        :description="'Menampilkan '.$invoices->total().' data tagihan invoice.'"
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
