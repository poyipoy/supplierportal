@extends('layouts.app')
@section('title', 'Dashboard Supplier Lokal - Portal ADASI')
@section('page-title', 'Dashboard Invoice Lokal')

@section('content')
<div class="tw-grid tw-gap-6 tw-pb-16">
    <x-ui.page-header
        title="Dashboard Supplier Lokal"
        description="Ajukan invoice digital, pantau verifikasi dokumen fisik, dan monitor jadwal pembayaran."
        eyebrow="Penagihan & Invoice Lokal"
    >
        <x-slot:actions>
            <x-ui.button :href="route('local-supplier.invoices.create')" variant="primary">
                <x-ui.icon name="plus" size="sm" />
                <span>Ajukan Invoice</span>
            </x-ui.button>
            <x-ui.button :href="route('local-supplier.invoices.index')" variant="outline">
                <x-ui.icon name="list" size="sm" />
                <span>Lihat Semua Invoice</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- KPI Metric Cards Grid --}}
    @php
        $totalInvoices = $counts->sum();
        $inProgress = ($counts['SUBMITTED'] ?? 0) + ($counts['WAITING_PHYSICAL_DOCUMENT'] ?? 0) + ($counts['UNDER_REVIEW'] ?? 0);
        $readyToPay = ($counts['APPROVED'] ?? 0) + ($counts['PAYMENT_SCHEDULED'] ?? 0);
    @endphp

    <div class="tw-grid tw-grid-cols-2 md:tw-grid-cols-3 lg:tw-grid-cols-5 tw-gap-4">
        <x-ui.metric-card
            label="Total Diajukan"
            :value="$totalInvoices"
            icon="receipt"
            tone="primary"
            :href="route('local-supplier.invoices.index')"
        />
        <x-ui.metric-card
            label="Dalam Proses"
            :value="$inProgress"
            icon="clock"
            tone="warning"
            :href="route('local-supplier.invoices.index')"
        />
        <x-ui.metric-card
            label="Perlu Revisi"
            :value="$counts['NEED_REVISION'] ?? 0"
            icon="alert-circle"
            tone="error"
            :href="route('local-supplier.invoices.index', ['status' => 'NEED_REVISION'])"
        />
        <x-ui.metric-card
            label="Siap Dibayar"
            :value="$readyToPay"
            icon="check-circle"
            tone="success"
            :href="route('local-supplier.invoices.index')"
        />
        <x-ui.metric-card
            label="Selesai / Lunas"
            :value="$counts['COMPLETED'] ?? 0"
            icon="check"
            tone="primary"
            :href="route('local-supplier.invoices.index', ['status' => 'COMPLETED'])"
        />
    </div>

    {{-- Vendor Organization Quick Status --}}
    <x-ui.card>
        <div class="tw-flex tw-flex-col sm:tw-flex-row sm:tw-items-center sm:tw-justify-between tw-gap-4">
            <div class="tw-flex tw-items-center tw-gap-3">
                <div class="tw-w-10 tw-h-10 tw-rounded-full tw-bg-primary/10 tw-text-primary tw-flex tw-items-center tw-justify-center tw-shrink-0">
                    <x-ui.icon name="building-2" size="md" />
                </div>
                <div>
                    <h3 class="tw-m-0 tw-text-ui-sm tw-font-semibold tw-text-on-surface">
                        {{ auth()->user()->supplier?->company_name ?: auth()->user()->name }}
                    </h3>
                    @if(auth()->user()->supplier?->npwp)
                        <span class="tw-text-ui-xs tw-text-on-surface-variant">
                            NPWP: {{ auth()->user()->supplier->npwp }}
                        </span>
                    @endif
                </div>
            </div>
            <div>
                <x-ui.button :href="route('local-supplier.invoices.create')" variant="outline" size="sm">
                    <x-ui.icon name="upload-cloud" size="sm" />
                    <span>Upload Invoice Baru</span>
                </x-ui.button>
            </div>
        </div>
    </x-ui.card>

    {{-- Recent Invoices --}}
    <x-ui.data-table
        title="Invoice Terbaru"
        description="5 pengajuan invoice terakhir Anda."
    >
        <x-slot:toolbar>
            <x-ui.button :href="route('local-supplier.invoices.index')" variant="ghost" size="sm">
                <span>Lihat Semua Invoice</span>
                <x-ui.icon name="arrow-right" size="sm" />
            </x-ui.button>
        </x-slot:toolbar>

        @include('local-invoices.table', ['portal' => 'local-supplier', 'payments' => false])
    </x-ui.data-table>
</div>
@endsection
