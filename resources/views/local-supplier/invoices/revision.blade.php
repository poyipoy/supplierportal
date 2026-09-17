@extends('layouts.app')
@section('title', 'Revisi Invoice')
@section('page-title', 'Revisi Invoice')

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        :title="'Revisi Pengajuan ' . $invoice->submission_number"
        description="Perbarui data dan unggah berkas revisi yang diminta oleh tim verifikasi."
        eyebrow="Portal Supplier Lokal"
    >
        <x-slot:actions>
            <x-ui.button :href="route('local-supplier.invoices.show', $invoice)" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>Kembali ke Detail</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if($invoice->statusHistories->where('event', 'revision_requested')->last()?->notes)
        <x-ui.alert tone="error" title="Catatan Permintaan Revisi Tim Finance">
            {{ $invoice->statusHistories->where('event', 'revision_requested')->last()->notes }}
        </x-ui.alert>
    @endif

    @include('local-invoices.form')
</div>
@endsection
