@extends('layouts.app')
@section('title', __('local_invoice.receipt.page_title', ['number' => $invoice->receipt->receipt_number]))
@section('page-title', __('local_invoice.labels.invoice_receipt'))
@section('content')
<div class="local-invoice-receipt tw-max-w-2xl tw-mx-auto tw-my-6">
@php
    $user = auth()->user();
    $resolvedDetailUrl = $detailUrl ?? (function() use ($invoice, $user) {
        if (request()->routeIs('purchasing.*') || ($user && $user->isPurchasing())) {
            return route('purchasing.local-invoices.show', $invoice);
        }
        if (request()->routeIs('accounting.*') || ($user && $user->role === 'accounting')) {
            return route('accounting.invoices.show', $invoice);
        }
        if (request()->routeIs('finance.*') || ($user && ($user->isFinance() || $user->isAdmin()))) {
            return route('finance.invoices.show', $invoice);
        }
        return route('local-supplier.invoices.show', $invoice);
    })();

    $resolvedIndexUrl = $indexUrl ?? (function() use ($user) {
        if (request()->routeIs('purchasing.*') || ($user && $user->isPurchasing())) {
            return route('purchasing.local-vendors.index');
        }
        if (request()->routeIs('accounting.*') || ($user && $user->role === 'accounting')) {
            return route('accounting.invoices.index');
        }
        if (request()->routeIs('finance.*') || ($user && ($user->isFinance() || $user->isAdmin()))) {
            return route('finance.invoices.index');
        }
        return route('local-supplier.invoices.index');
    })();

    $resolvedIndexLabel = $indexLabel ?? ($user?->isPurchasing() ? __('local_procurement.labels.vendor_register') : __('local_invoice.list.title'));
@endphp
    <x-ui.page-header
        :title="__('local_invoice.labels.submission_receipt')"
        :description="$invoice->receipt->receipt_number"
        :breadcrumbs="[
            ['label' => $resolvedIndexLabel, 'url' => $resolvedIndexUrl],
            ['label' => $invoice->invoice_number, 'url' => $resolvedDetailUrl],
            ['label' => __('local_invoice.labels.receipt')],
        ]"
    >
        <x-slot:actions>
            <x-ui.button :href="$resolvedDetailUrl" variant="outline" size="sm" class="d-print-none">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>{{ __('local_invoice.actions.view_detail') }}</span>
            </x-ui.button>
            <x-ui.button type="button" data-print-receipt variant="primary" size="sm" class="d-print-none">
                <x-ui.icon name="printer" size="sm" />
                <span>{{ __('local_invoice.actions.print_receipt') }}</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if(session('success'))
        <div class="alert alert-success d-flex align-items-center mb-4 d-print-none" role="alert">
            <x-ui.icon name="circle-check" size="sm" class="me-2 tw-text-success tw-shrink-0" />
            <div>
                <strong>{{ __('local_invoice.receipt.recorded') }}</strong>
                <div class="tw-text-ui-xs tw-mt-0.5">{{ session('success') }}</div>
            </div>
        </div>
    @endif

    <x-ui.card :title="__('local_invoice.receipt.company_title')">
        <div class="tw-flex tw-flex-col sm:tw-flex-row tw-gap-6 tw-items-start">
            <dl class="row tw-text-ui-xs tw-flex-1 tw-m-0">
                <dt class="col-sm-4 text-muted">{{ __('local_invoice.receipt.number') }}</dt>
                <dd class="col-sm-8"><strong class="tw-font-mono tw-text-primary">{{ $invoice->receipt->receipt_number }}</strong></dd>

                <dt class="col-sm-4 text-muted">{{ __('local_invoice.receipt.submission_number') }}</dt>
                <dd class="col-sm-8"><strong class="tw-font-mono">{{ $invoice->submission_number }}</strong></dd>

                <dt class="col-sm-4 text-muted">{{ __('local_invoice.receipt.invoice_number') }}</dt>
                <dd class="col-sm-8"><strong class="tw-font-mono">{{ $invoice->invoice_number }}</strong></dd>

                <dt class="col-sm-4 text-muted">{{ __('local_invoice.receipt.po_number') }}</dt>
                <dd class="col-sm-8"><span class="tw-font-mono">{{ $invoice->po_number }}</span></dd>

                <dt class="col-sm-4 text-muted">{{ __('local_invoice.receipt.supplier_name') }}</dt>
                <dd class="col-sm-8"><strong>{{ $invoice->supplier->supplier?->company_name ?: $invoice->supplier->name }}</strong></dd>

                <dt class="col-sm-4 text-muted">{{ __('local_invoice.receipt.invoice_ppn') }}</dt>
                <dd class="col-sm-8"><strong class="tw-font-mono">Rp {{ number_format($invoice->invoice_amount, 0, ',', '.') }}</strong> (PPN: Rp {{ number_format($invoice->tax_amount, 0, ',', '.') }})</dd>

                <dt class="col-sm-4 text-muted">{{ __('local_invoice.labels.submitted_time') }}</dt>
                <dd class="col-sm-8">@bizdt($invoice->submitted_at)</dd>

                <dt class="col-sm-4 text-muted">{{ __('local_invoice.labels.revision') }}</dt>
                <dd class="col-sm-8">{{ $invoice->revision_number }}</dd>
            </dl>

            @if(isset($qrCode))
                <div class="tw-text-center tw-shrink-0 tw-p-3 tw-bg-surface-container tw-rounded-lg tw-border tw-border-outline-variant">
                    <img src="{{ $qrCode }}" alt="{{ __('local_invoice.receipt.qr_alt') }}" class="tw-w-28 tw-h-28 tw-mx-auto">
                    <span class="tw-block tw-text-[10px] tw-text-on-surface-variant tw-mt-1.5 tw-font-mono">{{ __('local_invoice.receipt.scan_valid') }}</span>
                </div>
            @endif
        </div>

        <div class="tw-mt-4 tw-p-3 tw-rounded tw-bg-surface-container tw-text-ui-xs tw-text-on-surface-variant">
            <p class="tw-m-0">
                <strong>{{ __('local_invoice.receipt.important') }}</strong> {{ __('local_invoice.receipt.instruction') }}
            </p>
        </div>

        <div class="tw-mt-5 tw-pt-4 tw-border-t tw-border-outline-variant tw-flex tw-items-center tw-justify-between d-print-none">
            <x-ui.button :href="$resolvedDetailUrl" variant="outline" size="sm">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>{{ __('local_invoice.actions.view_detail') }}</span>
            </x-ui.button>
            <x-ui.button type="button" data-print-receipt variant="primary" size="sm">
                <x-ui.icon name="printer" size="sm" />
                <span>{{ __('local_invoice.actions.print_receipt') }}</span>
            </x-ui.button>
        </div>
    </x-ui.card>
</div>
@include('local-invoices.scripts')
@endsection
