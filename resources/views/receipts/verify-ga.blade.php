@extends('layouts.guest')
@section('title', __('ga.receipt.public_title'))

@section('content')
<div class="tw-min-h-screen tw-flex tw-items-center tw-justify-center tw-p-4 tw-bg-surface-dim">
    <div class="tw-max-w-md tw-w-full tw-bg-surface tw-rounded-xl tw-shadow-lg tw-border tw-border-outline-variant tw-overflow-hidden">
        {{-- Header Card --}}
        <div class="tw-bg-primary tw-text-on-primary tw-p-6 tw-text-center">
            <div class="tw-w-12 tw-h-12 tw-mx-auto tw-mb-3 tw-rounded-full tw-bg-white/20 tw-flex tw-items-center tw-justify-center">
                <x-ui.icon name="check-circle" size="lg" class="tw-text-white" />
            </div>
            <h1 class="tw-text-ui-base tw-font-bold tw-m-0">{{ __('ga.receipt.verified') }}</h1>
            <p class="tw-text-xs tw-text-white/80 tw-m-0 tw-mt-1">PT Astra Daido Steel Indonesia — General Affairs</p>
        </div>

        {{-- Body --}}
        <div class="tw-p-6 tw-space-y-4 tw-text-ui-xs">
            <div class="tw-space-y-2">
                <div class="tw-flex tw-justify-between tw-border-b tw-border-outline-variant tw-pb-2">
                    <span class="tw-text-on-surface-variant">{{ __('common.final_review.receipt_number') }}</span>
                    <strong class="tw-font-mono tw-text-primary">{{ $receipt->receipt_number }}</strong>
                </div>
                <div class="tw-flex tw-justify-between tw-border-b tw-border-outline-variant tw-pb-2">
                    <span class="tw-text-on-surface-variant">{{ __('common.labels_review.claim_number') }}</span>
                    <strong class="tw-font-mono">{{ $claim->claim_number }}</strong>
                </div>
                <div class="tw-flex tw-justify-between tw-border-b tw-border-outline-variant tw-pb-2">
                    <span class="tw-text-on-surface-variant">{{ __('ga.labels.employee_name') }}:</span>
                    <strong>{{ $claim->employee?->name }} ({{ $claim->employee?->department }})</strong>
                </div>
                <div class="tw-flex tw-justify-between tw-border-b tw-border-outline-variant tw-pb-2">
                    <span class="tw-text-on-surface-variant">{{ __('ga.labels.claim_type') }}:</span>
                    <span>{{ \App\Models\GaClaim::claimTypeLabel($claim->claim_type) }}</span>
                </div>
                <div class="tw-flex tw-justify-between tw-border-b tw-border-outline-variant tw-pb-2">
                    <span class="tw-text-on-surface-variant">{{ __('ga.labels.claim_date') }}:</span>
                    <span>{{ $claim->claim_date?->format('d/m/Y') }}</span>
                </div>
                <div class="tw-flex tw-justify-between tw-border-b tw-border-outline-variant tw-pb-2">
                    <span class="tw-text-on-surface-variant">{{ __('ga.labels.claim_amount') }}:</span>
                    <strong class="tw-font-mono tw-text-ui-sm">Rp {{ number_format($claim->amount, 0, ',', '.') }}</strong>
                </div>
                <div class="tw-flex tw-justify-between tw-border-b tw-border-outline-variant tw-pb-2">
                    <span class="tw-text-on-surface-variant">{{ __('local_invoice.labels.submitted_time') }}:</span>
                    <span>{{ \App\Support\BusinessTime::format($claim->created_at, 'd/m/Y H:i') }}</span>
                </div>
                <div class="tw-flex tw-justify-between tw-pt-1">
                    <span class="tw-text-on-surface-variant">{{ __('local_invoice.receipt.system_status') }}:</span>
                    <span class="tw-inline-flex tw-px-2 tw-py-0.5 tw-rounded tw-text-[11px] tw-font-semibold tw-bg-success/10 tw-text-success">
                        {{ __('ga.receipt.registered') }}
                    </span>
                </div>
            </div>

            <div class="tw-p-3 tw-rounded tw-bg-surface-container tw-text-[11px] tw-text-on-surface-variant">
                {{ __('ga.receipt.statement') }}
            </div>
        </div>
    </div>
</div>
@endsection
