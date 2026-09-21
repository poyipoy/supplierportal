@extends('layouts.app')
@section('title', 'Daftar Invoice - Supplier Lokal')
@section('page-title', 'Daftar Invoice')

@section('content')
<div class="tw-grid tw-gap-6">
    <x-ui.page-header
        title="Daftar Seluruh Invoice"
        description="Pantau status verifikasi, catatan revisi, dan jadwal pembayaran invoice Anda."
        eyebrow="Invoice Supplier Lokal"
    >
        <x-slot:actions>
            <x-ui.button :href="route('local-supplier.invoices.create')" variant="primary" size="sm">
                <x-ui.icon name="plus" size="sm" />
                <span>Ajukan Invoice</span>
            </x-ui.button>
            <x-ui.button :href="route('local-supplier.dashboard')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>Dashboard</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @include('local-invoices.filters')

    <x-ui.data-table
        title="Invoice Saya"
        :description="'Menampilkan '.$invoices->total().' data invoice.'"
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
