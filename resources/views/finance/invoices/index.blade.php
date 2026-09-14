@extends('layouts.app')
@section('title', 'Invoice Register - Finance AP')
@section('page-title', $title ?? 'Invoice Register')

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        :title="$title ?? 'Invoice Register'"
        description="Daftar seluruh invoice supplier lokal, penerimaan dokumen fisik, status verifikasi, dan jadwal jatuh tempo."
        eyebrow="Finance & Accounts Payable"
    >
        <x-slot:actions>
            <x-ui.button :href="route('finance.dashboard')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>Dashboard</span>
            </x-ui.button>
            <x-ui.button :href="route('finance.drp.supplier')" variant="outline" size="sm">
                <x-ui.icon name="wallet" size="sm" />
                <span>Kelola DRP</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @include('local-invoices.filters')

    <x-ui.data-table
        :title="$title ?? 'Invoice Register'"
        :description="'Menampilkan '.$invoices->total().' data tagihan invoice.'"
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
