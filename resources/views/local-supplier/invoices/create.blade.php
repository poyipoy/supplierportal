@extends('layouts.app')
@section('title', 'Ajukan Invoice')
@section('page-title', 'Ajukan Invoice')

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        title="Ajukan Invoice Lokal"
        description="Isi rincian tagihan invoice dan alokasikan Penerimaan Barang (GR) utuh yang sesuai."
        eyebrow="Portal Supplier Lokal"
    >
        <x-slot:actions>
            <x-ui.button :href="route('local-supplier.invoices.index')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>Kembali ke Daftar</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @include('local-invoices.form')
</div>
@endsection
