@extends('layouts.app')
@section('title', __('ga.receipt.page_title', ['number' => $claim->receipt?->receipt_number]))
@section('page-title', __('ga.receipt.title'))

@section('content')
<div class="local-invoice-receipt tw-max-w-2xl tw-mx-auto tw-my-6">
    <x-ui.page-header
        :title="__('ga.receipt.heading')"
        :description="$claim->receipt?->receipt_number"
    >
        <x-slot:actions>
            @php
                $currentUser = auth()->user();
                $resolvedDetailUrl = $detailUrl ?? (
                    ($currentUser && $currentUser->isFinance())
                        ? route('finance.ga-claims.show', $claim)
                        : route('ga.claims.show', $claim)
                );
            @endphp
            <x-ui.button :href="$resolvedDetailUrl" variant="ghost" size="sm" class="d-print-none">
                <x-ui.icon name="arrow-left" size="sm" />
                <span>{{ __('ga.actions.back_detail') }}</span>
            </x-ui.button>
            <x-ui.button type="button" onclick="window.print()" variant="primary" size="sm" class="d-print-none">
                <x-ui.icon name="printer" size="sm" />
                <span>{{ __('local_invoice.actions.print_receipt') }}</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card :title="__('ga.receipt.company_title')">
        <div class="tw-flex tw-flex-col sm:tw-flex-row tw-gap-6 tw-items-start">
            <dl class="row tw-text-ui-xs tw-flex-1 tw-m-0">
                <dt class="col-sm-4 text-muted">{{ __('local_invoice.receipt.number') }}</dt>
                <dd class="col-sm-8"><strong class="tw-font-mono tw-text-primary">{{ $claim->receipt?->receipt_number }}</strong></dd>

                <dt class="col-sm-4 text-muted">{{ __('ga.receipt.claim_number') }}</dt>
                <dd class="col-sm-8"><strong class="tw-font-mono">{{ $claim->claim_number }}</strong></dd>

                <dt class="col-sm-4 text-muted">{{ __('ga.labels.employee_name') }}</dt>
                <dd class="col-sm-8"><strong>{{ $claim->employee?->name }}</strong> ({{ $claim->employee?->department }})</dd>

                <dt class="col-sm-4 text-muted">{{ __('ga.labels.claim_type') }}</dt>
                <dd class="col-sm-8">{{ \App\Models\GaClaim::claimTypeLabel($claim->claim_type) }}</dd>

                <dt class="col-sm-4 text-muted">{{ __('ga.labels.claim_date') }}</dt>
                <dd class="col-sm-8">{{ $regionalFormatter->date($claim->claim_date, 'human') }}</dd>

                <dt class="col-sm-4 text-muted">{{ __('ga.labels.claim_amount') }}</dt>
                <dd class="col-sm-8"><strong class="tw-font-mono tw-text-ui-sm">Rp {{ number_format($claim->amount, 0, ',', '.') }}</strong></dd>

                <dt class="col-sm-4 text-muted">{{ __('ga.receipt.bank_account') }}</dt>
                <dd class="col-sm-8">{{ $claim->employee?->bank_name }} - {{ $claim->employee?->account_number }} {{ __('finance.drp_surface.account_holder') }} {{ $claim->employee?->account_holder_name }}</dd>

                <dt class="col-sm-4 text-muted">{{ __('local_invoice.labels.submitted_time') }}</dt>
                <dd class="col-sm-8">{{ $regionalFormatter->timestamp($claim->created_at, 'datetime') }}</dd>
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
                <strong>{{ __('local_invoice.receipt.important') }}</strong> {{ __('ga.receipt.instruction') }}
            </p>
        </div>
    </x-ui.card>
</div>
@endsection
