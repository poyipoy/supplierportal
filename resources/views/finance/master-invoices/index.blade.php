@extends('layouts.app')
@section('title', 'Laporan Master Invoice - Finance AP')
@section('page-title', 'Master Invoices (Repository & Reporting)')

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        title="Master Invoices (Reporting & Repository)"
        description="Pusat query dan pelaporan seluruh invoice supplier lokal lintas periode, status pelunasan, dan rekapitulasi nilai pembayaran."
        eyebrow="Finance & Accounts Payable"
    >
        <x-slot:actions>
            <x-ui.button :href="route('finance.dashboard')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>Dashboard</span>
            </x-ui.button>
            <x-ui.button :href="route('finance.master-invoices.export', request()->all())" variant="outline" size="sm">
                <x-ui.icon name="file-spreadsheet" size="sm" />
                <span>Export Excel</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Filter Quick Tabs: All, Unpaid, Paid --}}
    <div class="tw-flex tw-flex-wrap tw-items-center tw-gap-2">
        <a href="{{ route('finance.master-invoices') }}" class="btn btn-sm {{ empty(request('payment_status')) ? 'btn-primary' : 'btn-outline-secondary' }}">
            Semua Invoice
        </a>
        <a href="{{ route('finance.master-invoices', array_merge(request()->except('page'), ['payment_status' => 'UNPAID'])) }}" class="btn btn-sm {{ request('payment_status') === 'UNPAID' ? 'btn-primary' : 'btn-outline-secondary' }}">
            Belum Lunas (Unpaid)
        </a>
        <a href="{{ route('finance.master-invoices', array_merge(request()->except('page'), ['payment_status' => 'PAID'])) }}" class="btn btn-sm {{ request('payment_status') === 'PAID' ? 'btn-primary' : 'btn-outline-secondary' }}">
            Sudah Lunas (Paid)
        </a>
    </div>

    @include('local-invoices.filters')

    <x-ui.data-table
        title="Data Master Invoice"
        :description="'Ditemukan '.$invoices->total().' data tagihan invoice sesuai kriteria filter.'"
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
